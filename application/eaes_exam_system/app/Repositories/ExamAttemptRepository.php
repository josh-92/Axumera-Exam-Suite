<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class ExamAttemptRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function createSession(int $examId, int $studentId, float $totalMarks): int {
        $sql = "INSERT INTO student_exam_sessions (exam_id, student_id, total_marks) VALUES (:exam_id, :student_id, :total_marks)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'exam_id' => $examId,
            'student_id' => $studentId,
            'total_marks' => $totalMarks
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    public function getSession(int $sessionId): ?array {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM student_exam_sessions WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveResponse(int $sessionId, int $questionId, ?string $answer, int $timeSpent, bool $isCorrect, bool $isSkipped): void {
        $sql = "INSERT INTO student_exam_responses (session_id, question_id, selected_answer, time_spent_seconds, is_correct, is_skipped) 
                VALUES (:session_id, :question_id, :answer, :time_spent, :is_correct, :is_skipped)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'session_id' => $sessionId,
            'question_id' => $questionId,
            'answer' => $answer,
            'time_spent' => $timeSpent,
            'is_correct' => $isCorrect ? 1 : 0,
            'is_skipped' => $isSkipped ? 1 : 0
        ]);
    }

    public function finalizeSession(int $sessionId, float $score): void {
        $sql = "UPDATE student_exam_sessions SET status = 'submitted', score = :score, submitted_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['score' => $score, 'id' => $sessionId]);
    }
}