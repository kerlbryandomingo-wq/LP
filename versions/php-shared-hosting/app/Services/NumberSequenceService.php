<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * Concurrency-safe QUO-/ORD-/JO-2026-#### numbering. Two concurrent
 * requests calling next() for the same scope/year can never receive the
 * same number: the UPSERT + LAST_INSERT_ID(value+1) pattern below is
 * atomic at the row level in InnoDB, so the second request's UPDATE
 * simply waits for the first's row lock rather than racing it.
 */
final class NumberSequenceService
{
    public static function next(string $scope, string $prefix): string
    {
        $pdo = Database::connection();
        $year = (int) date('Y');

        $pdo->prepare(
            'INSERT INTO number_sequences (scope, year, value) VALUES (:scope, :year, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)'
        )->execute(['scope' => $scope, 'year' => $year]);

        $value = (int) $pdo->lastInsertId();
        return sprintf('%s-%d-%04d', $prefix, $year, $value);
    }
}
