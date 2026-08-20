<?php

declare(strict_types=1);

require_once __DIR__ . '/Autoload.php';

use App\Core\License;
use App\Core\Session;

// ---- Global config accessor -------------------------------------------------
$GLOBALS['__eaes_config'] = require __DIR__ . '/config.php';

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $GLOBALS['__eaes_config'];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

// ---- Error handling -----------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');

date_default_timezone_set((string) config('app.timezone', 'UTC'));

set_exception_handler(function (Throwable $e): void {
    \App\Core\Logger::error($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (config('app.debug')) {
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    } else {
        echo '<h1>Something went wrong</h1><p>The error has been logged. Please try again shortly.</p>';
    }
    exit;
});

// ---- Security headers -----------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self';");
    if (config('app.force_https')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---- Session ----------------------------------------------------------------
Session::start();

// ---- Installer guard ---------------------------------------------------------
// If the app has never been installed (no lock file), send the operator to
// the setup wizard instead of a raw DB error. install.php itself never
// includes this bootstrap file, so no redirect loop is possible here.
$installLock = __DIR__ . '/../storage/installed.lock';
if (!is_file($installLock)) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    header('Location: ' . $base . '/installer/install.php');
    exit;
}

// ---- License gate -----------------------------------------------------------
// Only the activation screen (and logout cleanup) remain reachable until a
// signed, machine-bound license has been installed. This runs before any exam
// repository or business logic is loaded.
$currentScript = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
$activationAllowedScripts = ['license.php', 'logout.php', 'health.php'];
if (!in_array($currentScript, $activationAllowedScripts, true) && !License::isValid()) {
    $basePath = '/' . trim(basename(dirname(__DIR__)), '/') . '/';
    header('Location: ' . $basePath . 'license.php');
    exit;
}
