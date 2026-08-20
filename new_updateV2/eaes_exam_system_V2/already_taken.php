<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Validator;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;

if (!isset($_SESSION['full_name'], $_SESSION['student_id'])) {
    header('Location: slogin.php');
    exit;
}

// Clear the "exam submitted" marker so a student who stays logged in is
// not blocked from a LATER, different exam. Re-attempting the SAME exam
// is still impossible: examportal checks the attempt status, which stays
// 'submitted' for this exam in the database.
unset($_SESSION['exam_submitted']);

$studentId = (int) $_SESSION['student_id'];
$fullName = (string) $_SESSION['full_name'];

// The exam this redirect came from, if we know it; otherwise fall back to
// the live exam or the most recent exam profile.
$exam = null;
$requestedExam = Validator::int($_GET['exam'] ?? 0);
if ($requestedExam > 0) {
    $exam = ExamRepository::find($requestedExam);
}
if (!$exam) {
    $exam = ExamRepository::liveExam() ?? ExamRepository::latest();
}

$attempt = null;
$score = null;
$total = null;
$submittedAt = null;
if ($exam) {
    $attempt = AttemptRepository::find($studentId, (int) $exam['id']);
    if ($attempt) {
        $score = $attempt['score'] !== null ? (int) $attempt['score'] : null;
        $total = $attempt['total_questions'] !== null ? (int) $attempt['total_questions'] : null;
        $submittedAt = $attempt['submitted_at'] ?: null;
    }
}

$examName = $exam ? (string) $exam['exam_name'] : 'the exam';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attempt Already Recorded - <?php echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .notice-card { width: 100%; max-width: 520px; padding: 48px 36px; text-align: center; }
        .notice-icon { font-size: 56px; line-height: 1; margin-bottom: 14px; }
        h2 { color: var(--color-ink-soft); font-size: 24px; font-weight: 700; margin: 0 0 10px; }
        .notice-text { color: var(--color-muted); font-size: 15px; line-height: 1.6; margin: 0 0 26px; }
        .result-box { background: #f8fafc; border: 1px solid var(--color-border); border-radius: 10px; padding: 18px; margin: 0 0 26px; text-align: left; }
        .result-box .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .result-box .row .k { color: var(--color-muted); }
        .result-box .row .v { font-weight: 600; color: var(--color-ink); }
        .btn-block { width: 100%; margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; text-decoration: none; }
        .help-note { margin-top: 18px; font-size: 12.5px; color: var(--color-muted); }
    </style>
</head>
<body>
    <div class="notice-card card">
        <div class="notice-icon">🚫</div>
        <h2>You've Already Taken This Exam</h2>
        <p class="notice-text">
            Hello <strong><?php echo htmlspecialchars($fullName); ?></strong> — the system allows
            <strong>one attempt per student</strong> for <strong><?php echo htmlspecialchars($examName); ?></strong>.
            Your attempt has already been recorded, so you cannot enter the exam again.
        </p>

        <?php if ($attempt && in_array($attempt['status'], ['submitted', 'auto_submitted'], true)) { ?>
            <div class="result-box">
                <?php if ($submittedAt) { ?>
                    <div class="row"><span class="k">Submitted</span><span class="v"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($submittedAt))); ?></span></div>
                <?php } ?>
                <?php if ($score !== null && $total !== null) { ?>
                    <div class="row"><span class="k">Your recorded score</span><span class="v"><?php echo (int) $score; ?> / <?php echo (int) $total; ?></span></div>
                <?php } ?>
                <div class="row"><span class="k">Attempt status</span><span class="v"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $attempt['status'])); ?></span></div>
            </div>
        <?php } ?>

        <p class="notice-text" style="font-size:13px;">
            If you believe this is a mistake, please contact your teacher or administrator.
        </p>

        <a href="logout.php" class="btn btn-primary btn-block">🚪 Log Out</a>
        <p class="help-note">Logging out lets another student use this computer.</p>
    </div>
</body>
</html>
