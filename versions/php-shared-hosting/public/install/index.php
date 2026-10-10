<?php

declare(strict_types=1);

/**
 * WordPress-style browser installer. Single-file wizard (session-backed
 * step state) rather than one PHP file per step — keeps all partial
 * credentials/admin-account input in one place until the final "Finish"
 * step commits everything atomically, so an interrupted install never
 * leaves half-written config.php/schema behind (spec section 3:
 * "installation recovery").
 */

define('LP_INSTALLER_CONTEXT', true);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Database;

session_start();

$configFile = APP_ROOT . '/config/config.php';
$lockFile = APP_ROOT . '/storage/install.lock';

// --- Lock check (spec: "protection against unauthorized installer reuse") ---
if (is_file($lockFile)) {
    http_response_code(403);
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Already Installed</title>
    <link rel="stylesheet" href="/assets/css/app.css"></head>
    <body><div class="login-page"><div class="login-card">
    <h1 class="page-title">LP System is already installed</h1>
    <p>The installer has been locked for security. To reinstall (this will NOT delete your
    existing data, but will let the wizard run again), an administrator must delete
    <code>storage/install.lock</code> via FTP/File Manager, or re-run it intentionally for
    disaster recovery.</p>
    <p><a href="/login" class="btn btn-primary">Go to login</a></p>
    </div></div></body></html>
    <?php
    exit;
}

function step(): int
{
    $step = (int) ($_GET['step'] ?? $_SESSION['install_step'] ?? 1);
    return max(1, min(6, $step));
}

function renderStep(int $n, array $data = []): void
{
    $file = __DIR__ . '/steps/' . $n . '.php';
    $currentStep = $n;
    extract($data);
    require __DIR__ . '/layout_top.php';
    require $file;
    require __DIR__ . '/layout_bottom.php';
}

$currentStep = step();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedStep = (int) ($_POST['_step'] ?? 1);

    if ($postedStep === 2) {
        // Requirements step has no input — just advance.
        $_SESSION['install_step'] = 3;
        header('Location: /install/?step=3');
        exit;
    }

    if ($postedStep === 3) {
        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'database' => trim((string) ($_POST['db_name'] ?? '')),
            'username' => trim((string) ($_POST['db_user'] ?? '')),
            'password' => (string) ($_POST['db_pass'] ?? ''),
        ];
        $test = Database::testConnection($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);
        if (!$test['ok']) {
            $error = 'Could not connect: ' . $test['error'];
            renderStep(3, ['error' => $error, 'db' => $db]);
            exit;
        }
        $_SESSION['install_db'] = $db;
        $_SESSION['install_step'] = 4;
        header('Location: /install/?step=4');
        exit;
    }

    if ($postedStep === 4) {
        // Run schema + seed migrations now, using the tested credentials.
        $db = $_SESSION['install_db'] ?? null;
        if (!$db) {
            header('Location: /install/?step=3');
            exit;
        }
        try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
            if (!$existing) {
                $schemaSql = file_get_contents(APP_ROOT . '/database/migrations/001_initial_schema.sql');
                $seqSql = file_get_contents(APP_ROOT . '/database/migrations/002_number_sequences.sql');
                $seedSql = file_get_contents(APP_ROOT . '/database/seeds/001_default_data.sql');
                foreach ([$schemaSql, $seqSql, $seedSql] as $sql) {
                    // Strip full-line "-- ..." comments before splitting on
                    // ";" — a comment containing a literal semicolon (e.g.
                    // "-- ...; id is always 'default'") would otherwise be
                    // misread as a statement boundary.
                    $noComments = preg_replace('/^--.*$/m', '', $sql);
                    foreach (array_filter(array_map('trim', explode(';', $noComments))) as $stmt) {
                        $pdo->exec($stmt);
                    }
                }
            }
            $_SESSION['install_step'] = 5;
            header('Location: /install/?step=5');
            exit;
        } catch (\Throwable $e) {
            renderStep(4, ['error' => 'Schema setup failed: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($postedStep === 5) {
        $name = trim((string) ($_POST['admin_name'] ?? ''));
        $email = trim((string) ($_POST['admin_email'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirm = (string) ($_POST['admin_password_confirm'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8) {
            renderStep(5, ['error' => 'Name, email, and a password of at least 8 characters are required.']);
            exit;
        }
        if ($password !== $confirm) {
            renderStep(5, ['error' => 'Passwords do not match.']);
            exit;
        }

        $_SESSION['install_admin'] = ['name' => $name, 'email' => $email, 'password' => $password];
        $_SESSION['install_step'] = 6;
        header('Location: /install/?step=6');
        exit;
    }

    if ($postedStep === 6) {
        $businessName = trim((string) ($_POST['business_name'] ?? 'LP System'));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Asia/Manila'));
        $appUrl = rtrim((string) ($_POST['app_url'] ?? ''), '/');

        $db = $_SESSION['install_db'];
        $admin = $_SESSION['install_admin'];

        try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $existingAdmin = $pdo->prepare('SELECT id FROM users WHERE email = :e');
            $existingAdmin->execute(['e' => $admin['email']]);
            if (!$existingAdmin->fetch()) {
                $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, active) VALUES (:id, :name, :email, :hash, :role, 1)')
                    ->execute([
                        'id' => 'usr_' . bin2hex(random_bytes(10)),
                        'name' => $admin['name'],
                        'email' => $admin['email'],
                        'hash' => password_hash($admin['password'], PASSWORD_BCRYPT),
                        'role' => 'ADMIN',
                    ]);
            }

            $pdo->prepare('UPDATE business_settings SET business_name = :name WHERE id = :bid')
                ->execute(['name' => $businessName, 'bid' => 'default']);

            $appKey = bin2hex(random_bytes(32));
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            $configContents = "<?php\nreturn " . var_export([
                'db' => $db,
                'app' => [
                    'key' => $appKey,
                    'url' => $appUrl !== '' ? $appUrl : (($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
                    'env' => 'production',
                    'debug' => false,
                    'timezone' => $timezone,
                    'name' => $businessName,
                ],
                'session' => ['name' => 'lp_session', 'lifetime_minutes' => 240, 'secure_cookie' => $isHttps],
                'mail' => ['smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => '', 'from_address' => 'no-reply@example.com', 'from_name' => $businessName],
                'installed' => true,
            ], true) . ";\n";

            if (!is_dir(APP_ROOT . '/config')) {
                mkdir(APP_ROOT . '/config', 0755, true);
            }
            file_put_contents(APP_ROOT . '/config/config.php', $configContents);
            // 0644, not something tighter like 0640: on typical shared
            // hosting the PHP process runs AS the account owner (suPHP/
            // PHP-FPM per-user), so a tighter mode would work there, but
            // this must also run correctly when PHP runs as a separate
            // system user (e.g. a traditional Apache+mod_php setup) —
            // HTTP access is what actually matters here, and that is
            // already fully blocked by config/.htaccess regardless of
            // this file's filesystem permissions.
            @chmod(APP_ROOT . '/config/config.php', 0644);

            if (!is_dir(dirname($lockFile))) {
                mkdir(dirname($lockFile), 0755, true);
            }
            file_put_contents($lockFile, "Installed " . date('c') . "\n");

            unset($_SESSION['install_db'], $_SESSION['install_admin'], $_SESSION['install_step']);

            renderStep(7, []);
            exit;
        } catch (\Throwable $e) {
            renderStep(6, ['error' => 'Final setup failed: ' . $e->getMessage()]);
            exit;
        }
    }
}

renderStep($currentStep, ['error' => $error]);
