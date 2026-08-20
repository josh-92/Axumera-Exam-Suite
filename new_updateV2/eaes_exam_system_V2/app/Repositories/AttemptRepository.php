<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:04              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Repositories; use App\Core\Database; class AttemptRepository { public static function find(int $ii9hU, int $ugLXG): ?array { $dzhGf = Database::connection()->prepare('SELECT * FROM exam_attempts WHERE student_id = :s AND exam_id = :e LIMIT 1'); $dzhGf->execute(['s' => $ii9hU, 'e' => $ugLXG]); $RmthD = $dzhGf->fetch(); return $RmthD ?: null; } public static function findOrStart(int $ii9hU, int $ugLXG): array { $lNNqu = self::find($ii9hU, $ugLXG); if (!$lNNqu) { goto J6EMd; } return $lNNqu; J6EMd: $fVwZF = Database::connection(); $dzhGf = $fVwZF->prepare("INSERT INTO exam_attempts (student_id, exam_id, answers, flags, status, started_at, last_saved_at, ip_address, user_agent)\n             VALUES (:s, :e, '{}', '{}', 'in_progress', NOW(), NOW(), :ip, :ua)"); $dzhGf->execute(['s' => $ii9hU, 'e' => $ugLXG, 'ip' => \App\Core\Logger::clientIp(), 'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]); return self::find($ii9hU, $ugLXG); } public static function autosave(int $ii9hU, int $ugLXG, array $cNYW6, array $R1q7u): bool { $hkJom = self::find($ii9hU, $ugLXG); if (!(!$hkJom || $hkJom['status'] !== 'in_progress')) { goto at1Lv; } return false; at1Lv: $dzhGf = Database::connection()->prepare('UPDATE exam_attempts SET answers = :a, flags = :f, last_saved_at = NOW() WHERE id = :id'); return $dzhGf->execute(['a' => json_encode($cNYW6, JSON_UNESCAPED_UNICODE), 'f' => json_encode($R1q7u, JSON_UNESCAPED_UNICODE), 'id' => $hkJom['id']]); }

    /**
     * Deadline-enforced autosave: the single write path for exam answers.
     *
     * While the attempt is within (duration + grace) the payload is saved
     * exactly like autosave(). Once the attempt has run past the deadline
     * the save is REFUSED and the attempt is finalized server-side with
     * whatever answers were already stored — so a scripted client cannot
     * keep answering after time runs out (the old autosave() had no expiry
     * check and granted unlimited extra time).
     *
     * @return array{saved: bool, expired: bool, seconds_remaining: int, score: ?int, total: ?int}
     */
    public static function autosaveIfWithinDeadline(int $ii9hU, int $ugLXG, array $cNYW6, array $R1q7u, int $durationSeconds, int $graceSeconds): array
    {
        // Defense in depth: the HTTP layer already whitelists answer keys and
        // values; enforce the same here so a polluted payload can never reach
        // storage no matter which caller invokes this method.
        $clean = [];
        foreach ($cNYW6 as $q => $a) {
            $q = (int) $q;
            $a = is_string($a) ? strtolower(trim($a)) : '';
            if ($q > 0 && in_array($a, ['a', 'b', 'c', 'd'], true)) {
                $clean[$q] = $a;
            }
        }
        $cNYW6 = $clean;
        $cleanFlags = [];
        foreach ($R1q7u as $q => $v) {
            $q = (int) $q;
            if ($q > 0 && $v) {
                $cleanFlags[$q] = true;
            }
        }
        $R1q7u = $cleanFlags;
        $hkJom = self::find($ii9hU, $ugLXG);
        if (!$hkJom || $hkJom['status'] !== 'in_progress') {
            return ['saved' => false, 'expired' => false, 'seconds_remaining' => 0, 'score' => null, 'total' => null];
        }
        $elapsed = time() - strtotime((string) $hkJom['started_at']);
        $remaining = $durationSeconds - $elapsed;
        if ($remaining > -$graceSeconds) {
            $ok = self::autosave($ii9hU, $ugLXG, $cNYW6, $R1q7u);
            return ['saved' => $ok, 'expired' => false, 'seconds_remaining' => max(0, $remaining), 'score' => null, 'total' => null];
        }
        $saved = json_decode((string) $hkJom['answers'], true) ?: [];
        $graded = \App\Services\GradingService::grade($ugLXG, $saved);
        self::markSubmitted((int) $hkJom['id'], $graded['score'], $graded['total'], 'auto_submitted');
        return ['saved' => false, 'expired' => true, 'seconds_remaining' => 0, 'score' => $graded['score'], 'total' => $graded['total']];
    }

    public static function recordViolation(int $ii9hU, int $ugLXG): array { $hkJom = self::find($ii9hU, $ugLXG); if (!(!$hkJom || $hkJom['status'] !== 'in_progress')) { goto rYnzA; } return ['violation_count' => (int) ($hkJom['violation_count'] ?? 0), 'flagged' => false]; rYnzA: $qKakQ = (int) b_K5t('integrity.flag_threshold', 3); $nNoSK = (int) $hkJom['violation_count'] + 1; $Y7J0E = $nNoSK >= $qKakQ; $dzhGf = Database::connection()->prepare('UPDATE exam_attempts
             SET violation_count = :count,
                 integrity_status = :status
             WHERE id = :id'); $dzhGf->execute(['count' => $nNoSK, 'status' => $Y7J0E ? 'flagged' : 'clean', 'id' => $hkJom['id']]); return ['violation_count' => $nNoSK, 'flagged' => $Y7J0E]; } public static function secondsRemaining(array $hkJom, int $xpJYq): int { $teZP6 = time() - strtotime($hkJom['started_at']); return max(0, $xpJYq - $teZP6); }    public static function markSubmitted(int $R_oSw, int $LTqpG, int $x8XQb, string $BhSDG = 'submitted'): bool
    {
        $dzhGf = Database::connection()->prepare("UPDATE exam_attempts SET score = :score, total_questions = :total, status = :status, submitted_at = NOW() WHERE id = :id AND status = 'in_progress'");
        $dzhGf->execute(['score' => $LTqpG, 'total' => $x8XQb, 'status' => $BhSDG, 'id' => $R_oSw]);
        return $dzhGf->rowCount() > 0;
    } public static function flaggedCountForExam(int $ugLXG): int { $dzhGf = Database::connection()->prepare("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = :id AND integrity_status = 'flagged'"); $dzhGf->execute(['id' => $ugLXG]); return (int) $dzhGf->fetchColumn(); } public static function forExam(int $ugLXG): array { $dzhGf = Database::connection()->prepare('SELECT ea.*, s.full_name, s.roll_number, s.stream, s.section
             FROM exam_attempts ea
             JOIN students s ON s.id = ea.student_id
             WHERE ea.exam_id = :id
             ORDER BY s.roll_number ASC, s.full_name ASC'); $dzhGf->execute(['id' => $ugLXG]); return $dzhGf->fetchAll(); } }
