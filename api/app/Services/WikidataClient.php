<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;

/**
 * Conversa com o endpoint SPARQL do Wikidata.
 * As consultas ficam em templates_dicas/sparql/*.rq (regra 47).
 */
final class WikidataClient extends ClienteHttp
{
    private string $pastaConsultas;

    public function __construct(?string $pastaConsultas = null)
    {
        $this->pastaConsultas = $pastaConsultas ?? dirname(__DIR__, 2) . '/templates_dicas/sparql';
    }

    private function endpoint(): string
    {
        return (string) Configuracao::obter('gerador.endpoint_sparql', 'https://query.wikidata.org/sparql');
    }

    /**
     * Le uma consulta do disco e troca os marcadores %NOME%.
     *
     * @param array<string,string|int> $substituicoes
     */
    public function carregarConsulta(string $nome, array $substituicoes = []): string
    {
        $arquivo = $this->pastaConsultas . '/' . $nome . '.rq';

        if (!is_file($arquivo)) {
            throw new \RuntimeException('Consulta SPARQL nao encontrada: ' . $arquivo);
        }

        $sparql = (string) file_get_contents($arquivo);

        foreach ($substituicoes as $chave => $valor) {
            $sparql = str_replace('%' . $chave . '%', (string) $valor, $sparql);
        }

        return $sparql;
    }

    /**
     * Executa SPARQL e devolve as linhas ja simplificadas.
     *
     * @return array{ok:bool, linhas:array<int,array<string,string>>, erro:?string}
     */
    public function consultar(string $sparql, bool $usarCache = true): array
    {
        $url = $this->endpoint() . '?' . http_build_query([
            'query'  => $sparql,
            'format' => 'json',
        ]);

        $resposta = $this->buscar($url, ['Accept' => 'application/sparql-results+json'], $usarCache);

        if (!$resposta['ok']) {
            return [
                'ok' => false,
                'linhas' => [],
                'erro' => 'Wikidata: ' . ($resposta['erro'] ?? 'erro desconhecido'),
            ];
        }

        $dados = json_decode($resposta['corpo'], true);

        if (!is_array($dados) || !isset($dados['results']['bindings'])) {
            return ['ok' => false, 'linhas' => [], 'erro' => 'Wikidata devolveu um formato inesperado.'];
        }

        $linhas = [];
        foreach ($dados['results']['bindings'] as $ligacao) {
            $linha = [];
            foreach ($ligacao as $coluna => $conteudo) {
                $linha[$coluna] = (string) ($conteudo['value'] ?? '');
                if (isset($conteudo['datatype'])) {
                    $linha[$coluna . '__tipo'] = (string) $conteudo['datatype'];
                }
            }
            $linhas[] = $linha;
        }

        return ['ok' => true, 'linhas' => $linhas, 'erro' => null];
    }

    /**
     * Lista candidatos de uma categoria (regra 34: filtra por sitelinks
     * e prioriza quem tem artigo em portugues).
     *
     * @return array{ok:bool, linhas:array, erro:?string}
     */
    /**
     * $recorte escolhe a variante da consulta. 'brasil' carrega o
     * arquivo <categoria>_lista_brasil.rq, que filtra por Brasil.
     */
    public function listarCandidatos(
        string $categoria,
        string $foco,
        int $minimoSitelinks,
        int $limite,
        int $deslocamento,
        ?string $recorte = null
    ): array {
        $arquivo = $categoria . '_lista' . ($recorte === null || $recorte === '' ? '' : '_' . $recorte);

        $sparql = $this->carregarConsulta($arquivo, [
            'FOCO'          => $this->limparQid($foco),
            'MIN_SITELINKS' => $minimoSitelinks,
            'LIMITE'        => $limite,
            'DESLOCAMENTO'  => $deslocamento,
        ]);

        return $this->consultar($sparql);
    }

    /**
     * Busca os fatos de um item, ja com rotulos em portugues.
     *
     * @return array{ok:bool, fatos:array<string,array<int,string>>, erro:?string}
     */
    public function fatosDoItem(string $qid, string $categoria): array
    {
        $sparql = $this->carregarConsulta($categoria . '_fatos', ['QID' => $this->limparQid($qid)]);
        $resultado = $this->consultar($sparql);

        if (!$resultado['ok']) {
            return ['ok' => false, 'fatos' => [], 'erro' => $resultado['erro']];
        }

        $fatos = [];

        foreach ($resultado['linhas'] as $linha) {
            $propriedade = $this->propriedadeDaUri($linha['p'] ?? '');

            if ($propriedade === null) {
                continue;
            }

            $rotulo = trim((string) ($linha['valorLabel'] ?? ''));
            $bruto  = trim((string) ($linha['valor'] ?? ''));

            // Quando nao ha rotulo, o SPARQL devolve a propria URI.
            if ($rotulo === '' || str_starts_with($rotulo, 'http')) {
                $rotulo = str_starts_with($bruto, 'http') ? '' : $bruto;
            }

            if ($rotulo === '') {
                continue;
            }

            $fatos[$propriedade] ??= [];

            if (!in_array($rotulo, $fatos[$propriedade], true)) {
                $fatos[$propriedade][] = $rotulo;
            }
        }

        return ['ok' => true, 'fatos' => $fatos, 'erro' => null];
    }

    /**
     * Rotulos alternativos em portugues (regra 42).
     *
     * @return array<int,string>
     */
    public function rotulosAlternativos(string $qid): array
    {
        $sparql = $this->carregarConsulta('alt_labels', ['QID' => $this->limparQid($qid)]);
        $resultado = $this->consultar($sparql);

        if (!$resultado['ok']) {
            return [];
        }

        $rotulos = [];
        foreach ($resultado['linhas'] as $linha) {
            $texto = trim((string) ($linha['alt'] ?? ''));
            if ($texto !== '' && mb_strlen($texto) <= 180) {
                $rotulos[$texto] = $texto;
            }
        }

        return array_values($rotulos);
    }

    /** Extrai "P106" de "http://www.wikidata.org/prop/direct/P106". */
    private function propriedadeDaUri(string $uri): ?string
    {
        if (preg_match('#/(P\d+)$#', $uri, $encontrado) === 1) {
            return $encontrado[1];
        }

        return null;
    }

    public function limparQid(string $qid): string
    {
        if (preg_match('/Q\d+/', $qid, $encontrado) === 1) {
            return $encontrado[0];
        }

        throw new \RuntimeException('QID invalido: ' . $qid);
    }

    /** Transforma a URI do item na URL da pagina do Wikidata. */
    public function urlDoItem(string $qid): string
    {
        return 'https://www.wikidata.org/wiki/' . $this->limparQid($qid);
    }
}
