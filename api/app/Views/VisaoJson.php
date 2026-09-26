<?php

declare(strict_types=1);

namespace App\Views;

use App\Core\Response;

/**
 * Toda resposta da API sai por aqui, sempre no mesmo formato:
 *   { "sucesso": true,  "dados": ..., "erro": null }
 *   { "sucesso": false, "dados": null, "erro": { "mensagem": "...", "detalhes": {...} } }
 */
final class VisaoJson
{
    private const OPCOES = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function sucesso(mixed $dados = null, int $status = 200): Response
    {
        return self::montar([
            'sucesso' => true,
            'dados'   => $dados,
            'erro'    => null,
        ], $status);
    }

    public static function erro(string $mensagem, int $status = 400, array $detalhes = []): Response
    {
        $erro = ['mensagem' => $mensagem];

        if ($detalhes !== []) {
            $erro['detalhes'] = $detalhes;
        }

        return self::montar([
            'sucesso' => false,
            'dados'   => null,
            'erro'    => $erro,
        ], $status);
    }

    private static function montar(array $corpo, int $status): Response
    {
        return (new Response())
            ->status($status)
            ->cabecalho('Content-Type', 'application/json; charset=utf-8')
            ->cabecalho('Cache-Control', 'no-store')
            ->cabecalho('X-Content-Type-Options', 'nosniff')
            ->conteudo((string) json_encode($corpo, self::OPCOES));
    }
}
