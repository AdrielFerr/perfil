<?php

declare(strict_types=1);

namespace App\Core;

use App\Views\VisaoJson;

/**
 * Lugar unico onde erros, excecoes e erros fatais viram JSON.
 * Em producao nunca vaza detalhe interno: so a mensagem amigavel.
 */
final class TratadorErros
{
    public static function registrar(): void
    {
        set_error_handler(static function (int $severidade, string $mensagem, string $arquivo, int $linha): bool {
            if (!(error_reporting() & $severidade)) {
                return false;
            }
            throw new \ErrorException($mensagem, 0, $severidade, $arquivo, $linha);
        });

        set_exception_handler([self::class, 'tratar']);

        register_shutdown_function(static function (): void {
            $erro = error_get_last();
            if ($erro === null) {
                return;
            }
            $fatais = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
            if (($erro['type'] & $fatais) === 0) {
                return;
            }
            self::tratar(new \ErrorException(
                $erro['message'],
                0,
                $erro['type'],
                $erro['file'],
                $erro['line']
            ));
        });
    }

    public static function tratar(\Throwable $e): void
    {
        Database::desfazer();
        self::gravarLog($e);

        $status = 500;
        $mensagem = 'Algo deu errado no servidor. Tente de novo em instantes.';
        $detalhes = [];

        if ($e instanceof ExcecaoHttp) {
            $status = $e->status();
            $mensagem = $e->getMessage();
            $detalhes = $e->detalhes();
        } elseif (Configuracao::ehDesenvolvimento()) {
            $detalhes = [
                'excecao' => $e::class,
                'mensagem_tecnica' => $e->getMessage(),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        if (ob_get_level() > 0) {
            ob_clean();
        }

        VisaoJson::erro($mensagem, $status, $detalhes)->enviar();
        exit;
    }

    private static function gravarLog(\Throwable $e): void
    {
        try {
            $arquivo = (string) Configuracao::obter('log', '');
        } catch (\Throwable) {
            return;
        }

        if ($arquivo === '') {
            return;
        }

        $pasta = dirname($arquivo);
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0775, true);
        }

        $linha = sprintf(
            "[%s] %s: %s em %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        @file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
    }
}
