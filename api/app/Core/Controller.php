<?php

declare(strict_types=1);

namespace App\Core;

use App\Views\VisaoJson;

/**
 * Base dos controllers: so atalhos de validacao e de resposta.
 * Nenhuma regra de jogo e nenhum SQL mora aqui.
 */
abstract class Controller
{
    protected function ok(mixed $dados = null, int $status = 200): Response
    {
        return VisaoJson::sucesso($dados, $status);
    }

    protected function criado(mixed $dados = null): Response
    {
        return VisaoJson::sucesso($dados, 201);
    }

    /** Garante que o campo veio preenchido. */
    protected function exigirTexto(Request $req, string $campo, int $minimo = 1, int $maximo = 255): string
    {
        $valor = $req->campoTexto($campo);
        $tamanho = mb_strlen($valor);

        if ($tamanho < $minimo) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('O campo "%s" e obrigatorio.', $campo),
                ['campo' => $campo]
            );
        }

        if ($tamanho > $maximo) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('O campo "%s" pode ter no maximo %d caracteres.', $campo, $maximo),
                ['campo' => $campo]
            );
        }

        return $valor;
    }

    /** Garante que o valor esta na lista permitida. */
    protected function exigirOpcao(Request $req, string $campo, array $opcoes, ?string $padrao = null): string
    {
        $valor = $req->campoTexto($campo, $padrao ?? '');

        if ($valor === '' && $padrao !== null) {
            return $padrao;
        }

        if (!in_array($valor, $opcoes, true)) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('O campo "%s" precisa ser um destes: %s.', $campo, implode(', ', $opcoes)),
                ['campo' => $campo, 'opcoes' => $opcoes]
            );
        }

        return $valor;
    }

    protected function exigirInteiro(Request $req, string $campo, int $minimo, int $maximo, ?int $padrao = null): int
    {
        if (!array_key_exists($campo, $req->corpo()) && $padrao !== null) {
            return $padrao;
        }

        $valor = $req->campoInteiro($campo, $padrao ?? 0);

        if ($valor < $minimo || $valor > $maximo) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('O campo "%s" precisa ficar entre %d e %d.', $campo, $minimo, $maximo),
                ['campo' => $campo]
            );
        }

        return $valor;
    }

    protected function exigirIdDeRota(Request $req, string $nome = 'id'): int
    {
        $valor = $req->parametro($nome);

        if (!is_numeric($valor) || (int) $valor <= 0) {
            throw ExcecaoHttp::requisicaoInvalida('Identificador invalido na URL.');
        }

        return (int) $valor;
    }
}
