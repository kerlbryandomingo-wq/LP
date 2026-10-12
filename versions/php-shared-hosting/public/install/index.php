<?php

declare(strict_types=1);

/**
 * WordPress-style browser installer.
 *
 *  1 Welcome → 2 Requirements → 3 Database → 4 Create tables → 5 Administrator
 *  → 6 Site settings / finish → 7 Done (installer locked)
 *
 * Safety rules:
 *  - Locked (403) for every GET and POST once the site is installed
 *    (storage/install.lock or config/config.php with installed=true).
 *  - A step can only be reached after the previous one succeeded on the
 *    server (session-tracked); posting a later step directly is refused.
 *  - Every POST carries an installer CSRF token.
 *  - Requirements are re-checked on the server when continuing.
 *  - Database errors are explained in plain language; passwords are never
 *    echoed back.
 *  - A database that already holds LP System data is only reused after an
 *    explicit confirmation, and never wiped. A database holding another
 *    application's "users" table is refused.
 *  - Tables are created through the same Migrator used for later updates,
 *    then verified, before the administrator can be created.
 *  - config.php is written atomically (temp file + rename), after the
 *    administrator account is saved; the lock file is written last.
 */

define('LP_INSTALLER_CONTEXT', true);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\AuthService;
use App\Services\Migrator;

const LP_MIN_PHP = '8.0.0';
const LP_LAST_STEP = 6;

$configFile = APP_ROOT . '/config/config.php';
$lockFile = APP_ROOT . '/storage/install.lock';

if (is_installed()) {
    render_fatal_page(
        403,
        'LP System is already installed',
        'The installer is locked so nobody can reinstall over your data. '
        . '<a href="' . e(url('/login')) . '">Go to the sign-in page</a>.'
    );
    exit;
}

// Separate session from the application, scoped to the installer folder.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('lp_install');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => base_path() . '/install/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
$S = &$_SESSION['lp_install'];
if (!is_array($S)) {
    $S = ['reached' => 1, 'token' => bin2hex(random_bytes(32))];
}

function install_url(int $step): string
{
    return url('/install/') . '?step=' . $step;
}

function go(int $step): void
{
    header('Location: ' . install_url($step));
    exit;
}

function render_step(int $n, array $data = []): void
{
    global $S;
    $currentStep = $n;
    $token = $S['token'];
    extract($data, EXTR_SKIP);
    require __DIR__ . '/layout_top.php';
    require __DIR__ . '/steps/' . $n . '.php';
    require __DIR__ . '/layout_bottom.php';
}

/** @return array<int, array{label:string, ok:bool|null, detail:string, optional?:bool}> */
function requirements(): array
{
    $checks = [];
    $checks[] = ['label' => 'PHP version ' . LP_MIN_PHP . ' or newer', 'ok' => version_compare(PHP_VERSION, LP_MIN_PHP, '>='), 'detail' => PHP_VERSION];
    foreach (['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'session', 'ctype', 'filter'] as $ext) {
        $checks[] = ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : 'missing — enable it in your hosting control panel (Select PHP Version / PHP Extensions)'];
    }
    foreach (['config', 'storage', 'storage/logs', 'storage/cache', 'storage/private-uploads'] as $dir) {
        $path = APP_ROOT . '/' . $dir;
        $ok = is_dir($path) && is_writable($path);
        $checks[] = ['label' => "Writable folder: {$dir}/", 'ok' => $ok, 'detail' => $ok ? 'writable' : 'not writable — set this folder to 755 (or 775) in File Manager'];
    }
    if (function_exists('apache_get_modules')) {
        $on = in_array('mod_rewrite', apache_get_modules(), true);
        $checks[] = ['label' => 'Apache mod_rewrite', 'ok' => $on, 'detail' => $on ? 'enabled' : 'not enabled — ask your host to enable mod_rewrite and .htaccess overrides'];
    }
    $checks[] = rewrite_check();
    $checks[] = ['label' => 'HTTPS', 'ok' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'detail' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'on' : 'not detected — strongly recommended; enable a free SSL certificate in your hosting panel', 'optional' => true];
    $checks[] = ['label' => 'GD extension', 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? 'loaded' : 'not loaded (optional)', 'optional' => true];
    return $checks;
}

/**
 * Asks this same site for /lp-rewrite-check, which only the front controller
 * answers. Pretty URLs (/login, /orders/…) need that rewriting. Some hosts
 * block a site from calling itself; then the check is "not verified" rather
 * than failed.
 */
function rewrite_check(): array
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        return ['label' => 'URL rewriting (.htaccess)', 'ok' => null, 'detail' => 'could not be verified automatically', 'optional' => true];
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $target = ($https ? 'https://' : 'http://') . $host . url('/lp-rewrite-check');
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents($target, false, $ctx);
    if ($body === false) {
        return ['label' => 'URL rewriting (.htaccess)', 'ok' => null, 'detail' => 'could not be verified (this server blocks requests to itself) — if pages other than the installer show "Not Found" later, ask your host to enable .htaccess / mod_rewrite', 'optional' => true];
    }
    $ok = str_contains($body, 'LP-REWRITE-OK');
    return ['label' => 'URL rewriting (.htaccess)', 'ok' => $ok, 'detail' => $ok ? 'working' : 'not working — the .htaccess files are being ignored. Ask your host to enable mod_rewrite and AllowOverride for your site'];
}

function requirements_ok(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['ok'] === false && empty($c['optional'])) {
            return false;
        }
    }
    return true;
}

function pdo_for(array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
    return new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

/** Plain-language explanation of a connection failure. Never includes the password. */
function explain_db_error(PDOException $e, array $db): string
{
    $code = (int) ($e->errorInfo[1] ?? $e->getCode());
    return match ($code) {
        1045 => 'The database username or password is incorrect.',
        1044, 1049 => "The database \"{$db['database']}\" does not exist, or the user \"{$db['username']}\" has not been given access to it. Create the database and add the user to it with ALL PRIVILEGES (cPanel → MySQL Databases). On cPanel the full name usually starts with your account name, e.g. account_lpsystem.",
        2002, 2003, 2005, 2006 => "Could not reach a database server at {$db['host']}:{$db['port']}. Most shared hosts use \"localhost\" and port 3306.",
        default => 'Could not connect to the database (error ' . $code . '). Check the details and try again.',
    };
}

/** @return string|null problem with the server version, or null if supported */
function db_version_problem(string $version): ?string
{
    if (stripos($version, 'mariadb') !== false) {
        return version_compare($version, '10.3.0', '>=') ? null : "MariaDB {$version} is too old; 10.3 or newer is required.";
    }
    return version_compare($version, '5.7.8', '>=') ? null : "MySQL {$version} is too old; 5.7.8 or newer is required.";
}

/**
 * What is already in the chosen database.
 * @return array{state:'empty'|'lp'|'other'|'foreign_users', tables:int, admins:int}
 */
function inspect_database(PDO $pdo): array
{
    $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_map('strtolower', $tables);
    if (!$tables) {
        return ['state' => 'empty', 'tables' => 0, 'admins' => 0];
    }
    if (in_array('users', $tables, true)) {
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols);
        if (!in_array('must_change_password', $cols, true) || !in_array('role', $cols, true)) {
            return ['state' => 'foreign_users', 'tables' => count($tables), 'admins' => 0];
        }
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn();
        return ['state' => 'lp', 'tables' => count($tables), 'admins' => $admins];
    }
    $lpTables = array_intersect($tables, Migrator::expectedTables());
    return ['state' => $lpTables ? 'lp' : 'other', 'tables' => count($tables), 'admins' => 0];
}

// ---------------------------------------------------------------------------

$requested = (int) ($_GET['step'] ?? $S['reached']);
$requested = max(1, min(LP_LAST_STEP, $requested));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (int) ($_POST['_step'] ?? 0);
    if (!hash_equals($S['token'], (string) ($_POST['_token'] ?? ''))) {
        http_response_code(403);
        render_step(max(1, min($S['reached'], LP_LAST_STEP)), ['error' => 'Your installer session expired or the form was submitted twice. Please try again.']);
        exit;
    }
    if ($posted < 1 || $posted > LP_LAST_STEP || $posted > $S['reached']) {
        go($S['reached']);
    }

    switch ($posted) {
        case 1:
            $S['reached'] = max($S['reached'], 2);
            go(2);

        case 2:
            $checks = requirements();
            if (!requirements_ok($checks)) {
                http_response_code(422);
                render_step(2, ['checks' => $checks, 'error' => 'Some requirements are still not met. Fix the items marked FAIL, then check again.']);
                exit;
            }
            $S['reached'] = max($S['reached'], 3);
            go(3);

        case 3:
            if (!requirements_ok(requirements())) {
                $S['reached'] = 2;
                go(2);
            }
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'port' => trim((string) ($_POST['db_port'] ?? '3306')),
                'database' => trim((string) ($_POST['db_name'] ?? '')),
                'username' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_pass'] ?? ''),
                'charset' => 'utf8mb4',
            ];
            $errors = [];
            if (!preg_match('/^[A-Za-z0-9.\-_]{1,255}$/', $db['host'])) {
                $errors[] = 'Database host must be a host name or IP address, e.g. localhost.';
            }
            if (!ctype_digit($db['port']) || (int) $db['port'] < 1 || (int) $db['port'] > 65535) {
                $errors[] = 'Port must be a number between 1 and 65535 (usually 3306).';
            }
            if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $db['database'])) {
                $errors[] = 'Database name may only contain letters, numbers, underscores, dashes and $ (max 64 characters).';
            }
            if ($db['username'] === '' || strlen($db['username']) > 80 || preg_match('/[\x00-\x1F\x7F]/', $db['username'])) {
                $errors[] = 'Enter the database username.';
            }
            if (strlen($db['password']) > 256 || preg_match('/[\x00]/', $db['password'])) {
                $errors[] = 'The database password is not valid.';
            }
            $db['port'] = (int) $db['port'];
            $shown = $db;
            unset($shown['password']);
            if ($errors) {
                http_response_code(422);
                render_step(3, ['db' => $shown, 'errors' => $errors]);
                exit;
            }
            try {
                $pdo = pdo_for($db);
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            } catch (PDOException $e) {
                error_log('Installer DB connection failed: ' . $e->getMessage());
                http_response_code(422);
                render_step(3, ['db' => $shown, 'errors' => [explain_db_error($e, $db)]]);
                exit;
            }
            if ($problem = db_version_problem($version)) {
                http_response_code(422);
                render_step(3, ['db' => $shown, 'errors' => [$problem]]);
                exit;
            }
            $found = inspect_database($pdo);
            if ($found['state'] === 'foreign_users') {
                http_response_code(422);
                render_step(3, ['db' => $shown, 'errors' => ['This database already contains a "users" table from a different application. Create a new, empty database for LP System and use that.']]);
                exit;
            }
            if ($found['state'] === 'lp' && ($_POST['confirm_existing'] ?? '') !== '1') {
                http_response_code(409);
                render_step(3, ['db' => $shown, 'existing' => $found, 'errors' => [
                    'This database already contains LP System data (' . $found['tables'] . ' tables, ' . $found['admins'] . ' administrator account(s)). '
                    . 'Nothing will be deleted. Tick the confirmation box to keep that data and bring it up to date, or use a different, empty database.',
                ]]);
                exit;
            }
            $S['db'] = $db;
            $S['db_version'] = $version;
            $S['db_state'] = $found;
            $S['reached'] = 4;
            unset($S['tables_ok'], $S['admin']);
            go(4);

        case 4:
            try {
                $pdo = pdo_for($S['db']);
                $m = new Migrator($pdo);
                $applied = $m->migrate();
                $missing = $m->missingTables();
            } catch (\Throwable $e) {
                error_log('Installer migration failed: ' . $e->getMessage());
                http_response_code(500);
                render_step(4, ['error' => 'Creating the tables failed. Details were written to storage/logs/php-error.log. '
                    . 'Make sure the database user has ALL PRIVILEGES on the database, then try again — completed steps are not repeated.']);
                exit;
            }
            if ($missing) {
                http_response_code(500);
                render_step(4, ['error' => 'These tables are still missing after setup: ' . implode(', ', $missing) . '. Check the database user\'s privileges and try again.']);
                exit;
            }
            $S['tables_ok'] = true;
            $S['applied'] = $applied;
            $S['reached'] = 5;
            go(5);

        case 5:
            if (empty($S['tables_ok'])) {
                go(4);
            }
            $name = trim((string) ($_POST['admin_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
            $password = (string) ($_POST['admin_password'] ?? '');
            $confirm = (string) ($_POST['admin_password_confirm'] ?? '');
            $errors = [];
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = 'Enter the administrator\'s full name.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $errors[] = 'Enter a valid email address.';
            }
            if ($p = AuthService::passwordProblem($password, $confirm)) {
                $errors[] = $p;
            }
            if (!$errors) {
                $stmt = pdo_for($S['db'])->prepare('SELECT 1 FROM users WHERE email = :e');
                $stmt->execute(['e' => $email]);
                if ($stmt->fetchColumn()) {
                    $errors[] = 'An account with this email already exists in this database. Use a different email.';
                }
            }
            if ($errors) {
                http_response_code(422);
                render_step(5, ['errors' => $errors, 'old' => ['admin_name' => $name, 'admin_email' => $email]]);
                exit;
            }
            // Only the hash is kept in the installer session.
            $S['admin'] = ['id' => 'usr_' . bin2hex(random_bytes(10)), 'name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_BCRYPT)];
            $S['reached'] = 6;
            go(6);

        case 6:
            if (empty($S['admin'])) {
                go(5);
            }
            $business = trim((string) ($_POST['business_name'] ?? ''));
            $timezone = (string) ($_POST['timezone'] ?? '');
            $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
            $errors = [];
            if ($business === '' || mb_strlen($business) > 100) {
                $errors[] = 'Enter your business name (up to 100 characters).';
            }
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose a valid timezone.';
            }
            if ($appUrl !== '' && (!filter_var($appUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $appUrl))) {
                $errors[] = 'Site URL must start with http:// or https://.';
            }
            if (!is_writable(APP_ROOT . '/config') || !is_writable(APP_ROOT . '/storage')) {
                $errors[] = 'The config/ and storage/ folders must be writable to finish. Set them to 755 (or 775) in File Manager.';
            }
            if ($errors) {
                http_response_code(422);
                render_step(6, ['errors' => $errors, 'old' => ['business_name' => $business, 'timezone' => $timezone, 'app_url' => $appUrl]]);
                exit;
            }

            $db = $S['db'];
            $admin = $S['admin'];
            try {
                $pdo = pdo_for($db);
                $pdo->beginTransaction();
                // Idempotent if this step is retried after a failure further down.
                $exists = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :e FOR UPDATE');
                $exists->execute(['e' => $admin['email']]);
                $row = $exists->fetch();
                if ($row && $row['id'] !== $admin['id']) {
                    $pdo->rollBack();
                    http_response_code(409);
                    $S['reached'] = 5;
                    render_step(5, ['errors' => ['An account with this email was created in the meantime. Use a different email.'], 'old' => ['admin_name' => $admin['name'], 'admin_email' => '']]);
                    exit;
                }
                if (!$row) {
                    $pdo->prepare("INSERT INTO users (id, name, email, password_hash, role, active, must_change_password) VALUES (:id, :name, :email, :hash, 'ADMIN', 1, 0)")
                        ->execute(['id' => $admin['id'], 'name' => $admin['name'], 'email' => $admin['email'], 'hash' => $admin['hash']]);
                }
                $pdo->prepare("UPDATE business_settings SET business_name = :n WHERE id = 'default'")->execute(['n' => $business]);
                $pdo->prepare("INSERT INTO audit_log (id, actor_id, action, entity_type, entity_id, metadata) VALUES (:id, :a, 'SYSTEM_INSTALLED', 'System', '-', :m)")
                    ->execute(['id' => 'aud_' . bin2hex(random_bytes(12)), 'a' => $admin['id'], 'm' => json_encode(['db_state' => $S['db_state']['state'] ?? 'empty'])]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Installer finish failed: ' . $e->getMessage());
                http_response_code(500);
                render_step(6, ['errors' => ['Saving the administrator account failed. Details were written to storage/logs/php-error.log.'], 'old' => $_POST]);
                exit;
            }

            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $config = [
                'db' => $db,
                'app' => [
                    'key' => bin2hex(random_bytes(32)),
                    'url' => $appUrl,
                    'env' => 'production',
                    'debug' => false,
                    'timezone' => $timezone,
                    'name' => $business,
                ],
                'session' => ['name' => 'lp_session', 'lifetime_minutes' => 240, 'secure_cookie' => $https],
                'installed' => true,
                'installed_at' => date('c'),
            ];
            $contents = "<?php\n// Generated by the LP System installer. Keep this file private; it contains the database password.\nreturn " . var_export($config, true) . ";\n";
            $tmp = APP_ROOT . '/config/.config.' . bin2hex(random_bytes(6)) . '.tmp';
            $wrote = @file_put_contents($tmp, $contents, LOCK_EX) === strlen($contents);
            // 0644: readable by the web server user on mod_php hosts; the file
            // is never web-served (config/.htaccess + it lives outside public/).
            $wrote = $wrote && @chmod($tmp, 0644) && @rename($tmp, $configFile);
            if (!$wrote) {
                @unlink($tmp);
                http_response_code(500);
                render_step(6, ['errors' => ['Could not write config/config.php. Make sure the config/ folder is writable, then click Finish again.'], 'old' => $_POST]);
                exit;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configFile, true);
            }
            @file_put_contents($lockFile, 'Installed ' . date('c') . "\n", LOCK_EX);

            $done = ['email' => $admin['email'], 'applied' => $S['applied'] ?? [], 'reused' => ($S['db_state']['state'] ?? '') === 'lp'];
            $_SESSION = [];
            session_destroy();
            render_step(7, ['done' => $done]);
            exit;
    }
}

if ($requested > $S['reached']) {
    go($S['reached']);
}
$data = [];
if ($requested === 2) {
    $data['checks'] = requirements();
}
if ($requested === 3 && !empty($S['db'])) {
    $data['db'] = array_diff_key($S['db'], ['password' => true]);
}
render_step($requested, $data);
