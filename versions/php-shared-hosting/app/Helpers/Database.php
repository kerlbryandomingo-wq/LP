<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use PDOException;

/**
 * Single PDO connection, lazily created. Every query in this app goes
 * through prepared statements via this class — no string-concatenated SQL
 * anywhere (SQL-injection protection, spec section 15).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = app_config()['db'] ?? [];
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $dbname = $cfg['database'] ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // InnoDB transactions are relied on throughout (e.g. quotation
                // -> order conversion, payment confirmation + balance checks) —
                // see Services/*.php's use of beginTransaction()/commit().
            ]);
        } catch (PDOException $e) {
            Logger::error('Database connection failed: ' . $e->getMessage());
            throw $e;
        }

        return self::$pdo;
    }

    /** Explicit connection for the installer, which tests credentials before any config.php exists. */
    public static function testConnection(string $host, int $port, string $dbname, string $username, string $password): array
    {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = $pdo->query('SELECT VERSION() AS v')->fetch()['v'] ?? 'unknown';
            return ['ok' => true, 'version' => $version];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
