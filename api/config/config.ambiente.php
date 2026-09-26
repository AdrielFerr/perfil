<?php

/**
 * Configuracao por variaveis de ambiente.
 *
 * Usado quando o jogo roda em container (Docker / Swarm). O Dockerfile
 * copia este arquivo para config/config.php, entao nenhuma senha fica
 * gravada na imagem: tudo vem do stack.
 *
 * No XAMPP local continua valendo o config/config.php feito na mao.
 */

/** Le uma variavel de ambiente com valor padrao. */
$env = static function (string $nome, string $padrao = ''): string {
    $valor = getenv($nome);

    return ($valor === false || $valor === '') ? $padrao : $valor;
};

return [

    'banco' => [
        'host'    => $env('PERFIL_DB_HOST', 'perfilmysql'),
        'porta'   => (int) $env('PERFIL_DB_PORTA', '3306'),
        'nome'    => $env('PERFIL_DB_NOME', 'perfil'),
        'usuario' => $env('PERFIL_DB_USUARIO', 'perfil'),
        'senha'   => $env('PERFIL_DB_SENHA', ''),
        'charset' => 'utf8mb4',
    ],

    // 'producao' esconde a mensagem tecnica do erro e so grava no log.
    'ambiente' => $env('PERFIL_AMBIENTE', 'producao'),

    'log' => __DIR__ . '/../cache/erros.log',

    // -----------------------------------------------------------------
    // Painel administrativo
    // Gere o hash com:
    //   php -r "echo password_hash('suasenha', PASSWORD_DEFAULT);"
    // Sem PERFIL_ADMIN_SENHA_HASH o login e recusado sempre, de proposito.
    // -----------------------------------------------------------------
    'admin' => [
        'usuario'    => $env('PERFIL_ADMIN_USUARIO', 'admin'),
        'senha_hash' => $env('PERFIL_ADMIN_SENHA_HASH', ''),
    ],

    // Em producao o front e a API saem do mesmo dominio, entao CORS nem
    // entra em cena. A lista existe para quando o Vite aponta para ca.
    'origens_permitidas' => array_values(array_filter(
        array_map('trim', explode(',', $env('PERFIL_ORIGENS', '')))
    )),

    'jogo' => [
        'dicas_por_carta'          => (int) $env('PERFIL_DICAS_POR_CARTA', '20'),
        'pontuacao_vitoria_padrao' => (int) $env('PERFIL_PONTUACAO_PADRAO', '50'),
        'minimo_jogadores'         => 2,
        'maximo_jogadores'         => 6,
    ],

    'gerador' => [
        'user_agent' => $env(
            'PERFIL_USER_AGENT',
            'JogoPerfil/1.0 (https://github.com/adriel/perfil; contato@exemplo.com.br) PHP/curl'
        ),

        'endpoint_sparql'    => 'https://query.wikidata.org/sparql',
        'endpoint_wikipedia' => 'https://pt.wikipedia.org/api/rest_v1/page/summary/',

        // Quanto maior, mais famoso o tema sorteado.
        'minimo_sitelinks'        => (int) $env('PERFIL_MINIMO_SITELINKS', '180'),
        'minimo_sitelinks_brasil' => (int) $env('PERFIL_MINIMO_SITELINKS_BR', '25'),

        // Quantas linhas pular na lista ordenada por fama. Zero = so os topos.
        'deslocamento_maximo' => (int) $env('PERFIL_DESLOCAMENTO', '0'),

        'pausa_ms'         => (int) $env('PERFIL_PAUSA_MS', '1200'),
        'timeout_segundos' => 60,

        'cache_segundos' => 604800,
        'cache_pasta'    => __DIR__ . '/../cache/http',

        'lote_padrao'             => (int) $env('PERFIL_LOTE_PADRAO', '10'),
        'candidatos_por_consulta' => (int) $env('PERFIL_CANDIDATOS', '120'),

        'mistura_dificuldade' => [
            'dificil' => (int) $env('PERFIL_MIX_DIFICIL', '5'),
            'media'   => (int) $env('PERFIL_MIX_MEDIA', '7'),
            'facil'   => (int) $env('PERFIL_MIX_FACIL', '8'),
        ],

        'aprovar_automaticamente' => $env('PERFIL_APROVAR_AUTO', 'false') === 'true',
    ],
];
