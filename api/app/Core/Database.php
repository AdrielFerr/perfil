<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Conexao PDO unica (singleton) com o MySQL.
 * Nenhum outro lugar do sistema cria conexao.
 */
final class Database
{
    private static ?PDO $conexao = null;

    private function __construct()
    {
    }

    public static function conexao(): PDO
    {
        if (self::$conexao instanceof PDO) {
            return self::$conexao;
        }

        $banco   = Configuracao::obter('banco');
        $charset = $banco['charset'] ?? 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $banco['host'],
            (int) ($banco['porta'] ?? 3306),
            $banco['nome'],
            $charset
        );

        try {
            self::$conexao = new PDO(
                $dsn,
                $banco['usuario'],
                $banco['senha'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException(
                'Nao foi possivel conectar ao MySQL: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return self::$conexao;
    }

    public static function iniciarTransacao(): void
    {
        if (!self::conexao()->inTransaction()) {
            self::conexao()->beginTransaction();
        }
    }

    public static function confirmar(): void
    {
        if (self::conexao()->inTransaction()) {
            self::conexao()->commit();
        }
    }

    public static function desfazer(): void
    {
        if (self::conexao()->inTransaction()) {
            self::conexao()->rollBack();
        }
    }
}
