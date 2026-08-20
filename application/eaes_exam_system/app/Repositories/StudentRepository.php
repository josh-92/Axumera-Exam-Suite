<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * `students` now holds only identity/registration data (one row per
 * roll_number + stream). Exam-specific state (answers, score, status)
 * lives in `exam_attempts` so a student's history across multiple exams
 * is preserved instead of being overwritten each time (a bug in the
 * original schema).
 */
class StudentRepository
{
    public static function findByRollAndStream(string $rollNumber, string $stream): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM students WHERE roll_number = :r AND stream = :s LIMIT 1'
        );
        $stmt->execute(['r' => $rollNumber, 's' => $stream]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Create the student identity if new, or refresh their name/section, then return the id. */
    public static function upsert(string $fullName, string $rollNumber, string $stream, string $section): int
    {
        $existing = self::findByRollAndStream($rollNumber, $stream);
        $db = Database::connection();

        if ($existing) {
            $stmt = $db->prepare('UPDATE students SET full_name = :n, section = :sec WHERE id = :id');
            $stmt->execute(['n' => $fullName, 'sec' => $section, 'id' => $existing['id']]);
            return (int) $existing['id'];
        }

        $stmt = $db->prepare(
            'INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES (:n, :r, :s, :sec, NOW())'
        );
        $stmt->execute(['n' => $fullName, 'r' => $rollNumber, 's' => $stream, 'sec' => $section]);
        return (int) $db->lastInsertId();
    }
}
