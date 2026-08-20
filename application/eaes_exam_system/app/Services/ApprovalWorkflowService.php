<?php
namespace App\Services;

use App\Repositories\ApprovalRepository;
use Exception;

class ApprovalWorkflowService {
    private ApprovalRepository $repo;
    
    // Authorization Matrix for approving/rejecting
    private const APPROVAL_ROLES = ['Reviewer', 'Department Head', 'Vice Principal', 'Administrator'];

    public function __construct() {
        $this->repo = new ApprovalRepository();
    }

    public function submitForReview(int $entityId, string $entityType, int $userId): void {
        if ($entityType !== 'question') throw new Exception("Unsupported entity type.");

        $entity = $this->repo->getQuestionState($entityId);
        if (!$entity) throw new Exception("Entity not found.");
        
        if (!in_array($entity['approval_status'], ['Draft', 'Rejected'])) {
            throw new Exception("Only Draft or Rejected items can be submitted for review.");
        }

        $this->transitionState($entityId, $entityType, 'Pending Review', 'SUBMITTED', $userId, 'Submitted for formal review.');
    }

    public function processDecision(int $entityId, string $entityType, string $decision, int $userId, string $userRole, string $comments): void {
        if (!in_array($userRole, self::APPROVAL_ROLES)) {
            throw new Exception("Role '{$userRole}' is not authorized to perform approvals.");
        }

        $entity = $this->repo->getQuestionState($entityId);
        if (!$entity || $entity['approval_status'] !== 'Pending Review') {
            throw new Exception("Entity is not pending review.");
        }

        $newStatus = $decision === 'approve' ? 'Approved' : 'Rejected';
        $action = strtoupper($decision); // 'APPROVE' or 'REJECT'

        $this->transitionState($entityId, $entityType, $newStatus, $action, $userId, $comments);

        // Notify the original author
        if ($entity['created_by']) {
            $title = "Item " . ucfirst($newStatus);
            $msg = "Your {$entityType} (ID: {$entityId}) has been {$newStatus} by a {$userRole}. Comments: {$comments}";
            $this->repo->createNotification($entity['created_by'], $title, $msg);
        }
    }

    private function transitionState(int $id, string $type, string $newStatus, string $action, int $userId, string $comments): void {
        $entity = $this->repo->getQuestionState($id);
        $oldStatus = $entity['approval_status'];

        $this->repo->updateQuestionStatus($id, $newStatus);
        $this->repo->logAction($type, $id, $action, $oldStatus, $newStatus, $userId, $comments);
    }
}