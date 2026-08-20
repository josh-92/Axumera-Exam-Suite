<?php

namespace App\Repositories;

use App\Core\Database;
use PDOException;

/**
 * Per-student, per-exam presentation order for the question-shuffling
 * feature: one row per (student, exam), written exactly once and never
 * updated afterward.
 *
 * The UNIQUE KEY on (student_id, exam_id) — not application logic — is what
 * guarantees "shuffle only once": if two requests for the same student's
 * first visit race each other (duplicate tab, retried request after a
 * dropped connection, etc.), only one INSERT can ever succeed. The loser
 * catches the resulting duplicate-key error and simply reads back whatever
 * the winner committed, so both requests converge on the same order.
 */
class ExamQuestionShuffleRepository
{
    public static function find(int $studentId, int $examId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM exam_question_shuffles WHERE student_id = :s AND exam_id = :e LIMIT 1'
        );
        $stmt->execute(['s' => $studentId, 'e' => $examId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insert the generated order if this student/exam doesn't have one yet.
     * Race-safe: relies on the unique key rather than a prior SELECT to
     * decide whether to write, so it is correct even under concurrent calls.
     *
     * @param int[] $questionOrder original question_number values, in display order
     * @param array<int,string> $choiceOrder question_number => shuffled option-letter string (e.g. "cadb")
     */
    public static function createIfNotExists(
        int $studentId,
        int $examId,
        int $attemptId,
        array $questionOrder,
        array $choiceOrder
    ): array {
        $existing = self::find($studentId, $examId);
        if ($existing) {
            return $existing;
        }

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO exam_question_shuffles (student_id, exam_id, attempt_id, question_order, choice_order, created_at)
                 VALUES (:s, :e, :a, :qo, :co, NOW())'
            );
            $stmt->execute([
                's'  => $studentId,
                'e'  => $examId,
                'a'  => $attemptId,
                'qo' => json_encode(array_values($questionOrder), JSON_UNESCAPED_UNICODE),
                'co' => json_encode($choiceOrder, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation. A concurrent request
            // already won the insert for this (student_id, exam_id) pair a
            // moment ago — that row is authoritative, so fall through to
            // read it below instead of treating this as a real failure.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $row = self::find($studentId, $examId);
        if ($row) {
            return $row;
        }

        // Defensive fallback: should be unreachable (either our INSERT or the
        // concurrent winner's INSERT must be visible by now), but never leave
        // the caller without a usable order.
        return [
            'student_id'     => $studentId,
            'exam_id'        => $examId,
            'attempt_id'     => $attemptId,
            'question_order' => json_encode(array_values($questionOrder), JSON_UNESCAPED_UNICODE),
            'choice_order'   => json_encode($choiceOrder, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** Wipe stale shuffles when an exam's question set is replaced (see ExamImportService::replaceFromUpload). */
    public static function deleteForExam(int $examId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM exam_question_shuffles WHERE exam_id = :id');
        $stmt->execute(['id' => $examId]);
    }
}
