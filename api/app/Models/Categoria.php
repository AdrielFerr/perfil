<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Categoria extends Model
{
    protected string $tabela = 'categorias';

    public function todas(): array
    {
        return $this->buscarTodos('SELECT * FROM categorias ORDER BY id');
    }

    public function porChave(string $chave): ?array
    {
        return $this->buscarUm(
            'SELECT * FROM categorias WHERE chave = :chave LIMIT 1',
            ['chave' => $chave]
        );
    }

    public function idPorChave(string $chave): ?int
    {
        $id = $this->buscarValor(
            'SELECT id FROM categorias WHERE chave = :chave LIMIT 1',
            ['chave' => $chave]
        );

        return $id === null ? null : (int) $id;
    }

    /** Quantas cartas aprovadas existem por categoria. */
    public function contagemDeCartas(): array
    {
        return $this->buscarTodos(
            "SELECT c.chave,
                    c.nome,
                    c.cor,
                    c.icone,
                    SUM(ca.status = 'aprovada') AS aprovadas,
                    SUM(ca.status = 'pendente') AS pendentes,
                    COUNT(ca.id)                AS total
               FROM categorias c
          LEFT JOIN cartas ca ON ca.categoria_id = c.id
           GROUP BY c.id, c.chave, c.nome, c.cor, c.icone
           ORDER BY c.id"
        );
    }
}
