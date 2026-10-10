<?php
/**
 * Application bootstrap — loaded by every entry point (public/index.php,
 * install/index.php, cron scripts). No Composer required: this file
 * registers a minimal PSR-4-ish autoloader for the App\ namespace so the
 * app runs even on hosts where `composer install` was never run. If
 * vendor/autoload.php exists (Composer was run), it's used instead/also,
 * but nothing in this app requires that.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Composer's autoloader, if present — harmless either way since our own
// autoloader below only registers classes Composer doesn't already know.
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

// Errors go to storage/logs, never to the browser — see Security.md.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

/** @return array<string,mixed> */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = APP_ROOT . '/config/config.php';
    if (!is_file($file)) {
        // No config yet — every entry point except the installer must stop here.
        if (!defined('LP_INSTALLER_CONTEXT')) {
            http_response_code(503);
            echo 'LP System is not installed yet. Visit /install/ to set it up.';
            exit;
        }
        return [];
    }
    $config = require $file;
    return $config;
}

date_default_timezone_set(app_config()['app']['timezone'] ?? 'Asia/Manila');
