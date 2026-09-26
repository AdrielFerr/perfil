<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador simples. As rotas sao declaradas em config/routes.php
 * no formato: [metodo, caminho, Controller::class, 'acao', [middlewares]].
 *
 * Caminhos aceitam parametros entre chaves: /partidas/{id}/dicas/{numero}
 */
final class Router
{
    /** @var array<int, array{metodo:string, padrao:string, controller:string, acao:string, middlewares:array}> */
    private array $rotas = [];

    public function adicionar(
        string $metodo,
        string $caminho,
        string $controller,
        string $acao,
        array $middlewares = []
    ): void {
        $this->rotas[] = [
            'metodo'      => strtoupper($metodo),
            'padrao'      => $this->compilar($caminho),
            'caminho'     => $caminho,
            'controller'  => $controller,
            'acao'        => $acao,
            'middlewares' => $middlewares,
        ];
    }

    public function carregar(array $definicoes): void
    {
        foreach ($definicoes as $rota) {
            $this->adicionar(
                $rota[0],
                $rota[1],
                $rota[2],
                $rota[3],
                $rota[4] ?? []
            );
        }
    }

    private function compilar(string $caminho): string
    {
        $padrao = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $caminho
        );

        return '#^' . rtrim((string) $padrao, '/') . '/?$#';
    }

    public function despachar(Request $requisicao): Response
    {
        $caminho = rtrim($requisicao->caminho(), '/');
        if ($caminho === '') {
            $caminho = '/';
        }

        $metodosDisponiveis = [];

        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['padrao'], $caminho, $encontrados)) {
                continue;
            }

            if ($rota['metodo'] !== $requisicao->metodo()) {
                $metodosDisponiveis[] = $rota['metodo'];
                continue;
            }

            $parametros = [];
            foreach ($encontrados as $chave => $valor) {
                if (is_string($chave)) {
                    $parametros[$chave] = $valor;
                }
            }
            $requisicao->definirParametros($parametros);

            foreach ($rota['middlewares'] as $middleware) {
                (new $middleware())->tratar($requisicao);
            }

            $controller = new $rota['controller']();
            $acao = $rota['acao'];

            if (!method_exists($controller, $acao)) {
                throw new \RuntimeException(
                    sprintf('Acao %s::%s nao existe.', $rota['controller'], $acao)
                );
            }

            return $controller->{$acao}($requisicao);
        }

        if ($metodosDisponiveis !== []) {
            throw new ExcecaoHttp(
                sprintf('O metodo %s nao e aceito neste endereco.', $requisicao->metodo()),
                405,
                ['metodos_aceitos' => array_values(array_unique($metodosDisponiveis))]
            );
        }

        throw ExcecaoHttp::naoEncontrado('Endereco nao existe nesta API: ' . $caminho);
    }
}
