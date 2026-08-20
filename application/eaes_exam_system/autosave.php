<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
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
// CSRF token embedded in the JSON body itself.
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($decoded['csrf_token'] ?? null);
if (!Csrf::verify($token)) {
    http_response_code(419);
    echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
    exit();
}

$exam = ExamRepository::liveExam();
if (!$exam) {
    echo json_encode(['status' => 'error', 'message' => 'No live exam.']);
    exit();
}

$examId = (int) $exam['id'];
$studentId = (int) $_SESSION['student_id'];

$answersIn = is_array($decoded['answers'] ?? null) ? $decoded['answers'] : [];
$flagsIn = is_array($decoded['flags'] ?? null) ? $decoded['flags'] : [];

// Sanitize: keep only valid question ids -> a/b/c/d.
$cleanAnswers = [];
foreach ($answersIn as $qid => $val) {
    $qid = (int) $qid;
    $val = is_string($val) ? strtolower(trim($val)) : '';
    if ($qid > 0 && in_array($val, ['a', 'b', 'c', 'd'], true)) {
        $cleanAnswers[$qid] = $val;
    }
}
$cleanFlags = [];
foreach ($flagsIn as $qid => $val) {
    $qid = (int) $qid;
    if ($qid > 0 && $val) {
        $cleanFlags[$qid] = true;
    }
}

$attempt = AttemptRepository::findOrStart($studentId, $examId);
$ok = AttemptRepository::autosave($studentId, $examId, $cleanAnswers, $cleanFlags);

$secondsRemaining = AttemptRepository::secondsRemaining($attempt, (int) $exam['duration'] * 60);

echo json_encode([
    'status'            => $ok ? 'success' : 'error',
    'seconds_remaining' => $secondsRemaining,
]);
