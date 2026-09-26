<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class CartaUsada extends Model
{
    protected string $tabela = 'cartas_usadas';

    public function registrar(int $partidaId, int $cartaId): void
    {
        $this->executar(
            'INSERT IGNORE INTO cartas_usadas (partida_id, carta_id) VALUES (:partida_id, :carta_id)',
            ['partida_id' => $partidaId, 'carta_id' => $cartaId]
        );
    }

    public function remover(int $partidaId, int $cartaId): void
    {
        $this->executar(
            'DELETE FROM cartas_usadas WHERE partida_id = :partida_id AND carta_id = :carta_id',
            ['partida_id' => $partidaId, 'carta_id' => $cartaId]
        );
    }

    /** @return array<int,int> */
    public function idsDaPartida(int $partidaId): array
    {
        $linhas = $this->buscarTodos(
            'SELECT carta_id FROM cartas_usadas WHERE partida_id = :partida_id',
            ['partida_id' => $partidaId]
        );

        return array_map('intval', array_column($linhas, 'carta_id'));
    }

    public function contarDaPartida(int $partidaId): int
    {
        return (int) $this->buscarValor(
            'SELECT COUNT(*) FROM cartas_usadas WHERE partida_id = :partida_id',
            ['partida_id' => $partidaId]
        );
    }
}
