<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\ExamAttemptService;
use App\Core\Session;

Session::start();
if (!Session::get('student_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Student unauthorized.']);
    exit;
}

$service = new ExamAttemptService();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'start') {
            $examId = (int)($input['exam_id'] ?? 0);
            $result = $service->startExam($examId, Session::get('student_id'));
            echo json_encode(['success' => true, 'data' => $result]);
        } elseif ($action === 'submit') {
            $sessionId = (int)($input['session_id'] ?? 0);
            $responses = $input['responses'] ?? [];
            $result = $service->submitExam($sessionId, $responses);
            echo json_encode($result);
        } else {
            throw new Exception("Invalid action.");
        }
    } else {
        throw new Exception("Invalid method.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}