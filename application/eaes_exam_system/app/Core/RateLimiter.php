<?php

namespace App\Core;

/**
 * Brute-force protection for the admin login form.
 * Tracks attempts per username in the database; locks the account
 * out temporarily after too many failures.
 */
class RateLimiter
{
    public static function isLocked(string $username): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT locked_until FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $lockedUntil = $stmt->fetchColumn();

        if (!$lockedUntil) {
            return false;
        }
        return strtotime($lockedUntil) > time();
    }

    public static function secondsUntilUnlock(string $username): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT locked_until FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $lockedUntil = $stmt->fetchColumn();
        if (!$lockedUntil) {
            return 0;
        }
        return max(0, strtotime($lockedUntil) - time());
    }

    public static function recordFailure(string $username): void
    {
        $db = Database::connection();
        $maxAttempts = (int) config('security.admin_max_login_attempts', 5);
        $lockoutMinutes = (int) config('security.admin_lockout_minutes', 15);

        $stmt = $db->prepare('SELECT id, failed_attempts FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        self::logAttempt($username, false);

        if (!$row) {
            return; // Unknown username — nothing to lock, but the attempt is logged for review.
        }

        $attempts = (int) $row['failed_attempts'] + 1;

        if ($attempts >= $maxAttempts) {
            $lockUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
            $upd = $db->prepare('UPDATE admin_users SET failed_attempts = :a, locked_until = :l WHERE id = :id');
            $upd->execute(['a' => $attempts, 'l' => $lockUntil, 'id' => $row['id']]);
            Logger::warning("Admin account '$username' locked for {$lockoutMinutes}m after {$attempts} failed attempts.");
        } else {
            $upd = $db->prepare('UPDATE admin_users SET failed_attempts = :a WHERE id = :id');
            $upd->execute(['a' => $attempts, 'id' => $row['id']]);
        }
    }

    public static function recordSuccess(string $username): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE username = :u');
        $stmt->execute(['u' => $username]);
        self::logAttempt($username, true);
    }

    private static function logAttempt(string $username, bool $success): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (:u, :ip, :s, NOW())'
            );
            $stmt->execute(['u' => $username, 'ip' => Logger::clientIp(), 's' => $success ? 1 : 0]);
        } catch (\Throwable $e) {
            Logger::error('Failed to record login attempt: ' . $e->getMessage());
        }
    }
}
