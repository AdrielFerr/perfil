<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Configuracao;
use App\Core\Controller;
use App\Core\ExcecaoHttp;
use App\Core\Request;
use App\Core\Response;
use App\Models\Carta;
use App\Models\Categoria;
use App\Models\Dica;
use App\Models\RespostaAlternativa;
use App\Services\GeradorCartas;
use App\Services\Normalizador;
use App\Views\VisaoCarta;

/**
 * Painel: gerar, revisar, editar, aprovar e excluir cartas e dicas.
 * Todas as rotas passam antes pelo middleware AuthAdmin.
 */
final class AdminController extends Controller
{
    private Carta $cartas;
    private Dica $dicas;
    private RespostaAlternativa $alternativas;
    private Categoria $categorias;

    public function __construct()
    {
        $this->cartas = new Carta();
        $this->dicas = new Dica();
        $this->alternativas = new RespostaAlternativa();
        $this->categorias = new Categoria();
    }

    /** GET /api/admin/resumo */
    public function resumo(Request $req): Response
    {
        return $this->ok([
            'cartas'     => $this->cartas->estatisticas(),
            'categorias' => array_map(static fn (array $c): array => [
                'chave'     => $c['chave'],
                'nome'      => $c['nome'],
                'cor'       => $c['cor'],
                'icone'     => $c['icone'],
                'aprovadas' => (int) $c['aprovadas'],
                'pendentes' => (int) $c['pendentes'],
                'total'     => (int) $c['total'],
            ], $this->categorias->contagemDeCartas()),
        ]);
    }

    /** GET /api/admin/cartas?status=pendente&categoria=pessoa&busca=...&pagina=1 */
    public function listar(Request $req): Response
    {
        $filtros = [
            'status'    => $this->filtroOpcional($req->consulta('status'), ['pendente', 'aprovada', 'rejeitada']),
            'categoria' => $this->filtroOpcional($req->consulta('categoria'), ['pessoa', 'lugar', 'ano', 'coisa']),
            'busca'     => trim((string) $req->consulta('busca', '')),
        ];

        $pagina = max(1, (int) $req->consulta('pagina', 1));
        $porPagina = min(100, max(5, (int) $req->consulta('por_pagina', 20)));

        return $this->ok(VisaoCarta::listagem(
            $this->cartas->listar($filtros, $pagina, $porPagina),
            $this->cartas->contar($filtros),
            $pagina,
            $porPagina
        ));
    }

    private function filtroOpcional(mixed $valor, array $permitidos): ?string
    {
        $valor = is_scalar($valor) ? trim((string) $valor) : '';

        return in_array($valor, $permitidos, true) ? $valor : null;
    }

    /** GET /api/admin/cartas/{id} */
    public function mostrar(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $carta = $this->exigirCarta($id);

        return $this->ok(VisaoCarta::completa(
            $carta,
            $this->dicas->daCarta($id),
            $this->alternativas->daCarta($id)
        ));
    }

    /** PUT /api/admin/cartas/{id} */
    public function atualizarCarta(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $this->exigirCarta($id);

        $dados = [];

        if (array_key_exists('resposta', $req->corpo())) {
            $resposta = $this->exigirTexto($req, 'resposta', 1, 180);
            $dados['resposta'] = $resposta;
            $dados['resposta_normalizada'] = Normalizador::normalizar($resposta);
        }

        if (array_key_exists('url_fonte', $req->corpo())) {
            $url = $req->campoTexto('url_fonte');
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw ExcecaoHttp::requisicaoInvalida('Link invalido.', ['campo' => 'url_fonte']);
            }
            $dados['url_fonte'] = $url === '' ? null : $url;
        }

        if (array_key_exists('categoria', $req->corpo())) {
            $chave = $this->exigirOpcao($req, 'categoria', ['pessoa', 'lugar', 'ano', 'coisa']);
            $categoriaId = $this->categorias->idPorChave($chave);

            if ($categoriaId === null) {
                throw ExcecaoHttp::requisicaoInvalida('Categoria desconhecida.');
            }

            $dados['categoria_id'] = $categoriaId;
        }

        if ($dados === []) {
            throw ExcecaoHttp::requisicaoInvalida('Nao veio nada para mudar.');
        }

        try {
            $this->cartas->atualizar($id, $dados);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ExcecaoHttp::conflito('Ja existe outra carta com essa mesma resposta.');
            }
            throw $e;
        }

        return $this->mostrar($req);
    }

    /** POST /api/admin/cartas/{id}/aprovar */
    public function aprovar(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $this->exigirCarta($id);

        $total = $this->dicas->contarDaCarta($id);
        $esperado = (int) Configuracao::obter('jogo.dicas_por_carta', 20);

        // Regra 59: so entra no jogo carta aprovada, e so aprova se estiver completa.
        if ($total < $esperado) {
            throw ExcecaoHttp::conflito(
                sprintf('Esta carta tem %d dicas e precisa de %d para ser aprovada.', $total, $esperado)
            );
        }

        $this->cartas->definirStatus($id, 'aprovada');

        return $this->ok(['id' => $id, 'status' => 'aprovada']);
    }

    /** POST /api/admin/cartas/{id}/rejeitar */
    public function rejeitar(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $this->exigirCarta($id);
        $this->cartas->definirStatus($id, 'rejeitada');

        return $this->ok(['id' => $id, 'status' => 'rejeitada']);
    }

    /** DELETE /api/admin/cartas/{id} */
    public function excluirCarta(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $this->exigirCarta($id);

        $this->cartas->excluir($id);

        return $this->ok(['id' => $id, 'excluida' => true]);
    }

    /** PUT /api/admin/dicas/{id} */
    public function atualizarDica(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $dica = $this->dicas->porId($id);

        if ($dica === null) {
            throw ExcecaoHttp::naoEncontrado('Dica nao encontrada.');
        }

        $dados = [];

        if (array_key_exists('texto', $req->corpo())) {
            $texto = $this->exigirTexto($req, 'texto', 5, 255);
            $dados['texto'] = $texto;
            $dados['texto_normalizado'] = Normalizador::normalizar($texto);
        }

        if (array_key_exists('dificuldade', $req->corpo())) {
            $dados['dificuldade'] = $this->exigirOpcao($req, 'dificuldade', ['dificil', 'media', 'facil']);
        }

        if ($dados === []) {
            throw ExcecaoHttp::requisicaoInvalida('Nao veio nada para mudar.');
        }

        try {
            $this->dicas->atualizar($id, $dados);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ExcecaoHttp::conflito('Esta carta ja tem uma dica com esse mesmo texto.');
            }
            throw $e;
        }

        return $this->ok(['dica' => $this->dicas->porId($id)]);
    }

    /** DELETE /api/admin/dicas/{id} */
    public function excluirDica(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);
        $dica = $this->dicas->porId($id);

        if ($dica === null) {
            throw ExcecaoHttp::naoEncontrado('Dica nao encontrada.');
        }

        $cartaId = (int) $dica['carta_id'];
        $this->dicas->excluir($id);

        // Carta incompleta nao pode continuar aprovada.
        $esperado = (int) Configuracao::obter('jogo.dicas_por_carta', 20);

        if ($this->dicas->contarDaCarta($cartaId) < $esperado) {
            $this->cartas->definirStatus($cartaId, 'pendente');
        }

        return $this->ok(['id' => $id, 'excluida' => true, 'carta_id' => $cartaId]);
    }

    /** POST /api/admin/alternativas */
    public function criarAlternativa(Request $req): Response
    {
        $cartaId = $this->exigirInteiro($req, 'carta_id', 1, PHP_INT_MAX);
        $this->exigirCarta($cartaId);

        $texto = $this->exigirTexto($req, 'texto', 1, 180);

        $this->alternativas->criarEmLote($cartaId, [[
            'texto'             => $texto,
            'texto_normalizado' => Normalizador::normalizar($texto),
        ]]);

        return $this->criado(['alternativas' => $this->alternativas->daCarta($cartaId)]);
    }

    /** DELETE /api/admin/alternativas/{id} */
    public function excluirAlternativa(Request $req): Response
    {
        $id = $this->exigirIdDeRota($req);

        if (!$this->alternativas->excluir($id)) {
            throw ExcecaoHttp::naoEncontrado('Resposta alternativa nao encontrada.');
        }

        return $this->ok(['id' => $id, 'excluida' => true]);
    }

    /** POST /api/admin/gerar  (regra 45: botao "gerar 10 cartas novas") */
    public function gerar(Request $req): Response
    {
        $quantidade = $this->exigirInteiro(
            $req,
            'quantidade',
            1,
            50,
            (int) Configuracao::obter('gerador.lote_padrao', 10)
        );

        $categoria = null;

        if (!empty($req->campo('categoria'))) {
            $categoria = $this->exigirOpcao($req, 'categoria', ['pessoa', 'lugar', 'ano', 'coisa']);
        }

        // A geracao conversa com a internet e pode demorar.
        set_time_limit(0);
        ignore_user_abort(true);

        $resultado = (new GeradorCartas())->gerar($quantidade, $categoria);

        return $this->ok($resultado);
    }

    private function exigirCarta(int $id): array
    {
        $carta = $this->cartas->porIdComCategoria($id);

        if ($carta === null) {
            throw ExcecaoHttp::naoEncontrado('Carta nao encontrada.');
        }

        return $carta;
    }
}
