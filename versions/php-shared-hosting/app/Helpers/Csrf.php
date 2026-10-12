<?php

declare(strict_types=1);

namespace App\Helpers;

/** One token per session, verified on every state-changing request. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && $token !== '' && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;
        return match ($unit) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    /**
     * Call at the top of every POST handler. 403 on failure — deliberately
     * not 419, which Apache doesn't recognise and turns into a 500.
     */
    public static function requireValid(): void
    {
        // A request larger than post_max_size arrives with $_POST emptied, so
        // the token "disappears"; say what actually happened instead.
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit = self::iniBytes((string) ini_get('post_max_size'));
        if (!$_POST && $limit > 0 && $length > $limit) {
            View::abort(413, 'Upload too large', 'The file you attached is larger than this server accepts (' . ini_get('post_max_size') . '). Please attach a smaller file.');
        }

        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            Logger::warning('CSRF token mismatch on ' . ($_SERVER['REQUEST_URI'] ?? ''));
            View::abort(403, 'Request expired', 'Your session has expired or this request could not be verified. Go back, refresh the page, and try again.');
        }
    }
}
