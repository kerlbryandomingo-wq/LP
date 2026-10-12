<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Services\AuthService;

final class View
{
    /** Renders a content view inside the shared authenticated layout. */
    public static function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        $user = AuthService::currentUser();
        $contentFile = APP_ROOT . '/app/Views/' . $view . '.php';
        ob_start();
        require $contentFile;
        $content = ob_get_clean();
        require APP_ROOT . '/app/Views/layouts/app.php';
    }

    /** Renders a view with no shared layout (login page). */
    public static function renderBare(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/app/Views/' . $view . '.php';
    }

    /**
     * Error page (404/403/409…). Inside the normal layout when someone is
     * logged in, so the sidebar stays available; standalone otherwise.
     */
    public static function abort(int $status, string $title, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && AuthService::currentUser()) {
            self::render('errors/error', ['title' => $title, 'message' => $message], $status);
        } else {
            render_fatal_page($status, $title, e($message) . ' <a href="' . e(url('/')) . '">Go to the home page</a>.');
        }
        exit;
    }

    public static function notFound(string $what): void
    {
        self::abort(404, $what . ' not found', 'It may have been removed, or the link is incorrect.');
    }

    /** Fetch one row by a prepared query, or show the 404 page. */
    public static function findOr404(string $sql, array $params, string $what): array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            self::notFound($what);
        }
        return $row;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function money($value): string
    {
        return '₱' . number_format((float) $value, 2);
    }

    /** Redirect to an app-relative path ('/orders/123'); the subfolder prefix is added here. */
    public static function redirect(string $path): void
    {
        header('Location: ' . url($path));
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
