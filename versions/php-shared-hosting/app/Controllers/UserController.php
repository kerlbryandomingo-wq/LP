<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\FormToken;
use App\Helpers\Id;
use App\Helpers\Input;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;

/**
 * Admin user management: staff/production/admin accounts. New accounts and
 * password resets get a one-time temporary password shown once to the
 * admin; the user must choose their own password at first sign-in.
 */
final class UserController
{
    public const ROLES = ['ADMIN' => 'Administrator', 'STAFF' => 'Staff', 'PRODUCTION' => 'Production'];

    public function index(array $params): void
    {
        $me = AuthService::authorize('users.manage');
        $users = Database::connection()->query(
            "SELECT id, name, email, role, active, must_change_password, last_active_at, created_at FROM users WHERE role <> 'CUSTOMER' ORDER BY active DESC, name ASC"
        )->fetchAll();
        $temp = $_SESSION['_temp_password'] ?? null;
        unset($_SESSION['_temp_password']);
        View::render('users/index', ['users' => $users, 'me' => $me, 'temp' => $temp, 'old' => [], 'errors' => [], 'pageTitle' => 'Users']);
    }

    private static function tempPassword(): string
    {
        // 12 chars from an unambiguous alphabet, always with a letter and a digit.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $p = '';
            for ($i = 0; $i < 12; $i++) {
                $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p));
        return $p;
    }

    public function store(array $params): void
    {
        $me = AuthService::authorize('users.manage');
        Csrf::requireValid();
        $errors = [];
        $name = Input::required('name', 'Name', $errors);
        $email = Input::email('email', 'Email', $errors, true);
        $role = Input::enum('role', 'Role', array_keys(self::ROLES), $errors);
        $pdo = Database::connection();
        if ($email) {
            $s = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
            $s->execute(['e' => $email]);
            if ($s->fetchColumn()) {
                $errors[] = 'An account with that email already exists.';
            }
        }
        if ($errors) {
            $users = $pdo->query("SELECT id, name, email, role, active, must_change_password, last_active_at, created_at FROM users WHERE role <> 'CUSTOMER' ORDER BY active DESC, name ASC")->fetchAll();
            View::render('users/index', ['users' => $users, 'me' => $me, 'temp' => null, 'old' => $_POST, 'errors' => $errors, 'pageTitle' => 'Users'], 422);
            return;
        }
        $pdo->beginTransaction();
        if (!FormToken::consume($pdo, $me['id'])) {
            $pdo->rollBack();
            View::flash('info', 'This form was already submitted — the account was not created twice.');
            View::redirect('/admin/users');
        }
        $id = Id::generate('usr');
        $temp = self::tempPassword();
        $pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash, role, active, must_change_password) VALUES (:id, :name, :email, :hash, :role, 1, 1)'
        )->execute(['id' => $id, 'name' => $name, 'email' => $email, 'hash' => AuthService::hashPassword($temp), 'role' => $role]);
        AuditService::log($me['id'], 'USER_CREATED', 'User', $id, ['email' => $email, 'role' => $role]);
        $pdo->commit();
        $_SESSION['_temp_password'] = ['email' => $email, 'password' => $temp];
        View::flash('success', "Account created for {$email}.");
        View::redirect('/admin/users');
    }

    private function target(string $id): array
    {
        return View::findOr404("SELECT * FROM users WHERE id = :id AND role <> 'CUSTOMER'", ['id' => $id], 'User');
    }

    private function activeAdminCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND active = 1")->fetchColumn();
    }

    public function update(array $params): void
    {
        $me = AuthService::authorize('users.manage');
        Csrf::requireValid();
        $u = $this->target($params['id']);
        $errors = [];
        $role = Input::enum('role', 'Role', array_keys(self::ROLES), $errors, $u['role']);
        $active = Input::str('active') === '1' ? 1 : 0;
        if ($u['id'] === $me['id'] && ($role !== $u['role'] || !$active)) {
            $errors[] = 'You cannot change your own role or deactivate your own account.';
        }
        $losingAdmin = $u['role'] === 'ADMIN' && (int) $u['active'] === 1 && ($role !== 'ADMIN' || !$active);
        if ($losingAdmin && $this->activeAdminCount() <= 1) {
            $errors[] = 'There must always be at least one active administrator.';
        }
        if ($errors) {
            View::flash('error', implode(' ', $errors));
            View::redirect('/admin/users');
        }
        Database::connection()->prepare('UPDATE users SET role = :r, active = :a WHERE id = :id')->execute(['r' => $role, 'a' => $active, 'id' => $u['id']]);
        AuditService::log($me['id'], 'USER_UPDATED', 'User', $u['id'], [
            'role_from' => $u['role'], 'role_to' => $role, 'active_from' => (int) $u['active'], 'active_to' => $active,
        ]);
        if (!$active) {
            // Signed-in sessions of a deactivated user end on their next request (AuthService::currentUser).
            Database::connection()->prepare('DELETE FROM login_attempts WHERE email = :e')->execute(['e' => $u['email']]);
        }
        View::flash('success', "Saved {$u['email']}.");
        View::redirect('/admin/users');
    }

    public function resetPassword(array $params): void
    {
        $me = AuthService::authorize('users.manage');
        Csrf::requireValid();
        $u = $this->target($params['id']);
        if ($u['id'] === $me['id']) {
            View::flash('error', 'Use "Change Password" to change your own password.');
            View::redirect('/admin/users');
        }
        $temp = self::tempPassword();
        Database::connection()->prepare('UPDATE users SET password_hash = :h, must_change_password = 1 WHERE id = :id')
            ->execute(['h' => AuthService::hashPassword($temp), 'id' => $u['id']]);
        Database::connection()->prepare('DELETE FROM login_attempts WHERE email = :e')->execute(['e' => $u['email']]);
        AuditService::log($me['id'], 'USER_PASSWORD_RESET', 'User', $u['id']);
        $_SESSION['_temp_password'] = ['email' => $u['email'], 'password' => $temp];
        View::flash('success', "Temporary password generated for {$u['email']}.");
        View::redirect('/admin/users');
    }
}
