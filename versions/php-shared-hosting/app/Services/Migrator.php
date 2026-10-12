<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Applies database/migrations/*.sql then database/seeds/*.sql in filename
 * order, recording each in schema_migrations so it is applied exactly once.
 *
 * Every migration is written to be idempotent (CREATE TABLE IF NOT EXISTS,
 * seed rows with ON DUPLICATE KEY), so an install that died half-way can
 * simply be re-run, and a database created by an earlier release (which had
 * no schema_migrations table) is brought up to date by running everything.
 *
 * File format constraint: statements are split on ";" after stripping
 * full-line "--" comments, so string literals must not contain ";".
 */
final class Migrator
{
    /** MySQL/MariaDB: 1060 duplicate column, 1061 duplicate key name, 1826 duplicate foreign key. */
    private const ALREADY_APPLIED_CODES = [1060, 1061, 1826];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return string[] relative names like "migrations/001_initial_schema.sql", in apply order */
    public static function allFiles(): array
    {
        $names = [];
        foreach (['migrations', 'seeds'] as $dir) {
            $files = glob(APP_ROOT . "/database/{$dir}/*.sql") ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $f) {
                $names[] = $dir . '/' . basename($f);
            }
        }
        return $names;
    }

    private function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] */
    public function applied(): array
    {
        $exists = $this->pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
        if (!$exists) {
            return [];
        }
        return $this->pdo->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    public function pending(): array
    {
        $applied = array_flip($this->applied());
        return array_values(array_filter(self::allFiles(), fn ($n) => !isset($applied[$n])));
    }

    /** @return string[] names applied by this call */
    public function migrate(): array
    {
        $this->ensureTrackingTable();
        $done = [];
        foreach ($this->pending() as $name) {
            $sql = file_get_contents(APP_ROOT . '/database/' . $name);
            if ($sql === false) {
                throw new RuntimeException("Cannot read {$name}.");
            }
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    // Re-running an interrupted update: the column/index from
                    // this ALTER is already there, which is the desired end state.
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (in_array($code, self::ALREADY_APPLIED_CODES, true) && preg_match('/^ALTER\s+TABLE/i', $statement)) {
                        continue;
                    }
                    throw new RuntimeException("Database update {$name} failed: " . $e->getMessage(), 0, $e);
                }
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:n)')->execute(['n' => $name]);
            $done[] = $name;
        }
        return $done;
    }

    /** Every table named in a CREATE TABLE statement across the migrations. @return string[] */
    public static function expectedTables(): array
    {
        $tables = ['schema_migrations'];
        foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([a-z_]+)`?/i', (string) file_get_contents($f), $m);
            $tables = array_merge($tables, $m[1]);
        }
        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    /** @return string[] expected tables that are missing from the database */
    public function missingTables(): array
    {
        $present = $this->pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
        $present = array_map('strtolower', $present);
        return array_values(array_diff(self::expectedTables(), $present));
    }
}
