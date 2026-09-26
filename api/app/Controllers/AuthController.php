<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Configuracao;
use App\Core\Controller;
use App\Core\ExcecaoHttp;
use App\Core\Request;
use App\Core\Response;
use App\Middlewares\AuthAdmin;

/**
 * Login do painel administrativo (regra 58): sessao PHP, sem token.
 */
final class AuthController extends Controller
{
    /** POST /api/admin/login */
    public function entrar(Request $req): Response
    {
        $usuario = $this->exigirTexto($req, 'usuario', 1, 60);
        $senha = (string) $req->campo('senha', '');

        $usuarioEsperado = (string) Configuracao::obter('admin.usuario', 'admin');
        $hashEsperado = (string) Configuracao::obter('admin.senha_hash', '');

        $usuarioConfere = hash_equals($usuarioEsperado, $usuario);
        $senhaConfere = $hashEsperado !== '' && password_verify($senha, $hashEsperado);

        // Compara os dois antes de responder, para nao dar pista de qual errou.
        if (!$usuarioConfere || !$senhaConfere) {
            // Pequena espera para atrapalhar tentativa em massa.
            usleep(400_000);
            throw ExcecaoHttp::naoAutorizado('Usuario ou senha incorretos.');
        }

        AuthAdmin::entrar($usuario);

        return $this->ok(['usuario' => $usuario, 'logado' => true]);
    }

    /** POST /api/admin/logout */
    public function sair(Request $req): Response
    {
        AuthAdmin::sair();

        return $this->ok(['logado' => false]);
    }

    /** GET /api/admin/sessao */
    public function sessao(Request $req): Response
    {
        return $this->ok([
            'logado'  => AuthAdmin::estaLogado(),
            'usuario' => AuthAdmin::usuario(),
        ]);
    }
}
