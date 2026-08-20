<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Repositories\AttemptRepository; use App\Repositories\ExamRepository; use App\Repositories\QuestionRepository; use App\Services\QuestionShuffleService; if (isset($_SESSION['full_name'], $_SESSION['student_id'])) { goto Mel1q; } header("Location: slogin.php"); exit; Mel1q: if (!(isset($_SESSION['exam_submitted']) && $_SESSION['exam_submitted'] === true)) { goto njkQ6; } header("Location: already_taken.php"); exit; njkQ6: $WJSjm = (int) $_SESSION['student_id']; $v5069 = $_SESSION['full_name']; $UPjj0 = $_SESSION['roll_number']; $lR8O8 = $_SESSION['stream']; $OTa6L = $_SESSION['section']; $QR3LD = ExamRepository::liveExam(); if ($QR3LD) { goto pxsEB; } header("Location: waite.php"); exit; pxsEB: $rfIcn = (int) $QR3LD['id']; $FbKgX = $QR3LD['exam_name']; $Lft51 = (int) $QR3LD['duration'] * 60; $hkJom = AttemptRepository::findOrStart($WJSjm, $rfIcn); if (!($hkJom['status'] !== 'in_progress')) { goto ZKtiB; } header("Location: already_taken.php?exam={$rfIcn}"); exit; ZKtiB: $ysTNf = AttemptRepository::secondsRemaining($hkJom, $Lft51); if (!($ysTNf <= 0)) { goto dlskF; } header("Location: review.php?autosubmit=true"); exit; dlskF: $J1aRb = json_decode((string) $hkJom['answers'], true) ?: []; $eMYdh = json_decode((string) $hkJom['flags'], true) ?: []; $bdz13 = []; $Vx_j1 = QuestionRepository::forExam($rfIcn); if (!$Vx_j1) { goto YNY0c; } $mC0eI = []; foreach ($Vx_j1 as $RmthD) { $mC0eI[(int) $RmthD['question_number']] = $RmthD; WjEeO: } knqeo: $IamBa = QuestionShuffleService::getOrCreateForStudent($WJSjm, $rfIcn, (int) $hkJom['id'], $QR3LD, $Vx_j1); $rEZEE = $IamBa['question_order'] ?: array_keys($mC0eI); $fgOje = $IamBa['choice_order']; foreach ($rEZEE as $i30SX) { $RmthD = $mC0eI[$i30SX] ?? null; if (!($RmthD === null)) { goto Oi099; } goto JNRfg; Oi099: if ((int) $RmthD['is_passage'] === 1) { goto CYeSE; } $fsMQL = isset($fgOje[$i30SX]) && $fgOje[$i30SX] !== '' ? str_split($fgOje[$i30SX]) : ['a', 'b', 'c', 'd']; $bdz13[] = ['is_passage' => false, 'id' => (int) $RmthD['question_number'], 'paragraph' => $RmthD['paragraph_text'] ?? '', 'text' => $RmthD['question_text'], 'options' => ['a' => $RmthD['option_a'], 'b' => $RmthD['option_b'], 'c' => $RmthD['option_c'], 'd' => $RmthD['option_d']], 'optionOrder' => $fsMQL]; goto Jcxe2; CYeSE: $bdz13[] = ['is_passage' => true, 'id' => (int) $RmthD['question_number'], 'roman_id' => $RmthD['option_a'], 'text' => $RmthD['question_text']]; Jcxe2: JNRfg: } VZUyM: YNY0c: if ($bdz13) { goto ViTVH; } $bdz13 = [['is_passage' => false, 'id' => 1, 'paragraph' => "", 'text' => "No questions found for this exam. Please contact your administrator.", 'options' => ['a' => 'N/A', 'b' => 'N/A', 'c' => 'N/A', 'd' => 'N/A'], 'optionOrder' => ['a', 'b', 'c', 'd']]]; ViTVH: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Portal — <?php  echo htmlspecialchars($FbKgX); ?></title>

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
            <div class="info-item"><strong>Full Name:</strong> <span><?php  echo htmlspecialchars($v5069); ?></span></div>
            <div class="info-item"><strong>Roll Number:</strong> <span><?php  echo htmlspecialchars((string) $UPjj0); ?></span></div>
            <div class="info-item"><strong>Stream:</strong> <span><?php  echo htmlspecialchars($lR8O8); ?></span></div>
            <div class="info-item"><strong>Section:</strong> <span><?php  echo htmlspecialchars($OTa6L); ?></span></div>
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
            <h2 class="subject-title"><span class="subject-icon">📝</span> <?php  echo htmlspecialchars($FbKgX); ?></h2>

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
            examData: <?php  echo json_encode($bdz13, JSON_UNESCAPED_UNICODE); ?>,
            secondsLeft: <?php  echo (int) $ysTNf; ?>,
            savedAnswers: <?php  echo json_encode($J1aRb, JSON_UNESCAPED_UNICODE); ?>,
            savedFlags: <?php  echo json_encode($eMYdh, JSON_UNESCAPED_UNICODE); ?>,
            csrfToken: <?php  echo json_encode(Csrf::token()); ?>,
            autosaveIntervalMs: <?php  echo (int) b_K5T('exam.autosave_interval_seconds', 15) * 1000; ?>,
            autosaveUrl: 'autosave.php',
            reviewUrl: 'review.php',
            integrity: {
                enabled: <?php  echo B_k5t('integrity.enabled', true) ? 'true' : 'false'; ?>,
                warnThreshold: <?php  echo (int) b_K5t('integrity.warn_threshold', 1); ?>,
                reportUrl: 'report_violation.php',
                startingViolationCount: <?php  echo (int) ($hkJom['violation_count'] ?? 0); ?>
            }
        };
    </script>
    <script src="assets/js/exam.js"></script>
</body>
</html>
