<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class RecommendationRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getExamQuestionMetrics(int $examId): array {
        $stmt = $this->db->getConnection()->prepare("
            SELECT eq.question_id, q.curriculum_topic_id, q.question, qac.difficulty_index, 
                   qac.discrimination_index, qac.quality_score, qac.classification, qac.total_attempts
            FROM generated_exam_questions eq
            JOIN questions q ON eq.question_id = q.id
            LEFT JOIN question_analytics_cache qac ON q.id = qac.question_id
            WHERE eq.exam_id = :exam_id
        ");
        $stmt->execute(['exam_id' => $examId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findBetterAlternative(int $topicId, int $excludeQuestionId, string $targetDifficulty = 'balanced'): ?array {
        // Deterministic heuristics: prioritize 'Excellent' classification, then 'Good'.
        // Also prioritize questions that are underused (< 50 attempts) to ensure rotation.
        $diffClause = "AND qac.difficulty_index BETWEEN 0.3 AND 0.7"; // Default balanced
        if ($targetDifficulty === 'harder') $diffClause = "AND qac.difficulty_index < 0.35";
        if ($targetDifficulty === 'easier') $diffClause = "AND qac.difficulty_index > 0.65";

        $sql = "
            SELECT q.id as new_question_id, q.question as new_question_text, qac.classification, qac.total_attempts, qac.quality_score
            FROM questions q
            JOIN question_analytics_cache qac ON q.id = qac.question_id
            WHERE q.curriculum_topic_id = :topic_id 
              AND q.id != :exclude_id
              AND qac.classification IN ('Excellent', 'Good')
              $diffClause
            ORDER BY 
              (CASE WHEN qac.total_attempts < 50 THEN 1 ELSE 0 END) DESC, -- Prioritize underused
              qac.quality_score DESC
            LIMIT 1
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['topic_id' => $topicId, 'exclude_id' => $excludeQuestionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function swapQuestion(int $examId, int $oldQId, int $newQId, string $reason, int $adminId): void {
        $this->db->getConnection()->beginTransaction();
        try {
            // Get old question marks
            $stmt = $this->db->getConnection()->prepare("SELECT allocated_marks FROM generated_exam_questions WHERE exam_id = ? AND question_id = ?");
            $stmt->execute([$examId, $oldQId]);
            $marks = $stmt->fetchColumn() ?: 1;

            // Swap
            $del = $this->db->getConnection()->prepare("DELETE FROM generated_exam_questions WHERE exam_id = ? AND question_id = ?");
            $del->execute([$examId, $oldQId]);

            $ins = $this->db->getConnection()->prepare("INSERT INTO generated_exam_questions (exam_id, question_id, allocated_marks) VALUES (?, ?, ?)");
            $ins->execute([$examId, $newQId, $marks]);

            // Log
            $log = $this->db->getConnection()->prepare("INSERT INTO exam_recommendation_logs (exam_id, action_type, original_question_id, new_question_id, reason_applied, applied_by) VALUES (?, 'REPLACE_QUESTION', ?, ?, ?, ?)");
            $log->execute([$examId, $oldQId, $newQId, $reason, $adminId]);

            $this->db->getConnection()->commit();
        } catch (\Exception $e) {
            $this->db->getConnection()->rollBack();
            throw $e;
        }
    }
}