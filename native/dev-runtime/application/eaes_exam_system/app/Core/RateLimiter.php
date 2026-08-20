<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:04              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Core; class RateLimiter { public static function isLocked(string $EuShz): bool { $fVwZF = Database::connection(); $dzhGf = $fVwZF->prepare('SELECT locked_until FROM admin_users WHERE username = :u LIMIT 1'); $dzhGf->execute(['u' => $EuShz]); $rpuvK = $dzhGf->fetchColumn(); if ($rpuvK) { goto mt3sJ; } return false; mt3sJ: return strtotime($rpuvK) > time(); } public static function secondsUntilUnlock(string $EuShz): int { $fVwZF = Database::connection(); $dzhGf = $fVwZF->prepare('SELECT locked_until FROM admin_users WHERE username = :u LIMIT 1'); $dzhGf->execute(['u' => $EuShz]); $rpuvK = $dzhGf->fetchColumn(); if ($rpuvK) { goto eIjpB; } return 0; eIjpB: return max(0, strtotime($rpuvK) - time()); } public static function recordFailure(string $EuShz): void { $fVwZF = Database::connection(); $dnepe = (int) b_K5t('security.admin_max_login_attempts', 5); $XNycv = (int) b_K5T('security.admin_lockout_minutes', 15); $dzhGf = $fVwZF->prepare('SELECT id, failed_attempts FROM admin_users WHERE username = :u LIMIT 1'); $dzhGf->execute(['u' => $EuShz]); $RmthD = $dzhGf->fetch(); self::logAttempt($EuShz, false); if ($RmthD) { goto QwnqF; } return; QwnqF: $fJAda = (int) $RmthD['failed_attempts'] + 1; if ($fJAda >= $dnepe) { goto ZvuBX; } $SQl9g = $fVwZF->prepare('UPDATE admin_users SET failed_attempts = :a WHERE id = :id'); $SQl9g->execute(['a' => $fJAda, 'id' => $RmthD['id']]); goto TEiDn; ZvuBX: $DJ5SS = date('Y-m-d H:i:s', time() + $XNycv * 60); $SQl9g = $fVwZF->prepare('UPDATE admin_users SET failed_attempts = :a, locked_until = :l WHERE id = :id'); $SQl9g->execute(['a' => $fJAda, 'l' => $DJ5SS, 'id' => $RmthD['id']]); Logger::warning("Admin account '{$EuShz}' locked for {$XNycv}m after {$fJAda} failed attempts."); TEiDn: } public static function recordSuccess(string $EuShz): void { $fVwZF = Database::connection(); $dzhGf = $fVwZF->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE username = :u'); $dzhGf->execute(['u' => $EuShz]); self::logAttempt($EuShz, true); } private static function logAttempt(string $EuShz, bool $mptIY): void { try { $fVwZF = Database::connection(); $dzhGf = $fVwZF->prepare('INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (:u, :ip, :s, NOW())'); $dzhGf->execute(['u' => $EuShz, 'ip' => Logger::clientIp(), 's' => $mptIY ? 1 : 0]); } catch (\Throwable $NacY1) { Logger::error('Failed to record login attempt: ' . $NacY1->getMessage()); } }

    // =====================================================================
    // Per-IP + generic per-account limiting over the login_attempts ledger
    // (added in the release-candidate hardening pass). Every failed login /
    // password-reset verification writes a failed row there with the client
    // IP, so counting those rows gives us both throttles for free.
    // =====================================================================

    /** Failed attempts from one IP within the lock window. */
    public static function ipFailureCount(string $ip): int
    {
        $window = (int) b_k5t('security.ip_lockout_minutes', 15);
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND success = 0
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute(['ip' => $ip]);
        return (int) $stmt->fetchColumn();
    }

    public static function ipLocked(string $ip): bool
    {
        return self::ipFailureCount($ip) >= (int) b_k5t('security.ip_max_login_attempts', 20);
    }

    public static function ipLockSeconds(string $ip): int
    {
        $window = (int) b_k5t('security.ip_lockout_minutes', 15);
        $stmt = Database::connection()->prepare(
            "SELECT MAX(attempted_at) FROM login_attempts
             WHERE ip_address = :ip AND success = 0
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute(['ip' => $ip]);
        $last = $stmt->fetchColumn();
        if (!$last) {
            return 0;
        }
        $expires = strtotime($last) + $window * 60;
        return max(0, $expires - time());
    }

    /** Successful login resets the IP failure ledger. */
    public static function clearIpFailures(string $ip): void
    {
        try {
            Database::connection()->prepare('DELETE FROM login_attempts WHERE ip_address = :ip AND success = 0')
                ->execute(['ip' => $ip]);
        } catch (\Throwable $e) {
            Logger::error('Failed to clear IP auth failures: ' . $e->getMessage());
        }
    }

    /** Failed attempts for an arbitrary account key within the window. */
    public static function accountFailureCount(string $key): int
    {
        $window = (int) b_k5t('security.admin_lockout_minutes', 15);
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u AND success = 0
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute(['u' => $key]);
        return (int) $stmt->fetchColumn();
    }

    public static function accountLocked(string $key): bool
    {
        return self::accountFailureCount($key) >= (int) b_k5t('security.admin_max_login_attempts', 5);
    }

    public static function accountLockSeconds(string $key): int
    {
        $window = (int) b_k5t('security.admin_lockout_minutes', 15);
        $stmt = Database::connection()->prepare(
            "SELECT MAX(attempted_at) FROM login_attempts
             WHERE username = :u AND success = 0
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute(['u' => $key]);
        $last = $stmt->fetchColumn();
        if (!$last) {
            return 0;
        }
        $expires = strtotime($last) + $window * 60;
        return max(0, $expires - time());
    }

    public static function recordAccountFailure(string $key): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (:u, :ip, 0, NOW())'
            )->execute(['u' => $key, 'ip' => Logger::clientIp()]);
        } catch (\Throwable $e) {
            Logger::error('Failed to record auth failure: ' . $e->getMessage());
        }
    }

    public static function clearAccountFailures(string $key): void
    {
        try {
            Database::connection()->prepare('DELETE FROM login_attempts WHERE username = :u AND success = 0')
                ->execute(['u' => $key]);
        } catch (\Throwable $e) {
            Logger::error('Failed to clear auth failures: ' . $e->getMessage());
        }
    } }
