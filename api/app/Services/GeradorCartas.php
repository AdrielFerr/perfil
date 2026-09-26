<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;
use App\Core\Database;
use App\Models\Carta;
use App\Models\Categoria;
use App\Models\Dica;
use App\Models\RespostaAlternativa;

/**
 * Gera cartas novas a partir do Wikidata e da Wikipedia, sem nenhuma IA.
 *
 * Fluxo de uma carta:
 *   1. sorteia um tema famoso no Wikidata (filtrado por sitelinks)
 *   2. descarta se o QID ou a resposta normalizada ja existem
 *   3. busca os fatos estruturados do tema
 *   4. pega os rotulos alternativos (respostas aceitas)
 *   5. monta as dicas com os modelos de frase
 *   6. completa com frases do resumo da Wikipedia, se faltar
 *   7. se nao fechar 20 dicas boas, joga fora e tenta outro tema
 *   8. grava carta + dicas + alternativas numa transacao
 *
 * A mesma classe e usada pelo painel (/admin/gerar) e pelo script
 * bin/gerar-cartas.php (regra 17).
 */
final class GeradorCartas
{
    private WikidataClient $wikidata;
    private WikipediaClient $wikipedia;
    private MontadorDicas $montador;
    private VerificadorPalpite $verificador;
    private Carta $cartas;
    private Categoria $categorias;
    private Dica $dicas;
    private RespostaAlternativa $alternativas;

    /** @var array<int,string> mensagens do que aconteceu, para mostrar no painel */
    private array $registro = [];

    /** QIDs e anos ja tentados nesta execucao. */
    private array $jaTentados = [];

    /** null = mundo todo; 'brasil' = so tema brasileiro. */
    private ?string $recorte = null;

    public function __construct(
        ?WikidataClient $wikidata = null,
        ?WikipediaClient $wikipedia = null,
        ?MontadorDicas $montador = null,
        ?VerificadorPalpite $verificador = null
    ) {
        $this->wikidata    = $wikidata ?? new WikidataClient();
        $this->wikipedia   = $wikipedia ?? new WikipediaClient();
        $this->verificador = $verificador ?? new VerificadorPalpite();
        $this->montador    = $montador ?? new MontadorDicas(null, $this->verificador);

        $this->cartas       = new Carta();
        $this->categorias   = new Categoria();
        $this->dicas        = new Dica();
        $this->alternativas = new RespostaAlternativa();
    }

    /** @return array<int,string> */
    public function registro(): array
    {
        return $this->registro;
    }

    private function anotar(string $mensagem): void
    {
        $this->registro[] = $mensagem;
    }

    /**
     * Gera N cartas, revezando entre as categorias.
     *
     * @return array{geradas:int, pedidas:int, por_categoria:array<string,int>, registro:array<int,string>}
     */
    public function gerar(int $quantidade, ?string $categoriaFixa = null, ?string $recorte = null): array
    {
        $quantidade = max(1, min(50, $quantidade));
        $this->recorte = $recorte;

        // A carta de ANO nao tem recorte de pais: o ano e o mesmo no mundo todo.
        if ($recorte !== null && $categoriaFixa === 'ano') {
            $this->anotar('[ano] o recorte por pais nao se aplica a ano: gerando normal.');
            $this->recorte = null;
        }

        $ordem = $categoriaFixa !== null && $categoriaFixa !== ''
            ? [$categoriaFixa]
            : ['pessoa', 'lugar', 'coisa', 'ano'];

        $geradas = 0;
        $porCategoria = array_fill_keys($ordem, 0);
        $tentativasSeguidasSemSucesso = 0;

        for ($i = 0; $i < $quantidade; $i++) {
            $categoria = $ordem[$i % count($ordem)];

            $id = $this->gerarUma($categoria);

            if ($id === null) {
                $tentativasSeguidasSemSucesso++;

                // Depois de muitas falhas seguidas, para em vez de
                // martelar o Wikidata (regra 46).
                if ($tentativasSeguidasSemSucesso >= 6) {
                    $this->anotar('Parei antes do fim: nao consegui fechar cartas boas nas ultimas tentativas.');
                    break;
                }

                continue;
            }

            $tentativasSeguidasSemSucesso = 0;
            $geradas++;
            $porCategoria[$categoria] = ($porCategoria[$categoria] ?? 0) + 1;
        }

        return [
            'geradas'       => $geradas,
            'pedidas'       => $quantidade,
            'por_categoria' => $porCategoria,
            'registro'      => $this->registro,
        ];
    }

    /** Devolve o id da carta criada ou null se nao deu. */
    public function gerarUma(string $categoria): ?int
    {
        try {
            return $categoria === 'ano'
                ? $this->gerarCartaDeAno()
                : $this->gerarCartaDeTema($categoria);
        } catch (\Throwable $e) {
            Database::desfazer();
            $this->anotar(sprintf('[%s] falhou: %s', $categoria, $e->getMessage()));
            return null;
        }
    }

    // =================================================================
    // Pessoa, lugar e coisa
    // =================================================================

    private function gerarCartaDeTema(string $categoria): ?int
    {
        $templates = $this->montador->templates($categoria);
        $focos = $templates['focos'] ?? [];

        if ($focos === []) {
            $this->anotar(sprintf('[%s] nenhum foco configurado em templates_dicas/%s.json.', $categoria, $categoria));
            return null;
        }

        // Tema brasileiro tem menos artigo em outras Wikipedias que uma
        // celebridade global, entao o piso de fama dele e outro.
        $minimoSitelinks = $this->recorte === 'brasil'
            ? (int) Configuracao::obter('gerador.minimo_sitelinks_brasil', 25)
            : (int) Configuracao::obter('gerador.minimo_sitelinks', 40);
        $limite = (int) Configuracao::obter('gerador.candidatos_por_consulta', 60);
        $deslocamento = (int) Configuracao::obter('gerador.deslocamento_maximo', 0);

        // Ate 4 focos diferentes por carta antes de desistir.
        for ($rodada = 0; $rodada < 4; $rodada++) {
            $foco = $focos[array_rand($focos)];

            // A consulta vem ordenada do mais famoso para o menos. Pular
            // linhas variava mais as cartas, mas jogava a gente na cauda:
            // era de la que saiam os temas que ninguem da mesa conhece.
            // O padrao e zero; mude em gerador.deslocamento_maximo.
            $linhas = $this->wikidata->listarCandidatos(
                $categoria,
                (string) $foco['qid'],
                $minimoSitelinks,
                $limite,
                $deslocamento > 0 ? random_int(0, $deslocamento) : 0,
                $this->recorte
            );

            if ($linhas['ok'] === false) {
                $this->anotar(sprintf('[%s] %s', $categoria, (string) $linhas['erro']));
                continue;
            }

            $linhas = $linhas['linhas'];

            if ($linhas === []) {
                continue;
            }

            shuffle($linhas);

            foreach ($linhas as $linha) {
                $id = $this->tentarTema($categoria, $linha);

                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** @param array<string,string> $linha */
    private function tentarTema(string $categoria, array $linha): ?int
    {
        $uri = (string) ($linha['item'] ?? '');
        $resposta = trim((string) ($linha['itemLabel'] ?? ''));
        $artigo = (string) ($linha['artigo'] ?? '');

        if ($uri === '' || $resposta === '') {
            return null;
        }

        $qid = $this->wikidata->limparQid($uri);

        if (isset($this->jaTentados[$qid])) {
            return null;
        }
        $this->jaTentados[$qid] = true;

        // Rotulo que continuou em ingles ou que e o proprio QID nao serve.
        if (preg_match('/^Q\d+$/', $resposta) === 1) {
            return null;
        }

        if (mb_strlen($resposta) > 120) {
            return null;
        }

        // Regras 48 e 49: nada de tema repetido.
        $respostaNormalizada = Normalizador::normalizar($resposta);

        if ($respostaNormalizada === '') {
            return null;
        }

        if ($this->cartas->existeQid($qid) || $this->cartas->existeRespostaNormalizada($respostaNormalizada)) {
            return null;
        }

        // --- Fatos do Wikidata ---
        $fatosResultado = $this->wikidata->fatosDoItem($qid, $categoria);

        if (!$fatosResultado['ok']) {
            $this->anotar(sprintf('[%s] %s: %s', $categoria, $resposta, (string) $fatosResultado['erro']));
            return null;
        }

        $fatos = $fatosResultado['fatos'];

        // Tema magro nunca fecha 20 dicas: corta cedo e economiza consulta.
        if (count($fatos) < 6) {
            return null;
        }

        // --- Respostas alternativas (regra 42) ---
        $alternativas = $this->prepararAlternativas(
            $this->wikidata->rotulosAlternativos($qid),
            $resposta
        );

        $textosAlternativos = array_column($alternativas, 'texto');

        // --- Dicas vindas dos fatos ---
        $candidatos = $this->montador->candidatosPorFatos(
            $categoria,
            $resposta,
            $textosAlternativos,
            $fatos
        );

        // --- Resumo da Wikipedia para completar (regra 40) ---
        $tituloArtigo = $this->tituloDoArtigo($artigo, $resposta);
        $resumo = $this->wikipedia->resumo($tituloArtigo);
        $textoResumo = null;
        $urlFonte = $artigo !== '' ? $artigo : null;

        if ($resumo['ok']) {
            $textoResumo = $resumo['resumo'];
            $urlFonte = $resumo['url'] ?? $urlFonte;

            // Pega sempre um bom punhado: parte vai cair no filtro de
            // vazamento e na remocao de repetidas.
            $candidatos = array_merge(
                $candidatos,
                $this->montador->candidatosDoResumo(
                    $this->wikipedia->frasesDoResumo($textoResumo),
                    $resposta,
                    $textosAlternativos,
                    10
                )
            );
        }

        // Frases genericas ficam de reserva: so entram se faltar dica.
        // Sete, e nao cinco: e melhor ter Maradona com tres frases de recheio
        // do que um tema que ninguem da mesa conhece com 20 dicas de verdade.
        $reserva = $this->montador->candidatosGenericos($categoria, 7);

        // --- Selecao final (regra 41) ---
        $dicas = $this->montador->selecionar($candidatos, $reserva);

        if ($dicas === null) {
            $this->anotar(sprintf(
                '[%s] "%s" descartado: so sobraram %d dicas aproveitaveis.',
                $categoria,
                $resposta,
                $this->montador->contarUteis(array_merge($candidatos, $reserva))
            ));
            return null;
        }

        return $this->gravar(
            $categoria,
            $qid,
            $resposta,
            $respostaNormalizada,
            $urlFonte,
            $textoResumo,
            $dicas,
            $alternativas
        );
    }

    private function tituloDoArtigo(string $urlArtigo, string $resposta): string
    {
        if ($urlArtigo === '') {
            return $resposta;
        }

        $caminho = parse_url($urlArtigo, PHP_URL_PATH) ?: '';
        $titulo = rawurldecode(basename($caminho));

        return $titulo !== '' ? str_replace('_', ' ', $titulo) : $resposta;
    }

    // =================================================================
    // Ano
    // =================================================================

    private function gerarCartaDeAno(): ?int
    {
        $templates = $this->montador->templates('ano');
        $minimo = (int) ($templates['intervalo']['ano_minimo'] ?? 1850);
        $maximo = (int) ($templates['intervalo']['ano_maximo'] ?? 2020);

        for ($tentativa = 0; $tentativa < 6; $tentativa++) {
            $ano = random_int($minimo, $maximo);
            $chave = 'ano-' . $ano;

            if (isset($this->jaTentados[$chave])) {
                continue;
            }
            $this->jaTentados[$chave] = true;

            $resposta = (string) $ano;

            if ($this->cartas->existeRespostaNormalizada($resposta)) {
                continue;
            }

            $listas = $this->buscarListasDoAno($ano, $templates);
            $candidatos = $this->montador->candidatosDoAno($ano, $listas);
            $reserva = $this->montador->candidatosGenericos('ano', 7);

            $dicas = $this->montador->selecionar($candidatos, $reserva);

            if ($dicas === null) {
                $this->anotar(sprintf(
                    '[ano] %d descartado: so sobraram %d dicas aproveitaveis.',
                    $ano,
                    $this->montador->contarUteis(array_merge($candidatos, $reserva))
                ));
                continue;
            }

            return $this->gravar(
                'ano',
                null,
                $resposta,
                $resposta,
                'https://pt.wikipedia.org/wiki/' . $ano,
                null,
                $dicas,
                []
            );
        }

        return null;
    }

    /** @return array<string,array<int,string>> */
    private function buscarListasDoAno(int $ano, array $templates): array
    {
        $listas = [];

        foreach ($templates['consultas'] ?? [] as $nome => $config) {
            $sparql = $this->wikidata->carregarConsulta((string) $config['arquivo'], [
                'ANO'           => $ano,
                'PROXIMO_ANO'   => $ano + 1,
                'MIN_SITELINKS' => (int) ($config['minimo_sitelinks'] ?? 45),
                'LIMITE'        => (int) ($config['limite'] ?? 20),
            ]);

            $resultado = $this->wikidata->consultar($sparql);

            if (!$resultado['ok']) {
                $this->anotar(sprintf('[ano] %d, %s: %s', $ano, $nome, (string) $resultado['erro']));
                continue;
            }

            $itens = [];
            foreach ($resultado['linhas'] as $linha) {
                $rotulo = trim((string) ($linha['itemLabel'] ?? ''));

                if ($rotulo === '' || preg_match('/^Q\d+$/', $rotulo) === 1) {
                    continue;
                }

                $itens[] = $rotulo;
            }

            $listas[$nome] = $itens;
        }

        return $listas;
    }

    // =================================================================
    // Gravacao
    // =================================================================

    /**
     * @param array<int,array{texto:string,texto_normalizado:string}> $alternativas
     */
    private function prepararAlternativas(array $rotulos, string $resposta): array
    {
        $respostaNormalizada = Normalizador::normalizar($resposta);
        $saida = [];
        $vistos = [$respostaNormalizada => true];

        foreach ($rotulos as $rotulo) {
            $texto = trim((string) $rotulo);
            $normalizado = Normalizador::normalizar($texto);

            if ($normalizado === '' || isset($vistos[$normalizado])) {
                continue;
            }

            if (mb_strlen($texto) > 180) {
                continue;
            }

            $vistos[$normalizado] = true;

            $saida[] = [
                'texto'             => $texto,
                'texto_normalizado' => $normalizado,
            ];
        }

        return $saida;
    }

    private function totalDicas(): int
    {
        return (int) Configuracao::obter('jogo.dicas_por_carta', 20);
    }

    private function gravar(
        string $categoria,
        ?string $qid,
        string $resposta,
        string $respostaNormalizada,
        ?string $urlFonte,
        ?string $resumo,
        array $dicas,
        array $alternativas
    ): ?int {
        $categoriaId = $this->categorias->idPorChave($categoria);

        if ($categoriaId === null) {
            throw new \RuntimeException('Categoria desconhecida no banco: ' . $categoria);
        }

        $status = Configuracao::obter('gerador.aprovar_automaticamente', false) === true
            ? 'aprovada'
            : 'pendente';

        Database::iniciarTransacao();

        try {
            $cartaId = $this->cartas->criar(
                $categoriaId,
                $qid,
                $resposta,
                $respostaNormalizada,
                $urlFonte,
                $resumo,
                $status
            );

            $this->dicas->criarEmLote($cartaId, $dicas);

            if ($alternativas !== []) {
                $this->alternativas->criarEmLote($cartaId, $alternativas);
            }

            Database::confirmar();
        } catch (\PDOException $e) {
            Database::desfazer();

            // 23000 = violacao de chave unica: alguem cadastrou o mesmo tema.
            if ($e->getCode() === '23000') {
                $this->anotar(sprintf('[%s] "%s" ja existia no banco.', $categoria, $resposta));
                return null;
            }

            throw $e;
        }

        $this->anotar(sprintf(
            '[%s] "%s" criada com %d dicas (%s).',
            $categoria,
            $resposta,
            count($dicas),
            $status
        ));

        return $cartaId;
    }
}
