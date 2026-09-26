<?php

declare(strict_types=1);

/**
 * Exporta as cartas APROVADAS para cartas.sql, o baralho que vai junto
 * no repositorio.
 *
 * E esse arquivo que o container importa na primeira subida: assim o
 * servidor ja nasce com baralho, sem depender do Wikidata no boot.
 *
 * Uso:  php api/bin/exportar-cartas.php
 * Precisa do pdo_mysql no PHP de linha de comando. No XAMPP, o PHP do
 * Apache costuma ter e o da linha de comando nao: nesse caso rode pelo
 * navegador ou ajuste o php.ini do CLI.
 */

define('RAIZ_API', dirname(__DIR__));

require RAIZ_API . '/vendor/autoload.php';

use App\Core\Configuracao;
use App\Core\Database;

Configuracao::carregar(RAIZ_API . '/config/config.php');

$pdo = Database::conexao();
$destino = RAIZ_API . '/../cartas.sql';

/** Escapa um valor para dentro do SQL. */
$v = static function (?string $valor) use ($pdo): string {
    return $valor === null ? 'NULL' : $pdo->quote($valor);
};

$cartas = $pdo->query(
    "SELECT c.*, cat.chave AS categoria_chave
       FROM cartas c
       JOIN categorias cat ON cat.id = c.categoria_id
      WHERE c.status = 'aprovada'
      ORDER BY cat.chave, c.resposta"
)->fetchAll(PDO::FETCH_ASSOC);

if ($cartas === []) {
    fwrite(STDERR, "Nenhuma carta aprovada para exportar.\n");
    exit(1);
}

$linhas = [];
$linhas[] = '-- ---------------------------------------------------------------------';
$linhas[] = '-- Baralho do Perfil: cartas aprovadas, com dicas e respostas aceitas.';
$linhas[] = '--';
$linhas[] = '-- Gerado por api/bin/exportar-cartas.php. Nao edite na mao: gere cartas';
$linhas[] = '-- no painel /admin, aprove as boas e rode o script de novo.';
$linhas[] = '--';
$linhas[] = '-- O container importa este arquivo na primeira subida, logo depois do';
$linhas[] = '-- database.sql. Rodar de novo nao duplica nada: cada carta e apagada';
$linhas[] = '-- pelo qid antes de ser inserida.';
$linhas[] = sprintf('-- Cartas neste arquivo: %d', count($cartas));
$linhas[] = '-- ---------------------------------------------------------------------';
$linhas[] = '';
$linhas[] = 'SET NAMES utf8mb4;';
$linhas[] = '';

$totalDicas = 0;
$totalAlternativas = 0;

foreach ($cartas as $carta) {
    $dicas = $pdo->prepare('SELECT * FROM dicas WHERE carta_id = :id ORDER BY numero');
    $dicas->execute(['id' => $carta['id']]);
    $dicas = $dicas->fetchAll(PDO::FETCH_ASSOC);

    $alternativas = $pdo->prepare('SELECT * FROM respostas_alternativas WHERE carta_id = :id ORDER BY id');
    $alternativas->execute(['id' => $carta['id']]);
    $alternativas = $alternativas->fetchAll(PDO::FETCH_ASSOC);

    $linhas[] = sprintf('-- %s (%s)', $carta['resposta'], $carta['categoria_chave']);

    // Apaga a versao antiga desta mesma carta, se existir. As dicas e as
    // alternativas somem junto pela FK com ON DELETE CASCADE.
    // A carta de ANO nao tem qid, por isso a resposta normalizada tambem
    // entra na conta: e ela que o jogo usa como identidade da carta.
    $linhas[] = $carta['qid'] === null
        ? sprintf(
            'DELETE FROM `cartas` WHERE `resposta_normalizada` = %s;',
            $v($carta['resposta_normalizada'])
        )
        : sprintf(
            'DELETE FROM `cartas` WHERE `qid` = %s OR `resposta_normalizada` = %s;',
            $v($carta['qid']),
            $v($carta['resposta_normalizada'])
        );

    $linhas[] = sprintf(
        'INSERT INTO `cartas` (`categoria_id`, `qid`, `resposta`, `resposta_normalizada`, `url_fonte`, `resumo_fonte`, `status`)'
        . ' SELECT `id`, %s, %s, %s, %s, %s, %s FROM `categorias` WHERE `chave` = %s;',
        $v($carta['qid']),
        $v($carta['resposta']),
        $v($carta['resposta_normalizada']),
        $v($carta['url_fonte']),
        $v($carta['resumo_fonte']),
        $v('aprovada'),
        $v($carta['categoria_chave'])
    );

    $linhas[] = 'SET @carta := LAST_INSERT_ID();';

    foreach ($dicas as $dica) {
        $linhas[] = sprintf(
            'INSERT INTO `dicas` (`carta_id`, `numero`, `texto`, `texto_normalizado`, `dificuldade`, `propriedade_origem`)'
            . ' VALUES (@carta, %d, %s, %s, %s, %s);',
            (int) $dica['numero'],
            $v($dica['texto']),
            $v($dica['texto_normalizado']),
            $v($dica['dificuldade']),
            $v($dica['propriedade_origem'])
        );
        $totalDicas++;
    }

    foreach ($alternativas as $alternativa) {
        $linhas[] = sprintf(
            'INSERT IGNORE INTO `respostas_alternativas` (`carta_id`, `texto`, `texto_normalizado`)'
            . ' VALUES (@carta, %s, %s);',
            $v($alternativa['texto']),
            $v($alternativa['texto_normalizado'])
        );
        $totalAlternativas++;
    }

    $linhas[] = '';
}

file_put_contents($destino, implode("\n", $linhas) . "\n");

fwrite(STDOUT, sprintf(
    "cartas.sql gerado: %d cartas, %d dicas, %d respostas alternativas.\n",
    count($cartas),
    $totalDicas,
    $totalAlternativas
));
