<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Formata cartas para o painel administrativo.
 * Aqui a resposta aparece, porque so o admin logado ve estes dados.
 */
final class VisaoCarta
{
    public static function resumo(array $carta): array
    {
        return [
            'id'            => (int) $carta['id'],
            'qid'           => $carta['qid'],
            'resposta'      => $carta['resposta'],
            'status'        => $carta['status'],
            'vezes_jogada'  => (int) $carta['vezes_jogada'],
            'url_fonte'     => $carta['url_fonte'],
            'criado_em'     => $carta['criado_em'],
            'total_dicas'   => isset($carta['total_dicas']) ? (int) $carta['total_dicas'] : null,
            'categoria'     => [
                'chave' => $carta['categoria_chave'] ?? null,
                'nome'  => $carta['categoria_nome'] ?? null,
                'cor'   => $carta['categoria_cor'] ?? null,
                'icone' => $carta['categoria_icone'] ?? null,
            ],
        ];
    }

    public static function completa(array $carta, array $dicas, array $alternativas): array
    {
        $saida = self::resumo($carta);
        $saida['resumo_fonte'] = $carta['resumo_fonte'] ?? null;
        $saida['total_dicas'] = count($dicas);

        $saida['dicas'] = array_map(static fn (array $d): array => [
            'id'                 => (int) $d['id'],
            'numero'             => (int) $d['numero'],
            'texto'              => $d['texto'],
            'dificuldade'        => $d['dificuldade'],
            'propriedade_origem' => $d['propriedade_origem'],
        ], $dicas);

        $saida['respostas_alternativas'] = array_map(static fn (array $a): array => [
            'id'    => (int) $a['id'],
            'texto' => $a['texto'],
        ], $alternativas);

        return $saida;
    }

    public static function listagem(array $cartas, int $total, int $pagina, int $porPagina): array
    {
        return [
            'cartas' => array_map([self::class, 'resumo'], $cartas),
            'paginacao' => [
                'total'      => $total,
                'pagina'     => $pagina,
                'por_pagina' => $porPagina,
                'paginas'    => (int) ceil($total / max(1, $porPagina)),
            ],
        ];
    }
}
