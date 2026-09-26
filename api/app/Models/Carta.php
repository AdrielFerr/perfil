<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Carta extends Model
{
    protected string $tabela = 'cartas';

    private const CAMPOS_COM_CATEGORIA = '
        c.*,
        cat.chave AS categoria_chave,
        cat.nome  AS categoria_nome,
        cat.cor   AS categoria_cor,
        cat.icone AS categoria_icone
    ';

    public function porIdComCategoria(int $id): ?array
    {
        return $this->buscarUm(
            'SELECT ' . self::CAMPOS_COM_CATEGORIA . '
               FROM cartas c
               JOIN categorias cat ON cat.id = c.categoria_id
              WHERE c.id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    public function existeQid(string $qid): bool
    {
        return $this->buscarValor('SELECT 1 FROM cartas WHERE qid = :qid LIMIT 1', ['qid' => $qid]) !== null;
    }

    public function existeRespostaNormalizada(string $normalizada): bool
    {
        return $this->buscarValor(
            'SELECT 1 FROM cartas WHERE resposta_normalizada = :r LIMIT 1',
            ['r' => $normalizada]
        ) !== null;
    }

    /**
     * Apaga a carta que tem esta resposta, se existir. As dicas e as
     * respostas alternativas somem junto, pela FK com ON DELETE CASCADE.
     * Usado pelo importador para substituir a carta em vez de duplicar.
     */
    public function excluirPorRespostaNormalizada(string $normalizada): int
    {
        return $this->executar(
            'DELETE FROM cartas WHERE resposta_normalizada = :normalizada',
            ['normalizada' => $normalizada]
        )->rowCount();
    }

    /** @return array<int,string> QIDs ja cadastrados, para filtrar o SPARQL. */
    public function qidsExistentes(): array
    {
        $linhas = $this->buscarTodos('SELECT qid FROM cartas WHERE qid IS NOT NULL');
        return array_column($linhas, 'qid');
    }

    public function criar(
        int $categoriaId,
        ?string $qid,
        string $resposta,
        string $respostaNormalizada,
        ?string $urlFonte,
        ?string $resumoFonte,
        string $status
    ): int {
        $this->executar(
            'INSERT INTO cartas
                 (categoria_id, qid, resposta, resposta_normalizada, url_fonte, resumo_fonte, status)
             VALUES
                 (:categoria_id, :qid, :resposta, :resposta_normalizada, :url_fonte, :resumo_fonte, :status)',
            [
                'categoria_id'         => $categoriaId,
                'qid'                  => $qid,
                'resposta'             => $resposta,
                'resposta_normalizada' => $respostaNormalizada,
                'url_fonte'            => $urlFonte,
                'resumo_fonte'         => $resumoFonte,
                'status'               => $status,
            ]
        );

        return $this->ultimoId();
    }

    public function atualizar(int $id, array $dados): bool
    {
        return $this->atualizarColunas($id, $dados, [
            'categoria_id',
            'resposta',
            'resposta_normalizada',
            'url_fonte',
            'resumo_fonte',
            'status',
        ]);
    }

    public function definirStatus(int $id, string $status): bool
    {
        return $this->executar(
            'UPDATE cartas SET status = :status WHERE id = :id',
            ['status' => $status, 'id' => $id]
        )->rowCount() > 0;
    }

    public function contarJogada(int $id): void
    {
        $this->executar('UPDATE cartas SET vezes_jogada = vezes_jogada + 1 WHERE id = :id', ['id' => $id]);
    }

    /** Usado pelo "Desfazer" quando uma carta volta para o monte. */
    public function atualizarContagem(int $id, int $vezes): void
    {
        $this->executar(
            'UPDATE cartas SET vezes_jogada = :vezes WHERE id = :id',
            ['vezes' => max(0, $vezes), 'id' => $id]
        );
    }

    public function totalAprovadas(): int
    {
        return (int) $this->buscarValor("SELECT COUNT(*) FROM cartas WHERE status = 'aprovada'");
    }

    /**
     * Sorteia a proxima carta da partida.
     * Regra 51: nunca repete dentro da partida; entre partidas, prioriza
     * as menos jogadas (e desempata no sorteio).
     */
    /**
     * @param string|array<int,string>|null $categoriaChave uma categoria, uma
     *        lista delas (os temas escolhidos na criacao da partida) ou null
     *        para sortear entre todas.
     */
    public function sortearParaPartida(int $partidaId, string|array|null $categoriaChave = null): ?array
    {
        $parametros = ['partida_id' => $partidaId];
        $filtroCategoria = '';

        $chaves = is_array($categoriaChave)
            ? array_values(array_filter($categoriaChave, static fn ($c): bool => is_string($c) && $c !== ''))
            : (($categoriaChave === null || $categoriaChave === '') ? [] : [$categoriaChave]);

        if ($chaves !== []) {
            // Um marcador nomeado por chave: PDO nao expande array sozinho.
            $marcadores = [];

            foreach ($chaves as $indice => $chave) {
                $marcadores[] = ':chave' . $indice;
                $parametros['chave' . $indice] = $chave;
            }

            $filtroCategoria = ' AND cat.chave IN (' . implode(', ', $marcadores) . ')';
        }

        return $this->buscarUm(
            'SELECT ' . self::CAMPOS_COM_CATEGORIA . '
               FROM cartas c
               JOIN categorias cat ON cat.id = c.categoria_id
              WHERE c.status = \'aprovada\'
                ' . $filtroCategoria . '
                AND NOT EXISTS (
                      SELECT 1 FROM cartas_usadas cu
                       WHERE cu.partida_id = :partida_id
                         AND cu.carta_id = c.id
                )
                AND (SELECT COUNT(*) FROM dicas d WHERE d.carta_id = c.id) >= 20
              ORDER BY c.vezes_jogada ASC, RAND()
              LIMIT 1',
            $parametros
        );
    }

    /** Listagem paginada do painel administrativo. */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        [$where, $parametros] = $this->montarFiltros($filtros);

        $offset = max(0, ($pagina - 1) * $porPagina);

        $sql = 'SELECT ' . self::CAMPOS_COM_CATEGORIA . ',
                       (SELECT COUNT(*) FROM dicas d WHERE d.carta_id = c.id) AS total_dicas
                  FROM cartas c
                  JOIN categorias cat ON cat.id = c.categoria_id
                  ' . $where . '
              ORDER BY c.id DESC
                 LIMIT ' . (int) $porPagina . ' OFFSET ' . (int) $offset;

        return $this->buscarTodos($sql, $parametros);
    }

    public function contar(array $filtros): int
    {
        [$where, $parametros] = $this->montarFiltros($filtros);

        return (int) $this->buscarValor(
            'SELECT COUNT(*)
               FROM cartas c
               JOIN categorias cat ON cat.id = c.categoria_id
               ' . $where,
            $parametros
        );
    }

    /** @return array{0:string,1:array} */
    private function montarFiltros(array $filtros): array
    {
        $condicoes = [];
        $parametros = [];

        if (!empty($filtros['status'])) {
            $condicoes[] = 'c.status = :status';
            $parametros['status'] = $filtros['status'];
        }

        if (!empty($filtros['categoria'])) {
            $condicoes[] = 'cat.chave = :chave';
            $parametros['chave'] = $filtros['categoria'];
        }

        if (!empty($filtros['busca'])) {
            $condicoes[] = 'c.resposta LIKE :busca';
            $parametros['busca'] = '%' . $filtros['busca'] . '%';
        }

        $where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);

        return [$where, $parametros];
    }

    /** Resumo usado na tela inicial do painel. */
    public function estatisticas(): array
    {
        $linha = $this->buscarUm(
            "SELECT COUNT(*)                        AS total,
                    SUM(status = 'aprovada')        AS aprovadas,
                    SUM(status = 'pendente')        AS pendentes,
                    SUM(status = 'rejeitada')       AS rejeitadas
               FROM cartas"
        );

        return [
            'total'      => (int) ($linha['total'] ?? 0),
            'aprovadas'  => (int) ($linha['aprovadas'] ?? 0),
            'pendentes'  => (int) ($linha['pendentes'] ?? 0),
            'rejeitadas' => (int) ($linha['rejeitadas'] ?? 0),
        ];
    }
}
