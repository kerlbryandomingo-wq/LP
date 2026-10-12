<?php

declare(strict_types=1);

namespace App\Helpers;

/** CSV download with spreadsheet formula-injection protection. */
final class Csv
{
    /**
     * A value a user typed (customer name, reference number…) that starts
     * with = + - @ or a tab/CR could be executed as a formula when the file is
     * opened in Excel/Sheets. Prefix those with an apostrophe so they display
     * as text. Numbers we generate ourselves are passed as numbers and kept.
     */
    public static function cell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /** @param string[] $headers @param iterable<array> $rows */
    public static function download(string $filename, array $headers, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and accents correctly
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }
}
