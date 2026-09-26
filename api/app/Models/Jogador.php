<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Jogador extends Model
{
    protected string $tabela = 'jogadores';

    public function daPartida(int $partidaId): array
    {
        return $this->buscarTodos(
            'SELECT * FROM jogadores WHERE partida_id = :partida_id ORDER BY ordem',
            ['partida_id' => $partidaId]
        );
    }

    public function criar(int $partidaId, string $nome, string $cor, string $avatar, int $ordem): int
    {
        $this->executar(
            'INSERT INTO jogadores (partida_id, nome, cor, avatar, ordem)
             VALUES (:partida_id, :nome, :cor, :avatar, :ordem)',
            [
                'partida_id' => $partidaId,
                'nome'       => $nome,
                'cor'        => $cor,
                'avatar'     => $avatar,
                'ordem'      => $ordem,
            ]
        );

        return $this->ultimoId();
    }

    public function somarPontos(int $id, int $pontos): void
    {
        $this->executar(
            'UPDATE jogadores SET pontos = pontos + :pontos WHERE id = :id',
            ['pontos' => $pontos, 'id' => $id]
        );
    }

    public function definirPontos(int $id, int $pontos): void
    {
        $this->executar(
            'UPDATE jogadores SET pontos = :pontos WHERE id = :id',
            ['pontos' => $pontos, 'id' => $id]
        );
    }

    public function pertenceAPartida(int $id, int $partidaId): bool
    {
        return $this->buscarValor(
            'SELECT 1 FROM jogadores WHERE id = :id AND partida_id = :partida_id LIMIT 1',
            ['id' => $id, 'partida_id' => $partidaId]
        ) !== null;
    }

    public function atualizar(int $id, array $dados): bool
    {
        return $this->atualizarColunas($id, $dados, ['nome', 'cor', 'avatar', 'pontos']);
    }
}
