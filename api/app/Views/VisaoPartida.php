<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Formata o estado da partida para o React.
 *
 * REGRA DE OURO: no modo digitado, a resposta da carta so entra no JSON
 * depois que a partida marca `carta_revelada = 1`. Quem decide isso e o
 * Service RegrasPartida, nunca o front-end.
 */
final class VisaoPartida
{
    public static function estado(array $partida, array $jogadores, ?array $carta, array $dicasReveladas): array
    {
        $revelada = (bool) $partida['carta_revelada'];
        $total = count($carta['dicas'] ?? []) ?: 20;
        $usados = array_map('intval', $partida['dicas_reveladas'] ?? []);

        return [
            'partida' => [
                'id'                  => (int) $partida['id'],
                'codigo'              => $partida['codigo'],
                'pontuacao_vitoria'   => (int) $partida['pontuacao_vitoria'],
                'status'              => $partida['status'],
                'numero_carta'        => (int) $partida['numero_carta'],
                'jogador_vez_id'      => $partida['jogador_vez_id'] !== null ? (int) $partida['jogador_vez_id'] : null,
                'ultimo_acertador_id' => $partida['ultimo_acertador_id'] !== null ? (int) $partida['ultimo_acertador_id'] : null,
                'vencedor_id'         => $partida['vencedor_id'] !== null ? (int) $partida['vencedor_id'] : null,
                'carta_revelada'      => $revelada,
                'dica_da_vez'         => $partida['dica_da_vez'] !== null ? (int) $partida['dica_da_vez'] : null,

                // Regra 25: quem ja errou ou desistiu esta fora ate a carta virar.
                'eliminados_carta'    => array_values(array_map('intval', $partida['eliminados_carta'] ?? [])),
                'pode_desfazer'       => (bool) ($partida['pode_desfazer'] ?? false),
                'fim_de_carta'        => (bool) ($partida['fim_de_carta'] ?? false),
            ],
            'jogadores' => array_map([self::class, 'jogador'], $jogadores),
            'carta'     => $carta === null ? null : self::carta($carta, $revelada, $usados, $dicasReveladas),
            'progresso' => [
                'dicas_reveladas' => count($usados),
                'dicas_restantes' => max(0, $total - count($usados)),

                // Regra 24: o acerto vale o numero de dicas AINDA fechadas.
                // Se o jogador ja abriu a dica da vez, e esse o valor de agora.
                // Se ainda precisa abrir uma, o valor cai mais um ponto.
                'valor_do_acerto' => max(
                    0,
                    $total - count($usados) - ($partida['dica_da_vez'] === null ? 1 : 0)
                ),
            ],
        ];
    }

    public static function jogador(array $jogador): array
    {
        return [
            'id'     => (int) $jogador['id'],
            'nome'   => $jogador['nome'],
            'cor'    => $jogador['cor'],
            'avatar' => $jogador['avatar'],
            'pontos' => (int) $jogador['pontos'],
            'ordem'  => (int) $jogador['ordem'],
        ];
    }

    /**
     * @param array $dicasReveladas mapa numero => ['texto'=>..,'dificuldade'=>..]
     */
    private static function carta(array $carta, bool $revelada, array $usados, array $dicasReveladas): array
    {
        $saida = [
            'id'        => (int) $carta['id'],
            'categoria' => [
                'chave' => $carta['categoria_chave'],
                'nome'  => $carta['categoria_nome'],
                'cor'   => $carta['categoria_cor'],
                'icone' => $carta['categoria_icone'],
            ],
            'dicas' => [],
        ];

        foreach ($usados as $numero) {
            if (!isset($dicasReveladas[$numero])) {
                continue;
            }
            $saida['dicas'][] = [
                'numero'      => $numero,
                'texto'       => $dicasReveladas[$numero]['texto'],
                'dificuldade' => $dicasReveladas[$numero]['dificuldade'],
            ];
        }

        if ($revelada) {
            $saida['resposta']  = $carta['resposta'];
            $saida['url_fonte'] = $carta['url_fonte'];
        }

        return $saida;
    }

    /** Resposta enviada depois de um palpite ou de um passe. */
    public static function resultadoPalpite(
        bool $acertou,
        int $pontosGanhos,
        array $estado,
        ?string $resposta = null,
        ?string $urlFonte = null,
        bool $eliminado = false
    ): array {
        $saida = [
            'acertou'       => $acertou,
            'pontos_ganhos' => $pontosGanhos,

            // Regra 25: true so quando o chute errado tirou o jogador da carta.
            'eliminado'     => $eliminado,
            'estado'        => $estado,
        ];

        if ($resposta !== null) {
            $saida['resposta']  = $resposta;
            $saida['url_fonte'] = $urlFonte;
        }

        return $saida;
    }
}
