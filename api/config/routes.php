<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CartaController;
use App\Controllers\JogadorController;
use App\Controllers\PartidaController;
use App\Middlewares\AuthAdmin;

/**
 * Mapa de rotas da API.
 * Formato: [metodo, caminho, Controller::class, 'acao', [middlewares]]
 *
 * O prefixo /api e opcional: o Request aceita /api/partidas e /partidas.
 */
return [

    // -----------------------------------------------------------------
    // Publico
    // -----------------------------------------------------------------
    ['GET',  '/saude',      CartaController::class, 'saude'],
    ['GET',  '/categorias', CartaController::class, 'categorias'],
    ['GET',  '/acervo',     CartaController::class, 'acervo'],

    // -----------------------------------------------------------------
    // Partida
    // -----------------------------------------------------------------
    ['POST', '/partidas',                 PartidaController::class, 'criar'],
    ['GET',  '/partidas/{id}',            PartidaController::class, 'mostrar'],
    ['GET',  '/partidas/codigo/{codigo}', PartidaController::class, 'porCodigo'],

    ['POST', '/partidas/{id}/dicas/{numero}', PartidaController::class, 'revelarDica'],
    ['POST', '/partidas/{id}/palpite',        PartidaController::class, 'palpitar'],
    ['POST', '/partidas/{id}/passar',         PartidaController::class, 'passar'],
    ['POST', '/partidas/{id}/revelar',        PartidaController::class, 'revelarResposta'],
    ['POST', '/partidas/{id}/proxima-carta',  PartidaController::class, 'proximaCarta'],
    ['POST', '/partidas/{id}/desfazer',       PartidaController::class, 'desfazer'],
    ['POST', '/partidas/{id}/encerrar',       PartidaController::class, 'encerrar'],

    // -----------------------------------------------------------------
    // Jogadores
    // -----------------------------------------------------------------
    ['GET', '/partidas/{id}/jogadores',             JogadorController::class, 'listar'],
    ['PUT', '/partidas/{id}/jogadores/{jogadorId}', JogadorController::class, 'atualizar'],

    // -----------------------------------------------------------------
    // Login do painel (sem middleware, senao ninguem consegue entrar)
    // -----------------------------------------------------------------
    ['POST', '/admin/login',  AuthController::class, 'entrar'],
    ['POST', '/admin/logout', AuthController::class, 'sair'],
    ['GET',  '/admin/sessao', AuthController::class, 'sessao'],

    // -----------------------------------------------------------------
    // Painel administrativo (regra 15: protegido por AuthAdmin)
    // -----------------------------------------------------------------
    ['GET',    '/admin/resumo',             AdminController::class, 'resumo',             [AuthAdmin::class]],
    ['GET',    '/admin/cartas',             AdminController::class, 'listar',             [AuthAdmin::class]],
    ['GET',    '/admin/cartas/{id}',        AdminController::class, 'mostrar',            [AuthAdmin::class]],
    ['PUT',    '/admin/cartas/{id}',        AdminController::class, 'atualizarCarta',     [AuthAdmin::class]],
    ['DELETE', '/admin/cartas/{id}',        AdminController::class, 'excluirCarta',       [AuthAdmin::class]],
    ['POST',   '/admin/cartas/{id}/aprovar',  AdminController::class, 'aprovar',          [AuthAdmin::class]],
    ['POST',   '/admin/cartas/{id}/rejeitar', AdminController::class, 'rejeitar',         [AuthAdmin::class]],

    ['PUT',    '/admin/dicas/{id}',    AdminController::class, 'atualizarDica', [AuthAdmin::class]],
    ['DELETE', '/admin/dicas/{id}',    AdminController::class, 'excluirDica',   [AuthAdmin::class]],

    ['POST',   '/admin/alternativas',      AdminController::class, 'criarAlternativa',   [AuthAdmin::class]],
    ['DELETE', '/admin/alternativas/{id}', AdminController::class, 'excluirAlternativa', [AuthAdmin::class]],

    ['POST',   '/admin/gerar', AdminController::class, 'gerar', [AuthAdmin::class]],
];
