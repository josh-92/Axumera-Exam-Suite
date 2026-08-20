<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class CurriculumRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getChaptersBySubjectAndGrade(string $subject, string $grade): array {
        $stmt = $this->db->getConnection()->prepare("
            SELECT c.*, t.id as topic_id, t.name as topic_name, t.target_weight as topic_weight 
            FROM curriculum_chapters c
            LEFT JOIN curriculum_topics t ON c.id = t.chapter_id
            WHERE c.subject = :subject AND c.grade = :grade
        ");
        $stmt->execute(['subject' => $subject, 'grade' => $grade]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExamCurriculumBreakdown(int $examId): array {
        $stmt = $this->db->getConnection()->prepare("
            SELECT eq.exam_id, q.curriculum_topic_id, ct.name as topic_name, cc.id as chapter_id, cc.name as chapter_name,
            COUNT(eq.question_id) as question_count, SUM(eq.allocated_marks) as total_marks
            FROM generated_exam_questions eq
            JOIN questions q ON eq.question_id = q.id
            LEFT JOIN curriculum_topics ct ON q.curriculum_topic_id = ct.id
            LEFT JOIN curriculum_chapters cc ON ct.chapter_id = cc.id
            WHERE eq.exam_id = :exam_id
            GROUP BY cc.id, ct.id
        ");
        $stmt->execute(['exam_id' => $examId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}