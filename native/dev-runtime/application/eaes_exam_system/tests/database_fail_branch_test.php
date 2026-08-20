<?php
/**
 * Branch test for the Database::fail() DB-outage handler.
 * Run twice:
 *   php tests/database_fail_branch_test.php api
 *   php tests/database_fail_branch_test.php page
 * Requires no MySQL — fail() is invoked directly via reflection and exits
 * before any DB work; a global b_k5t() stub satisfies the config lookup.
 */
require_once __DIR__ . '/../app/Autoload.php';

if (!function_exists('b_k5t')) {
    function b_k5t(string $key, mixed $default = null): mixed { return $default; }
}

$scenario = $argv[1] ?? 'api';

if ($scenario === 'api') {
    $_SERVER['SCRIPT_NAME'] = '/eaes_exam_system_protected/api_questions.php';
    $_SERVER['HTTP_ACCEPT'] = '*/*';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
} else {
    $_SERVER['SCRIPT_NAME'] = '/eaes_exam_system_protected/adminpanel.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
}

$method = new ReflectionMethod(App\Core\Database::class, 'fail');
$method->invoke(null);
// never reached — fail() always exits
