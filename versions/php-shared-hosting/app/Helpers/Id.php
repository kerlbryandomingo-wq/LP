<?php

declare(strict_types=1);

namespace App\Helpers;

/** Generates a short, URL-safe unique ID (same role as Prisma's cuid() in the original schema) — not cryptographically meaningful, just a collision-resistant primary key. */
final class Id
{
    public static function generate(string $prefix = ''): string
    {
        $raw = bin2hex(random_bytes(12));
        return $prefix !== '' ? "{$prefix}_{$raw}" : $raw;
    }
}
