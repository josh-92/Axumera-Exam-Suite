<?php
/**
 * EAES Word → JSON Converter
 * ==========================
 * A standalone tool for subject teachers: upload a Word (.docx) or plain
 * text (.txt) file, review the automatically-parsed questions, and download
 * a JSON file that the EAES Question Bank — or the Exam creation module —
 * imports directly. No template required, no login, no license, no database.
 *
 * Deploy: copy this folder to any PHP 8 host (XAMPP, a shared server…).
 * When it sits inside the EAES tree it uses the project's parser; when
 * deployed standalone it falls back to the bundled copy in lib/.
 *
 * Privacy: the uploaded document is parsed in memory and never stored.
 */

declare(strict_types=1);

use App\Services\DocxQuestionParser;

/* ------------------------------------------------------------------ */
/* Parser autoload: prefer the in-project copy (single source of truth) */
/* so parser fixes propagate here too; fall back to the bundled copy.  */
/* ------------------------------------------------------------------ */
foreach ([
    __DIR__ . '/../app/Services/DocxQuestionParser.php',   // inside the EAES tree
    __DIR__ . '/lib/DocxQuestionParser.php',                // portable copy
] as $cvCandidate) {
    if (is_file($cvCandidate)) {
        require_once $cvCandidate;
        break;
    }
}

const CV_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

/* ================================================================== */
/*  Helpers                                                           */
/* ================================================================== */

/** Cap a string to $max characters (mirrors the EAES validators). */
function cv_bounded(mixed $value, int $max): string
{
    $s = (string) ($value ?? '');
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}

/** Build a safe download filename ending in exactly .json. */
function cv_safe_name(string $name): string
{
    $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim($name, '._-');
    $dot = strrpos($name, '.'); // drop any existing extension first
    if ($dot !== false && $dot > 0) {
        $name = substr($name, 0, $dot);
    }
    if ($name === '') {
        $name = 'questions';
    }
    return $name . '.json';
}

/**
 * Validate + parse an uploaded file array.
 *
 * @param array<string,mixed> $file a $_FILES-style entry
 * @return array{ok: bool, questions: array<int,mixed>, warnings: array<int,string>, error: ?string}
 */
function cv_parse_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'questions' => [], 'warnings' => [], 'error' => 'The upload failed — please choose a file and try again.'];
    }
    if ((int) ($file['size'] ?? 0) > CV_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'questions' => [], 'warnings' => [], 'error' => 'The file is too large (max 5 MB).'];
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['docx', 'txt'], true)) {
        return ['ok' => false, 'questions' => [], 'warnings' => [], 'error' => 'Only .docx (Word) and .txt files can be converted here.'];
    }
    if (!class_exists(DocxQuestionParser::class)) {
        return ['ok' => false, 'questions' => [], 'warnings' => [], 'error' => 'The parser could not be loaded on this server (missing PHP zlib or SimpleXML?).'];
    }
    try {
        $result = DocxQuestionParser::parseFile((string) $file['tmp_name'], $ext);
        return ['ok' => true, 'questions' => $result['questions'], 'warnings' => $result['warnings'], 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'questions' => [], 'warnings' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Build the Question Bank JSON payload ({"questions": […]}) exactly as
 * the EAES Question Bank import accepts it. Subject/grade/difficulty are
 * defaults written into every row; detected passages become Essay items
 * (the bank has no passage type) — matching the in-app review flow.
 *
 * @return array{0: array<int,string>, 1: string} [errors, json]
 */
function cv_bank_json(array $rows, array $defaults): array
{
    $errors = [];
    $out = [];
    $defaults += ['subject' => '', 'grade' => '', 'difficulty' => ''];

    foreach ($rows as $i => $r) {
        if (!is_array($r)) {
            continue;
        }
        $num = $i + 1;
        $question = trim((string) ($r['question'] ?? ''));
        if ($question === '') {
            $errors[] = "Question {$num} is empty.";
            continue;
        }
        if (mb_strlen($question) > 20000) {
            $errors[] = "Question {$num} is too long (max 20,000 characters).";
            continue;
        }
        $type = (string) ($r['type'] ?? 'MCQ');
        if ($type === 'Passage') {
            $type = 'Essay'; // the bank stores reading passages as reference items
        }
        if (!in_array($type, ['MCQ', 'True/False', 'Essay'], true)) {
            $errors[] = "Question {$num} has an invalid type.";
            continue;
        }
        $correct = strtolower(trim((string) ($r['correct_answer'] ?? '')));
        if ($type !== 'Essay' && !in_array($correct, ['a', 'b', 'c', 'd'], true)) {
            $errors[] = "Question {$num} needs a correct answer (A–D) before it can be exported.";
            continue;
        }

        $out[] = [
            'question' => $question,
            'type' => $type,
            'difficulty' => in_array($defaults['difficulty'], ['easy', 'medium', 'hard'], true) ? $defaults['difficulty'] : '',
            'subject' => cv_bounded($defaults['subject'], 100),
            'grade' => cv_bounded($defaults['grade'], 50),
            'topic' => '',
            'tags' => '',
            'option_a' => cv_bounded($r['option_a'] ?? '', 5000),
            'option_b' => cv_bounded($r['option_b'] ?? '', 5000),
            'option_c' => cv_bounded($r['option_c'] ?? '', 5000),
            'option_d' => cv_bounded($r['option_d'] ?? '', 5000),
            'correct_answer' => $correct,
        ];
    }

    if ($errors !== []) {
        return [$errors, ''];
    }
    if ($out === []) {
        return [['No questions were selected for export.'], ''];
    }
    return [[], json_encode(['questions' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
}

/**
 * Build the Exam JSON payload (a bare array) exactly as the EAES exam
 * module (Validator::examJson + ExamImportService) accepts it. The exam
 * engine grades from the answer key, so every non-passage item must carry
 * a detected answer — anything else is rejected up front.
 *
 * @return array{0: array<int,string>, 1: string} [errors, json]
 */
function cv_exam_json(array $rows): array
{
    $errors = [];
    $out = [];
    $count = 0;

    foreach ($rows as $i => $r) {
        if (!is_array($r)) {
            continue;
        }
        $num = $i + 1;
        $isPassage = !empty($r['isPassage']) || ($r['type'] ?? '') === 'Passage';
        if ($isPassage) {
            $out[] = [
                'type' => 'passage',
                'id' => 'P' . $num,
                'content' => cv_bounded($r['question'] ?? '', 20000),
            ];
            continue;
        }
        $text = trim((string) ($r['question'] ?? ''));
        if ($text === '') {
            $errors[] = "Item #{$num} is missing question text.";
            continue;
        }
        $correct = strtolower(trim((string) ($r['correct_answer'] ?? '')));
        if (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
            $errors[] = "Item #{$num} has no correct answer (A/B/C/D) — the exam engine cannot grade it.";
            continue;
        }
        $count++;
        $out[] = [
            'type' => 'question',
            'question_number' => $count,
            'paragraph_text' => '',
            'question_text' => cv_bounded($text, 20000),
            'option_a' => cv_bounded($r['option_a'] ?? '', 5000),
            'option_b' => cv_bounded($r['option_b'] ?? '', 5000),
            'option_c' => cv_bounded($r['option_c'] ?? '', 5000),
            'option_d' => cv_bounded($r['option_d'] ?? '', 5000),
            'correct_answer' => $correct,
        ];
    }

    if ($errors !== []) {
        return [$errors, ''];
    }
    if ($out === []) {
        return [['No valid question items were found.'], ''];
    }
    return [[], json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
}

/* ================================================================== */
/*  HTTP handlers                                                     */
/* ================================================================== */

function cv_respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/** POST ?action=parse — multipart upload → parsed drafts (JSON). */
function cv_handle_parse(): void
{
    $result = cv_parse_upload($_FILES['file'] ?? []);
    if (!$result['ok']) {
        cv_respond_json(['success' => false, 'error' => $result['error']], 400);
        return;
    }
    cv_respond_json(['success' => true, 'questions' => $result['questions'], 'warnings' => $result['warnings']]);
}

/** POST ?action=build — reviewed rows → JSON download (bank or exam). */
function cv_handle_build(): void
{
    $format = ($_POST['format'] ?? 'bank') === 'exam' ? 'exam' : 'bank';
    $raw = (string) ($_POST['rows'] ?? '');
    if (strlen($raw) > 12 * 1024 * 1024) { // pre-decode guard (post_max_size permitting)
        cv_respond_json(['success' => false, 'error' => 'The submission is too large.'], 413);
        return;
    }
    $rows = json_decode($raw, true);
    $rows = is_array($rows) ? $rows : [];
    $defaults = [
        'subject' => trim((string) ($_POST['subject'] ?? '')),
        'grade' => trim((string) ($_POST['grade'] ?? '')),
        'difficulty' => trim((string) ($_POST['difficulty'] ?? '')),
    ];
    $filename = cv_safe_name((string) ($_POST['filename'] ?? 'questions.json'));

    if ($rows === []) {
        cv_respond_json(['success' => false, 'error' => 'No reviewed questions were submitted.'], 400);
        return;
    }
    if (count($rows) > 1000) {
        cv_respond_json(['success' => false, 'error' => 'Too many questions (max 1000 per file).'], 400);
        return;
    }

    [$errors, $json] = $format === 'exam' ? cv_exam_json($rows) : cv_bank_json($rows, $defaults);
    if ($errors !== []) {
        cv_respond_json(['success' => false, 'errors' => $errors], 422);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
}

/* ================================================================== */
/*  Request handling (skipped when included from the CLI test suite)   */
/* ================================================================== */

if (PHP_SAPI !== 'cli') {
    session_start();
    $_SESSION['cv_csrf'] = $_SESSION['cv_csrf'] ?? bin2hex(random_bytes(16));
    $cvToken = $_SESSION['cv_csrf'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($cvToken, (string) ($_POST['csrf'] ?? ''))) {
            cv_respond_json(['success' => false, 'error' => 'Session expired — refresh the page and try again.'], 419);
        }
        $cvAction = (string) ($_GET['action'] ?? '');
        if ($cvAction === 'parse') {
            cv_handle_parse();
        } elseif ($cvAction === 'build') {
            cv_handle_build();
        } else {
            cv_respond_json(['success' => false, 'error' => 'Unknown action.'], 400);
        }
        exit;
    }

    /* ?demo=1 — render a pre-parsed sample so the review flow is visible
       without uploading a file (also handy for first-time users). */
    $cvInitial = null;
    if (isset($_GET['demo']) && class_exists(DocxQuestionParser::class)) {
        $cvSample = "The Importance of Reading\n"
            . "Paragraph 1\n"
            . "Regular reading strengthens vocabulary, improves concentration, and builds empathy. "
            . "Studies show that students who read for twenty minutes a day score higher on comprehension "
            . "tests across every subject.\n\n"
            . "1. What is one benefit of regular reading mentioned in the passage?\n"
            . "A. Faster typing\nB. Stronger vocabulary\nC. Better eyesight\nD. Longer memory\n"
            . "Answer: B\n\n"
            . "2. Students who read daily score higher on comprehension tests.\n"
            . "True\nFalse\nAnswer: True\n\n"
            . "3. The passage states that reading builds:\n"
            . "A. empathy\nB. wealth\nC. speed\nD. height\nAnswer: A\n";
        $cvInitial = json_encode(
            DocxQuestionParser::parseText($cvSample),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EAES Question Converter</title>
<style>
    :root {
        --bg: #f3f2ee;
        --bg-alt: #e6e2d6;
        --card: #ffffff;
        --ink: #1f2937;
        --ink-soft: #6b7280;
        --border: #d8d5ca;
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --accent: #f59e0b;
        --danger: #dc2626;
        --ok: #16a34a;
        --radius: 16px;
        --shadow: 0 1px 2px rgba(0, 0, 0, .05), 0 8px 24px rgba(31, 41, 55, .07);
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        background: linear-gradient(180deg, var(--bg) 0%, var(--bg-alt) 100%);
        color: var(--ink);
        min-height: 100vh;
    }
    .wrap { max-width: 980px; margin: 0 auto; padding: 28px 20px 80px; }

    /* header */
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
    .brand-badge {
        width: 40px; height: 40px; border-radius: 12px; flex: none;
        background: linear-gradient(135deg, var(--primary), #7c3aed);
        color: #fff; display: grid; place-items: center; font-weight: 800; font-size: 18px;
        box-shadow: 0 4px 12px rgba(37, 99, 235, .35);
    }
    .brand h1 { margin: 0; font-size: 24px; letter-spacing: -.02em; }
    .subtitle { color: var(--ink-soft); margin: 0 0 20px 52px; font-size: 14px; }

    /* steps */
    .steps { display: flex; gap: 8px; margin: 18px 0 22px; flex-wrap: wrap; }
    .step {
        display: flex; align-items: center; gap: 8px;
        background: var(--card); border: 1px solid var(--border); border-radius: 999px;
        padding: 7px 14px; font-size: 13px; font-weight: 600; color: var(--ink-soft);
    }
    .step .n {
        width: 20px; height: 20px; border-radius: 50%; background: var(--primary); color: #fff;
        display: grid; place-items: center; font-size: 12px; font-weight: 700;
    }
    .step.is-on { color: var(--ink); border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .step.is-on .n { background: var(--primary); }

    /* cards */
    .card {
        background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
        box-shadow: var(--shadow); padding: 22px; margin-bottom: 18px;
    }
    .card h2 { margin: 0 0 4px; font-size: 17px; }
    .card .hint { margin: 0 0 14px; color: var(--ink-soft); font-size: 13px; line-height: 1.5; }

    /* dropzone */
    .dropzone {
        border: 2px dashed #b9b4a4; border-radius: var(--radius);
        background: #fbfaf6; padding: 34px 20px; text-align: center; cursor: pointer;
        transition: border-color .15s, background .15s, transform .1s;
    }
    .dropzone:hover, .dropzone.is-drag { border-color: var(--primary); background: #eff4ff; }
    .dropzone.is-drag { transform: scale(1.005); }
    .dz-icon { font-size: 34px; margin-bottom: 8px; }
    .dz-title { font-weight: 700; font-size: 15px; }
    .dz-sub { color: var(--ink-soft); font-size: 13px; margin-top: 4px; }
    .dz-file { display: none; }

    /* defaults row */
    .defaults { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-top: 16px; }
    .field label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .03em; }
    .field input, .field select {
        width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
        font-size: 14px; background: #fff; color: var(--ink);
    }
    .field input:focus, .field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.15); }

    /* summary + warnings */
    .summary {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        background: #eef2ff; border: 1px solid #c7d2fe; color: #4338ca;
        border-radius: 12px; padding: 10px 14px; font-size: 14px; font-weight: 600; margin-bottom: 14px;
    }
    .summary .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--primary); }
    .warnings { margin: 0 0 14px; padding: 0; list-style: none; }
    .warnings li {
        background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
        border-radius: 10px; padding: 9px 12px; font-size: 13px; margin-bottom: 8px; line-height: 1.5;
    }

    /* review list */
    .cv-list { display: grid; gap: 12px; }
    .cv-item {
        display: flex; gap: 12px; background: #fbfaf6; border: 1px solid var(--border);
        border-left: 4px solid var(--primary); border-radius: 12px; padding: 14px;
        transition: opacity .15s;
    }
    .cv-item.is-passage { border-left-color: #6366f1; background: #f8fafc; }
    .cv-item.is-skipped { opacity: .45; }
    .cv-include { margin-top: 4px; width: 17px; height: 17px; flex: none; accent-color: var(--primary); }
    .cv-main { flex: 1; min-width: 0; }
    .cv-q {
        width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
        font-size: 14px; font-family: inherit; resize: vertical; background: #fff; color: var(--ink); line-height: 1.5;
    }
    .cv-q:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
    .cv-opts { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 0; }
    .cv-opt {
        background: #fff; border: 1px solid var(--border); border-radius: 999px;
        padding: 5px 12px; font-size: 13px; color: var(--ink-soft);
    }
    .cv-opt b { color: var(--ink); }
    .cv-controls { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-top: 10px; }
    .cv-controls label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-soft); font-weight: 600; }
    .cv-controls select {
        padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; background: #fff; color: var(--ink);
    }
    .cv-note { font-size: 12px; color: #b45309; }
    .cv-note-passage { color: #4338ca; background: #eef2ff; border-radius: 999px; padding: 4px 10px; font-weight: 600; }

    /* actions */
    .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
    .btn {
        display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer;
        border-radius: 12px; padding: 12px 18px; font-size: 14px; font-weight: 700;
        transition: transform .08s, box-shadow .15s, background .15s;
    }
    .btn:active { transform: translateY(1px); }
    .btn:disabled { opacity: .5; cursor: not-allowed; }
    .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.3); }
    .btn-primary:hover:not(:disabled) { background: var(--primary-dark); }
    .btn-secondary { background: #eef0f4; color: var(--ink); border: 1px solid var(--border); }
    .btn-secondary:hover:not(:disabled) { background: #e2e5ec; }
    .btn-ghost { background: transparent; color: var(--ink-soft); border: 1px solid transparent; }
    .btn-ghost:hover { color: var(--danger); }

    .foot-note { color: var(--ink-soft); font-size: 12.5px; margin-top: 18px; line-height: 1.6; text-align: center; }

    /* toasts */
    .toasts { position: fixed; top: 18px; right: 18px; z-index: 60; display: grid; gap: 10px; max-width: 340px; }
    .toast {
        background: var(--ink); color: #fff; border-radius: 12px; padding: 12px 16px; font-size: 13.5px;
        box-shadow: var(--shadow); animation: toast-in .2s ease;
    }
    .toast.warning { background: #92400e; }
    .toast.error { background: var(--danger); }
    .toast.success { background: var(--ok); }
    @keyframes toast-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }

    .spinner {
        display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4);
        border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: -2px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 640px) {
        .subtitle { margin-left: 0; }
        .actions { flex-direction: column; }
        .btn { justify-content: center; }
    }
</style>
</head>
<body>
<div class="wrap">

    <header class="brand">
        <div class="brand-badge">EA</div>
        <h1>EAES Question Converter</h1>
    </header>
    <p class="subtitle">Turn a Word document into exam-ready JSON — no template needed.</p>

    <div class="steps">
        <div class="step is-on" id="step-1"><span class="n">1</span> Upload a Word file</div>
        <div class="step" id="step-2"><span class="n">2</span> Review each question</div>
        <div class="step" id="step-3"><span class="n">3</span> Download JSON &amp; import</div>
    </div>

    <!-- Step 1: upload + defaults -->
    <section class="card" id="upload-card">
        <h2>1 · Choose your document</h2>
        <p class="hint">
            Accepts <b>.docx</b> (Microsoft Word) and plain-text <b>.txt</b> files.
            Questions should be numbered (1., 2. …), options lettered (A. B. C. D.),
            and the answer marked with an “Answer: C” line — or simply <b>bold</b> the correct option.
            Everything is best-guess, so you always get a review step before anything is exported.
        </p>
        <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Upload a document">
            <div class="dz-icon">📄</div>
            <div class="dz-title">Drop your Word document here</div>
            <div class="dz-sub">or click to browse — .docx / .txt, up to 5 MB</div>
            <input type="file" class="dz-file" id="file" accept=".docx,.txt">
        </div>

        <div class="defaults">
            <div class="field">
                <label for="subject">Subject</label>
                <input id="subject" list="subject-list" placeholder="e.g. Biology" autocomplete="off">
                <datalist id="subject-list">
                    <option value="Biology"></option><option value="Chemistry"></option>
                    <option value="Physics"></option><option value="Mathematics"></option>
                    <option value="English"></option><option value="Agriculture"></option>
                    <option value="IT"></option><option value="Geography"></option>
                    <option value="History"></option><option value="Economics"></option>
                    <option value="SAT Practice"></option>
                </datalist>
            </div>
            <div class="field">
                <label for="grade">Grade</label>
                <input id="grade" list="grade-list" placeholder="e.g. 12" autocomplete="off">
                <datalist id="grade-list">
                    <option value="9"></option><option value="10"></option>
                    <option value="11"></option><option value="12"></option>
                </datalist>
            </div>
            <div class="field">
                <label for="difficulty">Difficulty</label>
                <select id="difficulty">
                    <option value="">— not set —</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
        </div>
        <p class="hint" style="margin:12px 0 0;">
            Subject, grade and difficulty are written into <b>every</b> question of the downloaded file —
            they can still be changed individually later inside the Question Bank.
        </p>
    </section>

    <!-- Step 2 + 3: review + download -->
    <section class="card" id="review-card" hidden>
        <h2 id="review-title">2 · Review the parsed questions</h2>
        <p class="hint">Check each question, set any missing answers, and untick anything you don’t want. Nothing is uploaded to the server — the file is processed in memory on your computer’s own PHP server.</p>

        <div class="summary" id="summary" hidden>
            <span class="dot"></span><span id="summary-text"></span>
        </div>
        <ul class="warnings" id="warnings"></ul>
        <div class="cv-list" id="cv-list"></div>

        <div class="actions">
            <button class="btn btn-primary" id="btn-bank" type="button">⬇ Download · Question Bank JSON</button>
            <button class="btn btn-secondary" id="btn-exam" type="button">⬇ Download · Exam JSON</button>
            <button class="btn btn-ghost" id="btn-reset" type="button">↺ Start over</button>
        </div>
        <p class="foot-note">
            <b>Question Bank JSON</b> → upload inside EAES under <i>Question Bank → Import</i>.
            &nbsp;·&nbsp;
            <b>Exam JSON</b> → attach when creating/editing an exam (every question needs a marked answer).
        </p>
    </section>

    <p class="foot-note">EAES Word → JSON Converter · no database · no login · nothing is stored</p>
</div>

<div class="toasts" id="toasts"></div>

<!-- hidden export form → streams the download through a hidden iframe -->
<form id="export-form" method="POST" action="index.php?action=build" target="export-frame">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($cvToken ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="format" id="export-format" value="bank">
    <input type="hidden" name="filename" id="export-filename" value="questions.json">
    <input type="hidden" name="subject" id="export-subject" value="">
    <input type="hidden" name="grade" id="export-grade" value="">
    <input type="hidden" name="difficulty" id="export-difficulty" value="">
    <textarea name="rows" id="export-rows" style="display:none"></textarea>
</form>
<iframe name="export-frame" style="display:none"></iframe>

<script>
window.CV_CSRF = <?= json_encode($cvToken ?? '') ?>;
window.CV_INITIAL = <?= $cvInitial ?? 'null' ?>;
</script>

<script>
(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);

    const state = {
        questions: [],   // parsed drafts from the server
        warnings: [],
        fileName: '',
        parsed: false,
    };

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(message, type = 'info', ms = 5000) {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = message;
        $('toasts').appendChild(el);
        setTimeout(() => el.remove(), ms);
    }

    function short(text, max = 50) {
        const s = String(text).replace(/\s+/g, ' ').trim();
        return s.length > max ? s.slice(0, max) + '…' : s;
    }

    // ------------------------------------------------------------ upload

    const dropzone = $('dropzone');
    const fileInput = $('file');

    function handleFile(file) {
        if (!file) return;
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (ext !== 'docx' && ext !== 'txt') {
            toast('Please choose a .docx (Word) or .txt file.', 'warning');
            return;
        }
        parseFile(file);
    }

    ['dragenter', 'dragover'].forEach((ev) =>
        dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
    ['dragleave', 'drop'].forEach((ev) =>
        dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
    dropzone.addEventListener('drop', (e) => handleFile(e.dataTransfer?.files?.[0]));
    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { handleFile(fileInput.files[0]); fileInput.value = ''; });
    dropzone.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });

    async function parseFile(file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('csrf', window.CV_CSRF);

        dropzone.innerHTML = '<div class="dz-icon">⏳</div><div class="dz-title">Reading your document…</div><div class="dz-sub"><span class="spinner"></span> parsing questions, options and answers</div>';

        try {
            const res = await fetch('index.php?action=parse', { method: 'POST', body: fd });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.success !== true) {
                throw new Error((data && data.error) || `The server returned HTTP ${res.status}.`);
            }
            state.questions = data.questions;
            state.warnings = data.warnings || [];
            state.fileName = file.name;
            state.parsed = true;
            resetDropzone();
            renderReview();
        } catch (e) {
            resetDropzone();
            toast(e.message, 'error', 7000);
        }
    }

    function resetDropzone() {
        dropzone.innerHTML =
            '<div class="dz-icon">📄</div>' +
            '<div class="dz-title">Drop your Word document here</div>' +
            '<div class="dz-sub">or click to browse — .docx / .txt, up to 5 MB</div>';
        dropzone.appendChild(fileInput);
    }

    // ------------------------------------------------------------ review

    function renderReview() {
        const qs = state.questions;
        const list = $('cv-list');

        if (qs.length === 0) {
            list.innerHTML = '<p class="hint">No questions were recognized in this file. Number each question (1. …), ' +
                'list options as A. B. C. D., and mark answers with an “Answer: X” line — then upload again.</p>';
            $('review-card').hidden = false;
            updateSummary();
            return;
        }

        list.innerHTML = qs.map((q, i) => {
            const isPassage = q.type === 'Passage';
            const opts = ['a', 'b', 'c', 'd']
                .filter((l) => (q.options?.[l] || '').trim())
                .map((l) => `<span class="cv-opt"><b>${l.toUpperCase()}.</b> ${esc(q.options[l])}</span>`)
                .join('');
            const answers = ['a', 'b', 'c', 'd'].map((l) =>
                `<option value="${l}" ${q.correct_answer === l ? 'selected' : ''}>${l.toUpperCase()}</option>`).join('');
            const note = isPassage
                ? '<span class="cv-note-passage">📄 Reading passage — kept as a reference item</span>'
                : (q.note ? `<span class="cv-note">⚠ ${esc(q.note)}</span>` : '');
            return `<div class="cv-item ${isPassage ? 'is-passage' : ''}" data-idx="${i}">
                <input type="checkbox" class="cv-include" checked title="Include this item">
                <div class="cv-main">
                    <textarea class="cv-q" rows="${isPassage ? 5 : 2}" maxlength="20000" title="You can edit the wording here">${esc(q.question)}</textarea>
                    <div class="cv-opts">${opts || (isPassage ? '' : '<span class="cv-opt">No options detected — set the type to Essay or add them in the Question Bank after importing.</span>')}</div>
                    <div class="cv-controls">
                        ${isPassage ? '' : `<label>Answer
                            <select class="cv-answer">
                                <option value="">— set —</option>
                                ${answers}
                            </select>
                        </label>`}
                        <label>Type
                            <select class="cv-type">
                                <option value="MCQ" ${q.type === 'MCQ' ? 'selected' : ''}>MCQ</option>
                                <option value="True/False" ${q.type === 'True/False' ? 'selected' : ''}>True/False</option>
                                <option value="Essay" ${q.type === 'Essay' || isPassage ? 'selected' : ''}>${isPassage ? 'Passage (saved as reference)' : 'Essay (no auto-grade)'}</option>
                            </select>
                        </label>
                        ${note}
                    </div>
                </div>
            </div>`;
        }).join('');

        $('warnings').innerHTML = state.warnings.map((w) => `<li>${esc(w)}</li>`).join('') || '';
        $('review-card').hidden = false;
        $('step-2').classList.add('is-on');
        updateSummary();
    }

    function updateSummary() {
        const qs = state.questions;
        const rows = collectRows();
        const summary = $('summary');
        if (qs.length === 0) { summary.hidden = true; updateStep3(); return; }
        const passages = qs.filter((q) => q.type === 'Passage').length;
        const needs = rows.filter((r) => !r.isPassage && r.type !== 'Essay' && !r.correct_answer).length;
        $('summary-text').textContent =
            `${rows.length} question${rows.length === 1 ? '' : 's'} selected` +
            (passages ? ` · ${passages} passage${passages === 1 ? '' : 's'}` : '') +
            (needs ? ` · ${needs} need${needs === 1 ? 's' : ''} an answer` : ' · all answers set');
        summary.hidden = false;
        updateStep3();
    }

    function updateStep3() {
        const rows = collectRows();
        const missing = rows.filter((r) => !r.isPassage && r.type !== 'Essay' && !r.correct_answer).length;
        $('btn-bank').disabled = rows.length === 0 || missing > 0;
        $('btn-exam').disabled = rows.length === 0;
        $('step-3').classList.add('is-on');
    }

    // ------------------------------------------------------------ collect

    function collectRows() {
        const out = [];
        document.querySelectorAll('#cv-list .cv-item').forEach((item) => {
            if (!item.querySelector('.cv-include').checked) return;
            const q = state.questions[Number(item.dataset.idx)];
            if (!q) return;
            const question = item.querySelector('.cv-q').value.trim();
            if (!question) return;
            const type = item.querySelector('.cv-type').value;
            const row = {
                question,
                type,
                isPassage: q.type === 'Passage',
                option_a: q.options?.a || '',
                option_b: q.options?.b || '',
                option_c: q.options?.c || '',
                option_d: q.options?.d || '',
            };
            const answer = item.querySelector('.cv-answer');
            if (answer && type !== 'Essay') row.correct_answer = answer.value;
            out.push(row);
        });
        return out;
    }

    // ------------------------------------------------------------ export

    function download(format) {
        const rows = collectRows();
        if (rows.length === 0) { toast('Select at least one question to export.', 'warning'); return; }

        let payload = rows;
        if (format === 'exam') {
            const skipped = rows.filter((r) => !r.isPassage && (r.type === 'Essay' || !r.correct_answer));
            if (skipped.length > 0) {
                const names = skipped.slice(0, 3).map((r) => '“' + short(r.question) + '”').join(', ');
                toast(`Exam export skipped ${skipped.length} item(s) that can't be auto-graded: ${names}${skipped.length > 3 ? '…' : ''}. Use the Question Bank JSON if you want to keep them.`, 'warning', 8000);
            }
            payload = rows.filter((r) => r.isPassage || (r.type !== 'Essay' && r.correct_answer));
            if (payload.length === 0) { toast('Nothing left to export after filtering — add answers or use the Question Bank format.', 'warning'); return; }
        } else {
            const missing = rows.filter((r) => !r.isPassage && r.type !== 'Essay' && !r.correct_answer);
            if (missing.length > 0) {
                toast(`Set the correct answer for ${missing.length} question(s) before exporting: ${missing.slice(0, 3).map((r) => '“' + short(r.question) + '”').join(', ')}${missing.length > 3 ? '…' : ''}`, 'warning', 8000);
                return;
            }
        }

        const base = state.fileName ? state.fileName.replace(/\.[^.]+$/, '') : 'questions';
        $('export-rows').value = JSON.stringify(payload);
        $('export-format').value = format;
        $('export-filename').value = `${base}.${format === 'exam' ? 'exam' : 'bank'}.json`;
        $('export-subject').value = $('subject').value.trim();
        $('export-grade').value = $('grade').value.trim();
        $('export-difficulty').value = $('difficulty').value;

        $('export-form').submit();
        toast(`Downloading ${format === 'exam' ? 'exam' : 'question bank'} JSON…`, 'success', 2500);
    }

    // ------------------------------------------------------------ wiring

    function reset() {
        state.questions = [];
        state.warnings = [];
        state.fileName = '';
        state.parsed = false;
        $('cv-list').innerHTML = '';
        $('warnings').innerHTML = '';
        $('review-card').hidden = true;
        $('summary').hidden = true;
        $('step-2').classList.remove('is-on');
        $('step-3').classList.remove('is-on');
        resetDropzone();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    $('btn-bank').addEventListener('click', () => download('bank'));
    $('btn-exam').addEventListener('click', () => download('exam'));
    $('btn-reset').addEventListener('click', reset);

    // live re-validation on any edit/checkbox/select change
    $('cv-list').addEventListener('change', updateSummary);
    $('cv-list').addEventListener('input', updateSummary);

    // demo mode: pre-render the sample review
    if (window.CV_INITIAL) {
        state.questions = window.CV_INITIAL.questions;
        state.warnings = window.CV_INITIAL.warnings;
        state.fileName = 'sample.docx';
        state.parsed = true;
        renderReview();
        toast('Demo mode — this is a sample parse. Drop your own Word file to convert it.', 'info', 6000);
    }
})();
</script>
</body>
</html>
<?php } // end non-CLI request handling ?>
