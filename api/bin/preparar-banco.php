<?php

declare(strict_types=1);

/**
 * Deixa o banco pronto para jogar, na subida do container:
 *
 *   1. espera o MySQL aceitar conexao
 *   2. cria as tabelas, se ainda nao existirem
 *   3. importa o baralho de cartas.sql, se nao houver carta aprovada
 *
 * Os dois passos tem trava. O database.sql comeca com DROP TABLE, entao
 * so roda com o banco sem a tabela `categorias`. E o baralho so entra com
 * o acervo vazio: quem gerou cartas no painel nao perde nada.
 *
 * Uso solto (fora do Docker):  php api/bin/preparar-banco.php
 * PERFIL_ARQUIVO_CONFIG troca o arquivo de configuracao lido.
 */

define('RAIZ_API', dirname(__DIR__));

require RAIZ_API . '/vendor/autoload.php';

use App\Core\Configuracao;

Configuracao::carregar(RAIZ_API . '/config/' . (getenv('PERFIL_ARQUIVO_CONFIG') ?: 'config.php'));

$host    = (string) Configuracao::obter('banco.host', '127.0.0.1');
$porta   = (int) Configuracao::obter('banco.porta', 3306);
$nome    = (string) Configuracao::obter('banco.nome', 'perfil');
$usuario = (string) Configuracao::obter('banco.usuario', 'root');
$senha   = (string) Configuracao::obter('banco.senha', '');

$tentativas = (int) (getenv('PERFIL_TENTATIVAS_BANCO') ?: 30);
$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $porta);

$pdo = null;

for ($i = 1; $i <= $tentativas; $i++) {
    try {
        $pdo = new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        fwrite(STDOUT, sprintf("[perfil] MySQL ainda nao respondeu (%d/%d)\n", $i, $tentativas));
        sleep(2);
    }
}

if ($pdo === null) {
    fwrite(STDERR, "[perfil] MySQL nao respondeu a tempo. Subindo o Apache assim mesmo.\n");
    exit(0);
}

$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    str_replace('`', '', $nome)
));

$pdo->exec(sprintf('USE `%s`', str_replace('`', '', $nome)));

/**
 * Roda um arquivo .sql inteiro. Devolve a mensagem de erro, ou null.
 *
 * O database.sql traz `CREATE DATABASE perfil` e `USE perfil` para quem
 * importa na mao pelo phpMyAdmin. Aqui essas duas linhas sao cortadas:
 * o banco certo ja foi escolhido acima e pode ter outro nome, vindo de
 * PERFIL_DB_NOME. Sem o corte, o script montaria as tabelas no banco
 * errado, e um `USE` perdido ja apagou banco de verdade antes.
 */
$rodarArquivo = static function (string $caminho) use ($pdo): ?string {
    $sql = (string) file_get_contents($caminho);

    $sql = (string) preg_replace(
        '/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/im',
        '',
        $sql
    );

    try {
        $pdo->exec($sql);
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};

$temTabelas = $pdo->query("SHOW TABLES LIKE 'categorias'")->fetchColumn() !== false;

if (!$temTabelas) {
    $schema = RAIZ_API . '/../database.sql';

    if (!is_file($schema)) {
        fwrite(STDERR, "[perfil] database.sql nao encontrado em {$schema}.\n");
        exit(0);
    }

    fwrite(STDOUT, "[perfil] Banco vazio: criando as tabelas.\n");

    $erro = $rodarArquivo($schema);

    if ($erro !== null) {
        fwrite(STDERR, '[perfil] Falha ao criar as tabelas: ' . $erro . "\n");
        exit(1);
    }

    fwrite(STDOUT, "[perfil] Tabelas criadas.\n");
}

// -------------------------------------------------------------------------
// Colunas que nasceram depois do primeiro deploy.
//
// O bloco acima so roda com o banco vazio, entao um servidor que ja estava
// no ar nunca receberia coluna nova: o codigo novo subia, o INSERT pedia uma
// coluna que nao existia e a partida parava de comecar. E o que aconteceu
// com `categorias`.
//
// A lista abaixo e conferida a cada subida. Cada entrada so e aplicada se a
// coluna faltar, entao rodar de novo nao custa nada e nao apaga dado.
// MySQL 8 nao tem ADD COLUMN IF NOT EXISTS: a checagem vai na mao.
// -------------------------------------------------------------------------
$colunasEsperadas = [
    [
        'tabela'    => 'partidas',
        'coluna'    => 'eliminados_carta',
        'definicao' => "JSON NULL COMMENT 'ids dos jogadores fora da carta atual' AFTER `dicas_reveladas`",
    ],
    [
        'tabela'    => 'partidas',
        'coluna'    => 'categorias',
        'definicao' => "JSON NULL COMMENT 'temas escolhidos na criacao; vazio = todos' AFTER `pontuacao_vitoria`",
    ],
];

$existeColuna = static function (PDO $pdo, string $tabela, string $coluna): bool {
    $consulta = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :tabela
            AND COLUMN_NAME = :coluna
          LIMIT 1'
    );
    $consulta->execute(['tabela' => $tabela, 'coluna' => $coluna]);

    return $consulta->fetchColumn() !== false;
};

$adicionadas = 0;

foreach ($colunasEsperadas as $esperada) {
    if ($existeColuna($pdo, $esperada['tabela'], $esperada['coluna'])) {
        continue;
    }

    try {
        $pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $esperada['tabela'],
            $esperada['coluna'],
            $esperada['definicao']
        ));

        fwrite(STDOUT, sprintf(
            "[perfil] Coluna %s.%s criada.\n",
            $esperada['tabela'],
            $esperada['coluna']
        ));

        $adicionadas++;
    } catch (PDOException $e) {
        fwrite(STDERR, sprintf(
            "[perfil] Falha ao criar %s.%s: %s\n",
            $esperada['tabela'],
            $esperada['coluna'],
            $e->getMessage()
        ));
        exit(1);
    }
}

if ($adicionadas === 0) {
    fwrite(STDOUT, "[perfil] Estrutura em dia.\n");
}

// -------------------------------------------------------------------------
// Baralho. Importado quando nao ha nenhuma carta aprovada, o que cobre tanto
// a primeira subida quanto um banco que ficou vazio. Com cartas no lugar, o
// arquivo nao e tocado: ninguem perde o que gerou e aprovou no painel.
// -------------------------------------------------------------------------
$cartas = RAIZ_API . '/../cartas.sql';

if (!is_file($cartas)) {
    fwrite(STDOUT, "[perfil] Sem cartas.sql no projeto: siga para o painel /admin e gere as cartas.\n");
    exit(0);
}

$aprovadas = (int) $pdo->query("SELECT COUNT(*) FROM cartas WHERE status = 'aprovada'")->fetchColumn();

// PERFIL_ATUALIZAR_BARALHO=true manda importar mesmo com o acervo cheio. E o
// jeito de levar cartas novas para um servidor que ja esta rodando.
//
// Nao e destrutivo por acidente: o cartas.sql apaga e reinsere carta por
// carta, pelo qid ou pela resposta, entao carta que voce gerou no painel e
// nao esta no arquivo continua de pe. O que se perde e a contagem de
// vezes_jogada das cartas substituidas, e uma partida em andamento que
// esteja justo numa delas puxa carta nova.
$forcar = in_array(strtolower((string) (getenv('PERFIL_ATUALIZAR_BARALHO') ?: '')), ['1', 'true', 'sim'], true);

if ($aprovadas > 0 && !$forcar) {
    fwrite(STDOUT, sprintf("[perfil] Ja ha %d cartas aprovadas. Baralho mantido.\n", $aprovadas));
    exit(0);
}

if ($aprovadas > 0) {
    fwrite(STDOUT, sprintf(
        "[perfil] PERFIL_ATUALIZAR_BARALHO ligado: atualizando as %d cartas com o cartas.sql.\n",
        $aprovadas
    ));
}

fwrite(STDOUT, "[perfil] Importando o baralho de cartas.sql.\n");

$erro = $rodarArquivo($cartas);

if ($erro !== null) {
    fwrite(STDERR, '[perfil] Falha ao importar o baralho: ' . $erro . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[perfil] Baralho importado: %d cartas aprovadas.\n",
    (int) $pdo->query("SELECT COUNT(*) FROM cartas WHERE status = 'aprovada'")->fetchColumn()
));
