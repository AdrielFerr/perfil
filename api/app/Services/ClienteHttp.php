<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;

/**
 * Base dos clientes Wikidata e Wikipedia.
 *
 * Regra 46: manda o User-Agent exigido pela Wikimedia, respeita uma pausa
 * entre consultas, guarda cache em disco e trata erro de rede sem quebrar.
 */
abstract class ClienteHttp
{
    private static float $ultimaChamada = 0.0;

    protected function userAgent(): string
    {
        return (string) Configuracao::obter(
            'gerador.user_agent',
            'JogoPerfil/1.0 (contato@exemplo.com.br) PHP/curl'
        );
    }

    protected function timeout(): int
    {
        return (int) Configuracao::obter('gerador.timeout_segundos', 60);
    }

    /**
     * Faz um GET com cache em disco.
     *
     * @param array<string,string> $cabecalhosExtras
     * @return array{ok:bool, corpo:string, status:int, erro:?string, cache:bool}
     */
    protected function buscar(string $url, array $cabecalhosExtras = [], bool $usarCache = true): array
    {
        $chave = $this->chaveCache($url);

        if ($usarCache) {
            $doCache = $this->lerCache($chave);
            if ($doCache !== null) {
                return ['ok' => true, 'corpo' => $doCache, 'status' => 200, 'erro' => null, 'cache' => true];
            }
        }

        $this->respeitarPausa();

        $cabecalhos = array_merge(
            [
                'User-Agent' => $this->userAgent(),
                'Accept-Encoding' => 'gzip',
            ],
            $cabecalhosExtras
        );

        $resultado = $this->requisitar($url, $cabecalhos);

        if ($resultado['ok'] && $usarCache) {
            $this->gravarCache($chave, $resultado['corpo']);
        }

        return $resultado;
    }

    /** @param array<string,string> $cabecalhos */
    private function requisitar(string $url, array $cabecalhos): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'corpo' => '',
                'status' => 0,
                'erro' => 'A extensao curl do PHP nao esta ativa. Ative extension=curl no php.ini.',
                'cache' => false,
            ];
        }

        $lista = [];
        foreach ($cabecalhos as $nome => $valor) {
            $lista[] = $nome . ': ' . $valor;
        }

        $tentativas = 0;
        $ultimoErro = null;
        $ultimoStatus = 0;

        while ($tentativas < 3) {
            $tentativas++;

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_TIMEOUT        => $this->timeout(),
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => $lista,
                CURLOPT_ENCODING       => 'gzip',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $corpo = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $erroCurl = curl_error($curl);
            curl_close($curl);

            if ($corpo === false) {
                $ultimoErro = 'Falha de rede: ' . ($erroCurl !== '' ? $erroCurl : 'sem resposta');
                $this->esperarAntesDeRepetir($tentativas);
                continue;
            }

            $ultimoStatus = $status;

            if ($status >= 200 && $status < 300) {
                return ['ok' => true, 'corpo' => (string) $corpo, 'status' => $status, 'erro' => null, 'cache' => false];
            }

            // 429 e 5xx merecem nova tentativa; 4xx nao.
            if ($status !== 429 && $status < 500) {
                return [
                    'ok' => false,
                    'corpo' => (string) $corpo,
                    'status' => $status,
                    'erro' => 'O servidor respondeu ' . $status . '.',
                    'cache' => false,
                ];
            }

            $ultimoErro = 'O servidor respondeu ' . $status . '.';
            $this->esperarAntesDeRepetir($tentativas);
        }

        return [
            'ok' => false,
            'corpo' => '',
            'status' => $ultimoStatus,
            'erro' => $ultimoErro ?? 'Nao foi possivel completar a consulta.',
            'cache' => false,
        ];
    }

    private function esperarAntesDeRepetir(int $tentativa): void
    {
        usleep(min(8, 2 ** $tentativa) * 1_000_000);
    }

    /** Regra 46: nunca dispara duas consultas coladas. */
    private function respeitarPausa(): void
    {
        $pausaMs = (int) Configuracao::obter('gerador.pausa_ms', 1200);

        if ($pausaMs <= 0) {
            return;
        }

        $agora = microtime(true);
        $desdeUltima = ($agora - self::$ultimaChamada) * 1000;

        if (self::$ultimaChamada > 0.0 && $desdeUltima < $pausaMs) {
            usleep((int) (($pausaMs - $desdeUltima) * 1000));
        }

        self::$ultimaChamada = microtime(true);
    }

    // -----------------------------------------------------------------
    // Cache em disco
    // -----------------------------------------------------------------

    private function pastaCache(): string
    {
        $pasta = (string) Configuracao::obter('gerador.cache_pasta', sys_get_temp_dir() . '/perfil-cache');

        if (!is_dir($pasta)) {
            @mkdir($pasta, 0775, true);
        }

        return $pasta;
    }

    private function chaveCache(string $url): string
    {
        return sha1($url);
    }

    private function lerCache(string $chave): ?string
    {
        $arquivo = $this->pastaCache() . '/' . $chave . '.cache';

        if (!is_file($arquivo)) {
            return null;
        }

        $validade = (int) Configuracao::obter('gerador.cache_segundos', 604800);

        if ($validade > 0 && (time() - (int) filemtime($arquivo)) > $validade) {
            @unlink($arquivo);
            return null;
        }

        $conteudo = @file_get_contents($arquivo);

        return $conteudo === false ? null : $conteudo;
    }

    private function gravarCache(string $chave, string $conteudo): void
    {
        @file_put_contents($this->pastaCache() . '/' . $chave . '.cache', $conteudo, LOCK_EX);
    }

    public function limparCache(): int
    {
        $arquivos = glob($this->pastaCache() . '/*.cache') ?: [];
        $apagados = 0;

        foreach ($arquivos as $arquivo) {
            if (@unlink($arquivo)) {
                $apagados++;
            }
        }

        return $apagados;
    }
}
