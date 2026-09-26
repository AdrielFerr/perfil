<?php
/**
 * Copie este arquivo para config.php e ajuste os valores.
 * Este arquivo fica FORA da pasta publica, nunca e servido pelo Apache.
 */

return [

    // -----------------------------------------------------------------
    // Banco de dados
    // -----------------------------------------------------------------
    'banco' => [
        'host'   => '127.0.0.1',
        'porta'  => 3306,
        'nome'   => 'perfil',
        'usuario' => 'root',
        'senha'  => '',
        'charset' => 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // Ambiente
    // 'desenvolvimento' mostra a mensagem tecnica do erro no JSON.
    // 'producao' esconde tudo e grava apenas no log.
    // -----------------------------------------------------------------
    'ambiente' => 'desenvolvimento',

    'log' => __DIR__ . '/../cache/erros.log',

    // -----------------------------------------------------------------
    // Painel administrativo
    // Gere a senha com:
    //   php -r "echo password_hash('minhasenha', PASSWORD_DEFAULT);"
    // -----------------------------------------------------------------
    'admin' => [
        'usuario'    => 'admin',
        // senha padrao: perfil123  (TROQUE ANTES DE USAR)
        'senha_hash' => '$2y$10$lxbM6MbZ7JjwvQVSxRgTqOp2ufnA.18FmtGjucktJ6txhZAgA4ZY.',
    ],

    // -----------------------------------------------------------------
    // CORS: origens liberadas no modo desenvolvimento.
    // Com o proxy do Vite ligado voce nem precisa disso, mas fica aqui
    // caso queira abrir o Vite direto contra o Apache.
    // -----------------------------------------------------------------
    'origens_permitidas' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    // -----------------------------------------------------------------
    // Regras padrao do jogo
    // -----------------------------------------------------------------
    'jogo' => [
        'dicas_por_carta'          => 20,
        'pontuacao_vitoria_padrao' => 50,
        'minimo_jogadores'         => 2,
        'maximo_jogadores'         => 6,
    ],

    // -----------------------------------------------------------------
    // Gerador de cartas (Wikidata + Wikipedia)
    // -----------------------------------------------------------------
    'gerador' => [
        // Obrigatorio pelas regras de uso do Wikimedia. Coloque um
        // contato real seu.
        'user_agent' => 'JogoPerfil/1.0 (https://localhost; contato@exemplo.com.br) PHP/curl',

        'endpoint_sparql'    => 'https://query.wikidata.org/sparql',
        'endpoint_wikipedia' => 'https://pt.wikipedia.org/api/rest_v1/page/summary/',

        // Minimo de sitelinks (quantas Wikipedias tem artigo sobre o tema).
        // Quanto maior, mais famoso o tema.
        'minimo_sitelinks' => 120,

        // Quantas linhas pular na lista ordenada por fama. Zero = so os topos.
        'deslocamento_maximo' => 0,

        // Pausa entre consultas, em milissegundos (respeito ao limite de uso).
        'pausa_ms' => 1200,

        'timeout_segundos' => 60,

        // Cache em disco das respostas HTTP, em segundos (7 dias).
        'cache_segundos' => 604800,
        'cache_pasta'    => __DIR__ . '/../cache/http',

        // Quantas cartas o botao do painel gera por vez (valor padrao).
        'lote_padrao' => 10,

        // Tamanho do sorteio no Wikidata por categoria.
        'candidatos_por_consulta' => 120,

        // Mistura de dificuldade desejada em cada carta de 20 dicas.
        'mistura_dificuldade' => [
            'dificil' => 7,
            'media'   => 7,
            'facil'   => 6,
        ],

        // Cartas geradas ja entram aprovadas? Se false, ficam 'pendente'
        // e precisam ser aprovadas no painel.
        'aprovar_automaticamente' => false,
    ],
];
