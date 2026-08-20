<?php

/**
 * tests/exam_integrity_simulation_test.php
 * ----------------------------------------
 * Exam-integrity attack simulation against the scratch database. Each
 * scenario mimics what a hostile student (or scripted client) would do:
 *
 *   A. Late-save attack   — answers accepted after the timer expires
 *                           (must be refused; attempt auto-submitted).
 *   B. Answer injection   — junk question keys / values smuggled into the
 *                           answers payload (must be sanitized at the write
 *                           path and never affect grading).
 *   C. Cross-student      — student B touching student A's attempt
 *                           (session-scoped lookups must isolate them).
 *   D. Duplicate submit   — racing submissions must not clobber the first
 *                           finalized result.
 *   E. Shuffle integrity  — per-student order is fixed after first render
 *                           (a reopened tab gets the SAME order), passage
 *                           questions stay with their passage, and two
 *                           students do not share one order.
 *
 * Run: php tests/exam_integrity_simulation_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

// Match the app's timezone so PHP time() and the DB wall clock agree (the
// same skew guard used by the security-regression suite).
$appTz = 'UTC';
if (preg_match('/^APP_TIMEZONE=(.+)$/m', (string) @file_get_contents(__DIR__ . '/../.env'), $m)) {
    $appTz = trim($m[1]);
}
date_default_timezone_set($appTz);

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

use App\Core\Database;
use App\Repositories\AttemptRepository;
use App\Services\GradingService;
use App\Services\QuestionShuffleService;

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
// Scratch DB
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_integrity_sim_test';

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
    "CREATE TABLE `exams` (
        `id` INT(11) NOT NULL AUTO_INCREMENT, `exam_name` VARCHAR(150) NOT NULL,
        `duration` INT(11) NOT NULL, `stream` VARCHAR(50) NOT NULL,
        `is_live` TINYINT(1) NOT NULL DEFAULT 0, `color_theme` VARCHAR(20) DEFAULT '#ff4a71',
        `json_filename` VARCHAR(255) DEFAULT 'questions.json',
        `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0, `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `students` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `full_name` VARCHAR(100) NOT NULL, `roll_number` INT(11) NOT NULL,
        `stream` VARCHAR(50) NOT NULL, `section` VARCHAR(10) NOT NULL,
        `password_hash` VARCHAR(255) NULL, `last_login_at` DATETIME NULL, `deleted_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_roll_stream` (`roll_number`, `stream`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `questions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `exam_id` INT(11) NULL, `question_number` INT(11) NULL,
        `is_passage` TINYINT(1) NOT NULL DEFAULT 0, `paragraph_text` TEXT NULL,
        `question_text` TEXT NOT NULL,
        `option_a` TEXT NOT NULL, `option_b` TEXT NOT NULL,
        `option_c` TEXT NOT NULL, `option_d` TEXT NOT NULL,
        `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
        `question` TEXT NULL, `type` VARCHAR(50) NOT NULL DEFAULT 'MCQ',
        `difficulty` VARCHAR(20) NULL, `topic` VARCHAR(255) NULL,
        PRIMARY KEY (`id`), KEY `idx_exam_id` (`exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `exam_attempts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `student_id` INT(11) NOT NULL, `exam_id` INT(11) NOT NULL,
        `answers` LONGTEXT NOT NULL, `flags` LONGTEXT NOT NULL,
        `score` INT(11) NULL, `total_questions` INT(11) NULL,
        `status` ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
        `started_at` DATETIME NOT NULL, `submitted_at` DATETIME NULL,
        `last_saved_at` DATETIME NULL, `ip_address` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL, `violation_count` INT(11) NOT NULL DEFAULT 0,
        `integrity_status` ENUM('clean','flagged') NOT NULL DEFAULT 'clean',
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_student_exam` (`student_id`, `exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$server->exec(
    "CREATE TABLE `exam_question_shuffles` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `student_id` INT(11) NOT NULL, `exam_id` INT(11) NOT NULL,
        `attempt_id` INT(11) NOT NULL,
        `question_order` LONGTEXT NOT NULL,
        `choice_order` LONGTEXT NOT NULL DEFAULT '{}',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_student_exam_shuffle` (`student_id`, `exam_id`),
        KEY `idx_exam_id` (`exam_id`), KEY `idx_attempt_id` (`attempt_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

Database::useConnection($server);

// Seed: one exam (120s), five questions (1..5; answers a,a,b,c,d), where
// question 2 is the "continuation" of passage question 1.
$server->exec("INSERT INTO exams (exam_name, duration, stream, shuffle_questions, shuffle_choices) VALUES ('Integrity Sim Exam', 120, 'Natural Science', 1, 1)");
$examId = (int) $server->lastInsertId();
$seed = [
    [1, 1, 'Passage text here…', 'Read the passage, then answer.', 'a', 'x', 'y', 'z', 'a'],
    [2, 0, null, 'Which follows the passage?', 'a', 'x', 'y', 'z', 'b'],
    [3, 0, null, '2 + 2 = ?', '3', '4', '5', '6', 'b'],
    [4, 0, null, 'Capital of Ethiopia?', 'Addis Ababa', 'Nairobi', 'Cairo', 'Kampala', 'a'],
    [5, 0, null, '5 x 5 = ?', '20', '25', '30', '35', 'b'],
];
foreach ($seed as $row) {
    [$num, $isPassage, $para, $text, $a, $b, $c, $d, $correct] = $row;
    $server->prepare(
        "INSERT INTO questions (exam_id, question_number, is_passage, paragraph_text, question_text, option_a, option_b, option_c, option_d, correct_answer)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$examId, $num, $isPassage, $para, $text, $a, $b, $c, $d, $correct]);
}
$server->exec("INSERT INTO students (full_name, roll_number, stream, section) VALUES ('Alice', 1, 'Natural Science', 'A')");
$sA = (int) $server->lastInsertId();
$server->exec("INSERT INTO students (full_name, roll_number, stream, section) VALUES ('Bob', 2, 'Natural Science', 'A')");
$sB = (int) $server->lastInsertId();

$questions = $server->query("SELECT * FROM questions WHERE exam_id = {$examId} ORDER BY question_number")->fetchAll();

// ---------------------------------------------------------------------------
echo "\nA. LATE-SAVE ATTACK\n";
// ---------------------------------------------------------------------------
$server->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at) VALUES (?, ?, '{}', '{}', 'in_progress', NOW(), NOW())")->execute([$sA, $examId]);
$attemptA = (int) $server->lastInsertId();

// q2 (passage continuation) and q3 both answered correctly. Passage rows
// (is_passage=1) are not graded; the exam grades q2..q5 -> total 4.
$r = AttemptRepository::autosaveIfWithinDeadline($sA, $examId, [2 => 'b', 3 => 'b'], [], 120, 10);
check('within deadline: answers saved', $r['saved'] === true && $r['expired'] === false);

$server->exec("UPDATE exam_attempts SET started_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = {$attemptA}");
$r = AttemptRepository::autosaveIfWithinDeadline($sA, $examId, [2 => 'a'], [], 120, 10);
check('past deadline: late payload refused', $r['saved'] === false && $r['expired'] === true);
$row = fetchOne($server, 'SELECT answers, status, score, total_questions FROM exam_attempts WHERE id = ?', [$attemptA]);
check('auto-submitted from SAVED answers (2/4, not the late payload)', $row !== null && $row['status'] === 'auto_submitted' && (int) $row['score'] === 2 && (int) $row['total_questions'] === 4 && $row['answers'] === '{"2":"b","3":"b"}');

// ---------------------------------------------------------------------------
echo "\nB. ANSWER INJECTION (junk keys/values)\n";
// ---------------------------------------------------------------------------
$server->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at) VALUES (?, ?, '{}', '{}', 'in_progress', NOW(), NOW())")->execute([$sB, $examId]);
$attemptB = (int) $server->lastInsertId();

$junk = [
    3 => 'b',          // real question, correct
    4 => 'a',          // real question, correct
    999 => 'a',        // nonexistent question
    -7 => 'c',         // negative key
    0 => 'd',          // zero key
    5 => 'e',          // invalid option letter
    5 => '  b  ',      // valid after trim (overwrites the invalid value)
    'x' => 'a',        // non-numeric key
];
$r = AttemptRepository::autosaveIfWithinDeadline($sB, $examId, $junk, [], 120, 10);
check('injected payload still saved (within deadline)', $r['saved'] === true);
$stored = json_decode((string) fetchOne($server, 'SELECT answers FROM exam_attempts WHERE id = ?', [$attemptB])['answers'], true);
// The write path keeps every POSITIVE key with a valid letter (999 included),
// but grading only consults questions that exist in the exam — so unknown
// keys can never inflate a score.
ksort($stored);
check('stored answers contain only sanitized positive keys/values', $stored === [3 => 'b', 4 => 'a', 5 => 'b', 999 => 'a']);
$graded = GradingService::grade($examId, $stored);
check('grading counts only real questions (3/4)', (int) $graded['score'] === 3 && (int) $graded['total'] === 4);
$polluted = GradingService::grade($examId, $stored + [999999 => 'a', -7 => 'c', 0 => 'd', 'x' => 'a']);
check('even a polluted array cannot inflate the score', (int) $polluted['score'] === 3 && (int) $polluted['total'] === 4);

// ---------------------------------------------------------------------------
echo "\nC. CROSS-STUDENT ISOLATION\n";
// ---------------------------------------------------------------------------
$r = AttemptRepository::autosaveIfWithinDeadline($sA, $examId, [3 => 'a'], [], 120, 10);
check('student A writing again is refused (already finalized)', $r['saved'] === false);
$rowA = fetchOne($server, 'SELECT answers FROM exam_attempts WHERE id = ?', [$attemptA]);
$rowB = fetchOne($server, 'SELECT answers FROM exam_attempts WHERE id = ?', [$attemptB]);
check('A and B answers are isolated rows', $rowA !== null && $rowB !== null && $rowA['answers'] !== $rowB['answers']);

// ---------------------------------------------------------------------------
echo "\nD. DUPLICATE-SUBMIT RACE\n";
// ---------------------------------------------------------------------------
$server->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at) VALUES (?, ?, ?, '{}', 'in_progress', NOW(), NOW())")
    ->execute([$sA, $examId + 1, json_encode([3 => 'b'])]);
// (exam 2 doesn't exist in `exams`; only the attempt row is exercised here)
$raceAttempt = (int) $server->lastInsertId();
$first = AttemptRepository::markSubmitted($raceAttempt, 1, 1, 'submitted');
$second = AttemptRepository::markSubmitted($raceAttempt, 99, 99, 'auto_submitted');
check('first submit wins, second is a no-op', $first === true && $second === false);
$row = fetchOne($server, 'SELECT score, total_questions, status FROM exam_attempts WHERE id = ?', [$raceAttempt]);
check('racing submit cannot clobber score/status', $row !== null && (int) $row['score'] === 1 && (int) $row['total_questions'] === 1 && $row['status'] === 'submitted');

// ---------------------------------------------------------------------------
echo "\nE. SHUFFLE INTEGRITY\n";
// ---------------------------------------------------------------------------
$examMeta = fetchOne($server, 'SELECT * FROM exams WHERE id = ?', [$examId]);
$server->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at) VALUES (?, ?, '{}', '{}', 'in_progress', NOW(), NOW())")->execute([$sA, $examId + 2]);
$shuffleAttempt = (int) $server->lastInsertId();

$firstOrder = QuestionShuffleService::getOrCreateForStudent($sA, $examId, $shuffleAttempt, $examMeta, $questions);
$secondOrder = QuestionShuffleService::getOrCreateForStudent($sA, $examId, $shuffleAttempt, $examMeta, $questions);
check('reopened tab receives the IDENTICAL question order', $firstOrder['question_order'] === $secondOrder['question_order']);
check('reopened tab receives the IDENTICAL choice order', $firstOrder['choice_order'] === $secondOrder['choice_order']);
check('shuffled order contains every question exactly once', count($firstOrder['question_order']) === 5 && array_unique($firstOrder['question_order']) === array_values($firstOrder['question_order']));

$q1 = array_search(1, $firstOrder['question_order'], true);
$q2 = array_search(2, $firstOrder['question_order'], true);
check('passage (1) stays adjacent to its continuation (2)', abs($q1 - $q2) === 1);

// A second student must NOT inherit the first student's order.
$server->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at) VALUES (?, ?, '{}', '{}', 'in_progress', NOW(), NOW())")->execute([$sB, $examId + 2]);
$shuffleAttemptB = (int) $server->lastInsertId();
$orderB = QuestionShuffleService::getOrCreateForStudent($sB, $examId, $shuffleAttemptB, $examMeta, $questions);
$same = $orderB['question_order'] === $firstOrder['question_order'] && $orderB['choice_order'] === $firstOrder['choice_order'];
check('two students do not share one fixed order', !$same);

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------
Database::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
