<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;
use App\Services\GradingService;

header('Content-Type: application/json');

if (!isset($_SESSION['full_name'], $_SESSION['student_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No active session found']);
    exit();
}

$raw = file_get_contents('php://input');
$decoded = json_decode($raw, true) ?: [];
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($decoded['csrf_token'] ?? null);
if (!Csrf::verify($token)) {
    http_response_code(419);
    echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
    exit();
}

$studentId = (int) $_SESSION['student_id'];

$exam = ExamRepository::liveExam() ?? ExamRepository::latest();
if (!$exam) {
    echo json_encode(['status' => 'error', 'message' => 'No exam profile found']);
    exit();
}
$examId = (int) $exam['id'];

$attempt = AttemptRepository::find($studentId, $examId);
if (!$attempt) {
    echo json_encode(['status' => 'error', 'message' => 'No attempt found to submit']);
    exit();
}

if ($attempt['status'] !== 'in_progress') {
    // Already graded — return the existing result idempotently instead of re-grading.
    echo json_encode(['status' => 'success', 'score' => $attempt['score'], 'already_submitted' => true]);
    exit();
}

// Grade from the server's own autosaved copy of the answers — never trust a
// score or answer set supplied directly by the client for grading.
$storedAnswers = json_decode((string) $attempt['answers'], true) ?: [];
$result = GradingService::grade($examId, $storedAnswers);

$durationSeconds = (int) $exam['duration'] * 60;
$grace = (int) config('exam.grace_period_seconds', 10);
$remaining = AttemptRepository::secondsRemaining($attempt, $durationSeconds);
$status = ($remaining <= -$grace) ? 'auto_submitted' : 'submitted';

AttemptRepository::markSubmitted((int) $attempt['id'], $result['score'], $result['total'], $status);

$_SESSION['exam_submitted'] = true;

Logger::audit('student', (string) ($_SESSION['roll_number'] ?? ''), 'exam_submitted', [
    'exam_id' => $examId,
    'score'   => $result['score'],
    'total'   => $result['total'],
    'status'  => $status,
]);

echo json_encode(['status' => 'success', 'score' => $result['score'], 'total' => $result['total']]);
