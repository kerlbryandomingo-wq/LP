<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\AuditService;

final class AuthController
{
    public function showLogin(array $params): void
    {
        if (AuthService::check()) {
            View::redirect('/dashboard');
        }
        View::renderBare('auth/login', ['error' => $_GET['error'] ?? null]);
    }

    public function login(array $params): void
    {
        Csrf::requireValid();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            View::renderBare('auth/login', ['error' => 'Please enter both email and password.']);
            return;
        }

        $result = AuthService::login($email, $password);
        if (!$result['ok']) {
            View::renderBare('auth/login', ['error' => $result['error']]);
            return;
        }

        AuditService::log($result['user']['id'], 'LOGIN', 'User', $result['user']['id']);

        if (!empty($_SESSION['must_change_password'])) {
            View::redirect('/change-password');
        }
        View::redirect('/dashboard');
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
}
