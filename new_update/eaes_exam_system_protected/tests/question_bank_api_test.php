<?php

/**
 * Question Bank integration test
 * ------------------------------
 * Runs against a scratch MySQL database (created + dropped by this script):
 *
 *   php tests/question_bank_api_test.php
 *
 * It applies the REAL migration file (statement by statement, so the test
 * doubles as a migration smoke test), seeds one admin + two exams (one live),
 * then exercises every QuestionBankRepository workflow:
 *   - create/edit with validation rules
 *   - listing, search (incl. LIKE-escape), filters, facets, pagination
 *   - archive/restore with live-exam blocking + affected-exam warnings
 *   - assign/unassign/points, snapshot materialization, duplicates
 *   - CSV parsing (BOM, quoted newlines, headers) and import error reporting
 *   - export, provenance and snapshot immutability
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

use App\Repositories\QuestionBankRepository;

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

/** Split a .sql migration into individual executable statements (comments tolerated). */
function splitStatements(string $sql): array
{
    $chunks = array_map('trim', explode(';', $sql));
    $out = [];
    foreach ($chunks as $chunk) {
        // strip line comments and /* */ blocks so comment-only chunks become empty
        $clean = preg_replace('/^--.*$/m', '', $chunk);
        $clean = preg_replace('/\/\*.*?\*\//s', '', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            continue;
        }
        // A comment fragment left over from a ';' inside a comment is never a
        // statement, so only accept chunks that begin with a SQL keyword.
        if (preg_match('/^(ALTER|CREATE|DROP|SET|INSERT|UPDATE|PREPARE|EXECUTE|DEALLOCATE|SELECT)\b/i', $clean)) {
            $out[] = $clean;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Scratch database setup
// ---------------------------------------------------------------------------

$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_exam_qbank_test';

try {
    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
} catch (Throwable $e) {
    echo "Cannot connect to MySQL ({$host}:{$port}): {$e->getMessage()}\n";
    exit(1);
}

$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$server->exec("USE `{$dbName}`");

// Base tables (original pre-migration shape, copied from schema.sql)
$server->exec(
    "CREATE TABLE `admin_users` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `full_name` VARCHAR(100) DEFAULT '',
        `role` VARCHAR(30) NOT NULL DEFAULT 'admin',
        `failed_attempts` INT(11) NOT NULL DEFAULT 0,
        `locked_until` DATETIME DEFAULT NULL,
        `last_login_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
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
        `exam_id` INT(11) NOT NULL,
        `question_number` INT(11) NOT NULL,
        `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
        `paragraph_text` TEXT DEFAULT NULL,
        `question_text` TEXT NOT NULL,
        `option_a` TEXT NOT NULL,
        `option_b` TEXT NOT NULL,
        `option_c` TEXT NOT NULL,
        `option_d` TEXT NOT NULL,
        `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_exam_id` (`exam_id`),
        CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// Apply the real migration (also smoke-tests idempotency by running twice)
$migration = (string) file_get_contents(__DIR__ . '/../database/migrations/2026_08_06_add_question_bank_crud.sql');
if ($migration === '') {
    echo "Migration file not found.\n";
    exit(1);
}
foreach (splitStatements($migration) as $stmt) {
    $server->exec($stmt);
}
foreach (splitStatements($migration) as $stmt) {
    $server->exec($stmt); // second pass must not error (idempotency)
}

$server->exec("INSERT INTO admin_users (username, password_hash, full_name, role) VALUES ('testadmin', 'x', 'Test Admin', 'admin')");
$adminId = (int) $server->lastInsertId();
$server->exec("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Midterm Physics', 60, 'Natural Science', 0)");
$examId = (int) $server->lastInsertId();
$server->exec("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Live Chemistry', 45, 'Natural Science', 1)");
$liveExamId = (int) $server->lastInsertId();

QuestionBankRepository::useConnection($server);

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// CREATE / validation
// ---------------------------------------------------------------------------

echo "CREATE — MCQ, True/False, Essay\n";

$mcqId = QuestionBankRepository::save([
    'question' => 'What is 2 + 2?', 'type' => 'MCQ', 'difficulty' => 'easy',
    'subject' => 'Mathematics', 'grade' => 'Grade 1', 'topic' => 'Addition',
    'tags' => 'math, basic', 'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '',
    'correct_answer' => 'b', 'is_public' => 1,
], $adminId)['id'];
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $mcqId]);
check('MCQ row created', $mcqId > 0);
check('question mirrors question_text (legacy compat)', $row !== null && $row['question'] === $row['question_text'] && $row['question'] === 'What is 2 + 2?');
check('bank row has NULL exam_id and question_number', $row !== null && $row['exam_id'] === null && $row['question_number'] === null);
check('is_passage=0, paragraph NULL, correct answer stored', $row !== null && (int) $row['is_passage'] === 0 && $row['paragraph_text'] === null && $row['correct_answer'] === 'b');
check('status/approval_status default to approved', $row !== null && $row['status'] === 'approved' && $row['approval_status'] === 'Approved');
check('created_by recorded + created_at set', $row !== null && (int) $row['created_by'] === $adminId && $row['created_at'] !== null);

$tfId = QuestionBankRepository::save([
    'question' => 'Water boils at 100°C at sea level.', 'type' => 'True/False', 'difficulty' => 'easy',
    'subject' => 'Physics', 'grade' => 'Grade 10', 'topic' => 'Thermodynamics', 'tags' => 'water',
    'correct_answer' => 'a', 'is_public' => 1,
], $adminId)['id'];
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $tfId]);
check('True/False options forced to True/False', $row !== null && $row['option_a'] === 'True' && $row['option_b'] === 'False' && $row['option_c'] === '' && $row['correct_answer'] === 'a');
$tfId2 = QuestionBankRepository::save([
    'question' => 'The Earth is flat.', 'type' => 'True/False', 'difficulty' => 'medium', 'correct_answer' => 'false',
], $adminId)['id'];
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $tfId2]);
check('True/False accepts "false" and maps to b', $row !== null && $row['correct_answer'] === 'b');

$essayId = QuestionBankRepository::save([
    'question' => 'Explain photosynthesis.', 'type' => 'Essay', 'difficulty' => 'hard',
    'subject' => 'Biology', 'grade' => 'Grade 11', 'correct_answer' => 'bogus',
], $adminId)['id'];
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $essayId]);
check('Essay options blanked + correct answer cleared', $row !== null && $row['option_a'] === '' && $row['correct_answer'] === '');

echo "\nCREATE — validation errors\n";

$threw = false;
try { QuestionBankRepository::save(['question' => '   ', 'type' => 'MCQ'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'required'); }
check('empty question text rejected', $threw);

$threw = false;
try { QuestionBankRepository::save(['question' => 'x', 'type' => 'Fill-in-blank'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'Invalid question type'); }
check('unknown type rejected', $threw);

$threw = false;
try { QuestionBankRepository::save(['question' => 'x', 'type' => 'MCQ', 'difficulty' => 'impossible'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'Invalid difficulty'); }
check('unknown difficulty rejected', $threw);

$threw = false;
try { QuestionBankRepository::save(['question' => 'x', 'type' => 'MCQ', 'option_a' => 'A', 'option_b' => 'B'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'correct answer'); }
check('MCQ without correct answer rejected', $threw);

$threw = false;
try { QuestionBankRepository::save(['question' => 'x', 'type' => 'MCQ', 'option_a' => 'A', 'correct_answer' => 'a'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'two non-empty'); }
check('MCQ with fewer than two options rejected', $threw);

$threw = false;
try { QuestionBankRepository::save(['question' => 'x', 'type' => 'MCQ', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => '', 'option_d' => '', 'correct_answer' => 'c'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'cannot be empty'); }
check('MCQ with empty correct option rejected', $threw);

// ---------------------------------------------------------------------------
// EDIT
// ---------------------------------------------------------------------------

echo "\nEDIT\n";

QuestionBankRepository::save([
    'id' => $mcqId, 'question' => 'What is 2 + 2? (edited)', 'type' => 'MCQ', 'difficulty' => 'medium',
    'subject' => 'Mathematics', 'grade' => 'Grade 1', 'topic' => 'Addition', 'tags' => 'math',
    'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '', 'correct_answer' => 'b',
], $adminId);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $mcqId]);
check('edit updates text + difficulty', $row !== null && $row['question'] === 'What is 2 + 2? (edited)' && $row['difficulty'] === 'medium');
check('edit keeps original created_by', $row !== null && (int) $row['created_by'] === $adminId);
check('edit bumps updated_at', $row !== null && $row['updated_at'] !== null);

$threw = false;
try { QuestionBankRepository::save(['id' => 99999, 'question' => 'ghost', 'type' => 'MCQ'], $adminId); } catch (InvalidArgumentException $e) { $threw = true; }
check('editing a missing question rejected', $threw);

// ---------------------------------------------------------------------------
// LIST / SEARCH / FILTERS / FACETS / PAGINATION
// ---------------------------------------------------------------------------

echo "\nLIST — search, filters, facets, pagination\n";

$list = QuestionBankRepository::paginate(['status' => 'active'], 1, 15);
check('active list excludes nothing here (4 questions)', $list['total'] === 4);

$r = QuestionBankRepository::paginate(['status' => 'active', 'subject' => 'Mathematics'], 1, 15);
check('subject filter', $r['total'] === 1);

$r = QuestionBankRepository::paginate(['status' => 'active', 'difficulty' => 'medium'], 1, 15);
check('difficulty filter (medium = edited MCQ + flat-earth T/F)', $r['total'] === 2);

$r = QuestionBankRepository::paginate(['status' => 'active', 'type' => 'True/False'], 1, 15);
check('type filter', $r['total'] === 2);

$r = QuestionBankRepository::paginate(['status' => 'active', 'search' => 'photosynthesis'], 1, 15);
check('search matches essay text', $r['total'] === 1 && (int) $r['rows'][0]['id'] === $essayId);

$r = QuestionBankRepository::paginate(['status' => 'active', 'search' => 'thermodynamics'], 1, 15);
check('search matches topic', $r['total'] === 1);

QuestionBankRepository::save(['question' => 'A discount of 100% off everything', 'type' => 'MCQ', 'option_a' => 'a1', 'option_b' => 'a2', 'correct_answer' => 'a'], $adminId);
$r = QuestionBankRepository::paginate(['status' => 'active', 'search' => '100%'], 1, 15);
check('LIKE wildcards in search are escaped (100%)', $r['total'] === 1);
$r = QuestionBankRepository::paginate(['status' => 'active', 'search' => 'off everything'], 1, 15);
check('search "off everything" finds the % question', $r['total'] === 1);

$today = date('Y-m-d');
$r = QuestionBankRepository::paginate(['status' => 'active', 'date_from' => $today, 'date_to' => $today], 1, 15);
check('date range includes today', $r['total'] === 5);
$r = QuestionBankRepository::paginate(['status' => 'active', 'date_from' => '1999-01-01', 'date_to' => '1999-01-02'], 1, 15);
check('date range excludes everything before', $r['total'] === 0);

$threw = false;
try { QuestionBankRepository::paginate(['date_from' => 'not-a-date'], 1, 15); } catch (InvalidArgumentException $e) { $threw = true; }
check('invalid date format rejected', $threw);

$facets = QuestionBankRepository::facets();
check('facets include subjects', in_array('Mathematics', $facets['subjects'], true));
check('facets include types', in_array('Essay', $facets['types'], true));

$r = QuestionBankRepository::paginate(['status' => 'active', 'created_by' => $adminId], 1, 15);
check('created_by filter', $r['total'] === 5);

// Pagination: add 20 more questions → 25 active total
for ($i = 0; $i < 20; $i++) {
    QuestionBankRepository::save(['question' => "Bulk question {$i}", 'type' => 'MCQ', 'option_a' => 'A', 'option_b' => 'B', 'correct_answer' => 'a'], $adminId);
}
$r = QuestionBankRepository::paginate(['status' => 'active'], 1, 10);
check('pagination page 1 has 10 rows', count($r['rows']) === 10 && $r['total'] === 25 && $r['total_pages'] === 3);
$r = QuestionBankRepository::paginate(['status' => 'active'], 3, 10);
check('pagination page 3 has remaining rows', count($r['rows']) === 5);
$r = QuestionBankRepository::paginate(['status' => 'active'], 2, 10);
check('pagination page 2 differs from page 1', (int) $r['rows'][0]['id'] !== (int) QuestionBankRepository::paginate(['status' => 'active'], 1, 10)['rows'][0]['id']);

// ---------------------------------------------------------------------------
// ARCHIVE / RESTORE
// ---------------------------------------------------------------------------

echo "\nARCHIVE / RESTORE\n";

$res = QuestionBankRepository::archive([$mcqId]);
check('archive returns affected count', $res['archived'] === 1 && $res['blocked'] === []);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $mcqId]);
check('archived row has status + timestamp', $row !== null && $row['status'] === 'archived' && $row['archived_at'] !== null);
check('archived question excluded from active list', QuestionBankRepository::paginate(['status' => 'active'], 1, 100)['total'] === 24);
check('archived question appears in archived list', QuestionBankRepository::paginate(['status' => 'archived'], 1, 15)['total'] === 1);

$restored = QuestionBankRepository::restore([$mcqId]);
check('restore brings question back', $restored === 1 && QuestionBankRepository::paginate(['status' => 'active'], 1, 100)['total'] === 25);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $mcqId]);
check('restore clears archived_at + resets status', $row !== null && $row['archived_at'] === null && $row['status'] === 'approved');

// ---------------------------------------------------------------------------
// ASSIGN / UNASSIGN / POINTS
// ---------------------------------------------------------------------------

echo "\nASSIGN / UNASSIGN / POINTS\n";

$res = QuestionBankRepository::assign($examId, [$mcqId, $tfId], 2.0, [$tfId => 5], $adminId);
check('assign returns 2 created', $res['assigned'] === 2 && $res['errors'] === []);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $res['created'][0]['snapshot_id']]);
check('snapshot materialized inside exam (exam_id + source set)', $row !== null && (int) $row['exam_id'] === $examId && (int) $row['source_question_id'] === $mcqId);
check('snapshot question_number assigned sequentially', $row !== null && (int) $row['question_number'] === 1);
check('snapshot mirrors question text', $row !== null && $row['question_text'] === 'What is 2 + 2? (edited)');
check('snapshot copies bank metadata', $row !== null && $row['type'] === 'MCQ' && $row['difficulty'] === 'medium' && $row['subject'] === 'Mathematics');
check('snapshot is NOT a bank row (excluded from list)', QuestionBankRepository::paginate(['status' => 'active'], 1, 100)['total'] === 25);

$assigned = QuestionBankRepository::assigned($examId);
check('assigned() lists both with points', count($assigned) === 2);
$mcqAssigned = array_values(array_filter($assigned, fn ($a) => (int) $a['question_id'] === $mcqId))[0] ?? null;
check('per-question points respected (default 2 for MCQ, 5 for T/F)', $mcqAssigned !== null && (float) $mcqAssigned['points'] === 2.0);

$dup = QuestionBankRepository::assign($examId, [$mcqId], 1);
check('duplicate assignment rejected with error', $dup['assigned'] === 0 && count($dup['errors']) === 1);

$threw = false;
try { QuestionBankRepository::assign($examId, [$essayId], 1); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'Essay'); }
check('assign of Essay rejected', $threw);

$mixed = QuestionBankRepository::assign($examId, [$essayId, $tfId2], 1);
check('bulk assign with an Essay still assigns the valid ones', $mixed['assigned'] === 1 && count($mixed['errors']) === 1);

$threw = false;
try { QuestionBankRepository::assign($liveExamId, [$tfId2], 1); } catch (InvalidArgumentException $e) { $threw = true; }
check('assign to a LIVE exam blocked', $threw);

$threw = false;
try { QuestionBankRepository::assign(99999, [$tfId2], 1); } catch (InvalidArgumentException $e) { $threw = true; }
check('assign to a missing exam blocked', $threw);

$threw = false;
try { QuestionBankRepository::updatePoints($liveExamId, $mcqId, 4); } catch (InvalidArgumentException $e) { $threw = true; }
check('points update on LIVE exam blocked', $threw);

$ok = QuestionBankRepository::updatePoints($examId, $mcqId, 3.5);
check('points update works on editable exam', $ok);
$assigned = QuestionBankRepository::assigned($examId);
$mcqAssigned = array_values(array_filter($assigned, fn ($a) => (int) $a['question_id'] === $mcqId))[0] ?? null;
check('points persisted (3.5)', $mcqAssigned !== null && (float) $mcqAssigned['points'] === 3.5);

$threw = false;
try { QuestionBankRepository::unassign($liveExamId, $mcqId); } catch (InvalidArgumentException $e) { $threw = true; }
check('unassign from LIVE exam blocked', $threw);

check('points update on non-assigned question is a no-op (returns false)', QuestionBankRepository::updatePoints($examId, 777777, 1) === false);

// Editing the bank question must NOT mutate the already-materialized snapshot
QuestionBankRepository::save([
    'id' => $mcqId, 'question' => 'What is 2 + 2? (v3)', 'type' => 'MCQ', 'difficulty' => 'easy',
    'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '', 'correct_answer' => 'b',
], $adminId);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $res['created'][0]['snapshot_id']]);
check('editing bank question does not mutate existing snapshot', $row !== null && $row['question_text'] === 'What is 2 + 2? (edited)');

$threw = false;
try { QuestionBankRepository::save(['id' => $res['created'][0]['snapshot_id'], 'question' => 'nope', 'type' => 'MCQ'], $adminId); } catch (InvalidArgumentException $e) { $threw = true; }
check('snapshot rows cannot be edited via the bank editor', $threw);

QuestionBankRepository::unassign($examId, $tfId);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $res['created'][1]['snapshot_id']]);
check('unassign deletes the snapshot copy', $row === null);
$assignedAfter = QuestionBankRepository::assigned($examId);
check('unassign removes the pivot row', count(array_filter($assignedAfter, fn ($a) => (int) $a['question_id'] === $tfId)) === 0);

// Archive protection: question assigned to a LIVE exam → hard block.
// Simulate reality: the exam went live AFTER the assignment (assign() itself
// refuses live exams) — the only way a bank question ends up inside one.
$server->exec("UPDATE exams SET is_live = 1 WHERE id = {$examId}");
$ar = QuestionBankRepository::archive([$tfId2]);
check('archiving a question assigned to a LIVE exam is blocked', $ar['blocked'] === [$tfId2] && $ar['archived'] === 0);
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $tfId2]);
check('blocked question still active', $row !== null && $row['status'] === 'approved');
$server->exec("UPDATE exams SET is_live = 0 WHERE id = {$examId}");

// Archive protection: assigned to non-live exam → allowed, with affected exam reported
$ar = QuestionBankRepository::archive([$mcqId]);
check('archive of question assigned to non-live exam allowed', $ar['archived'] === 1);
check('affected exam reported', count($ar['affected_exams']) === 1 && $ar['affected_exams'][0]['exam_name'] === 'Midterm Physics');
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $res['created'][0]['snapshot_id']]);
check('snapshot inside the exam survives archiving the source', $row !== null);
$threw = false;
try { QuestionBankRepository::assign($examId, [$mcqId], 1); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'archived'); }
check('archived source cannot be re-assigned', $threw);
QuestionBankRepository::restore([$mcqId]);

// ---------------------------------------------------------------------------
// IMPORT + CSV PARSING
// ---------------------------------------------------------------------------

echo "\nIMPORT + CSV PARSING\n";

$csv = "\xEF\xBB\xBFquestion,type,difficulty,subject,grade,option_a,option_b,correct_answer\n" .
       "\"What is the capital of France?\",MCQ,easy,Geography,Grade 8,Paris,London,a\n" .
       ",\n" . // blank row — skipped
       "\"A paragraph with,\na real newline inside\",MCQ,medium,,,Alpha,Beta,b\n" .
       "Missing answer options,MCQ,easy,,,,,\n";
$parsed = QuestionBankRepository::parseCsv($csv);
check('parseCsv handles BOM, quotes, embedded newlines, blank rows', count($parsed) === 3);
check('parseCsv maps headers (option_a, correct_answer)', $parsed[0]['option_a'] === 'Paris' && $parsed[0]['correct_answer'] === 'a');

$threw = false;
try { QuestionBankRepository::parseCsv("type,difficulty\nMCQ,easy\n"); } catch (InvalidArgumentException $e) { $threw = true; }
check('CSV without a question column rejected', $threw);

$res = QuestionBankRepository::import($parsed, ['grade' => 'Grade 8'], $adminId);
check('import inserts valid rows', $res['imported'] === 2 && $res['total'] === 3);
check('import reports invalid row with line number', count($res['errors']) === 1 && $res['errors'][0]['line'] === 5);
check('import applies defaults when column missing', QuestionBankRepository::paginate(['status' => 'active', 'grade' => 'Grade 8'], 1, 100)['total'] === 2);

// ---------------------------------------------------------------------------
// IMPORT — external JSON export format (user's file shape)
// ---------------------------------------------------------------------------

echo "\nIMPORT — external JSON export format\n";

// Mirrors the shape of the uploaded Mathematics_Question_Bank_500.json:
// question_type labels, capitalized difficulty, nested options object,
// "answer" instead of "correct_answer", tags as an array.
$externalRows = [
    [
        'id' => 1,
        'question' => 'The formula for the nth term of an arithmetic sequence is:',
        'options' => ['A' => 'an = a1 + (n-1)d', 'B' => 'an = a1 - (n+1)d', 'C' => 'an = a1 x r^(n-1)', 'D' => 'an = a1/n'],
        'answer' => 'A',
        'subject' => 'Mathematics',
        'grade' => '12',
        'topic' => 'Sequences and Series: Arithmetic Sequences',
        'difficulty' => 'Easy',
        'question_type' => 'Multiple Choice',
        'marks' => 1,
        'learning_outcome' => 'Find the nth term of an arithmetic sequence',
        'tags' => ['Arithmetic Sequence'],
        'explanation' => 'The nth term of an arithmetic sequence is an = a1 + (n-1)d.',
    ],
    [
        'id' => 2,
        'question' => 'Find the 10th term of the arithmetic sequence 2, 5, 8, 11, ...',
        'options' => ['A' => '35', 'B' => '26', 'C' => '29', 'D' => '32'],
        'answer' => 'C',
        'difficulty' => 'Easy',
        'question_type' => 'Multiple choice', // lowercase c also accepted
        'subject' => 'Mathematics',
        'grade' => '12',
    ],
    [
        'id' => 3,
        'question' => 'The derivative of a constant function f(x) = c is:',
        'options' => ['1' => 'cx', '2' => '0', '3' => 'c', '4' => '1'], // 1-indexed list
        'answer' => 'B',
        'difficulty' => 'MEDIUM', // uppercase also accepted
        'question_type' => 'MCQ',
    ],
    [
        'id' => 4,
        'question' => 'True or false: The Earth is flat.',
        'answer' => 'false',
        'difficulty' => 'Hard',
        'question_type' => 'True or False',
        'tags' => ['geography', 'science'],
    ],
];

$res = QuestionBankRepository::import($externalRows, ['subject' => 'Mathematics', 'grade' => 'Grade 12'], $adminId);
check('external-format JSON imports all 4 rows', $res['imported'] === 4 && $res['errors'] === []);

$row = fetchOne($server, "SELECT * FROM questions WHERE question = 'The formula for the nth term of an arithmetic sequence is:'");
check('question_type "Multiple Choice" mapped to MCQ', $row !== null && $row['type'] === 'MCQ');
check('capitalized difficulty "Easy" normalized to easy', $row !== null && $row['difficulty'] === 'easy');
check('nested options object mapped to option_a..d', $row !== null && $row['option_a'] === 'an = a1 + (n-1)d' && $row['option_c'] === 'an = a1 x r^(n-1)');
check('"answer" key mapped to correct_answer (lowercased)', $row !== null && $row['correct_answer'] === 'a');
check('tags array joined to a string', $row !== null && $row['tags'] === 'Arithmetic Sequence');

$row = fetchOne($server, "SELECT * FROM questions WHERE question LIKE 'The derivative of a constant%'");
check('1-indexed options list mapped to option_a..d', $row !== null && $row['option_a'] === 'cx' && $row['option_b'] === '0');
check('uppercase difficulty "MEDIUM" normalized to medium', $row !== null && $row['difficulty'] === 'medium');

$row = fetchOne($server, "SELECT * FROM questions WHERE question LIKE 'True or false:%'");
check('question_type "True or False" mapped to True/False', $row !== null && $row['type'] === 'True/False');
check('True/False "answer"=false maps to option b', $row !== null && $row['correct_answer'] === 'b' && $row['option_a'] === 'True' && $row['option_b'] === 'False');
check('"Hard" normalized to hard', $row !== null && $row['difficulty'] === 'hard');
check('multi-tag array joined', $row !== null && $row['tags'] === 'geography, science');

// Numeric difficulty codes (1/2/3) map via the aliases instead of being dropped
$res = QuestionBankRepository::import([
    ['question' => 'Numeric difficulty 2', 'options' => ['A' => 'x', 'B' => 'y'], 'answer' => 'a', 'difficulty' => 2, 'question_type' => 'MCQ'],
], [], $adminId);
check('numeric difficulty code 2 maps to medium', $res['imported'] === 1 && fetchOne($server, "SELECT * FROM questions WHERE question = 'Numeric difficulty 2'")['difficulty'] === 'medium');

// A row that is genuinely invalid must still be reported with a line number
$bad = [
    ['question' => 'OK question', 'options' => ['A' => 'x', 'B' => 'y'], 'answer' => 'a', 'question_type' => 'Multiple Choice'],
    ['question' => 'Bad difficulty', 'options' => ['A' => 'x', 'B' => 'y'], 'answer' => 'a', 'difficulty' => 'Extreme', 'question_type' => 'MCQ'],
    ['question' => 'No answer', 'options' => ['A' => 'x', 'B' => 'y'], 'question_type' => 'Multiple Choice'],
];
$res = QuestionBankRepository::import($bad, [], $adminId);
check('invalid external rows still reported per line', $res['imported'] === 1 && count($res['errors']) === 2);
check('unmapped difficulty surfaces a clear error', count($res['errors']) >= 1 && str_contains($res['errors'][0]['message'], "Invalid difficulty 'Extreme'"));

// ---------------------------------------------------------------------------
// EXPORT
// ---------------------------------------------------------------------------

echo "\nEXPORT\n";

$export = QuestionBankRepository::export(['status' => 'active', 'subject' => 'Geography']);
check('export respects filters', count($export) === 1 && $export[0]['type'] === 'MCQ');
$export = QuestionBankRepository::export(['status' => 'all']);
check('export status=all includes archived rows', QuestionBankRepository::paginate(['status' => 'all'], 1, 100)['total'] === count($export));

// ---------------------------------------------------------------------------
// REVIEW-FIX REGRESSIONS
// ---------------------------------------------------------------------------

echo "\nREVIEW-FIX REGRESSIONS\n";

$threw = false;
try { QuestionBankRepository::save(['question' => 'Never say never.', 'type' => 'True/False'], $adminId); } catch (InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'correct answer'); }
check('True/False without a correct answer is rejected (no silent default)', $threw);

$pubId = QuestionBankRepository::save(['question' => 'Defaults to public.', 'type' => 'MCQ', 'option_a' => 'A', 'option_b' => 'B', 'correct_answer' => 'a'], $adminId)['id'];
$row = fetchOne($server, 'SELECT * FROM questions WHERE id = :id', ['id' => $pubId]);
check('is_public defaults to 1 (public) when omitted', $row !== null && (int) $row['is_public'] === 1);

check('re-saving identical marks still succeeds (no spurious failure)', QuestionBankRepository::updatePoints($examId, $mcqId, 3.5) === true);

// ---------------------------------------------------------------------------
// TEARDOWN
// ---------------------------------------------------------------------------

QuestionBankRepository::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
