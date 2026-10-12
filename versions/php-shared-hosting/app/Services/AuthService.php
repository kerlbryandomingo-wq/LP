<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Logger;
use App\Helpers\Session;
use App\Helpers\View;

/**
 * Replaces NextAuth while preserving the original Role model (ADMIN / STAFF /
 * PRODUCTION / CUSTOMER) and its bcrypt password hashes: the Next.js app
 * hashes with bcryptjs, whose $2a$/$2b$ hashes PHP's password_verify()
 * accepts unchanged, so migrated passwords keep working.
 */
final class AuthService
{
    /** Failed attempts allowed per email (and, more generously, per IP) inside the window. */
    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 30;
    public const WINDOW_MINUTES = 15;

    /** Used to keep response time similar whether or not the email exists. */
    private const DUMMY_HASH = '$2y$10$nS2D0kmmHiPy3hWcvjqoaeTTrzavJuFIEQ0XBXvNiDjcJjeCBXcb.';

    private static ?array $cachedUser = null;

    private static function clientIp(): string
    {
        // REMOTE_ADDR only — X-Forwarded-For is client-controlled.
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }

    /** login_attempts only exists after database update 003; before it is applied, throttling is skipped so an admin can still sign in to apply it. */
    private static function attemptsTableReady(): bool
    {
        static $ready = null;
        return $ready ??= (bool) Database::connection()->query("SHOW TABLES LIKE 'login_attempts'")->fetchColumn();
    }

    public static function isThrottled(string $email): bool
    {
        if (!self::attemptsTableReady()) {
            return false;
        }
        $pdo = Database::connection();
        $window = self::WINDOW_MINUTES;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = :e AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)");
        $stmt->execute(['e' => strtolower($email)]);
        if ((int) $stmt->fetchColumn() >= self::MAX_FAILURES_PER_EMAIL) {
            return true;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)");
        $stmt->execute(['ip' => self::clientIp()]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES_PER_IP;
    }

    private static function recordFailure(string $email): void
    {
        if (!self::attemptsTableReady()) {
            return;
        }
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:e, :ip)')
            ->execute(['e' => strtolower($email), 'ip' => self::clientIp()]);
        // Opportunistic cleanup so the table never grows without bound.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    /** @return array{ok:bool, error?:string, user?:array} */
    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (self::isThrottled($email)) {
            Logger::warning('Login throttled for ' . $email . ' from ' . self::clientIp());
            return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . self::WINDOW_MINUTES . ' minutes and try again.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        $hash = ($user && $user['password_hash']) ? $user['password_hash'] : self::DUMMY_HASH;
        $valid = password_verify($password, $hash) && $user && $user['password_hash'];

        if (!$valid) {
            self::recordFailure($email);
            Logger::warning('Failed login for ' . $email . ' from ' . self::clientIp());
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        if (!(bool) $user['active']) {
            return ['ok' => false, 'error' => 'This account has been deactivated. Contact an administrator.'];
        }
        if ($user['role'] === 'CUSTOMER') {
            return ['ok' => false, 'error' => 'Customer accounts cannot sign in to this edition of LP System (the customer portal is not available yet).'];
        }

        if (self::attemptsTableReady()) {
            $pdo->prepare('DELETE FROM login_attempts WHERE email = :e')->execute(['e' => $email]);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute(['h' => self::hashPassword($password), 'id' => $user['id']]);
        }

        Session::regenerate();
        $_SESSION['user_id'] = $user['id'];
        $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

        self::$cachedUser = null;
        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        self::$cachedUser = null;
        Session::destroy();
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        // Deactivation (or a role no longer allowed here) takes effect on the very next request.
        if (!$user || !$user['active'] || $user['role'] === 'CUSTOMER') {
            $_SESSION = [];
            return null;
        }
        self::$cachedUser = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::currentUser() !== null;
    }

    /** Signed-in user or redirect to the login page. Also enforces a pending forced password change. */
    public static function requireLogin(bool $allowPendingPasswordChange = false): array
    {
        $user = self::currentUser();
        if (!$user) {
            View::redirect('/login');
        }
        if (!$allowPendingPasswordChange && (bool) $user['must_change_password']) {
            View::redirect('/account/password');
        }
        return $user;
    }

    /** Server-side permission gate — 403 if the signed-in user's role lacks the ability. */
    public static function authorize(string $ability): array
    {
        $user = self::requireLogin();
        if (!Policy::allows($user, $ability)) {
            Logger::warning("Forbidden: user {$user['id']} ({$user['role']}) lacked {$ability} on " . ($_SERVER['REQUEST_URI'] ?? ''));
            View::abort(403, 'Not allowed', 'Your account does not have permission to do this.');
        }
        return $user;
    }

    public static function can(string $ability): bool
    {
        return Policy::allows(self::currentUser(), $ability);
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    /** Shared password rule for the installer, user creation and password changes. Returns an error or null. */
    public static function passwordProblem(string $password, string $confirm): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (strlen($password) > 72) {
            return 'Password must be at most 72 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        if ($password !== $confirm) {
            return 'Passwords do not match.';
        }
        return null;
    }
}
