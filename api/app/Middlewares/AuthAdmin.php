<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Core\ExcecaoHttp;
use App\Core\Request;

/**
 * Regra 15: tudo que comeca com /admin (menos o login) passa por aqui.
 * Sem sessao valida, a requisicao morre com 401.
 */
final class AuthAdmin
{
    public static function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('PERFIL_ADMIN');
        session_start();
    }

    public static function estaLogado(): bool
    {
        self::iniciarSessao();

        return ($_SESSION['admin_logado'] ?? false) === true;
    }

    public static function entrar(string $usuario): void
    {
        self::iniciarSessao();
        session_regenerate_id(true);

        $_SESSION['admin_logado'] = true;
        $_SESSION['admin_usuario'] = $usuario;
        $_SESSION['admin_entrou_em'] = time();
    }

    public static function sair(): void
    {
        self::iniciarSessao();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $parametros['path'],
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        session_destroy();
    }

    public static function usuario(): ?string
    {
        self::iniciarSessao();

        return $_SESSION['admin_usuario'] ?? null;
    }

    public function tratar(Request $requisicao): void
    {
        if (!self::estaLogado()) {
            throw ExcecaoHttp::naoAutorizado('Entre no painel para continuar.');
        }
    }
}
