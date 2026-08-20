<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Aggregate statistics for the admin analytics dashboard.
 * Kept as plain SQL aggregates (no ORM) to stay fast on shared/XAMPP hosting.
 */
class AnalyticsRepository
{
    public static function overview(): array
    {
        $db = Database::connection();

        $totalExams = (int) $db->query('SELECT COUNT(*) FROM exams')->fetchColumn();
        $totalStudents = (int) $db->query('SELECT COUNT(*) FROM students')->fetchColumn();
        $totalAttempts = (int) $db->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn();
        $totalSubmitted = (int) $db->query("SELECT COUNT(*) FROM exam_attempts WHERE status IN ('submitted','auto_submitted')")->fetchColumn();
        $avgScorePct = $db->query(
            "SELECT AVG(score / NULLIF(total_questions,0)) * 100 FROM exam_attempts WHERE status IN ('submitted','auto_submitted')"
        )->fetchColumn();

        return [
            'total_exams'     => $totalExams,
            'total_students'  => $totalStudents,
            'total_attempts'  => $totalAttempts,
            'total_submitted' => $totalSubmitted,
            'completion_rate' => $totalAttempts > 0 ? round(($totalSubmitted / $totalAttempts) * 100, 1) : 0.0,
            'avg_score_pct'   => $avgScorePct !== null ? round((float) $avgScorePct, 1) : null,
        ];
    }

    public static function perExamSummary(): array
    {
        $sql = "SELECT
                    e.id, e.exam_name, e.stream, e.is_live,
                    COUNT(ea.id) AS attempts,
                    SUM(CASE WHEN ea.status IN ('submitted','auto_submitted') THEN 1 ELSE 0 END) AS submitted,
                    AVG(CASE WHEN ea.status IN ('submitted','auto_submitted') AND ea.total_questions > 0
                             THEN ea.score / ea.total_questions * 100 END) AS avg_pct,
                    MAX(CASE WHEN ea.status IN ('submitted','auto_submitted') AND ea.total_questions > 0
                             THEN ea.score / ea.total_questions * 100 END) AS max_pct,
                    MIN(CASE WHEN ea.status IN ('submitted','auto_submitted') AND ea.total_questions > 0
                             THEN ea.score / ea.total_questions * 100 END) AS min_pct
                FROM exams e
                LEFT JOIN exam_attempts ea ON ea.exam_id = e.id
                GROUP BY e.id
                ORDER BY e.id DESC";

        return Database::connection()->query($sql)->fetchAll();
    }

    public static function scoreDistribution(int $examId, int $buckets = 10): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT score, total_questions FROM exam_attempts
             WHERE exam_id = :id AND status IN ('submitted','auto_submitted') AND total_questions > 0"
        );
        $stmt->execute(['id' => $examId]);
        $rows = $stmt->fetchAll();

        $histogram = array_fill(0, $buckets, 0);
        foreach ($rows as $row) {
            $pct = ($row['score'] / $row['total_questions']) * 100;
            $bucket = min($buckets - 1, (int) floor($pct / (100 / $buckets)));
            $histogram[$bucket]++;
        }
        return $histogram;
    }

    /** Which questions are most frequently answered incorrectly, for a given exam. */
    public static function questionDifficulty(int $examId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT question_number, correct_answer FROM questions WHERE exam_id = :id AND is_passage = 0 ORDER BY question_number"
        );
        $stmt->execute(['id' => $examId]);
        $questions = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT answers FROM exam_attempts WHERE exam_id = :id AND status IN ('submitted','auto_submitted')"
        );
        $stmt->execute(['id' => $examId]);
        $attempts = $stmt->fetchAll();

        $results = [];
        foreach ($questions as $q) {
            $qn = (int) $q['question_number'];
            $correct = strtolower(trim((string) $q['correct_answer']));
            $answeredCount = 0;
            $correctCount = 0;

            foreach ($attempts as $attempt) {
                $answers = json_decode((string) $attempt['answers'], true) ?: [];
                $chosen = $answers[$qn] ?? $answers[(string) $qn] ?? null;
                if ($chosen === null) {
                    continue;
                }
                $answeredCount++;
                if (strtolower(trim((string) $chosen)) === $correct) {
                    $correctCount++;
                }
            }

            $results[] = [
                'question_number' => $qn,
                'answered'         => $answeredCount,
                'correct'          => $correctCount,
                'accuracy_pct'     => $answeredCount > 0 ? round(($correctCount / $answeredCount) * 100, 1) : null,
            ];
        }

        return $results;
    }
}
