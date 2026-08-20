<?php

/**
 * DocxQuestionParser integration test
 * -----------------------------------
 * 1. Builds real .docx bytes (minimal ZIP with word/document.xml, both
 *    deflated and stored variants) plus plain-text samples.
 * 2. Asserts the heuristic parser output: numbering stripped, options
 *    mapped, "Answer:" lines + bolded options + answer keys + True/False.
 * 3. Imports the parsed rows into a scratch MySQL database through
 *    QuestionBankRepository::import() and verifies the stored rows.
 *
 * Run: php tests/docx_parser_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

use App\Repositories\QuestionBankRepository;
use App\Services\DocxQuestionParser;
use App\Services\ExamImportService;

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

/** Build a minimal single-entry ZIP with word/document.xml. $method: 8=deflate, 0=stored. */
function buildDocxZip(string $documentXml, int $method = 8): string
{
    $entry = 'word/document.xml';
    $raw = $documentXml;
    $comp = $method === 8 ? gzdeflate($raw) : $raw;
    $crc = crc32($raw);

    $local = "PK\x03\x04"
        . pack('v', 20) . pack('v', 0x0800) . pack('v', $method)
        . pack('v', 0) . pack('v', 0) // time, date
        . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($raw))
        . pack('v', strlen($entry)) . pack('v', 0)
        . $entry . $comp;

    $central = "PK\x01\x02"
        . pack('v', 0x0314) . pack('v', 20) . pack('v', 0x0800) . pack('v', $method)
        . pack('v', 0) . pack('v', 0) // time, date
        . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($raw))
        . pack('v', strlen($entry)) . pack('v', 0) . pack('v', 0)
        . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', 0)
        . $entry;

    $eocd = "PK\x05\x06"
        . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1)
        . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);

    return $local . $central . $eocd;
}

function xmlDoc(array $paragraphs): string
{
    $body = '';
    foreach ($paragraphs as $p) {
        $ppr = !empty($p['num']) ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>' : '';
        $rpr = !empty($p['b']) ? '<w:rPr><w:b/></w:rPr>' : '';
        $body .= '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t>' . htmlspecialchars($p['t'], ENT_XML1) . '</w:t></w:r></w:p>';
    }
    return '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body . '</w:body></w:document>';
}

function qOf(array $questions, string $needle): ?array
{
    foreach ($questions as $q) {
        if (str_starts_with((string) $q['question'], $needle)) {
            return $q;
        }
    }
    return null;
}

$tmp = sys_get_temp_dir() . '/eaes_docx_test_' . uniqid() . '.docx';

// ---------------------------------------------------------------------------
// A. Word doc: numbered stems, A–D options, "Answer:" lines, True/False
// ---------------------------------------------------------------------------
echo "DOCX — numbered questions + Answer lines + True/False\n";

$docA = xmlDoc([
    ['t' => 'Physics Grade 12 — Chapter 2 Test'],
    ['t' => '1. What is the SI unit of force?'],
    ['t' => 'A. Watt'],
    ['t' => 'B. Newton'],
    ['t' => 'C. Joule'],
    ['t' => 'D. Pascal'],
    ['t' => 'Answer: B'],
    ['t' => '2. Frequency modulation works by varying:'],
    ['t' => 'A. amplitude'],
    ['t' => 'B. frequency'],
    ['t' => 'C. phase'],
    ['t' => 'Answer: B'],
    ['t' => '3. The advantage of FM over AM is:'],
    ['t' => 'A. higher bandwidth'],
    ['t' => 'B. simpler receiver'],
    ['t' => 'C. better noise immunity'],
    ['t' => 'D. longer range'],
    ['t' => 'Answer: C'],
    ['t' => '4. Water boils at 100°C at sea level.'],
    ['t' => 'True'],
    ['t' => 'False'],
    ['t' => 'Answer: True'],
]);
file_put_contents($tmp, buildDocxZip($docA));
$res = DocxQuestionParser::parseFile($tmp, 'docx');
check('deflated docx parses 4 questions (title ignored)', count($res['questions']) === 4);

$q = qOf($res['questions'], 'What is the SI unit of force?');
check('stem number stripped, question text clean', $q !== null && $q['question'] === 'What is the SI unit of force?');
check('options A–D mapped', $q !== null && $q['options']['a'] === 'Watt' && $q['options']['b'] === 'Newton' && $q['options']['c'] === 'Joule' && $q['options']['d'] === 'Pascal');
check('Answer: B → correct b (high confidence)', $q !== null && $q['correct_answer'] === 'b' && $q['confidence'] === 'high' && $q['type'] === 'MCQ');

$q = qOf($res['questions'], 'The advantage of FM over AM is:');
check('second MCQ correct answer C', $q !== null && $q['correct_answer'] === 'c');

$q = qOf($res['questions'], 'Water boils at 100°C');
check('bare True/False lines become True/False type', $q !== null && $q['type'] === 'True/False' && $q['options']['a'] === 'True' && $q['options']['b'] === 'False');
check('Answer: True maps to a', $q !== null && $q['correct_answer'] === 'a');
check('no warnings when every answer is detected', $res['warnings'] === []);

// ---------------------------------------------------------------------------
// B. Word doc: bolded correct option (no Answer line)
// ---------------------------------------------------------------------------
echo "\nDOCX — bold option marks the correct answer\n";

$docB = xmlDoc([
    ['t' => '1. The capital of France is:'],
    ['t' => 'A. Berlin'],
    ['t' => 'B. Paris', 'b' => true],
    ['t' => 'C. Rome'],
    ['t' => 'D. Madrid'],
]);
file_put_contents($tmp, buildDocxZip($docB, 0)); // stored variant exercises method 0
$res = DocxQuestionParser::parseFile($tmp, 'docx');
$q = $res['questions'][0] ?? null;
check('stored (uncompressed) docx parses too', $q !== null);
check('bolded option B inferred as correct (medium confidence)', $q !== null && $q['correct_answer'] === 'b' && $q['confidence'] === 'medium');
check('no misleading note when answer inferred', $q !== null && $q['note'] === null);

// ---------------------------------------------------------------------------
// C. Text file: wrapped stem, lowercase options, trailing answer key
// ---------------------------------------------------------------------------
echo "\nTXT — wrapped stems + answer key block\n";

$txtC = "Physics Practice\n"
    . "1. What is the SI unit of force?\n"
    . "a. Watt\nb. Newton\nc. Joule\nd. Pascal\n\n"
    . "2. The advantage of FM over AM is:\n"
    . "A. higher bandwidth\nB. simpler receiver\nC. better noise immunity\nD. longer range\n\n"
    . "3. Which of the following is NOT a\n"
    . "type of modulation?\n"
    . "A. FM\nB. AM\nC. PWM\nD. DNA\n\n"
    . "Answers:\n"
    . "1-B 2-B\n";
file_put_contents($tmp, $txtC);
$res = DocxQuestionParser::parseFile($tmp, 'txt');
check('txt parses 3 questions', count($res['questions']) === 3);
$q = qOf($res['questions'], 'Which of the following is NOT a');
check('wrapped stem rejoined into one question', $q !== null && $q['question'] === 'Which of the following is NOT a type of modulation?');
check('lowercase options a./b. mapped', qOf($res['questions'], 'What is the SI unit')['options']['a'] === 'Watt');
check('answer key block applied (1-B, 2-B)', qOf($res['questions'], 'What is the SI unit')['correct_answer'] === 'b' && qOf($res['questions'], 'The advantage of FM over AM is:')['correct_answer'] === 'b');
check('question without key/answer → null + note', qOf($res['questions'], 'Which of the following is NOT a')['correct_answer'] === null
    && str_contains((string) qOf($res['questions'], 'Which of the following is NOT a')['note'], 'No correct answer'));
check('warning emitted for the unanswered question', count($res['warnings']) === 1);

// ---------------------------------------------------------------------------
// D. Text file: "Question 3:" style + unnumbered stem fallback
// ---------------------------------------------------------------------------
echo "\nTXT — Question N: style + unnumbered stems\n";

$txtD = "Question 3: The derivative of a constant is:\n"
    . "a. cx\nb. 0\nc. c\nd. 1\n"
    . "Answer: b\n\n"
    . "The capital of France is:\n"
    . "A. Berlin\nB. Paris\nC. Rome\n";
file_put_contents($tmp, $txtD);
$res = DocxQuestionParser::parseFile($tmp, 'txt');
$q = qOf($res['questions'], 'The derivative of a constant is:');
check('Question N: prefix stripped, answer detected', $q !== null && $q['question'] === 'The derivative of a constant is:' && $q['correct_answer'] === 'b');
$q = qOf($res['questions'], 'The capital of France is:');
check('unnumbered stem before options is detected', $q !== null && $q['options']['b'] === 'Paris');
check('no answer → flagged for the teacher', $q !== null && $q['correct_answer'] === null && str_contains((string) $q['note'], 'answer'));

unlink($tmp);

// ---------------------------------------------------------------------------
// D2. UTF-16 text (Word "Unicode text" save) + 5th-option handling
// ---------------------------------------------------------------------------
echo "\nTXT — UTF-16 BOM + 5-option note\n";

$utf16 = mb_convert_encoding("1. The capital of France is:\nA. Berlin\nB. Paris\nC. Rome\nD. Madrid\nAnswer: B\n", 'UTF-16LE') ;
$utf16 = "\xFF\xFE" . $utf16; // UTF-16LE BOM
file_put_contents($tmp, $utf16);
$res = DocxQuestionParser::parseFile($tmp, 'txt');
$q = $res['questions'][0] ?? null;
check('UTF-16 text decodes and parses', $q !== null && $q['question'] === 'The capital of France is:' && $q['correct_answer'] === 'b');

$five = DocxQuestionParser::parseText("1. Which is not a programming language?\nA. PHP\nB. Python\nC. Java\nD. HTML\nE. C++\nAnswer: D");
$q = $five['questions'][0] ?? null;
check('5th option E dropped with a note', $q !== null && $q['options']['d'] === 'HTML' && !isset($q['options']['e']) && str_contains((string) $q['note'], '5th option'));

unlink($tmp);

// ---------------------------------------------------------------------------
// E. End-to-end: parsed rows → QuestionBankRepository::import() → scratch DB
// ---------------------------------------------------------------------------
echo "\nIMPORT — parsed rows into scratch database\n";

$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_exam_docx_test';

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
$server->exec("CREATE TABLE `questions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `exam_id` INT(11) NOT NULL,
    `question_number` INT(11) NOT NULL, `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
    `paragraph_text` TEXT DEFAULT NULL, `question_text` TEXT NOT NULL,
    `option_a` TEXT NOT NULL, `option_b` TEXT NOT NULL, `option_c` TEXT NOT NULL, `option_d` TEXT NOT NULL,
    `correct_answer` VARCHAR(5) NOT NULL DEFAULT '', PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$migration = (string) file_get_contents(__DIR__ . '/../database/migrations/2026_08_06_add_question_bank_crud.sql');
$chunks = array_map('trim', explode(';', $migration));
foreach ($chunks as $chunk) {
    $clean = trim((string) preg_replace(['/^--.*$/m', '/\/\*.*?\*\//s'], '', $chunk));
    if ($clean !== '' && preg_match('/^(ALTER|CREATE|DROP|SET|INSERT|UPDATE)\b/i', $clean)) {
        $server->exec($clean);
    }
}
$server->exec("INSERT INTO admin_users (username, password_hash, full_name, role) VALUES ('testadmin', 'x', 'Test Admin', 'admin')");
$adminId = (int) $server->lastInsertId();
QuestionBankRepository::useConnection($server);

// Regenerate a source document for the import phase (Answer lines + True/False).
$srcA = DocxQuestionParser::parseText(implode("\n", [
    '1. What is the SI unit of force?', 'A. Watt', 'B. Newton', 'C. Joule', 'D. Pascal', 'Answer: B',
    '2. The advantage of FM over AM is:', 'A. higher bandwidth', 'B. simpler receiver',
    'C. better noise immunity', 'D. longer range', 'Answer: C',
    '3. Water boils at 100°C at sea level.', 'True', 'False', 'Answer: True',
]));
$rows = DocxQuestionParser::toImportRows($srcA['questions'], ['subject' => 'Physics', 'grade' => 'Grade 12', 'difficulty' => 'medium']);
check('toImportRows produces CSV-style rows', count($rows) === 3 && $rows[0]['option_b'] === 'Newton' && $rows[0]['correct_answer'] === 'b');
check('defaults flow into every row', $rows[0]['subject'] === 'Physics' && $rows[0]['grade'] === 'Grade 12' && $rows[0]['difficulty'] === 'medium');

$res = QuestionBankRepository::import($rows, ['subject' => 'Physics', 'grade' => 'Grade 12', 'difficulty' => 'medium'], $adminId);
check('all 3 parsed questions imported', $res['imported'] === 3 && $res['errors'] === []);

$stmt = $server->prepare("SELECT * FROM questions WHERE question = :q");
$stmt->execute(['q' => 'What is the SI unit of force?']);
$row = $stmt->fetch();
check('bank row stores parsed MCQ', $row !== null && $row['type'] === 'MCQ' && $row['option_b'] === 'Newton' && $row['correct_answer'] === 'b');
check('bank row stores difficulty default', $row !== null && $row['difficulty'] === 'medium' && $row['subject'] === 'Physics' && $row['grade'] === 'Grade 12');

$stmt->execute(['q' => 'Water boils at 100°C at sea level.']);
$row = $stmt->fetch();
check('bank row stores parsed True/False', $row !== null && $row['type'] === 'True/False' && $row['option_a'] === 'True' && $row['option_b'] === 'False' && $row['correct_answer'] === 'a');

// A parsed question WITHOUT a detected answer must be rejected by the bank
// validator (not silently stored) — exactly what the review step prevents.
$noAnswer = DocxQuestionParser::parseText("4. Which planet is closest to the Sun?\nA. Venus\nB. Mercury\nC. Earth\nD. Mars");
check('no-answer parse leaves correct_answer empty', $noAnswer['questions'][0]['correct_answer'] === null);
$res = QuestionBankRepository::import(DocxQuestionParser::toImportRows($noAnswer['questions'], []), [], $adminId);
check('no-answer row rejected with a clear error', $res['imported'] === 0 && count($res['errors']) === 1 && str_contains($res['errors'][0]['message'], 'correct answer'));

QuestionBankRepository::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

// ---------------------------------------------------------------------------
// F. Exam module: ExamImportService::decode() with .docx uploads
// ---------------------------------------------------------------------------
echo "\nEXAM MODULE — ExamImportService Word decode\n";

$decodeMethod = new ReflectionMethod(ExamImportService::class, 'decode');
$decodeMethod->setAccessible(true);

$tmpF = sys_get_temp_dir() . '/eaes_exam_test_' . uniqid() . '.docx';

// A .docx with every answer marked → rows mapped to the examJson shape.
$docxF = xmlDoc([
    ['t' => '1. What is the SI unit of force?'],
    ['t' => 'A. Watt'],
    ['t' => 'B. Newton'],
    ['t' => 'C. Joule'],
    ['t' => 'D. Pascal'],
    ['t' => 'Answer: B'],
]);
file_put_contents($tmpF, buildDocxZip($docxF));
[$errs, $rows] = $decodeMethod->invoke(null, ['name' => 'exam.docx', 'tmp_name' => $tmpF]);
check('exam decode maps docx to examJson rows', $errs === [] && count($rows) === 1);
check('exam row carries question_text + options + answer', $rows[0]['question_text'] === 'What is the SI unit of force?'
    && $rows[0]['option_b'] === 'Newton' && $rows[0]['correct_answer'] === 'b');

// A .docx with an unmarked answer → refused (no silent wrong key).
$docxG = xmlDoc([
    ['t' => '1. What is the SI unit of force?'],
    ['t' => 'A. Watt'],
    ['t' => 'B. Newton'],
    ['t' => 'C. Joule'],
    ['t' => 'D. Pascal'],
    ['t' => 'Answer: B'],
    ['t' => '2. Which planet is closest to the Sun?'],
    ['t' => 'A. Venus'],
    ['t' => 'B. Mercury'],
    ['t' => 'C. Earth'],
    ['t' => 'D. Mars'],
]);
file_put_contents($tmpF, buildDocxZip($docxG));
[$errs, $rows] = $decodeMethod->invoke(null, ['name' => 'exam.docx', 'tmp_name' => $tmpF]);
check('exam import refuses questions without a detected answer', $errs !== [] && $rows === [] && str_contains($errs[0], 'correct answer'));

// A corrupt upload must produce a friendly error, never a 500.
file_put_contents($tmpF, 'this is definitely not a zip archive');
[$errs, $rows] = $decodeMethod->invoke(null, ['name' => 'exam.docx', 'tmp_name' => $tmpF]);
check('corrupt docx rejected with a friendly error', $errs !== [] && $rows === [] && str_contains($errs[0], 'could not be read'));
unlink($tmpF);

// ---------------------------------------------------------------------------
// G. Word list numbering (numId) + reading passage (the real-file scenario)
// ---------------------------------------------------------------------------
echo "\nDOCX — numId stems + reading passage\n";

$docG = xmlDoc([
    ['t' => 'Reading Comprehension Passage'],
    ['t' => '[Paragraph 1]'],
    ['t' => 'The transition from printed pages to digital screens has fundamentally altered how humans consume information, reshaping attention spans and study habits in ways that were unimaginable a generation ago.'],
    ['t' => '[Paragraph 2]'],
    ['t' => 'In the digital realm, readers are inundated with sensory distractions such as hyperlinks, pop-ups and notifications that fragment sustained attention and encourage shallow skimming over deep comprehension.'],
    ['t' => 'Which of the following best expresses the main idea of the passage?', 'num' => true],
    ['t' => 'A) Digital media is entirely destructive.'],
    ['t' => 'B) The shift to digital reading alters neural pathways and requires balanced cultivation of both skimming and deep reading skills.'],
    ['t' => 'C) Bi-literacy is an unrealistic goal.'],
    ['t' => 'D) Printed books are superior in every metric.'],
    ['t' => 'Answer: B'],
    ['t' => 'Choose the word that best completes the sentence:', 'num' => true],
    ['t' => 'Despite the team best efforts, the project fell through due to a lack of ________ funding.'],
    ['t' => 'A) superficial'],
    ['t' => 'B) inadequate'],
    ['t' => 'C) sufficient'],
    ['t' => 'D) redundant'],
    ['t' => 'Answer: C'],
]);
file_put_contents($tmp, buildDocxZip($docG));
$res = DocxQuestionParser::parseFile($tmp, 'docx');
check('numId doc: 1 passage + 2 questions parsed', count($res['questions']) === 3
    && $res['questions'][0]['type'] === 'Passage'
    && count(array_filter($res['questions'], fn ($q) => $q['type'] !== 'Passage')) === 2);
check('passage keeps its paragraphs', str_contains($res['questions'][0]['question'], '[Paragraph 2]')
    && str_contains($res['questions'][0]['question'], 'digital realm'));
check('numId stem captured (no literal number in text)', $res['questions'][1]['question'] === 'Which of the following best expresses the main idea of the passage?');
check('split stem rejoined across two paragraphs', $res['questions'][2]['question'] === 'Choose the word that best completes the sentence: Despite the team best efforts, the project fell through due to a lack of ________ funding.');
check('answers detected for both', $res['questions'][1]['correct_answer'] === 'b' && $res['questions'][2]['correct_answer'] === 'c');
check('passage warning emitted', count($res['warnings']) === 1 && str_contains($res['warnings'][0], 'passage'));

// The same document through the exam module keeps the passage as a passage block.
[$errs, $rows] = $decodeMethod->invoke(null, ['name' => 'exam.docx', 'tmp_name' => $tmp]);
check('exam decode keeps passage as a passage block', $errs === [] && count($rows) === 3
    && $rows[0]['type'] === 'passage' && str_contains($rows[0]['content'], '[Paragraph 1]')
    && $rows[1]['type'] === 'question' && $rows[1]['correct_answer'] === 'b');
unlink($tmp);
@unlink($tmp);

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
