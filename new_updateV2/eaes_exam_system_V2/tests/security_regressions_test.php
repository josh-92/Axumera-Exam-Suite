<?php

/**
 * tests/security_regressions_test.php
 * ------------------------------------
 * Regression guards for the adversarial security audit (2026-08-09).
 *
 * DB-backed portion runs against a scratch MySQL database (created and
 * dropped by this script, like the other integration tests):
 *
 *   php tests/security_regressions_test.php
 *
 * Covers the fixes made after the audit found:
 *   - autosave.php accepted answers indefinitely after the exam deadline
 *     (unlimited extra time) and submit_exam.php could never classify a
 *     late attempt as auto_submitted (dead branch caused by a clamped 0).
 *   - markSubmitted() blindly overwrote an already-finalized attempt, so
 *     racing duplicate submissions could clobber the first result.
 *   - installer/install.php?force=1 bypassed the installed.lock guard and
 *     let an anonymous visitor re-run the installer.
 *   - exam.js / exam_session.js interpolated teacher-authored question
 *     text with innerHTML (stored XSS via imported question files).
 *
 * The generated-exam / AI API regression guards were removed together with
 * the AI feature set (see docs/CHANGELOG.md, 2026-08-10).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

// Mirror the b_k5t() config helper bootstrap defines (this test loads only
// the autoloader, so the helper is not otherwise available).
if (!function_exists('b_k5t')) {
    $GLOBALS['__eaes_config'] = require __DIR__ . '/../app/config.php';
    function b_k5t(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $node = $GLOBALS['__eaes_config'];
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}

// The app calls date_default_timezone_set(APP_TIMEZONE) in bootstrap; mirror
// that here so strtotime() of DB wall-clock values (the deadline math) lines
// up with time(), exactly as it does in the running app.
$appTz = 'UTC';
if (preg_match('/^APP_TIMEZONE=(.+)$/m', (string) @file_get_contents(__DIR__ . '/../.env'), $m)) {
    $appTz = trim($m[1]);
}
date_default_timezone_set($appTz);

use App\Core\Database;
use App\Repositories\AttemptRepository;

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

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Scratch database
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_security_regression_test';

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

$server->exec(
    "CREATE TABLE `students` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `full_name` VARCHAR(100) NOT NULL,
        `roll_number` INT(11) NOT NULL,
        `stream` VARCHAR(50) NOT NULL,
        `section` VARCHAR(10) NOT NULL,
        `password_hash` VARCHAR(255) NULL,
        `last_login_at` DATETIME NULL,
        `deleted_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_roll_stream` (`roll_number`, `stream`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `exams` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `exam_name` VARCHAR(150) NOT NULL,
        `duration` INT(11) NOT NULL,
        `stream` VARCHAR(50) NOT NULL,
        `is_live` TINYINT(1) NOT NULL DEFAULT 0,
        `color_theme` VARCHAR(20) DEFAULT '#ff4a71',
        `json_filename` VARCHAR(255) DEFAULT 'questions.json',
        `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0,
        `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `questions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `exam_id` INT(11) NULL,
        `question_number` INT(11) NULL,
        `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
        `paragraph_text` TEXT NULL,
        `question_text` TEXT NOT NULL,
        `option_a` TEXT NOT NULL,
        `option_b` TEXT NOT NULL,
        `option_c` TEXT NOT NULL,
        `option_d` TEXT NOT NULL,
        `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
        `question` TEXT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'MCQ',
        `difficulty` VARCHAR(20) NULL,
        `topic` VARCHAR(255) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_exam_id` (`exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `exam_attempts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `student_id` INT(11) NOT NULL,
        `exam_id` INT(11) NOT NULL,
        `answers` LONGTEXT NOT NULL,
        `flags` LONGTEXT NOT NULL,
        `score` INT(11) NULL,
        `total_questions` INT(11) NULL,
        `status` ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
        `started_at` DATETIME NOT NULL,
        `submitted_at` DATETIME NULL,
        `last_saved_at` DATETIME NULL,
        `ip_address` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `violation_count` INT(11) NOT NULL DEFAULT 0,
        `integrity_status` ENUM('clean','flagged') NOT NULL DEFAULT 'clean',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_student_exam` (`student_id`, `exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `login_attempts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL,
        `ip_address` VARCHAR(45) NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_username_time` (`username`, `attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

Database::useConnection($server);

// Seed: one exam (60s), one question (answer b), two students.
$server->exec("INSERT INTO exams (exam_name, duration, stream) VALUES ('Deadline Exam', 60, 'Natural Science')");
$examId = (int) $server->lastInsertId();
$server->exec(
    "INSERT INTO questions (exam_id, question_number, question_text, option_a, option_b, option_c, option_d, correct_answer)
     VALUES ({$examId}, 1, '2 + 2 = ?', '3', '4', '5', '6', 'b')"
);
$server->exec("INSERT INTO students (full_name, roll_number, stream, section) VALUES ('A', 1, 'Natural Science', 'A')");
$s1 = (int) $server->lastInsertId();
$server->exec("INSERT INTO students (full_name, roll_number, stream, section) VALUES ('B', 2, 'Natural Science', 'A')");
$s2 = (int) $server->lastInsertId();

// ---------------------------------------------------------------------------
// A. Exam deadline enforcement (autosave.php path)
// ---------------------------------------------------------------------------
echo "\nA. DEADLINE ENFORCEMENT (autosaveIfWithinDeadline)\n";

$server->exec(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at)
     VALUES ({$s1}, {$examId}, '{}', '{}', 'in_progress', DATE_SUB(NOW(), INTERVAL 30 SECOND), NOW())"
);
$attemptId = (int) $server->lastInsertId();

$r = AttemptRepository::autosaveIfWithinDeadline($s1, $examId, [1 => 'b'], [], 60, 10);
check('autosave within deadline saves the payload', $r['saved'] === true && $r['expired'] === false);
// Bound generously: the scratch DB and PHP can disagree about the clock by a
// few seconds (the same skew the question-bank test guards against), so only
// assert it is a sane, non-negative value within the window.
check('seconds_remaining reported inside deadline', $r['seconds_remaining'] >= 0 && $r['seconds_remaining'] <= 60);
$row = fetchOne($server, 'SELECT answers, status FROM exam_attempts WHERE id = :id', ['id' => $attemptId]);
check('answers persisted', $row !== null && $row['answers'] === '{"1":"b"}' && $row['status'] === 'in_progress');

// Now the deadline passes (attempt started 2 hours ago on a 60s exam) and the
// student tries to CHANGE an answer — the late payload must be refused and the
// attempt finalized from the answers ALREADY on the server.
$server->exec("UPDATE exam_attempts SET started_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = {$attemptId}");
$r = AttemptRepository::autosaveIfWithinDeadline($s1, $examId, [1 => 'a'], [], 60, 10);
check('autosave past the deadline is refused + flagged expired', $r['saved'] === false && $r['expired'] === true);
check('expired attempt scored from SAVED answers, not the late payload', $r['score'] === 1 && $r['total'] === 1);
$row = fetchOne($server, 'SELECT answers, status, score FROM exam_attempts WHERE id = :id', ['id' => $attemptId]);
check('attempt auto-submitted with the original answer', $row !== null && $row['status'] === 'auto_submitted' && $row['answers'] === '{"1":"b"}' && (int) $row['score'] === 1);

$r = AttemptRepository::autosaveIfWithinDeadline($s1, $examId, [1 => 'a'], [], 60, 10);
check('a second late autosave cannot resurrect the attempt', $r['saved'] === false && $r['expired'] === false);
$row = fetchOne($server, 'SELECT answers, status FROM exam_attempts WHERE id = :id', ['id' => $attemptId]);
check('answers untouched after the second attempt', $row !== null && $row['answers'] === '{"1":"b"}' && $row['status'] === 'auto_submitted');

// ---------------------------------------------------------------------------
// B. Duplicate-submit hardening (markSubmitted is conditional)
// ---------------------------------------------------------------------------
echo "\nB. DUPLICATE SUBMIT HARDENING\n";

$server->exec(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at)
     VALUES ({$s2}, {$examId}, '{\"1\":\"b\"}', '{}', 'in_progress', NOW())"
);
$attempt2 = (int) $server->lastInsertId();
check('first markSubmitted applies', AttemptRepository::markSubmitted($attempt2, 1, 1, 'submitted') === true);
check('second markSubmitted refuses (already finalized)', AttemptRepository::markSubmitted($attempt2, 0, 1, 'submitted') === false);
$row = fetchOne($server, 'SELECT score, status FROM exam_attempts WHERE id = :id', ['id' => $attempt2]);
check('first result preserved (score not clobbered)', $row !== null && (int) $row['score'] === 1 && $row['status'] === 'submitted');

// ---------------------------------------------------------------------------
// C. Static source guards for the file-level fixes
// ---------------------------------------------------------------------------
echo "\nC. STATIC SOURCE GUARDS\n";

// The AI feature set (and its API entry points) was removed on 2026-08-10 —
// guard that none of the files come back silently.
$aiFiles = ['api_blueprints.php', 'api_curriculum.php', 'api_exam_attempt.php', 'api_generator.php', 'api_recommendations.php', 'admin_blueprint_builder.php', 'admin_generated_exams.php', 'admin_curriculum_intelligence.php', 'admin_exam_recommendations.php'];
$stillThere = [];
foreach ($aiFiles as $f) {
    if (is_file(__DIR__ . '/../' . $f)) {
        $stillThere[] = $f;
    }
}
check('AI API/admin entry points removed from the web root', $stillThere === []);

$installer = (string) file_get_contents(__DIR__ . '/../installer/install.php');
check('installer no longer accepts the ?force= re-run bypass', !str_contains($installer, "\$_GET['force']"));

$examJs = (string) file_get_contents(__DIR__ . '/../assets/js/exam.js');
check('exam.js escapes question text before innerHTML', str_contains($examJs, 'questionText.innerHTML = esc(q.text);'));
check('exam.js escapes option text', str_contains($examJs, '+ \'. \' + esc(value);'));

check('no static .html shells remain in the web root', glob(__DIR__ . '/../*.html') === [] || glob(__DIR__ . '/../*.html') === false);

// ---------------------------------------------------------------------------
// D. Per-IP + per-account rate limiting (login_attempts ledger)
// ---------------------------------------------------------------------------
echo "\nD. RATE LIMITING (per-IP + per-account)\n";

use App\Core\RateLimiter;

$ip = '203.0.113.55';
for ($i = 0; $i < 19; $i++) {
    $server->exec("INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES ('student:1', '{$ip}', 0, NOW())");
}
check('IP not locked below the 20-attempt threshold', !RateLimiter::ipLocked($ip));
$server->exec("INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES ('student:1', '{$ip}', 0, NOW())");
check('IP locked at the 20-attempt threshold', RateLimiter::ipLocked($ip));
$secs = RateLimiter::ipLockSeconds($ip);
check('IP lock reports remaining seconds', $secs > 0 && $secs <= 15 * 60);
RateLimiter::clearIpFailures($ip);
check('successful login clears the IP ledger', !RateLimiter::ipLocked($ip) && RateLimiter::ipFailureCount($ip) === 0);

$key = 'pwdreset:7';
for ($i = 0; $i < 4; $i++) {
    RateLimiter::recordAccountFailure($key);
}
check('account not locked below the 5-attempt threshold', !RateLimiter::accountLocked($key));
RateLimiter::recordAccountFailure($key);
check('account locked at the 5-attempt threshold', RateLimiter::accountLocked($key));
check('account lock reports remaining seconds', RateLimiter::accountLockSeconds($key) > 0);
RateLimiter::clearAccountFailures($key);
check('successful verification clears the account ledger', !RateLimiter::accountLocked($key));

// Static guard: the installer no longer ships the 100-attempt admin default.
$installer = (string) file_get_contents(__DIR__ . '/../installer/install.php');
check('installer .env template uses a sane admin lockout threshold', !str_contains($installer, 'ADMIN_MAX_LOGIN_ATTEMPTS=100'));
check('installer .env template includes the IP throttle keys', str_contains($installer, 'IP_MAX_LOGIN_ATTEMPTS=20') && str_contains($installer, 'IP_LOCKOUT_MINUTES=15'));

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------
Database::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
