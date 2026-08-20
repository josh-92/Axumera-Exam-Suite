<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\IntelligentGeneratorService;
use App\Repositories\ExamGeneratorRepository;
use App\Core\Session;

Session::start();
if (!Session::get('admin_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$service = new IntelligentGeneratorService();
$repo = new ExamGeneratorRepository();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        switch ($action) {
            case 'generate':
                $bpId = (int)($input['blueprint_id'] ?? 0);
                $exclusions = $input['excluded_teachers'] ?? []; // Array of teacher IDs
                $result = $service->generate($bpId, Session::get('admin_id'), $exclusions);
                echo json_encode($result);
                break;

            case 'regenerate':
                $examId = (int)($input['exam_id'] ?? 0);
                $result = $service->regenerate($examId, Session::get('admin_id'));
                echo json_encode($result);
                break;

            case 'lock':
                $examId = (int)($input['exam_id'] ?? 0);
                $success = $repo->lockExam($examId, Session::get('admin_id'));
                echo json_encode(['success' => $success, 'message' => $success ? 'Exam locked.' : 'Failed to lock exam.']);
                break;

            default:
                throw new Exception("Invalid POST action.");
        }
    } elseif ($method === 'GET' && $action === 'view') {
        $examId = (int)($_GET['exam_id'] ?? 0);
        $exam = $repo->getExam($examId);
        if (!$exam) throw new Exception("Exam not found.");
        echo json_encode(['success' => true, 'exam' => $exam]);
    } else {
        throw new Exception("Invalid endpoint or method.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}