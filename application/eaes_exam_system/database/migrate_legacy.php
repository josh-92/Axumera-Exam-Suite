<?php
/**
 * database/migrate_legacy.php
 *
 * One-time helper for upgrading an existing installation of the original
 * (pre-refactor) exam system to the new schema in database/schema.sql.
 *
 * USAGE (run from a terminal, not the browser):
 *   1. Back up your database first: mysqldump eaes_exam > backup.sql
 *   2. Rename your OLD database (the one with the legacy `students`/`exams`/
 *      `questions`/`admin_users` tables) to something like `eaes_exam_old`.
 *   3. Run the installer (installer/install.php) normally against a fresh
 *      database to create the new schema.
 *   4. Run:  php database/migrate_legacy.php eaes_exam_old
 *      (pass the OLD database name as the first argument; the NEW database
 *      credentials are read from your .env as usual)
 *
 * What it does:
 *   - Copies admin_users (plaintext passwords are preserved; they will be
 *     transparently upgraded to bcrypt the next time each admin logs in).
 *   - Copies exams and questions as-is (adds is_passage=0 to every row,
 *     since the legacy schema had no such column).
 *   - Splits the legacy `students` table (which conflated identity with a
 *     single exam's result) into the new `students` (identity) +
 *     `exam_attempts` (one row per historical result) tables.
 */

require_once __DIR__ . '/../app/Autoload.php';
$GLOBALS['__eaes_config'] = require __DIR__ . '/../app/config.php';
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $GLOBALS['__eaes_config'];
        foreach ($segments as $s) {
            if (!is_array($value) || !array_key_exists($s, $value)) return $default;
            $value = $value[$s];
        }
        return $value;
    }
}

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line, e.g.:\n  php database/migrate_legacy.php eaes_exam_old\n");
}

$oldDbName = $argv[1] ?? null;
if (!$oldDbName) {
    die("Usage: php database/migrate_legacy.php <old_database_name>\n");
}

$dbConfig = config('db');
$new = App\Core\Database::connection();
$old = App\Core\Database::connectWith($dbConfig['host'], $dbConfig['port'], $oldDbName, $dbConfig['user'], $dbConfig['pass'], $dbConfig['charset']);

echo "Connected to old database '$oldDbName' and new database '{$dbConfig['name']}'.\n";

// ---- admin_users ----
$count = 0;
foreach ($old->query('SELECT * FROM admin_users') as $row) {
    $stmt = $new->prepare('INSERT IGNORE INTO admin_users (username, password_hash, created_at) VALUES (:u, :p, NOW())');
    $stmt->execute(['u' => $row['username'], 'p' => $row['password']]); // legacy plaintext; upgraded on next login
    $count++;
}
echo "Migrated $count admin user(s).\n";

// ---- exams + questions ----
$examIdMap = [];
$stmt = $old->query('SELECT * FROM exams');
$examCount = 0;
foreach ($stmt as $row) {
    $ins = $new->prepare('INSERT INTO exams (exam_name, duration, stream, is_live, color_theme, json_filename, created_at, updated_at)
                           VALUES (:name, :duration, :stream, :live, :color, :file, NOW(), NOW())');
    $ins->execute([
        'name' => $row['exam_name'], 'duration' => $row['duration'], 'stream' => $row['stream'],
        'live' => $row['is_live'] ?? 0, 'color' => $row['color_theme'] ?? '#0062cc', 'file' => $row['json_filename'] ?? 'questions.json',
    ]);
    $examIdMap[$row['id']] = (int) $new->lastInsertId();
    $examCount++;
}
echo "Migrated $examCount exam(s).\n";

$qCount = 0;
$stmt = $old->query('SELECT * FROM questions');
foreach ($stmt as $row) {
    if (!isset($examIdMap[$row['exam_id']])) continue;
    $isPassage = ($row['paragraph_text'] ?? '') === 'PASSAGE_BLOCK' ? 1 : 0;
    $ins = $new->prepare('INSERT INTO questions (exam_id, question_number, is_passage, paragraph_text, question_text, option_a, option_b, option_c, option_d, correct_answer)
                           VALUES (:e, :n, :p, :para, :qt, :a, :b, :c, :d, :corr)');
    $ins->execute([
        'e' => $examIdMap[$row['exam_id']], 'n' => $row['question_number'], 'p' => $isPassage,
        'para' => $row['paragraph_text'], 'qt' => $row['question_text'],
        'a' => $row['option_a'], 'b' => $row['option_b'], 'c' => $row['option_c'], 'd' => $row['option_d'],
        'corr' => $row['correct_answer'],
    ]);
    $qCount++;
}
echo "Migrated $qCount question row(s).\n";

// ---- students -> students + exam_attempts ----
$studentCount = 0;
$attemptCount = 0;
$stmt = $old->query('SELECT * FROM students');
foreach ($stmt as $row) {
    $findStmt = $new->prepare('SELECT id FROM students WHERE roll_number = :r AND stream = :s LIMIT 1');
    $findStmt->execute(['r' => $row['roll_number'], 's' => $row['stream']]);
    $studentId = $findStmt->fetchColumn();

    if (!$studentId) {
        $ins = $new->prepare('INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES (:n, :r, :s, :sec, NOW())');
        $ins->execute(['n' => $row['full_name'], 'r' => $row['roll_number'], 's' => $row['stream'], 'sec' => $row['section']]);
        $studentId = (int) $new->lastInsertId();
        $studentCount++;
    }

    if (!empty($row['exam_id']) && isset($examIdMap[$row['exam_id']])) {
        $newExamId = $examIdMap[$row['exam_id']];
        $status = ($row['status'] ?? '') === 'Submitted' ? 'submitted' : 'in_progress';
        $ins = $new->prepare("INSERT IGNORE INTO exam_attempts
                (student_id, exam_id, answers, flags, score, total_questions, status, started_at, submitted_at)
                VALUES (:sid, :eid, '{}', '{}', :score, NULL, :status, NOW(), :submitted)");
        $ins->execute([
            'sid' => $studentId, 'eid' => $newExamId, 'score' => $row['score'],
            'status' => $status, 'submitted' => $status === 'submitted' ? date('Y-m-d H:i:s') : null,
        ]);
        $attemptCount++;
    }
}
echo "Migrated $studentCount student identity/identities and $attemptCount attempt record(s).\n";
echo "Done. Legacy admin passwords will auto-upgrade to bcrypt on next successful login.\n";
