<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Dica extends Model
{
    protected string $tabela = 'dicas';

    public function daCarta(int $cartaId): array
    {
        return $this->buscarTodos(
            'SELECT * FROM dicas WHERE carta_id = :carta_id ORDER BY numero',
            ['carta_id' => $cartaId]
        );
    }

    /** @return array<int,array> mapa numero => dica */
    public function daCartaPorNumero(int $cartaId): array
    {
        $mapa = [];
        foreach ($this->daCarta($cartaId) as $dica) {
            $mapa[(int) $dica['numero']] = $dica;
        }
        return $mapa;
    }

    public function porCartaENumero(int $cartaId, int $numero): ?array
    {
        return $this->buscarUm(
            'SELECT * FROM dicas WHERE carta_id = :carta_id AND numero = :numero LIMIT 1',
            ['carta_id' => $cartaId, 'numero' => $numero]
        );
    }

    public function contarDaCarta(int $cartaId): int
    {
        return (int) $this->buscarValor(
            'SELECT COUNT(*) FROM dicas WHERE carta_id = :carta_id',
            ['carta_id' => $cartaId]
        );
    }

    /**
     * Grava as 20 dicas de uma carta de uma vez.
     * @param array<int,array{numero:int,texto:string,texto_normalizado:string,dificuldade:string,propriedade_origem:?string}> $dicas
     */
    public function criarEmLote(int $cartaId, array $dicas): int
    {
        if ($dicas === []) {
            return 0;
        }

        $comando = $this->pdo()->prepare(
            'INSERT INTO dicas (carta_id, numero, texto, texto_normalizado, dificuldade, propriedade_origem)
             VALUES (:carta_id, :numero, :texto, :texto_normalizado, :dificuldade, :propriedade_origem)'
        );

        $gravadas = 0;
        foreach ($dicas as $dica) {
            $comando->execute([
                'carta_id'           => $cartaId,
                'numero'             => (int) $dica['numero'],
                'texto'              => $dica['texto'],
                'texto_normalizado'  => $dica['texto_normalizado'],
                'dificuldade'        => $dica['dificuldade'],
                'propriedade_origem' => $dica['propriedade_origem'] ?? null,
            ]);
            $gravadas++;
        }

        return $gravadas;
    }

    public function atualizar(int $id, array $dados): bool
    {
        return $this->atualizarColunas($id, $dados, [
            'texto',
            'texto_normalizado',
            'dificuldade',
            'propriedade_origem',
        ]);
    }

    public function excluirDaCarta(int $cartaId): void
    {
        $this->executar('DELETE FROM dicas WHERE carta_id = :carta_id', ['carta_id' => $cartaId]);
    }
}
