<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\ApprovalWorkflowService;
use App\Repositories\ApprovalRepository;
use App\Core\Session;

Session::start();
$userId = Session::get('user_id');
$userRole = Session::get('role'); // e.g., 'Department Head'

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$service = new ApprovalWorkflowService();
$repo = new ApprovalRepository();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'pending') {
        // Fetch items pending review
        $items = $repo->getPendingEntities('question');
        echo json_encode(['success' => true, 'data' => $items]);
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $entityId = (int)($input['entity_id'] ?? 0);
        $entityType = $input['entity_type'] ?? 'question';

        if ($action === 'submit') {
            $service->submitForReview($entityId, $entityType, $userId);
            echo json_encode(['success' => true, 'message' => 'Successfully submitted for review.']);
            
        } elseif ($action === 'decision') {
            $decision = $input['decision'] ?? ''; // 'approve' or 'reject'
            $comments = $input['comments'] ?? 'No comments provided.';
            
            $service->processDecision($entityId, $entityType, $decision, $userId, $userRole, $comments);
            echo json_encode(['success' => true, 'message' => "Successfully processed decision: {$decision}"]);
        } else {
            throw new Exception("Invalid action.");
        }
    } else {
        throw new Exception("Invalid HTTP method.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}