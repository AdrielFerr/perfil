<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ExcecaoHttp;
use App\Core\Request;
use App\Core\Response;
use App\Models\Jogador;
use App\Models\Partida;
use App\Views\VisaoPartida;

/**
 * Placar e ajustes de identidade dos jogadores (nome, cor, avatar).
 */
final class JogadorController extends Controller
{
    private Jogador $jogadores;
    private Partida $partidas;

    public function __construct()
    {
        $this->jogadores = new Jogador();
        $this->partidas = new Partida();
    }

    /** GET /api/partidas/{id}/jogadores */
    public function listar(Request $req): Response
    {
        $partidaId = $this->exigirIdDeRota($req);
        $this->exigirPartida($partidaId);

        $lista = array_map(
            [VisaoPartida::class, 'jogador'],
            $this->jogadores->daPartida($partidaId)
        );

        // Placar ordenado do maior para o menor.
        usort($lista, static fn (array $a, array $b): int => $b['pontos'] <=> $a['pontos']);

        return $this->ok(['jogadores' => $lista]);
    }

    /** PUT /api/partidas/{id}/jogadores/{jogadorId} */
    public function atualizar(Request $req): Response
    {
        $partidaId = $this->exigirIdDeRota($req);
        $jogadorId = (int) $req->parametro('jogadorId', 0);

        $this->exigirPartida($partidaId);

        if (!$this->jogadores->pertenceAPartida($jogadorId, $partidaId)) {
            throw ExcecaoHttp::naoEncontrado('Esse jogador nao esta nesta partida.');
        }

        $dados = [];

        if (array_key_exists('nome', $req->corpo())) {
            $dados['nome'] = $this->exigirTexto($req, 'nome', 1, 40);
        }

        if (array_key_exists('cor', $req->corpo())) {
            $cor = $req->campoTexto('cor');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) !== 1) {
                throw ExcecaoHttp::requisicaoInvalida('Cor invalida. Use o formato #RRGGBB.', ['campo' => 'cor']);
            }
            $dados['cor'] = $cor;
        }

        if (array_key_exists('avatar', $req->corpo())) {
            $avatar = $req->campoTexto('avatar');
            if ($avatar === '' || mb_strlen($avatar) > 4) {
                throw ExcecaoHttp::requisicaoInvalida('Escolha um emoji para o avatar.', ['campo' => 'avatar']);
            }
            $dados['avatar'] = $avatar;
        }

        if ($dados === []) {
            throw ExcecaoHttp::requisicaoInvalida('Nao veio nada para mudar.');
        }

        $this->jogadores->atualizar($jogadorId, $dados);

        $jogador = $this->jogadores->porId($jogadorId);

        return $this->ok(['jogador' => VisaoPartida::jogador((array) $jogador)]);
    }

    private function exigirPartida(int $partidaId): array
    {
        $partida = $this->partidas->porId($partidaId);

        if ($partida === null) {
            throw ExcecaoHttp::naoEncontrado('Partida nao encontrada.');
        }

        return $partida;
    }
}
