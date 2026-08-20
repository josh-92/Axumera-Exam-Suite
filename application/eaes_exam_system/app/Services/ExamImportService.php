<?php

namespace App\Services;

use App\Core\Validator;
use App\Repositories\ExamQuestionShuffleRepository;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionRepository;

class ExamImportService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5MB

    /** @return array{errors: string[], exam_id: ?int} */
    public static function createFromUpload(array $file, array $meta): array
    {
        $errors = self::validateUpload($file);
        if ($errors) {
            return ['errors' => $errors, 'exam_id' => null];
        }

        [$jsonErrors, $normalized] = self::decode($file);
        if ($jsonErrors) {
            return ['errors' => $jsonErrors, 'exam_id' => null];
        }

        $examId = ExamRepository::create([
            'exam_name'     => $meta['exam_name'],
            'duration'      => $meta['duration'],
            'stream'        => $meta['stream'],
            'color_theme'   => $meta['color_theme'],
            'json_filename' => basename($file['name']),
        ]);

        QuestionRepository::bulkInsert($examId, $normalized);

        return ['errors' => [], 'exam_id' => $examId];
    }

    /** @return string[] errors (empty = success) */
    public static function replaceFromUpload(int $examId, array $file, array $meta): array
    {
        $errors = self::validateUpload($file);
        if ($errors) {
            return $errors;
        }

        [$jsonErrors, $normalized] = self::decode($file);
        if ($jsonErrors) {
            return $jsonErrors;
        }

        $meta['json_filename'] = basename($file['name']);
        ExamRepository::updateMetaWithFile($examId, $meta);
        QuestionRepository::deleteForExam($examId);
        QuestionRepository::bulkInsert($examId, $normalized);

        // The question set just changed shape (different count/order/content),
        // so any previously-generated per-student shuffle for this exam would
        // now reference stale or non-existent question numbers. Wipe them —
        // any attempt that already has answers saved keeps those answers
        // (they're keyed by question_number in exam_attempts, untouched here);
        // only the display-order cache is cleared, and it regenerates fresh
        // the next time a student's exam page loads.
        ExamQuestionShuffleRepository::deleteForExam($examId);

        return [];
    }

    private static function validateUpload(array $file): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['The exam file failed to upload. Please try again.'];
        }
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            return ['The exam file is too large (max 5MB).'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            return ['Only .json exam files are accepted.'];
        }
        // Defence-in-depth: verify actual content looks like JSON, not just the extension.
        $head = file_get_contents($file['tmp_name'], false, null, 0, 1);
        if ($head !== '[' && $head !== '{') {
            return ['The uploaded file does not look like valid JSON.'];
        }
        return [];
    }

    private static function decode(array $file): array
    {
        $raw = file_get_contents($file['tmp_name']);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [['Invalid JSON: ' . json_last_error_msg()], []];
        }
        return Validator::examJson($decoded);
    }
}
