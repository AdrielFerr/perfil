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

if ($aprovadas > 0) {
    fwrite(STDOUT, sprintf("[perfil] Ja ha %d cartas aprovadas. Baralho mantido.\n", $aprovadas));
    exit(0);
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
