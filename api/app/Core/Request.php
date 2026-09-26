<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Leitura de tudo que chega na requisicao: metodo, caminho,
 * query string, corpo JSON e parametros de rota.
 */
final class Request
{
    private string $metodo;
    private string $caminho;
    private array $query;
    private array $corpo;
    private array $parametros = [];

    public function __construct()
    {
        $this->metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->caminho = $this->descobrirCaminho();
        $this->query = $_GET ?? [];
        $this->corpo = $this->lerCorpo();
    }

    private function descobrirCaminho(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $caminho = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Quando o Apache serve a API dentro de um subdiretorio
        // (ex.: http://localhost/perfil-api/public/), removemos o prefixo.
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($caminho, $base)) {
            $caminho = substr($caminho, strlen($base));
        }

        $caminho = '/' . trim(rawurldecode($caminho), '/');

        // Aceita tanto /api/partidas quanto /partidas.
        if ($caminho === '/api') {
            return '/';
        }
        if (str_starts_with($caminho, '/api/')) {
            $caminho = substr($caminho, 4);
        }

        return $caminho === '' ? '/' : $caminho;
    }

    private function lerCorpo(): array
    {
        $bruto = file_get_contents('php://input');

        if ($bruto === false || $bruto === '') {
            return $_POST ?? [];
        }

        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($tipo, 'application/json')) {
            $dados = json_decode($bruto, true);
            return is_array($dados) ? $dados : [];
        }

        // Tenta JSON mesmo sem o cabecalho correto.
        $dados = json_decode($bruto, true);
        if (is_array($dados)) {
            return $dados;
        }

        parse_str($bruto, $formulario);
        return is_array($formulario) ? $formulario : [];
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function caminho(): string
    {
        return $this->caminho;
    }

    public function definirParametros(array $parametros): void
    {
        $this->parametros = $parametros;
    }

    public function parametro(string $nome, mixed $padrao = null): mixed
    {
        return $this->parametros[$nome] ?? $padrao;
    }

    public function parametroInteiro(string $nome): int
    {
        return (int) ($this->parametros[$nome] ?? 0);
    }

    public function consulta(string $nome, mixed $padrao = null): mixed
    {
        return $this->query[$nome] ?? $padrao;
    }

    public function corpo(): array
    {
        return $this->corpo;
    }

    public function campo(string $nome, mixed $padrao = null): mixed
    {
        return $this->corpo[$nome] ?? $padrao;
    }

    public function campoTexto(string $nome, string $padrao = ''): string
    {
        $valor = $this->corpo[$nome] ?? $padrao;
        return is_scalar($valor) ? trim((string) $valor) : $padrao;
    }

    public function campoInteiro(string $nome, int $padrao = 0): int
    {
        $valor = $this->corpo[$nome] ?? $padrao;
        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    public function campoBooleano(string $nome, bool $padrao = false): bool
    {
        if (!array_key_exists($nome, $this->corpo)) {
            return $padrao;
        }
        return filter_var($this->corpo[$nome], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $padrao;
    }

    public function campoLista(string $nome): array
    {
        $valor = $this->corpo[$nome] ?? [];
        return is_array($valor) ? $valor : [];
    }

    public function origem(): string
    {
        return $_SERVER['HTTP_ORIGIN'] ?? '';
    }
}
