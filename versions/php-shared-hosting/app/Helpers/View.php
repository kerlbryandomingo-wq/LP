<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Services\AuthService;

final class View
{
    /** Renders a content view inside the shared authenticated layout (sidebar/header — visual parity with the Next.js app's Shell component). */
    public static function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $user = AuthService::currentUser();
        $contentFile = APP_ROOT . '/app/Views/' . $view . '.php';
        ob_start();
        require $contentFile;
        $content = ob_get_clean();
        require APP_ROOT . '/app/Views/layouts/app.php';
    }

    /** Renders a view with no shared layout (login page, installer, printable documents). */
    public static function renderBare(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/app/Views/' . $view . '.php';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function money($value): string
    {
        return '₱' . number_format((float) $value, 2);
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }
}
