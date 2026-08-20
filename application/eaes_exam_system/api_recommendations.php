<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\ExamRecommendationEngine;
use App\Repositories\RecommendationRepository;
use App\Core\Session;

Session::start();
if (!Session::get('admin_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$engine = new ExamRecommendationEngine();
$repo = new RecommendationRepository();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'analyze') {
        $examId = (int)($_GET['exam_id'] ?? 0);
        $result = $engine->generateRecommendations($examId);
        echo json_encode(['success' => true, 'data' => $result]);
    } elseif ($method === 'POST' && $action === 'apply_swap') {
        $input = json_decode(file_get_contents('php://input'), true);
        $examId = (int)($input['exam_id'] ?? 0);
        $oldQId = (int)($input['old_question_id'] ?? 0);
        $newQId = (int)($input['new_question_id'] ?? 0);
        $reason = $input['reason'] ?? 'AI Recommendation Applied';
        
        $repo->swapQuestion($examId, $oldQId, $newQId, $reason, Session::get('admin_id'));
        echo json_encode(['success' => true, 'message' => 'Question successfully swapped.']);
    } else {
        throw new Exception("Invalid endpoint.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}