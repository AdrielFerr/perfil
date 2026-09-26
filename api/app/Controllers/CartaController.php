<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Carta;
use App\Models\Categoria;

/**
 * Informacoes publicas sobre o acervo de cartas.
 * Nunca devolve resposta nem dica: isso so sai pela partida ou pelo painel.
 */
final class CartaController extends Controller
{
    /** GET /api/categorias */
    public function categorias(Request $req): Response
    {
        $categorias = (new Categoria())->contagemDeCartas();

        return $this->ok([
            'categorias' => array_map(static fn (array $c): array => [
                'chave'     => $c['chave'],
                'nome'      => $c['nome'],
                'cor'       => $c['cor'],
                'icone'     => $c['icone'],
                'aprovadas' => (int) $c['aprovadas'],
            ], $categorias),
        ]);
    }

    /** GET /api/acervo - o front usa para avisar "sem cartas aprovadas". */
    public function acervo(Request $req): Response
    {
        $cartas = new Carta();

        return $this->ok([
            'total_aprovadas' => $cartas->totalAprovadas(),
            'pronto_para_jogar' => $cartas->totalAprovadas() > 0,
        ]);
    }

    /** GET /api/saude - checagem rapida de banco e extensoes. */
    public function saude(Request $req): Response
    {
        $extensoes = [];

        foreach (['pdo_mysql', 'curl', 'mbstring', 'intl', 'json'] as $extensao) {
            $extensoes[$extensao] = extension_loaded($extensao);
        }

        return $this->ok([
            'api'        => 'ok',
            'php'        => PHP_VERSION,
            'banco'      => (new Carta())->totalAprovadas() >= 0 ? 'ok' : 'erro',
            'extensoes'  => $extensoes,
        ]);
    }
}
