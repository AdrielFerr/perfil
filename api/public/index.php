<?php

declare(strict_types=1);

/**
 * Front controller (regra 9).
 * Tudo que chega na API passa por aqui. As pastas app/ e config/ ficam
 * um nivel acima e nao sao alcancaveis pelo navegador.
 */

use App\Core\Configuracao;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\TratadorErros;

define('RAIZ_API', dirname(__DIR__));

$autoload = RAIZ_API . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sucesso' => false,
        'dados'   => null,
        'erro'    => [
            'mensagem' => 'Autoload do Composer nao encontrado. Rode "composer dump-autoload" dentro da pasta api/.',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require $autoload;

// Erros nunca vao para a saida: viram JSON pelo TratadorErros.
ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

try {
    Configuracao::carregar(RAIZ_API . '/config/config.php');
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sucesso' => false,
        'dados'   => null,
        'erro'    => ['mensagem' => $e->getMessage()],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

TratadorErros::registrar();

$requisicao = new Request();

Response::aplicarCors($requisicao);

// O navegador manda OPTIONS antes de POST/PUT/DELETE quando ha CORS.
if ($requisicao->metodo() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$roteador = new Router();
$roteador->carregar(require RAIZ_API . '/config/routes.php');

$roteador->despachar($requisicao)->enviar();
