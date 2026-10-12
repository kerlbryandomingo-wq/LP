<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;

final class AuthController
{
    public function showLogin(array $params): void
    {
        if (AuthService::check()) {
            View::redirect('/dashboard');
        }
        View::renderBare('auth/login', ['error' => null, 'email' => '']);
    }

    public function login(array $params): void
    {
        Csrf::requireValid();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            View::renderBare('auth/login', ['error' => 'Please enter both email and password.', 'email' => $email]);
            return;
        }

        $result = AuthService::login($email, $password);
        if (!$result['ok']) {
            http_response_code(401);
            View::renderBare('auth/login', ['error' => $result['error'], 'email' => $email]);
            return;
        }

        AuditService::log($result['user']['id'], 'LOGIN', 'User', $result['user']['id']);
        View::redirect((bool) $result['user']['must_change_password'] ? '/account/password' : '/dashboard');
    }

    public function logout(array $params): void
    {
        Csrf::requireValid();
        $user = AuthService::currentUser();
        if ($user) {
            AuditService::log($user['id'], 'LOGOUT', 'User', $user['id']);
        }
        AuthService::logout();
        View::redirect('/login');
    }

    public function showPassword(array $params): void
    {
        $user = AuthService::requireLogin(true);
        View::render('auth/password', ['user' => $user, 'errors' => [], 'forced' => (bool) $user['must_change_password']]);
    }

    public function changePassword(array $params): void
    {
        $user = AuthService::requireLogin(true);
        Csrf::requireValid();

        $errors = [];
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if ($problem = AuthService::passwordProblem($new, $confirm)) {
            $errors[] = $problem;
        }
        if (!$errors && password_verify($new, (string) $user['password_hash'])) {
            $errors[] = 'Choose a password different from your current one.';
        }
        if ($errors) {
            View::render('auth/password', ['user' => $user, 'errors' => $errors, 'forced' => (bool) $user['must_change_password']], 422);
            return;
        }

        Database::connection()->prepare('UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id')
            ->execute(['h' => AuthService::hashPassword($new), 'id' => $user['id']]);
        AuditService::log($user['id'], 'PASSWORD_CHANGED', 'User', $user['id']);
        session_regenerate_id(true);
        View::flash('success', 'Your password has been changed.');
        View::redirect('/dashboard');
    }
}
