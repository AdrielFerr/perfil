<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Pilha de acoes da partida. Cada linha guarda a fotografia do estado
 * ANTES da acao, e e isso que o botao "Desfazer" restaura.
 */
final class AcaoPartida extends Model
{
    protected string $tabela = 'acoes_partida';

    public function empilhar(int $partidaId, string $tipo, ?string $descricao, array $estadoAnterior): int
    {
        $this->executar(
            'INSERT INTO acoes_partida (partida_id, tipo, descricao, estado_anterior)
             VALUES (:partida_id, :tipo, :descricao, :estado)',
            [
                'partida_id' => $partidaId,
                'tipo'       => $tipo,
                'descricao'  => $descricao,
                'estado'     => json_encode($estadoAnterior, JSON_UNESCAPED_UNICODE),
            ]
        );

        return $this->ultimoId();
    }

    public function ultima(int $partidaId): ?array
    {
        $linha = $this->buscarUm(
            'SELECT * FROM acoes_partida WHERE partida_id = :partida_id ORDER BY id DESC LIMIT 1',
            ['partida_id' => $partidaId]
        );

        if ($linha === null) {
            return null;
        }

        $estado = $linha['estado_anterior'];
        $linha['estado_anterior'] = is_array($estado)
            ? $estado
            : (json_decode((string) $estado, true) ?: []);

        return $linha;
    }

    public function existe(int $partidaId): bool
    {
        return $this->buscarValor(
            'SELECT 1 FROM acoes_partida WHERE partida_id = :partida_id LIMIT 1',
            ['partida_id' => $partidaId]
        ) !== null;
    }

    public function desempilhar(int $id): void
    {
        $this->executar('DELETE FROM acoes_partida WHERE id = :id', ['id' => $id]);
    }

    public function limpar(int $partidaId): void
    {
        $this->executar('DELETE FROM acoes_partida WHERE partida_id = :partida_id', ['partida_id' => $partidaId]);
    }
}
