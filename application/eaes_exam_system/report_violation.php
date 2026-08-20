<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;

header('Content-Type: application/json');

if (!isset($_SESSION['full_name'], $_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in.']);
    exit();
}

$raw = file_get_contents('php://input');
$decoded = json_decode($raw, true) ?: [];

// sendBeacon posts as a Blob without our custom header, so also accept the
// CSRF token embedded in the JSON body itself (same pattern as autosave.php).
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($decoded['csrf_token'] ?? null);
if (!Csrf::verify($token)) {
    http_response_code(419);
    echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
    exit();
}

if (!(bool) config('integrity.enabled', true)) {
    echo json_encode(['status' => 'success', 'ignored' => true]);
    exit();
}

// A small fixed vocabulary — never trust/store an arbitrary client string as
// the event type, both to keep the audit log clean and to stop this endpoint
// being used as a generic free-text log injection point.
$allowedEvents = [
    'tab_hidden', 'window_blur', 'fullscreen_exit',
    'copy_attempt', 'paste_attempt', 'context_menu_attempt', 'devtools_shortcut_attempt',
];
$event = is_string($decoded['event'] ?? null) ? $decoded['event'] : '';
if (!in_array($event, $allowedEvents, true)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Unrecognized event type.']);
    exit();
}

$exam = ExamRepository::liveExam();
if (!$exam) {
    echo json_encode(['status' => 'error', 'message' => 'No live exam.']);
    exit();
}

$examId = (int) $exam['id'];
$studentId = (int) $_SESSION['student_id'];

$result = AttemptRepository::recordViolation($studentId, $examId);

Logger::audit('student', (string) ($_SESSION['roll_number'] ?? $studentId), 'integrity_violation', [
    'exam_id'         => $examId,
    'event'           => $event,
    'violation_count' => $result['violation_count'],
    'flagged'         => $result['flagged'],
]);

$autoSubmitThreshold = (int) config('integrity.auto_submit_threshold', 0);
$shouldAutoSubmit = $autoSubmitThreshold > 0 && $result['violation_count'] >= $autoSubmitThreshold;

echo json_encode([
    'status'           => 'success',
    'violation_count'  => $result['violation_count'],
    'flagged'          => $result['flagged'],
    'warn_threshold'   => (int) config('integrity.warn_threshold', 1),
    'auto_submit'      => $shouldAutoSubmit,
]);
