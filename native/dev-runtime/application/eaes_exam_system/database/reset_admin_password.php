<?php

/**
 * database/reset_admin_password.php — recover a forgotten / locked admin
 * ----------------------------------------------------------------------
 * Run this from the project root ON THE SERVER (command line only, never
 * from the web). It is the recovery path for the admin login: no email is
 * configured anywhere in the system, so whoever has terminal access to
 * the machine resets the account directly in the database.
 *
 * Usage:
 *   php database/reset_admin_password.php                       # list admin accounts
 *   php database/reset_admin_password.php admin                 # random temp password (printed once)
 *   php database/reset_admin_password.php admin 'NewPass123!'   # set an explicit password (min 8 chars)
 *
 * The reset also clears any failed-attempt lockout on the account.
 */

require_once __DIR__ . '/../app/Autoload.php';

$GLOBALS['__eaes_config'] = require __DIR__ . '/../app/config.php';
if (!function_exists('B_k5t')) {
    function B_k5T(string $CmSTM, mixed $Ctd3t = null): mixed
    {
        $keys = explode('.', $CmSTM);
        $value = $GLOBALS['__eaes_config'];
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $Ctd3t;
            }
            $value = $value[$key];
        }
        return $value;
    }
}

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line, e.g.:\n  php database/reset_admin_password.php admin\n");
}

use App\Repositories\AdminRepository;

$admins = AdminRepository::all();

// ---- mode 1: no username → list accounts ----
$username = trim((string) ($argv[1] ?? ''));
if ($username === '') {
    if (!$admins) {
        echo "No admin accounts found.\n";
        exit(1);
    }
    echo "Admin accounts (" . count($admins) . "):\n";
    foreach ($admins as $a) {
        $locked = $a['locked_until'] !== null && strtotime((string) $a['locked_until']) > time();
        printf(
            "  %-20s role=%-8s name=%-20s last_login=%-22s %s\n",
            $a['username'],
            $a['role'] ?: '-',
            $a['full_name'] ?: '-',
            $a['last_login_at'] ?: '-',
            $locked ? 'LOCKED' : ($a['failed_attempts'] > 0 ? "{$a['failed_attempts']} failed attempt(s)" : 'ok')
        );
    }
    echo "\nTo reset one:  php database/reset_admin_password.php <username>\n";
    exit(0);
}

// ---- find the account ----
$admin = AdminRepository::findByUsername($username);
if (!$admin) {
    echo "No admin account named '{$username}' exists.\n";
    echo "Existing accounts: " . implode(', ', array_column($admins, 'username')) . "\n";
    exit(1);
}

$wasLocked = (int) $admin['failed_attempts'] > 0 || ($admin['locked_until'] !== null && strtotime((string) $admin['locked_until']) > time());

// ---- mode 2: explicit password argument ----
$password = (string) ($argv[2] ?? '');
if ($password !== '') {
    if (strlen($password) < 8) {
        echo "The new password must be at least 8 characters.\n";
        exit(1);
    }
    AdminRepository::resetPassword((int) $admin['id'], $password);
    echo "Password for '{$username}' has been reset" . ($wasLocked ? ' (lockout cleared)' : '') . ".\n";
    echo "Log in at adminlogin.php with that password.\n";
    \App\Core\Logger::audit('system', $username, 'admin_password_reset_cli', ['source' => 'cli']);
    exit(0);
}

// ---- mode 3: generate a random temporary password, print it once ----
$temp = bin2hex(random_bytes(8)); // 16 hex chars
AdminRepository::resetPassword((int) $admin['id'], $temp);
echo "Password for '{$username}' has been reset" . ($wasLocked ? ' (lockout cleared)' : '') . ".\n";
echo "\n  Temporary password: {$temp}\n\n";
echo "This is shown once. Log in at adminlogin.php and change it afterwards.\n";
\App\Core\Logger::audit('system', $username, 'admin_password_reset_cli', ['source' => 'cli']);
exit(0);
