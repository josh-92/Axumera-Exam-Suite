<?php
namespace App\Services;

use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGeneratorRepository;
use App\Core\Database;
use Exception;
use PDO;

class ExamAttemptService {
    private ExamAttemptRepository $attemptRepo;
    private ExamGeneratorRepository $generatorRepo;
    private Database $db;

    public function __construct() {
        $this->attemptRepo = new ExamAttemptRepository();
        $this->generatorRepo = new ExamGeneratorRepository();
        $this->db = new Database();
    }

    public function startExam(int $examId, int $studentId): array {
        $exam = $this->generatorRepo->getExam($examId);
        if (!$exam) throw new Exception("Exam not found.");

        $sessionId = $this->attemptRepo->createSession($examId, $studentId, (float)$exam['total_marks']);
        
        // Strip correct answers before sending to student interface
        $sanitizedQuestions = array_map(function($q) {
            unset($q['correct_answer']);
            return $q;
        }, $exam['questions']);

        return [
            'session_id' => $sessionId,
            'exam_name' => $exam['exam_name'],
            'total_questions' => count($sanitizedQuestions),
            'questions' => $sanitizedQuestions
        ];
    }

    public function submitExam(int $sessionId, array $responses): array {
        $session = $this->attemptRepo->getSession($sessionId);
        if (!$session || $session['status'] !== 'in_progress') {
            throw new Exception("Invalid or already submitted exam session.");
        }

        $this->db->getConnection()->beginTransaction();
        try {
            $totalScore = 0.00;

            foreach ($responses as $resp) {
                $qId = (int)$resp['question_id'];
                $selectedAnswer = $resp['selected_answer'] ?? null;
                $timeSpent = (int)($resp['time_spent_seconds'] ?? 0);
                $isSkipped = empty($selectedAnswer);

                // Fetch correct answer and allocated marks
                $qStmt = $this->db->getConnection()->prepare("
                    SELECT q.correct_answer, eq.allocated_marks 
                    FROM generated_exam_questions eq 
                    JOIN questions q ON eq.question_id = q.id 
                    WHERE eq.exam_id = :exam_id AND eq.question_id = :q_id
                ");
                $qStmt->execute(['exam_id' => $session['exam_id'], 'q_id' => $qId]);
                $qData = $qStmt->fetch(PDO::FETCH_ASSOC);

                if (!$qData) continue;

                $isCorrect = (!$isSkipped && trim(strtolower($selectedAnswer)) === trim(strtolower($qData['correct_answer'])));
                $awardedMarks = $isCorrect ? (float)$qData['allocated_marks'] : 0.00;
                $totalScore += $awardedMarks;

                $this->attemptRepo->saveResponse($sessionId, $qId, $selectedAnswer, $timeSpent, $isCorrect, $isSkipped);

                // Also populate telemetry bridge for analytics engine
                $tStmt = $this->db->getConnection()->prepare("
                    INSERT INTO student_exam_answers (exam_id, student_id, question_id, is_correct, is_skipped, time_spent_seconds) 
                    VALUES (:exam_id, :student_id, :q_id, :is_corr, :is_skip, :time)
                ");
                $tStmt->execute([
                    'exam_id' => $session['exam_id'],
                    'student_id' => $session['student_id'],
                    'q_id' => $qId,
                    'is_corr' => $isCorrect ? 1 : 0,
                    'is_skip' => $isSkipped ? 1 : 0,
                    'time' => $timeSpent
                ]);
            }

            $this->attemptRepo->finalizeSession($sessionId, $totalScore);
            $this->db->getConnection()->commit();

            return ['success' => true, 'score' => $totalScore, 'total_marks' => $session['total_marks']];
        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            throw $e;
        }
    }
}