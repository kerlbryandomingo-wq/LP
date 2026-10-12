<?php
/**
 * Application bootstrap — loaded by every entry point (public/index.php,
 * public/install/index.php, CLI scripts). No Composer required: this file
 * registers a minimal autoloader for the App\ namespace so the app runs
 * even on hosts where `composer install` was never run.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Errors go to storage/logs, never to the browser.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
    // Sent from PHP as well as .htaccess so they apply even where the host
    // has mod_headers disabled. No inline scripts are used anywhere, so the
    // policy can forbid them outright (XSS defense in depth).
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

/**
 * URL path the application is served under, without a trailing slash:
 * '' for https://example.com/, '/lpsystem' for https://example.com/lpsystem/.
 *
 * Works for all three supported layouts without any configuration:
 *  - package extracted into the web root (root .htaccess rewrites into public/)
 *  - package extracted into a subfolder of the web root
 *  - document root pointed directly at public/
 * In the first two, requests are internally rewritten into /…/public/, so
 * SCRIPT_NAME contains "/public" while the browser-visible URL doesn't; the
 * "/public" segment is dropped unless the visitor really used it.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = '';
    if (PHP_SAPI === 'cli') {
        return $base;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $publicDir = realpath(APP_ROOT . '/public') ?: '';
    $rootDir = realpath(APP_ROOT) ?: '';
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($publicDir !== '' && str_starts_with($scriptFile, $publicDir . DIRECTORY_SEPARATOR)) {
        // Entry point inside public/ (index.php or install/index.php).
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($publicDir)));
        $publicUrl = str_ends_with($scriptName, $relative) ? substr($scriptName, 0, -strlen($relative)) : '';
        $base = $publicUrl;
        if (str_ends_with($publicUrl, '/public') && !str_starts_with($requestPath, $publicUrl . '/')) {
            $base = substr($publicUrl, 0, -strlen('/public'));
        }
    } elseif ($rootDir !== '' && str_starts_with($scriptFile, $rootDir . DIRECTORY_SEPARATOR)) {
        // Root index.php fallback (host served the package root without rewriting).
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }

    // Only plain path characters — this value is printed into HTML and headers.
    if (!preg_match('#^(/[A-Za-z0-9._~\-]+)*$#', $base)) {
        $base = '';
    }
    return $base;
}

/** Application URL for an app-relative path: url('/login') -> '/lpsystem/login'. */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** HTML-escape helper usable from any template. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Coloured status pill used by every list/detail screen. */
function status_badge(?string $status): string
{
    $tone = match ($status) {
        'CONFIRMED', 'APPROVED', 'COMPLETED', 'RELEASED', 'READY', 'PASS', 'ACTIVE' => 'green',
        'REJECTED', 'CANCELLED', 'FAIL', 'REWORK', 'INACTIVE' => 'red',
        'PENDING', 'SENT', 'ON_HOLD', 'QC', 'REVISION_REQUESTED', 'NEW' => 'yellow',
        'OPEN', 'IN_PRODUCTION', 'IN_PROGRESS', 'FULFILLING', 'QUOTED' => 'blue',
        default => 'slate',
    };
    return '<span class="badge badge-' . $tone . '">' . e(str_replace('_', ' ', (string) $status)) . '</span>';
}

/** Value of a submitted field for re-displaying a form after a validation error. */
function old(array $old, string $key, $default = ''): string
{
    $v = $old[$key] ?? $default;
    return is_scalar($v) ? (string) $v : '';
}

/**
 * Installed = the installer finished. Either signal counts, so deleting just
 * one of them (e.g. a stray lock-file removal) can never reopen the installer
 * over a live system.
 */
function is_installed(): bool
{
    if (is_file(APP_ROOT . '/storage/install.lock')) {
        return true;
    }
    $file = APP_ROOT . '/config/config.php';
    if (is_file($file)) {
        $cfg = require $file;
        return is_array($cfg) && !empty($cfg['installed']);
    }
    return false;
}

/** Minimal standalone error page — must not depend on the database or views. */
function render_fatal_page(int $status, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $css = e(url('/assets/css/app.css'));
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . "<title>" . e($title) . "</title><link rel=\"stylesheet\" href=\"{$css}\"></head><body><div class=\"login-page\"><div class=\"login-card\">"
        . "<h1 class=\"page-title\">" . e($title) . "</h1><p>" . $message . "</p></div></div></body></html>";
}

/** @return array<string,mixed> */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = APP_ROOT . '/config/config.php';
    if (!is_file($file)) {
        if (defined('LP_INSTALLER_CONTEXT')) {
            return [];
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "LP System is not installed yet.\n");
            exit(1);
        }
        if (is_installed()) {
            // Lock file present but config.php is gone: never reopen the
            // installer over an existing system — ask for a restore instead.
            render_fatal_page(503, 'Configuration missing', 'This LP System installation is missing <code>config/config.php</code>. Restore it from your backup (see BACKUP.md).');
            exit;
        }
        header('Location: ' . url('/install/'));
        exit;
    }
    $config = require $file;
    return $config;
}

// Uncaught exceptions/fatal errors: log the details, show a generic page.
// Never echo exception messages — they can contain SQL or file paths.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e): void {
        $ref = bin2hex(random_bytes(4));
        error_log("[ref {$ref}] Uncaught " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        render_fatal_page(500, 'Something went wrong', 'The request could not be completed. Please go back and try again. If it keeps happening, give your administrator this reference: <code>' . e($ref) . '</code>');
    });
}

if (!defined('LP_INSTALLER_CONTEXT')) {
    date_default_timezone_set(app_config()['app']['timezone'] ?? 'Asia/Manila');
} else {
    date_default_timezone_set('Asia/Manila');
}
