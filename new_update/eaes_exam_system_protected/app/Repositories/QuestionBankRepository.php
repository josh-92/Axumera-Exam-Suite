<?php

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * QuestionBankRepository
 * ----------------------
 * Standalone question bank + exam assignment logic.
 *
 * Data model
 *   Bank rows:              questions.exam_id IS NULL AND source_question_id IS NULL
 *   Materialized exam copy: questions.exam_id SET, source_question_id = bank id
 *                           (created when a bank question is assigned to an exam).
 *   Legacy import rows:     questions.exam_id SET, source_question_id IS NULL
 *
 * The legacy exam-taking engine reads questions by exam_id, so assigning a
 * bank question simply materializes a copy inside the target exam. The
 * exam_question_assignments pivot records the source question, points and
 * display position, and is used for data-integrity checks (a bank question
 * assigned to a LIVE exam cannot be archived).
 *
 * The class deliberately uses the static App\Core\Database::connection()
 * singleton (the only database access pattern that works in the deployed
 * obfuscated build — newer code that news up Database and calls
 * getConnection() does not exist in the shipped Core class). A static test
 * seam (useConnection) lets the integration test point it at a scratch DB.
 */
class QuestionBankRepository
{
    public const TYPES = ['MCQ', 'True/False', 'Essay'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];
    public const MAX_POINTS = 9999.99;
    public const MAX_EXPORT_ROWS = 5000;

    /**
     * Question-type aliases seen in third-party question-bank exports.
     * Keys are lowercased + stripped of spaces/hyphens/underscores so
     * "Multiple Choice", "multiple-choice", "MCQ" all resolve to MCQ.
     */
    public const TYPE_ALIASES = [
        'mcq' => 'MCQ', 'multiplechoice' => 'MCQ', 'multiple' => 'MCQ', 'mc' => 'MCQ',
        'singlechoice' => 'MCQ', 'objective' => 'MCQ', 'choice' => 'MCQ',
        'truefalse' => 'True/False', 'trueorfalse' => 'True/False', 'tf' => 'True/False',
        'boolean' => 'True/False',
        'essay' => 'Essay', 'openended' => 'Essay', 'openendedquestion' => 'Essay',
        'shortanswer' => 'Essay', 'written' => 'Essay', 'subjective' => 'Essay',
    ];

    /** Difficulty aliases seen in exports (keys lowercased, like TYPE_ALIASES). */
    public const DIFFICULTY_ALIASES = [
        'easy' => 'easy', 'e' => 'easy', '1' => 'easy', 'basic' => 'easy', 'simple' => 'easy',
        'medium' => 'medium', 'm' => 'medium', '2' => 'medium', 'moderate' => 'medium', 'average' => 'medium',
        'hard' => 'hard', 'h' => 'hard', '3' => 'hard', 'difficult' => 'hard', 'challenging' => 'hard',
    ];

    private static ?PDO $connection = null;

    /** Test seam: redirect every query to a different connection. */
    public static function useConnection(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    private static function connection(): PDO
    {
        return self::$connection ?? Database::connection();
    }

    // ===================================================================
    // Listing, filtering, facets
    // ===================================================================

    /**
     * Paginated bank listing with search + filters.
     *
     * Supported filters (all optional):
     *   search     free text across question/subject/topic/tags
     *   subject    exact subject match
     *   grade      exact grade match
     *   difficulty easy|medium|hard
     *   type       MCQ|True/False|Essay
     *   date_from  YYYY-MM-DD (created_at >=)
     *   date_to    YYYY-MM-DD (created_at <=)
     *   status     active (default) | archived | all
     *   created_by admin id to restrict to one author
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        self::purgeOrphanSnapshots();

        [$where, $params] = self::bankWhere($filters);

        $count = self::connection()->prepare("SELECT COUNT(*) FROM questions q WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT q.*, a.username AS created_by_name,
                       (SELECT COUNT(*) FROM exam_question_assignments eaq WHERE eaq.question_id = q.id) AS assign_count
                FROM questions q
                LEFT JOIN admin_users a ON a.id = q.created_by
                WHERE {$where}
                ORDER BY q.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /** Distinct filter values for the UI dropdowns (active bank rows only). */
    public static function facets(): array
    {
        $out = ['subjects' => [], 'grades' => [], 'difficulties' => [], 'types' => []];
        $sql = "SELECT DISTINCT subject, grade, difficulty, type
                FROM questions q
                WHERE q.exam_id IS NULL AND q.source_question_id IS NULL AND q.archived_at IS NULL
                ORDER BY 1";
        $rows = self::connection()->query($sql)->fetchAll();
        foreach ($rows as $r) {
            if ($r['subject'] !== null && $r['subject'] !== '') {
                $out['subjects'][] = $r['subject'];
            }
            if ($r['grade'] !== null && $r['grade'] !== '') {
                $out['grades'][] = $r['grade'];
            }
            if ($r['difficulty'] !== null && $r['difficulty'] !== '') {
                $out['difficulties'][] = $r['difficulty'];
            }
            $out['types'][] = $r['type'];
        }
        return $out;
    }

    /** Full question row (bank rows only) plus its exam assignments. */
    public static function find(int $id): ?array
    {
        $stmt = self::connection()->prepare(
            "SELECT q.*, a.username AS created_by_name
             FROM questions q
             LEFT JOIN admin_users a ON a.id = q.created_by
             WHERE q.id = :id AND q.exam_id IS NULL AND q.source_question_id IS NULL
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $stmt = self::connection()->prepare(
            "SELECT e.id, e.exam_name, e.is_live, e.stream, eaq.points, eaq.position
             FROM exam_question_assignments eaq
             JOIN exams e ON e.id = eaq.exam_id
             WHERE eaq.question_id = :id
             ORDER BY eaq.assigned_at DESC"
        );
        $stmt->execute(['id' => $id]);
        $row['assignments'] = $stmt->fetchAll();
        return $row;
    }

    // ===================================================================
    // Create / update
    // ===================================================================

    /**
     * Create or update a bank question. Validates server-side and throws
     * InvalidArgumentException with a user-friendly message on failure.
     *
     * Returns ['id' => int, 'created' => bool].
     */
    public static function save(array $data, int $actorId): array
    {
        $n = self::normalizePayload($data);
        $existingId = (int) ($data['id'] ?? 0);

        if ($existingId > 0) {
            $existing = self::bankRow($existingId);
            if (!$existing) {
                throw new InvalidArgumentException('The question you tried to edit no longer exists in the bank.');
            }
            // NOTE: PDO native prepares (emulation off) forbid reusing a named
            // placeholder, so question/question_text use distinct markers.
            $stmt = self::connection()->prepare(
                "UPDATE questions SET
                     question = :question, question_text = :question_mirror, type = :type,
                     difficulty = :difficulty, topic = :topic, subject = :subject, grade = :grade,
                     is_public = :is_public, tags = :tags, correct_answer = :correct,
                     option_a = :a, option_b = :b, option_c = :c, option_d = :d,
                     updated_at = NOW()
                 WHERE id = :id AND exam_id IS NULL AND source_question_id IS NULL"
            );
            $stmt->execute($n + ['id' => $existingId, 'question_mirror' => $n['question']]);
            return ['id' => $existingId, 'created' => false];
        }

        $stmt = self::connection()->prepare(
            "INSERT INTO questions
                (exam_id, question_number, is_passage, paragraph_text, question_text,
                 option_a, option_b, option_c, option_d, correct_answer,
                 question, type, difficulty, topic, subject, grade, is_public, tags,
                 status, approval_status, created_by, created_at, updated_at)
             VALUES
                (NULL, NULL, 0, NULL, :question, :a, :b, :c, :d, :correct,
                 :question_mirror, :type, :difficulty, :topic, :subject, :grade, :is_public, :tags,
                 'approved', 'Approved', :actor, NOW(), NOW())"
        );
        $stmt->execute($n + ['actor' => $actorId, 'question_mirror' => $n['question']]);
        return ['id' => (int) self::connection()->lastInsertId(), 'created' => true];
    }

    // ===================================================================
    // Soft delete (archive) / restore
    // ===================================================================

    /**
     * Archive (soft-delete) bank questions.
     *
     * Data-integrity rule: a question assigned to a LIVE exam is hard-blocked
     * (students could be mid-attempt). Questions assigned only to non-live
     * exams are archived with a warning listing the affected exams — the
     * materialized copies stay in those exams (their content must not change
     * mid-course), only future assignment is prevented.
     */
    public static function archive(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($id) => $id > 0);
        if ($ids === []) {
            throw new InvalidArgumentException('No questions selected to archive.');
        }

        $blocked = [];
        $affected = [];
        $seenExams = [];
        $archivedCount = 0;

        foreach ($ids as $id) {
            $q = self::bankRow($id);
            if (!$q) {
                continue; // already gone / not a bank row
            }
            $stmt = self::connection()->prepare(
                "SELECT e.id, e.exam_name, e.is_live
                 FROM exam_question_assignments eaq
                 JOIN exams e ON e.id = eaq.exam_id
                 WHERE eaq.question_id = :id
                 ORDER BY e.is_live DESC, e.exam_name ASC"
            );
            $stmt->execute(['id' => $id]);
            $exams = $stmt->fetchAll();

            $hasLive = false;
            foreach ($exams as $e) {
                if ((int) $e['is_live'] === 1) {
                    $hasLive = true;
                } elseif (!isset($seenExams[$e['id']])) {
                    $seenExams[$e['id']] = true;
                    $affected[] = ['id' => (int) $e['id'], 'exam_name' => $e['exam_name'], 'is_live' => 0];
                }
            }

            if ($hasLive) {
                $blocked[] = $id;
                continue;
            }

            $upd = self::connection()->prepare(
                "UPDATE questions SET status = 'archived', approval_status = 'Archived', archived_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND archived_at IS NULL"
            );
            $upd->execute(['id' => $id]);
            $archivedCount += $upd->rowCount();
        }

        return [
            'archived' => $archivedCount,
            'blocked' => $blocked,
            'affected_exams' => $affected,
        ];
    }

    /** Restore archived questions back to the active bank. */
    public static function restore(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($id) => $id > 0);
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::connection()->prepare(
            "UPDATE questions
             SET status = 'approved', approval_status = 'Approved', archived_at = NULL, updated_at = NOW()
             WHERE id IN ({$marks}) AND archived_at IS NOT NULL AND exam_id IS NULL AND source_question_id IS NULL"
        );
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }

    // ===================================================================
    // Assignment to exams
    // ===================================================================

    /** Exams available for assignment (live ones are returned but blocked server-side). */
    public static function assignableExams(): array
    {
        return self::connection()->query(
            "SELECT id, exam_name, is_live, stream, duration FROM exams ORDER BY id DESC"
        )->fetchAll();
    }

    /** Bank questions currently assigned to an exam, with points/position. */
    public static function assigned(int $examId): array
    {
        $stmt = self::connection()->prepare(
            "SELECT eaq.id AS assignment_id, eaq.question_id, eaq.snapshot_id, eaq.points, eaq.position, eaq.assigned_at,
                    COALESCE(q.question, q.question_text) AS question, q.type, q.difficulty, q.subject, q.grade, q.status
             FROM exam_question_assignments eaq
             JOIN questions q ON q.id = eaq.question_id
             WHERE eaq.exam_id = :exam_id
             ORDER BY eaq.position ASC, eaq.id ASC"
        );
        $stmt->execute(['exam_id' => $examId]);
        return $stmt->fetchAll();
    }

    /**
     * Assign bank questions to an exam.
     *
     * Each assignment materializes a snapshot copy inside the exam (so the
     * legacy exam-taking engine picks it up with zero changes) and records
     * the source, points and position in the pivot table.
     *
     * @param array   $questionIds  bank question ids
     * @param float   $defaultPoints default points when the map has no entry
     * @param array   $pointsMap    optional [question_id => points]
     */
    public static function assign(int $examId, array $questionIds, float $defaultPoints, array $pointsMap = [], int $actorId = 0): array
    {
        self::requireEditableExam($examId); // throws if missing or live

        $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
        $questionIds = array_filter($questionIds, fn ($id) => $id > 0);
        if ($questionIds === []) {
            throw new InvalidArgumentException('No questions selected to assign.');
        }
        self::validatePoints($defaultPoints);

        $marks = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = self::connection()->prepare(
            "SELECT id, COALESCE(question, question_text) AS question, type, difficulty, topic, subject, grade,
                    is_public, tags, status, option_a, option_b, option_c, option_d, correct_answer, created_by
             FROM questions
             WHERE id IN ({$marks}) AND exam_id IS NULL AND source_question_id IS NULL"
        );
        $stmt->execute(array_values($questionIds));
        $questions = [];
        foreach ($stmt->fetchAll() as $row) {
            $questions[(int) $row['id']] = $row;
        }

        $errors = [];
        $toAssign = [];
        foreach ($questionIds as $id) {
            if (!isset($questions[$id])) {
                $errors[] = "Question #{$id} was not found in the bank.";
                continue;
            }
            $q = $questions[$id];
            if ($q['status'] === 'archived') {
                $errors[] = "Question #{$id} is archived — restore it before assigning.";
                continue;
            }
            if ($q['type'] === 'Essay') {
                $errors[] = "Question #{$id} is an Essay and cannot be auto-graded by the exam engine. Assign MCQ or True/False questions only.";
                continue;
            }
            $toAssign[] = $q;
        }

        if ($toAssign === []) {
            throw new InvalidArgumentException($errors ? implode(' ', $errors) : 'No assignable questions were selected.');
        }

        // Reject duplicates against existing assignments up front (unique key would also catch these).
        // All-positional placeholders: PDO rejects mixing named and positional markers.
        // Marker count must match the FILTERED list (invalid items were dropped above).
        $idsToCheck = array_values(array_map(fn ($q) => $q['id'], $toAssign));
        $checkMarks = implode(',', array_fill(0, count($idsToCheck), '?'));
        $existing = self::connection()->prepare(
            "SELECT question_id FROM exam_question_assignments WHERE exam_id = ? AND question_id IN ({$checkMarks})"
        );
        $existing->execute(array_merge([$examId], $idsToCheck));
        $dupIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $toAssign = array_values(array_filter($toAssign, fn ($q) => !in_array((int) $q['id'], $dupIds, true)));
        foreach ($dupIds as $dupId) {
            $errors[] = "Question #{$dupId} is already assigned to this exam.";
        }

        $pdo = self::connection();
        $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(question_number), 0) + 1 FROM questions WHERE exam_id = :exam_id");
        $stmtNum->execute(['exam_id' => $examId]);
        $nextNumber = (int) $stmtNum->fetchColumn();

        $snapStmt = $pdo->prepare(
            "INSERT INTO questions
                (exam_id, question_number, is_passage, paragraph_text, question_text,
                 option_a, option_b, option_c, option_d, correct_answer,
                 question, type, difficulty, topic, subject, grade, is_public, tags,
                 status, approval_status, created_by, source_question_id, created_at, updated_at)
             VALUES
                (:exam_id, :qnum, 0, NULL, :question, :a, :b, :c, :d, :correct,
                 :question_mirror, :type, :difficulty, :topic, :subject, :grade, :is_public, :tags,
                 'approved', 'Approved', :creator, :source_id, NOW(), NOW())"
        );
        $pivotStmt = $pdo->prepare(
            "INSERT INTO exam_question_assignments
                (exam_id, question_id, snapshot_id, points, position, assigned_by, assigned_at, updated_at)
             VALUES (:exam_id, :question_id, :snapshot_id, :points, :position, :assigned_by, NOW(), NOW())"
        );

        $created = [];
        $pdo->beginTransaction();
        try {
            foreach ($toAssign as $q) {
                $qnum = $nextNumber++;
                $snapStmt->execute([
                    'exam_id' => $examId,
                    'qnum' => $qnum,
                    'question' => $q['question'],
                    'question_mirror' => $q['question'],
                    'a' => $q['option_a'] ?? '',
                    'b' => $q['option_b'] ?? '',
                    'c' => $q['option_c'] ?? '',
                    'd' => $q['option_d'] ?? '',
                    'correct' => $q['correct_answer'] ?? '',
                    'type' => $q['type'],
                    'difficulty' => $q['difficulty'],
                    'topic' => $q['topic'],
                    'subject' => $q['subject'],
                    'grade' => $q['grade'],
                    'is_public' => (int) $q['is_public'],
                    'tags' => $q['tags'],
                    'creator' => $q['created_by'],
                    'source_id' => (int) $q['id'],
                ]);
                $snapshotId = (int) $pdo->lastInsertId();

                $points = isset($pointsMap[$q['id']]) ? (float) $pointsMap[$q['id']] : $defaultPoints;
                self::validatePoints($points);
                $pivotStmt->execute([
                    'exam_id' => $examId,
                    'question_id' => (int) $q['id'],
                    'snapshot_id' => $snapshotId,
                    'points' => $points,
                    'position' => $qnum,
                    'assigned_by' => $actorId > 0 ? $actorId : null,
                ]);
                $created[] = ['question_id' => (int) $q['id'], 'snapshot_id' => $snapshotId, 'points' => $points];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['assigned' => count($created), 'created' => $created, 'errors' => $errors];
    }

    /** Remove a bank question from an exam: deletes the snapshot copy + pivot row. */
    public static function unassign(int $examId, int $questionId): void
    {
        self::requireEditableExam($examId);

        $pivot = self::connection()->prepare(
            "SELECT snapshot_id FROM exam_question_assignments WHERE exam_id = :exam_id AND question_id = :question_id LIMIT 1"
        );
        $pivot->execute(['exam_id' => $examId, 'question_id' => $questionId]);
        $row = $pivot->fetch();
        if (!$row) {
            throw new InvalidArgumentException('This question is not assigned to the selected exam.');
        }

        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            if ($row['snapshot_id']) {
                $pdo->prepare("DELETE FROM questions WHERE id = :id AND exam_id = :exam_id")
                    ->execute(['id' => (int) $row['snapshot_id'], 'exam_id' => $examId]);
            }
            $pdo->prepare("DELETE FROM exam_question_assignments WHERE exam_id = :exam_id AND question_id = :question_id")
                ->execute(['exam_id' => $examId, 'question_id' => $questionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Update the marks for an already-assigned question. */
    public static function updatePoints(int $examId, int $questionId, float $points): bool
    {
        self::requireEditableExam($examId);
        self::validatePoints($points);
        $stmt = self::connection()->prepare(
            "UPDATE exam_question_assignments SET points = :points, updated_at = NOW()
             WHERE exam_id = :exam_id AND question_id = :question_id"
        );
        $stmt->execute(['points' => $points, 'exam_id' => $examId, 'question_id' => $questionId]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        // rowCount() is 0 both when the value didn't change AND when the
        // assignment is missing — disambiguate so re-saving identical marks
        // doesn't surface a spurious failure.
        $exists = self::connection()->prepare(
            "SELECT 1 FROM exam_question_assignments WHERE exam_id = :exam_id AND question_id = :question_id LIMIT 1"
        );
        $exists->execute(['exam_id' => $examId, 'question_id' => $questionId]);
        return (bool) $exists->fetchColumn();
    }

    // ===================================================================
    // Import / export
    // ===================================================================

    /**
     * Import bank questions from parsed CSV/JSON rows.
     * Valid rows are inserted (in one transaction); invalid rows are
     * reported with their line number so the admin can fix and re-upload.
     */
    public static function import(array $rows, array $defaults, int $actorId): array
    {
        $errors = [];
        $inserted = 0;
        $total = count($rows);

        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                // Prefer the physical file line attached by parseCsv() for
                // accurate error reporting; fall back to the row ordinal for JSON.
                $line = is_array($row) && isset($row['_line']) ? (int) $row['_line'] : $i + 1;
                if (is_array($row)) {
                    unset($row['_line']);
                }
                // Non-empty row values override the defaults; empty cells fall
                // back to them (array_merge would let an empty string shadow a default).
                $rowValues = is_array($row) ? array_filter($row, fn ($v) => $v !== '' && $v !== null) : [];
                $payload = array_merge($defaults, $rowValues);
                try {
                    $n = self::normalizePayload($payload);
                    $stmt = $pdo->prepare(
                        "INSERT INTO questions
                            (exam_id, question_number, is_passage, paragraph_text, question_text,
                             option_a, option_b, option_c, option_d, correct_answer,
                             question, type, difficulty, topic, subject, grade, is_public, tags,
                             status, approval_status, created_by, created_at, updated_at)
                         VALUES
                            (NULL, NULL, 0, NULL, :question, :a, :b, :c, :d, :correct,
                             :question_mirror, :type, :difficulty, :topic, :subject, :grade, :is_public, :tags,
                             'approved', 'Approved', :actor, NOW(), NOW())"
                    );
                    $stmt->execute($n + ['actor' => $actorId, 'question_mirror' => $n['question']]);
                    $inserted++;
                } catch (InvalidArgumentException $e) {
                    $errors[] = ['line' => $line, 'message' => $e->getMessage()];
                } catch (\Throwable $e) {
                    $errors[] = ['line' => $line, 'message' => 'Database error: ' . $e->getMessage()];
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['imported' => $inserted, 'total' => $total, 'errors' => $errors];
    }

    /**
     * Parse CSV content into associative rows keyed by header.
     * Handles BOM, quoted fields with embedded newlines, and header aliases
     * (question/question_text, correct_answer/correct, option_a…).
     */
    public static function parseCsv(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip UTF-8 BOM
        if (trim($content) === '') {
            throw new InvalidArgumentException('The CSV file is empty.');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = null;
        $rows = [];
        $lineNo = 0;
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($line === [null] || $line === null) {
                continue; // blank line
            }
            $line = array_map(fn ($cell) => trim((string) $cell), $line);
            if ($headers === null) {
                $headers = array_map(fn ($h) => strtolower(str_replace(' ', '_', $h)), $line);
                if (!in_array('question', $headers, true) && !in_array('question_text', $headers, true)) {
                    fclose($handle);
                    throw new InvalidArgumentException('The CSV must have a "question" column header.');
                }
                continue;
            }
            $row = [];
            $empty = true;
            foreach ($headers as $idx => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $line[$idx] ?? '';
                if ($value !== '') {
                    $empty = false;
                }
                $row[$header] = $value;
            }
            if ($empty) {
                continue;
            }
            // Carry the physical file line so import() can report exactly which
            // row of the uploaded file failed. import() strips this key again.
            $row['_line'] = $lineNo;
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            throw new InvalidArgumentException('No data rows were found in the CSV file.');
        }
        return $rows;
    }

    /** Export the current filter set (no pagination). */
    public static function export(array $filters): array
    {
        self::purgeOrphanSnapshots();
        [$where, $params] = self::bankWhere($filters);
        $sql = "SELECT q.*, a.username AS created_by_name
                FROM questions q
                LEFT JOIN admin_users a ON a.id = q.created_by
                WHERE {$where}
                ORDER BY q.id DESC
                LIMIT " . self::MAX_EXPORT_ROWS;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ===================================================================
    // Internals
    // ===================================================================

    /** Shared WHERE clause for bank listings (active/archived/all + filters). */
    private static function bankWhere(array $filters): array
    {
        $clauses = ["q.exam_id IS NULL", "q.source_question_id IS NULL"];
        $params = [];

        $status = $filters['status'] ?? 'active';
        if ($status === 'archived') {
            $clauses[] = "q.archived_at IS NOT NULL";
        } elseif ($status !== 'all') {
            $clauses[] = "q.archived_at IS NULL";
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Distinct markers per column — native PDO prepares reject a reused
            // named placeholder (HY093), so each LIKE gets its own param.
            $clauses[] = "(COALESCE(q.question, q.question_text) LIKE :search1 ESCAPE '\\\\'
                           OR q.subject LIKE :search2 ESCAPE '\\\\'
                           OR q.topic LIKE :search3 ESCAPE '\\\\'
                           OR q.tags LIKE :search4 ESCAPE '\\\\')";
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
            $params['search3'] = $term;
            $params['search4'] = $term;
        }

        foreach (['subject', 'grade', 'difficulty', 'type'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $clauses[] = "q.{$field} = :f_{$field}";
                $params["f_{$field}"] = $value;
            }
        }

        if (!empty($filters['created_by'])) {
            $clauses[] = "q.created_by = :created_by";
            $params['created_by'] = (int) $filters['created_by'];
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $op) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new InvalidArgumentException("Invalid date format for {$field} (expected YYYY-MM-DD).");
                }
                $time = $op === '>=' ? ' 00:00:00' : ' 23:59:59';
                $clauses[] = "q.created_at {$op} :{$field}";
                $params[$field] = $value . $time;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Validate + normalize a single question payload. Used by save() and
     * import(). Throws InvalidArgumentException on the first problem.
     */
    public static function normalizePayload(array $data): array
    {
        $questionRaw = $data['question'] ?? $data['question_text'] ?? '';
        $question = is_string($questionRaw) ? trim($questionRaw) : '';
        if ($question === '') {
            throw new InvalidArgumentException('Question text is required.');
        }
        if (mb_strlen($question) > 20000) {
            throw new InvalidArgumentException('Question text is too long (max 20,000 characters).');
        }

        // Accept 'type' (internal) or 'question_type' (third-party exports),
        // plus natural-language labels like "Multiple Choice".
        $typeRaw = $data['type'] ?? $data['question_type'] ?? 'MCQ';
        $type = self::canonicalType((string) $typeRaw);
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid question type '{$typeRaw}'. Allowed: " . implode(', ', self::TYPES) . '.');
        }

        // Default visibility matches the UI + DB column default: public.
        $isPublic = array_key_exists('is_public', $data) ? (!empty($data['is_public']) ? 1 : 0) : 1;

        // Difficulty is case-insensitive and accepts synonyms ("Easy", "Hard",
        // "moderate", and numeric codes 1/2/3 from some exports).
        $difficultyRaw = $data['difficulty'] ?? null;
        $difficulty = null;
        if (is_scalar($difficultyRaw) && trim((string) $difficultyRaw) !== '') {
            $difficulty = self::canonicalDifficulty(trim((string) $difficultyRaw));
            if (!in_array($difficulty, self::DIFFICULTIES, true)) {
                throw new InvalidArgumentException("Invalid difficulty '{$difficultyRaw}'. Allowed: easy, medium, hard.");
            }
        }

        $subject = self::bounded($data['subject'] ?? '', 100, 'subject');
        $grade = self::bounded($data['grade'] ?? '', 50, 'grade');
        $topic = self::bounded($data['topic'] ?? '', 255, 'topic');
        $tagsRaw = $data['tags'] ?? '';
        if (is_array($tagsRaw)) { // exports often send tags as an array
            $tagsRaw = implode(', ', array_filter(
                array_map(fn ($t) => trim((string) $t), $tagsRaw),
                fn ($t) => $t !== ''
            ));
        }
        $tags = self::bounded((string) $tagsRaw, 500, 'tags');

        // MCQ options — accept a nested object ({"A":…, "B":…} as in third-party
        // exports, or a plain [a,b,c,d] list) OR flat option_a..option_d keys.
        $options = [];
        if (isset($data['options']) && is_array($data['options'])) {
            $opts = $data['options'];
            // A numeric list is either 0-indexed [a,b,c,d] or 1-indexed {1:..,2:..};
            // detect once so letters never map to the wrong slot.
            $isZeroIndexed = array_key_exists(0, $opts);
            foreach (['a', 'b', 'c', 'd'] as $i => $letter) {
                $value = $opts[$letter] ?? $opts[strtoupper($letter)] ?? null;
                if ($value === null) {
                    $value = $isZeroIndexed ? ($opts[$i] ?? '') : ($opts[$i + 1] ?? '');
                }
                $options[$letter] = self::bounded(is_scalar($value) ? (string) $value : '', 5000, "option_{$letter}") ?? '';
            }
        } else {
            foreach (['a', 'b', 'c', 'd'] as $letter) {
                // options are NOT NULL in the base schema — empty cells become ''
                $options[$letter] = self::bounded($data["option_{$letter}"] ?? '', 5000, "option_{$letter}") ?? '';
            }
        }

        // Correct answer: accept 'correct_answer' (internal) or 'answer' (exports).
        $correctRaw = (string) ($data['correct_answer'] ?? $data['answer'] ?? '');

        if ($type === 'True/False') {
            $trueFalse = strtolower(trim($correctRaw));
            if (!in_array($trueFalse, ['a', 'b', 'true', 'false'], true)) {
                throw new InvalidArgumentException('True/False questions must have a correct answer (True or False).');
            }
            $correct = $trueFalse === 'b' || $trueFalse === 'false' ? 'b' : 'a';
            $options = ['a' => 'True', 'b' => 'False', 'c' => '', 'd' => ''];
        } elseif ($type === 'Essay') {
            $options = ['a' => '', 'b' => '', 'c' => '', 'd' => ''];
            $correct = '';
        } else { // MCQ
            $correct = strtolower(trim($correctRaw));
            if (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                throw new InvalidArgumentException('MCQ questions must have a correct answer (A, B, C or D).');
            }
            $filled = array_filter($options, fn ($o) => $o !== '');
            if (count($filled) < 2) {
                throw new InvalidArgumentException('MCQ questions need at least two non-empty options.');
            }
            if ($options[$correct] === '') {
                throw new InvalidArgumentException('The correct answer option cannot be empty.');
            }
        }

        return [
            'question' => $question,
            'type' => $type,
            'difficulty' => $difficulty !== '' ? $difficulty : null,
            'topic' => $topic,
            'subject' => $subject,
            'grade' => $grade,
            'is_public' => $isPublic,
            'tags' => $tags,
            'a' => $options['a'],
            'b' => $options['b'],
            'c' => $options['c'],
            'd' => $options['d'],
            'correct' => $correct,
        ];
    }

    /** Normalize a type label ("Multiple Choice", "TRUE/FALSE", "Open Ended", …). */
    private static function canonicalType(string $raw): string
    {
        $key = strtolower($raw);
        $key = (string) preg_replace('/[^a-z0-9]/', '', $key); // strip spaces, hyphens, slashes
        return self::TYPE_ALIASES[$key] ?? $raw;
    }

    /** Normalize a difficulty label ("Easy", "MEDIUM", "moderate", "Difficult", …). */
    private static function canonicalDifficulty(string $raw): string
    {
        $key = strtolower($raw);
        $key = (string) preg_replace('/[^a-z0-9]/', '', $key);
        return self::DIFFICULTY_ALIASES[$key] ?? $key;
    }

    /** Load a single bank row (exam_id NULL + source_question_id NULL). */
    private static function bankRow(int $id): ?array
    {
        $stmt = self::connection()->prepare(
            "SELECT id, status FROM questions WHERE id = :id AND exam_id IS NULL AND source_question_id IS NULL LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Ensure the exam exists and is not live (assign/unassign/points mutations). */
    private static function requireEditableExam(int $examId): array
    {
        $stmt = self::connection()->prepare("SELECT id, exam_name, is_live FROM exams WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $examId]);
        $exam = $stmt->fetch();
        if (!$exam) {
            throw new InvalidArgumentException('The selected exam no longer exists.');
        }
        if ((int) $exam['is_live'] === 1) {
            throw new InvalidArgumentException(
                'This exam is live right now — question assignments cannot be changed while students are taking it.'
            );
        }
        return $exam;
    }

    private static function validatePoints(float $points): void
    {
        if ($points <= 0 || $points > self::MAX_POINTS) {
            throw new InvalidArgumentException('Points must be between 0.01 and ' . self::MAX_POINTS . '.');
        }
    }

    private static function bounded(mixed $value, int $max, string $label): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is too long (max {$max} characters).");
        }
        return $value;
    }

    /**
     * Opportunistic housekeeping run before every list/export:
     *   1. Remove materialized copies that lost their exam (only possible if
     *      the older decouple migration ran first and changed the exam FK to
     *      SET NULL). Such rows belong to no exam and are not standalone bank
     *      questions, so deleting them is always safe.
     *   2. Drop assignment records whose snapshot no longer exists — e.g.
     *      after an admin re-uploads an exam's JSON, which hard-replaces the
     *      exam's question rows (including materialized copies) while the
     *      legacy import service cannot touch the pivot table.
     */
    private static function purgeOrphanSnapshots(): void
    {
        self::connection()->exec(
            "DELETE FROM questions WHERE exam_id IS NULL AND source_question_id IS NOT NULL"
        );
        self::connection()->exec(
            "DELETE eaq FROM exam_question_assignments eaq
             LEFT JOIN questions s ON s.id = eaq.snapshot_id
             WHERE eaq.snapshot_id IS NOT NULL AND s.id IS NULL"
        );
    }
}
