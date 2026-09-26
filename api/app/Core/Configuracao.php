<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Guarda o array de config/config.php e permite ler valores
 * usando caminho com ponto: Configuracao::obter('banco.host').
 */
final class Configuracao
{
    private static ?array $valores = null;

    public static function carregar(string $caminho): void
    {
        if (!is_file($caminho)) {
            throw new \RuntimeException(
                'Arquivo de configuracao nao encontrado. Copie config/config.exemplo.php para config/config.php.'
            );
        }

        $valores = require $caminho;

        if (!is_array($valores)) {
            throw new \RuntimeException('config/config.php precisa retornar um array.');
        }

        self::$valores = $valores;
    }

    /** @return mixed */
    public static function obter(string $caminho, mixed $padrao = null): mixed
    {
        if (self::$valores === null) {
            throw new \RuntimeException('Configuracao ainda nao carregada.');
        }

        $atual = self::$valores;

        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }
            $atual = $atual[$parte];
        }

        return $atual;
    }

    public static function ehDesenvolvimento(): bool
    {
        return self::obter('ambiente', 'producao') === 'desenvolvimento';
    }
}
