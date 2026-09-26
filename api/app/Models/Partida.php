<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Partida extends Model
{
    protected string $tabela = 'partidas';

    /** @param array<int,string> $categorias temas escolhidos; vazio = todos */
    public function criar(string $codigo, int $pontuacaoVitoria, array $categorias = []): int
    {
        $this->executar(
            'INSERT INTO partidas (codigo, pontuacao_vitoria, categorias, dicas_reveladas, eliminados_carta)
             VALUES (:codigo, :pontuacao, :categorias, :dicas, :eliminados)',
            [
                'codigo'     => $codigo,
                'pontuacao'  => $pontuacaoVitoria,
                'categorias' => json_encode(array_values($categorias)),
                'dicas'      => '[]',
                'eliminados' => '[]',
            ]
        );

        return $this->ultimoId();
    }

    /**
     * Devolve a partida com dicas_reveladas e eliminados_carta ja convertidos
     * em arrays de inteiros.
     */
    public function carregar(int $id): ?array
    {
        $partida = $this->porId($id);

        if ($partida === null) {
            return null;
        }

        return $this->decodificarListas($partida);
    }

    public function porCodigo(string $codigo): ?array
    {
        $partida = $this->buscarUm(
            'SELECT * FROM partidas WHERE codigo = :codigo LIMIT 1',
            ['codigo' => $codigo]
        );

        if ($partida === null) {
            return null;
        }

        return $this->decodificarListas($partida);
    }

    private function decodificarListas(array $partida): array
    {
        $partida['dicas_reveladas'] = $this->decodificarLista($partida['dicas_reveladas']);
        $partida['eliminados_carta'] = $this->decodificarLista($partida['eliminados_carta'] ?? null);
        $partida['categorias'] = $this->decodificarTextos($partida['categorias'] ?? null);

        return $partida;
    }

    /** Mesma ideia da lista de numeros, mas guardando texto. */
    private function decodificarTextos(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return array_values(array_map('strval', $bruto));
        }

        $lista = json_decode((string) $bruto, true);

        return is_array($lista) ? array_values(array_map('strval', $lista)) : [];
    }

    private function decodificarLista(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return array_map('intval', $bruto);
        }

        $lista = json_decode((string) $bruto, true);

        return is_array($lista) ? array_map('intval', $lista) : [];
    }

    public function codigoExiste(string $codigo): bool
    {
        return $this->buscarValor(
            'SELECT 1 FROM partidas WHERE codigo = :codigo LIMIT 1',
            ['codigo' => $codigo]
        ) !== null;
    }

    public function atualizar(int $id, array $dados): bool
    {
        foreach (['dicas_reveladas', 'eliminados_carta'] as $lista) {
            if (array_key_exists($lista, $dados) && is_array($dados[$lista])) {
                $dados[$lista] = json_encode(
                    array_values(array_map('intval', $dados[$lista]))
                );
            }
        }

        if (array_key_exists('carta_revelada', $dados)) {
            $dados['carta_revelada'] = (int) (bool) $dados['carta_revelada'];
        }

        return $this->atualizarColunas($id, $dados, [
            'pontuacao_vitoria',
            'status',
            'carta_atual_id',
            'jogador_vez_id',
            'ultimo_acertador_id',
            'vencedor_id',
            'dicas_reveladas',
            'eliminados_carta',
            'dica_da_vez',
            'numero_carta',
            'carta_revelada',
        ]);
    }
}
