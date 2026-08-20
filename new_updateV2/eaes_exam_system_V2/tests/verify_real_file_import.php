<?php
/**
 * One-off verification: run the user's actual JSON export file through the
 * QuestionBankRepository import pipeline against a scratch DB.
 * Usage: php tests/verify_real_file_import.php "path/to/file.json"
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

use App\Repositories\QuestionBankRepository;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php tests/verify_real_file_import.php <path-to-json>\n");
    exit(2);
}

// --- scratch DB (same shape as the integration test) ---
$dbName = 'eaes_exam_qbank_verify';
try {
    $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "MySQL unavailable: {$e->getMessage()}\n");
    exit(1);
}
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$server->exec("USE `{$dbName}`");
$server->exec("CREATE TABLE `admin_users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL, `full_name` VARCHAR(100) DEFAULT '',
    `role` VARCHAR(30) NOT NULL DEFAULT 'admin', PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$server->exec("CREATE TABLE `exams` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `exam_name` VARCHAR(150) NOT NULL,
    `duration` INT(11) NOT NULL, `stream` VARCHAR(50) NOT NULL,
    `is_live` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$server->exec("CREATE TABLE `questions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `exam_id` INT(11) NOT NULL,
    `question_number` INT(11) NOT NULL, `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
    `paragraph_text` TEXT DEFAULT NULL, `question_text` TEXT NOT NULL,
    `option_a` TEXT NOT NULL, `option_b` TEXT NOT NULL, `option_c` TEXT NOT NULL,
    `option_d` TEXT NOT NULL, `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`), KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// real migration (robust splitter — the naive explode(';') breaks on the
// semicolons inside string literals, as the main test harness discovered)
function splitStatements(string $sql): array
{
    $chunks = array_map('trim', explode(';', $sql));
    $out = [];
    foreach ($chunks as $chunk) {
        $clean = preg_replace('/^--.*$/m', '', $chunk);
        $clean = preg_replace('/\/\*.*?\*\//s', '', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            continue;
        }
        if (preg_match('/^(ALTER|CREATE|DROP|SET|INSERT|UPDATE|PREPARE|EXECUTE|DEALLOCATE|SELECT)\b/i', $clean)) {
            $out[] = $clean;
        }
    }
    return $out;
}
$migration = file_get_contents(__DIR__ . '/../database/migrations/2026_08_06_add_question_bank_crud.sql');
foreach (splitStatements($migration) as $stmt) {
    $server->exec($stmt);
}
$server->exec("INSERT INTO admin_users (username, password_hash, full_name) VALUES ('verify', 'x', 'Verify')");
$adminId = (int) $server->lastInsertId();
QuestionBankRepository::useConnection($server);

// --- decode the real file exactly like handleImport() does ---
$content = file_get_contents($file);
$decoded = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Invalid JSON: " . json_last_error_msg() . "\n");
    exit(1);
}
$rows = isset($decoded['questions']) && is_array($decoded['questions']) ? $decoded['questions'] : $decoded;
printf("File says total_questions=%s, decoded rows=%d\n", var_export($decoded['total_questions'] ?? 'n/a', true), count($rows));

$result = QuestionBankRepository::import($rows, ['subject' => 'Mathematics', 'grade' => 'Grade 12'], $adminId);
printf("IMPORTED %d of %d — %d skipped\n", $result['imported'], $result['total'], count($result['errors']));
foreach (array_slice($result['errors'], 0, 10) as $err) {
    printf("  Line %d: %s\n", $err['line'], $err['message']);
}

// sanity: distinct types/difficulties stored
$stmt = $server->query("SELECT type, difficulty, COUNT(*) c FROM questions WHERE exam_id IS NULL GROUP BY type, difficulty ORDER BY type, difficulty");
echo "\nStored breakdown:\n";
foreach ($stmt->fetchAll() as $r) {
    printf("  %-10s %-8s %d\n", $r['type'], $r['difficulty'], $r['c']);
}

QuestionBankRepository::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
exit($result['imported'] === $result['total'] ? 0 : 1);
