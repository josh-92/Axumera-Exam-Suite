<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\BlueprintService;
use App\Core\Session;

Session::start();
if (!Session::get('admin_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$service = new BlueprintService();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'POST' && $action === 'save') {
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $service->saveBlueprint($input, Session::get('admin_id'));
        echo json_encode($result);
        
    } elseif ($method === 'GET' && $action === 'generate') {
        $blueprintId = (int)($_GET['id'] ?? 0);
        $examData = $service->generateExamInstance($blueprintId, Session::get('admin_id'));
        echo json_encode(['success' => true, 'exam' => $examData]);
        
    } else {
        throw new Exception("Invalid endpoint or method.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}