<?php

namespace App\Services;

use App\Core\Validator;
use App\Repositories\ExamQuestionShuffleRepository;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionRepository;

/**
 * ExamImportService
 * -----------------
 * Creates or replaces an exam's questions from an uploaded file.
 *
 * Supported formats:
 *   .json  legacy exam JSON array (Validator::examJson shape)
 *   .docx  Microsoft Word document — parsed heuristically, no template needed
 *   .txt   plain text — parsed with the same heuristics
 *
 * For Word/text files the parser guesses the structure (numbered questions,
 * lettered options, "Answer: X" lines or bolded options). The exam engine
 * grades from the correct-answer key, so questions without a DETECTED answer
 * are rejected up front (the error lists every affected question) rather
 * than silently defaulting to option A and corrupting scores. Teachers who
 * don't mark answers should use the Question Bank's Word import instead,
 * which offers a review step where answers can be set before saving.
 */

class ExamImportService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const SUPPORTED_EXTENSIONS = ['json', 'docx', 'txt'];

    public static function createFromUpload(array $file, array $meta): array
    {
        $errors = self::validateUpload($file);
        if ($errors !== []) {
            return ['errors' => $errors, 'exam_id' => null];
        }
        [$decodeErrors, $rows] = self::decode($file);
        if ($decodeErrors !== []) {
            return ['errors' => $decodeErrors, 'exam_id' => null];
        }

        $examId = ExamRepository::create([
            'exam_name' => $meta['exam_name'],
            'duration' => $meta['duration'],
            'stream' => $meta['stream'],
            'color_theme' => $meta['color_theme'],
            'json_filename' => basename((string) $file['name']),
            'shuffle_questions' => $meta['shuffle_questions'] ?? false,
            'shuffle_choices' => $meta['shuffle_choices'] ?? false,
        ]);
        QuestionRepository::bulkInsert($examId, $rows);
        return ['errors' => [], 'exam_id' => $examId];
    }

    public static function replaceFromUpload(int $examId, array $file, array $meta): array
    {
        $errors = self::validateUpload($file);
        if ($errors !== []) {
            return $errors;
        }
        [$decodeErrors, $rows] = self::decode($file);
        if ($decodeErrors !== []) {
            return $decodeErrors;
        }

        $meta['json_filename'] = basename((string) $file['name']);
        ExamRepository::updateMetaWithFile($examId, $meta);
        QuestionRepository::deleteForExam($examId);
        QuestionRepository::bulkInsert($examId, $rows);
        ExamQuestionShuffleRepository::deleteForExam($examId);
        return [];
    }

    /** @return array<int,string> error messages (empty = OK) */
    private static function validateUpload(array $file): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['The exam file failed to upload. Please try again.'];
        }
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            return ['The exam file is too large (max 5MB).'];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
            return ['Only .json, .docx and .txt exam files are accepted.'];
        }
        if ($ext === 'json') {
            $first = (string) file_get_contents($file['tmp_name'], false, null, 0, 1);
            if ($first !== '[' && $first !== '{') {
                return ['The uploaded file does not look like valid JSON.'];
            }
        }
        return [];
    }

    /**
     * Decode an uploaded file into examJson-shaped rows.
     *
     * @return array{0: array<int,string>, 1: array<int,array<string,mixed>>} [errors, rows]
     */
    private static function decode(array $file): array
    {
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $decoded = json_decode((string) file_get_contents($file['tmp_name']), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [['Invalid JSON: ' . json_last_error_msg()], []];
            }
            [$errors, $rows] = Validator::examJson($decoded);
            return [$errors, $rows];
        }

        // --- Word / text: heuristically parsed ------------------------------
        try {
            $parsed = DocxQuestionParser::parseFile((string) $file['tmp_name'], $ext);
        } catch (\Throwable $e) {
            // A corrupt/hostile upload must never 500 the admin page.
            return [['This file could not be read as a Word document or text file: ' . $e->getMessage()], []];
        }
        if ($parsed['questions'] === []) {
            return [[
                'No questions were recognized in this file. '
                . 'Number each question (1. …), list options as A. B. C. D., and mark the answer '
                . 'with an "Answer: X" line. Use the Question Bank import for a guided review step.',
            ], []];
        }

        $rows = [];
        $missing = [];
        $needingAnswer = 0;
        foreach ($parsed['questions'] as $i => $q) {
            // A detected reading passage becomes a real passage block so
            // students see it with the comprehension questions that follow.
            if (($q['type'] ?? '') === 'Passage') {
                $rows[] = [
                    'type' => 'passage',
                    'id' => 'P' . ($i + 1),
                    'content' => (string) $q['question'],
                ];
                continue;
            }
            $needingAnswer++;
            $correct = strtolower((string) ($q['correct_answer'] ?? ''));
            if (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                $missing[] = '#' . ($i + 1) . ' “' . self::shorten((string) $q['question'], 60) . '”';
                continue;
            }
            $rows[] = [
                'type' => 'question',
                'question_number' => $i + 1,
                'paragraph_text' => '',
                'question_text' => (string) $q['question'],
                'option_a' => (string) ($q['options']['a'] ?? ''),
                'option_b' => (string) ($q['options']['b'] ?? ''),
                'option_c' => (string) ($q['options']['c'] ?? ''),
                'option_d' => (string) ($q['options']['d'] ?? ''),
                'correct_answer' => $correct,
            ];
        }

        if ($missing !== []) {
            $list = implode(', ', $missing);
            $dropped = count($missing);
            return [[
                "The exam engine grades from the answer key, so {$dropped} of {$needingAnswer} question(s) "
                . "could not be imported because their correct answer was not detected: {$list}. "
                . 'Add "Answer: X" lines (or bold the correct option) and upload again, or use the '
                . 'Question Bank import, which lets you set answers during a review step.',
            ], []];
        }

        return [[], $rows];
    }

    private static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max) . '…';
    }
}
