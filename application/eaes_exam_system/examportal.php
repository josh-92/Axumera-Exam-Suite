<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionRepository;
use App\Services\QuestionShuffleService;

if (!isset($_SESSION['full_name'], $_SESSION['student_id'])) {
    header("Location: slogin.php");
    exit();
}

if (isset($_SESSION['exam_submitted']) && $_SESSION['exam_submitted'] === true) {
    session_unset();
    session_destroy();
    header("Location: slogin.php");
    exit();
}

$student_id = (int) $_SESSION['student_id'];
$student_name = $_SESSION['full_name'];
$student_roll = $_SESSION['roll_number'];
$student_stream = $_SESSION['stream'];
$student_section = $_SESSION['section'];

$exam = ExamRepository::liveExam();
if (!$exam) {
    header("Location: waite.php");
    exit();
}

$active_exam_id = (int) $exam['id'];
$exam_title = $exam['exam_name'];
$exam_duration_seconds = (int) $exam['duration'] * 60;

// Server-authoritative attempt: starts the clock the first time the student
// opens the portal, and survives refreshes/crashes from then on.
$attempt = AttemptRepository::findOrStart($student_id, $active_exam_id);

if ($attempt['status'] !== 'in_progress') {
    // Already submitted (e.g. duplicate tab) — do not allow a second run.
    $_SESSION['exam_submitted'] = true;
    header("Location: slogin.php");
    exit();
}

$seconds_left = AttemptRepository::secondsRemaining($attempt, $exam_duration_seconds);
if ($seconds_left <= 0) {
    // Time already elapsed (e.g. they closed the tab past the deadline) — send to review for auto-submit.
    header("Location: review.php?autosubmit=true");
    exit();
}

$saved_answers = json_decode((string) $attempt['answers'], true) ?: [];
$saved_flags   = json_decode((string) $attempt['flags'], true) ?: [];

$questions_list = [];
$rows = QuestionRepository::forExam($active_exam_id);
if ($rows) {
    // This student's permanent display order (and, if enabled, choice order),
    // generated once and replayed identically on every subsequent visit —
    // refresh, resume, reconnect, whatever. See QuestionShuffleService.
    $rowsByQuestionNumber = [];
    foreach ($rows as $row) {
        $rowsByQuestionNumber[(int) $row['question_number']] = $row;
    }

    $shuffle = QuestionShuffleService::getOrCreateForStudent(
        $student_id,
        $active_exam_id,
        (int) $attempt['id'],
        $exam,
        $rows
    );
    $displayOrder = $shuffle['question_order'] ?: array_keys($rowsByQuestionNumber);
    $choiceOrderByQuestion = $shuffle['choice_order'];

    foreach ($displayOrder as $questionNumber) {
        $row = $rowsByQuestionNumber[$questionNumber] ?? null;
        if ($row === null) {
            // Defensive: a stale shuffle referencing a question that no
            // longer exists (shouldn't happen — replacing an exam's
            // questions wipes old shuffles — but never let this crash the
            // student's exam over it).
            continue;
        }

        if ((int) $row['is_passage'] === 1) {
            $questions_list[] = [
                'is_passage' => true,
                'id'         => (int) $row['question_number'],
                'roman_id'   => $row['option_a'],
                'text'       => $row['question_text'],
            ];
        } else {
            // Options are always keyed by their ORIGINAL letter (a/b/c/d) —
            // shuffling only changes optionOrder, the sequence those letters
            // are displayed in. The student's submitted answer is always the
            // original letter, so GradingService/autosave/review never need
            // to know shuffling exists.
            $optionOrder = isset($choiceOrderByQuestion[$questionNumber]) && $choiceOrderByQuestion[$questionNumber] !== ''
                ? str_split($choiceOrderByQuestion[$questionNumber])
                : ['a', 'b', 'c', 'd'];

            $questions_list[] = [
                'is_passage'  => false,
                'id'          => (int) $row['question_number'],
                'paragraph'   => $row['paragraph_text'] ?? '',
                'text'        => $row['question_text'],
                'options'     => [
                    'a' => $row['option_a'],
                    'b' => $row['option_b'],
                    'c' => $row['option_c'],
                    'd' => $row['option_d'],
                ],
                'optionOrder' => $optionOrder,
                // correct_answer intentionally omitted — never sent to the client.
            ];
        }
    }
}

if (!$questions_list) {
    $questions_list = [[
        'is_passage' => false, 'id' => 1, 'paragraph' => "",
        'text' => "No questions found for this exam. Please contact your administrator.",
        'options' => ['a' => 'N/A', 'b' => 'N/A', 'c' => 'N/A', 'd' => 'N/A'],
        'optionOrder' => ['a', 'b', 'c', 'd'],
    ]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Portal — <?php echo htmlspecialchars($exam_title); ?></title>

    <script>
        window.MathJax = {
            tex: { inlineMath: [['$', '$'], ['\\(', '\\)']], displayMath: [['$$', '$$'], ['\\[', '\\]']] },
            options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre'] }
        };
    </script>
    <script id="MathJax-script" async src="assets/vendor/mathjax/tex-mml-chtml.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/exam.css">
</head>
<body class="exam-gated">
    <script>
        history.pushState(null, null, location.href);
        window.onpopstate = function () { history.go(1); };
    </script>

    <header class="info-bar">
        <div class="info-content">
            <div class="info-item"><strong>Full Name:</strong> <span><?php echo htmlspecialchars($student_name); ?></span></div>
            <div class="info-item"><strong>Roll Number:</strong> <span><?php echo htmlspecialchars((string) $student_roll); ?></span></div>
            <div class="info-item"><strong>Stream:</strong> <span><?php echo htmlspecialchars($student_stream); ?></span></div>
            <div class="info-item"><strong>Section:</strong> <span><?php echo htmlspecialchars($student_section); ?></span></div>
            <div class="info-item" id="autosave-indicator" title="Your answers are saved to the server automatically">💾 Saved</div>
            <div class="info-item" id="integrity-indicator" title="This exam is monitored for tab switches and fullscreen exits" style="display: none;">🛡️ Monitored</div>
        </div>
    </header>

    <div id="integrity-gate-overlay" class="integrity-overlay">
        <div class="integrity-gate-card">
            <h2>🛡️ Exam Lockdown Mode</h2>
            <p>This exam is monitored. Before you begin, please note:</p>
            <ul>
                <li>The exam will run in fullscreen. Leaving fullscreen is recorded.</li>
                <li>Switching tabs or windows is recorded.</li>
                <li>Copy, paste, and right-click are disabled during the exam.</li>
            </ul>
            <p class="integrity-gate-note">Repeated violations may be reviewed by your teacher and, depending on this exam's settings, can end your attempt automatically.</p>
            <button type="button" class="btn-submit-exam" id="integrity-gate-start-btn">Enter Fullscreen &amp; Begin</button>
        </div>
    </div>

    <div id="integrity-warning-overlay" class="integrity-overlay" style="display: none;">
        <div class="integrity-gate-card integrity-warning-card">
            <h2>⚠️ Integrity Warning</h2>
            <p id="integrity-warning-text">You left the exam window. This has been recorded.</p>
            <button type="button" class="btn-submit-exam" id="integrity-warning-resume-btn">Return to Fullscreen &amp; Continue</button>
        </div>
    </div>

    <div class="main-container">
        <main class="exam-workspace" id="exam-workspace-view">
            <h2 class="subject-title"><span class="subject-icon">📝</span> <?php echo htmlspecialchars($exam_title); ?></h2>

            <div class="timer-container">
                <div class="timer-box" id="timer-box-display">Time left: <span id="timer-digits">--:--:--</span></div>
                <button type="button" class="btn-toggle-timer" id="timer-toggle-btn">Hide</button>
            </div>

            <div id="paragraph-box-container" class="paragraph-display-box" style="display: none;"></div>

            <div class="question-row">
                <div class="question-status-box">
                    <h3>Question <span id="current-q-num-label">1</span></h3>
                    <p class="status-text" id="status-text">Not yet answered</p>
                    <p class="points-text">Marked out of 1.00</p>
                    <button class="btn-action" id="flag-btn">🚩 Flag question</button>
                    <button class="btn-action" id="clear-btn" style="margin-top: 5px; color: #d9383a;">❌ Clear my choice</button>
                </div>

                <div class="question-card">
                    <p class="question-text" id="question-text">Loading...</p>
                    <div class="options-container" id="options-container"></div>
                </div>
            </div>

            <div class="nav-buttons">
                <button class="btn-nav" id="prev-btn">Previous page</button>
                <button class="btn-nav btn-next" id="next-btn">Next page</button>
            </div>
        </main>

        <aside class="sidebar" id="sidebar-panel">
            <div>
                <h3>Exam Overview</h3>
                <div class="overview-grid" id="overview-grid"></div>
            </div>
            <button class="btn-submit-exam" id="finish-attempt-sidebar-btn">Finish Attempt...</button>
        </aside>
    </div>

    <script>
        window.EAES_EXAM = {
            examData: <?php echo json_encode($questions_list, JSON_UNESCAPED_UNICODE); ?>,
            secondsLeft: <?php echo (int) $seconds_left; ?>,
            savedAnswers: <?php echo json_encode($saved_answers, JSON_UNESCAPED_UNICODE); ?>,
            savedFlags: <?php echo json_encode($saved_flags, JSON_UNESCAPED_UNICODE); ?>,
            csrfToken: <?php echo json_encode(Csrf::token()); ?>,
            autosaveIntervalMs: <?php echo (int) config('exam.autosave_interval_seconds', 15) * 1000; ?>,
            autosaveUrl: 'autosave.php',
            reviewUrl: 'review.php',
            integrity: {
                enabled: <?php echo config('integrity.enabled', true) ? 'true' : 'false'; ?>,
                warnThreshold: <?php echo (int) config('integrity.warn_threshold', 1); ?>,
                reportUrl: 'report_violation.php',
                startingViolationCount: <?php echo (int) ($attempt['violation_count'] ?? 0); ?>
            }
        };
    </script>
    <script src="assets/js/exam.js"></script>
</body>
</html>
