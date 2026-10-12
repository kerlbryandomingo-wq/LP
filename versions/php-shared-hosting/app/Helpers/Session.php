<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Secure session bootstrap: HttpOnly always; Secure whenever the request is
 * HTTPS (or the installer recorded HTTPS); SameSite=Lax; cookie scoped to the
 * install's own path so two installs on one domain never share a session;
 * session ID regenerated on login (AuthService::login); idle timeout.
 */
final class Session
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = app_config()['session'] ?? [];
        $secure = (bool) ($cfg['secure_cookie'] ?? false) || self::isHttps();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() . '/',
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
            $_SESSION = [];
            session_regenerate_id(true);
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
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
