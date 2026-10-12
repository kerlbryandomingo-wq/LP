<?php

declare(strict_types=1);

/**
 * Failure modes on an INSTALLED test copy:
 *   phase "config" — config/config.php missing (lock file present)
 *   phase "dbdown" — database server stopped (run-all.sh stops/starts it)
 * Environment: QA_BASE, QA_APP_ROOT, QA_DB_PASS (must never appear), QA_PHASE, QA_OUT.
 */

require __DIR__ . '/lib.php';

$BASE = rtrim((string) getenv('QA_BASE'), '/');
$ROOT = rtrim((string) getenv('QA_APP_ROOT'), '/');
$phase = (string) getenv('QA_PHASE');
$R = new Results('failure-' . $phase);
$clean = fn (string $b) => !preg_match('#(Stack trace|Fatal error|Warning: |PDOException|SQLSTATE|/var/www|\.php on line|Connection refused|No such file)#', $b)
    && !str_contains($b, (string) getenv('QA_DB_PASS'));

if ($phase === 'config') {
    $R->section('config.php missing');
    rename($ROOT . '/config/config.php', $ROOT . '/config/config.php.qa');
    try {
        $b = new Browser($BASE);
        $r = $b->get('/dashboard');
        $R->check('M1', 'Missing config.php: clear 503 "Configuration missing" page', $r['status'] === 503 && str_contains($r['body'], 'Configuration missing'), "status {$r['status']}");
        $R->check('M2', 'No internals leaked', $clean($r['body']));
        $r = $b->get('/install/');
        $R->check('M3', 'Installer does NOT reopen over the existing system', $r['status'] === 403, "status {$r['status']}");
    } finally {
        rename($ROOT . '/config/config.php.qa', $ROOT . '/config/config.php');
    }
    $r = (new Browser($BASE))->get('/login');
    $R->check('M4', 'Site works again once config.php is restored', $r['status'] === 200);
} else {
    $R->section('database server down');
    $b = new Browser($BASE);
    $login = $b->get('/login'); // renders without the database
    $R->check('M4b', 'Sign-in page still renders while the database is down', $login['status'] === 200);
    $attempts = [
        'POST /login' => fn () => $b->post('/login', ['_csrf' => csrf($login['body']), 'email' => 'qa-admin@example.test', 'password' => 'irrelevant-1']),
        'GET /dashboard' => fn () => $b->get('/dashboard'),
        'GET /orders' => fn () => $b->get('/orders'),
    ];
    foreach ($attempts as $p => $fn) {
        $r = $fn();
        $R->check('M5', "DB down, {$p}: generic error page (500) with a reference code", $r['status'] === 500 && str_contains($r['body'], 'Something went wrong') && (bool) preg_match('#<code>[0-9a-f]{8}</code>#', $r['body']), "status {$r['status']}");
        $R->check('M6', "DB down, {$p}: no SQL error, path or password shown", $clean($r['body']));
    }
    $log = (string) @file_get_contents($ROOT . '/storage/logs/php-error.log');
    $R->check('M7', 'Error details written to storage/logs/php-error.log instead', str_contains($log, 'Uncaught PDOException') || str_contains($log, 'SQLSTATE'));
}

$counts = $R->counts();
file_put_contents((string) getenv('QA_OUT'), json_encode(['layout' => 'failure-' . $phase, 'base' => $BASE, 'rows' => $R->rows, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, sprintf("\n[failure-%s] PASS %d  FAIL %d\n", $phase, $counts['PASS'], $counts['FAIL']));
exit($counts['FAIL'] > 0 ? 1 : 0);
