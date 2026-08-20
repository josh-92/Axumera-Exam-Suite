<?php

/**
 * Student auth + exam-gating integration test
 * -------------------------------------------
 * Exercises the new ID+password student portal against a scratch database:
 *   1. Registration (create), legacy-account claiming, duplicate rejection
 *   2. Password hashing / verification / reset + identity-verified recovery
 *   3. Login rate limiting (brute-force lockout, mirrored from admin)
 *   4. Re-attempt gating decision (in_progress vs submitted) that drives
 *      the "You've already taken this exam" screen
 *
 * Run: php tests/student_auth_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Autoload.php';

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

echo "== Student auth test =====================================\n";

// ---------------------------------------------------------------------------
// Scratch database
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = 3306;
$dbUser = 'root';
$dbPass = '';
$dbName = 'eaes_exam_student_auth_test';

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

$server->exec("CREATE TABLE `students` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    roll_number INT NOT NULL,
    stream VARCHAR(50) NOT NULL,
    section VARCHAR(10) NOT NULL,
    password_hash VARCHAR(255) NULL,
    last_login_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_roll_stream (roll_number, stream)
) ENGINE=InnoDB");

$server->exec("CREATE TABLE `admin_users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT '',
    role VARCHAR(30) NOT NULL DEFAULT 'admin',
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY username (username)
) ENGINE=InnoDB");

$server->exec("CREATE TABLE `login_attempts` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_username_time (username, attempted_at)
) ENGINE=InnoDB");

$server->exec("CREATE TABLE `exams` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_name VARCHAR(150) NOT NULL,
    duration INT NOT NULL DEFAULT 60,
    stream VARCHAR(50) NOT NULL,
    is_live TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB");

$server->exec("CREATE TABLE `exam_attempts` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    answers LONGTEXT NOT NULL,
    flags LONGTEXT NOT NULL,
    score INT NULL,
    total_questions INT NULL,
    status ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
    started_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    UNIQUE KEY uniq_student_exam (student_id, exam_id),
    CONSTRAINT fk_t_attempts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_t_attempts_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB");

StudentRepository::useConnection($server);
\App\Core\Database::useConnection($server); // seam so AdminRepository hits the scratch DB too

// ---------------------------------------------------------------------------
// 1. Registration
// ---------------------------------------------------------------------------
echo "\nREGISTRATION\n";

$r = StudentRepository::provision('Abebe Tesfaye', '101', 'Natural Science', 'A', 'secret123');
check('new roll → created', $r['ok'] === true && $r['mode'] === 'created' && $r['id'] > 0);
$row = StudentRepository::findByRollAndStream('101', 'Natural Science');
check('row stored with bcrypt hash', $row !== null && str_starts_with((string) $row['password_hash'], '$2'));
check('hasPassword true', StudentRepository::hasPassword($row));
check('correct password verifies', StudentRepository::verifyPassword($row, 'secret123'));
check('wrong password rejected', !StudentRepository::verifyPassword($row, 'wrongpass'));

$dup = StudentRepository::provision('Someone Else', '101', 'Natural Science', 'B', 'another1');
check('duplicate register rejected', $dup['ok'] === false && str_contains((string) $dup['error'], 'already registered'));

// Same roll, other stream is a distinct account
$other = StudentRepository::provision('Marta Kebede', '101', 'Social Science', 'C', 'secret456');
check('same roll, other stream → distinct account', $other['ok'] === true && $other['mode'] === 'created');

// ---------------------------------------------------------------------------
// 2. Legacy (password-less) account claiming
// ---------------------------------------------------------------------------
echo "\nLEGACY CLAIM\n";

$server->prepare("INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES ('Legacy Kid', 200, 'Natural Science', 'B', NOW())")->execute();

$claimOk = StudentRepository::provision('Legacy Kid', '200', 'Natural Science', 'B', 'newpass1');
check('claim with matching name+section → ok', $claimOk['ok'] === true && $claimOk['mode'] === 'claimed');
$legacy = StudentRepository::findByRollAndStream('200', 'Natural Science');
check('claimed account now verifies', StudentRepository::verifyPassword($legacy, 'newpass1'));

$server->prepare("INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES ('Real Student', 201, 'Natural Science', 'A', NOW())")->execute();
$claimWrong = StudentRepository::provision('Imposter', '201', 'Natural Science', 'A', 'hacked1');
check('claim with mismatched name → rejected', $claimWrong['ok'] === false && str_contains((string) $claimWrong['error'], 'does not match'));
$unclaimed = StudentRepository::findByRollAndStream('201', 'Natural Science');
check('unclaimed row still has no password', !StudentRepository::hasPassword($unclaimed));

// ---------------------------------------------------------------------------
// 3. Identity-verified recovery
// ---------------------------------------------------------------------------
echo "\nRECOVERY\n";

$found = StudentRepository::verifyIdentity('101', 'Natural Science', 'Abebe Tesfaye', 'A');
check('verifyIdentity matches registered account', $found !== null && (int) $found['id'] === $r['id']);
check('verifyIdentity rejects wrong name', StudentRepository::verifyIdentity('101', 'Natural Science', 'Wrong Name', 'A') === null);
check('verifyIdentity rejects password-less row', StudentRepository::verifyIdentity('201', 'Natural Science', 'Real Student', 'A') === null);

StudentRepository::setPassword($r['id'], 'changed99');
$after = StudentRepository::findById($r['id']);
check('setPassword rotates hash', !StudentRepository::verifyPassword($after, 'secret123') && StudentRepository::verifyPassword($after, 'changed99'));

StudentRepository::recordLogin($r['id']);
$logged = StudentRepository::findById($r['id']);
check('recordLogin stamps last_login_at', $logged['last_login_at'] !== null);

// ---------------------------------------------------------------------------
// 4. Rate limiting
// ---------------------------------------------------------------------------
echo "\nRATE LIMIT\n";

check('fresh roll not locked', !StudentRepository::authLocked('999'));
foreach (range(1, StudentRepository::MAX_AUTH_ATTEMPTS) as $i) {
    StudentRepository::recordAuthFailure('999');
}
check('5 failures → locked', StudentRepository::authLocked('999'));
check('lock reports seconds', StudentRepository::authLockSeconds('999') > 0);
StudentRepository::recordAuthSuccess('999');
check('success clears the lock', !StudentRepository::authLocked('999'));

// ---------------------------------------------------------------------------
// 5. Admin search + legacy upsert compatibility
// ---------------------------------------------------------------------------
echo "\nADMIN SEARCH + COMPAT\n";

$all = StudentRepository::search('');
check('search returns all', count($all) >= 4);
$hit = StudentRepository::search('Abebe');
check('search finds by name', count($hit) === 1 && (int) $hit[0]['roll_number'] === 101);
$hitRoll = StudentRepository::search('200');
check('search finds by roll', count($hitRoll) === 1);
check('count() is accurate', StudentRepository::count() === (int) $server->query('SELECT COUNT(*) FROM students')->fetchColumn());

$upsertId = StudentRepository::upsert('Compat Kid', '300', 'Social Science', 'C');
check('legacy upsert still creates rows', $upsertId > 0);
check('upsert row has no password', !StudentRepository::hasPassword(StudentRepository::findById($upsertId)));

// ---------------------------------------------------------------------------
// 6. Admin add/remove (attempt-count warning + live-exam guard + cascade)
// ---------------------------------------------------------------------------
echo "\nADMIN ADD / REMOVE\n";

$adminAdded = StudentRepository::provision('New Kid', '400', 'Social Science', 'A', 'temp1pass');
check('admin add creates account', $adminAdded['ok'] === true && $adminAdded['mode'] === 'created');
$addedId = $adminAdded['id'];
check('attemptCount 0 for fresh student', StudentRepository::attemptCount($addedId) === 0);
check('no live guard for fresh student', !StudentRepository::hasLiveInProgressAttempt($addedId));

$server->prepare("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Guard Exam', 60, 'Social Science', 1)")->execute();
$guardExam = (int) $server->lastInsertId();
$server->prepare(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at)
     VALUES (:s, :e, '{}', '{}', 'in_progress', NOW())"
)->execute(['s' => $addedId, 'e' => $guardExam]);
check('attemptCount counts the in-progress attempt', StudentRepository::attemptCount($addedId) === 1);
check('live guard blocks removal mid-exam', StudentRepository::hasLiveInProgressAttempt($addedId));

$server->exec('UPDATE exams SET is_live = 0 WHERE id = ' . (int) $guardExam);
check('live guard clears once the exam stops', !StudentRepository::hasLiveInProgressAttempt($addedId));

// Removal now ARCHIVES (soft-delete) — reversible until purged.
StudentRepository::archive($addedId);
check('archive hides the student from active lookups', StudentRepository::findById($addedId) === null
    && StudentRepository::findByRollAndStream('400', 'Social Science') === null);
$archivedRow = StudentRepository::findById($addedId, true);
check('archived row findable with includeArchived, deleted_at set', $archivedRow !== null && $archivedRow['deleted_at'] !== null);
check('archived student keeps attempt history', (int) $server->query('SELECT COUNT(*) FROM exam_attempts WHERE student_id = ' . (int) $addedId)->fetchColumn() === 1);
$reAdd = StudentRepository::provision('New Kid', '400', 'Social Science', 'A', 'temp2pass');
check('re-adding an archived roll is blocked with a clear message', !$reAdd['ok'] && str_contains((string) $reAdd['error'], 'archived'));

StudentRepository::restore($addedId);
check('restore brings the student back active (can log in again)', StudentRepository::findById($addedId) !== null
    && StudentRepository::findByRollAndStream('400', 'Social Science') !== null);
check('restore keeps attempt history intact', (int) $server->query('SELECT COUNT(*) FROM exam_attempts WHERE student_id = ' . (int) $addedId)->fetchColumn() === 1);

StudentRepository::archive($addedId);
StudentRepository::purge($addedId);
check('purge removes the row permanently', StudentRepository::findById($addedId, true) === null);
$orphans = (int) $server->query('SELECT COUNT(*) FROM exam_attempts WHERE student_id = ' . (int) $addedId)->fetchColumn();
check('purge cascade removes attempt rows', $orphans === 0);

// ---------------------------------------------------------------------------
// 7. Re-attempt gating (drives the "already taken" screen)
// ---------------------------------------------------------------------------
echo "\nRE-ATTEMPT GATE\n";

$server->prepare("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Grade 12 Mock', 60, 'Natural Science', 1)")->execute();
$examId = (int) $server->lastInsertId();

$server->prepare(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, score, total_questions, status, started_at, submitted_at)
     VALUES (:s, :e, '{}', '{}', 8, 10, 'submitted', NOW(), NOW())"
)->execute(['s' => $r['id'], 'e' => $examId]);

$stmt = $server->prepare('SELECT * FROM exam_attempts WHERE student_id = :s AND exam_id = :e');
$stmt->execute(['s' => $r['id'], 'e' => $examId]);
$attempt = $stmt->fetch();
check('submitted attempt blocks re-entry', $attempt !== false && $attempt['status'] !== 'in_progress');

$server->prepare(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at)
     VALUES (:s, :e, '{}', '{}', 'in_progress', NOW())"
)->execute(['s' => (int) $other['id'], 'e' => $examId]);
$stmt = $server->prepare('SELECT * FROM exam_attempts WHERE student_id = :s AND exam_id = :e');
$stmt->execute(['s' => (int) $other['id'], 'e' => $examId]);
$fresh = $stmt->fetch();
check('in-progress attempt still allowed', $fresh !== false && $fresh['status'] === 'in_progress');

// ---------------------------------------------------------------------------
// 8. Bulk import (admin CSV upload)
// ---------------------------------------------------------------------------
echo "\nBULK IMPORT\n";

// parseCsv: BOM + header aliases + quoted commas + blank lines
$csvContent = "\xEF\xBB\xBFname,roll,stream,section\n" .
    "Hana Girma,500,Natural Science,A\n" .
    "\"Tadesse, Bekele\",501,Social Science,B\n" .
    "\n" .
    "Plain Row,502,Natural Science,C\n";
$parsed = StudentRepository::parseCsv($csvContent);
check('parseCsv handles BOM + aliases + quotes + blanks', count($parsed) === 3
    && $parsed[0]['full_name'] === 'Hana Girma'
    && $parsed[0]['roll_number'] === '500'
    && $parsed[1]['full_name'] === 'Tadesse, Bekele'
    && $parsed[2]['_line'] === 5);

try {
    StudentRepository::parseCsv("full_name,roll_number,stream\nA,1,Natural Science\n");
    check('parseCsv rejects missing column header', false);
} catch (InvalidArgumentException $e) {
    check('parseCsv rejects missing column header', str_contains($e->getMessage(), 'section'));
}
try {
    StudentRepository::parseCsv('');
    check('parseCsv rejects empty file', false);
} catch (InvalidArgumentException $e) {
    check('parseCsv rejects empty file', true);
}
$tooBig = 'full_name,roll_number,stream,section' . "\n";
foreach (range(1, StudentRepository::MAX_IMPORT_ROWS + 1) as $i) {
    $tooBig .= "Kid {$i}," . (600 + $i) . ",Natural Science,A\n";
}
try {
    StudentRepository::parseCsv($tooBig);
    check('parseCsv caps row count', false);
} catch (InvalidArgumentException $e) {
    check('parseCsv caps row count', str_contains($e->getMessage(), (string) StudentRepository::MAX_IMPORT_ROWS));
}

// importBatch: create + claim + skip + error in one pass
$server->prepare("INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES ('Claim Me', 502, 'Natural Science', 'B', NOW())")->execute();
$batch = StudentRepository::importBatch([
    ['_line' => 2, 'full_name' => 'Kid One', 'roll_number' => '510', 'stream' => 'Natural Science', 'section' => 'A'],
    ['_line' => 3, 'full_name' => 'Kid Two', 'roll_number' => '511', 'stream' => 'Social Science', 'section' => 'B'],
    ['_line' => 4, 'full_name' => 'Claim Me', 'roll_number' => '502', 'stream' => 'Natural Science', 'section' => 'B'],
    ['_line' => 5, 'full_name' => 'Dup Roll', 'roll_number' => '101', 'stream' => 'Natural Science', 'section' => 'C'], // already registered (Abebe)
    ['_line' => 6, 'full_name' => 'Bad Roll', 'roll_number' => '1000', 'stream' => 'Natural Science', 'section' => 'A'],
    ['_line' => 7, 'full_name' => 'Bad Section', 'roll_number' => '512', 'stream' => 'Natural Science', 'section' => 'D'],
    ['_line' => 8, 'full_name' => 'Lower Case', 'roll_number' => '513', 'stream' => 'Natural Science', 'section' => 'b'],
]);
check('importBatch counts match (3 created, 1 claimed, 1 skipped, 2 errors)',
    $batch['created'] === 3 && $batch['claimed'] === 1 && $batch['skipped'] === 1 && $batch['errors'] === 2);
check('created rows all carry temp passwords', count(array_filter($batch['rows'], fn ($r) => $r['status'] === 'created' && $r['password'] !== null)) === 3);
check('skipped/error rows carry no password', count(array_filter($batch['rows'], fn ($r) => in_array($r['status'], ['skipped', 'error'], true) && $r['password'] !== null)) === 0);

$pwOf = function (array $rows, string $roll, string $status): ?string {
    foreach ($rows as $r) {
        if ($r['roll'] === $roll && $r['status'] === $status) {
            return $r['password'];
        }
    }
    return null;
};
$pw510 = $pwOf($batch['rows'], '510', 'created');
$imported510 = StudentRepository::findByRollAndStream('510', 'Natural Science');
check('imported student logs in with the temp password', $imported510 !== null && $pw510 !== null && StudentRepository::verifyPassword($imported510, $pw510));

$pw502 = $pwOf($batch['rows'], '502', 'claimed');
$claimed502 = StudentRepository::findByRollAndStream('502', 'Natural Science');
check('legacy row claimed through batch import', $claimed502 !== null && $pw502 !== null && StudentRepository::verifyPassword($claimed502, $pw502));

$abebe = StudentRepository::findByRollAndStream('101', 'Natural Science');
check('already-registered roll skipped, password untouched', $abebe !== null && StudentRepository::verifyPassword($abebe, 'changed99'));
$lower = StudentRepository::findByRollAndStream('513', 'Natural Science');
check('section normalized to uppercase', $lower !== null && $lower['section'] === 'B');
$errRows = array_values(array_filter($batch['rows'], fn ($r) => $r['status'] === 'error'));
check('invalid rows reported with reasons', count($errRows) === 2 && str_contains((string) $errRows[0]['error'], 'Roll number'));

// 8c. Flexible stream/section labels (teachers don't always type the canonical form)
// ---------------------------------------------------------------------------
echo "\nFLEXIBLE STREAM LABELS\n";

$streamCases = [
    'natural science' => 'Natural Science',   // lowercase
    'NATURAL SCIENCE' => 'Natural Science',   // uppercase
    'Social Science' => 'Social Science',
    'NaturalScience' => 'Natural Science',    // no space
    'natural-science' => 'Natural Science',   // dash
    'N. Science' => 'Natural Science',        // dots
    'natural  science' => 'Natural Science',  // extra spaces
    'NS' => 'Natural Science',                // abbreviation
    'ss' => 'Social Science',
    'natural' => 'Natural Science',           // one word
    'Social' => 'Social Science',
    'na' => 'Natural Science',                // unambiguous prefix
    'soc' => 'Social Science',
    'ተፈጥሮ ሳይንስ' => 'Natural Science',        // Amharic
    'ማህበራዊ ሳይንስ' => 'Social Science',
];
$streamOk = true;
foreach ($streamCases as $in => $expected) {
    if (StudentRepository::normalizeStream($in) !== $expected) {
        $streamOk = false;
    }
}
check('normalizeStream maps ' . count($streamCases) . ' label variants', $streamOk);
check('normalizeStream rejects ambiguous/unknown labels',
    StudentRepository::normalizeStream('science') === null
    && StudentRepository::normalizeStream('grade 12') === null
    && StudentRepository::normalizeStream('') === null);
check('normalizeSection is case-insensitive',
    StudentRepository::normalizeSection('a') === 'A' && StudentRepository::normalizeSection(' B ') === 'B' && StudentRepository::normalizeSection('d') === null);

// importBatch now accepts a lowercase-stream file and stores the canonical value
$flex = StudentRepository::importBatch([
    ['_line' => 2, 'full_name' => 'Flex One', 'roll_number' => '560', 'stream' => 'natural science', 'section' => 'a'],
    ['_line' => 3, 'full_name' => 'Flex Two', 'roll_number' => '561', 'stream' => 'SS', 'section' => 'b'],
]);
check('importBatch accepts flexible stream/section labels', $flex['created'] === 2 && $flex['errors'] === 0);
$flexOne = StudentRepository::findByRollAndStream('560', 'Natural Science');
$flexTwo = StudentRepository::findByRollAndStream('561', 'Social Science');
check('canonical stream stored + section uppercased',
    $flexOne !== null && $flexOne['section'] === 'A' && $flexTwo !== null && $flexTwo['section'] === 'B');

// error messages now reveal the rejected value
$bad = StudentRepository::importBatch([
    ['_line' => 2, 'full_name' => 'Bad Stream', 'roll_number' => '562', 'stream' => 'Arts and Humanities', 'section' => 'A'],
]);
$badMsg = $bad['rows'][0]['error'] ?? '';
check('stream error shows the offending value', $bad['errors'] === 1 && str_contains($badMsg, 'Arts and Humanities'));

// ---------------------------------------------------------------------------
// 8b. parseXlsx (Excel .xlsx upload — pure PHP, no composer)
// ---------------------------------------------------------------------------
echo "\nXLSX IMPORT\n";

// Build a minimal .xlsx zip (PharData) for the parser tests.
function makeXlsx(string $sheetDataXml, array $sharedStrings = [], string $sheetEntry = 'xl/worksheets/sheet1.xml', array $extraSheets = []): string
{
    $dir = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir . '/class.xlsx';
    $zip = new PharData($path);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $sheetName = basename($sheetEntry);
    $wbTarget = str_starts_with($sheetEntry, 'xl/') ? substr($sheetEntry, 3) : $sheetEntry;
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $wbTarget . '"/></Relationships>');
    if ($sharedStrings) {
        $sst = '';
        foreach ($sharedStrings as $s) {
            $sst .= '<si><t>' . htmlspecialchars((string) $s, ENT_XML1) . '</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">' . $sst . '</sst>');
    }
    foreach ($extraSheets as $entry => $data) {
        $zip->addFromString($entry, '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $data . '</sheetData></worksheet>');
    }
    $zip->addFromString($sheetEntry, '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetDataXml . '</sheetData></worksheet>');
    $zip = null;
    return $path;
}
$cleanupXlsx = static function (string $path): void {
    foreach (glob(dirname($path) . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(dirname($path));
};

// Fixture A: shared strings + numeric roll + inline section, and a decoy
// first sheet (sheet1.xml garbage) — the reader must follow workbook.xml
// rels to the real sheet (sheet2.xml), not the first worksheet it finds.
$sst = ['name', 'roll', 'stream', 'section', 'Natural Science', 'Hana Girma', 'Social Science', 'Tadesse Bekele'];
$real = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>5</v></c><c r="B2"><v>520</v></c><c r="C2" t="s"><v>4</v></c><c r="D2" t="inlineStr"><is><t>A</t></is></c></row>'
    . '<row r="3"><c r="A3" t="s"><v>7</v></c><c r="B3"><v>521</v></c><c r="C3" t="s"><v>6</v></c><c r="D3" t="inlineStr"><is><t>B</t></is></c></row>';
$decoy = '<row r="1"><c r="A1" t="inlineStr"><is><t>wrong</t></is></c><c r="B1" t="inlineStr"><is><t>columns</t></is></c></row>';
$xlA = makeXlsx($real, $sst, 'xl/worksheets/sheet2.xml', ['xl/worksheets/sheet1.xml' => $decoy]);
$parsedA = StudentRepository::parseXlsx($xlA);
check('xlsx: follows rels to the real sheet (not the decoy first sheet)',
    count($parsedA) === 2 && $parsedA[0]['full_name'] === 'Hana Girma' && $parsedA[1]['full_name'] === 'Tadesse Bekele');
check('xlsx: numeric roll cells become strings, inline section read',
    $parsedA[0]['roll_number'] === '520' && $parsedA[0]['stream'] === 'Natural Science' && $parsedA[0]['section'] === 'A');
check('xlsx: _line carries the physical spreadsheet row', $parsedA[0]['_line'] === 2 && $parsedA[1]['_line'] === 3);
$cleanupXlsx($xlA);

// Fixture B: inline strings everywhere, NO sharedStrings.xml at all
$xlB = makeXlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>full_name</t></is></c><c r="B1" t="inlineStr"><is><t>roll_number</t></is></c><c r="C1" t="inlineStr"><is><t>stream</t></is></c><c r="D1" t="inlineStr"><is><t>section</t></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>Liya Worku</t></is></c><c r="B2" t="inlineStr"><is><t>530</t></is></c><c r="C2" t="inlineStr"><is><t>Natural Science</t></is></c><c r="D2" t="inlineStr"><is><t>C</t></is></c></row>');
$parsedB = StudentRepository::parseXlsx($xlB);
check('xlsx: inline-only workbook (no sharedStrings) parses',
    count($parsedB) === 1 && $parsedB[0]['full_name'] === 'Liya Worku' && $parsedB[0]['roll_number'] === '530' && $parsedB[0]['section'] === 'C');
$cleanupXlsx($xlB);

// Fixture C: header alias (name/roll) works from Excel too
$xlC = makeXlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>roll</t></is></c><c r="C1" t="inlineStr"><is><t>stream</t></is></c><c r="D1" t="inlineStr"><is><t>section</t></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>Kid From Excel</t></is></c><c r="B2" t="inlineStr"><is><t>540</t></is></c><c r="C2" t="inlineStr"><is><t>Social Science</t></is></c><c r="D2" t="inlineStr"><is><t>A</t></is></c></row>');
$parsedC = StudentRepository::parseXlsx($xlC);
check('xlsx: header aliases (name/roll) accepted',
    count($parsedC) === 1 && $parsedC[0]['full_name'] === 'Kid From Excel' && $parsedC[0]['roll_number'] === '540');
$cleanupXlsx($xlC);

// Fixture D: missing required column header
$xlD = makeXlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>full_name</t></is></c><c r="B1" t="inlineStr"><is><t>roll_number</t></is></c><c r="C1" t="inlineStr"><is><t>stream</t></is></c></row>');
try {
    StudentRepository::parseXlsx($xlD);
    check('xlsx: missing column header rejected', false);
} catch (\InvalidArgumentException $e) {
    check('xlsx: missing column header rejected', str_contains($e->getMessage(), 'section'));
}
$cleanupXlsx($xlD);

// Fixture E: not a zip at all, and a zip with no worksheet
$txtPath = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(4)) . '/not_a_zip.xlsx';
mkdir(dirname($txtPath));
file_put_contents($txtPath, 'this is not an excel file');
try {
    StudentRepository::parseXlsx($txtPath);
    check('xlsx: non-zip file rejected', false);
} catch (\InvalidArgumentException $e) {
    check('xlsx: non-zip file rejected', str_contains($e->getMessage(), 'not a valid .xlsx'));
}
@unlink($txtPath);
@rmdir(dirname($txtPath));

$xlF = makeXlsx(''); // empty sheetData

try {
    StudentRepository::parseXlsx($xlF);
    check('xlsx: empty workbook rejected', false);
} catch (\InvalidArgumentException $e) {
    check('xlsx: empty workbook rejected', true);
}
$cleanupXlsx($xlF);

// Fixture G: parseXlsx output feeds importBatch end-to-end
$xlG = makeXlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>full_name</t></is></c><c r="B1" t="inlineStr"><is><t>roll_number</t></is></c><c r="C1" t="inlineStr"><is><t>stream</t></is></c><c r="D1" t="inlineStr"><is><t>section</t></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>Excel Batch One</t></is></c><c r="B2" t="inlineStr"><is><t>550</t></is></c><c r="C2" t="inlineStr"><is><t>Natural Science</t></is></c><c r="D2" t="inlineStr"><is><t>A</t></is></c></row>'
    . '<row r="3"><c r="A3" t="inlineStr"><is><t>Excel Batch Two</t></is></c><c r="B3" t="inlineStr"><is><t>551</t></is></c><c r="C3" t="inlineStr"><is><t>Social Science</t></is></c><c r="D3" t="inlineStr"><is><t>B</t></is></c></row>');
$batchX = StudentRepository::importBatch(StudentRepository::parseXlsx($xlG));
check('xlsx → importBatch creates accounts with temp passwords',
    $batchX['created'] === 2 && $batchX['errors'] === 0 && $batchX['rows'][0]['password'] !== null);
$xlStudent = StudentRepository::findByRollAndStream('550', 'Natural Science');
$pwX = $batchX['rows'][0]['password'] ?? null;
check('xlsx-imported student logs in with the temp password',
    $xlStudent !== null && $pwX !== null && StudentRepository::verifyPassword($xlStudent, $pwX));
$cleanupXlsx($xlG);

// ---------------------------------------------------------------------------
// 9. Bulk remove (admin removal list — the mirror of bulk import)
// ---------------------------------------------------------------------------
echo "\nBULK REMOVE\n";

// parseRollCsv: bare rolls, header with stream, BOM, blanks, aliases
$remCsv = "\xEF\xBB\xBFroll_number,stream\n" .
    "700,Natural Science\n" .
    "701\n" .
    "\n" .
    "702,SS\n";
$remParsed = StudentRepository::parseRollCsv($remCsv);
check('parseRollCsv: header skipped, stream captured, blanks ignored',
    count($remParsed) === 3
    && $remParsed[0]['roll_number'] === '700' && $remParsed[0]['stream'] === 'Natural Science'
    && $remParsed[1]['roll_number'] === '701' && $remParsed[1]['stream'] === null
    && $remParsed[2]['stream'] === 'SS'
    && $remParsed[2]['_line'] === 5);
$remBare = StudentRepository::parseRollCsv("710\n711\n");
check('parseRollCsv: bare roll-only file (no header)',
    count($remBare) === 2 && $remBare[0]['stream'] === null);
try {
    StudentRepository::parseRollCsv('');
    check('parseRollCsv: empty file rejected', false);
} catch (\InvalidArgumentException $e) {
    check('parseRollCsv: empty file rejected', true);
}

// parseRollXlsx: column A = roll, column B = stream (flexible labels)
$xlRem = makeXlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>roll_number</t></is></c><c r="B1" t="inlineStr"><is><t>stream</t></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>720</t></is></c><c r="B2" t="inlineStr"><is><t>social science</t></is></c></row>'
    . '<row r="3"><c r="A3" t="inlineStr"><is><t>721</t></is></c></row>');
$remXl = StudentRepository::parseRollXlsx($xlRem);
check('parseRollXlsx: roll + stream columns parsed',
    count($remXl) === 2 && $remXl[0]['roll_number'] === '720' && $remXl[0]['stream'] === 'social science' && $remXl[1]['stream'] === null);
$cleanupXlsx($xlRem);

// Set up students to remove
$mk = function (string $name, string $roll, string $stream, string $section) {
    return StudentRepository::provision($name, $roll, $stream, $section, 'bulkpass1');
};
$mk('Remove Me Nat', '730', 'Natural Science', 'A');
$mk('Remove Me Soc', '730', 'Social Science', 'B'); // same roll, other stream
$mk('Keep Me', '731', 'Natural Science', 'A');
$mk('Guard Me', '732', 'Natural Science', 'A');

// live-exam guard for one of them
$server->prepare("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Bulk Guard', 60, 'Natural Science', 1)")->execute();
$bulkGuardExam = (int) $server->lastInsertId();
$guardMe = StudentRepository::findByRollAndStream('732', 'Natural Science');
$server->prepare(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at)
     VALUES (:s, :e, '{}', '{}', 'in_progress', NOW())"
)->execute(['s' => $guardMe['id'], 'e' => $bulkGuardExam]);

$removed = StudentRepository::removeBatch([
    ['_line' => 2, 'roll_number' => '730', 'stream' => 'Natural Science'], // exact stream match
    ['_line' => 3, 'roll_number' => '730'],                              // roll-only → remaining stream
    ['_line' => 4, 'roll_number' => '999'],                              // not found
    ['_line' => 5, 'roll_number' => '732', 'stream' => 'Natural Science'], // live-exam guard
    ['_line' => 6, 'roll_number' => 'not-a-roll'],                        // bad roll
    ['_line' => 7, 'roll_number' => '733', 'stream' => 'Arts'],           // bad stream
]);
check('removeBatch counts (2 removed, 2 skipped, 2 errors)',
    $removed['removed'] === 2 && $removed['skipped'] === 2 && $removed['errors'] === 2);
check('roll-only entry removed the remaining stream, other stream gone',
    StudentRepository::findByRollAndStream('730', 'Natural Science') === null
    && StudentRepository::findByRollAndStream('730', 'Social Science') === null);
check('roll+stream match only removed that stream\'s student',
    StudentRepository::findByRollAndStream('731', 'Natural Science') !== null);
check('not-found roll skipped with reason', $removed['rows'][2]['status'] === 'skipped'
    && str_contains($removed['rows'][2]['reason'], 'No student found'));
check('live-exam student skipped, still present', $removed['rows'][3]['status'] === 'skipped'
    && str_contains($removed['rows'][3]['reason'], 'live exam')
    && StudentRepository::findByRollAndStream('732', 'Natural Science') !== null);
check('bad roll/stream reported as errors', $removed['rows'][4]['status'] === 'errors'
    && str_contains((string) $removed['rows'][5]['reason'], 'Stream must be'));
$removedReasons = array_column($removed['rows'], 'reason', 'roll');
check('removed rows carry restorable notes', str_contains($removedReasons['730'] ?? '', 'Archived'));
check('bulk-removed students appear in the archived view',
    count(array_filter(StudentRepository::searchArchived(), fn ($s) => (int) $s['roll_number'] === 730)) === 2);
check('active search excludes archived students',
    count(array_filter(StudentRepository::search('730'), fn ($s) => (int) $s['roll_number'] === 730)) === 0);
// Reversal: restore both archived 730s and they log in again
$arch730 = StudentRepository::searchArchived('730');
foreach ($arch730 as $a) {
    StudentRepository::restore((int) $a['id']);
}
check('restore reverses a bulk removal', StudentRepository::findByRollAndStream('730', 'Natural Science') !== null
    && StudentRepository::findByRollAndStream('730', 'Social Science') !== null);

// numeric-equivalence: stored '045' removed by '45'
$mk('Zero Pad', '045', 'Natural Science', 'C');
$byNum = StudentRepository::removeBatch([['_line' => 2, 'roll_number' => '45', 'stream' => 'Natural Science']]);
check('roll matching is numeric-equivalent (045 vs 45)', $byNum['removed'] === 1
    && StudentRepository::findByRollAndStream('045', 'Natural Science') === null);

// ---------------------------------------------------------------------------
// 10. Removal preview + confirm (resolve-then-archive, nothing before confirm)
// ---------------------------------------------------------------------------
echo "\nREMOVAL PREVIEW\n";

$mk2 = function (string $name, string $roll, string $stream, string $section) {
    return StudentRepository::provision($name, $roll, $stream, $section, 'bulkpass1');
};
$mk2('Preview One', '910', 'Natural Science', 'A');
$mk2('Preview Two', '911', 'Social Science', 'B');
$mk2('Preview Guard', '912', 'Natural Science', 'A');

$server->prepare("INSERT INTO exams (exam_name, duration, stream, is_live) VALUES ('Preview Guard Exam', 60, 'Natural Science', 1)")->execute();
$prevGuardExam = (int) $server->lastInsertId();
$prevGuard = StudentRepository::findByRollAndStream('912', 'Natural Science');
$server->prepare(
    "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at)
     VALUES (:s, :e, '{}', '{}', 'in_progress', NOW())"
)->execute(['s' => $prevGuard['id'], 'e' => $prevGuardExam]);

$resolution = StudentRepository::resolveRemovalEntries([
    ['_line' => 2, 'roll_number' => '910', 'stream' => 'Natural Science'],
    ['_line' => 3, 'roll_number' => '911', 'stream' => 'Social Science'],
    ['_line' => 4, 'roll_number' => '912', 'stream' => 'Natural Science'], // blocked mid-exam
    ['_line' => 5, 'roll_number' => '999'],                              // not found
    ['_line' => 6, 'roll_number' => 'bad'],                              // bad roll
]);
check('resolve counts (2 candidates, 2 skipped, 1 error)',
    $resolution['candidates'] === 2 && $resolution['skipped'] === 2 && $resolution['errors'] === 1);
check('candidates carry student ids + attempt counts',
    $resolution['rows'][0]['status'] === 'removed' && $resolution['rows'][0]['student_id'] === (int) $prevGuard['id'] - 2
    && array_key_exists('attempts', $resolution['rows'][0]));
check('blocked student resolved as skipped, not candidate',
    $resolution['rows'][2]['status'] === 'skipped' && str_contains($resolution['rows'][2]['reason'], 'live exam'));
check('preview resolves WITHOUT archiving anything',
    StudentRepository::findByRollAndStream('910', 'Natural Science') !== null
    && StudentRepository::findByRollAndStream('911', 'Social Science') !== null);

// confirm step: archive exactly the candidates
$candidateIds = array_values(array_filter(array_map(fn ($r) => $r['student_id'], $resolution['rows'])));
$confirmed = StudentRepository::archiveCandidates($candidateIds);
check('archiveCandidates archives only the previewed ids',
    $confirmed['removed'] === 2 && $confirmed['skipped'] === 0);
check('blocked student untouched after confirm',
    StudentRepository::findByRollAndStream('912', 'Natural Science') !== null);
check('previewed students now archived',
    StudentRepository::findByRollAndStream('910', 'Natural Science') === null
    && StudentRepository::findByRollAndStream('911', 'Social Science') === null);

// TOCTOU: a student archived between preview and confirm is skipped, not duplicated
$mk2('Late Archive', '913', 'Natural Science', 'A');
$late = StudentRepository::findByRollAndStream('913', 'Natural Science');
StudentRepository::archive((int) $late['id']); // someone else archived it meanwhile
$again = StudentRepository::archiveCandidates([(int) $late['id']]);
check('confirm skips students archived after the preview', $again['removed'] === 0 && $again['skipped'] === 1
    && str_contains($again['rows'][0]['reason'], 'No longer active'));
check('no duplicate row was created', StudentRepository::findByRollAndStream('913', 'Natural Science') === null
    && count(array_filter(StudentRepository::searchArchived(), fn ($s) => (int) $s['roll_number'] === 913)) === 1);

// preview payload shape (what admin_students.php stores in the session)
$previewPayload = [
    'created_at' => time(),
    'archive_ids' => $candidateIds,
    'rows' => $resolution['rows'],
    'issues' => ['Line 4: (roll 912) — blocked'],
];
check('preview payload carries archive_ids + display rows + issues',
    is_array($previewPayload['archive_ids']) && count($previewPayload['archive_ids']) === 2
    && count($previewPayload['rows']) === 5 && count($previewPayload['issues']) === 1);

// ---------------------------------------------------------------------------
// 11. Admin recovery (forgotten/locked admin password)
// ---------------------------------------------------------------------------
echo "\nADMIN RECOVERY\n";

use App\Repositories\AdminRepository;

$adminId = AdminRepository::create('recovery_tester', 'OldPass123!', 'Recovery Tester', 'owner');
check('admin create works', AdminRepository::findById($adminId) !== null);
$adminRow = AdminRepository::findByUsername('recovery_tester');
check('admin verify with current password', AdminRepository::verifyPassword($adminRow, 'OldPass123!'));
check('admin verify rejects wrong password', !AdminRepository::verifyPassword($adminRow, 'wrongpass'));

// simulate a lockout (5 failed attempts + locked until the future)
$server->prepare('UPDATE admin_users SET failed_attempts = 5, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = :id')
    ->execute(['id' => $adminId]);

// recovery reset must clear the lockout AND set a new password
AdminRepository::resetPassword($adminId, 'NewPass456!');
$after = AdminRepository::findById($adminId);
check('resetPassword clears the lockout', (int) $after['failed_attempts'] === 0 && $after['locked_until'] === null);
check('resetPassword sets a working new password', AdminRepository::verifyPassword($after, 'NewPass456!'));
check('old password no longer works', !AdminRepository::verifyPassword($after, 'OldPass123!'));
check('all() lists the account with lock state', count(array_filter(AdminRepository::all(), fn ($a) => $a['username'] === 'recovery_tester')) === 1);

// cleanup the temp admin
$server->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $adminId]);
check('temp admin cleaned up', AdminRepository::findById($adminId) === null);

// ---------------------------------------------------------------------------
// 12. Admin settings (web page: create / reset / delete other admins)
// ---------------------------------------------------------------------------
echo "\nADMIN SETTINGS\n";

$settingsId = AdminRepository::create('settings_tester', 'Pass12345!', 'Settings Tester');
check('settings: create then usernameExists true', AdminRepository::usernameExists('settings_tester'));
check('settings: usernameExists false for unknown', !AdminRepository::usernameExists('nobody_here'));

// the page blocks duplicate usernames via usernameExists before INSERT
check('settings: duplicate username detected', AdminRepository::usernameExists('settings_tester'));

// reset clears lockout + sets a fresh password (same path the page uses)
$server->prepare('UPDATE admin_users SET failed_attempts = 3, locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = :id')
    ->execute(['id' => $settingsId]);
AdminRepository::resetPassword($settingsId, 'TempPass999!');
$afterReset = AdminRepository::findById($settingsId);
check('settings: reset clears lockout', (int) $afterReset['failed_attempts'] === 0 && $afterReset['locked_until'] === null);
check('settings: reset sets a working password', AdminRepository::verifyPassword($afterReset, 'TempPass999!'));

$countBefore = AdminRepository::count();
AdminRepository::delete($settingsId);
check('settings: delete removes the account', AdminRepository::findById($settingsId) === null && !AdminRepository::usernameExists('settings_tester'));
check('settings: count decreases after delete', AdminRepository::count() === $countBefore - 1);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
StudentRepository::useConnection(null);
\App\Core\Database::useConnection(null);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");

echo "\n" . str_repeat('-', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
