<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\Migrator;

/** Admin → Database Updates: applies new database/migrations after uploading a newer release. */
final class SystemController
{
    public function updates(array $params): void
    {
        AuthService::authorize('system.updates');
        $m = new Migrator(Database::connection());
        View::render('system/updates', [
            'pending' => $m->pending(),
            'applied' => $m->applied(),
            'missing' => $m->missingTables(),
            'phpVersion' => PHP_VERSION,
            'dbVersion' => (string) Database::connection()->query('SELECT VERSION()')->fetchColumn(),
            'pageTitle' => 'Database Updates',
        ]);
    }

    public function applyUpdates(array $params): void
    {
        $user = AuthService::authorize('system.updates');
        Csrf::requireValid();
        $m = new Migrator(Database::connection());
        try {
            $done = $m->migrate();
        } catch (\RuntimeException $e) {
            error_log('Database update failed: ' . $e->getMessage());
            View::flash('error', 'A database update failed and was stopped. Details were written to storage/logs/php-error.log. Restore your backup if the site misbehaves (see BACKUP.md).');
            View::redirect('/admin/updates');
        }
        AuditService::log($user['id'], 'DATABASE_UPDATED', 'System', '-', ['applied' => $done]);
        View::flash('success', $done ? 'Applied ' . count($done) . ' database update(s).' : 'The database was already up to date.');
        View::redirect('/admin/updates');
    }
}
