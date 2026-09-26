<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Base dos Models. Todo acesso ao banco passa por aqui,
 * sempre com prepared statements.
 */
abstract class Model
{
    protected string $tabela = '';

    protected function pdo(): PDO
    {
        return Database::conexao();
    }

    protected function executar(string $sql, array $parametros = []): PDOStatement
    {
        $comando = $this->pdo()->prepare($sql);
        $comando->execute($parametros);
        return $comando;
    }

    protected function buscarUm(string $sql, array $parametros = []): ?array
    {
        $linha = $this->executar($sql, $parametros)->fetch();
        return $linha === false ? null : $linha;
    }

    protected function buscarTodos(string $sql, array $parametros = []): array
    {
        return $this->executar($sql, $parametros)->fetchAll();
    }

    protected function buscarValor(string $sql, array $parametros = []): mixed
    {
        $valor = $this->executar($sql, $parametros)->fetchColumn();
        return $valor === false ? null : $valor;
    }

    protected function ultimoId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function porId(int $id): ?array
    {
        return $this->buscarUm(
            sprintf('SELECT * FROM `%s` WHERE id = :id LIMIT 1', $this->tabela),
            ['id' => $id]
        );
    }

    public function excluir(int $id): bool
    {
        return $this->executar(
            sprintf('DELETE FROM `%s` WHERE id = :id', $this->tabela),
            ['id' => $id]
        )->rowCount() > 0;
    }

    /**
     * Monta um UPDATE apenas com as colunas informadas.
     * As chaves do array sao validadas contra a lista de colunas permitidas.
     */
    protected function atualizarColunas(int $id, array $dados, array $colunasPermitidas): bool
    {
        $partes = [];
        $parametros = ['id' => $id];

        foreach ($dados as $coluna => $valor) {
            if (!in_array($coluna, $colunasPermitidas, true)) {
                continue;
            }
            $partes[] = sprintf('`%s` = :%s', $coluna, $coluna);
            $parametros[$coluna] = $valor;
        }

        if ($partes === []) {
            return false;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE id = :id',
            $this->tabela,
            implode(', ', $partes)
        );

        return $this->executar($sql, $parametros)->rowCount() >= 0;
    }
}
