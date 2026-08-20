<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamQuestionShuffleRepository;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionRepository;

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
$exam = ExamRepository::liveExam();
if (!$exam) {
    header("Location: waite.php");
    exit();
}
$examId = (int) $exam['id'];

$attempt = AttemptRepository::find($student_id, $examId);
$savedAnswers = $attempt ? (json_decode((string) $attempt['answers'], true) ?: []) : [];

// Only the *current live exam's* real questions. Order must match exactly
// what this student saw in examportal.php, or "Q3" here could refer to a
// different question than "Q3" did during the exam — so we read back this
// student's already-persisted shuffle order rather than the raw DB order.
// (Read-only here: the shuffle is only ever generated on examportal.php,
// the first time a student opens the exam.)
$rows = QuestionRepository::forExam($examId);
$rowsByQuestionNumber = [];
foreach ($rows as $row) {
    if ((int) $row['is_passage'] === 1) {
        continue;
    }
    $rowsByQuestionNumber[(int) $row['question_number']] = $row;
}

$displayOrder = array_keys($rowsByQuestionNumber); // fallback: natural order
$shuffleRow = ExamQuestionShuffleRepository::find($student_id, $examId);
if ($shuffleRow) {
    $savedOrder = json_decode((string) $shuffleRow['question_order'], true) ?: [];
    $filtered = array_values(array_filter($savedOrder, fn($qn) => isset($rowsByQuestionNumber[$qn])));
    if ($filtered) {
        $displayOrder = $filtered;
    }
}

$questions_meta = [];
$counter = 1;
foreach ($displayOrder as $questionNumber) {
    $questions_meta[] = ['display_num' => $counter, 'db_id' => $questionNumber];
    $counter++;
}
if (!$questions_meta) {
    $questions_meta[] = ['display_num' => 1, 'db_id' => 1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Summary Review</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { padding: 40px 20px; display: flex; justify-content: center; }
        .review-card { width: 100%; max-width: 800px; padding: 40px; text-align: center; position: relative; }
        h2 { font-size: 28px; color: var(--color-ink); margin-bottom: 10px; }
        .subtitle { color: var(--color-muted); font-size: 16px; margin-bottom: 35px; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 15px; margin-bottom: 40px; text-align: left; }
        .summary-box { border-radius: 8px; padding: 15px; text-align: center; font-weight: 600; border: 1px solid var(--color-border); }
        .summary-box .num { font-size: 20px; display: block; margin-bottom: 4px; }
        .summary-box .status-text { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .box-answered { background-color: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .box-unanswered { background-color: #fef2f2; border-color: #fee2e2; color: #991b1b; }
        .action-container { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--color-border); padding-top: 25px; margin-top: 20px; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, .6); display: flex; align-items: center; justify-content: center; visibility: hidden; opacity: 0; transition: all .25s ease; z-index: 100; }
        .modal-overlay.open { visibility: visible; opacity: 1; }
        .modal-box { background: white; padding: 40px; border-radius: 12px; max-width: 450px; width: 100%; text-align: center; box-shadow: var(--shadow-lg); transform: scale(.9); transition: transform .25s ease; }
        .modal-overlay.open .modal-box { transform: scale(1); }
        .modal-box h3 { font-size: 22px; margin-bottom: 12px; }
        .modal-box p { color: var(--color-muted); font-size: 15px; margin-bottom: 25px; line-height: 1.5; }
        .modal-buttons { display: flex; gap: 15px; justify-content: center; }
        .success-overlay { position: fixed; inset: 0; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; visibility: hidden; opacity: 0; z-index: 200; }
        .success-overlay.active { visibility: visible; opacity: 1; }
        .gif-holder { width: 200px; height: 200px; margin-bottom: 20px; }
        .gif-holder img { width: 100%; height: 100%; object-fit: contain; }
    </style>
</head>
<body>
    <script>
        history.pushState(null, null, location.href);
        window.onpopstate = function () { history.go(1); };
    </script>

    <div class="review-card card" id="main-review-layout">
        <h2>Review Your Responses</h2>
        <p class="subtitle">Please review your question completion status before final submission.</p>
        <div class="summary-grid" id="status-grid-view"></div>
        <div class="action-container">
            <button class="btn btn-muted" onclick="goBackToExam()">⬅ Back To Exam</button>
            <button class="btn btn-success" onclick="openConfirmationModal()">Submit Final Exam</button>
        </div>
    </div>

    <div class="modal-overlay" id="confirm-modal">
        <div class="modal-box">
            <h3>Are you absolutely sure?</h3>
            <p>Once you confirm, your responses will be locked permanently and you will not be allowed to re-enter the exam portal.</p>
            <div class="modal-buttons">
                <button class="btn btn-muted" onclick="closeConfirmationModal()">Cancel</button>
                <button class="btn btn-success" onclick="processFinalUpload()">Yes, Submit</button>
            </div>
        </div>
    </div>

    <div class="success-overlay" id="loading-animation-screen">
        <div class="gif-holder"><img src="assets/img/submitted.svg" alt="Submission Successful"></div>
        <h2 style="color: var(--color-success);">Exam Submitted Successfully!</h2>
        <p style="color: var(--color-muted); margin-top: 5px;">Your responses have been recorded.</p>
    </div>

    <script>
        const metaQuestions = <?php echo json_encode($questions_meta); ?>;
        const savedAnswers = <?php echo json_encode($savedAnswers, JSON_UNESCAPED_UNICODE); ?>;
        const csrfToken = <?php echo json_encode(Csrf::token()); ?>;

        function buildReviewGrid() {
            const grid = document.getElementById('status-grid-view');
            grid.innerHTML = "";
            metaQuestions.forEach(q => {
                const box = document.createElement('div');
                box.className = 'summary-box';
                const numSpan = document.createElement('span');
                numSpan.className = 'num';
                numSpan.textContent = "Q" + q.display_num;
                box.appendChild(numSpan);
                const statusSpan = document.createElement('span');
                statusSpan.className = 'status-text';
                if (savedAnswers[q.db_id]) {
                    box.classList.add('box-answered');
                    statusSpan.textContent = "Answered";
                } else {
                    box.classList.add('box-unanswered');
                    statusSpan.textContent = "Unanswered";
                }
                box.appendChild(statusSpan);
                grid.appendChild(box);
            });
        }

        function goBackToExam() { window.location.href = "examportal.php"; }
        function openConfirmationModal() { document.getElementById('confirm-modal').classList.add('open'); }
        function closeConfirmationModal() { document.getElementById('confirm-modal').classList.remove('open'); }

        function processFinalUpload() {
            closeConfirmationModal();
            document.getElementById('loading-animation-screen').classList.add('active');

            fetch('submit_exam.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ csrf_token: csrfToken })
            })
            .then(res => res.json())
            .finally(() => {
                setTimeout(() => { window.location.href = 'slogin.php'; }, 2500);
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autosubmit') === 'true') {
            document.getElementById('main-review-layout').style.display = 'none';
            processFinalUpload();
        } else {
            buildReviewGrid();
        }
    </script>
</body>
</html>
