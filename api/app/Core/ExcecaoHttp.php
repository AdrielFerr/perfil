<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Excecao que vira resposta de erro amigavel.
 * Qualquer camada pode lancar; o TratadorErros converte em JSON.
 */
class ExcecaoHttp extends \RuntimeException
{
    private int $status;
    private array $detalhes;

    public function __construct(string $mensagem, int $status = 400, array $detalhes = [])
    {
        parent::__construct($mensagem, $status);
        $this->status = $status;
        $this->detalhes = $detalhes;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function detalhes(): array
    {
        return $this->detalhes;
    }

    public static function requisicaoInvalida(string $mensagem, array $detalhes = []): self
    {
        return new self($mensagem, 400, $detalhes);
    }

    public static function naoAutorizado(string $mensagem = 'Voce precisa entrar no painel para fazer isso.'): self
    {
        return new self($mensagem, 401);
    }

    public static function naoEncontrado(string $mensagem = 'Nao encontrado.'): self
    {
        return new self($mensagem, 404);
    }

    public static function conflito(string $mensagem): self
    {
        return new self($mensagem, 409);
    }

    public static function indisponivel(string $mensagem): self
    {
        return new self($mensagem, 503);
    }
}
