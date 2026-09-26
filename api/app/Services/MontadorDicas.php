<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;

/**
 * Transforma fatos crus do Wikidata em 20 dicas prontas para jogar.
 *
 *  36 - frases em primeira pessoa, no estilo do jogo original
 *  37 - varios modelos por propriedade, sorteados
 *  38 - numero vira faixa ou aproximacao
 *  39 - mistura de dificuldade e posicoes de 1 a 20 embaralhadas
 *  40 - completa com frases do resumo da Wikipedia
 *  41 - se nao fechar 20 dicas boas, devolve null e o tema e descartado
 *  43 - nenhuma dica pode conter a resposta
 *  44 - dicas curtas, em portugues do Brasil
 *  50 - nada de dica repetida nem de duas dicas sobre o mesmo fato
 */
final class MontadorDicas
{
    private const TAMANHO_MAXIMO_DICA = 170;
    /**
     * Valor maior que isso vira dica comprida e dificil de ler em voz
     * alta (regra 44). Costuma ser rotulo oficial em ingles, do tipo
     * "Primetime Emmy Award for Outstanding Casting for a Comedy Series".
     */
    private const TAMANHO_MAXIMO_VALOR = 55;
    /**
     * Acima disso duas dicas sao praticamente a mesma frase.
     * Cuidado ao baixar: modelos com esqueleto parecido ("Quem me dirigiu
     * foi X" e "Quem me escreveu foi Y") passam de 0.78 de semelhanca e
     * seriam descartados sem necessidade, mesmo falando de fatos diferentes.
     */
    private const SEMELHANCA_MAXIMA = 0.88;

    private string $pastaTemplates;
    private VerificadorPalpite $verificador;

    /** @var array<string,array> cache dos arquivos JSON ja lidos */
    private array $templates = [];

    public function __construct(?string $pastaTemplates = null, ?VerificadorPalpite $verificador = null)
    {
        $this->pastaTemplates = $pastaTemplates ?? dirname(__DIR__, 2) . '/templates_dicas';
        $this->verificador = $verificador ?? new VerificadorPalpite();
    }

    public function templates(string $categoria): array
    {
        if (isset($this->templates[$categoria])) {
            return $this->templates[$categoria];
        }

        $arquivo = $this->pastaTemplates . '/' . $categoria . '.json';

        if (!is_file($arquivo)) {
            throw new \RuntimeException('Arquivo de modelos nao encontrado: ' . $arquivo);
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);

        if (!is_array($dados)) {
            throw new \RuntimeException('Arquivo de modelos com JSON invalido: ' . $arquivo);
        }

        return $this->templates[$categoria] = $dados;
    }

    private function totalDicas(): int
    {
        return (int) Configuracao::obter('jogo.dicas_por_carta', 20);
    }

    // =================================================================
    // Pessoa, lugar e coisa
    // =================================================================

    /**
     * @param array<string,array<int,string>> $fatos mapa P### => valores
     * @return array<int,array{texto:string,dificuldade:string,propriedade:string}>
     */
    public function candidatosPorFatos(
        string $categoria,
        string $resposta,
        array $alternativas,
        array $fatos
    ): array {
        $templates = $this->templates($categoria);
        $candidatos = [];

        // O mesmo valor nao vira duas dicas. Sem isto, o Minecraft ganhava
        // "quem me projetou", "quem me desenvolveu" e "meu criador" com o
        // mesmo Markus Persson: tres dicas queimadas no mesmo fato.
        $valoresUsados = [];

        foreach ($templates['propriedades'] ?? [] as $propriedade => $config) {
            if (!isset($fatos[$propriedade])) {
                continue;
            }

            $maximo = (int) ($config['max_dicas'] ?? 1);
            $modelos = $config['modelos'] ?? [];

            if ($modelos === []) {
                continue;
            }

            $valores = $fatos[$propriedade];
            shuffle($valores);

            $geradas = 0;

            foreach ($valores as $valorBruto) {
                if ($geradas >= $maximo) {
                    break;
                }

                // Regra 37: sorteia entre os modelos disponiveis.
                $modelosEmbaralhados = $modelos;
                shuffle($modelosEmbaralhados);

                foreach ($modelosEmbaralhados as $modelo) {
                    $valor = $this->formatarValor($valorBruto, $modelo['formato'] ?? 'texto');

                    if ($valor === null) {
                        continue;
                    }

                    // Faixas e anos podem se repetir sem problema ("nasci nos
                    // anos 80" e "estreei nos anos 80" sao fatos diferentes).
                    // Nome proprio, nao: esse vale uma dica so.
                    $chaveValor = Normalizador::normalizar($valor);

                    if (mb_strlen($valor) > 3 && isset($valoresUsados[$chaveValor])) {
                        continue;
                    }

                    $texto = $this->preencher($modelo['texto'], ['valor' => $valor]);

                    if (!$this->dicaEhBoa($texto, $resposta, $alternativas)) {
                        continue;
                    }

                    $candidatos[] = [
                        'texto'       => $texto,
                        'dificuldade' => $this->dificuldadeValida($modelo['dificuldade'] ?? 'media'),

                        // Valores diferentes da mesma propriedade sao fatos
                        // diferentes ("sou ator" e "sou diretor"), entao cada
                        // um ganha uma chave propria. O limite de quantos
                        // entram continua sendo o max_dicas do modelo.
                        'propriedade' => $geradas === 0 ? $propriedade : $propriedade . '#' . $geradas,
                    ];

                    $valoresUsados[$chaveValor] = true;
                    $geradas++;
                    break;
                }
            }
        }

        foreach ($this->candidatosDerivados($templates, $fatos, $resposta, $alternativas) as $derivado) {
            $candidatos[] = $derivado;
        }

        return $candidatos;
    }

    /**
     * Ultimo recurso: frases vagas e sempre verdadeiras da categoria.
     * Entram so para completar as 20 dicas, no maximo tres por carta,
     * e sao todas dificeis de proposito.
     *
     * @return array<int,array{texto:string,dificuldade:string,propriedade:string}>
     */
    public function candidatosGenericos(string $categoria, int $quantidade): array
    {
        if ($quantidade <= 0) {
            return [];
        }

        $genericas = $this->templates($categoria)['genericas'] ?? [];

        if ($genericas === []) {
            return [];
        }

        shuffle($genericas);
        $saida = [];

        foreach (array_slice($genericas, 0, $quantidade) as $indice => $modelo) {
            $saida[] = [
                'texto'       => (string) $modelo['texto'],
                'dificuldade' => $this->dificuldadeValida($modelo['dificuldade'] ?? 'dificil'),
                'propriedade' => 'generica_' . $indice,
            ];
        }

        return $saida;
    }

    /**
     * Dicas que nascem da AUSENCIA ou da QUANTIDADE de um fato,
     * e nao do valor dele. Ex.: "Eu ja nao estou mais neste mundo."
     */
    private function candidatosDerivados(
        array $templates,
        array $fatos,
        string $resposta,
        array $alternativas
    ): array {
        $saida = [];

        foreach ($templates['derivadas'] ?? [] as $derivada) {
            $condicao = (string) ($derivada['condicao'] ?? '');

            if (!$this->condicaoAtendida($condicao, $fatos)) {
                continue;
            }

            $modelos = $derivada['modelos'] ?? [];

            if ($modelos === []) {
                continue;
            }

            $modelo = $modelos[array_rand($modelos)];
            $texto = (string) $modelo['texto'];

            if (!$this->dicaEhBoa($texto, $resposta, $alternativas)) {
                continue;
            }

            $saida[] = [
                'texto'       => $texto,
                'dificuldade' => $this->dificuldadeValida($modelo['dificuldade'] ?? 'dificil'),
                'propriedade' => 'derivada_' . ($derivada['chave'] ?? 'x'),
            ];
        }

        return $saida;
    }

    private function condicaoAtendida(string $condicao, array $fatos): bool
    {
        if (preg_match('/^tem_varios_(P\d+)$/', $condicao, $achado) === 1) {
            return count($fatos[$achado[1]] ?? []) > 1;
        }

        if (preg_match('/^tem_(P\d+)$/', $condicao, $achado) === 1) {
            return !empty($fatos[$achado[1]]);
        }

        if (preg_match('/^sem_(P\d+)$/', $condicao, $achado) === 1) {
            return empty($fatos[$achado[1]]);
        }

        return false;
    }

    // =================================================================
    // Ano
    // =================================================================

    /**
     * @param array<string,array<int,string>> $listas eventos/nascimentos/mortes/lancamentos
     * @return array<int,array{texto:string,dificuldade:string,propriedade:string}>
     */
    public function candidatosDoAno(int $ano, array $listas): array
    {
        $templates = $this->templates('ano');
        $resposta = (string) $ano;
        $candidatos = [];

        // --- Fatos calculados, sem consultar nada (regra 35d) ---
        foreach ($templates['calculadas'] ?? [] as $calculada) {
            $chave = (string) ($calculada['chave'] ?? '');
            [$valores, $positivo] = $this->calcularDoAno($chave, $ano);

            if ($valores === null) {
                continue;
            }

            $modelos = $positivo
                ? ($calculada['modelos'] ?? [])
                : ($calculada['modelos_negativo'] ?? []);

            if ($modelos === []) {
                continue;
            }

            $modelo = $modelos[array_rand($modelos)];
            $texto = $this->preencher((string) $modelo['texto'], $valores);

            if (!$this->dicaEhBoa($texto, $resposta, [])) {
                continue;
            }

            $candidatos[] = [
                'texto'       => $texto,
                'dificuldade' => $this->dificuldadeValida($modelo['dificuldade'] ?? 'dificil'),
                'propriedade' => 'calculado_' . $chave,
            ];
        }

        // --- Acontecimentos, nascimentos, mortes e lancamentos ---
        foreach ($templates['listas'] ?? [] as $nome => $config) {
            $itens = $listas[$nome] ?? [];

            if ($itens === []) {
                continue;
            }

            $modelos = $config['modelos'] ?? [];
            $maximo = (int) ($config['max_dicas'] ?? 3);

            if ($modelos === []) {
                continue;
            }

            shuffle($itens);
            $geradas = 0;

            foreach ($itens as $item) {
                if ($geradas >= $maximo) {
                    break;
                }

                $valor = $this->limparTexto((string) $item);

                if ($valor === null) {
                    continue;
                }

                $modelo = $modelos[array_rand($modelos)];
                $texto = $this->preencher((string) $modelo['texto'], ['valor' => $valor]);

                if (!$this->dicaEhBoa($texto, $resposta, [])) {
                    continue;
                }

                $candidatos[] = [
                    'texto'       => $texto,
                    'dificuldade' => $this->dificuldadeValida($modelo['dificuldade'] ?? 'media'),
                    'propriedade' => $nome . '_' . $geradas,
                ];

                $geradas++;
            }
        }

        return $candidatos;
    }

    /**
     * @return array{0:?array<string,string|int>, 1:bool} valores e se o caso e o positivo
     */
    private function calcularDoAno(string $chave, int $ano): array
    {
        $digitos = str_split((string) abs($ano));
        $bissexto = ($ano % 4 === 0 && $ano % 100 !== 0) || $ano % 400 === 0;

        return match ($chave) {
            'seculo'         => [['seculo' => $this->romano(intdiv($ano - 1, 100) + 1)], true],
            'decada'         => [['decada' => (string) (intdiv($ano, 10) * 10)], true],
            'bissexto'       => [[], $bissexto],
            'paridade'       => [[], $ano % 2 === 0],
            'soma_digitos'   => [['soma' => (string) array_sum(array_map('intval', $digitos))], true],
            'ultimo_digito'  => [['ultimo' => $digitos[count($digitos) - 1]], true],
            'distancia'      => [['distancia' => (string) $this->arredondarParaBaixo((int) date('Y') - $ano, 10)], true],
            'metade_seculo'  => [[], ($ano % 100) <= 50 && ($ano % 100) !== 0],
            'multiplo_quatro' => [[], $ano % 4 === 0],
            'olimpiada'      => [[], $ano % 4 === 0 && $ano >= 1896],
            'copa'           => [[], $ano % 4 === 2 && $ano >= 1930],
            default          => [null, true],
        };
    }

    private function arredondarParaBaixo(int $numero, int $passo): int
    {
        return max($passo, intdiv($numero, $passo) * $passo);
    }

    private function romano(int $numero): string
    {
        $mapa = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100  => 'C', 90  => 'XC', 50  => 'L', 40  => 'XL',
            10   => 'X', 9   => 'IX', 5   => 'V', 4   => 'IV', 1 => 'I',
        ];

        $saida = '';
        foreach ($mapa as $valor => $letra) {
            while ($numero >= $valor) {
                $saida .= $letra;
                $numero -= $valor;
            }
        }

        return $saida;
    }

    // =================================================================
    // Resumo da Wikipedia (regra 40)
    // =================================================================

    /**
     * @param array<int,string> $frases
     * @return array<int,array{texto:string,dificuldade:string,propriedade:string}>
     */
    public function candidatosDoResumo(
        array $frases,
        string $resposta,
        array $alternativas,
        int $quantidade
    ): array {
        $saida = [];
        $indice = 0;

        foreach ($frases as $posicao => $frase) {
            if (count($saida) >= $quantidade) {
                break;
            }

            // Regra 38: numero exato nao entra em dica. A frase da
            // Wikipedia vem inteira, entao quem traz medida precisa e
            // descartada em vez de reescrita.
            if ($this->temNumeroPreciso($frase)) {
                continue;
            }

            $texto = $this->mascararResposta($frase, $resposta, $alternativas);

            if ($texto === null) {
                continue;
            }

            // Regra 40: se depois de mascarar a frase ainda entrega o tema,
            // ela e descartada.
            if (!$this->dicaEhBoa($texto, $resposta, $alternativas)) {
                continue;
            }

            $saida[] = [
                'texto'       => $texto,
                // A primeira frase do artigo costuma definir o tema: e a mais facil.
                'dificuldade' => $posicao === 0 ? 'facil' : 'media',
                'propriedade' => 'resumo_wikipedia_' . $indice,
            ];

            $indice++;
        }

        return $saida;
    }

    /**
     * A frase traz medida exata ou lista de datas?
     * Ex.: "com 780 km²", "1.234.567 habitantes",
     *      "Em 2010, 2011, 2012, 2013 e 2014, ganhou..."
     */
    private function temNumeroPreciso(string $frase): bool
    {
        $unidades = 'km²|km2|km|m²|m2|mm|cm|m|ha|hab|habitantes|metros|quilômetros|'
            . 'toneladas|litros|minutos|horas|anos|milhões|milhão|mil|bilhões';

        if (preg_match('/\d[\d.,]*\s*(' . $unidades . ')\b/iu', $frase) === 1) {
            return true;
        }

        // O "%" nao tem fronteira de palavra depois, entao vai a parte.
        if (preg_match('/\d[\d.,]*\s*%/u', $frase) === 1) {
            return true;
        }

        // Tres ou mais numeros soltos costumam ser lista de datas.
        return preg_match_all('/\d+/', $frase) >= 3;
    }

    /** Troca o nome do tema por "..." dentro da frase. */
    private function mascararResposta(string $frase, string $resposta, array $alternativas): ?string
    {
        $texto = trim($frase);

        $alvos = array_merge([$resposta], $alternativas);

        // Ordena do maior para o menor, senao "Vinci" sai antes de "Leonardo da Vinci".
        usort($alvos, static fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        foreach ($alvos as $alvo) {
            $alvo = trim((string) $alvo);

            if (mb_strlen($alvo) < 3) {
                continue;
            }

            $texto = (string) preg_replace(
                '/' . preg_quote($alvo, '/') . '/iu',
                '...',
                $texto
            );

            // Tambem mascara cada palavra significativa isolada.
            foreach (Normalizador::palavrasSignificativas($alvo, 5) as $palavra) {
                $texto = (string) preg_replace(
                    '/\b' . preg_quote($palavra, '/') . '[a-zà-ú]*/iu',
                    '...',
                    $texto
                );
            }
        }

        // Limpa reticencias grudadas e espacos sobrando.
        $texto = (string) preg_replace('/(\.\.\.\s*){2,}/u', '... ', $texto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);
        $texto = trim($texto);

        // Frase que virou so reticencias nao serve.
        $semReticencias = trim(str_replace('...', '', $texto));

        if (mb_strlen($semReticencias) < 25) {
            return null;
        }

        if (mb_strlen($texto) > self::TAMANHO_MAXIMO_DICA) {
            return null;
        }

        return $texto;
    }

    // =================================================================
    // Selecao final
    // =================================================================

    /**
     * Quantas dicas realmente aproveitaveis sobram depois de tirar
     * repetidas e parecidas. Serve para explicar por que um tema foi
     * descartado.
     */
    public function contarUteis(array $candidatos): int
    {
        return count($this->removerRepetidas($candidatos));
    }

    /**
     * Escolhe 20 dicas com a mistura de dificuldade desejada, embaralha
     * as posicoes e devolve prontas para gravar.
     *
     * @param array<int,array{texto:string,dificuldade:string,propriedade:string}> $candidatos
     * @param array<int,array{texto:string,dificuldade:string,propriedade:string}> $reserva
     *        frases genericas, usadas so se faltar dica de verdade
     * @return array<int,array{numero:int,texto:string,texto_normalizado:string,dificuldade:string,propriedade_origem:string}>|null
     */
    public function selecionar(array $candidatos, array $reserva = []): ?array
    {
        $total = $this->totalDicas();
        $unicos = $this->removerRepetidas($candidatos);

        // A reserva so entra se o tema nao deu dicas suficientes sozinho.
        if (count($unicos) < $total && $reserva !== []) {
            $unicos = $this->removerRepetidas(array_merge($unicos, $reserva));
        }

        if (count($unicos) < $total) {
            return null;
        }

        $mistura = (array) Configuracao::obter('gerador.mistura_dificuldade', [
            'dificil' => 11,
            'media'   => 6,
            'facil'   => 3,
        ]);

        $porDificuldade = ['dificil' => [], 'media' => [], 'facil' => []];

        foreach ($unicos as $candidato) {
            $porDificuldade[$candidato['dificuldade']][] = $candidato;
        }

        foreach ($porDificuldade as $nivel => $lista) {
            shuffle($lista);
            $porDificuldade[$nivel] = $lista;
        }

        $escolhidas = [];

        // Primeiro respeita a cota de cada nivel.
        foreach (['dificil', 'media', 'facil'] as $nivel) {
            $cota = (int) ($mistura[$nivel] ?? 0);

            for ($i = 0; $i < $cota && $porDificuldade[$nivel] !== []; $i++) {
                $escolhidas[] = array_shift($porDificuldade[$nivel]);
            }
        }

        // Depois completa com o que sobrou. A ordem comeca pelas medias e
        // faceis de proposito: sobra de dica dificil e o que deixava a carta
        // impossivel antes.
        foreach (['media', 'facil', 'dificil'] as $nivel) {
            while (count($escolhidas) < $total && $porDificuldade[$nivel] !== []) {
                $escolhidas[] = array_shift($porDificuldade[$nivel]);
            }
        }

        if (count($escolhidas) < $total) {
            return null;
        }

        $escolhidas = array_slice($escolhidas, 0, $total);

        // Regra 39: as posicoes de 1 a 20 sao embaralhadas.
        shuffle($escolhidas);

        $saida = [];
        foreach ($escolhidas as $indice => $dica) {
            $saida[] = [
                'numero'             => $indice + 1,
                'texto'              => $dica['texto'],
                'texto_normalizado'  => Normalizador::normalizar($dica['texto']),
                'dificuldade'        => $dica['dificuldade'],
                'propriedade_origem' => $dica['propriedade'],
            ];
        }

        return $saida;
    }

    /** Regra 50: fora dicas iguais, quase iguais ou sobre o mesmo fato. */
    private function removerRepetidas(array $candidatos): array
    {
        $saida = [];
        $normalizadas = [];
        $propriedades = [];

        foreach ($candidatos as $candidato) {
            $normalizada = Normalizador::normalizar($candidato['texto']);

            if ($normalizada === '' || isset($normalizadas[$normalizada])) {
                continue;
            }

            if (isset($propriedades[$candidato['propriedade']])) {
                continue;
            }

            $muitoParecida = false;
            foreach (array_keys($normalizadas) as $jaExistente) {
                if (Normalizador::semelhanca($normalizada, $jaExistente) >= self::SEMELHANCA_MAXIMA) {
                    $muitoParecida = true;
                    break;
                }
            }

            if ($muitoParecida) {
                continue;
            }

            $normalizadas[$normalizada] = true;
            $propriedades[$candidato['propriedade']] = true;
            $saida[] = $candidato;
        }

        return $saida;
    }

    // =================================================================
    // Auxiliares de texto e numero
    // =================================================================

    /** @param array<string,string|int> $valores */
    private function preencher(string $modelo, array $valores): string
    {
        $busca = [];
        $troca = [];

        foreach ($valores as $chave => $valor) {
            $busca[] = '{' . $chave . '}';
            $troca[] = (string) $valor;
        }

        return trim(str_replace($busca, $troca, $modelo));
    }

    /** Regras 43 e 44: sem vazamento, curta e legivel. */
    private function dicaEhBoa(string $texto, string $resposta, array $alternativas): bool
    {
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::TAMANHO_MAXIMO_DICA) {
            return false;
        }

        // Sobrou marcador sem preencher.
        if (str_contains($texto, '{')) {
            return false;
        }

        return !$this->verificador->vazaResposta($texto, $resposta, $alternativas);
    }

    private function dificuldadeValida(string $nivel): string
    {
        return in_array($nivel, ['dificil', 'media', 'facil'], true) ? $nivel : 'media';
    }

    /** Devolve null quando o valor nao serve para virar dica. */
    private function formatarValor(string $bruto, string $formato): ?string
    {
        $bruto = trim($bruto);

        if ($bruto === '') {
            return null;
        }

        return match ($formato) {
            'ano'                => $this->extrairAno($bruto),
            'decada'             => $this->formatarDecada($bruto),
            'seculo'             => $this->formatarSeculo($bruto),
            'idade_aproximada'   => $this->formatarIdade($bruto),
            'faixa_populacao'    => $this->formatarFaixa($bruto, ''),
            'faixa_area'         => $this->formatarFaixa($bruto, 'km²', 1),
            'faixa_altitude'     => $this->formatarFaixa($bruto, 'metros'),
            'faixa_comprimento'  => $this->formatarFaixa($bruto, 'km'),
            'faixa_altura'       => $this->formatarAltura($bruto),
            'faixa_duracao'      => $this->formatarDuracao($bruto),
            default              => $this->limparTexto($bruto),
        };
    }

    /** Tira parenteses, corta o que e longo demais e recusa lixo. */
    private function limparTexto(string $bruto): ?string
    {
        // Valor sem rotulo no Wikidata ou URL nao viram dica.
        if (preg_match('/^Q\d+$/', $bruto) === 1 || str_starts_with($bruto, 'http')) {
            return null;
        }

        $texto = (string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $bruto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);
        $texto = trim($texto, " \t\n\r\0\x0B.,;:");

        if ($texto === '' || mb_strlen($texto) > self::TAMANHO_MAXIMO_VALOR) {
            return null;
        }

        // Numero solto costuma ser um dado sem unidade: nao vira frase boa.
        if (preg_match('/^[\d.,+-]+$/', $texto) === 1) {
            return null;
        }

        // Regra 34: a dica e em portugues. Quando o Wikidata nao tem o rotulo
        // em portugues, o SPARQL cai no ingles e sai coisa como "Director of
        // the College de France" no meio da frase. Melhor nao ter a dica.
        if ($this->pareceEstrangeiro($texto)) {
            return null;
        }

        return $texto;
    }

    /**
     * Heuristica simples: palavras de ligacao que so existem em outra lingua.
     * Nao precisa ser perfeita, precisa ser barata e nao derrubar portugues.
     */
    private function pareceEstrangeiro(string $texto): bool
    {
        // So entram palavras que nao existem em portugues. Particulas de nome
        // proprio (van, von, della, del) ficam de fora de proposito: "Ludwig
        // van Beethoven" e uma dica perfeitamente boa em portugues.
        $palavras = [
            'of', 'the', 'and', 'for', 'with', 'from',   // ingles
            'des', 'du', 'les', 'sur', 'aux',            // frances
            'und', 'fur',                                // alemao
        ];

        $minusculo = ' ' . mb_strtolower($texto) . ' ';

        foreach ($palavras as $palavra) {
            if (str_contains($minusculo, ' ' . $palavra . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function extrairAno(string $bruto): ?string
    {
        if (preg_match('/(-?\d{1,4})-\d{2}-\d{2}/', $bruto, $achado) === 1) {
            return (string) (int) $achado[1];
        }

        if (preg_match('/^-?\d{1,4}$/', $bruto) === 1) {
            return (string) (int) $bruto;
        }

        return null;
    }

    private function formatarDecada(string $bruto): ?string
    {
        $ano = $this->extrairAno($bruto);

        if ($ano === null) {
            return null;
        }

        return (string) (intdiv((int) $ano, 10) * 10);
    }

    private function formatarSeculo(string $bruto): ?string
    {
        $ano = $this->extrairAno($bruto);

        if ($ano === null || (int) $ano <= 0) {
            return null;
        }

        return $this->romano(intdiv((int) $ano - 1, 100) + 1);
    }

    private function formatarIdade(string $bruto): ?string
    {
        $ano = $this->extrairAno($bruto);

        if ($ano === null) {
            return null;
        }

        $anos = (int) date('Y') - (int) $ano;

        if ($anos < 10) {
            return null;
        }

        return (string) $this->arredondarParaBaixo($anos, 10);
    }

    /**
     * Regra 38: numero vira aproximacao.
     * 8_515_767 -> "mais de 5 milhões de"   (com unidade: "... de km²")
     */
    private function formatarFaixa(string $bruto, string $unidade, int $minimo = 0): ?string
    {
        $numero = (float) str_replace(',', '.', preg_replace('/[^\d.,-]/', '', $bruto) ?? '');

        if ($numero <= $minimo) {
            return null;
        }

        $redondo = $this->numeroRedondoAbaixo($numero);

        if ($redondo <= 0) {
            return null;
        }

        $texto = 'mais de ' . $this->escreverNumero($redondo);

        if ($unidade === 'km²') {
            return $texto . ' km²';
        }

        if ($unidade !== '') {
            return $texto . ' ' . $unidade;
        }

        return $texto;
    }

    /** Maior numero "redondo" (1, 2 ou 5 vezes potencia de 10) abaixo do valor. */
    private function numeroRedondoAbaixo(float $numero): float
    {
        if ($numero < 10) {
            return floor($numero);
        }

        $potencia = 10 ** (int) floor(log10($numero));

        foreach ([5, 2, 1] as $multiplicador) {
            $candidato = $multiplicador * $potencia;
            if ($candidato <= $numero) {
                return (float) $candidato;
            }
        }

        return $potencia;
    }

    /** 5000000 -> "5 milhões de" | 500000 -> "500 mil" | 200 -> "200" */
    private function escreverNumero(float $numero): string
    {
        if ($numero >= 1_000_000_000) {
            $bilhoes = $numero / 1_000_000_000;
            $texto = $bilhoes == 1.0 ? 'um bilhão' : $this->semZeros($bilhoes) . ' bilhões';
            return $texto . ' de';
        }

        if ($numero >= 1_000_000) {
            $milhoes = $numero / 1_000_000;
            $texto = $milhoes == 1.0 ? 'um milhão' : $this->semZeros($milhoes) . ' milhões';
            return $texto . ' de';
        }

        if ($numero >= 1000) {
            $milhares = $numero / 1000;
            return $milhares == 1.0 ? 'mil' : $this->semZeros($milhares) . ' mil';
        }

        return $this->semZeros($numero);
    }

    private function semZeros(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 1, ',', '.'), '0'), ',');
    }

    /** 142 minutos -> "entre 120 e 150" (regra 38: nada de numero exato) */
    private function formatarDuracao(string $bruto): ?string
    {
        $minutos = (int) round((float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', $bruto) ?? ''));

        if ($minutos < 20 || $minutos > 400) {
            return null;
        }

        $faixas = [
            [20, 45], [45, 60], [60, 90], [90, 105], [105, 120],
            [120, 150], [150, 180], [180, 400],
        ];

        foreach ($faixas as [$de, $ate]) {
            if ($minutos < $ate) {
                return sprintf('entre %d e %d', $de, $ate);
            }
        }

        return null;
    }

    /** 1.88 -> "entre 1,80 m e 1,90 m" */
    private function formatarAltura(string $bruto): ?string
    {
        $metros = (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', $bruto) ?? '');

        // Alguns itens guardam a altura em centimetros.
        if ($metros > 3) {
            $metros /= 100;
        }

        if ($metros < 1.2 || $metros > 2.6) {
            return null;
        }

        $piso = floor($metros * 10) / 10;
        $teto = $piso + 0.1;

        return sprintf(
            'entre %s m e %s m',
            number_format($piso, 2, ',', '.'),
            number_format($teto, 2, ',', '.')
        );
    }
}
