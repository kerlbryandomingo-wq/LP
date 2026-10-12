<?php

declare(strict_types=1);

/**
 * Upgrade QA: a site installed with the PREVIOUS release (and holding data)
 * receives the new release files the way UPGRADE.md describes (upload over
 * the old files, keep config/ and storage/), then an administrator applies
 * the database updates from Admin → Database Updates.
 *
 * Environment: QA_BASE, QA_APP_ROOT, QA_DB_HOST/PORT/NAME/USER/PASS,
 * QA_ADMIN_PASSWORD, QA_NEW_ZIP, QA_OUT. The previous release must already
 * be extracted (not installed) at QA_APP_ROOT and served at QA_BASE.
 */

require __DIR__ . '/lib.php';

$env = fn (string $k, ?string $d = null) => getenv($k) !== false ? (string) getenv($k) : ($d ?? throw new RuntimeException("Missing env {$k}"));
$BASE = rtrim($env('QA_BASE'), '/');
$ROOT = rtrim($env('QA_APP_ROOT'), '/');
$DB = ['host' => $env('QA_DB_HOST', '127.0.0.1'), 'port' => $env('QA_DB_PORT', '3306'), 'name' => $env('QA_DB_NAME'), 'user' => $env('QA_DB_USER'), 'pass' => $env('QA_DB_PASS')];
$PW = $env('QA_ADMIN_PASSWORD');
$R = new Results('upgrade');
$pdo = new PDO("mysql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$one = function (string $sql, array $p = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
};
set_exception_handler(function (Throwable $e) use ($R) {
    $R->check('ABORT', 'Upgrade run aborted', false, $e->getMessage() . ' line ' . $e->getLine());
    file_put_contents(getenv('QA_OUT'), json_encode(['layout' => 'upgrade', 'rows' => $R->rows, 'counts' => $R->counts()], JSON_PRETTY_PRINT));
    exit(1);
});

$R->section('Previous release: install and add data');
$b = new Browser($BASE);
$b->get('/install/?step=1');
$b->post('/install/', ['_step' => '2']);
$b->post('/install/', ['_step' => '3', 'db_host' => $DB['host'], 'db_port' => $DB['port'], 'db_name' => $DB['name'], 'db_user' => $DB['user'], 'db_pass' => $DB['pass']]);
$b->post('/install/', ['_step' => '4']);
$b->post('/install/', ['_step' => '5', 'admin_name' => 'LP System QA Administrator', 'admin_email' => 'qa-admin@example.test', 'admin_password' => $PW, 'admin_password_confirm' => $PW]);
$r = $b->post('/install/', ['_step' => '6', 'business_name' => 'QA Print Shop', 'timezone' => 'Asia/Manila', 'app_url' => '']);
$R->check('G1', 'Previous release installed with its own installer', is_file($ROOT . '/storage/install.lock') && (int) $one('SELECT COUNT(*) FROM users') === 1);
$R->check('G2', 'Previous release has no schema_migrations table', !$one("SHOW TABLES LIKE 'schema_migrations'"));
$old = new Browser($BASE);
$lp = $old->get('/login');
$old->post('/login', ['_csrf' => csrf($lp['body']), 'email' => 'qa-admin@example.test', 'password' => $PW]);
$cf = $old->get('/customers/new');
$old->post('/customers', ['_csrf' => csrf($cf['body']), 'name' => 'QA Upgrade Customer', 'email' => 'qa-upgrade@example.test']);
$R->check('G3', 'Data created in the previous release', (int) $one("SELECT COUNT(*) FROM customers WHERE name = 'QA Upgrade Customer'") === 1);
$cfgBefore = hash_file('sha256', $ROOT . '/config/config.php');

$R->section('Upload new release over the old files (UPGRADE.md)');
exec('unzip -oq ' . escapeshellarg($env('QA_NEW_ZIP')) . ' -d ' . escapeshellarg($ROOT) . ' && chown -R www-data:www-data ' . escapeshellarg($ROOT), $o, $code);
// PHP OPcache re-checks file timestamps every opcache.revalidate_freq
// seconds (default 2); requests inside that window can mix cached old and
// new files. A real upload takes far longer; wait it out here.
sleep(3);
$R->check('G4', 'New files uploaded; config.php untouched', $code === 0 && hash_file('sha256', $ROOT . '/config/config.php') === $cfgBefore);
$r = (new Browser($BASE))->get('/install/');
$R->check('G5', 'Installer stays locked after the upload', $r['status'] === 403);
$a = new Browser($BASE);
$r = $a->get('/dashboard');
$R->check('G6', 'Signed-out visitor is sent to sign-in (no error page)', in_array($r['status'], [302, 303], true) && str_ends_with((string) $r['location'], '/login'));
$lp = $a->get('/login');
$R->check('G7', 'Sign-in page works before the database update', $lp['status'] === 200 && str_contains($lp['body'], 'name="_csrf"'));
$r = $a->post('/login', ['_csrf' => csrf($lp['body']), 'email' => 'qa-admin@example.test', 'password' => $PW]);
$R->check('G8', 'Administrator can sign in before the database update', in_array($r['status'], [302, 303], true));
$r = $a->get('/orders');
$R->check('G9', 'Administrator is directed to Database Updates while updates are pending', in_array($r['status'], [302, 303], true) && str_ends_with((string) $r['location'], '/admin/updates'));
$u = $a->get('/admin/updates');
$R->check('G10', 'Database Updates lists the pending updates', $u['status'] === 200 && str_contains($u['body'], 'migrations/003_security_hardening.sql') && str_contains($u['body'], 'Apply Updates'));
$r = $a->post('/admin/updates', ['_csrf' => csrf($u['body'])]);
$u = $a->follow($r);
$R->check('G11', 'Applying updates succeeds', str_contains(alerts($u['body']), 'Applied') && str_contains($u['body'], 'data-updates="none"'), alerts($u['body']));
$R->check('G12', 'All updates recorded; new tables/columns exist',
    (int) $one('SELECT COUNT(*) FROM schema_migrations') === count(glob($ROOT . '/database/migrations/*.sql')) + count(glob($ROOT . '/database/seeds/*.sql'))
    && (bool) $one("SHOW TABLES LIKE 'login_attempts'") && (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'qc_results' AND column_name = 'seq'") === 1);
$R->check('G13', 'Existing data preserved', (int) $one("SELECT COUNT(*) FROM customers WHERE name = 'QA Upgrade Customer'") === 1 && (int) $one('SELECT COUNT(*) FROM users') === 1 && (int) $one('SELECT COUNT(*) FROM workflow_stages') === 5);
$r = $a->get('/customers');
$R->check('G14', 'Application usable after the update', $r['status'] === 200 && str_contains($r['body'], 'QA Upgrade Customer'));
$cid = (string) $one("SELECT id FROM customers WHERE name = 'QA Upgrade Customer'");
$qf = $a->get('/quotations/new?customer_id=' . $cid);
$a->post('/quotations', ['_csrf' => csrf($qf['body']), '_submission' => input_value($qf['body'], '_submission'), 'customer_id' => $cid, 'valid_until' => '', 'service_id' => [''],
    'description' => ['QA Test Tarpaulin Printing'], 'qty' => ['10'], 'unit' => ['pcs'], 'unit_price' => ['500'], 'discount_type' => 'FIXED', 'discount_value' => '100', 'tax_pct' => '12', 'notes' => '']);
$R->check('G15', 'New-release features work on the upgraded database (quotation total 5488.00)', $one('SELECT total FROM quotations WHERE customer_id = :c', ['c' => $cid]) === '5488.00');
$u = $a->get('/admin/updates');
$r = $a->post('/admin/updates', ['_csrf' => csrf($u['body'])]);
$R->check('G16', 'Applying again is harmless ("already up to date")', str_contains(alerts($a->follow($r)['body']), 'already up to date'));

$counts = $R->counts();
file_put_contents($env('QA_OUT'), json_encode(['layout' => 'upgrade', 'base' => $BASE, 'rows' => $R->rows, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, sprintf("\n[upgrade] PASS %d  FAIL %d\n", $counts['PASS'], $counts['FAIL']));
exit($counts['FAIL'] > 0 ? 1 : 0);
