<?php

declare(strict_types=1);

/**
 * End-to-end QA run against ONE freshly extracted, NOT yet installed copy of
 * the release ZIP served by a real web server, with its own empty,
 * disposable database. Drives the browser installer, then exercises the
 * business workflows, roles and security controls over HTTP, and checks the
 * resulting database state read-only (plus clearly-labelled test setup).
 *
 * Environment:
 *   QA_BASE            e.g. http://127.0.0.1:8912/lpsystem
 *   QA_LAYOUT          label for the report (root / subfolder / docroot-public)
 *   QA_APP_ROOT        filesystem path of the extracted package
 *   QA_DB_HOST/PORT/NAME/USER/PASS   the disposable database
 *   QA_ADMIN_PASSWORD  random admin password for this run (never printed)
 *   QA_OUT             where to write the JSON results
 *
 * Only QA-prefixed, fictitious data is created. Never point this at a real
 * installation.
 */

require __DIR__ . '/lib.php';

$env = fn (string $k, ?string $d = null) => getenv($k) !== false ? (string) getenv($k) : ($d ?? throw new RuntimeException("Missing env {$k}"));
$BASE = rtrim($env('QA_BASE'), '/');
$LAYOUT = $env('QA_LAYOUT');
$ROOT = rtrim($env('QA_APP_ROOT'), '/');
$DB = ['host' => $env('QA_DB_HOST', '127.0.0.1'), 'port' => $env('QA_DB_PORT', '3306'), 'name' => $env('QA_DB_NAME'), 'user' => $env('QA_DB_USER'), 'pass' => $env('QA_DB_PASS')];
$ADMIN_PW = $env('QA_ADMIN_PASSWORD');
$OUT = $env('QA_OUT');
$TMP = sys_get_temp_dir() . '/lpqa-' . bin2hex(random_bytes(4));
mkdir($TMP);
$basePath = (string) (parse_url($BASE, PHP_URL_PATH) ?? '');

$R = new Results($LAYOUT);
$secrets = [$ADMIN_PW, $DB['pass']];

$pdo = new PDO("mysql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$one = function (string $sql, array $p = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
};
$row = function (string $sql, array $p = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetch();
};
$noLeak = function (string $body) use ($secrets): bool {
    foreach ($secrets as $s) {
        if ($s !== '' && str_contains($body, $s)) {
            return false;
        }
    }
    return !preg_match('#(Stack trace|Fatal error|Warning: |Notice: |Deprecated: |PDOException|SQLSTATE|/var/www|\.php on line)#', $body);
};
$is500 = fn (array $r) => $r['status'] >= 500;

$fail = function (Throwable $e) use ($R, $OUT) {
    $R->check('ABORT', 'Run aborted by an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    file_put_contents($OUT, json_encode(['layout' => $R->layout, 'rows' => $R->rows, 'counts' => $R->counts()], JSON_PRETTY_PRINT));
    exit(1);
};
set_exception_handler($fail);

// =====================================================================
$R->section('Test A — first visit');
$b = new Browser($BASE);
$r = $b->get('/');
$R->check('A1', 'Home redirects to the installer when not installed', in_array($r['status'], [302, 303], true) && str_ends_with((string) parse_url((string) $r['location'], PHP_URL_PATH), $basePath . '/install/'), "status {$r['status']} → {$r['location']}");
$r = $b->get('/install/');
$R->check('A2', 'Installer welcome page loads (200)', $r['status'] === 200 && str_contains($r['body'], 'Welcome'), "status {$r['status']}");
$R->check('A3', 'Installer stylesheet URL is correct for this layout', str_contains($r['body'], 'href="' . $basePath . '/assets/css/app.css"'));
$css = $b->get('/assets/css/app.css');
$R->check('A4', 'Stylesheet is served (200, text/css)', $css['status'] === 200 && str_contains(implode(';', $css['headers']['content-type'] ?? []), 'text/css'));
$js = $b->get('/assets/js/app.js');
$R->check('A5', 'Script is served (200)', $js['status'] === 200 && str_contains($js['body'], 'DOMContentLoaded'));
$installPage = $b->get('/install/');
$token = input_value($installPage['body'], '_token');
$R->check('A6', 'Installer form carries a CSRF token', (bool) $token);

// Bypass attempts on a fresh session.
$x = new Browser($BASE);
$x->get('/install/');
$r = $x->post('/install/', ['_step' => '6', 'business_name' => 'X', 'timezone' => 'UTC']);
$R->check('A7', 'Posting the final step without a token is refused (403), no PHP warnings', $r['status'] === 403 && $noLeak($r['body']), "status {$r['status']}");
$tok = input_value($x->get('/install/')['body'], '_token');
$r = $x->post('/install/', ['_token' => $tok, '_step' => '6', 'business_name' => 'X', 'timezone' => 'UTC']);
$R->check('A8', 'Jumping to step 6 with a valid token is redirected back to step 1', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=1'), "status {$r['status']} → {$r['location']}");
$r = $x->get('/install/?step=5');
$R->check('A9', 'GET of an unreached step redirects to the reached step', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=1'));
$r = $x->post('/install/', ['_token' => $tok, '_step' => '2']);
$R->check('A10', 'Skipping the requirements step (POST step 2 before step 1) is refused', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=1'));

// =====================================================================
$R->section('Test B — requirements');
$r = $b->post('/install/', ['_token' => $token, '_step' => '1']);
$r = $b->follow($r);
$R->check('B1', 'Requirements page lists checks', $r['status'] === 200 && str_contains($r['body'], 'Requirements Check'));
$R->check('B2', 'All required checks pass on a correct server', !str_contains($r['body'], 'badge-red') && str_contains($r['body'], 'All required checks passed'), alerts($r['body']));
$R->check('B3', 'URL-rewriting probe reports "working"', (bool) preg_match('#URL rewriting.*?working#s', $r['body']));
// Deliberate failure: make storage/cache unwritable for the web server.
$cache = $ROOT . '/storage/cache';
$origPerm = fileperms($cache) & 0777;
chmod($cache, 0555);
$r = $b->get('/install/?step=2');
$R->check('B4', 'Deliberate failure: unwritable storage/cache/ is reported as FAIL', str_contains($r['body'], 'badge-red') && str_contains($r['body'], 'storage/cache/'));
$r = $b->post('/install/', ['_token' => $token, '_step' => '2']);
$R->check('B5', 'Continuing while a requirement fails is refused on the server (422)', $r['status'] === 422, "status {$r['status']}");
chmod($cache, $origPerm);
$r = $b->post('/install/', ['_token' => $token, '_step' => '2']);
$R->check('B6', 'After fixing permissions the wizard continues to step 3', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=3'));

// =====================================================================
$R->section('Test C — database configuration');
$good = ['_token' => $token, '_step' => '3', 'db_host' => $DB['host'], 'db_port' => $DB['port'], 'db_name' => $DB['name'], 'db_user' => $DB['user'], 'db_pass' => $DB['pass']];
$sentinel = 'WrongPw-' . bin2hex(random_bytes(6));
$r = $b->post('/install/', ['db_pass' => $sentinel] + $good);
$R->check('C1', 'Wrong password: clear message, request refused', $r['status'] === 422 && str_contains($r['body'], 'username or password is incorrect'), alerts($r['body']));
$R->check('C2', 'Wrong password is never echoed back, no raw SQL error', !str_contains($r['body'], $sentinel) && $noLeak($r['body']));
$r = $b->post('/install/', ['db_name' => 'qa_does_not_exist_' . bin2hex(random_bytes(3))] + $good);
$R->check('C3', 'Wrong database name: "does not exist" guidance', $r['status'] === 422 && str_contains($r['body'], 'does not exist'), alerts($r['body']));
$r = $b->post('/install/', ['db_port' => '70000'] + $good);
$R->check('C4', 'Out-of-range port rejected by validation', $r['status'] === 422 && str_contains($r['body'], 'Port must be'));
$r = $b->post('/install/', ['db_host' => "localhost';DROP TABLE x;--"] + $good);
$R->check('C5', 'Malformed host rejected by validation', $r['status'] === 422 && str_contains($r['body'], 'Database host must be'));
$r = $b->post('/install/', ['db_name' => 'a b`c'] + $good);
$R->check('C6', 'Malformed database name rejected', $r['status'] === 422 && str_contains($r['body'], 'Database name may only'));
$r = $b->post('/install/', ['db_host' => '127.0.0.1', 'db_port' => '1'] + $good);
$R->check('C7', 'Unreachable server: plain-language message', $r['status'] === 422 && str_contains($r['body'], 'Could not reach'), alerts($r['body']));
$r = $b->post('/install/', $good);
$R->check('C8', 'Valid credentials advance to step 4', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=4'), "status {$r['status']}");
$r = $b->get('/install/?step=4');
$R->check('C9', 'Step 4 shows the connected database and server version', str_contains($r['body'], $DB['name']) && preg_match('/MariaDB|\d+\.\d+/', $r['body']) === 1);
$R->check('C10', 'No tables created before step 4 is confirmed', (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 0);

// =====================================================================
$R->section('Test D — schema creation');
$r = $b->post('/install/', ['_token' => $token, '_step' => '4']);
$R->check('D1', 'Create Tables succeeds and advances to step 5', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=5'), "status {$r['status']} " . alerts($r['body']));
$migFiles = array_merge(
    array_map(fn ($f) => 'migrations/' . basename($f), glob($ROOT . '/database/migrations/*.sql')),
    array_map(fn ($f) => 'seeds/' . basename($f), glob($ROOT . '/database/seeds/*.sql'))
);
$applied = $pdo->query('SELECT name FROM schema_migrations ORDER BY applied_at, name')->fetchAll(PDO::FETCH_COLUMN);
$R->check('D2', 'Every migration and seed recorded once in schema_migrations (' . count($migFiles) . ')', $applied === $migFiles, implode(',', $applied));
$expectedTables = ['schema_migrations'];
foreach (glob($ROOT . '/database/migrations/*.sql') as $f) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z_]+)/i', file_get_contents($f), $m);
    $expectedTables = array_merge($expectedTables, $m[1]);
}
sort($expectedTables);
$expectedTables = array_values(array_unique($expectedTables));
sort($expectedTables);
$tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
$R->check('D3', 'All ' . count($expectedTables) . ' expected tables exist, no extras', $tables === $expectedTables, implode(',', array_diff($expectedTables, $tables)));
$R->check('D4', 'All tables are InnoDB', (int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'") === 0);
$R->check('D5', 'Money columns are DECIMAL(12,2)', (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ('total','total_amount','amount','unit_price','subtotal') AND column_type <> 'decimal(12,2)'") === 0);
$R->check('D6', 'Seed: default workflow has 5 stages incl. one QC stage', (int) $one("SELECT COUNT(*) FROM workflow_stages WHERE template_id = 'wft_default'") === 5 && (int) $one('SELECT COUNT(*) FROM workflow_stages WHERE is_qc_stage = 1') === 1);
$R->check('D7', 'Release-3 columns present (sort_order, approved_by_id, reviewed_by_id, order_line_item_id, seq)', (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ('sort_order','approved_by_id','reviewed_by_id','order_line_item_id','seq')") === 7);
$R->check('D8', 'No users exist before the administrator step', (int) $one('SELECT COUNT(*) FROM users') === 0);

// =====================================================================
$R->section('Test E — administrator account');
$adminEmail = 'qa-admin@example.test';
$adminForm = ['_token' => $token, '_step' => '5', 'admin_name' => 'LP System QA Administrator', 'admin_email' => $adminEmail];
$r = $b->post('/install/', $adminForm + ['admin_password' => 'short1', 'admin_password_confirm' => 'short1']);
$R->check('E1', 'Too-short password rejected', $r['status'] === 422 && str_contains($r['body'], 'at least 8 characters'));
$r = $b->post('/install/', $adminForm + ['admin_password' => 'allletterspassword', 'admin_password_confirm' => 'allletterspassword']);
$R->check('E2', 'Password without a number rejected', $r['status'] === 422 && str_contains($r['body'], 'letter and one number'));
$r = $b->post('/install/', $adminForm + ['admin_password' => $ADMIN_PW, 'admin_password_confirm' => $ADMIN_PW . 'x']);
$R->check('E3', 'Mismatched confirmation rejected', $r['status'] === 422 && str_contains($r['body'], 'do not match'));
$r = $b->post('/install/', ['admin_email' => 'not-an-email'] + $adminForm + ['admin_password' => $ADMIN_PW, 'admin_password_confirm' => $ADMIN_PW]);
$R->check('E4', 'Invalid email rejected', $r['status'] === 422 && str_contains($r['body'], 'valid email'));
$R->check('E5', 'Typed password not echoed into the form after an error', !str_contains($r['body'], $ADMIN_PW));
$r = $b->post('/install/', $adminForm + ['admin_password' => $ADMIN_PW, 'admin_password_confirm' => $ADMIN_PW]);
$R->check('E6', 'Valid administrator details advance to step 6', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'step=6'));
$R->check('E7', 'Administrator not written to the database until Finish', (int) $one('SELECT COUNT(*) FROM users') === 0);

// =====================================================================
$R->section('Test F — completion and lock');
$fin = ['_token' => $token, '_step' => '6', 'business_name' => 'QA Print Shop', 'app_url' => '', 'timezone' => 'Asia/Manila'];
$r = $b->post('/install/', ['timezone' => 'Mars/Olympus'] + $fin);
$R->check('F1', 'Invalid timezone rejected', $r['status'] === 422 && str_contains($r['body'], 'valid timezone'));
$r = $b->post('/install/', ['app_url' => 'javascript:alert(1)'] + $fin);
$R->check('F2', 'Non-http site URL rejected', $r['status'] === 422 && str_contains($r['body'], 'must start with http'));
$r = $b->post('/install/', $fin);
$R->check('F3', 'Finish shows the completion page', $r['status'] === 200 && str_contains($r['body'], 'data-install-complete'), "status {$r['status']} " . alerts($r['body']));
$R->check('F4', 'Completion page links to sign-in under this layout\'s path', str_contains($r['body'], 'href="' . $basePath . '/login"'));
$R->check('F5', 'config/config.php and storage/install.lock created', is_file($ROOT . '/config/config.php') && is_file($ROOT . '/storage/install.lock'));
$cfgText = (string) file_get_contents($ROOT . '/config/config.php');
$R->check('F6', 'config.php holds no administrator password', !str_contains($cfgText, $ADMIN_PW) && str_contains($cfgText, "'installed' => true"));
$R->check('F7', 'No leftover temporary config files', !glob($ROOT . '/config/.config.*'));
$admin = $row('SELECT * FROM users WHERE email = :e', ['e' => $adminEmail]);
$R->check('F8', 'Administrator saved: name, ADMIN role, active', $admin && $admin['name'] === 'LP System QA Administrator' && $admin['role'] === 'ADMIN' && (int) $admin['active'] === 1);
$R->check('F9', 'Password stored as a bcrypt hash, never plain text', $admin && str_starts_with($admin['password_hash'], '$2y$') && password_verify($ADMIN_PW, $admin['password_hash']));
$plainHits = (int) $one('SELECT COUNT(*) FROM audit_log WHERE metadata LIKE :p', ['p' => '%' . $ADMIN_PW . '%']) + (int) $one('SELECT COUNT(*) FROM users WHERE password_hash = :p', ['p' => $ADMIN_PW]);
$R->check('F10', 'Admin password not present anywhere in users/audit tables', $plainHits === 0);
$R->check('F11', 'Business name saved', $one("SELECT business_name FROM business_settings WHERE id='default'") === 'QA Print Shop');
foreach (['GET /install/' => fn () => $b->get('/install/'), 'GET /install/?step=3' => fn () => $b->get('/install/?step=3'),
          'POST /install/ step 3' => fn () => $b->post('/install/', $good), 'POST step 6 (new browser)' => fn () => (new Browser($BASE))->post('/install/', $fin)] as $label => $fn) {
    $r = $fn();
    $R->check('F12', "Installer locked after install: {$label} → 403", $r['status'] === 403 && str_contains($r['body'], 'already installed'), "status {$r['status']}");
}
$R->check('F13', 'Still exactly one administrator after lock bypass attempts', (int) $one("SELECT COUNT(*) FROM users WHERE role='ADMIN'") === 1);
// Removing only the lock file must not reopen the installer (config says installed).
rename($ROOT . '/storage/install.lock', $ROOT . '/storage/install.lock.qa');
$r = (new Browser($BASE))->get('/install/');
$R->check('F14', 'Deleting only install.lock does not reopen the installer', $r['status'] === 403, "status {$r['status']}");
rename($ROOT . '/storage/install.lock.qa', $ROOT . '/storage/install.lock');

// =====================================================================
$R->section('Authentication and sessions');
$admin = new Browser($BASE);
$r = $admin->get('/dashboard');
$firstCookie = implode("\n", $r['headers']['set-cookie'] ?? []);
$R->check('L1', 'Protected page redirects to /login when signed out', in_array($r['status'], [302, 303], true) && str_ends_with((string) $r['location'], $basePath . '/login'));
$lp = $admin->get('/login');
$R->check('L2', 'Login page renders with assets under the right path', $lp['status'] === 200 && str_contains($lp['body'], $basePath . '/assets/css/app.css'));
$preSession = $admin->cookieValue('lp_session');
$setCookie = $firstCookie . "\n" . implode("\n", $lp['headers']['set-cookie'] ?? []);
$R->check('L3', 'Session cookie: HttpOnly, SameSite=Lax, Path=' . $basePath . '/', str_contains($setCookie, 'HttpOnly') && stripos($setCookie, 'SameSite=Lax') !== false && str_contains($setCookie, 'path=' . $basePath . '/'), $setCookie);
$r = $admin->post('/login', ['_csrf' => csrf($lp['body']), 'email' => $adminEmail, 'password' => 'wrong-password-1']);
$R->check('L4', 'Wrong password: 401 with a generic message', $r['status'] === 401 && str_contains($r['body'], 'Invalid email or password'));
$r = $admin->post('/login', ['email' => $adminEmail, 'password' => $ADMIN_PW]);
$R->check('L5', 'Login without CSRF token refused (403)', $r['status'] === 403);
$lp = $admin->get('/login');
$r = $admin->post('/login', ['_csrf' => csrf($lp['body']), 'email' => strtoupper($adminEmail), 'password' => $ADMIN_PW]);
$R->check('L6', 'Correct login (email case-insensitive) redirects to dashboard', in_array($r['status'], [302, 303], true) && str_ends_with((string) $r['location'], $basePath . '/dashboard'));
$R->check('L7', 'Session ID changes at login (session fixation protection)', $preSession !== null && $admin->cookieValue('lp_session') !== $preSession);
$dash = $admin->get('/dashboard');
$R->check('L8', 'Dashboard loads for the administrator', $dash['status'] === 200 && str_contains($dash['body'], 'LP System QA Administrator') && str_contains($dash['body'], 'data-kpi="outstanding"'));
$R->check('L9', 'Navigation links use this layout\'s base path', str_contains($dash['body'], 'href="' . $basePath . '/customers"') && !preg_match('#href="/(customers|orders)"#', $basePath === '' ? '' : $dash['body']));
$R->check('L10', 'Security headers: CSP, X-Frame-Options, nosniff; no X-Powered-By', isset($dash['headers']['content-security-policy'], $dash['headers']['x-frame-options'], $dash['headers']['x-content-type-options']) && !isset($dash['headers']['x-powered-by']));
$R->check('L11', 'Admin-only menu items visible to admin (Users, Database Updates)', str_contains($dash['body'], '/admin/users') && str_contains($dash['body'], '/admin/updates'));
$up = $admin->get('/admin/updates');
$R->check('L12', 'Database Updates page: up to date after install', $up['status'] === 200 && str_contains($up['body'], 'data-updates="none"'));
$r = $admin->get('/logout');
$R->check('L13', 'GET /logout does not log out (404; logout is POST+CSRF only)', $r['status'] === 404 && $admin->get('/dashboard')['status'] === 200);

// Throttling with a fresh cookie jar per attempt (can't be reset by dropping cookies).
$victim = 'qa-throttle-' . bin2hex(random_bytes(3)) . '@example.test';
$statuses = [];
for ($i = 0; $i < 6; $i++) {
    $t = new Browser($BASE);
    $p = $t->get('/login');
    $statuses[] = $t->post('/login', ['_csrf' => csrf($p['body']), 'email' => $victim, 'password' => 'nope-' . $i])['body'];
}
$R->check('L14', 'Login throttled after 5 failures even with new cookies each time', str_contains(end($statuses), 'Too many failed attempts') && !str_contains($statuses[3], 'Too many'));
$R->check('L15', 'Throttle is stored server-side (login_attempts rows)', (int) $one('SELECT COUNT(*) FROM login_attempts WHERE email = :e', ['e' => $victim]) >= 5);

// =====================================================================
$R->section('User management and roles');
$users = $admin->get('/admin/users');
$mk = function (string $name, string $email, string $role) use ($admin, $users) {
    $page = $admin->get('/admin/users');
    $r = $admin->post('/admin/users', ['_csrf' => csrf($page['body']), '_submission' => input_value($page['body'], '_submission'), 'name' => $name, 'email' => $email, 'role' => $role]);
    $page = $admin->follow($r);
    preg_match('#<code class="temp-password">([^<]+)</code>#', $page['body'], $m);
    return $m[1] ?? null;
};
$staffTemp = $mk('QA Staff User', 'qa-staff@example.test', 'STAFF');
$prodTemp = $mk('QA Production User', 'qa-production@example.test', 'PRODUCTION');
$R->check('U1', 'Admin creates STAFF and PRODUCTION accounts; one-time temporary passwords shown', $staffTemp && $prodTemp);
$R->check('U2', 'New accounts must change password at first sign-in', (int) $one("SELECT COUNT(*) FROM users WHERE email IN ('qa-staff@example.test','qa-production@example.test') AND must_change_password = 1") === 2);
$page = $admin->get('/admin/users');
$r = $admin->post('/admin/users', ['_csrf' => csrf($page['body']), '_submission' => input_value($page['body'], '_submission'), 'name' => 'Dup', 'email' => 'qa-staff@example.test', 'role' => 'STAFF']);
$R->check('U3', 'Duplicate email rejected', $r['status'] === 422 && str_contains($r['body'], 'already exists'));
$secrets[] = $staffTemp;
$secrets[] = $prodTemp;

$signin = function (string $email, string $pw) use ($BASE): Browser {
    $c = new Browser($BASE);
    $p = $c->get('/login');
    $c->post('/login', ['_csrf' => csrf($p['body']), 'email' => $email, 'password' => $pw]);
    return $c;
};
$staffPw = rand_password();
$prodPw = rand_password();
$secrets[] = $staffPw;
$secrets[] = $prodPw;
$staff = $signin('qa-staff@example.test', (string) $staffTemp);
$r = $staff->get('/orders');
$R->check('U4', 'Temporary-password user is forced to the change-password page', in_array($r['status'], [302, 303], true) && str_ends_with((string) $r['location'], '/account/password'));
$changePw = function (Browser $c, string $old, string $new): array {
    $p = $c->get('/account/password');
    return $c->post('/account/password', ['_csrf' => csrf($p['body']), 'current_password' => $old, 'new_password' => $new, 'new_password_confirm' => $new]);
};
$r = $changePw($staff, 'wrong-current-1', $staffPw);
$R->check('U5', 'Change password requires the correct current password', $r['status'] === 422);
$r = $changePw($staff, (string) $staffTemp, $staffPw);
$R->check('U6', 'Staff sets own password and continues', in_array($r['status'], [302, 303], true) && $staff->get('/orders')['status'] === 200);
$prod = $signin('qa-production@example.test', (string) $prodTemp);
$changePw($prod, (string) $prodTemp, $prodPw);
$R->check('U7', 'Production user sets own password', $prod->get('/job-orders')['status'] === 200);
$pd = $prod->follow($prod->get('/dashboard'));
$R->check('U8', 'Production dashboard → production list; no finance links in menu', str_contains($pd['url'], '/job-orders') && !str_contains($pd['body'], 'href="' . $basePath . '/payments"') && !str_contains($pd['body'], 'href="' . $basePath . '/orders"'));
foreach (['/orders', '/payments', '/customers', '/quotations', '/inquiries', '/admin/users', '/orders/export', '/inventory/new'] as $p) {
    $s = $prod->get($p)['status'];
    $R->check('U9', "PRODUCTION denied {$p} (403)", $s === 403, "status {$s}");
}
foreach (['/admin/users', '/admin/updates'] as $p) {
    $s = $staff->get($p)['status'];
    $R->check('U10', "STAFF denied {$p} (403)", $s === 403, "status {$s}");
}
$ua = $admin->get('/admin/users');
$adminId = (string) $one('SELECT id FROM users WHERE email = :e', ['e' => $adminEmail]);
$R->check('U11', 'Admin cannot change own role or deactivate self (no controls rendered for own row)', !str_contains($ua['body'], 'action="' . $basePath . '/admin/users/' . $adminId . '"'));
$r = $admin->post('/admin/users/' . $adminId, ['_csrf' => csrf($ua['body']), 'role' => 'STAFF', 'active' => '0']);
$R->check('U12', 'Server refuses self-demotion even if posted directly', $one('SELECT role FROM users WHERE id = :i', ['i' => $adminId]) === 'ADMIN' && str_contains(alerts($admin->follow($r)['body']), 'cannot change your own role'));
// Test setup (documented): a CUSTOMER-role row, created the way the original app's
// customer self-registration would; this edition has no portal, so the account must be blocked.
$custPw = rand_password();
$pdo->prepare("INSERT INTO users (id, name, email, password_hash, role, active) VALUES ('usr_qacustomer', 'QA Customer Account', 'qa-customer-login@example.test', :h, 'CUSTOMER', 1)")
    ->execute(['h' => password_hash($custPw, PASSWORD_BCRYPT)]);
$c = new Browser($BASE);
$p = $c->get('/login');
$r = $c->post('/login', ['_csrf' => csrf($p['body']), 'email' => 'qa-customer-login@example.test', 'password' => $custPw]);
$R->check('U13', 'CUSTOMER-role account cannot sign in (portal not in this edition)', $r['status'] === 401 && str_contains($r['body'], 'Customer accounts cannot sign in') && $c->get('/dashboard')['status'] !== 200);
// Deactivation ends an existing session on the next request.
$tmpUserTemp = $mk('QA Temp Staff', 'qa-temp@example.test', 'STAFF');
$tmpPw = rand_password();
$tmpB = $signin('qa-temp@example.test', (string) $tmpUserTemp);
$changePw($tmpB, (string) $tmpUserTemp, $tmpPw);
$tmpId = (string) $one("SELECT id FROM users WHERE email = 'qa-temp@example.test'");
$ua = $admin->get('/admin/users');
$admin->post('/admin/users/' . $tmpId, ['_csrf' => csrf($ua['body']), 'role' => 'STAFF', 'active' => '0']);
$s = $tmpB->get('/orders');
$R->check('U14', 'Deactivated user is signed out on their next request', in_array($s['status'], [302, 303], true) && str_ends_with((string) $s['location'], '/login'));

// =====================================================================
$R->section('Customers');
$form = $staff->get('/customers/new');
$sub = input_value($form['body'], '_submission');
$cust = ['_csrf' => csrf($form['body']), '_submission' => $sub, 'name' => 'QA Demo Printing Customer', 'company_name' => 'QA Test Contact',
    'email' => 'qa-customer@example.test', 'contact_number' => '09990000000', 'address' => 'QA Test Address', 'facebook_url' => ''];
$r = $staff->post('/customers', ['email' => 'not-an-email'] + $cust);
$R->check('C-1', 'Customer validation: bad email rejected, form kept', $r['status'] === 422 && str_contains($r['body'], 'not a valid email') && str_contains($r['body'], 'QA Demo Printing Customer'));
$r = $staff->post('/customers', $cust);
$R->check('C-2', 'Customer created', in_array($r['status'], [302, 303], true));
$r2 = $staff->post('/customers', $cust);
$R->check('C-3', 'Double-submitting the same form does not create a second customer', (int) $one("SELECT COUNT(*) FROM customers WHERE name = 'QA Demo Printing Customer'") === 1 && str_contains(alerts($staff->follow($r2)['body']), 'already submitted'));
$customerId = (string) $one("SELECT id FROM customers WHERE name = 'QA Demo Printing Customer'");
$R->check('C-4', 'Customer number assigned from the sequence (CUS-YYYY-0001)', $one('SELECT display_id FROM customers WHERE id = :i', ['i' => $customerId]) === 'CUS-' . date('Y') . '-0001');
$e = $staff->get('/customers/' . $customerId . '/edit');
$r = $staff->post('/customers/' . $customerId, ['_csrf' => csrf($e['body']), 'name' => 'QA Demo Printing Customer', 'company_name' => 'QA Test Contact', 'email' => 'qa-customer@example.test',
    'contact_number' => '09990000000', 'address' => 'QA Test Address 2', 'facebook_url' => '', 'is_qualified_for_terms' => '1']);
$R->check('C-5', 'Customer edited; STAFF cannot grant payment terms (admin-only field ignored)', $one('SELECT address FROM customers WHERE id = :i', ['i' => $customerId]) === 'QA Test Address 2' && (int) $one('SELECT is_qualified_for_terms FROM customers WHERE id = :i', ['i' => $customerId]) === 0);
$s = $staff->get('/customers?q=' . urlencode('QA Demo'));
$R->check('C-6', 'Customer search finds the record', str_contains($s['body'], 'QA Demo Printing Customer'));
$s = $staff->get('/customers?q=' . urlencode('%'));
$R->check('C-7', 'Search wildcard characters are treated literally ("%" matches nothing)', str_contains($s['body'], 'No customers match'));

// =====================================================================
$R->section('Inquiry → Quotation');
$f = $staff->get('/inquiries/new?customer_id=' . $customerId);
$R->check('Q1', 'Inquiry form preselects the customer', str_contains($f['body'], 'value="' . $customerId . '" selected'));
$inq = ['_csrf' => csrf($f['body']), '_submission' => input_value($f['body'], '_submission'), 'customer_id' => $customerId,
    'desired_product' => 'QA Test Tarpaulin Printing', 'rough_qty' => '10', 'rough_qty_unit' => 'pcs', 'description' => 'QA test inquiry — 10 tarpaulins.'];
$r = $staff->post('/inquiries', ['rough_qty' => '-3'] + $inq);
$R->check('Q2', 'Negative quantity rejected', $r['status'] === 422);
$r = $staff->post('/inquiries', $inq);
$inquiryId = (string) $one("SELECT id FROM inquiries WHERE desired_product = 'QA Test Tarpaulin Printing'");
$R->check('Q3', 'Inquiry created (status NEW)', $inquiryId !== '' && $one('SELECT status FROM inquiries WHERE id = :i', ['i' => $inquiryId]) === 'NEW');
$ip = $staff->get('/inquiries/' . $inquiryId);
$r = $staff->post('/inquiries/' . $inquiryId . '/convert', ['_csrf' => csrf($ip['body'])]);
$R->check('Q4', 'Convert to Quotation opens a prefilled quotation form', str_contains((string) $r['location'], '/quotations/new?inquiry_id=' . $inquiryId));
$qf = $staff->follow($r);
$R->check('Q5', 'Quotation form prefilled from inquiry (product, qty 10)', str_contains($qf['body'], 'value="QA Test Tarpaulin Printing"') && str_contains($qf['body'], 'name="qty[]" aria-label="Quantity" min="1" step="1" value="10"'));
$R->check('Q6', 'Inquiry stays NEW until the quotation is actually saved', $one('SELECT status FROM inquiries WHERE id = :i', ['i' => $inquiryId]) === 'NEW');
$q = ['_csrf' => csrf($qf['body']), '_submission' => input_value($qf['body'], '_submission'), 'inquiry_id' => $inquiryId, 'customer_id' => $customerId, 'valid_until' => date('Y-m-d', strtotime('+14 days')),
    'service_id' => [''], 'description' => ['QA Test Tarpaulin Printing'], 'qty' => ['10'], 'unit' => ['pcs'], 'unit_price' => ['500'],
    'discount_type' => 'FIXED', 'discount_value' => '100', 'tax_pct' => '12', 'notes' => 'QA test quotation'];
$bad = function (array $over) use ($staff, $q) {
    return $staff->post('/quotations', array_replace($q, $over));
};
$R->check('Q7', 'Quantity 0 rejected', $bad(['qty' => ['0']])['status'] === 422);
$R->check('Q8', 'Non-numeric price rejected', $bad(['unit_price' => ['abc']])['status'] === 422);
$R->check('Q9', 'Fixed discount larger than subtotal rejected', $bad(['discount_value' => '6000'])['status'] === 422);
$R->check('Q10', 'Tax above 100% rejected', $bad(['tax_pct' => '150'])['status'] === 422);
$R->check('Q11', 'No line items rejected', $bad(['description' => [''], 'unit_price' => ['']])['status'] === 422);
$R->check('Q12', 'Rejected submissions created nothing', (int) $one('SELECT COUNT(*) FROM quotations') === 0);
$r = $staff->post('/quotations', $q);
$quote = $row('SELECT * FROM quotations WHERE inquiry_id = :i', ['i' => $inquiryId]);
$R->check('Q13', 'Quotation created as DRAFT with number QUO-YYYY-0001', $quote && $quote['status'] === 'DRAFT' && $quote['quote_number'] === 'QUO-' . date('Y') . '-0001');
$R->check('Q14', 'Totals: subtotal 5000.00, fixed discount 100.00, tax 12% = 588.00, total 5488.00',
    $quote && $quote['subtotal'] === '5000.00' && $quote['discount_amount'] === '100.00' && $quote['tax_amount'] === '588.00' && $quote['total'] === '5488.00',
    $quote ? "{$quote['subtotal']} / {$quote['discount_amount']} / {$quote['tax_amount']} / {$quote['total']}" : 'none');
$R->check('Q15', 'Inquiry marked QUOTED', $one('SELECT status FROM inquiries WHERE id = :i', ['i' => $inquiryId]) === 'QUOTED');
$r = $staff->post('/quotations', $q);
$R->check('Q16', 'Re-submitting the quotation form does not create a duplicate', (int) $one('SELECT COUNT(*) FROM quotations') === 1);
$qid = $quote['id'];
$qs = $staff->get('/quotations/' . $qid);
$R->check('Q17', 'Quotation page shows ₱5,488.00 grand total', str_contains($qs['body'], '₱5,488.00') && str_contains($qs['body'], '-₱100.00') && str_contains($qs['body'], '₱588.00'));
$qe = $staff->get('/quotations/' . $qid . '/edit');
$edit = array_replace($q, ['_csrf' => csrf($qe['body']), 'notes' => 'QA test quotation (edited)']);
unset($edit['_submission'], $edit['inquiry_id']);
$r = $staff->post('/quotations/' . $qid, $edit);
$R->check('Q18', 'Draft quotation edited; totals unchanged', $one('SELECT notes FROM quotations WHERE id = :i', ['i' => $qid]) === 'QA test quotation (edited)' && $one('SELECT total FROM quotations WHERE id = :i', ['i' => $qid]) === '5488.00');
$qs = $staff->get('/quotations/' . $qid);
$staff->post('/quotations/' . $qid . '/send', ['_csrf' => csrf($qs['body'])]);
$R->check('Q19', 'Quotation marked SENT', $one('SELECT status FROM quotations WHERE id = :i', ['i' => $qid]) === 'SENT');
$r = $prod->post('/quotations/' . $qid . '/approve', ['_csrf' => csrf($prod->get('/job-orders')['body']), 'reason' => 'x x x']);
$R->check('Q20', 'PRODUCTION cannot approve quotations (403)', $r['status'] === 403);
$r = $staff->post('/quotations/' . $qid . '/approve', ['_csrf' => csrf($qs['body']), 'reason' => '']);
$R->check('Q21', 'Approval without a reason refused', $one('SELECT status FROM quotations WHERE id = :i', ['i' => $qid]) === 'SENT');
$r = $staff->post('/quotations/' . $qid . '/approve', ['_csrf' => csrf($qs['body']), 'reason' => 'QA: customer approved by phone']);
$R->check('Q22', 'Approval creates the order and opens it', in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], '/orders/'));
$r2 = $staff->post('/quotations/' . $qid . '/approve', ['_csrf' => csrf($qs['body']), 'reason' => 'QA: double click']);
$r3 = $staff->post('/quotations/' . $qid . '/convert', ['_csrf' => csrf($qs['body'])]);
$R->check('Q23', 'Double approval / repeat convert never creates a second order', (int) $one('SELECT COUNT(*) FROM orders WHERE quotation_id = :i', ['i' => $qid]) === 1 && str_contains(alerts($staff->follow($r3)['body']), 'already has order'));
$r = $staff->post('/quotations/' . $qid . '/reject', ['_csrf' => csrf($qs['body']), 'reason' => 'QA: should not apply']);
$R->check('Q24', 'An approved quotation can no longer be rejected', $one('SELECT status FROM quotations WHERE id = :i', ['i' => $qid]) === 'APPROVED');
$R->check('Q25', 'Approval recorded with staff member and reason', $one('SELECT approval_reason FROM quotations WHERE id = :i', ['i' => $qid]) === 'QA: customer approved by phone' && $one('SELECT approved_by_id FROM quotations WHERE id = :i', ['i' => $qid]) !== null);
$f2 = $staff->get('/quotations/new?inquiry_id=' . $inquiryId);
$R->check('Q26', 'A QUOTED inquiry cannot seed a second quotation form', !str_contains($f2['body'], 'name="inquiry_id"'));

// =====================================================================
$R->section('Order and payments');
$order = $row('SELECT * FROM orders WHERE quotation_id = :i', ['i' => $qid]);
$oid = $order['id'];
$R->check('O1', 'Order copies the quotation breakdown exactly', $order['subtotal'] === '5000.00' && $order['discount_amount'] === '100.00' && $order['tax_amount'] === '588.00' && $order['total_amount'] === '5488.00' && $order['discount_type'] === 'FIXED');
$R->check('O2', 'Order number ORD-YYYY-0001, status OPEN, standard 50% partial terms', $order['order_number'] === 'ORD-' . date('Y') . '-0001' && $order['status'] === 'OPEN' && $order['payment_term_type'] === 'STANDARD_PARTIAL' && (int) $order['required_partial_pct'] === 50);
$R->check('O3', 'Line items copied to the order', (int) $one('SELECT COUNT(*) FROM order_line_items WHERE order_id = :o', ['o' => $oid]) === 1);
$R->check('O4', 'No job order yet — production waits for the partial payment', (int) $one('SELECT COUNT(*) FROM job_orders WHERE order_id = :o', ['o' => $oid]) === 0);
$op = $staff->get('/orders/' . $oid);
$R->check('O5', 'Order page: balance ₱5,488.00 and Record Payment available', str_contains($op['body'], '₱5,488.00') && str_contains($op['body'], 'record-payment-modal'));
$png = tiny_png($TMP . '/qa-proof.png');
$payForm = fn (array $page, array $over = []) => array_replace([
    '_csrf' => csrf($page['body']), '_submission' => input_value($page['body'], '_submission'), 'amount' => '1000.00', 'payment_date' => date('Y-m-d'),
    'method' => 'BANK_TRANSFER', 'reference_number' => 'QA-PAYMENT-001', 'notes' => 'QA payment',
], $over);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op, ['amount' => '6000']));
$R->check('P1', 'Payment above the outstanding balance refused', str_contains(alerts($staff->follow($r)['body']), 'exceeds the remaining balance'));
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op, ['payment_date' => date('Y-m-d', strtotime('+3 days'))]));
$R->check('P2', 'Future payment date refused', str_contains(alerts($staff->follow($r)['body']), 'cannot be in the future'));
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op, ['reference_number' => '']));
$R->check('P3', 'Non-cash payment without a reference refused', str_contains(alerts($staff->follow($r)['body']), 'reference number'));
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op, ['amount' => '-5']));
$R->check('P4', 'Negative amount refused', (int) $one('SELECT COUNT(*) FROM payments') === 0);
// Invalid uploads.
file_put_contents($TMP . '/evil.png', "<?php echo 'pwned'; ?>");
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op) + ['proof' => new CURLFile($TMP . '/evil.png', 'image/png', 'evil.png')], true);
$R->check('P5', 'PHP disguised as .png rejected by content check', str_contains(alerts($staff->follow($r)['body']), 'Unsupported file type') && (int) $one('SELECT COUNT(*) FROM payments') === 0);
$R->check('P6', 'Rejected upload left no file behind', count(glob($ROOT . '/storage/private-uploads/payment/*') ?: []) === 0);
$big = $TMP . '/big.pdf';
file_put_contents($big, "%PDF-1.4\n" . str_repeat('A', 9 * 1024 * 1024));
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op) + ['proof' => new CURLFile($big, 'application/pdf', 'big.pdf')], true);
$bigMsg = alerts($staff->follow($r)['body']) . ' ' . $r['body'];
$R->check('P7', 'Over-size (9 MB) proof rejected with a clear message', (int) $one('SELECT COUNT(*) FROM payments') === 0 && (str_contains($bigMsg, 'too large') || str_contains($bigMsg, 'larger than this server accepts')), "status {$r['status']}");
// Valid payment with proof.
$op = $staff->get('/orders/' . $oid);
$fields = $payForm($op) + ['proof' => new CURLFile($png, 'image/png', 'qa-proof.png')];
$r = $staff->post('/orders/' . $oid . '/payments', $fields, true);
$R->check('P8', 'Payment QA-PAYMENT-001 (₱1,000.00, PNG proof) recorded as PENDING', (int) $one("SELECT COUNT(*) FROM payments WHERE reference_number = 'QA-PAYMENT-001' AND status = 'PENDING' AND amount = 1000.00") === 1);
$fields['proof'] = new CURLFile($png, 'image/png', 'qa-proof.png');
$r = $staff->post('/orders/' . $oid . '/payments', $fields, true);
$R->check('P9', 'Duplicate submission of the same payment form is ignored', (int) $one("SELECT COUNT(*) FROM payments WHERE reference_number = 'QA-PAYMENT-001'") === 1 && str_contains(alerts($staff->follow($r)['body']), 'already submitted'));
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op, ['reference_number' => 'qa-payment-001']));
$R->check('P10', 'Same reference on a new form refused as a duplicate', (int) $one("SELECT COUNT(*) FROM payments WHERE LOWER(reference_number) = 'qa-payment-001'") === 1 && str_contains(alerts($staff->follow($r)['body']), 'already recorded'));
$R->check('P11', 'Only one stored proof file for the payment', count(glob($ROOT . '/storage/private-uploads/payment/*') ?: []) === 1);
$pay1 = $row("SELECT * FROM payments WHERE reference_number = 'QA-PAYMENT-001'");
$op = $staff->get('/orders/' . $oid);
$R->check('P12', 'Proof link shown on the order page', str_contains($op['body'], $basePath . '/files/' . $pay1['proof_file_path']));
$dl = $staff->get('/files/' . $pay1['proof_file_path']);
$R->check('P13', 'Staff can open the proof (image/png, nosniff, sandbox CSP)', $dl['status'] === 200 && ($dl['headers']['content-type'][0] ?? '') === 'image/png' && isset($dl['headers']['x-content-type-options']) && str_contains($dl['headers']['content-security-policy'][0] ?? '', 'sandbox'));
$R->check('P14', 'PRODUCTION cannot open payment proofs (404)', $prod->get('/files/' . $pay1['proof_file_path'])['status'] === 404);
$R->check('P15', 'Signed-out visitor cannot open payment proofs', in_array((new Browser($BASE))->get('/files/' . $pay1['proof_file_path'])['status'], [302, 303], true));
$R->check('P16', 'Uploaded file not reachable by any public URL', (new Browser($BASE))->get('/storage/private-uploads/payment/' . basename((string) (glob($ROOT . '/storage/private-uploads/payment/*')[0] ?? 'x')))['status'] !== 200);
$r = $prod->post('/payments/' . $pay1['id'] . '/confirm', ['_csrf' => csrf($prod->get('/job-orders')['body'])]);
$R->check('P17', 'PRODUCTION cannot confirm payments (403)', $r['status'] === 403 && $one('SELECT status FROM payments WHERE id = :i', ['i' => $pay1['id']]) === 'PENDING');
$r = $staff->post('/payments/' . $pay1['id'] . '/confirm', ['_csrf' => 'forged']);
$R->check('P18', 'Confirm with a forged CSRF token refused (403)', $r['status'] === 403 && $one('SELECT status FROM payments WHERE id = :i', ['i' => $pay1['id']]) === 'PENDING');
$op = $staff->get('/orders/' . $oid);
$staff->post('/payments/' . $pay1['id'] . '/confirm', ['_csrf' => csrf($op['body'])]);
$r = $staff->post('/payments/' . $pay1['id'] . '/confirm', ['_csrf' => csrf($op['body'])]);
$R->check('P19', 'Payment confirmed once; second confirm reports "already confirmed"', $one('SELECT status FROM payments WHERE id = :i', ['i' => $pay1['id']]) === 'CONFIRMED' && str_contains(alerts($staff->follow($r)['body']), 'already confirmed'));
$R->check('P20', 'Reviewer recorded on the payment; audit log written', $one('SELECT reviewed_by_id FROM payments WHERE id = :i', ['i' => $pay1['id']]) !== null && (int) $one("SELECT COUNT(*) FROM audit_log WHERE action = 'PAYMENT_CONFIRMED' AND entity_id = :i", ['i' => $pay1['id']]) === 1);
$R->check('P21', 'Below the 50% partial (₱2,744.00): still no job order', (int) $one('SELECT COUNT(*) FROM job_orders WHERE order_id = :o', ['o' => $oid]) === 0);
// Rejected payment path.
$op = $staff->get('/orders/' . $oid);
$staff->post('/orders/' . $oid . '/payments', $payForm($op, ['amount' => '50', 'reference_number' => 'QA-PAYMENT-REJECT', 'method' => 'GCASH']));
$rej = (string) $one("SELECT id FROM payments WHERE reference_number = 'QA-PAYMENT-REJECT'");
$op = $staff->get('/orders/' . $oid);
$staff->post('/payments/' . $rej . '/reject', ['_csrf' => csrf($op['body']), 'reason' => 'QA: amount not received']);
$R->check('P22', 'Payment rejected with a reason; not counted in the balance', $one('SELECT status FROM payments WHERE id = :i', ['i' => $rej]) === 'REJECTED' && $one('SELECT reject_reason FROM payments WHERE id = :i', ['i' => $rej]) === 'QA: amount not received');
$op = $staff->get('/orders/' . $oid);
$staff->post('/orders/' . $oid . '/payments', $payForm($op, ['amount' => '1744.00', 'reference_number' => 'QA-PAYMENT-002', 'method' => 'CASH']));
$p2 = (string) $one("SELECT id FROM payments WHERE reference_number = 'QA-PAYMENT-002'");
$op = $staff->get('/orders/' . $oid);
$r = $staff->post('/payments/' . $p2 . '/confirm', ['_csrf' => csrf($op['body'])]);
$jo = $row('SELECT * FROM job_orders WHERE order_id = :o', ['o' => $oid]);
$R->check('P23', 'Partial reached (₱2,744.00 confirmed): job order created automatically, ON_HOLD', $jo && $jo['status'] === 'ON_HOLD' && (int) $jo['quantity'] === 10 && str_contains(alerts($staff->follow($r)['body']), 'job order'));
$op = $staff->get('/orders/' . $oid);
$R->check('P24', 'Order page balance now ₱2,744.00', (bool) preg_match('#data-total="balance">₱2,744\.00#', $op['body']));
$r = $staff->post('/orders/' . $oid . '/job-orders', ['_csrf' => csrf($op['body']), '_submission' => input_value($op['body'], '_submission') ?? bin2hex(random_bytes(32)), 'line_item_id' => $jo['order_line_item_id']]);
$R->check('P25', 'A second job order for the same line item is refused', (int) $one('SELECT COUNT(*) FROM job_orders WHERE order_id = :o', ['o' => $oid]) === 1);

// =====================================================================
$R->section('Production, QC and release');
$joid = $jo['id'];
$jp = $prod->get('/job-orders/' . $joid);
$R->check('J1', 'Production user sees the job order; no peso amounts shown', $jp['status'] === 200 && !str_contains($jp['body'], '₱'));
$adv = function (Browser $c) use ($joid, $one) {
    $p = $c->get('/job-orders/' . $joid);
    return [$c->post('/job-orders/' . $joid . '/advance', ['_csrf' => csrf($p['body']), 'expected_stage' => input_value($p['body'], 'expected_stage'), 'expected_status' => input_value($p['body'], 'expected_status')]), $p];
};
[$r, $page] = $adv($prod);
$R->check('J2', 'Start Production: ON_HOLD → IN_PROGRESS; order → IN_PRODUCTION', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'IN_PROGRESS' && $one('SELECT status FROM orders WHERE id = :o', ['o' => $oid]) === 'IN_PRODUCTION');
// Replay the same (now stale) form: must not advance a second time.
$prod->post('/job-orders/' . $joid . '/advance', ['_csrf' => csrf($page['body']), 'expected_stage' => input_value($page['body'], 'expected_stage'), 'expected_status' => input_value($page['body'], 'expected_status')]);
$R->check('J3', 'Double-click / stale form does not advance twice', (int) $one('SELECT current_stage_order FROM job_orders WHERE id = :i', ['i' => $joid]) === 1 && $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'IN_PROGRESS');
$adv($prod); // → Printing
$adv($prod); // → Finishing
$adv($prod); // → QC
$R->check('J4', 'Stages advance in order to Quality Control (stage 4, status QC)', (int) $one('SELECT current_stage_order FROM job_orders WHERE id = :i', ['i' => $joid]) === 4 && $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'QC');
[$r] = $adv($prod);
$R->check('J5', 'Cannot pass QC without any QC result', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'QC' && str_contains(alerts($prod->follow($r)['body']), 'at least one QC'));
$qc = function (string $item, string $outcome) use ($prod, $joid) {
    $p = $prod->get('/job-orders/' . $joid);
    return $prod->post('/job-orders/' . $joid . '/qc', ['_csrf' => csrf($p['body']), '_submission' => input_value($p['body'], '_submission'), 'item_label' => $item, 'outcome' => $outcome, 'notes' => 'QA']);
};
$qc('QA colour check', 'FAIL');
[$r] = $adv($prod);
$R->check('J6', 'A failed QC item blocks the next stage', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'QC' && str_contains(alerts($prod->follow($r)['body']), 'QC failed'));
$qc('QA colour check', 'PASS');
[$r] = $adv($prod);
$R->check('J7', 'After a passing re-check the job order moves to READY', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'READY');
[$r] = $adv($prod);
$R->check('J8', 'Release blocked while the balance is unpaid', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'READY' && str_contains(alerts($prod->follow($r)['body']), 'full payment'));
$op = $staff->get('/orders/' . $oid);
$staff->post('/orders/' . $oid . '/payments', $payForm($op, ['amount' => '2744.00', 'reference_number' => 'QA-PAYMENT-003', 'method' => 'MAYA']));
$p3 = (string) $one("SELECT id FROM payments WHERE reference_number = 'QA-PAYMENT-003'");
$op = $staff->get('/orders/' . $oid);
$staff->post('/payments/' . $p3 . '/confirm', ['_csrf' => csrf($op['body'])]);
$op = $staff->get('/orders/' . $oid);
$R->check('J9', 'Fully paid: balance cleared (confirmed ₱5,488.00)', $one("SELECT SUM(amount) FROM payments WHERE order_id = :o AND status='CONFIRMED'", ['o' => $oid]) === '5488.00' && str_contains($op['body'], 'Fully Paid'));
$op2 = $staff->get('/orders/' . $oid);
// The Record Payment form is no longer offered; post a hand-crafted request with a fresh token.
$r = $staff->post('/orders/' . $oid . '/payments', $payForm($op2, ['amount' => '1', 'reference_number' => 'QA-PAYMENT-004', '_submission' => bin2hex(random_bytes(32))]));
$R->check('J10', 'Overpayment on a fully paid order refused', str_contains(alerts($staff->follow($r)['body']), 'no remaining balance'));
[$r] = $adv($prod);
$R->check('J11', 'Release succeeds once paid; order → FULFILLING', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'RELEASED' && $one('SELECT status FROM orders WHERE id = :o', ['o' => $oid]) === 'FULFILLING');
$p = $prod->get('/job-orders/' . $joid);
$prod->post('/job-orders/' . $joid . '/undo', ['_csrf' => csrf($p['body']), 'expected_stage' => input_value($p['body'], 'expected_stage'), 'expected_status' => input_value($p['body'], 'expected_status')]);
$R->check('J12', 'Undo restores READY and the order back to IN_PRODUCTION', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'READY' && $one('SELECT status FROM orders WHERE id = :o', ['o' => $oid]) === 'IN_PRODUCTION');
$adv($prod);
$jp = $prod->get('/job-orders/' . $joid);
$R->check('J13', 'Stage history lists every change with the user', substr_count($jp['body'], 'QA Production User') >= 6);
$op = $staff->get('/orders/' . $oid);
$staff->post('/orders/' . $oid . '/complete', ['_csrf' => csrf($op['body'])]);
$R->check('J14', 'Order completed; job order COMPLETED', $one('SELECT status FROM orders WHERE id = :o', ['o' => $oid]) === 'COMPLETED' && $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'COMPLETED');
$p = $prod->get('/job-orders/' . $joid);
$r = $prod->post('/job-orders/' . $joid . '/undo', ['_csrf' => csrf($p['body'])]);
$R->check('J15', 'Completed order\'s job order can no longer be changed', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $joid]) === 'COMPLETED');
$op = $staff->get('/orders/' . $oid);
$staff->post('/orders/' . $oid . '/cancel', ['_csrf' => csrf($op['body']), 'reason' => 'QA']);
$R->check('J16', 'Completed order cannot be cancelled', $one('SELECT status FROM orders WHERE id = :o', ['o' => $oid]) === 'COMPLETED');
$dash = $admin->get('/dashboard');
$R->check('J17', 'Dashboard outstanding balance ₱0.00 after full payment', (bool) preg_match('#data-kpi="outstanding">₱0\.00#', $dash['body']));

// =====================================================================
$R->section('Second order: cancellation, release exception, approved terms');
$adminCsrf = fn (string $path) => csrf($admin->get($path)['body']);
$c2 = $admin->get('/customers/new');
$admin->post('/customers', ['_csrf' => csrf($c2['body']), '_submission' => input_value($c2['body'], '_submission'), 'name' => 'QA Terms Customer', 'company_name' => '', 'email' => '',
    'contact_number' => '', 'address' => '', 'facebook_url' => '', 'is_qualified_for_terms' => '1']);
$cust2 = (string) $one("SELECT id FROM customers WHERE name = 'QA Terms Customer'");
$R->check('S1', 'Admin can flag a customer as qualified for payment terms', (int) $one('SELECT is_qualified_for_terms FROM customers WHERE id = :i', ['i' => $cust2]) === 1);
$qf = $admin->get('/quotations/new?customer_id=' . $cust2);
$admin->post('/quotations', ['_csrf' => csrf($qf['body']), '_submission' => input_value($qf['body'], '_submission'), 'customer_id' => $cust2, 'valid_until' => '',
    'service_id' => ['', ''], 'description' => ['QA Banner A', 'QA Sticker B'], 'qty' => ['2', '3'], 'unit' => ['pcs', 'pcs'], 'unit_price' => ['199.99', '0.01'],
    'discount_type' => 'PERCENTAGE', 'discount_value' => '10', 'tax_pct' => '12', 'notes' => '']);
$q2 = $row('SELECT * FROM quotations WHERE customer_id = :c', ['c' => $cust2]);
// 2×199.99 + 3×0.01 = 400.01; 10% = 40.00 (round2 of 40.001); taxable 360.01; 12% = 43.20 (43.2012); total 403.21
$R->check('S2', 'Percentage discount + tax rounding matches the original formula (400.01 → 403.21)', $q2 && $q2['subtotal'] === '400.01' && $q2['discount_amount'] === '40.00' && $q2['tax_amount'] === '43.20' && $q2['total'] === '403.21', $q2 ? "{$q2['subtotal']}/{$q2['discount_amount']}/{$q2['tax_amount']}/{$q2['total']}" : '');
$admin->post('/quotations/' . $q2['id'] . '/approve', ['_csrf' => $adminCsrf('/quotations/' . $q2['id']), 'reason' => 'QA: approved in person']);
$o2 = $row('SELECT * FROM orders WHERE quotation_id = :q', ['q' => $q2['id']]);
$R->check('S3', 'Qualified customer: order gets APPROVED_TERMS and its first job order immediately', $o2 && $o2['payment_term_type'] === 'APPROVED_TERMS' && (int) $one('SELECT COUNT(*) FROM job_orders WHERE order_id = :o', ['o' => $o2['id']]) === 1);
$op = $admin->get('/orders/' . $o2['id']);
$line2 = (string) $one('SELECT id FROM order_line_items WHERE order_id = :o AND description = :d', ['o' => $o2['id'], 'd' => 'QA Sticker B']);
$admin->post('/orders/' . $o2['id'] . '/job-orders', ['_csrf' => csrf($op['body']), '_submission' => input_value($op['body'], '_submission'), 'line_item_id' => $line2]);
$R->check('S4', 'Second job order added for the second line item', (int) $one('SELECT COUNT(*) FROM job_orders WHERE order_id = :o', ['o' => $o2['id']]) === 2);
$jo2 = (string) $one('SELECT id FROM job_orders WHERE order_id = :o ORDER BY created_at LIMIT 1', ['o' => $o2['id']]);
$p = $prod->get('/job-orders/' . $jo2);
$prod->post('/job-orders/' . $jo2 . '/advance', ['_csrf' => csrf($p['body']), 'expected_stage' => input_value($p['body'], 'expected_stage'), 'expected_status' => input_value($p['body'], 'expected_status')]);
$R->check('S5', 'Approved terms: production starts with ₱0 paid', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $jo2]) === 'IN_PROGRESS');
$r = $staff->post('/orders/' . $o2['id'] . '/release-exception', ['_csrf' => csrf($staff->get('/orders/' . $o2['id'])['body']), 'reason' => 'QA']);
$R->check('S6', 'STAFF cannot grant a release exception (admin only, 403)', $r['status'] === 403);
$admin->post('/orders/' . $o2['id'] . '/release-exception', ['_csrf' => $adminCsrf('/orders/' . $o2['id']), 'reason' => 'QA: trusted client, pays on delivery']);
$R->check('S7', 'Admin release exception recorded and audited', (int) $one('SELECT release_exception FROM orders WHERE id = :o', ['o' => $o2['id']]) === 1 && (int) $one("SELECT COUNT(*) FROM audit_log WHERE action = 'ORDER_RELEASE_EXCEPTION_GRANTED'") === 1);
$admin->post('/orders/' . $o2['id'] . '/cancel', ['_csrf' => $adminCsrf('/orders/' . $o2['id']), 'reason' => '']);
$R->check('S8', 'Cancel without a reason refused', $one('SELECT status FROM orders WHERE id = :o', ['o' => $o2['id']]) !== 'CANCELLED');
$admin->post('/orders/' . $o2['id'] . '/cancel', ['_csrf' => $adminCsrf('/orders/' . $o2['id']), 'reason' => 'QA: customer withdrew']);
$R->check('S9', 'Order cancelled with reason', $one('SELECT status FROM orders WHERE id = :o', ['o' => $o2['id']]) === 'CANCELLED');
$p = $prod->get('/job-orders/' . $jo2);
$prod->post('/job-orders/' . $jo2 . '/advance', ['_csrf' => csrf($p['body']), 'expected_stage' => input_value($p['body'], 'expected_stage'), 'expected_status' => input_value($p['body'], 'expected_status')]);
$R->check('S10', 'Cancelled order\'s job orders are frozen', $one('SELECT status FROM job_orders WHERE id = :i', ['i' => $jo2]) === 'IN_PROGRESS');
$op = $admin->get('/orders/' . $o2['id']);
$R->check('S11', 'No Record Payment on a cancelled order', !str_contains($op['body'], 'record-payment-modal'));
$dash = $admin->get('/dashboard');
$R->check('S12', 'Cancelled order excluded from outstanding balance (still ₱0.00)', (bool) preg_match('#data-kpi="outstanding">₱0\.00#', $dash['body']));

// =====================================================================
$R->section('Inventory');
$inv = $staff->get('/inventory/new');
$item = ['_csrf' => csrf($inv['body']), '_submission' => input_value($inv['body'], '_submission'), 'sku' => 'qa-tarp-001', 'name' => 'QA Tarpaulin Roll', 'unit' => 'roll', 'current_qty' => '3', 'reorder_threshold' => '5'];
$staff->post('/inventory', $item);
$R->check('I1', 'Inventory item created (SKU normalised to upper case)', $one("SELECT current_qty FROM inventory_items WHERE sku = 'QA-TARP-001'") === 3);
$inv = $staff->get('/inventory/new');
$r = $staff->post('/inventory', array_replace($item, ['_csrf' => csrf($inv['body']), '_submission' => input_value($inv['body'], '_submission')]));
$R->check('I2', 'Duplicate SKU rejected', $r['status'] === 422 && str_contains($r['body'], 'already used'));
$il = $staff->get('/inventory?low=1');
$R->check('I3', 'Low-stock filter shows the item flagged Low', str_contains($il['body'], 'QA Tarpaulin Roll') && str_contains($il['body'], 'badge-yellow'));
$iid = (string) $one("SELECT id FROM inventory_items WHERE sku = 'QA-TARP-001'");
$ie = $staff->get('/inventory/' . $iid . '/edit');
$staff->post('/inventory/' . $iid, ['_csrf' => csrf($ie['body']), 'sku' => 'QA-TARP-001', 'name' => 'QA Tarpaulin Roll', 'unit' => 'roll', 'current_qty' => '12', 'reorder_threshold' => '5']);
$R->check('I4', 'Stock quantity updated and audited', $one("SELECT current_qty FROM inventory_items WHERE sku = 'QA-TARP-001'") === 12 && (int) $one("SELECT COUNT(*) FROM audit_log WHERE action = 'INVENTORY_ITEM_UPDATED'") === 1);
$R->check('I5', 'PRODUCTION can view inventory but not edit it', $prod->get('/inventory')['status'] === 200 && $prod->get('/inventory/' . $iid . '/edit')['status'] === 403);
$ie = $staff->get('/inventory/' . $iid . '/edit');
$r = $staff->post('/inventory/' . $iid, ['_csrf' => csrf($ie['body']), 'sku' => 'QA-TARP-001', 'name' => 'QA Tarpaulin Roll', 'unit' => 'roll', 'current_qty' => '-4', 'reorder_threshold' => '5']);
$R->check('I6', 'Negative stock rejected', $r['status'] === 422);

// =====================================================================
$R->section('Exports');
$c3 = $staff->get('/customers/new');
$staff->post('/customers', ['_csrf' => csrf($c3['body']), '_submission' => input_value($c3['body'], '_submission'), 'name' => '=HYPERLINK("http://example.test","QA-CSV")', 'company_name' => '', 'email' => '', 'contact_number' => '', 'address' => '', 'facebook_url' => '']);
$cust3 = (string) $one('SELECT id FROM customers WHERE name LIKE :n', ['n' => '=HYPERLINK%']);
$qf = $staff->get('/quotations/new?customer_id=' . $cust3);
$staff->post('/quotations', ['_csrf' => csrf($qf['body']), '_submission' => input_value($qf['body'], '_submission'), 'customer_id' => $cust3, 'valid_until' => '', 'service_id' => [''], 'description' => ['QA CSV item'],
    'qty' => ['1'], 'unit' => [''], 'unit_price' => ['10'], 'discount_type' => 'PERCENTAGE', 'discount_value' => '0', 'tax_pct' => '0', 'notes' => '']);
$q3 = (string) $one('SELECT id FROM quotations WHERE customer_id = :c', ['c' => $cust3]);
$staff->post('/quotations/' . $q3 . '/approve', ['_csrf' => csrf($staff->get('/quotations/' . $q3)['body']), 'reason' => 'QA CSV test']);
$csv = $staff->get('/orders/export');
$R->check('X1', 'Orders CSV export downloads (text/csv attachment)', $csv['status'] === 200 && str_contains($csv['headers']['content-type'][0] ?? '', 'text/csv') && str_contains($csv['headers']['content-disposition'][0] ?? '', 'attachment'));
$R->check('X2', 'Export contains the QA order with its total and zero balance', (bool) preg_match('#ORD-\d{4}-0001,CUS-\d{4}-0001,"?QA Demo Printing Customer"?,COMPLETED,[^\n]*5488,5488,0,#', $csv['body']));
$R->check('X3', 'CSV formula injection neutralised (cell starts with \')', str_contains($csv['body'], '"\'=HYPERLINK(') && !preg_match('#(^|,)"?=HYPERLINK#m', $csv['body']));
$pcsv = $staff->get('/payments/export?status=CONFIRMED');
$R->check('X4', 'Payments CSV export honours the status filter', $pcsv['status'] === 200 && str_contains($pcsv['body'], 'QA-PAYMENT-001') && !str_contains($pcsv['body'], 'QA-PAYMENT-REJECT'));
$R->check('X5', 'PRODUCTION cannot export (403)', $prod->get('/payments/export')['status'] === 403);

// =====================================================================
$R->section('Security probes');
$x = $staff->get('/customers?q=' . urlencode("' OR 1=1 -- "));
$R->check('X-1', 'SQL injection in search: no error, no rows leaked', $x['status'] === 200 && str_contains($x['body'], 'No customers match') && $noLeak($x['body']));
$x = $staff->get('/orders/' . rawurlencode("' OR '1'='1"));
$R->check('X-2', 'SQL injection in an ID: plain 404', $x['status'] === 404 && $noLeak($x['body']));
$x = $staff->get('/orders?status=' . urlencode("OPEN' UNION SELECT 1 -- ") . '&page=' . urlencode('1;DROP'));
$R->check('X-3', 'Invalid filter/page values ignored safely', $x['status'] === 200 && $noLeak($x['body']));
$c4 = $staff->get('/customers/new');
$staff->post('/customers', ['_csrf' => csrf($c4['body']), '_submission' => input_value($c4['body'], '_submission'), 'name' => '<script>alert("qa-xss")</script>', 'company_name' => '<img src=x onerror=alert(1)>', 'email' => '', 'contact_number' => '', 'address' => '', 'facebook_url' => '']);
$xl = $staff->get('/customers?q=' . urlencode('qa-xss'));
$R->check('X-4', 'Stored XSS payload rendered escaped (list)', str_contains($xl['body'], '&lt;script&gt;alert(&quot;qa-xss&quot;)&lt;/script&gt;') && !str_contains($xl['body'], '<script>alert("qa-xss")'));
$xid = (string) $one('SELECT id FROM customers WHERE name LIKE :n', ['n' => '<script>%']);
$xs = $staff->get('/customers/' . $xid);
$R->check('X-5', 'Stored XSS payload rendered escaped (detail)', !str_contains($xs['body'], '<img src=x') && str_contains($xs['body'], '&lt;img src=x'));
$x = $staff->get('/customers?q=' . urlencode('"><script>alert(1)</script>'));
$R->check('X-6', 'Reflected XSS in search box escaped', !str_contains($x['body'], '"><script>alert(1)</script>'));
$x = $staff->post('/customers', ['name' => 'QA CSRF victim']);
$R->check('X-7', 'State change without CSRF token refused (403)', $x['status'] === 403 && (int) $one("SELECT COUNT(*) FROM customers WHERE name = 'QA CSRF victim'") === 0);
$anon = new Browser($BASE);
foreach (['/config/config.php', '/app/bootstrap.php', '/storage/install.lock', '/storage/logs/php-error.log', '/database/migrations/001_initial_schema.sql',
          '/.htaccess', '/README-DEPLOYMENT.md', '/composer.json', '/public/index.php', '/app/Views/layouts/app.php', '/assets/../../config/config.php',
          '/files/..%2F..%2Fconfig%2Fconfig.php', '/index.php/../config/config.php'] as $path) {
    $x = $anon->get($path);
    $leak = str_contains($x['body'], "'password'") || str_contains($x['body'], 'CREATE TABLE') || str_contains($x['body'], '<?php') || str_contains($x['body'], 'Installed ');
    $R->check('X-8', "Not downloadable: {$path}", !$leak && $x['status'] !== 500, "status {$x['status']}");
}
$x = $anon->get('/no-such-page-' . bin2hex(random_bytes(3)));
$R->check('X-9', 'Unknown page: friendly 404, no internals', $x['status'] === 404 && $noLeak($x['body']));
$x = $staff->get('/orders/ord_doesnotexist');
$R->check('X-10', 'Unknown record: 404 inside the app layout', $x['status'] === 404 && str_contains($x['body'], 'Order not found'));
$x = $staff->post('/orders/' . $oid . '/payments', ['_csrf' => csrf($staff->get('/orders')['body']), 'amount' => 'abc', 'method' => 'BITCOIN', 'payment_date' => 'yesterday']);
$R->check('X-11', 'Garbage payment input handled (no 500, nothing stored)', !$is500($x) && (int) $one("SELECT COUNT(*) FROM payments WHERE method NOT IN ('CASH','BANK_TRANSFER','GCASH','MAYA','CHEQUE','VOUCHER','OTHER')") === 0);
$x = $staff->post('/orders/ord_bogus/payments', ['_csrf' => csrf($staff->get('/orders')['body']), 'amount' => '10']);
$R->check('X-12', 'Payment against a non-existent order: 404', $x['status'] === 404);
$x = $staff->get('/job-orders?status=ALL');
$R->check('X-13', 'Production list (all) renders', $x['status'] === 200);
$logText = is_file($ROOT . '/storage/logs/php-error.log') ? (string) file_get_contents($ROOT . '/storage/logs/php-error.log') : '';
$R->check('X-14', 'No PHP warnings/notices/fatals logged during the run', !preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $logText), substr($logText, 0, 400));
$appLog = is_file($ROOT . '/storage/logs/app.log') ? (string) file_get_contents($ROOT . '/storage/logs/app.log') : '';
$allSecretsHidden = true;
foreach ($secrets as $s) {
    if ($s !== '' && (str_contains($logText, $s) || str_contains($appLog, $s))) {
        $allSecretsHidden = false;
    }
}
$R->check('X-15', 'No passwords written to the logs', $allSecretsHidden);
$x = $staff->get('/storage/');
$R->check('X-16', 'No directory listings', !str_contains($x['body'], 'Index of') && !str_contains($anon->get('/assets/')['body'], 'Index of'));
// Test setup: plant a PHP file inside public/ (as if an attacker managed to write one).
$probe = $ROOT . '/public/assets/qa-probe.php';
file_put_contents($probe, "<?php echo 'QA-' . 'EXECUTED';");
$x = $anon->get('/assets/qa-probe.php');
unlink($probe);
$R->check('X-17', 'A .php file planted under public/ is not executed', !str_contains($x['body'], 'QA-EXECUTED') && $x['status'] === 403, "status {$x['status']}");

// =====================================================================
$R->section('Pagination and logout');
for ($i = 1; $i <= 26; $i++) {
    $f = $staff->get('/customers/new');
    $staff->post('/customers', ['_csrf' => csrf($f['body']), '_submission' => input_value($f['body'], '_submission'), 'name' => sprintf('QA Bulk Customer %02d', $i), 'company_name' => '', 'email' => '', 'contact_number' => '', 'address' => '', 'facebook_url' => '']);
}
$p1 = $staff->get('/customers');
$p2 = $staff->get('/customers?page=2');
$R->check('N1', 'Customer list paginates (25 per page, page 2 reachable)', str_contains($p1['body'], 'Page 1 of 2') && str_contains($p1['body'], 'page=2') && str_contains($p2['body'], 'Page 2 of 2'));
$p99 = $staff->get('/customers?page=99');
$R->check('N2', 'Out-of-range page shows an empty list, not an error', $p99['status'] === 200);
$d = $staff->get('/dashboard');
$r = $staff->post('/logout', ['_csrf' => csrf($d['body'])]);
$R->check('N3', 'Logout (POST + CSRF) ends the session', in_array($r['status'], [302, 303], true) && in_array($staff->get('/orders')['status'], [302, 303], true));

// =====================================================================
$counts = $R->counts();
file_put_contents($OUT, json_encode(['layout' => $LAYOUT, 'base' => $BASE, 'rows' => $R->rows, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
array_map('unlink', glob($TMP . '/*'));
rmdir($TMP);
fwrite(STDOUT, sprintf("\n[%s] PASS %d  FAIL %d  BLOCKED %d  NOT RUN %d\n", $LAYOUT, $counts['PASS'], $counts['FAIL'], $counts['BLOCKED'], $counts['NOT RUN']));
exit($counts['FAIL'] > 0 ? 1 : 0);
