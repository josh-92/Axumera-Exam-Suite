<?php

use App\Core\Env;

Env::load(dirname(__DIR__) . '/.env');

return [
    'app' => [
        'name'      => Env::get('APP_NAME', 'EAES Exam System'),
        'env'       => Env::get('APP_ENV', 'production'),
        'debug'     => filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
        'url'       => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'  => Env::get('APP_TIMEZONE', 'UTC'),
        'key'       => Env::get('APP_KEY', ''),
        'force_https' => filter_var(Env::get('FORCE_HTTPS', false), FILTER_VALIDATE_BOOLEAN),
    ],
    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => (int) Env::get('DB_PORT', 3306),
        'name'    => Env::get('DB_NAME', 'eaes_exam'),
        'user'    => Env::get('DB_USER', 'root'),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
    'security' => [
        'session_lifetime_minutes' => (int) Env::get('SESSION_LIFETIME_MINUTES', 180),
        'admin_max_login_attempts' => (int) Env::get('ADMIN_MAX_LOGIN_ATTEMPTS', 5),
        'admin_lockout_minutes'    => (int) Env::get('ADMIN_LOCKOUT_MINUTES', 15),
    ],
    'exam' => [
        'autosave_interval_seconds' => (int) Env::get('AUTOSAVE_INTERVAL_SECONDS', 15),
        'grace_period_seconds'      => (int) Env::get('GRACE_PERIOD_SECONDS', 10),
    ],
    'integrity' => [
        // Master switch for the lockdown/anti-cheat layer (fullscreen gate,
        // tab-switch detection, copy/paste + devtools deterrents).
        'enabled' => filter_var(Env::get('INTEGRITY_LOCKDOWN_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Violations (tab switch, fullscreen exit, ...) before the student
        // sees an on-screen warning. 0 disables warnings (still logs/counts).
        'warn_threshold' => (int) Env::get('INTEGRITY_WARN_THRESHOLD', 1),
        // Violations before the attempt is force auto-submitted. Set to 0 to
        // never auto-submit — violations are still recorded and flagged for
        // teacher review either way. Most schools should start with this
        // OFF (0) and review flagged attempts manually before enabling it.
        'auto_submit_threshold' => (int) Env::get('INTEGRITY_AUTO_SUBMIT_THRESHOLD', 0),
        // An attempt is marked 'flagged' for the admin dashboard once its
        // violation_count reaches this number, regardless of auto-submit.
        'flag_threshold' => (int) Env::get('INTEGRITY_FLAG_THRESHOLD', 3),
    ],
];
