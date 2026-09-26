<?php

declare(strict_types=1);

/**
 * Gerador de cartas para o Agendador de Tarefas do Windows (ou cron).
 * Regra 17: usa exatamente os mesmos Services da API, sem duplicar nada.
 *
 * Uso:
 *   php bin/gerar-cartas.php
 *   php bin/gerar-cartas.php 20
 *   php bin/gerar-cartas.php 5 pessoa
 *   php bin/gerar-cartas.php 10 --aprovar
 *
 * Exemplo de tarefa agendada no Windows:
 *   Programa:  C:\php\php.exe
 *   Argumentos: "C:\xampp\htdocs\perfil\api\bin\gerar-cartas.php" 10
 */

use App\Core\Configuracao;
use App\Services\GeradorCartas;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script so roda pela linha de comando.\n");
}

$raiz = dirname(__DIR__);

require $raiz . '/vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
set_time_limit(0);

Configuracao::carregar($raiz . '/config/config.php');

// --- Argumentos ---
$quantidade = (int) Configuracao::obter('gerador.lote_padrao', 10);
$categoria = null;
$aprovarNoFinal = false;

foreach (array_slice($argv, 1) as $argumento) {
    if ($argumento === '--aprovar') {
        $aprovarNoFinal = true;
        continue;
    }

    if (ctype_digit($argumento)) {
        $quantidade = (int) $argumento;
        continue;
    }

    if (in_array($argumento, ['pessoa', 'lugar', 'ano', 'coisa'], true)) {
        $categoria = $argumento;
    }
}

$inicio = microtime(true);

echo sprintf(
    "[%s] Gerando %d carta(s)%s...\n",
    date('Y-m-d H:i:s'),
    $quantidade,
    $categoria !== null ? ' da categoria ' . $categoria : ''
);

try {
    $gerador = new GeradorCartas();
    $resultado = $gerador->gerar($quantidade, $categoria);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[erro] %s\n", $e->getMessage()));
    exit(1);
}

foreach ($resultado['registro'] as $linha) {
    echo '  ' . $linha . "\n";
}

// --aprovar sobe para 'aprovada' tudo que acabou de ser gerado e esta completo.
if ($aprovarNoFinal && $resultado['geradas'] > 0) {
    $cartas = new App\Models\Carta();
    $dicas = new App\Models\Dica();
    $esperado = (int) Configuracao::obter('jogo.dicas_por_carta', 20);

    $pendentes = $cartas->listar(['status' => 'pendente'], 1, 100);
    $aprovadas = 0;

    foreach ($pendentes as $carta) {
        if ($dicas->contarDaCarta((int) $carta['id']) >= $esperado) {
            $cartas->definirStatus((int) $carta['id'], 'aprovada');
            $aprovadas++;
        }
    }

    echo sprintf("  %d carta(s) pendente(s) aprovada(s) automaticamente.\n", $aprovadas);
}

echo sprintf(
    "[%s] Pronto: %d de %d carta(s) em %.1fs.\n",
    date('Y-m-d H:i:s'),
    $resultado['geradas'],
    $resultado['pedidas'],
    microtime(true) - $inicio
);

exit($resultado['geradas'] > 0 ? 0 : 2);
