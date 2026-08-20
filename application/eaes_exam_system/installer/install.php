<?php

/**
 * installer/install.php — first-run setup wizard.
 * Does NOT use app/bootstrap.php (that would redirect back here).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Autoload.php';

session_start();

// Packaged installations use one private, loopback-only MariaDB service.  In
// development, where this file is not below the runtime root, retain the
// existing manual database configuration workflow.
$runtimePortsFile = dirname($root, 2) . '/config/ports.json';
$runtimePorts = is_file($runtimePortsFile) ? json_decode((string) file_get_contents($runtimePortsFile), true) : null;
$privateRuntime = is_array($runtimePorts) && isset($runtimePorts['mariadb']) && is_int($runtimePorts['mariadb']);

function is_loopback_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

if ($privateRuntime && !is_loopback_request()) {
    http_response_code(403);
    exit('First-run setup is available only from this computer.');
}

$lockFile = $root . '/storage/installed.lock';
if (is_file($lockFile)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif">EAES is already installed. The setup wizard is disabled to protect this installation.</p>');
}

$step = (int) ($_GET['step'] ?? 1);
$errors = [];
$notice = '';

function render_layout(string $title, string $body): void
{
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>Install — EAES Exam System</title>
    <style>
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#0f172a;margin:0;padding:40px 20px;color:#1e293b;}
        .wrap{max-width:640px;margin:0 auto;}
        .card{background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.3);padding:36px;}
        h1{font-size:22px;margin:0 0 4px;} .sub{color:#64748b;font-size:13px;margin-bottom:24px;}
        .steps{display:flex;gap:6px;margin-bottom:26px;}
        .steps div{flex:1;height:5px;border-radius:3px;background:#e2e8f0;}
        .steps div.done{background:#2563eb;}
        label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px;color:#334155;}
        input,select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;}
        button{margin-top:22px;width:100%;padding:13px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:15px;cursor:pointer;}
        button:hover{background:#1d4ed8;}
        .err{background:#fdf2f2;color:#de3a3a;border-left:4px solid #de3a3a;padding:12px;border-radius:4px;font-size:13px;margin-bottom:16px;}
        .ok{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;padding:12px;border-radius:4px;font-size:13px;margin-bottom:16px;}
        table{width:100%;border-collapse:collapse;font-size:13px;} td{padding:8px 4px;border-bottom:1px solid #f1f5f9;}
        code{background:#f1f5f9;padding:2px 6px;border-radius:4px;}
    </style></head><body><div class='wrap'><div class='card'>
    <h1>EAES Exam System — Setup</h1><div class='sub'>$title</div>$body
    </div></div></body></html>";
}

function step_indicator(int $current): string
{
    $html = "<div class='steps'>";
    for ($i = 1; $i <= 4; $i++) {
        $html .= "<div class='" . ($i <= $current ? 'done' : '') . "'></div>";
    }
    return $html . "</div>";
}

// ---------------------------------------------------------------------
// STEP 1 — Requirements check
// ---------------------------------------------------------------------
if ($step === 1) {
    $checks = [
        'PHP >= 8.1'          => version_compare(PHP_VERSION, '8.1.0', '>='),
        'pdo_mysql extension' => extension_loaded('pdo_mysql'),
        'json extension'      => extension_loaded('json'),
        'mbstring extension'  => extension_loaded('mbstring'),
        'storage/ writable'   => is_writable($root . '/storage'),
        'app/ readable'       => is_readable($root . '/app'),
    ];
    $allOk = !in_array(false, $checks, true);

    $rows = '';
    foreach ($checks as $label => $ok) {
        $rows .= "<tr><td>$label</td><td style='text-align:right'>" . ($ok ? '✅' : '❌') . "</td></tr>";
    }

    $body = step_indicator(1) . "<table>$rows</table>";
    if (!$allOk) {
        $body .= "<div class='err' style='margin-top:16px;'>Please resolve the failed checks above before continuing (e.g. enable the pdo_mysql extension in php.ini, or <code>chmod 755 storage</code>).</div>";
    } else {
        $body .= "<form method='get'><input type='hidden' name='step' value='2'><button type='submit'>Continue →</button></form>";
    }
    render_layout('Step 1 of 4 — System Requirements', $body);
    exit;
}

// ---------------------------------------------------------------------
// STEP 2 — Database configuration
// ---------------------------------------------------------------------
if ($step === 2) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $host = $privateRuntime ? '127.0.0.1' : trim($_POST['db_host'] ?? 'localhost');
        $port = $privateRuntime ? (int) $runtimePorts['mariadb'] : (int) ($_POST['db_port'] ?? 3306);
        $name = $privateRuntime ? 'eaes_exam' : trim($_POST['db_name'] ?? '');
        $user = $privateRuntime ? 'root' : trim($_POST['db_user'] ?? 'root');
        $pass = $privateRuntime ? '' : (string) ($_POST['db_pass'] ?? '');
        $appUrl = trim($_POST['app_url'] ?? '');
        $appName = trim($_POST['app_name'] ?? 'EAES Exam System');

        if ($name === '') {
            $errors[] = 'Database name is required.';
        } else {
            try {
                // Connect with multi-statement support
                $pdo = new PDO(
                    "mysql:host=$host;port=$port;charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                    ]
                );
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $pdo->exec("USE `$name`");

                // Read schema file, strip all comment lines and BOM
                $schema = file_get_contents($root . '/database/schema.sql');
                if (substr($schema, 0, 3) === "\xEF\xBB\xBF") {
                    $schema = substr($schema, 3);
                }
                $lines = explode("\n", $schema);
                $filteredLines = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    // Skip empty lines and lines that start with '--'
                    if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                        continue;
                    }
                    $filteredLines[] = $line;
                }
                $cleanSql = implode("\n", $filteredLines);

                // Execute the entire schema as one multi-statement query
                $pdo->exec($cleanSql);

                $appKey = bin2hex(random_bytes(32));
                $applicationUser = $user;
                $applicationPass = $pass;
                if ($privateRuntime) {
                    // The web application never runs as the private MariaDB root user.
                    $applicationUser = 'axumera_app';
                    $applicationPass = bin2hex(random_bytes(32));
                    $pdo->exec("CREATE USER 'axumera_app'@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($applicationPass));
                    $pdo->exec("GRANT ALL PRIVILEGES ON `eaes_exam`.* TO 'axumera_app'@'127.0.0.1'");
                    $pdo->exec('FLUSH PRIVILEGES');
                }
                $env = <<<ENV
                APP_NAME="{$appName}"
                APP_ENV=production
                APP_DEBUG=false
                APP_URL={$appUrl}
                APP_TIMEZONE=Africa/Addis_Ababa
                APP_KEY={$appKey}

                DB_HOST={$host}
                DB_PORT={$port}
                DB_NAME={$name}
                DB_USER={$applicationUser}
                DB_PASS={$applicationPass}
                DB_CHARSET=utf8mb4

                SESSION_LIFETIME_MINUTES=180
                ADMIN_MAX_LOGIN_ATTEMPTS=100
                ADMIN_LOCKOUT_MINUTES=15
                FORCE_HTTPS=false

                AUTOSAVE_INTERVAL_SECONDS=15
                GRACE_PERIOD_SECONDS=10

                ENV;
                $env = preg_replace('/^ +/m', '', $env);

                if (file_put_contents($root . '/.env', $env, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write the private application configuration.');
                }

                header('Location: install.php?step=3');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Database setup failed: ' . $e->getMessage();
            }
        }
    }

    $errHtml = $errors ? "<div class='err'>" . implode('<br>', array_map('htmlspecialchars', $errors)) . "</div>" : '';
    $body = step_indicator(2) . $errHtml . "
    <form method='post'>
        <label>Application Name</label><input name='app_name' value='EAES Exam System'>
        <label>Application URL</label><input name='app_url' placeholder='http://localhost/eaes_exam_system' value='" . htmlspecialchars((str_contains($_SERVER['HTTP_HOST'] ?? '', ':') ? '' : '') . 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . str_replace('/installer', '', dirname($_SERVER['REQUEST_URI'] ?? ''))) . "'>
        " . ($privateRuntime
            ? "<div class='ok'>The private local database is configured automatically for this new installation.</div>"
            : "<label>Database Host</label><input name='db_host' value='localhost'>
        <label>Database Port</label><input name='db_port' value='3306'>
        <label>Database Name</label><input name='db_name' value='eaes_exam' required>
        <label>Database User</label><input name='db_user' value='root'>
        <label>Database Password</label><input type='password' name='db_pass' value=''>") . "
        <button type='submit'>Create Database & Continue →</button>
    </form>";
    render_layout('Step 2 of 4 — Database Configuration', $body);
    exit;
}

// ---------------------------------------------------------------------
// STEP 3 — Create first admin account
// ---------------------------------------------------------------------
if ($step === 3) {
    if (!is_file($root . '/.env')) {
        header('Location: install.php?step=2');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if ($username === '' || strlen($password) < 8) {
            $errors[] = 'Please provide a username and a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            try {
                $GLOBALS['__eaes_config'] = require $root . '/app/config.php';
                if (!function_exists('config')) {
                    function config(string $key, mixed $default = null): mixed
                    {
                        $segments = explode('.', $key);
                        $value = $GLOBALS['__eaes_config'];
                        foreach ($segments as $s) { if (!is_array($value) || !array_key_exists($s, $value)) return $default; $value = $value[$s]; }
                        return $value;
                    }
                }
                $pdo = App\Core\Database::connection();
                $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name, role, created_at) VALUES (:u, :p, :f, :r, NOW())');
                $stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT), 'f' => 'Administrator', 'r' => 'owner']);

                header('Location: install.php?step=4');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Could not create the admin account: ' . $e->getMessage();
            }
        }
    }

    $errHtml = $errors ? "<div class='err'>" . implode('<br>', array_map('htmlspecialchars', $errors)) . "</div>" : '';
    $body = step_indicator(3) . $errHtml . "
    <form method='post'>
        <label>Admin Username</label><input name='username' required autocomplete='off'>
        <label>Admin Password</label><input type='password' name='password' required minlength='8'>
        <label>Confirm Password</label><input type='password' name='confirm' required minlength='8'>
        <button type='submit'>Create Admin Account →</button>
    </form>";
    render_layout('Step 3 of 4 — Administrator Account', $body);
    exit;
}

// ---------------------------------------------------------------------
// STEP 4 — Finish
// ---------------------------------------------------------------------
if ($step === 4) {
    @file_put_contents($lockFile, date('c') . "\n");
    $body = step_indicator(4) . "
    <div class='ok'>🎉 Installation complete! EAES is ready to use.</div>
    <p style='font-size:13px;color:#475569;line-height:1.6;'>
        For production use, remember to:
        <ul style='padding-left:18px;'>
            <li>Delete or restrict access to the <code>installer/</code> folder.</li>
            <li>Open the activation screen and upload the signed <code>license.lic</code> supplied by your software provider.</li>
            <li>Set <code>APP_DEBUG=false</code> and <code>FORCE_HTTPS=true</code> once HTTPS is configured.</li>
        </ul>
    </p>
    <a href='../adminlogin.php'><button type='button'>Go to Admin Login →</button></a>";
    render_layout('Step 4 of 4 — Done', $body);
    exit;
}

header('Location: install.php?step=1');
