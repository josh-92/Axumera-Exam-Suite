<?php

namespace App\Repositories;

use App\Core\Database;

class QuestionRepository
{
    public static function forExam(int $examId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM questions WHERE exam_id = :id ORDER BY question_number ASC'
        );
        $stmt->execute(['id' => $examId]);
        return $stmt->fetchAll();
    }

    public static function countForExam(int $examId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = :id AND is_passage = 0');
        $stmt->execute(['id' => $examId]);
        return (int) $stmt->fetchColumn();
    }

    /** Only real questions (not passage blocks), keyed by question_number => correct_answer. */
    public static function answerKey(int $examId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT question_number, correct_answer FROM questions WHERE exam_id = :id AND is_passage = 0'
        );
        $stmt->execute(['id' => $examId]);
        $key = [];
        foreach ($stmt->fetchAll() as $row) {
            $key[(int) $row['question_number']] = strtolower(trim((string) $row['correct_answer']));
        }
        return $key;
    }

    public static function deleteForExam(int $examId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM questions WHERE exam_id = :id');
        $stmt->execute(['id' => $examId]);
    }

    /** Bulk-insert already-validated question rows (see Validator::examJson). */
    public static function bulkInsert(int $examId, array $normalizedItems): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO questions
                (exam_id, question_number, is_passage, paragraph_text, question_text, option_a, option_b, option_c, option_d, correct_answer)
             VALUES
                (:exam_id, :qnum, :is_passage, :paragraph, :text, :a, :b, :c, :d, :correct)'
        );

        $counter = 1;
        foreach ($normalizedItems as $item) {
            if ($item['type'] === 'passage') {
                $stmt->execute([
                    'exam_id'    => $examId,
                    'qnum'       => $counter,
                    'is_passage' => 1,
                    'paragraph'  => 'PASSAGE_BLOCK',
                    'text'       => $item['content'],
                    'a'          => $item['id'],
                    'b'          => '',
                    'c'          => '',
                    'd'          => '',
                    'correct'    => '',
                ]);
            } else {
                $stmt->execute([
                    'exam_id'    => $examId,
                    'qnum'       => $item['question_number'] ?: $counter,
                    'is_passage' => 0,
                    'paragraph'  => $item['paragraph_text'],
                    'text'       => $item['question_text'],
                    'a'          => $item['option_a'],
                    'b'          => $item['option_b'],
                    'c'          => $item['option_c'],
                    'd'          => $item['option_d'],
                    'correct'    => $item['correct_answer'],
                ]);
            }
            $counter++;
        }
    }
}
