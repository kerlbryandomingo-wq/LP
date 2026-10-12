<?php

declare(strict_types=1);

/**
 * Repeat-install QA: a fresh, uninstalled copy of the package pointed at
 *  (1) a database that holds another application's "users" table → refused;
 *  (2) a database that already holds LP System data (from the root-layout
 *      run) → requires explicit confirmation, keeps every record, refuses to
 *      reuse an existing admin email, and does not create duplicates.
 *
 * Environment: QA_BASE, QA_APP_ROOT, QA_DB_HOST/USER/PASS, QA_DB_NAME (LP
 * data), QA_FOREIGN_DB, QA_ADMIN_PASSWORD, QA_OUT.
 */

require __DIR__ . '/lib.php';

$env = fn (string $k, ?string $d = null) => getenv($k) !== false ? (string) getenv($k) : ($d ?? throw new RuntimeException("Missing env {$k}"));
$BASE = rtrim($env('QA_BASE'), '/');
$ROOT = rtrim($env('QA_APP_ROOT'), '/');
$DB = ['host' => $env('QA_DB_HOST', '127.0.0.1'), 'port' => '3306', 'name' => $env('QA_DB_NAME'), 'user' => $env('QA_DB_USER'), 'pass' => $env('QA_DB_PASS')];
$PW = $env('QA_ADMIN_PASSWORD');
$R = new Results('reinstall');
$pdo = new PDO("mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$one = fn (string $sql) => $pdo->query($sql)->fetchColumn();
set_exception_handler(function (Throwable $e) use ($R) {
    $R->check('ABORT', 'Reinstall run aborted', false, $e->getMessage() . ' line ' . $e->getLine());
    file_put_contents(getenv('QA_OUT'), json_encode(['layout' => 'reinstall', 'rows' => $R->rows, 'counts' => $R->counts()], JSON_PRETTY_PRINT));
    exit(1);
});

$before = [
    'customers' => (int) $one('SELECT COUNT(*) FROM customers'),
    'orders' => (int) $one('SELECT COUNT(*) FROM orders'),
    'payments' => (int) $one('SELECT COUNT(*) FROM payments'),
    'users' => (int) $one('SELECT COUNT(*) FROM users'),
    'migrations' => (int) $one('SELECT COUNT(*) FROM schema_migrations'),
    'stages' => (int) $one('SELECT COUNT(*) FROM workflow_stages'),
];

$R->section('Installer over existing databases');
$b = new Browser($BASE);
$t = input_value($b->get('/install/')['body'], '_token');
$b->post('/install/', ['_token' => $t, '_step' => '1']);
$b->post('/install/', ['_token' => $t, '_step' => '2']);
$base3 = ['_token' => $t, '_step' => '3', 'db_host' => $DB['host'], 'db_port' => '3306', 'db_user' => $DB['user'], 'db_pass' => $DB['pass']];

$r = $b->post('/install/', ['db_name' => $env('QA_FOREIGN_DB')] + $base3);
$R->check('R1', 'Database with another application\'s "users" table is refused', $r['status'] === 422 && str_contains($r['body'], 'different application'));

$r = $b->post('/install/', ['db_name' => $DB['name']] + $base3);
$R->check('R2', 'Existing LP System data detected; confirmation required (409)', $r['status'] === 409 && str_contains($r['body'], 'already contains LP System data') && str_contains($r['body'], 'name="confirm_existing"'));
$R->check('R3', 'Nothing changed without confirmation', (int) $one('SELECT COUNT(*) FROM customers') === $before['customers']);

$r = $b->post('/install/', ['db_name' => $DB['name'], 'confirm_existing' => '1'] + $base3);
$R->check('R4', 'With confirmation the wizard continues', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=4'));
$s4 = $b->get('/install/?step=4');
$R->check('R5', 'Step 4 warns that existing data is kept', str_contains($s4['body'], 'existing records are kept'));
$r = $b->post('/install/', ['_token' => $t, '_step' => '4']);
$R->check('R6', 'Table step on an up-to-date database succeeds without changes', in_array($r['status'], [302, 303], true) && (int) $one('SELECT COUNT(*) FROM schema_migrations') === $before['migrations'] && (int) $one('SELECT COUNT(*) FROM workflow_stages') === $before['stages']);

$adm = ['_token' => $t, '_step' => '5', 'admin_name' => 'QA Second Admin', 'admin_password' => $PW, 'admin_password_confirm' => $PW];
$r = $b->post('/install/', ['admin_email' => 'qa-admin@example.test'] + $adm);
$R->check('R7', 'Existing administrator email cannot be reused', $r['status'] === 422 && str_contains($r['body'], 'already exists'));
$r = $b->post('/install/', ['admin_email' => 'qa-admin2@example.test'] + $adm);
$r = $b->post('/install/', ['_token' => $t, '_step' => '6', 'business_name' => 'QA Print Shop', 'app_url' => '', 'timezone' => 'Asia/Manila']);
$R->check('R8', 'Install completes and reports that existing data was kept', $r['status'] === 200 && str_contains($r['body'], 'existing data was kept'));
$after = [
    'customers' => (int) $one('SELECT COUNT(*) FROM customers'),
    'orders' => (int) $one('SELECT COUNT(*) FROM orders'),
    'payments' => (int) $one('SELECT COUNT(*) FROM payments'),
    'users' => (int) $one('SELECT COUNT(*) FROM users'),
];
$R->check('R9', 'All existing customers, orders and payments preserved', $after['customers'] === $before['customers'] && $after['orders'] === $before['orders'] && $after['payments'] === $before['payments'], json_encode([$before, $after]));
$R->check('R10', 'Exactly one new administrator added', $after['users'] === $before['users'] + 1);
$c = new Browser($BASE);
$lp = $c->get('/login');
$c->post('/login', ['_csrf' => csrf($lp['body']), 'email' => 'qa-admin2@example.test', 'password' => $PW]);
$o = $c->get('/orders');
$R->check('R11', 'New administrator sees the existing orders', $o['status'] === 200 && str_contains($o['body'], 'ORD-'));
$R->check('R12', 'Installer locked again afterwards', (new Browser($BASE))->get('/install/')['status'] === 403);

$counts = $R->counts();
file_put_contents($env('QA_OUT'), json_encode(['layout' => 'reinstall', 'base' => $BASE, 'rows' => $R->rows, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, sprintf("\n[reinstall] PASS %d  FAIL %d\n", $counts['PASS'], $counts['FAIL']));
exit($counts['FAIL'] > 0 ? 1 : 0);
