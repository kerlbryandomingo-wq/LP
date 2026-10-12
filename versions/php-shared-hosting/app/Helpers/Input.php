<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Small, explicit request-input readers. Each returns a cleaned value and
 * records a human-readable message in $errors when the value is invalid, so
 * controllers can validate a whole form and report every problem at once.
 */
final class Input
{
    public static function str(string $key, int $max = 255): string
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v)) {
            return '';
        }
        return mb_substr(trim($v), 0, $max);
    }

    public static function strOrNull(string $key, int $max = 255): ?string
    {
        $v = self::str($key, $max);
        return $v === '' ? null : $v;
    }

    public static function required(string $key, string $label, array &$errors, int $max = 255): string
    {
        $v = self::str($key, $max);
        if ($v === '') {
            $errors[] = "{$label} is required.";
        }
        return $v;
    }

    public static function email(string $key, string $label, array &$errors, bool $required = false): ?string
    {
        $v = self::strOrNull($key);
        if ($v === null) {
            if ($required) {
                $errors[] = "{$label} is required.";
            }
            return null;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "{$label} is not a valid email address.";
        }
        return strtolower($v);
    }

    public static function int(string $key, string $label, array &$errors, int $min, int $max, ?int $default = null): ?int
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v) || trim($v) === '') {
            if ($default === null) {
                return null;
            }
            return $default;
        }
        if (!preg_match('/^-?\d+$/', trim($v))) {
            $errors[] = "{$label} must be a whole number.";
            return $default;
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            $errors[] = "{$label} must be between {$min} and {$max}.";
        }
        return $n;
    }

    /** Money/decimal with at most 2 decimal places, as a string safe for DECIMAL(12,2). */
    public static function money(string $key, string $label, array &$errors, float $min = 0.0, bool $required = true): ?string
    {
        $v = $_POST[$key] ?? '';
        $v = is_string($v) ? trim(str_replace(',', '', $v)) : '';
        if ($v === '') {
            if ($required) {
                $errors[] = "{$label} is required.";
            }
            return null;
        }
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v)) {
            $errors[] = "{$label} must be an amount like 1250 or 1250.50.";
            return null;
        }
        if ((float) $v < $min) {
            $errors[] = "{$label} must be at least " . number_format($min, 2) . '.';
        }
        return number_format((float) $v, 2, '.', '');
    }

    public static function date(string $key, string $label, array &$errors, bool $required = false): ?string
    {
        $v = self::strOrNull($key, 20);
        if ($v === null) {
            if ($required) {
                $errors[] = "{$label} is required.";
            }
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $errors[] = "{$label} must be a valid date.";
            return null;
        }
        return $v;
    }

    /** @param string[] $allowed */
    public static function enum(string $key, string $label, array $allowed, array &$errors, ?string $default = null): ?string
    {
        $v = self::strOrNull($key, 50) ?? $default;
        if ($v === null || !in_array($v, $allowed, true)) {
            $errors[] = "{$label} is not a valid choice.";
            return $default;
        }
        return $v;
    }

    /** Escape LIKE wildcards in user search text. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '\\%_') . '%';
    }

    public static function query(string $key, int $max = 100): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }
}
