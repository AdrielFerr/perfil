<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Objeto simples de resposta. Quem monta o conteudo sao as Views;
 * esta classe so cuida de status, cabecalhos e envio.
 */
final class Response
{
    private int $status = 200;
    private array $cabecalhos = [];
    private string $conteudo = '';

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function cabecalho(string $nome, string $valor): self
    {
        $this->cabecalhos[$nome] = $valor;
        return $this;
    }

    public function conteudo(string $conteudo): self
    {
        $this->conteudo = $conteudo;
        return $this;
    }

    public function obterStatus(): int
    {
        return $this->status;
    }

    public function obterConteudo(): string
    {
        return $this->conteudo;
    }

    public function enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->cabecalhos as $nome => $valor) {
                header($nome . ': ' . $valor, true);
            }
        }

        echo $this->conteudo;
    }

    /** Aplica os cabecalhos de CORS conforme config/config.php. */
    public static function aplicarCors(Request $requisicao): void
    {
        $origem = $requisicao->origem();
        $permitidas = (array) Configuracao::obter('origens_permitidas', []);

        if ($origem !== '' && in_array($origem, $permitidas, true)) {
            header('Access-Control-Allow-Origin: ' . $origem);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }
}
