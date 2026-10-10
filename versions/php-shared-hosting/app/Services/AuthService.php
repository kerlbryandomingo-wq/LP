<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Logger;
use App\Helpers\Session;
use PDO;

/**
 * Replaces NextAuth while preserving the original Role model (ADMIN /
 * STAFF / PRODUCTION / CUSTOMER — prisma/schema.prisma's Role enum) and
 * its bcrypt password hashes: the Next.js app hashes with bcryptjs,
 * which produces standard $2a$/$2b$ bcrypt hashes fully compatible with
 * PHP's native password_verify()/PASSWORD_BCRYPT — an existing user's
 * password keeps working unchanged after migration, with no forced reset.
 */
final class AuthService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    public static function attemptsRemaining(string $email): int
    {
        $key = 'login_attempts_' . sha1(strtolower($email));
        $data = $_SESSION[$key] ?? null;
        if (!$data) {
            return self::MAX_ATTEMPTS;
        }
        if (time() - $data['first'] > self::LOCKOUT_SECONDS) {
            unset($_SESSION[$key]);
            return self::MAX_ATTEMPTS;
        }
        return max(0, self::MAX_ATTEMPTS - $data['count']);
    }

    private static function recordFailedAttempt(string $email): void
    {
        $key = 'login_attempts_' . sha1(strtolower($email));
        $data = $_SESSION[$key] ?? ['count' => 0, 'first' => time()];
        if (time() - $data['first'] > self::LOCKOUT_SECONDS) {
            $data = ['count' => 0, 'first' => time()];
        }
        $data['count']++;
        $_SESSION[$key] = $data;
    }

    private static function clearAttempts(string $email): void
    {
        unset($_SESSION['login_attempts_' . sha1(strtolower($email))]);
    }

    /** @return array{ok:bool, error?:string, user?:array} */
    public static function login(string $email, string $password): array
    {
        if (self::attemptsRemaining($email) <= 0) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Please wait a few minutes and try again.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt($email);
            Logger::warning("Failed login attempt for {$email}");
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        if (!(bool) $user['active']) {
            return ['ok' => false, 'error' => 'This account has been deactivated. Contact an administrator.'];
        }

        self::clearAttempts($email);

        // Session fixation protection — a fresh session ID on every
        // privilege change, same as the Next.js app's NextAuth callback.
        Session::regenerate();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['must_change_password'] = (bool) $user['must_change_password'];

        $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || !$user['active']) {
            Session::destroy();
            return null;
        }
        $cached = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::currentUser() !== null;
    }

    /** Server-side role gate — never trust a hidden button or JS check alone (spec section 9). */
    public static function requireRole(array $roles): array
    {
        $user = self::currentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            echo 'You do not have permission to access this page.';
            exit;
        }
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        return $user;
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }
}
