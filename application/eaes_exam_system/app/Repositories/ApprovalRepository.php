<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class ApprovalRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getQuestionState(int $questionId): ?array {
        $stmt = $this->db->getConnection()->prepare("SELECT id, approval_status, created_by FROM questions WHERE id = :id");
        $stmt->execute(['id' => $questionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateQuestionStatus(int $questionId, string $status): void {
        $stmt = $this->db->getConnection()->prepare("UPDATE questions SET approval_status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $questionId]);
    }

    public function logAction(string $entityType, int $entityId, string $action, string $old, string $new, int $userId, ?string $comments): void {
        $sql = "INSERT INTO approval_logs (entity_type, entity_id, action, old_status, new_status, acted_by, comments) 
                VALUES (:type, :id, :action, :old, :new, :user, :comments)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'type' => $entityType,
            'id' => $entityId,
            'action' => $action,
            'old' => $old,
            'new' => $new,
            'user' => $userId,
            'comments' => $comments
        ]);
    }

    public function createNotification(int $userId, string $title, string $message): void {
        $sql = "INSERT INTO user_notifications (user_id, title, message) VALUES (:user_id, :title, :message)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message
        ]);
    }

    public function getPendingEntities(string $entityType = 'question'): array {
        // Fetches items waiting for review
        $sql = "SELECT q.id, q.question, q.approval_status, u.name as submitter_name 
                FROM questions q 
                LEFT JOIN users u ON q.created_by = u.id 
                WHERE q.approval_status = 'Pending Review'";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}