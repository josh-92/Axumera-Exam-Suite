<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * StudentRepository
 * -----------------
 * Student identity + authentication for the gated exam portal.
 *
 * The student "ID" is the (roll_number, stream) natural key — roll numbers
 * are school-assigned (1–999) and may repeat across streams. Every exam
 * gate is backed by this registry; passwords are bcrypt-hashed exactly like
 * admin passwords.
 *
 * A student row can exist with password_hash = NULL — these are legacy rows
 * created by the old name/roll "login" before passwords existed. Such rows
 * cannot log in until the student "claims" the account via provision(),
 * which verifies the stored full_name + section before setting a password
 * (so a stranger cannot claim someone else's identity).
 *
 * The class deliberately uses the static App\Core\Database::connection()
 * singleton (the only DB-access pattern that works in the deployed
 * obfuscated build). A static test seam (useConnection) lets the
 * integration test point it at a scratch DB, mirroring
 * QuestionBankRepository::useConnection().
 */
class StudentRepository
{
    public const MAX_AUTH_ATTEMPTS = 5;
    public const AUTH_LOCK_MINUTES = 15;

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
    // Lookup
    // ===================================================================

    /**
     * Full student row by the (roll_number, stream) natural key.
     * Only ACTIVE students (archived ones are invisible to login, claims,
     * admin search and removal resolution — they live in the archive).
     */
    public static function findByRollAndStream(string $rollNumber, string $stream): ?array
    {
        $stmt = self::connection()->prepare(
            'SELECT * FROM students WHERE roll_number = :r AND stream = :s AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['r' => $rollNumber, 's' => $stream]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Full student row by id (optionally including archived rows). */
    public static function findById(int $id, bool $includeArchived = false): ?array
    {
        $sql = 'SELECT * FROM students WHERE id = :id' . ($includeArchived ? '' : ' AND deleted_at IS NULL') . ' LIMIT 1';
        $stmt = self::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Total ACTIVE registered students (analytics). */
    public static function count(): int
    {
        return (int) self::connection()->query('SELECT COUNT(*) FROM students WHERE deleted_at IS NULL')->fetchColumn();
    }

    /** Archived row with the given (roll_number, stream) — blocks re-adding the roll until restored/purged. */
    public static function findArchivedByRollAndStream(string $rollNumber, string $stream): ?array
    {
        $stmt = self::connection()->prepare(
            'SELECT * FROM students WHERE roll_number = :r AND stream = :s AND deleted_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute(['r' => $rollNumber, 's' => $stream]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Admin listing with optional free-text search across roll number,
     * full name and stream. Newest rows first.
     */
    /**
     * Admin listing of ACTIVE students with optional free-text search
     * across roll number, full name and stream. Newest rows first.
     */
    public static function search(string $term = '', int $limit = 300): array
    {
        return self::searchWhere('s.deleted_at IS NULL', $term, $limit);
    }

    /**
     * Admin listing of ARCHIVED (soft-deleted) students — the restore view.
     * Same search semantics as search().
     */
    public static function searchArchived(string $term = '', int $limit = 300): array
    {
        return self::searchWhere('s.deleted_at IS NOT NULL', $term, $limit);
    }

    private static function searchWhere(string $where, string $term, int $limit): array
    {
        $sql = "SELECT s.*,
                       (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.student_id = s.id) AS attempt_count,
                       (SELECT ea2.status FROM exam_attempts ea2
                         WHERE ea2.student_id = s.id ORDER BY ea2.id DESC LIMIT 1) AS last_attempt_status,
                       (SELECT ea3.score FROM exam_attempts ea3
                         WHERE ea3.student_id = s.id ORDER BY ea3.id DESC LIMIT 1) AS last_attempt_score
                FROM students s";
        $params = [];
        $term = trim($term);
        if ($term !== '') {
            $sql .= ' WHERE ' . $where . ' AND (s.roll_number LIKE :t1 OR s.full_name LIKE :t2 OR s.stream LIKE :t3)';
            $like = '%' . $term . '%';
            $params = ['t1' => $like, 't2' => $like, 't3' => $like];
        } else {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY s.roll_number ASC, s.stream ASC LIMIT ' . (int) $limit;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Number of attempt records owned by a student (used for deletion warnings). */
    public static function attemptCount(int $id): int
    {
        $stmt = self::connection()->prepare('SELECT COUNT(*) FROM exam_attempts WHERE student_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether the student has an in-progress attempt on a LIVE exam.
     * Removing such a student mid-exam would silently erase a sitting, so
     * the admin UI refuses to delete until the exam is stopped.
     */
    public static function hasLiveInProgressAttempt(int $id): bool
    {
        $stmt = self::connection()->prepare(
            "SELECT COUNT(*) FROM exam_attempts ea
             JOIN exams e ON e.id = ea.exam_id
             WHERE ea.student_id = :id AND ea.status = 'in_progress' AND e.is_live = 1"
        );
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Archive (soft-delete) a student: sets deleted_at so they vanish from
     * login, lookups and the active list, but the row — and their attempt
     * history and question shuffles — is fully restorable. This is what
     * every "Remove" flow does now, so mistakes are reversible.
     */
    public static function archive(int $id): void
    {
        self::connection()->prepare('UPDATE students SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /** Bring an archived student back — everything (attempts included) is intact. */
    public static function restore(int $id): void
    {
        self::connection()->prepare('UPDATE students SET deleted_at = NULL WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Permanently remove an archived student. Their attempt records and
     * per-student question shuffles are removed by the FK ON DELETE
     * CASCADE — callers must warn the admin about the attempt count first.
     */
    public static function purge(int $id): void
    {
        self::connection()->prepare('DELETE FROM students WHERE id = :id')->execute(['id' => $id]);
    }

    /** All ACTIVE students with a given roll number (a roll may exist in both streams). */
    public static function findByRoll(string $rollNumber): array
    {
        $stmt = self::connection()->prepare(
            "SELECT * FROM students
             WHERE deleted_at IS NULL
               AND (roll_number = :r OR (roll_number REGEXP '^[0-9]+$' AND CAST(roll_number AS UNSIGNED) = :n))
             ORDER BY id"
        );
        $stmt->execute(['r' => $rollNumber, 'n' => (int) $rollNumber]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Resolve a roll-number removal list WITHOUT archiving anything — the
     * "preview" step. Every entry comes back with a status: 'removed'
     * (a candidate that WILL be archived, carrying its student_id),
     * 'skipped' (not found, or mid-exam on a live exam) or 'errors'
     * (bad roll/stream). The admin confirms before any row is touched.
     *
     * @param array<int, array{roll_number: string, stream: ?string, _line: int}> $entries
     * @return array{candidates: int, skipped: int, errors: int, rows: array<int, array{line: int, roll: string, name: string, stream: string, section: string, status: string, reason: string, attempts: int, student_id: ?int}>}
     */
    public static function resolveRemovalEntries(array $entries): array
    {
        $out = ['candidates' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];
        foreach ($entries as $entry) {
            $line = (int) ($entry['_line'] ?? 0);
            $roll = trim((string) ($entry['roll_number'] ?? ''));
            $streamRaw = trim((string) ($entry['stream'] ?? ''));
            $stream = $streamRaw !== '' ? self::normalizeStream($streamRaw) : null;

            $emit = function (string $status, string $reason, array $student = [], int $attempts = 0) use (&$out, $line, $roll, $streamRaw) {
                $out[$status === 'removed' ? 'candidates' : $status]++;
                $out['rows'][] = [
                    'line' => $line, 'roll' => $roll, 'name' => (string) ($student['full_name'] ?? ''),
                    'stream' => (string) ($student['stream'] ?? $streamRaw), 'section' => (string) ($student['section'] ?? ''),
                    'status' => $status, 'reason' => $reason, 'attempts' => $attempts,
                    'student_id' => $status === 'removed' ? (int) ($student['id'] ?? 0) : null,
                ];
            };

            if (!preg_match('/^\d{1,3}$/', $roll) || (int) $roll < 1 || (int) $roll > 999) {
                $emit('errors', 'Roll number must be a whole number 1-999.');
                continue;
            }
            if ($streamRaw !== '' && $stream === null) {
                $emit('errors', 'Stream must be "Natural Science" or "Social Science" (got "' . $streamRaw . '").');
                continue;
            }

            $candidates = $stream !== null
                ? ($found = self::findByRollAndStream($roll, $stream)) !== null ? [$found] : []
                : self::findByRoll($roll);

            if (!$candidates) {
                $emit('skipped', 'No student found with this roll number' . ($stream !== null ? ' in ' . $stream : '') . '.');
                continue;
            }

            foreach ($candidates as $student) {
                $id = (int) $student['id'];
                if (self::hasLiveInProgressAttempt($id)) {
                    $emit('skipped', 'Has an in-progress attempt on a live exam — stop the exam first.', $student, self::attemptCount($id));
                    continue;
                }
                $emit('removed', 'Will be archived.', $student, self::attemptCount($id));
            }
        }
        return $out;
    }

    /**
     * The "confirm" step: archive the exact student IDs chosen on the
     * preview screen. Guards are re-checked here (a student may have been
     * archived or gone mid-exam since the preview) — nothing is removed
     * silently.
     *
     * @param array<int, int> $ids
     * @return array{removed: int, skipped: int, rows: array<int, array{student_id: int, name: string, roll: string, stream: string, status: string, reason: string, attempts: int}>}
     */
    public static function archiveCandidates(array $ids): array
    {
        $out = ['removed' => 0, 'skipped' => 0, 'rows' => []];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $student = self::findById($id); // active only
            if (!$student) {
                $out['skipped']++;
                $out['rows'][] = [
                    'student_id' => $id, 'name' => '', 'roll' => '', 'stream' => '',
                    'status' => 'skipped',
                    'reason' => 'No longer active — was removed or archived after the preview.',
                    'attempts' => 0,
                ];
                continue;
            }
            if (self::hasLiveInProgressAttempt($id)) {
                $out['skipped']++;
                $out['rows'][] = [
                    'student_id' => $id, 'name' => (string) $student['full_name'],
                    'roll' => (string) $student['roll_number'], 'stream' => (string) $student['stream'],
                    'status' => 'skipped',
                    'reason' => 'Started a live exam after the preview — stop the exam first.',
                    'attempts' => self::attemptCount($id),
                ];
                continue;
            }
            $attempts = self::attemptCount($id);
            self::archive($id);
            $out['removed']++;
            $out['rows'][] = [
                'student_id' => $id, 'name' => (string) $student['full_name'],
                'roll' => (string) $student['roll_number'], 'stream' => (string) $student['stream'],
                'status' => 'removed',
                'reason' => $attempts > 0
                    ? 'Archived with ' . $attempts . ' attempt record(s) preserved — restorable.'
                    : 'Archived — restorable from the Archived view.',
                'attempts' => $attempts,
            ];
        }
        return $out;
    }

    /**
     * Bulk-remove students by roll-number list (the mirror of importBatch).
     * Resolves every entry, then archives the resolved candidates — rows
     * stay in the database fully restorable from the Archived view.
     * Nothing happens silently: every entry comes back with a status
     * (removed / skipped / error) and a human-readable reason.
     *
     * @param array<int, array{roll_number: string, stream: ?string, _line: int}> $entries
     * @return array{removed: int, skipped: int, errors: int, rows: array<int, array{line: int, roll: string, name: string, stream: string, status: string, reason: string, attempts: int}>}
     */
    public static function removeBatch(array $entries): array
    {
        $resolution = self::resolveRemovalEntries($entries);
        $out = ['removed' => 0, 'skipped' => $resolution['skipped'], 'errors' => $resolution['errors'], 'rows' => []];

        // Dedupe candidate rows by student id: since resolution no longer
        // archives as it goes, the same student can be matched by several
        // entries (e.g. "730" plus "730,Natural Science") — it must only
        // be listed and archived once.
        $ids = [];
        $seen = [];
        foreach ($resolution['rows'] as $r) {
            if ($r['status'] === 'removed') {
                if (isset($seen[$r['student_id']])) {
                    continue;
                }
                $seen[$r['student_id']] = true;
                $ids[] = (int) $r['student_id'];
            }
            $out['rows'][] = $r;
        }
        $archived = self::archiveCandidates($ids);

        // Patch each candidate row with the archive result (keeps entry order).
        $idx = 0;
        foreach ($out['rows'] as $i => $r) {
            if ($r['status'] !== 'removed') {
                continue;
            }
            $a = $archived['rows'][$idx++] ?? null;
            if ($a === null) {
                continue;
            }
            $out['rows'][$i]['status'] = $a['status'];
            $out['rows'][$i]['reason'] = $a['reason'];
            $out['rows'][$i]['attempts'] = $a['attempts'];
            if ($a['status'] === 'removed') {
                $out['removed']++;
            } else {
                $out['skipped']++;
            }
        }
        return $out;
    }

    // ===================================================================
    // Passwords
    // ===================================================================

    public static function hasPassword(array $student): bool
    {
        return !empty($student['password_hash']);
    }

    /** Verify a plaintext password against the stored bcrypt hash. */
    public static function verifyPassword(array $student, string $plain): bool
    {
        $hash = (string) ($student['password_hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    /** Hash + store a new password (also used to reset a forgotten one). */
    public static function setPassword(int $id, string $plain): void
    {
        $stmt = self::connection()->prepare(
            'UPDATE students SET password_hash = :h WHERE id = :id'
        );
        $stmt->execute(['h' => password_hash($plain, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /** Touch last_login_at after a successful login. */
    public static function recordLogin(int $id): void
    {
        self::connection()->prepare('UPDATE students SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Register a brand-new student account (first-time registration).
     * The caller is responsible for the duplicate/claim decision — see
     * provision(). The unique (roll_number, stream) key backstops this:
     * a second insert throws a PDO unique-constraint exception.
     */
    public static function create(string $fullName, string $rollNumber, string $stream, string $section, string $plain): int
    {
        $stmt = self::connection()->prepare(
            'INSERT INTO students (full_name, roll_number, stream, section, password_hash, created_at)
             VALUES (:n, :r, :s, :sec, :h, NOW())'
        );
        $stmt->execute([
            'n' => $fullName,
            'r' => $rollNumber,
            's' => $stream,
            'sec' => $section,
            'h' => password_hash($plain, PASSWORD_DEFAULT),
        ]);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * One-stop registration decision for the admin add-student flow (admin_students.php).
     *
     *   * (roll, stream) exists WITH a password  → 'registered' error
     *   * exists WITHOUT a password (legacy row) → 'claimed' if the typed
     *     full name + section match the stored identity, else error
     *   * does not exist                        → 'created'
     *
     * @return array{ok: bool, id: int, mode: string|null, error: string|null}
     */
    public static function provision(string $fullName, string $rollNumber, string $stream, string $section, string $plain): array
    {
        // Canonicalize recognizable labels so callers don't have to (the
        // legacy-row identity check below compares against stored values).
        $stream = self::normalizeStream($stream) ?? $stream;
        $section = self::normalizeSection($section) ?? $section;

        $existing = self::findByRollAndStream($rollNumber, $stream);
        if ($existing) {
            if (self::hasPassword($existing)) {
                return [
                    'ok' => false,
                    'id' => (int) $existing['id'],
                    'mode' => null,
                    'error' => 'This roll number is already registered. Please log in — or use "Forgot password?" if you lost your password.',
                ];
            }
            // Legacy password-less row: verify the typed identity before claiming.
            $nameMatch = mb_strtolower(trim($fullName)) === mb_strtolower(trim((string) $existing['full_name']));
            $sectionMatch = trim($section) === trim((string) $existing['section']);
            if (!$nameMatch || !$sectionMatch) {
                return [
                    'ok' => false,
                    'id' => (int) $existing['id'],
                    'mode' => null,
                    'error' => 'A record already exists for this roll number, but the name or section you entered does not match it. Please check with your teacher before continuing.',
                ];
            }
            self::setPassword((int) $existing['id'], $plain);
            return ['ok' => true, 'id' => (int) $existing['id'], 'mode' => 'claimed', 'error' => null];
        }
        // An archived row still occupies the (roll, stream) unique key — the
        // admin must restore or purge it before the roll can be used again.
        $archived = self::findArchivedByRollAndStream($rollNumber, $stream);
        if ($archived !== null) {
            return [
                'ok' => false,
                'id' => (int) $archived['id'],
                'mode' => null,
                'error' => 'This roll number is archived. Restore it from Admin → Students → Archived (or purge it there) before re-adding.',
            ];
        }
        return ['ok' => true, 'id' => self::create($fullName, $rollNumber, $stream, $section, $plain), 'mode' => 'created', 'error' => null];
    }

    /**
     * Identity-verified password recovery (forgot.php step 1).
     * Returns the student row only when the typed roll + stream + full
     * name + section all match a REGISTERED (password-set) account, so a
     * reset can only be performed by someone who knows the student's
     * identity details.
     */
    public static function verifyIdentity(string $rollNumber, string $stream, string $fullName, string $section): ?array
    {
        $student = self::findByRollAndStream($rollNumber, $stream);
        if (!$student || !self::hasPassword($student)) {
            return null;
        }
        $nameMatch = mb_strtolower(trim($fullName)) === mb_strtolower(trim((string) $student['full_name']));
        $sectionMatch = trim($section) === trim((string) $student['section']);
        return $nameMatch && $sectionMatch ? $student : null;
    }

    // ===================================================================
    // Bulk import (admin_students.php → Import CSV / Excel)
    // ===================================================================

    /** Max rows accepted from one upload so a single import stays bounded. */
    public const MAX_IMPORT_ROWS = 500;

    /**
     * Map a free-typed stream label to the canonical value the rest of the
     * app stores ("Natural Science" / "Social Science"). Flexible on
     * purpose: case, extra spaces, dashes/dots/underscores, common
     * abbreviations (NS/SS), one-word forms, unambiguous prefixes and the
     * usual Amharic labels all resolve. Returns null when the label can't
     * be pinned to exactly one stream.
     */
    public static function normalizeStream(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        // "Natural Science", "NaturalScience", "natural-science", "N. Science"…
        $v = (string) preg_replace('/[\s\-_.]+/u', '', $v);
        if ($v === '') {
            return null;
        }

        // Explicit aliases (incl. common Amharic labels for Ethiopian schools).
        $aliases = [
            'ns' => 'Natural Science',
            'ss' => 'Social Science',
            'nscience' => 'Natural Science', // "N. Science" / "N Science"
            'sscience' => 'Social Science',
            'nsci' => 'Natural Science',     // "N. Sci"
            'ssci' => 'Social Science',
            'natural' => 'Natural Science',
            'social' => 'Social Science',
            'ተፈጥሮ' => 'Natural Science',
            'ተፈጥሮሳይንስ' => 'Natural Science',
            'ማህበራዊ' => 'Social Science',
            'ማኅበራዊ' => 'Social Science',
            'ማህበራዊሳይንስ' => 'Social Science',
            'ማኅበራዊሳይንስ' => 'Social Science',
        ];
        if (isset($aliases[$v])) {
            return $aliases[$v];
        }

        // Unambiguous prefix of exactly one canonical form (e.g. "na", "soc").
        $matched = [];
        foreach (['Natural Science' => 'naturalscience', 'Social Science' => 'socialscience'] as $label => $norm) {
            if (str_starts_with($norm, $v) || str_starts_with($v, $norm)) {
                $matched[$label] = true;
            }
        }
        return count($matched) === 1 ? array_key_first($matched) : null;
    }

    /** Normalize a free-typed section to the canonical A/B/C (case-insensitive). */
    public static function normalizeSection(string $value): ?string
    {
        $s = strtoupper(trim($value));
        return in_array($s, ['A', 'B', 'C'], true) ? $s : null;
    }

    /**
     * Parse student CSV content into rows keyed by header.
     * Mirrors QuestionBankRepository::parseCsv: strips the UTF-8 BOM,
     * handles quoted fields, tolerates header aliases (name/full_name,
     * roll/roll_number) and skips blank lines. Throws InvalidArgumentException
     * on empty input or missing required columns.
     *
     * @return array<int, array{full_name: string, roll_number: string, stream: string, section: string, _line: int}>
     */
    public static function parseCsv(string $content): array
    {
        return self::normalizeGrid(self::csvToGrid($content));
    }

    /**
     * Parse a .xlsx workbook into the same row shape as parseCsv(), so
     * teachers can upload a real Excel file instead of exporting CSV first.
     * Pure PHP (PharData + SimpleXML — no composer dependency): reads the
     * first sheet via workbook.xml + its relationships, resolves shared
     * strings, inline strings and numbers, then reuses normalizeGrid() for
     * header aliasing and validation. Throws InvalidArgumentException on
     * unreadable or malformed files.
     *
     * @return array<int, array{full_name: string, roll_number: string, stream: string, section: string, _line: int}>
     */
    public static function parseXlsx(string $filePath): array
    {
        return self::normalizeGrid(self::readXlsxGrid($filePath));
    }

    /**
     * Parse a removal list (bulk delete): a .csv/.txt with one roll number
     * per line, or a roll_number[,stream] header. Flexible on purpose — a
     * bare roll-only file, "roll_number,stream", "roll,stream" or a
     * single-column header all work; blank lines are skipped.
     *
     * @return array<int, array{roll_number: string, stream: ?string, _line: int}>
     */
    public static function parseRollCsv(string $content): array
    {
        return self::rollRowsFromGrid(self::csvToGrid($content));
    }

    /**
     * Parse a removal list from an .xlsx workbook: column A = roll number,
     * optional column B = stream (flexible labels via normalizeStream).
     *
     * @return array<int, array{roll_number: string, stream: ?string, _line: int}>
     */
    public static function parseRollXlsx(string $filePath): array
    {
        return self::rollRowsFromGrid(self::readXlsxGrid($filePath));
    }

    /** Parse CSV text into a positional grid (BOM-stripped, quoted fields, blank lines skipped). */
    private static function csvToGrid(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip UTF-8 BOM
        if (trim($content) === '') {
            throw new \InvalidArgumentException('The file is empty.');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $grid = [];
        $lineNo = 0;
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($line === [null] || $line === null) {
                continue; // blank line
            }
            $grid[$lineNo] = array_map(fn ($cell) => trim((string) $cell), $line);
        }
        fclose($handle);

        return $grid;
    }

    /**
     * Read the first worksheet of an .xlsx into a positional grid
     * (physical row number → positional cells). Pure PHP (PharData +
     * SimpleXML): locates the sheet via workbook.xml + relationships with a
     * worksheet-entry fallback, and resolves shared strings, inline strings
     * and numbers.
     *
     * @return array<int, array<int, string>>
     */
    private static function readXlsxGrid(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('The uploaded Excel file could not be read.');
        }
        try {
            $zip = new \PharData($filePath);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The uploaded file is not a valid .xlsx workbook.');
        }

        // Read an entry from the archive (phar:// URLs need forward slashes).
        $read = function (string $entry) use ($zip, $filePath): ?string {
            if (!isset($zip[$entry])) {
                return null;
            }
            $content = @file_get_contents('phar://' . str_replace('\\', '/', $filePath) . '/' . $entry);
            return $content === false ? null : $content;
        };

        // ---- locate the first sheet (workbook.xml → rels → target) ----
        $sheetTarget = null;
        $workbookXml = $read('xl/workbook.xml');
        if ($workbookXml !== null) {
            try {
                $workbook = new \SimpleXMLElement($workbookXml);
                $sheet = $workbook->sheets->sheet[0] ?? null;
                if ($sheet !== null) {
                    $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                    $relsXml = $read('xl/_rels/workbook.xml.rels');
                    if ($relsXml !== null) {
                        $rels = new \SimpleXMLElement($relsXml);
                        foreach ($rels->Relationship as $rel) {
                            if ((string) $rel['Id'] === $rid) {
                                $target = (string) $rel['Target'];
                                if (str_starts_with($target, '/')) {
                                    $sheetTarget = ltrim($target, '/'); // package-root absolute
                                } elseif (str_starts_with($target, 'xl/')) {
                                    $sheetTarget = $target;
                                } else {
                                    $sheetTarget = 'xl/' . $target; // relative to xl/ (the OOXML convention)
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // fall through to the entry scan below
            }
        }
        if ($sheetTarget === null) {
            foreach (new \RecursiveIteratorIterator($zip) as $entry) {
                $name = str_replace('\\', '/', $entry->getPathname());
                if (preg_match('#/xl/worksheets/[^/]+\.xml$#', $name)) {
                    $sheetTarget = (string) preg_replace('#^phar://[^/]+/#', '', $name);
                    break;
                }
            }
        }
        $sheetXml = $sheetTarget !== null ? $read($sheetTarget) : null;
        if ($sheetXml === null) {
            throw new \InvalidArgumentException('The .xlsx file contains no readable worksheet.');
        }

        // ---- shared strings (deduplicated cell text) ----
        $sharedStrings = [];
        $sstXml = $read('xl/sharedStrings.xml');
        if ($sstXml !== null) {
            try {
                $sst = new \SimpleXMLElement($sstXml);
                foreach ($sst->si as $si) {
                    $text = '';
                    foreach ($si->t as $t) {
                        $text .= (string) $t;
                    }
                    if ($text === '') {
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            } catch (\Throwable $e) {
                // malformed shared strings — treat every cell as literal text
            }
        }

        // ---- sheet grid (physical row number → positional cells) ----
        $grid = [];
        try {
            $sheet = new \SimpleXMLElement($sheetXml);
            foreach ($sheet->sheetData->row as $row) {
                $rowNum = (int) ($row['r'] ?? 0);
                if ($rowNum < 1) {
                    continue;
                }
                $cells = [];
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    if ($ref === '') {
                        continue;
                    }
                    $colIdx = self::columnIndex((string) preg_replace('/\d+$/', '', $ref));
                    $type = (string) $c['t'];
                    $value = '';
                    if ($type === 'inlineStr') {
                        $is = $c->is;
                        if ($is !== null) {
                            foreach ($is->t as $t) {
                                $value .= (string) $t;
                            }
                        }
                    } elseif ($type === 's') {
                        $value = $sharedStrings[(int) trim((string) $c->v)] ?? '';
                    } else {
                        $value = trim((string) $c->v);
                        if ($type === 'b') {
                            $value = $value === '1' ? 'TRUE' : 'FALSE';
                        }
                    }
                    $cells[$colIdx] = trim((string) $value);
                }
                $full = [];
                if ($cells) {
                    for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                        $full[$i] = $cells[$i] ?? '';
                    }
                }
                $grid[$rowNum] = $full;
            }
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The .xlsx worksheet could not be read.');
        }
        if (!$grid) {
            throw new \InvalidArgumentException('The Excel file is empty.');
        }

        return $grid;
    }

    /**
     * Convert a positional grid into removal-list entries: header row
     * (roll_number[,stream]) is detected and skipped, otherwise every row
     * is an entry — one cell = roll only, two cells = roll,stream.
     *
     * @param array<int, array<int, string>> $grid
     * @return array<int, array{roll_number: string, stream: ?string, _line: int}>
     */
    private static function rollRowsFromGrid(array $grid): array
    {
        $rows = [];
        $header = null; // [rollIdx, streamIdx|null]
        foreach ($grid as $lineNo => $line) {
            if ($line === []) {
                continue;
            }
            $first = strtolower(trim((string) ($line[0] ?? '')));
            if ($header === null && in_array($first, ['roll_number', 'roll', 'id', 'rollno'], true)) {
                $streamIdx = null;
                foreach ($line as $idx => $cell) {
                    if (in_array(strtolower(trim((string) $cell)), ['stream', 'stream_name'], true)) {
                        $streamIdx = $idx;
                        break;
                    }
                }
                $header = [0, $streamIdx];
                continue;
            }
            if ($header === null && $first === 'stream') {
                continue; // a "stream"-only header with no roll column — skip the header line
            }
            $roll = trim((string) ($line[0] ?? ''));
            if ($roll === '') {
                continue;
            }
            $stream = null;
            if ($header !== null && $header[1] !== null) {
                $stream = trim((string) ($line[$header[1]] ?? ''));
            } elseif (isset($line[1]) && trim((string) $line[1]) !== '') {
                $stream = trim((string) $line[1]); // bare two-column file: roll,stream
            }
            $rows[] = ['roll_number' => $roll, 'stream' => $stream !== '' ? $stream : null, '_line' => $lineNo];
        }
        if (!$rows) {
            throw new \InvalidArgumentException('The file contains no roll numbers to remove.');
        }
        return $rows;
    }

    /** Convert a spreadsheet column reference (A, B, …, Z, AA, …) to a 0-based index. */
    private static function columnIndex(string $letters): int
    {
        $index = 0;
        $letters = strtoupper($letters);
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Shared grid → rows pipeline for both parseCsv() and parseXlsx():
     * maps the header row (with friendly aliases), validates the required
     * columns, skips blank rows, enforces MAX_IMPORT_ROWS and attaches the
     * physical line/row number as _line.
     *
     * @param array<int, array<int, string>> $grid physical row number → positional cells
     * @return array<int, array{full_name: string, roll_number: string, stream: string, section: string, _line: int}>
     */
    private static function normalizeGrid(array $grid): array
    {
        $headers = null;
        $rows = [];
        foreach ($grid as $lineNo => $line) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => strtolower(str_replace(' ', '_', $h)), $line);
                // Accept friendly aliases so teachers don't need the exact key names.
                $mapped = [];
                foreach ($headers as $idx => $header) {
                    $mapped[$idx] = match ($header) {
                        'name' => 'full_name',
                        'roll', 'id', 'roll_no' => 'roll_number',
                        default => $header,
                    };
                }
                $headers = $mapped;
                $missing = array_diff(['full_name', 'roll_number', 'stream', 'section'], $headers);
                if ($missing) {
                    throw new \InvalidArgumentException(
                        'The file must have "' . implode('", "', $missing) . '" column header(s).'
                    );
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
            if (count($rows) >= self::MAX_IMPORT_ROWS) {
                throw new \InvalidArgumentException(
                    'The file contains more than ' . self::MAX_IMPORT_ROWS . ' rows — split the class list into smaller files.'
                );
            }
            $row['_line'] = $lineNo;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Provision a whole class list in one pass (admin bulk import).
     *
     * Each row is validated, given a fresh random temporary password, and
     * routed through provision(): brand-new rolls are created, legacy
     * password-less rows are claimed when the CSV identity matches, and
     * already-registered rolls are SKIPPED — their password is deliberately
     * left untouched (re-importing a stale list must never silently reset
     * someone's password; use setPassword / the Reset button instead).
     *
     * Temp passwords are returned ONLY in the result (never stored in
     * plaintext, never logged) so the page can build the one-time
     * credentials download.
     *
     * @param array<int, array{full_name: string, roll_number: string, stream: string, section: string, _line?: int}> $rows
     * @return array{created: int, claimed: int, skipped: int, errors: int, rows: array<int, array{line: int, roll: string, name: string, stream: string, section: string, status: string, password: ?string, error: ?string}>}
     */
    public static function importBatch(array $rows): array
    {
        $out = ['created' => 0, 'claimed' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];
        foreach ($rows as $row) {
            $line = (int) ($row['_line'] ?? 0);
            $name = trim((string) ($row['full_name'] ?? ''));
            $rollRaw = trim((string) ($row['roll_number'] ?? ''));
            $stream = trim((string) ($row['stream'] ?? ''));
            $section = strtoupper(trim((string) ($row['section'] ?? '')));

            // ---- validate (stream/section labels are normalized, not strict) ----
            $validationError = null;
            $streamNorm = self::normalizeStream($stream);
            $sectionNorm = self::normalizeSection($section);
            if ($name === '' || mb_strlen($name) > 100) {
                $validationError = 'Name must be 1-100 characters.';
            } elseif (!preg_match('/^\d{1,3}$/', $rollRaw) || (int) $rollRaw < 1 || (int) $rollRaw > 999) {
                $validationError = 'Roll number must be a whole number 1-999.';
            } elseif ($streamNorm === null) {
                $validationError = 'Stream must be "Natural Science" or "Social Science"'
                    . ($stream === '' ? '.' : ' (got "' . $stream . '").');
            } elseif ($sectionNorm === null) {
                $validationError = 'Section must be A, B or C' . ($section === '' ? '.' : ' (got "' . $section . '").');
            }
            if ($validationError !== null) {
                $out['errors']++;
                $out['rows'][] = [
                    'line' => $line, 'roll' => $rollRaw, 'name' => $name, 'stream' => $stream, 'section' => $section,
                    'status' => 'error', 'password' => null, 'error' => $validationError,
                ];
                continue;
            }
            $stream = $streamNorm;
            $section = $sectionNorm;

            $password = bin2hex(random_bytes(5));
            $result = self::provision($name, $rollRaw, $stream, $section, $password);
            if ($result['ok']) {
                $status = $result['mode'] === 'claimed' ? 'claimed' : 'created';
                $out[$status]++;
                $out['rows'][] = [
                    'line' => $line, 'roll' => $rollRaw, 'name' => $name, 'stream' => $stream, 'section' => $section,
                    'status' => $status, 'password' => $password, 'error' => null,
                ];
            } else {
                $out['skipped']++;
                $out['rows'][] = [
                    'line' => $line, 'roll' => $rollRaw, 'name' => $name, 'stream' => $stream, 'section' => $section,
                    'status' => 'skipped', 'password' => null, 'error' => (string) $result['error'],
                ];
            }
        }
        return $out;
    }

    // ===================================================================
    // Login rate limiting (brute-force protection, mirrored from admin)
    // ===================================================================

    /** Failure count for a roll number within the lock window. */
    public static function authFailureCount(string $rollNumber): int
    {
        $stmt = self::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u AND success = 0 AND attempted_at > (NOW() - INTERVAL " . (int) self::AUTH_LOCK_MINUTES . " MINUTE)"
        );
        $stmt->execute(['u' => self::authKey($rollNumber)]);
        return (int) $stmt->fetchColumn();
    }

    public static function authLocked(string $rollNumber): bool
    {
        return self::authFailureCount($rollNumber) >= self::MAX_AUTH_ATTEMPTS;
    }

    public static function authLockSeconds(string $rollNumber): int
    {
        $stmt = self::connection()->prepare(
            "SELECT MAX(attempted_at) FROM login_attempts
             WHERE username = :u AND success = 0 AND attempted_at > (NOW() - INTERVAL " . (int) self::AUTH_LOCK_MINUTES . " MINUTE)"
        );
        $stmt->execute(['u' => self::authKey($rollNumber)]);
        $last = $stmt->fetchColumn();
        if (!$last) {
            return 0;
        }
        $expires = strtotime($last) + self::AUTH_LOCK_MINUTES * 60;
        return max(0, $expires - time());
    }

    public static function recordAuthFailure(string $rollNumber): void
    {
        self::logAuthAttempt($rollNumber, false);
    }

    public static function recordAuthSuccess(string $rollNumber): void
    {
        // A successful login resets the failure ledger for this roll number
        // (mirrors the admin limiter, which zeroes failed_attempts).
        try {
            self::connection()->prepare('DELETE FROM login_attempts WHERE username = :u AND success = 0')
                ->execute(['u' => self::authKey($rollNumber)]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Failed to clear student auth failures: ' . $e->getMessage());
        }
        self::logAuthAttempt($rollNumber, true);
    }

    private static function authKey(string $rollNumber): string
    {
        return 'student:' . $rollNumber;
    }

    private static function logAuthAttempt(string $rollNumber, bool $success): void
    {
        try {
            self::connection()->prepare(
                'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (:u, :ip, :s, NOW())'
            )->execute([
                'u' => self::authKey($rollNumber),
                'ip' => \App\Core\Logger::clientIp(),
                's' => $success ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // A ledger write must never block the login attempt itself.
            \App\Core\Logger::error('Failed to record student auth attempt: ' . $e->getMessage());
        }
    }

    // ===================================================================
    // Backward compatibility — the old name/roll "login" helper
    // ===================================================================

    /**
     * Legacy upsert used by the pre-password login (name + roll + stream +
     * section, no password). Kept so nothing that still calls it breaks;
     * the gated portal no longer uses it. New rows created this way have
     * password_hash = NULL and must be claimed via provision().
     */
    public static function upsert(string $fullName, string $rollNumber, string $stream, string $section): int
    {
        $existing = self::findByRollAndStream($rollNumber, $stream);
        $pdo = self::connection();
        if ($existing) {
            $pdo->prepare('UPDATE students SET full_name = :n, section = :sec WHERE id = :id')
                ->execute(['n' => $fullName, 'sec' => $section, 'id' => $existing['id']]);
            return (int) $existing['id'];
        }
        $pdo->prepare(
            'INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES (:n, :r, :s, :sec, NOW())'
        )->execute(['n' => $fullName, 'r' => $rollNumber, 's' => $stream, 'sec' => $section]);
        return (int) $pdo->lastInsertId();
    }
}
