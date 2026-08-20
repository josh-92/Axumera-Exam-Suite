<?php

/**
 * tests/converter_test.php
 * ------------------------
 * End-to-end tests for the standalone word-to-json converter:
 *   - parse a .txt upload through the converter's own entry point
 *   - build both export formats (Question Bank JSON + Exam JSON)
 *   - strictness: missing answers are rejected
 *   - the real "english exam.docx" (when present) → 80 questions + 1 passage
 *
 * Run: php tests/converter_test.php
 */

require __DIR__ . '/../word-to-json/index.php'; // defines cv_* functions (CLI-safe)

$pass = 0;
$fail = 0;

function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
}

/* ------------------------------------------------------------------ */
/* 1. Parse a .txt upload through the converter entry point            */
/* ------------------------------------------------------------------ */

$txt = "1. What is the capital of France?\n"
    . "A. Berlin\nB. Paris\nC. Madrid\nD. Rome\n"
    . "Answer: B\n\n"
    . "2. The earth is flat.\n"
    . "True\nFalse\nAnswer: False\n";

$tmp = tempnam(sys_get_temp_dir(), 'cv') . '.txt';
file_put_contents($tmp, $txt);
$parsed = cv_parse_upload([
    'name' => 'quiz.txt',
    'tmp_name' => $tmp,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($txt),
]);
@unlink($tmp);

check($parsed['ok'] === true, 'txt upload parses OK');
check(count($parsed['questions']) === 2, 'parses 2 questions from txt');
check(($parsed['questions'][0]['correct_answer'] ?? null) === 'b', 'Q1 answer detected (b)');
check(($parsed['questions'][1]['type'] ?? '') === 'True/False', 'Q2 detected as True/False');
check(($parsed['questions'][1]['correct_answer'] ?? null) === 'b', 'Q2 answer detected (b = False)');

// rejection paths
check(cv_parse_upload(['name' => 'x.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 4])['ok'] === false, 'rejects non-docx/txt extension');
check(cv_parse_upload(['name' => 'x.docx', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0])['ok'] === false, 'rejects failed upload');

/* ------------------------------------------------------------------ */
/* 2. Question Bank JSON builder                                       */
/* ------------------------------------------------------------------ */

$bankRows = [
    ['question' => 'Which gas do plants absorb?', 'type' => 'MCQ', 'option_a' => 'O2', 'option_b' => 'CO2', 'correct_answer' => 'b', 'isPassage' => false],
    ['question' => 'The water cycle recycles a fixed amount of water.\nParagraph 1 text.', 'type' => 'Essay', 'isPassage' => true, 'correct_answer' => ''],
];

[$errs, $json] = cv_bank_json($bankRows, ['subject' => 'Biology', 'grade' => '12', 'difficulty' => 'medium']);
check($errs === [], 'bank build: no errors');
$dec = json_decode($json, true);
check(isset($dec['questions']) && count($dec['questions']) === 2, 'bank build: {"questions":[2]}');
check($dec['questions'][0]['subject'] === 'Biology' && $dec['questions'][0]['grade'] === '12', 'bank build: defaults applied');
check($dec['questions'][0]['difficulty'] === 'medium', 'bank build: difficulty applied');
check($dec['questions'][0]['correct_answer'] === 'b', 'bank build: answer kept');
check($dec['questions'][1]['type'] === 'Essay' && $dec['questions'][1]['correct_answer'] === '', 'bank build: passage → Essay, no answer required');
check(json_last_error() === JSON_ERROR_NONE, 'bank build: valid JSON (UTF-8 safe)');

// missing answer must be rejected for gradable types
[$errs2, $json2] = cv_bank_json(
    [['question' => 'No answer here?', 'type' => 'MCQ', 'option_a' => 'a', 'option_b' => 'b', 'correct_answer' => '']],
    []
);
check($errs2 !== [] && $json2 === '', 'bank build: rejects unanswered MCQ');

/* ------------------------------------------------------------------ */
/* 3. Exam JSON builder                                                */
/* ------------------------------------------------------------------ */

[$errs3, $json3] = cv_exam_json($bankRows);
check($errs3 === [], 'exam build: no errors');
$dec3 = json_decode($json3, true);
check(is_array($dec3) && count($dec3) === 2, 'exam build: bare array of 2 items');
check(($dec3[0]['type'] ?? '') === 'question' && $dec3[0]['question_text'] === 'Which gas do plants absorb?', 'exam build: question row shape');
check(($dec3[0]['option_b'] ?? '') === 'CO2' && ($dec3[0]['correct_answer'] ?? '') === 'b', 'exam build: options + answer');
check(($dec3[1]['type'] ?? '') === 'passage' && $dec3[1]['content'] !== '', 'exam build: passage block');

// strict: unanswered question → error, nothing exported
[$errs4, $json4] = cv_exam_json(
    [['question' => 'No answer here?', 'type' => 'MCQ', 'option_a' => 'a', 'option_b' => 'b', 'correct_answer' => '']]
);
check($errs4 !== [] && $json4 === '', 'exam build: rejects unanswered question');

// passage-only file is fine for the exam format
[$errs5, $json5] = cv_exam_json([['question' => 'Long passage text', 'type' => 'Essay', 'isPassage' => true, 'correct_answer' => '']]);
check($errs5 === [] && (json_decode($json5, true)[0]['type'] ?? '') === 'passage', 'exam build: passage-only export OK');

/* ------------------------------------------------------------------ */
/* 4. Standalone deployment: bundled parser parity                     */
/* ------------------------------------------------------------------ */

$appParser = __DIR__ . '/../app/Services/DocxQuestionParser.php';
$libParser = __DIR__ . '/../word-to-json/lib/DocxQuestionParser.php';
check(
    is_file($libParser) && is_file($appParser)
    && sha1_file($appParser) === sha1_file($libParser),
    'bundled lib/ parser stays byte-identical to the app parser'
);

// The bundled copy must be usable standalone: run it in a fresh PHP process
// (it declares the same class, so it cannot be loaded twice in one process).
$out = null;
$code = 1;
@exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "require " . var_export($libParser, true) . "; "
        . "\$r = \\App\\Services\\DocxQuestionParser::parseText('1. Q?\\nA. x\\nB. y\\nAnswer: B'); "
        . "echo count(\$r['questions']);"
    ),
    $out,
    $code
);
check($code === 0 && trim(implode('', $out)) === '1', 'bundled lib/ parser parses standalone (fresh process)');

/* ------------------------------------------------------------------ */
/* 5. Misc helpers                                                     */
/* ------------------------------------------------------------------ */

check(cv_safe_name('english exam.docx') === 'english_exam.json', 'filename sanitized (original ext stripped)');
check(cv_safe_name('english exam.bank.json') === 'english_exam.bank.json', 'existing .json kept as a dot-name');
check(cv_safe_name('..//..') === 'questions.json', 'empty name falls back');
check(cv_bounded('héllo', 3) === 'hél', 'bounded() is multibyte-safe');

/* ------------------------------------------------------------------ */
/* 6. Real Word file (end-to-end through the converter)                */
/* ------------------------------------------------------------------ */

$real = 'C:/Users/hp/Music/english exam.docx';
if (is_file($real)) {
    $r = cv_parse_upload([
        'name' => 'english exam.docx',
        'tmp_name' => $real,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($real),
    ]);
    check($r['ok'] === true, 'real docx parses OK');
    if ($r['ok']) {
        $nq = count(array_filter($r['questions'], fn ($q) => ($q['type'] ?? '') !== 'Passage'));
        $np = count(array_filter($r['questions'], fn ($q) => ($q['type'] ?? '') === 'Passage'));
        $na = count(array_filter($r['questions'], fn ($q) => ($q['type'] ?? '') !== 'Passage' && ($q['correct_answer'] ?? null) !== null));
        echo "  info real file → {$nq} questions, {$np} passage(s), {$na} with answers\n";
        check($nq === 80 && $np === 1, 'real docx → 80 questions + 1 passage');
        check($na === 80, 'real docx → every question has a detected answer');
    }
} else {
    echo "  skip real-file check (not found at {$real})\n";
}

/* ------------------------------------------------------------------ */

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
