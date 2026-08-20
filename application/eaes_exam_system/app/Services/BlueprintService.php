<?php
namespace App\Services;

use App\Repositories\BlueprintRepository;
use App\Core\Database;
use Exception;
use PDO;

class BlueprintService {
    private BlueprintRepository $repo;
    private Database $db;

    public function __construct() {
        $this->repo = new BlueprintRepository();
        $this->db = new Database();
    }

    public function saveBlueprint(array $data, int $userId): array {
        $this->validateBlueprintData($data);
        
        $this->db->getConnection()->beginTransaction();
        try {
            // Versioning logic: If updating an existing blueprint, archive it and create a child.
            $version = 1;
            $parentId = null;
            if (!empty($data['id'])) {
                $existing = $this->repo->getBlueprint((int)$data['id']);
                if (!$existing || $existing['creator_id'] !== $userId) {
                    throw new Exception("Unauthorized or missing blueprint.");
                }
                $this->repo->archive($existing['id'], $userId);
                $version = $existing['version'] + 1;
                $parentId = $existing['id'];
            }

            $blueprintData = [
                'name' => trim($data['name']),
                'description' => trim($data['description'] ?? ''),
                'creator_id' => $userId,
                'subject' => $data['subject'],
                'grade' => $data['grade'],
                'semester' => $data['semester'],
                'total_questions' => $data['total_questions'],
                'total_marks' => $data['total_marks'],
                'time_limit_minutes' => $data['time_limit_minutes'],
                'shuffle_questions' => $data['shuffle_questions'] ?? 1,
                'shuffle_choices' => $data['shuffle_choices'] ?? 1,
                'version' => $version,
                'parent_id' => $parentId
            ];

            $newId = $this->repo->create($blueprintData);
            $this->repo->addRules($newId, $data['rules']);

            $this->db->getConnection()->commit();
            return ['success' => true, 'id' => $newId, 'version' => $version];
        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            throw $e;
        }
    }

    public function generateExamInstance(int $blueprintId, int $userId): array {
        if (!$this->repo->checkAccess($blueprintId, $userId)) {
            throw new Exception("Access denied to this blueprint.");
        }

        $blueprint = $this->repo->getBlueprint($blueprintId);
        if (!$blueprint) throw new Exception("Blueprint not found.");

        $selectedQuestions = [];
        $actualMarks = 0;

        foreach ($blueprint['rules'] as $rule) {
            $questions = $this->fetchQuestionsForRule($blueprint, $rule);
            if (count($questions) < $rule['question_count']) {
                throw new Exception("Question Bank deficit: Could not find {$rule['question_count']} questions for Topic: '{$rule['topic']}', Difficulty: '{$rule['difficulty']}'. Found only " . count($questions));
            }
            
            foreach ($questions as $q) {
                $q['allocated_marks'] = $rule['marks_per_question'];
                $selectedQuestions[] = $q;
                $actualMarks += $rule['marks_per_question'];
            }
        }

        if ($blueprint['shuffle_questions']) {
            shuffle($selectedQuestions);
        }

        return [
            'blueprint_name' => $blueprint['name'],
            'total_questions' => count($selectedQuestions),
            'total_marks' => $actualMarks,
            'time_limit' => $blueprint['time_limit_minutes'],
            'shuffle_choices' => $blueprint['shuffle_choices'],
            'questions' => $selectedQuestions
        ];
    }

    private function fetchQuestionsForRule(array $blueprint, array $rule): array {
        $conditions = ["subject = :subject", "grade = :grade"];
        $params = ['subject' => $blueprint['subject'], 'grade' => $blueprint['grade']];

        if (!empty($rule['topic'])) {
            $conditions[] = "topic = :topic";
            $params['topic'] = $rule['topic'];
        }
        if (!empty($rule['difficulty'])) {
            $conditions[] = "difficulty = :difficulty";
            $params['difficulty'] = $rule['difficulty'];
        }
        if (!empty($rule['question_type'])) {
            $conditions[] = "type = :type";
            $params['type'] = $rule['question_type'];
        }
        if (!empty($rule['source_teacher_id'])) {
            $conditions[] = "created_by = :teacher";
            $params['teacher'] = $rule['source_teacher_id'];
        }

        $sql = "SELECT id, question, option_a, option_b, option_c, option_d, correct_answer, type, difficulty 
                FROM questions 
                WHERE " . implode(" AND ", $conditions) . " 
                ORDER BY RAND() 
                LIMIT " . (int)$rule['question_count'];

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function validateBlueprintData(array $data): void {
        if (empty($data['name']) || empty($data['subject']) || empty($data['grade'])) {
            throw new Exception("Name, Subject, and Grade are required.");
        }
        if (empty($data['rules']) || !is_array($data['rules'])) {
            throw new Exception("Blueprint must contain at least one question rule.");
        }
        
        $calcQuestions = 0;
        $calcMarks = 0;
        foreach ($data['rules'] as $rule) {
            $calcQuestions += (int)$rule['question_count'];
            $calcMarks += ((int)$rule['question_count'] * (float)$rule['marks_per_question']);
        }

        if ($calcQuestions != $data['total_questions']) {
            throw new Exception("Rule question counts ({$calcQuestions}) do not match blueprint total ({$data['total_questions']}).");
        }
        if ($calcMarks != $data['total_marks']) {
            throw new Exception("Rule marks ({$calcMarks}) do not match blueprint total marks ({$data['total_marks']}).");
        }
    }
}