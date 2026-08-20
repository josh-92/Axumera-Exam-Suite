<?php

namespace App\Repositories;

use App\Core\Database;

class ExamRepository
{
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM exams ORDER BY id DESC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function liveExam(): ?array
    {
        $row = Database::connection()
            ->query("SELECT * FROM exams WHERE is_live = 1 LIMIT 1")
            ->fetch();
        return $row ?: null;
    }

    public static function latest(): ?array
    {
        $row = Database::connection()
            ->query('SELECT * FROM exams ORDER BY id DESC LIMIT 1')
            ->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO exams (exam_name, duration, stream, is_live, color_theme, json_filename, shuffle_questions, shuffle_choices, created_at, updated_at)
             VALUES (:name, :duration, :stream, 0, :color, :file, :shuffle_q, :shuffle_c, NOW(), NOW())'
        );
        $stmt->execute([
            'name'      => $data['exam_name'],
            'duration'  => $data['duration'],
            'stream'    => $data['stream'],
            'color'     => $data['color_theme'],
            'file'      => $data['json_filename'],
            'shuffle_q' => !empty($data['shuffle_questions']) ? 1 : 0,
            'shuffle_c' => !empty($data['shuffle_choices']) ? 1 : 0,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function updateMeta(int $id, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE exams SET exam_name = :name, duration = :duration, stream = :stream, color_theme = :color,
                shuffle_questions = :shuffle_q, shuffle_choices = :shuffle_c, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'name'      => $data['exam_name'],
            'duration'  => $data['duration'],
            'stream'    => $data['stream'],
            'color'     => $data['color_theme'],
            'shuffle_q' => !empty($data['shuffle_questions']) ? 1 : 0,
            'shuffle_c' => !empty($data['shuffle_choices']) ? 1 : 0,
            'id'        => $id,
        ]);
    }

    public static function updateMetaWithFile(int $id, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE exams SET exam_name = :name, duration = :duration, stream = :stream, color_theme = :color, json_filename = :file,
                shuffle_questions = :shuffle_q, shuffle_choices = :shuffle_c, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'name'      => $data['exam_name'],
            'duration'  => $data['duration'],
            'stream'    => $data['stream'],
            'color'     => $data['color_theme'],
            'file'      => $data['json_filename'],
            'shuffle_q' => !empty($data['shuffle_questions']) ? 1 : 0,
            'shuffle_c' => !empty($data['shuffle_choices']) ? 1 : 0,
            'id'        => $id,
        ]);
    }

    /** Ensures only one exam is ever live at a time. */
    public static function setLive(int $id, bool $live): void
    {
        $db = Database::connection();
        $db->exec('UPDATE exams SET is_live = 0');
        if ($live) {
            $stmt = $db->prepare('UPDATE exams SET is_live = 1 WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
    }

    public static function delete(int $id): void
    {
        $db = Database::connection();
        // questions cascade-delete via FK, exam_attempts too (see schema).
        $stmt = $db->prepare('DELETE FROM exams WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function submissionCount(int $examId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = :id');
        $stmt->execute(['id' => $examId]);
        return (int) $stmt->fetchColumn();
    }
}
