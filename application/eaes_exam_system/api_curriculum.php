<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\CurriculumIntelligenceService;
use App\Core\Session;

Session::start();
if (!Session::get('admin_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$service = new CurriculumIntelligenceService();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'coverage') {
        $examId = (int)($_GET['exam_id'] ?? 0);
        $report = $service->analyzeCoverage($examId);
        echo json_encode(['success' => true, 'report' => $report]);
    } else {
        throw new Exception("Invalid endpoint or method.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}