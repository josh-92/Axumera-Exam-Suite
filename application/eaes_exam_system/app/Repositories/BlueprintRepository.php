<?php
namespace App\Repositories;

use App\Core\Database;
use PDO;

class BlueprintRepository {
    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function create(array $data): int {
        $sql = "INSERT INTO exam_blueprints 
                (name, description, creator_id, subject, grade, semester, total_questions, total_marks, time_limit_minutes, shuffle_questions, shuffle_choices, version, parent_id) 
                VALUES (:name, :description, :creator_id, :subject, :grade, :semester, :total_questions, :total_marks, :time_limit_minutes, :shuffle_questions, :shuffle_choices, :version, :parent_id)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'creator_id' => $data['creator_id'],
            'subject' => $data['subject'],
            'grade' => $data['grade'],
            'semester' => $data['semester'],
            'total_questions' => $data['total_questions'],
            'total_marks' => $data['total_marks'],
            'time_limit_minutes' => $data['time_limit_minutes'],
            'shuffle_questions' => $data['shuffle_questions'] ?? 1,
            'shuffle_choices' => $data['shuffle_choices'] ?? 1,
            'version' => $data['version'] ?? 1,
            'parent_id' => $data['parent_id'] ?? null
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    public function addRules(int $blueprintId, array $rules): void {
        $sql = "INSERT INTO exam_blueprint_rules (blueprint_id, topic, chapter, difficulty, question_type, source_teacher_id, question_count, marks_per_question) 
                VALUES (:blueprint_id, :topic, :chapter, :difficulty, :question_type, :source_teacher_id, :question_count, :marks_per_question)";
        $stmt = $this->db->getConnection()->prepare($sql);
        
        foreach ($rules as $rule) {
            $stmt->execute([
                'blueprint_id' => $blueprintId,
                'topic' => $rule['topic'] ?? null,
                'chapter' => $rule['chapter'] ?? null,
                'difficulty' => $rule['difficulty'] ?? null,
                'question_type' => $rule['question_type'] ?? null,
                'source_teacher_id' => $rule['source_teacher_id'] ?? null,
                'question_count' => $rule['question_count'],
                'marks_per_question' => $rule['marks_per_question']
            ]);
        }
    }

    public function getBlueprint(int $id): ?array {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM exam_blueprints WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $blueprint = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($blueprint) {
            $rStmt = $this->db->getConnection()->prepare("SELECT * FROM exam_blueprint_rules WHERE blueprint_id = :id");
            $rStmt->execute(['id' => $id]);
            $blueprint['rules'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $blueprint ?: null;
    }

    public function archive(int $id, int $userId): bool {
        $stmt = $this->db->getConnection()->prepare("UPDATE exam_blueprints SET status = 'archived' WHERE id = :id AND creator_id = :user_id");
        return $stmt->execute(['id' => $id, 'user_id' => $userId]);
    }

    public function checkAccess(int $blueprintId, int $userId): bool {
        $stmt = $this->db->getConnection()->prepare("
            SELECT 1 FROM exam_blueprints WHERE id = :id AND creator_id = :user_id
            UNION
            SELECT 1 FROM exam_blueprint_shares WHERE blueprint_id = :id AND shared_with_admin_id = :user_id
        ");
        $stmt->execute(['id' => $blueprintId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    }
}