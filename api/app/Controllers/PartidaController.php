<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Configuracao;
use App\Core\Controller;
use App\Core\ExcecaoHttp;
use App\Core\Request;
use App\Core\Response;
use App\Models\Partida;
use App\Services\RegrasPartida;

/**
 * Controller fino: valida a entrada, chama o Service e devolve a View.
 * Nenhum SQL e nenhuma regra de jogo aqui dentro.
 */
final class PartidaController extends Controller
{
    private RegrasPartida $regras;

    public function __construct()
    {
        $this->regras = new RegrasPartida();
    }

    /** POST /api/partidas */
    public function criar(Request $req): Response
    {
        $pontuacao = $this->exigirInteiro(
            $req,
            'pontuacao_vitoria',
            10,
            200,
            (int) Configuracao::obter('jogo.pontuacao_vitoria_padrao', 50)
        );

        $jogadores = $this->validarJogadores($req->campoLista('jogadores'));

        return $this->criado($this->regras->criar($jogadores, $pontuacao));
    }

    /** @return array<int,array{nome:string,cor:string,avatar:string}> */
    private function validarJogadores(array $brutos): array
    {
        $minimo = (int) Configuracao::obter('jogo.minimo_jogadores', 2);
        $maximo = (int) Configuracao::obter('jogo.maximo_jogadores', 6);

        if (count($brutos) < $minimo || count($brutos) > $maximo) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('Cadastre de %d a %d jogadores.', $minimo, $maximo),
                ['campo' => 'jogadores']
            );
        }

        $jogadores = [];
        $nomesUsados = [];

        foreach (array_values($brutos) as $indice => $bruto) {
            if (!is_array($bruto)) {
                throw ExcecaoHttp::requisicaoInvalida('Lista de jogadores em formato invalido.');
            }

            $nome = trim((string) ($bruto['nome'] ?? ''));

            if ($nome === '' || mb_strlen($nome) > 40) {
                throw ExcecaoHttp::requisicaoInvalida(
                    sprintf('O jogador %d precisa de um nome de 1 a 40 caracteres.', $indice + 1),
                    ['campo' => 'jogadores', 'posicao' => $indice]
                );
            }

            $chave = mb_strtolower($nome);

            if (isset($nomesUsados[$chave])) {
                throw ExcecaoHttp::requisicaoInvalida(
                    sprintf('O nome "%s" esta repetido. Cada jogador precisa de um nome diferente.', $nome),
                    ['campo' => 'jogadores', 'posicao' => $indice]
                );
            }
            $nomesUsados[$chave] = true;

            $cor = trim((string) ($bruto['cor'] ?? ''));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) !== 1) {
                $cor = '#B3202E';
            }

            $avatar = trim((string) ($bruto['avatar'] ?? ''));
            if ($avatar === '' || mb_strlen($avatar) > 4) {
                $avatar = '🙂';
            }

            $jogadores[] = ['nome' => $nome, 'cor' => $cor, 'avatar' => $avatar];
        }

        return $jogadores;
    }

    /** GET /api/partidas/{id} */
    public function mostrar(Request $req): Response
    {
        return $this->ok($this->regras->estado($this->exigirIdDeRota($req)));
    }

    /** GET /api/partidas/codigo/{codigo} */
    public function porCodigo(Request $req): Response
    {
        $codigo = strtoupper((string) $req->parametro('codigo', ''));

        if (preg_match('/^[A-Z0-9]{8}$/', $codigo) !== 1) {
            throw ExcecaoHttp::requisicaoInvalida('Codigo de partida invalido.');
        }

        $partida = (new Partida())->porCodigo($codigo);

        if ($partida === null) {
            throw ExcecaoHttp::naoEncontrado('Nao achei nenhuma partida com esse codigo.');
        }

        return $this->ok($this->regras->estado((int) $partida['id']));
    }

    /** POST /api/partidas/{id}/dicas/{numero} */
    public function revelarDica(Request $req): Response
    {
        $numero = (int) $req->parametro('numero', 0);

        return $this->ok($this->regras->revelarDica($this->exigirIdDeRota($req), $numero));
    }

    /** POST /api/partidas/{id}/palpite */
    public function palpitar(Request $req): Response
    {
        $partidaId = $this->exigirIdDeRota($req);
        $palpite = $this->exigirTexto($req, 'palpite', 1, 120);

        return $this->ok($this->regras->palpitarDigitado($partidaId, $palpite));
    }

    /** POST /api/partidas/{id}/passar */
    public function passar(Request $req): Response
    {
        return $this->ok($this->regras->passarVez($this->exigirIdDeRota($req)));
    }

    /** POST /api/partidas/{id}/revelar */
    public function revelarResposta(Request $req): Response
    {
        return $this->ok($this->regras->revelarResposta($this->exigirIdDeRota($req)));
    }

    /** POST /api/partidas/{id}/proxima-carta */
    public function proximaCarta(Request $req): Response
    {
        return $this->ok($this->regras->proximaCarta($this->exigirIdDeRota($req)));
    }

    /** POST /api/partidas/{id}/desfazer */
    public function desfazer(Request $req): Response
    {
        return $this->ok($this->regras->desfazer($this->exigirIdDeRota($req)));
    }

    /** POST /api/partidas/{id}/encerrar */
    public function encerrar(Request $req): Response
    {
        return $this->ok($this->regras->encerrar($this->exigirIdDeRota($req)));
    }
}
