<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * One row per (student, exam). `started_at` is set once, server-side, the
 * first time the student opens the exam — this makes the countdown timer
 * authoritative and immune to page refreshes or client clock tampering.
 * Answers/flags are periodically autosaved here as JSON so progress
 * survives a crashed browser or lost connection.
 */
class AttemptRepository
{
    public static function find(int $studentId, int $examId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM exam_attempts WHERE student_id = :s AND exam_id = :e LIMIT 1'
        );
        $stmt->execute(['s' => $studentId, 'e' => $examId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Get the attempt, creating it (and starting the timer) if this is the first visit. */
    public static function findOrStart(int $studentId, int $examId): array
    {
        $existing = self::find($studentId, $examId);
        if ($existing) {
            return $existing;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at, ip_address, user_agent)
             VALUES (:s, :e, '{}', '{}', 'in_progress', NOW(), NOW(), :ip, :ua)"
        );
        $stmt->execute([
            's'  => $studentId,
            'e'  => $examId,
            'ip' => \App\Core\Logger::clientIp(),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        return self::find($studentId, $examId);
    }

    public static function autosave(int $studentId, int $examId, array $answers, array $flags): bool
    {
        $attempt = self::find($studentId, $examId);
        if (!$attempt || $attempt['status'] !== 'in_progress') {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE exam_attempts SET answers = :a, flags = :f, last_saved_at = NOW() WHERE id = :id'
        );
        return $stmt->execute([
            'a'  => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'f'  => json_encode($flags, JSON_UNESCAPED_UNICODE),
            'id' => $attempt['id'],
        ]);
    }

    /**
     * Increment the integrity-violation counter for an in-progress attempt
     * and flag it once it crosses the configured review threshold.
     *
     * @return array{violation_count:int, flagged:bool} the attempt's new state
     */
    public static function recordViolation(int $studentId, int $examId): array
    {
        $attempt = self::find($studentId, $examId);
        if (!$attempt || $attempt['status'] !== 'in_progress') {
            return ['violation_count' => (int) ($attempt['violation_count'] ?? 0), 'flagged' => false];
        }

        $flagThreshold = (int) config('integrity.flag_threshold', 3);
        $newCount = (int) $attempt['violation_count'] + 1;
        $flagged = $newCount >= $flagThreshold;

        $stmt = Database::connection()->prepare(
            'UPDATE exam_attempts
             SET violation_count = :count,
                 integrity_status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'count'  => $newCount,
            'status' => $flagged ? 'flagged' : 'clean',
            'id'     => $attempt['id'],
        ]);

        return ['violation_count' => $newCount, 'flagged' => $flagged];
    }

    /** Seconds remaining given the exam duration, computed server-side. */
    public static function secondsRemaining(array $attempt, int $durationSeconds): int
    {
        $elapsed = time() - strtotime($attempt['started_at']);
        return max(0, $durationSeconds - $elapsed);
    }

    public static function markSubmitted(int $attemptId, int $score, int $totalQuestions, string $status = 'submitted'): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE exam_attempts SET score = :score, total_questions = :total, status = :status, submitted_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'score'  => $score,
            'total'  => $totalQuestions,
            'status' => $status,
            'id'     => $attemptId,
        ]);
    }

    /** Count of attempts flagged by the integrity monitor for this exam (for an admin-dashboard badge). */
    public static function flaggedCountForExam(int $examId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM exam_attempts WHERE exam_id = :id AND integrity_status = 'flagged'"
        );
        $stmt->execute(['id' => $examId]);
        return (int) $stmt->fetchColumn();
    }

    public static function forExam(int $examId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ea.*, s.full_name, s.roll_number, s.stream, s.section
             FROM exam_attempts ea
             JOIN students s ON s.id = ea.student_id
             WHERE ea.exam_id = :id
             ORDER BY s.roll_number ASC, s.full_name ASC'
        );
        $stmt->execute(['id' => $examId]);
        return $stmt->fetchAll();
    }
}
