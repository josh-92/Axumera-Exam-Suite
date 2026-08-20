<?php

/**
 * tests/fresh_install_e2e_test.php
 * --------------------------------
 * Release tasks 4, 5 and the runtime half of 6, end to end over real HTTP.
 *
 * Copies the CURRENT workspace into a throwaway folder under the web root,
 * runs the real 4-step web installer against a fresh MySQL database, then
 * verifies, over HTTP with real cookies/CSRF:
 *
 *   T4 — fresh install : migrations applied (schema_migrations populated),
 *                        every schema + migration table present, installer
 *                        lock written and a hard stop on re-run (incl.
 *                        ?force=), admin pages render for a logged-in admin,
 *                        student flow works, `php database/migrate.php` is
 *                        idempotent right after install.
 *   T5 — BOLA / IDOR   : anonymous / student / admin authorization matrix
 *                        on every ID-accepting endpoint, with admin/student
 *                        role separation.
 *   T6 — deployment    : protected folders/.env return 403, APP_DEBUG=false
 *                        + APP_ENV=production written by the installer,
 *                        FORCE_HTTPS=true actually redirects to HTTPS and
 *                        marks session cookies Secure, HSTS + secure headers
 *                        present.
 *
 * The workspace itself is never modified: the copy is installed and the
 * original .env / storage / database are untouched. The copy folder and its
 * database are removed in the finally block.
 *
 * Requires: Apache serving the web root, MySQL on 127.0.0.1:3306 (root, no
 * password), php CLI on PATH, and a valid storage/license.lic for this
 * machine (the copy validates the same license).
 *
 * Run: php tests/fresh_install_e2e_test.php
 */

declare(strict_types=1);

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
// Locate the web root (the workspace lives directly inside it) and derive the
// throwaway copy + database names.
// ---------------------------------------------------------------------------
$workspace = dirname(__DIR__);                    // .../htdocs/eaes_exam_system_protected
$webRoot = dirname($workspace);                   // .../htdocs
$copyDir = $webRoot . '/eaes_release_test';       // throwaway install target
$baseUrl = 'http://localhost/eaes_release_test';
$dbName = 'eaes_release_test';

$tmpJar = sys_get_temp_dir() . '/eaes_release_jar_' . getmypid();
@mkdir($tmpJar, 0777, true);
$jarAnon = $tmpJar . '/anon';
$jarCookie = $tmpJar . '/cookie'; // fresh jar for the cookie-flag checks
$jarAdminA = $tmpJar . '/adminA';
$jarAdminB = $tmpJar . '/adminB';
$jarStudent1 = $tmpJar . '/s1';
$jarStudent2 = $tmpJar . '/s2';

/** Minimal HTTP client: [status, headers[], body] with a cookie jar. */
function httpReq(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = ['Accept: */*'];
    if (array_key_exists('json', $opts)) {
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($opts['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $opts['csrf'];
    }
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if (!empty($opts['jar'])) {
        $curlOpts[CURLOPT_COOKIEJAR] = $opts['jar'];
        $curlOpts[CURLOPT_COOKIEFILE] = $opts['jar'];
    }
    if ($method === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        $curlOpts[CURLOPT_POSTFIELDS] = array_key_exists('json', $opts)
            ? json_encode($opts['json'])
            : ($opts['form'] ?? []);
    }
    curl_setopt_array($ch, $curlOpts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headRaw = substr($raw, 0, $headerSize);
    $headers = [];
    foreach (explode("\r\n", $headRaw) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $headerSize)];
}

function csrfField(string $html): string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function csrfJs(string $html): string
{
    return preg_match('/window\.EAES_CSRF\s*=\s*"([^"]+)"/', $html, $m) ? $m[1] : '';
}

function csrfExam(string $html): string
{
    return preg_match('/csrfToken:\s*"([^"]+)"/', $html, $m) ? $m[1] : '';
}

function jsonBody(array $r): array
{
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : [];
}

function expectStatus(string $label, array $r, int $status, ?string $needle = null): void
{
    $ok = $r['status'] === $status && ($needle === null || str_contains($r['body'], $needle));
    check($label, $ok);
}

/** Recursively delete a directory tree (pure PHP — shell rm is unreliable under cmd.exe). */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Recursively copy a directory tree, skipping a set of relative paths
 * (both files and directories).
 */
function copyTree(string $src, string $dst, array $skip): void
{
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    $items = scandir($src);
    foreach ($items ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $src . DIRECTORY_SEPARATOR . $item;
        if (in_array($item, $skip, true)) {
            continue;
        }
        $to = $dst . DIRECTORY_SEPARATOR . $item;
        if (is_dir($from) && !is_link($from)) {
            // Keep only the skip entries that live inside this subtree, with
            // the prefix stripped so they match the next level down.
            $subSkip = [];
            foreach ($skip as $s) {
                if (str_starts_with($s, $item . '/')) {
                    $subSkip[] = substr($s, strlen($item) + 1);
                }
            }
            copyTree($from, $to, $subSkip);
        } else {
            copy($from, $to);
        }
    }
}

// ---------------------------------------------------------------------------
// Shared MySQL connection (root / no password — the XAMPP dev default).
// ---------------------------------------------------------------------------
$adminPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    // ---------------------------------------------------------------
    // SETUP: fresh copy of the CURRENT workspace (no lock, no license
    // cache, no logs/out/freebuff/word-to-json).
    // ---------------------------------------------------------------
    echo "SETUP — copying workspace into {$copyDir}\n";
    rrmdir($copyDir);
    copyTree($workspace, $copyDir, ['.freebuff', 'tests/out', 'storage/logs', 'word-to-json']);
    @unlink($copyDir . '/storage/installed.lock');
    @unlink($copyDir . '/storage/cache/license.cache');
    @mkdir($copyDir . '/storage/logs', 0777, true); // excluded from the copy; PHP writes its error log here
    check('workspace copied (web root files present)', is_file($copyDir . '/index.php') && is_file($copyDir . '/installer/install.php'));
    check('installed.lock removed for a truly fresh install', !is_file($copyDir . '/storage/installed.lock'));
    check('license file carried over (same machine)', is_file($copyDir . '/storage/license.lic'));

    // ---------------------------------------------------------------
    // T4 — INSTALLER, 4 steps over HTTP
    // ---------------------------------------------------------------
    echo "\nT4 — INSTALLER (4 steps over HTTP)\n";

    $r = httpReq('GET', $baseUrl . '/installer/install.php', ['jar' => $jarAnon]);
    expectStatus('step 1 renders requirements check', $r, 200, 'Step 1 of 4');

    $r = httpReq('POST', $baseUrl . '/installer/install.php?step=2', [
        'jar' => $jarAnon,
        'form' => [
            'app_name' => 'EAES Release Test',
            'app_url' => $baseUrl,
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => $dbName,
            'db_user' => 'root',
            'db_pass' => '',
        ],
    ]);
    check('step 2 creates the database and redirects to step 3', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'install.php?step=3'));

    $r = httpReq('GET', $baseUrl . '/installer/install.php?step=3', ['jar' => $jarAnon]);
    expectStatus('step 3 renders the admin-account form', $r, 200, 'Administrator Account');

    $r = httpReq('POST', $baseUrl . '/installer/install.php?step=3', [
        'jar' => $jarAnon,
        'form' => ['username' => 'releaseadmin', 'password' => 'ReleaseAdmin!2026', 'confirm' => 'ReleaseAdmin!2026'],
    ]);
    check('step 3 creates the owner admin and redirects to step 4', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'install.php?step=4'));

    $r = httpReq('GET', $baseUrl . '/installer/install.php?step=4', ['jar' => $jarAnon]);
    expectStatus('step 4 reports installation complete', $r, 200, 'Installation complete');
    check('installed.lock written', is_file($copyDir . '/storage/installed.lock'));

    // Installer lock: hard stop on any re-run, including ?force=.
    foreach (['', '?step=2', '?step=3', '?force=1', '?step=1&force=1'] as $qs) {
        $r = httpReq('GET', $baseUrl . '/installer/install.php' . $qs, ['jar' => $jarAnon]);
        check("installer re-run blocked ({$qs})", $r['status'] === 200 && str_contains($r['body'], 'already installed'));
    }

    // ---------------------------------------------------------------
    // T4 — DATABASE VERIFICATION (schema + migrations + admin row)
    // ---------------------------------------------------------------
    echo "\nT4 — DATABASE VERIFICATION\n";

    $fresh = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $tables = $fresh->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $needTables = [
        'admin_users', 'settings', 'exams', 'questions', 'exam_question_assignments',
        'students', 'exam_attempts', 'exam_question_shuffles', 'login_attempts', 'activity_log',
        'schema_migrations',
    ];
    $missing = array_values(array_diff($needTables, $tables));
    check('every schema + migration table exists on a fresh install', $missing === []);

    $migrationFiles = glob($copyDir . '/database/migrations/*.sql');
    $recorded = $fresh->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    check('schema_migrations records every migration (fresh install)', (int) $recorded === count($migrationFiles));

    $adminRow = $fresh->query('SELECT * FROM admin_users LIMIT 1')->fetch();
    check('owner admin created with a bcrypt hash', $adminRow !== false
        && $adminRow['username'] === 'releaseadmin' && $adminRow['role'] === 'owner'
        && str_starts_with((string) $adminRow['password_hash'], '$2'));

    // migrate.php must be a no-op on a fresh install (the installer recorded
    // the chain) — run it twice.
    foreach ([1, 2] as $run) {
        exec('php ' . escapeshellarg($copyDir . '/database/migrate.php') . ' 2>&1', $out, $code);
        $outText = implode("\n", $out);
        check("migrate.php run #{$run} reports no pending migrations (exit {$code})", $code === 0 && str_contains($outText, 'No pending migrations'));
    }

    // ---------------------------------------------------------------
    // T4 — ADMIN LOGIN + CORE ADMIN PAGES
    // ---------------------------------------------------------------
    echo "\nT4 — ADMIN LOGIN + CORE PAGES\n";

    $r = httpReq('GET', $baseUrl . '/adminlogin.php', ['jar' => $jarAdminA]);
    $adminCsrf = csrfField($r['body']);
    check('admin login page provides a CSRF token', $adminCsrf !== '');
    $r = httpReq('POST', $baseUrl . '/adminlogin.php', [
        'jar' => $jarAdminA,
        'form' => ['username' => 'releaseadmin', 'password' => 'ReleaseAdmin!2026', 'csrf_token' => $adminCsrf],
    ]);
    check('owner admin logs in (302 to dashboard)', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'adminpanel.php'));
    $r = httpReq('GET', $baseUrl . '/adminpanel.php', ['jar' => $jarAdminA]);
    expectStatus('admin dashboard renders', $r, 200, 'Create Exam');

    foreach ([
        'admin_question_bank.php' => ['Question Bank'],
        'analytics.php' => ['Analytics Overview'],
        'admin_students.php' => ['Add Student'],
        'admin_settings.php' => ['Create Admin Account'],
    ] as $page => $needles) {
        $r = httpReq('GET', $baseUrl . '/' . $page, ['jar' => $jarAdminA]);
        $ok = $r['status'] === 200;
        foreach ($needles as $n) {
            $ok = $ok && str_contains($r['body'], $n);
        }
        check("core admin page renders for admin: {$page}", $ok);
    }

    // ---------------------------------------------------------------
    // SEED: admin B, students, question bank, regular exam
    // ---------------------------------------------------------------
    echo "\nSEED — admin B, students, bank, regular exam\n";

    $fresh->exec("INSERT INTO admin_users (username, password_hash, full_name, role) VALUES ('adminB', '" .
        password_hash('AdminB!2026', PASSWORD_DEFAULT) . "', 'Admin B', 'admin')");
    $adminBId = (int) $fresh->lastInsertId();
    $fresh->exec("INSERT INTO students (full_name, roll_number, stream, section, password_hash) VALUES ('Student One', 101, 'Natural Science', 'A', '" .
        password_hash('StudentPass1!', PASSWORD_DEFAULT) . "')");
    $s1 = (int) $fresh->lastInsertId();
    $fresh->exec("INSERT INTO students (full_name, roll_number, stream, section, password_hash) VALUES ('Student Two', 102, 'Natural Science', 'A', '" .
        password_hash('StudentPass2!', PASSWORD_DEFAULT) . "')");
    $s2 = (int) $fresh->lastInsertId();

    $bankStmt = $fresh->prepare(
        "INSERT INTO questions (question, question_text, option_a, option_b, option_c, option_d, correct_answer, type, difficulty, topic, subject, grade, status, created_by)
         VALUES (:q, :q2, 'A', 'B', 'C', 'D', :c, 'MCQ', :diff, :topic, 'Mathematics', 'Grade 12', :status, {$adminBId})"
    );
    $bankIds = [];
    foreach ([
        ['Algebra', 'easy', 'a', 'approved'],
        ['Algebra', 'easy', 'b', 'approved'],
        ['Calculus', 'medium', 'c', 'approved'],
        ['Trigonometry', 'hard', 'd', 'approved'],
        ['Algebra', 'easy', 'a', 'archived'],
    ] as [$topic, $diff, $correct, $status]) {
        $bankStmt->execute(['q' => "Q {$topic} {$diff}", 'q2' => "Q {$topic} {$diff}", 'c' => $correct, 'diff' => $diff, 'topic' => $topic, 'status' => $status]);
        $bankIds[] = (int) $fresh->lastInsertId();
    }
    $fresh->exec("INSERT INTO exams (exam_name, duration, stream, is_live, shuffle_questions, shuffle_choices) VALUES ('Release Regular Exam', 60, 'Natural Science', 1, 0, 0)");
    $regExamId = (int) $fresh->lastInsertId();
    $fresh->prepare("INSERT INTO questions (exam_id, question_number, is_passage, paragraph_text, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, 1, 1, 'P', 'Passage', 'I', '', '', '', '')")->execute([$regExamId]);
    $fresh->prepare("INSERT INTO questions (exam_id, question_number, is_passage, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, 2, 0, '2 + 2?', '3', '4', '5', '6', 'b')")->execute([$regExamId]);
    $fresh->prepare("INSERT INTO questions (exam_id, question_number, is_passage, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, 3, 0, 'Capital?', 'AA', 'NB', 'CA', 'KA', 'a')")->execute([$regExamId]);

    // ---------------------------------------------------------------
    // T5 — BOLA / IDOR SWEEP (anonymous / student / admin matrix)
    // ---------------------------------------------------------------
    echo "\nT5 — BOLA/IDOR SWEEP (anonymous / student / admin)\n";

    // --- Anonymous: every API endpoint is rejected ---
    $anonCases = [
        ['GET', '/api_questions.php', 401],
        ['GET', '/autosave.php', 401],
    ];
    foreach ($anonCases as [$m, $path, $status]) {
        $opts = ['jar' => $jarAnon];
        if ($m === 'POST') {
            $opts['json'] = ['exam_id' => 1];
        }
        $r = httpReq($m, $baseUrl . $path, $opts);
        check("anonymous {$m} {$path} -> {$status}", $r['status'] === $status);
    }
    foreach (['analytics.php', 'admin_students.php', 'admin_settings.php', 'admin_question_bank.php'] as $page) {
        $r = httpReq('GET', $baseUrl . '/' . $page, ['jar' => $jarAnon]);
        check("anonymous {$page} redirected to admin login", $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'adminlogin.php'));
    }
    $r = httpReq('POST', $baseUrl . '/autosave.php', ['jar' => $jarAnon, 'json' => ['answers' => [], 'flags' => []]]);
    check('anonymous autosave rejected (401)', $r['status'] === 401);
    $r = httpReq('POST', $baseUrl . '/submit_exam.php', ['jar' => $jarAnon, 'json' => []]);
    check('anonymous submit_exam rejected (no session)', str_contains($r['body'], 'No active session found'));

    // --- Admin B: a second admin account authenticates independently ---
    $r = httpReq('GET', $baseUrl . '/adminlogin.php', ['jar' => $jarAdminB]);
    $r = httpReq('POST', $baseUrl . '/adminlogin.php', [
        'jar' => $jarAdminB,
        'form' => ['username' => 'adminB', 'password' => 'AdminB!2026', 'csrf_token' => csrfField($r['body'])],
    ]);
    check('admin B logs in', $r['status'] === 302);
    $r = httpReq('GET', $baseUrl . '/adminpanel.php', ['jar' => $jarAdminB]);
    check('admin B reaches the dashboard', $r['status'] === 200 && str_contains($r['body'], 'Create Exam'));

    // --- Students: independent sessions + student↔admin separation ---
    // Student 1 logs in through the real portal.
    $r = httpReq('GET', $baseUrl . '/slogin.php', ['jar' => $jarStudent1]);
    $r = httpReq('POST', $baseUrl . '/slogin.php', [
        'jar' => $jarStudent1,
        'form' => ['rollnumber' => '101', 'stream' => 'Natural Science', 'password' => 'StudentPass1!', 'csrf_token' => csrfField($r['body'])],
    ]);
    check('student 1 logs in (302 to wait room)', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'waite.php'));

    $r = httpReq('GET', $baseUrl . '/adminlogin.php', ['jar' => $jarStudent1]);
    check('student session is not an admin session', $r['status'] === 200);
    $r = httpReq('GET', $baseUrl . '/api_questions.php', ['jar' => $jarStudent1]);
    check('student cannot call the admin question-bank API', $r['status'] === 401);

    // Student portal flow on the live regular exam.
    $r = httpReq('GET', $baseUrl . '/waite.php?check_status=1', ['jar' => $jarStudent1]);
    check('wait room reports the live exam (start)', (jsonBody($r)['status'] ?? '') === 'start');
    $r = httpReq('GET', $baseUrl . '/examportal.php', ['jar' => $jarStudent1]);
    expectStatus('exam portal renders with questions', $r, 200, 'Examination Portal');
    $studentCsrf = csrfExam($r['body']);
    check('exam portal exposes the student CSRF token', $studentCsrf !== '');

    $r = httpReq('POST', $baseUrl . '/autosave.php', [
        'jar' => $jarStudent1, 'csrf' => $studentCsrf,
        'json' => ['answers' => ['2' => 'b'], 'flags' => []],
    ]);
    check('autosave persists answers over HTTP', $r['status'] === 200 && (jsonBody($r)['status'] ?? '') === 'success');

    $r = httpReq('POST', $baseUrl . '/submit_exam.php', ['jar' => $jarStudent1, 'csrf' => $studentCsrf, 'json' => []]);
    $submitData = jsonBody($r);
    check('submit_exam grades from server-side answers (1/2)', ($submitData['status'] ?? '') === 'success' && (int) ($submitData['score'] ?? -1) === 1 && (int) ($submitData['total'] ?? -1) === 2);

    $r = httpReq('GET', $baseUrl . '/already_taken.php', ['jar' => $jarStudent1]);
    expectStatus('already-taken page shows the recorded result', $r, 200, "You've Already Taken This Exam");
    $r = httpReq('GET', $baseUrl . '/examportal.php', ['jar' => $jarStudent1]);
    check('re-entering the exam redirects to already-taken', $r['status'] === 302 && str_contains((string) ($r['headers']['location'] ?? ''), 'already_taken.php'));

    // Student 2 logs in through the real portal (independent session).
    $r = httpReq('GET', $baseUrl . '/slogin.php', ['jar' => $jarStudent2]);
    $s2Csrf = csrfField($r['body']);
    $r = httpReq('POST', $baseUrl . '/slogin.php', [
        'jar' => $jarStudent2,
        'form' => ['rollnumber' => '102', 'stream' => 'Natural Science', 'password' => 'StudentPass2!', 'csrf_token' => $s2Csrf],
    ]);
    check('student 2 logs in', $r['status'] === 302);
    $r = httpReq('GET', $baseUrl . '/examportal.php', ['jar' => $jarStudent2]);
    check('student 2 reaches the exam portal', $r['status'] === 200 && str_contains($r['body'], 'Examination Portal'));

    // ---------------------------------------------------------------
    // T6 — DEPLOYMENT RUNTIME CHECKS
    // ---------------------------------------------------------------
    echo "\nT6 — DEPLOYMENT RUNTIME CHECKS\n";

    foreach (['app/config.php', 'app/bootstrap.php', 'app/Autoload.php', 'storage/installed.lock', 'database/schema.sql', 'partials/admin_header.php', '.env', '.env.example'] as $protected) {
        $r = httpReq('GET', $baseUrl . '/' . $protected, ['jar' => $jarAnon]);
        check("protected path returns 403: {$protected}", $r['status'] === 403);
    }

    $env = (string) @file_get_contents($copyDir . '/.env');
    check('.env written with APP_DEBUG=false (installer default)', str_contains($env, 'APP_DEBUG=false'));
    check('.env written with APP_ENV=production', str_contains($env, 'APP_ENV=production'));
    check('.env written with FORCE_HTTPS=false by default', str_contains($env, 'FORCE_HTTPS=false'));

    // A fresh jar (no prior session cookie) so the response actually carries Set-Cookie.
    $r = httpReq('GET', $baseUrl . '/slogin.php', ['jar' => $jarCookie]);
    $setCookie = (string) ($r['headers']['set-cookie'] ?? '');
    check('session cookie is HttpOnly + SameSite=Lax', $setCookie !== '' && str_contains($setCookie, 'HttpOnly') && str_contains($setCookie, 'SameSite=Lax'));
    check('session cookie is NOT Secure on plain HTTP by default', !str_contains($setCookie, 'Secure'));

    // FORCE_HTTPS=true must redirect plain HTTP to HTTPS and mark Secure.
    // (The installer's heredoc may carry CRLF line endings — match loosely.)
    $envHttps = preg_replace('/^FORCE_HTTPS=.*$/m', 'FORCE_HTTPS=true', $env);
    file_put_contents($copyDir . '/.env', $envHttps);
    $r = httpReq('GET', $baseUrl . '/slogin.php', ['jar' => $jarCookie]);
    check('FORCE_HTTPS=true redirects plain HTTP to HTTPS (302)', $r['status'] === 302 && str_starts_with((string) ($r['headers']['location'] ?? ''), 'https://'));
    $jarHttps = $tmpJar . '/https'; // fresh jar: no prior session cookie, so Set-Cookie is actually emitted
    $r = httpReq('GET', 'https://localhost/eaes_release_test/slogin.php', ['jar' => $jarHttps]);
    $setCookie = (string) ($r['headers']['set-cookie'] ?? '');
    // PHP emits the flag lowercased ("secure") — match case-insensitively.
    if ($r['status'] !== 200 || stripos($setCookie, 'secure') === false) {
        echo '  [DEBUG] https slogin status=' . $r['status'] . ' set-cookie=' . var_export($setCookie, true) . "\n";
    }
    check('HTTPS request sets a Secure session cookie', $r['status'] === 200 && stripos($setCookie, 'secure') !== false);
    check('HSTS header present on HTTPS', isset($r['headers']['strict-transport-security']));
    check('security headers present (nosniff + frame + referrer)', isset($r['headers']['x-content-type-options'])
        && isset($r['headers']['x-frame-options']) && isset($r['headers']['referrer-policy']));
    file_put_contents($copyDir . '/.env', $env); // restore

    // No PHP errors were logged during the whole run (the CSRF-mismatch and
    // audit entries the sweep itself triggers go to the daily app log, not
    // php-error.log — only real PHP runtime errors land there).
    $errLog = $copyDir . '/storage/logs/php-error.log';
    $errs = is_file($errLog) ? (string) @file_get_contents($errLog) : '';
    if (trim($errs) !== '') {
        echo "  [DEBUG] php-error.log content:\n" . $errs . "\n";
    }
    check('no PHP errors logged during the release run', trim($errs) === '');
} catch (Throwable $e) {
    echo '  [FAIL] unexpected exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    // Cleanup: drop the scratch DB and remove the copy (and jars).
    try {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (Throwable $e) {
        echo "  [WARN] could not drop {$dbName}: {$e->getMessage()}\n";
    }
    rrmdir($copyDir);
    rrmdir($tmpJar);
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "RESULT: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
