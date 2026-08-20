<?php

namespace App\Core;

class Validator
{
    public static function string(mixed $value, int $maxLength = 255): string
    {
        $value = is_string($value) ? trim($value) : '';
        return mb_substr($value, 0, $maxLength);
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function inArray(mixed $value, array $allowed, mixed $default = null): mixed
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function hexColor(mixed $value, string $default = '#0062cc'): string
    {
        $value = is_string($value) ? trim($value) : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $default;
    }

    /** Roll numbers are constrained to a sane numeric range to match the original UI. */
    public static function rollNumber(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $n = (int) $value;
        return ($n >= 1 && $n <= 999) ? $n : null;
    }

    public static function answerOption(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($value, ['a', 'b', 'c', 'd'], true) ? $value : null;
    }

    /**
     * Validate an uploaded exam JSON structure before it ever touches the database.
     * Returns [errors[], normalizedItems[]].
     */
    public static function examJson(mixed $decoded): array
    {
        $errors = [];
        if (!is_array($decoded) || $decoded === []) {
            return [['The uploaded file is not a valid, non-empty JSON array of questions.'], []];
        }

        $normalized = [];
        foreach ($decoded as $i => $item) {
            if (!is_array($item)) {
                $errors[] = "Item #$i is not an object.";
                continue;
            }
            $type = $item['type'] ?? 'question';
            if ($type === 'passage') {
                $normalized[] = [
                    'type'    => 'passage',
                    'id'      => self::string($item['id'] ?? 'I', 20),
                    'content' => self::string($item['content'] ?? '', 20000),
                ];
                continue;
            }
            if (!isset($item['question_text']) || trim((string) $item['question_text']) === '') {
                $errors[] = "Item #$i is missing question_text.";
                continue;
            }
            $correct = strtolower(trim((string) ($item['correct_answer'] ?? 'a')));
            if (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                $errors[] = "Item #$i has an invalid correct_answer (must be a/b/c/d).";
                continue;
            }
            $normalized[] = [
                'type'            => 'question',
                'question_number' => self::int($item['question_number'] ?? ($i + 1)),
                'paragraph_text'  => self::string($item['paragraph_text'] ?? '', 20000),
                'question_text'   => self::string($item['question_text'], 20000),
                'option_a'        => self::string($item['option_a'] ?? '', 5000),
                'option_b'        => self::string($item['option_b'] ?? '', 5000),
                'option_c'        => self::string($item['option_c'] ?? '', 5000),
                'option_d'        => self::string($item['option_d'] ?? '', 5000),
                'correct_answer'  => $correct,
            ];
        }

        if ($normalized === []) {
            $errors[] = 'No valid question items were found in the file.';
        }

        return [$errors, $normalized];
    }
}
