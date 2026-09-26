<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class RespostaAlternativa extends Model
{
    protected string $tabela = 'respostas_alternativas';

    public function daCarta(int $cartaId): array
    {
        return $this->buscarTodos(
            'SELECT * FROM respostas_alternativas WHERE carta_id = :carta_id ORDER BY id',
            ['carta_id' => $cartaId]
        );
    }

    /** @return array<int,string> apenas os textos normalizados */
    public function normalizadasDaCarta(int $cartaId): array
    {
        $linhas = $this->buscarTodos(
            'SELECT texto_normalizado FROM respostas_alternativas WHERE carta_id = :carta_id',
            ['carta_id' => $cartaId]
        );

        return array_column($linhas, 'texto_normalizado');
    }

    /** @param array<int,array{texto:string,texto_normalizado:string}> $alternativas */
    public function criarEmLote(int $cartaId, array $alternativas): int
    {
        if ($alternativas === []) {
            return 0;
        }

        $comando = $this->pdo()->prepare(
            'INSERT IGNORE INTO respostas_alternativas (carta_id, texto, texto_normalizado)
             VALUES (:carta_id, :texto, :texto_normalizado)'
        );

        $gravadas = 0;
        foreach ($alternativas as $alternativa) {
            $comando->execute([
                'carta_id'          => $cartaId,
                'texto'             => $alternativa['texto'],
                'texto_normalizado' => $alternativa['texto_normalizado'],
            ]);
            $gravadas += $comando->rowCount();
        }

        return $gravadas;
    }

    public function excluirDaCarta(int $cartaId): void
    {
        $this->executar(
            'DELETE FROM respostas_alternativas WHERE carta_id = :carta_id',
            ['carta_id' => $cartaId]
        );
    }
}
