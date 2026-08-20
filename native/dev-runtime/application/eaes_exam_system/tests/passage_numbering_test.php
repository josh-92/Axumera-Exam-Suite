<?php

/**
 * tests/passage_numbering_test.php
 * --------------------------------
 * Regression guard for the passage numbering collision.
 *
 * The documented exam JSON format numbers a passage's sub-questions 1..N
 * while the passage block itself is a separate row. QuestionRepository::
 * bulkInsert used to give the passage the running item counter (1), so a
 * passage followed by a question numbered 1 produced TWO rows with
 * question_number = 1. examportal.php keys its question map by
 * question_number, so the passage was silently dropped and the first
 * question rendered twice.
 *
 * Fix: passage rows are stored with unique negative question_numbers
 * (-N … -1 in file order) that can never collide with the teacher's
 * 1-based question numbering.
 *
 * Run: php tests/passage_numbering_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

use App\Core\Database;
use App\Repositories\QuestionRepository;

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
// Scratch database (mirrors tests/student_auth_test.php conventions)
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_exam_passage_numbering_test';

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

$server->exec("CREATE TABLE `exams` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `exam_name` VARCHAR(150) NOT NULL,
    `duration` INT(11) NOT NULL DEFAULT 60,
    `stream` VARCHAR(50) NOT NULL DEFAULT 'Natural Science',
    `is_live` TINYINT(1) NOT NULL DEFAULT 0,
    `color_theme` VARCHAR(20) DEFAULT '#ff4a71',
    `json_filename` VARCHAR(255) DEFAULT 'questions.json',
    `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0,
    `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$server->exec("CREATE TABLE `questions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `exam_id` INT(11) NULL DEFAULT NULL,
    `question_number` INT(11) NULL DEFAULT NULL,
    `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
    `paragraph_text` TEXT DEFAULT NULL,
    `question_text` TEXT NOT NULL,
    `option_a` TEXT NOT NULL,
    `option_b` TEXT NOT NULL,
    `option_c` TEXT NOT NULL,
    `option_d` TEXT NOT NULL,
    `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

Database::useConnection($server);

// ---------------------------------------------------------------------------
// Helper: the exact row shapes ExamImportService produces
// ---------------------------------------------------------------------------
function q(int $num, string $answer = 'a'): array
{
    return [
        'type' => 'question',
        'question_number' => $num,
        'paragraph_text' => '',
        'question_text' => "Question {$num}",
        'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
        'correct_answer' => $answer,
    ];
}

function p(string $id): array
{
    return ['type' => 'passage', 'id' => $id, 'content' => "Passage {$id} content"];
}

// ---------------------------------------------------------------------------
// 1. Documented format: passage + sub-questions numbered 1..N
// ---------------------------------------------------------------------------
echo "\nSINGLE PASSAGE (documented JSON shape)\n";

$server->exec("INSERT INTO exams (exam_name) VALUES ('Passage Exam 1')");
$examId = (int) $server->lastInsertId();
QuestionRepository::bulkInsert($examId, [p('I'), q(1, 'b'), q(2, 'c'), q(3, 'a')]);

$rows = QuestionRepository::forExam($examId);
check('forExam returns every row (passage + 3 questions)', count($rows) === 4);

$numbers = array_map('intval', array_column($rows, 'question_number'));
check('no question_number collision (4 rows, 4 distinct numbers)', count($numbers) === count(array_unique($numbers)));

$passage = array_values(array_filter($rows, fn ($r) => (int) $r['is_passage'] === 1));
check('the passage row keeps is_passage = 1', count($passage) === 1);
check('passage gets a unique negative slot', (int) $passage[0]['question_number'] < 0);

$questionNumbers = array_values(array_filter($numbers, fn ($n) => $n > 0));
sort($questionNumbers);
check('sub-questions keep the teacher\'s 1..N numbering', $questionNumbers === [1, 2, 3]);

// ---------------------------------------------------------------------------
// 2. Multiple passages: file order preserved in the stored numbers
// ---------------------------------------------------------------------------
echo "\nMULTI PASSAGE\n";

$server->exec("INSERT INTO exams (exam_name) VALUES ('Passage Exam 2')");
$examId2 = (int) $server->lastInsertId();
QuestionRepository::bulkInsert($examId2, [p('I'), q(1, 'a'), q(2, 'b'), p('II'), q(3, 'c'), q(4, 'd')]);

$rows2 = QuestionRepository::forExam($examId2);
$passages2 = array_values(array_filter($rows2, fn ($r) => (int) $r['is_passage'] === 1));
check('two passage rows stored', count($passages2) === 2);

$pNums = array_map('intval', array_column($passages2, 'question_number'));
sort($pNums);
check('passage slots are unique and negative', $pNums === [-2, -1]);

$all2 = array_map('intval', array_column($rows2, 'question_number'));
check('no collisions across passages + questions', count($all2) === count(array_unique($all2)));
check('DB order keeps the first passage first (file order preserved)', (int) $rows2[0]['question_number'] === -2);

// ---------------------------------------------------------------------------
// 3. No passage: plain numbered questions are untouched
// ---------------------------------------------------------------------------
echo "\nNO PASSAGE (backward compatible)\n";

$server->exec("INSERT INTO exams (exam_name) VALUES ('Plain Exam')");
$examId3 = (int) $server->lastInsertId();
QuestionRepository::bulkInsert($examId3, [q(1, 'a'), q(2, 'b'), q(3, 'c')]);

$rows3 = QuestionRepository::forExam($examId3);
$nums3 = array_map('intval', array_column($rows3, 'question_number'));
check('plain questions keep 1..N untouched', $nums3 === [1, 2, 3]);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
Database::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
