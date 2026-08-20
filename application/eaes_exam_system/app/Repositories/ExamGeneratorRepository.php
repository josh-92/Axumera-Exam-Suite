<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class ExamGeneratorRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function createExam(int $blueprintId, int $creatorId, string $examName, int $totalQs, float $totalMarks): int {
        $sql = "INSERT INTO generated_exams (blueprint_id, creator_id, exam_name, total_questions, total_marks) 
                VALUES (:bp_id, :creator, :name, :qs, :marks)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'bp_id' => $blueprintId,
            'creator' => $creatorId,
            'name' => $examName,
            'qs' => $totalQs,
            'marks' => $totalMarks
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    public function attachQuestions(int $examId, array $questions): void {
        $sql = "INSERT INTO generated_exam_questions (exam_id, question_id, rule_id, allocated_marks, selection_reason, order_index) 
                VALUES (:exam_id, :q_id, :rule_id, :marks, :reason, :order_idx)";
        $stmt = $this->db->getConnection()->prepare($sql);
        
        foreach ($questions as $index => $q) {
            $stmt->execute([
                'exam_id' => $examId,
                'q_id' => $q['id'],
                'rule_id' => $q['rule_id'],
                'marks' => $q['allocated_marks'],
                'reason' => $q['selection_reason'],
                'order_idx' => $index + 1
            ]);
        }
    }

    public function logEvent(int $blueprintId, ?int $examId, string $action, string $level, string $message): void {
        $sql = "INSERT INTO generation_logs (blueprint_id, exam_id, action_type, log_level, message) 
                VALUES (:bp, :exam, :action, :lvl, :msg)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'bp' => $blueprintId,
            'exam' => $examId,
            'action' => $action,
            'lvl' => $level,
            'msg' => $message
        ]);
    }

    public function updateQuestionLastUsed(array $questionIds): void {
        if (empty($questionIds)) return;
        $inQuery = implode(',', array_fill(0, count($questionIds), '?'));
        $sql = "UPDATE questions SET last_used_at = CURRENT_TIMESTAMP WHERE id IN ($inQuery)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($questionIds);
    }

    public function getExam(int $examId): ?array {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM generated_exams WHERE id = :id");
        $stmt->execute(['id' => $examId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exam) {
            $qStmt = $this->db->getConnection()->prepare("
                SELECT eq.*, q.question, q.type, q.difficulty, q.topic 
                FROM generated_exam_questions eq 
                JOIN questions q ON eq.question_id = q.id 
                WHERE eq.exam_id = :id 
                ORDER BY eq.order_index ASC
            ");
            $qStmt->execute(['id' => $examId]);
            $exam['questions'] = $qStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $exam ?: null;
    }

    public function lockExam(int $examId, int $userId): bool {
        $stmt = $this->db->getConnection()->prepare("UPDATE generated_exams SET is_locked = 1 WHERE id = :id AND creator_id = :uid");
        return $stmt->execute(['id' => $examId, 'uid' => $userId]);
    }

    public function clearExamQuestions(int $examId): void {
        $stmt = $this->db->getConnection()->prepare("DELETE FROM generated_exam_questions WHERE exam_id = :id");
        $stmt->execute(['id' => $examId]);
    }
}