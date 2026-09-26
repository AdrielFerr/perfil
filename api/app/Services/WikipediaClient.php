<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;

/**
 * Busca o resumo do artigo na Wikipedia em portugues.
 * Usado quando faltam fatos do Wikidata para fechar 20 dicas (regra 40).
 */
final class WikipediaClient extends ClienteHttp
{
    private function endpoint(): string
    {
        return (string) Configuracao::obter(
            'gerador.endpoint_wikipedia',
            'https://pt.wikipedia.org/api/rest_v1/page/summary/'
        );
    }

    /**
     * @return array{ok:bool, resumo:string, url:?string, erro:?string}
     */
    public function resumo(string $titulo): array
    {
        $titulo = trim($titulo);

        if ($titulo === '') {
            return ['ok' => false, 'resumo' => '', 'url' => null, 'erro' => 'Titulo vazio.'];
        }

        $url = $this->endpoint() . rawurlencode(str_replace(' ', '_', $titulo));
        $resposta = $this->buscar($url, ['Accept' => 'application/json']);

        if (!$resposta['ok']) {
            return [
                'ok' => false,
                'resumo' => '',
                'url' => null,
                'erro' => 'Wikipedia: ' . ($resposta['erro'] ?? 'erro desconhecido'),
            ];
        }

        $dados = json_decode($resposta['corpo'], true);

        if (!is_array($dados) || empty($dados['extract'])) {
            return ['ok' => false, 'resumo' => '', 'url' => null, 'erro' => 'Artigo sem resumo.'];
        }

        // Desambiguacao nao serve como fonte de dicas.
        if (($dados['type'] ?? '') === 'disambiguation') {
            return ['ok' => false, 'resumo' => '', 'url' => null, 'erro' => 'Pagina de desambiguacao.'];
        }

        return [
            'ok' => true,
            'resumo' => (string) $dados['extract'],
            'url' => $dados['content_urls']['desktop']['page'] ?? ('https://pt.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $titulo))),
            'erro' => null,
        ];
    }

    /**
     * Quebra o resumo em frases limpas, prontas para virar dica.
     *
     * @return array<int,string>
     */
    public function frasesDoResumo(string $resumo): array
    {
        // Protege abreviacoes comuns para nao quebrar a frase no lugar errado.
        $protegido = str_replace(
            [' a.C.', ' d.C.', 'Dr.', 'Sr.', 'Sra.', 'Prof.', 'etc.', 'Jr.'],
            [' a§C§', ' d§C§', 'Dr§', 'Sr§', 'Sra§', 'Prof§', 'etc§', 'Jr§'],
            $resumo
        );

        $partes = preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-Ú"«(])/u', $protegido) ?: [];

        $frases = [];
        foreach ($partes as $parte) {
            $frase = trim(str_replace('§', '.', $parte));

            if ($frase === '') {
                continue;
            }

            // Descarta pedacos grandes demais ou pequenos demais.
            $tamanho = mb_strlen($frase);
            if ($tamanho < 25 || $tamanho > 180) {
                continue;
            }

            $frases[] = $frase;
        }

        return $frases;
    }
}
