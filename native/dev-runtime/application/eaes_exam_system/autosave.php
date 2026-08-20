<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:05              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Logger; use App\Repositories\AttemptRepository; use App\Repositories\ExamRepository; header('Content-Type: application/json'); if (isset($_SESSION['full_name'], $_SESSION['student_id'])) { goto aotkN; } http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Not logged in.']); exit; aotkN: $sBI4y = file_get_contents('php://input'); $SUqg4 = json_decode($sBI4y, true) ?: []; $VpokN = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $SUqg4['csrf_token'] ?? null; if (Csrf::verify($VpokN)) { goto b_EG3; } http_response_code(419); echo json_encode(['status' => 'error', 'message' => 'Session expired.']); exit; b_EG3: $QR3LD = ExamRepository::liveExam(); if ($QR3LD) { goto DHz4x; } echo json_encode(['status' => 'error', 'message' => 'No live exam.']); exit; DHz4x: $ugLXG = (int) $QR3LD['id']; $ii9hU = (int) $_SESSION['student_id']; $ktSoB = is_array($SUqg4['answers'] ?? null) ? $SUqg4['answers'] : []; $ccvN8 = is_array($SUqg4['flags'] ?? null) ? $SUqg4['flags'] : []; $vFqUR = []; foreach ($ktSoB as $U2wi_ => $stPki) { $U2wi_ = (int) $U2wi_; $stPki = is_string($stPki) ? strtolower(trim($stPki)) : ''; if (!($U2wi_ > 0 && in_array($stPki, ['a', 'b', 'c', 'd'], true))) { goto jzREV; } $vFqUR[$U2wi_] = $stPki; jzREV: m2bJ0: } ZfruI: $BcYzJ = []; foreach ($ccvN8 as $U2wi_ => $stPki) { $U2wi_ = (int) $U2wi_; if (!($U2wi_ > 0 && $stPki)) { goto CqADb; } $BcYzJ[$U2wi_] = true; CqADb: YrwDF: } U6m37: // Answers are only accepted while the attempt is within (duration + grace).
// Once past the deadline the attempt is finalized server-side and the
// payload is refused — a scripted client can no longer keep answering
// after time runs out.
$wphBH = AttemptRepository::autosaveIfWithinDeadline($ii9hU, $ugLXG, $vFqUR, $BcYzJ, (int) $QR3LD['duration'] * 60, (int) b_k5T('exam.grace_period_seconds', 10));
if (!empty($wphBH['expired'])) {
    Logger::audit('student', (string) ($_SESSION['roll_number'] ?? $ii9hU), 'exam_auto_submitted', ['exam_id' => $ugLXG, 'score' => $wphBH['score'], 'total' => $wphBH['total']]);
    echo json_encode(['status' => 'expired', 'seconds_remaining' => 0, 'score' => $wphBH['score'], 'total' => $wphBH['total']]);
    exit;
}
echo json_encode(['status' => $wphBH['saved'] ? 'success' : 'error', 'seconds_remaining' => $wphBH['seconds_remaining']]);
