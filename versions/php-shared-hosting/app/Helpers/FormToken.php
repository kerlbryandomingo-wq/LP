<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

/**
 * One-time token on "create" forms (payments, quotations, orders, …).
 * consume() inserts the token inside the caller's transaction; a second
 * submission of the same form (double-click, browser resubmit) hits the
 * primary key and is refused, so it can never create a duplicate record.
 * If the caller's transaction rolls back (e.g. validation failed later),
 * the token is released and the user can simply submit again.
 */
final class FormToken
{
    public static function field(): string
    {
        return '<input type="hidden" name="_submission" value="' . bin2hex(random_bytes(32)) . '">';
    }

    /** @return bool true the first time a token is used; false if missing/invalid or already used */
    public static function consume(PDO $pdo, ?string $userId): bool
    {
        $token = $_POST['_submission'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO form_submissions (token, user_id) VALUES (:t, :u)');
        $stmt->execute(['t' => $token, 'u' => $userId]);
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM form_submissions WHERE created_at < (NOW() - INTERVAL 30 DAY)');
        }
        return $stmt->rowCount() === 1;
    }
}
