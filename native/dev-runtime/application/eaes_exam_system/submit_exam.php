<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Logger; use App\Repositories\AttemptRepository; use App\Repositories\ExamRepository; use App\Services\GradingService; header('Content-Type: application/json'); if (isset($_SESSION['full_name'], $_SESSION['student_id'])) { goto ehbLF; } echo json_encode(['status' => 'error', 'message' => 'No active session found']); exit; ehbLF: $sBI4y = file_get_contents('php://input'); $SUqg4 = json_decode($sBI4y, true) ?: []; $VpokN = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $SUqg4['csrf_token'] ?? null; if (Csrf::verify($VpokN)) { goto iPQQD; } http_response_code(419); echo json_encode(['status' => 'error', 'message' => 'Session expired.']); exit; iPQQD: $ii9hU = (int) $_SESSION['student_id']; $QR3LD = ExamRepository::liveExam() ?? ExamRepository::latest(); if ($QR3LD) { goto bPJPJ; } echo json_encode(['status' => 'error', 'message' => 'No exam profile found']); exit; bPJPJ: $ugLXG = (int) $QR3LD['id']; $hkJom = AttemptRepository::find($ii9hU, $ugLXG); if ($hkJom) { goto RMQ6Y; } echo json_encode(['status' => 'error', 'message' => 'No attempt found to submit']); exit; RMQ6Y: if (!($hkJom['status'] !== 'in_progress')) { goto HO8p7; } echo json_encode(['status' => 'success', 'score' => $hkJom['score'], 'already_submitted' => true]); exit; HO8p7: $vnxT_ = json_decode((string) $hkJom['answers'], true) ?: []; $wphBH = GradingService::grade($ugLXG, $vnxT_); $xpJYq = (int) $QR3LD['duration'] * 60; $It3UN = (int) b_k5T('exam.grace_period_seconds', 10);
// Classify on RAW elapsed time — secondsRemaining() clamps at 0, which used
// to make the "auto_submitted" branch below unreachable (every late submit
// looked on-time).
$BhSDG = ($xpJYq - (time() - strtotime((string) $hkJom['started_at']))) <= -$It3UN ? 'auto_submitted' : 'submitted';
$applied = AttemptRepository::markSubmitted((int) $hkJom['id'], $wphBH['score'], $wphBH['total'], $BhSDG);
if (!$applied) {
    // A racing request already finalized this attempt — never overwrite it.
    $fresh = AttemptRepository::find($ii9hU, $ugLXG);
    echo json_encode(['status' => 'success', 'score' => (int) ($fresh['score'] ?? 0), 'total' => (int) ($fresh['total_questions'] ?? 0), 'already_submitted' => true]);
    exit;
}
$_SESSION['exam_submitted'] = true; Logger::audit('student', (string) ($_SESSION['roll_number'] ?? ''), 'exam_submitted', ['exam_id' => $ugLXG, 'score' => $wphBH['score'], 'total' => $wphBH['total'], 'status' => $BhSDG]); echo json_encode(['status' => 'success', 'score' => $wphBH['score'], 'total' => $wphBH['total']]);
