<?php

declare(strict_types=1);

namespace App\Helpers;

/** One token per session, verified on every state-changing request (spec section 9/15). */
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
        $t = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"_csrf\" value=\"{$t}\">";
    }

    public static function verify(?string $token): bool
    {
        if (!$token || empty($_SESSION['_csrf'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf'], $token);
    }

    /**
     * Call at the top of every POST handler; exits with 403 on failure.
     * Deliberately not 419: Apache (and so most shared hosts) doesn't
     * recognise that non-standard code and turns it into a 500.
     */
    public static function requireValid(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify($token)) {
            http_response_code(403);
            Logger::warning('CSRF token mismatch on ' . ($_SERVER['REQUEST_URI'] ?? ''));
            echo 'Your session has expired or this request could not be verified. Please go back, refresh the page, and try again.';
            exit;
        }
    }
}
