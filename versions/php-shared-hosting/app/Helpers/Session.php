<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Secure session bootstrap (spec section 9): HttpOnly always, Secure
 * when served over HTTPS, SameSite=Lax (Strict would break an external
 * payment-gateway redirect back into the app, Lax is the standard safe
 * default), session ID regenerated on login (see AuthService::login) to
 * prevent session fixation, and an idle-timeout enforced on every request.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = app_config()['session'] ?? [];
        $secure = (bool) ($cfg['secure_cookie'] ?? false);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name($cfg['name'] ?? 'lp_session');
        session_start();

        $lifetimeMinutes = (int) ($cfg['lifetime_minutes'] ?? 240);
        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > $lifetimeMinutes * 60) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
