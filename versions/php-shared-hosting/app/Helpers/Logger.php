<?php

declare(strict_types=1);

namespace App\Helpers;

/** Writes to storage/logs, never to the browser — see SECURITY.md "secure error handling." */
final class Logger
{
    private static function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message);
        $path = APP_ROOT . '/storage/logs/app.log';
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARNING', $message);
    }
}
