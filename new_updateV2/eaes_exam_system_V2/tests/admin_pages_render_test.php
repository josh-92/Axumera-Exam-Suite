<?php

declare(strict_types=1);

/**
 * tests/admin_pages_render_test.php
 * ----------------------------------
 * Regression guard for the "hidden toolbar" bug class.
 *
 * Every admin page is rendered as a logged-in admin WITH NO QUERY STRING
 * (exactly how the nav links reach them) and we assert the page's key
 * action buttons/links are present. This catches the bug where a missing
 * ?view= silently produced an empty value and hid the whole Students
 * toolbar — and any future page whose UI is gated on an unguarded
 * $_GET/$_POST read.
 *
 * Pages are rendered against a scratch MySQL database (no real data is
 * touched) with the minimum schema each page queries at render time.
 *
 * Run: php tests/admin_pages_render_test.php
 */

use App\Core\Database;
use App\Repositories\QuestionBankRepository;
use App\Repositories\StudentRepository;

$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}\n";
    }
}

// ---------------------------------------------------------------------------
// 1. Scratch database (mirrors tests/student_auth_test.php conventions)
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_admin_pages_render_test';

try {
    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    echo "Cannot connect to MySQL ({$host}:{$port}): {$e->getMessage()}\n";
    exit(1);
}
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$server->exec("USE `{$dbName}`");

$server->exec("CREATE TABLE `admin_users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL, `full_name` VARCHAR(100) DEFAULT '',
    `role` VARCHAR(30) NOT NULL DEFAULT 'admin', `failed_attempts` INT(11) NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL, `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$server->exec("CREATE TABLE `students` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `full_name` VARCHAR(100) NOT NULL,
    `roll_number` INT(11) NOT NULL, `stream` VARCHAR(50) NOT NULL,
    `section` VARCHAR(10) NOT NULL, `password_hash` VARCHAR(255) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL, `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_roll_stream` (`roll_number`, `stream`),
    KEY `idx_students_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$server->exec("CREATE TABLE `exams` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `exam_name` VARCHAR(150) NOT NULL,
    `duration` INT(11) NOT NULL, `stream` VARCHAR(50) NOT NULL,
    `is_live` TINYINT(1) NOT NULL DEFAULT 0, `color_theme` VARCHAR(20) DEFAULT '#ff4a71',
    `json_filename` VARCHAR(255) DEFAULT 'questions.json',
    `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0, `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$server->exec("CREATE TABLE `exam_attempts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL, `exam_id` INT(11) NOT NULL,
    `answers` LONGTEXT NOT NULL DEFAULT '{}', `flags` LONGTEXT NOT NULL DEFAULT '{}',
    `score` INT(11) DEFAULT NULL, `total_questions` INT(11) DEFAULT NULL,
    `status` ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
    `started_at` DATETIME NOT NULL, `submitted_at` DATETIME DEFAULT NULL,
    `last_saved_at` DATETIME DEFAULT NULL, `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL, `violation_count` INT(11) NOT NULL DEFAULT 0,
    `integrity_status` ENUM('clean','flagged') NOT NULL DEFAULT 'clean', PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_student_exam` (`student_id`, `exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Seed: 1 admin, 2 active students + 1 archived, 1 exam, 1 submitted attempt.
$server->exec("INSERT INTO admin_users (username, password_hash, full_name, role)
               VALUES ('testadmin', 'x', 'Test Admin', 'admin')");
$server->exec("INSERT INTO students (full_name, roll_number, stream, section, password_hash) VALUES
               ('Render Active One', 101, 'Natural Science', 'A', 'x'),
               ('Render Active Two', 102, 'Social Science', 'B', NULL)");
$server->exec("INSERT INTO students (full_name, roll_number, stream, section, password_hash, deleted_at) VALUES
               ('Render Archived', 103, 'Natural Science', 'A', 'x', NOW())");
$server->exec("INSERT INTO exams (exam_name, duration, stream, is_live, color_theme, json_filename) VALUES
               ('Render Test Exam', 60, 'Natural Science', 0, '#ff4a71', 'questions.json')");
$server->exec("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, score, total_questions, status, started_at, integrity_status) VALUES
               (1, 1, '{}', '{}', 8, 10, 'submitted', NOW(), 'clean')");

// ---------------------------------------------------------------------------
// 2. Wire the repositories to the scratch DB + load the app shell.
//    SCRIPT_NAME is faked to license.php (exempt from the license gate) so the
//    test also passes on machines without an active .lic file.
// ---------------------------------------------------------------------------
chdir(dirname(__DIR__)); // .html admin pages include 'partials/…' relative to the project root
$_SERVER['SCRIPT_NAME'] = '/license.php';
$_SERVER['REQUEST_URI'] = '/license.php';
require_once __DIR__ . '/../app/bootstrap.php';

Database::useConnection($server);
StudentRepository::useConnection($server);
QuestionBankRepository::useConnection($server);

// ---------------------------------------------------------------------------
// 3. Render helper — logged-in admin, NO query string (the nav-click path).
// ---------------------------------------------------------------------------
function renderPage(string $file, string $scriptName, string $prependFile = ''): string
{
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['REQUEST_URI'] = $scriptName;
    $_GET = [];
    $_POST = [];
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = 'testadmin';
    ob_start();
    if ($prependFile !== '') {
        include $prependFile;
    }
    include $file;
    return (string) ob_get_clean();
}

function assertPage(string $label, string $file, string $scriptName, array $mustContain, array $opts = []): void
{
    $checkNav = $opts['nav'] ?? true;

    // The .html shells start with `require_once 'partials/admin_header.php'`;
    // in one PHP process that's a no-op after any earlier page included the
    // header (real HTTP is one request per process, so it always renders).
    // Rendering the header explicitly first reproduces the real response.
    $prepend = !empty($opts['headerFirst']) ? __DIR__ . '/../partials/admin_header.php' : '';
    $html = renderPage($file, $scriptName, $prepend);
    check("{$label}: renders ({$file})", is_string($html) && $html !== '');
    foreach ($mustContain as $needle) {
        check("{$label}: contains \"" . (strlen($needle) > 40 ? substr($needle, 0, 40) . '…' : $needle) . '"', str_contains($html, $needle));
    }
    // The whole admin nav must survive on every page (a missing link is hidden UI too).
    if ($checkNav) {
        $nav = ['adminpanel.php', 'admin_question_bank.php', 'analytics.php', 'admin_students.php', 'admin_settings.php', 'license.php'];
        foreach ($nav as $href) {
            check("{$label}: nav link to {$href}", str_contains($html, 'href="' . $href . '"'));
        }
    }
    // Direct guard for the bug class: no undefined-key warnings in the output.
    check("{$label}: no 'Undefined array key' warning", !str_contains($html, 'Undefined array key'));
    check("{$label}: no fatal error", !str_contains($html, 'Fatal error'));
}

echo "\nADMIN PAGE RENDER (no query string)\n";

// --- Exams dashboard (adminpanel.php) ---
assertPage('adminpanel', __DIR__ . '/../adminpanel.php', '/adminpanel.php', [
    'Create Exam',          // create-exam UI
    'exam-profile-card',    // exam card loop renders
    'Render Test Exam',     // seeded exam actually listed
]);

// --- Question Bank (admin_question_bank.php) ---
assertPage('question_bank', __DIR__ . '/../admin_question_bank.php', '/admin_question_bank.php', [
    'Question Bank',        // page title
    'qb-assign-exam',       // assignment dropdowns exist
    'qb-assignments-exam',
    'Render Test Exam',     // exam list JSON fed to the dropdowns
]);

// --- Students (admin_students.php) — the original regression ---
assertPage('students', __DIR__ . '/../admin_students.php', '/admin_students.php', [
    'Import CSV / Excel',   // toolbar buttons render with NO ?view=
    'Remove by List',
    'Add Student',
    'Active (2)',           // Active tab with live count
    'Archived (1)',         // Archived tab with live count
    'Render Active One',    // active student listed
]);

// --- Admin Settings (admin_settings.php) ---
assertPage('settings', __DIR__ . '/../admin_settings.php', '/admin_settings.php', [
    'Admin Accounts &amp; Settings',
    'Create Admin Account',
    'testadmin',            // seeded admin listed
    'badge-me',             // "you" badge on the current admin
]);

// --- Analytics (analytics.php) ---
assertPage('analytics', __DIR__ . '/../analytics.php', '/analytics.php', [
    'Exam Profiles',        // stat card
    'Score Distribution',   // chart section
    'Render Test Exam',     // per-exam table
]);

// --- License (license.php) — no header nav by design (pre-login gate) ---
// The page has two states (activated vs needs-activation); assert the parts
// that exist in both, plus whichever state the current machine is in.
assertPage('license', __DIR__ . '/../license.php', '/license.php', [
    'Software Activation',  // page title (both states)
    'activation-card',      // shared card shell
], ['nav' => false]);
$licenseHtml = renderPage(__DIR__ . '/../license.php', '/license.php');
check('license: renders either the activation form or the active notice',
    str_contains($licenseHtml, 'Activate') || str_contains($licenseHtml, 'License active'));

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
StudentRepository::useConnection(null);
QuestionBankRepository::useConnection(null);
Database::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
