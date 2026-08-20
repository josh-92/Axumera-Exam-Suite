<?php
namespace App\Services;

use App\Repositories\BlueprintRepository;
use App\Repositories\ExamGeneratorRepository;
use App\Core\Database;
use Exception;
use PDO;

class IntelligentGeneratorService {
    private BlueprintRepository $blueprintRepo;
    private ExamGeneratorRepository $generatorRepo;
    private Database $db;

    public function __construct() {
        $this->blueprintRepo = new BlueprintRepository();
        $this->generatorRepo = new ExamGeneratorRepository();
        $this->db = new Database();
    }

    public function generate(int $blueprintId, int $userId, array $excludedTeacherIds = [], int $coolDownDays = 30): array {
        $blueprint = $this->blueprintRepo->getBlueprint($blueprintId);
        if (!$blueprint) throw new Exception("Blueprint not found.");

        $this->db->getConnection()->beginTransaction();
        try {
            $examName = $blueprint['name'] . ' - Instance ' . date('YmdHis');
            $examId = $this->generatorRepo->createExam($blueprintId, $userId, $examName, $blueprint['total_questions'], $blueprint['total_marks']);
            
            $this->generatorRepo->logEvent($blueprintId, $examId, 'START', 'info', "Initiated generation for Blueprint: {$blueprint['name']}");

            $selectedQuestions = [];
            $usedQuestionIds = []; // Prevent duplicates within the same exam

            foreach ($blueprint['rules'] as $rule) {
                $questions = $this->fetchIntelligentQuestions($blueprint, $rule, $excludedTeacherIds, $coolDownDays, $usedQuestionIds);
                
                if (count($questions) < $rule['question_count']) {
                    $msg = "Constraint Failure: Requires {$rule['question_count']} questions for Topic '{$rule['topic']}', Difficulty '{$rule['difficulty']}'. Found only " . count($questions) . ". Adjust blueprint or add more approved questions to the bank.";
                    $this->generatorRepo->logEvent($blueprintId, $examId, 'CONSTRAINT_FAILURE', 'error', $msg);
                    throw new Exception($msg);
                }

                foreach ($questions as $q) {
                    $reason = sprintf(
                        "Matched Rule #%d: Topic='%s', Type='%s', Difficulty='%s'. Status: Approved. Last Used: %s. Selected randomly from valid pool.",
                        $rule['id'], 
                        $rule['topic'] ?? 'Any', 
                        $rule['question_type'] ?? 'Any', 
                        $rule['difficulty'] ?? 'Any',
                        $q['last_used_at'] ?? 'Never'
                    );

                    $selectedQuestions[] = [
                        'id' => $q['id'],
                        'rule_id' => $rule['id'],
                        'allocated_marks' => $rule['marks_per_question'],
                        'selection_reason' => $reason
                    ];
                    $usedQuestionIds[] = $q['id'];
                }
            }

            if ($blueprint['shuffle_questions']) {
                shuffle($selectedQuestions);
            }

            $this->generatorRepo->attachQuestions($examId, $selectedQuestions);
            $this->generatorRepo->updateQuestionLastUsed($usedQuestionIds);
            
            $this->generatorRepo->logEvent($blueprintId, $examId, 'SUCCESS', 'success', "Successfully generated exam with " . count($selectedQuestions) . " questions.");
            $this->db->getConnection()->commit();

            return ['success' => true, 'exam_id' => $examId, 'message' => 'Exam successfully generated.'];

        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            // Log failure outside the aborted transaction
            $this->generatorRepo->logEvent($blueprintId, null, 'ROLLBACK', 'error', $e->getMessage());
            throw $e;
        }
    }

    public function regenerate(int $examId, int $userId): array {
        $exam = $this->generatorRepo->getExam($examId);
        if (!$exam) throw new Exception("Exam not found.");
        if ($exam['is_locked']) throw new Exception("Cannot regenerate a locked exam.");

        // Clear old questions, keep the exam ID shell, run generation logic again.
        $this->db->getConnection()->beginTransaction();
        try {
            $this->generatorRepo->clearExamQuestions($examId);
            $this->db->getConnection()->commit();
            
            // Re-run standard generation on the same blueprint
            return $this->generate($exam['blueprint_id'], $userId);
        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            throw $e;
        }
    }

    private function fetchIntelligentQuestions(array $blueprint, array $rule, array $excludedTeachers, int $coolDown, array $usedIds): array {
        $params = [
            'subject' => $blueprint['subject'],
            'grade' => $blueprint['grade']
        ];
        
        $conditions = [
            "status = 'approved'", // Strict approval workflow
            "subject = :subject",
            "grade = :grade",
            "(last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL $coolDown DAY))" // Cooldown
        ];

        if (!empty($usedIds)) {
            $placeholders = implode(',', array_fill(0, count($usedIds), '?'));
            $conditions[] = "id NOT IN ($placeholders)";
            // To handle mixed named and positional params cleanly in PDO, we'll append to an array of pure values.
            // But PDO prefers one or the other. We will rewrite all to positional or all to named.
            // Let's use named parameters for everything for safety.
        }

        // Rebuilding dynamic named params securely
        $sqlParams = ['subject' => $blueprint['subject'], 'grade' => $blueprint['grade']];
        
        $sql = "SELECT id, last_used_at FROM questions WHERE status = 'approved' AND subject = :subject AND grade = :grade AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL $coolDown DAY))";

        if (!empty($usedIds)) {
            $in = [];
            foreach ($usedIds as $i => $uid) {
                $key = 'used_' . $i;
                $in[] = ':' . $key;
                $sqlParams[$key] = $uid;
            }
            $sql .= " AND id NOT IN (" . implode(',', $in) . ")";
        }

        if (!empty($excludedTeachers)) {
            $in = [];
            foreach ($excludedTeachers as $i => $tid) {
                $key = 'excl_' . $i;
                $in[] = ':' . $key;
                $sqlParams[$key] = $tid;
            }
            $sql .= " AND created_by NOT IN (" . implode(',', $in) . ")";
        }

        if (!empty($rule['topic'])) {
            $sql .= " AND topic = :topic";
            $sqlParams['topic'] = $rule['topic'];
        }
        if (!empty($rule['difficulty'])) {
            $sql .= " AND difficulty = :diff";
            $sqlParams['diff'] = $rule['difficulty'];
        }
        if (!empty($rule['question_type'])) {
            $sql .= " AND type = :type";
            $sqlParams['type'] = $rule['question_type'];
        }
        if (!empty($rule['source_teacher_id'])) {
            $sql .= " AND created_by = :source";
            $sqlParams['source'] = $rule['source_teacher_id'];
        }

        // Prioritize least recently used, then randomize completely to ensure variability among equal candidates
        $sql .= " ORDER BY last_used_at ASC, RAND() LIMIT " . (int)$rule['question_count'];

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($sqlParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}