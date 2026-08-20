<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Admin account storage + password handling.
 *
 * Password formats: bcrypt hashes (password_hash). For backward
 * compatibility verifyPassword() also accepts legacy plaintext rows and
 * transparently upgrades them to bcrypt on a successful login.
 */
class AdminRepository
{
    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Verify a plaintext password. Legacy plaintext rows are upgraded to bcrypt on success. */
    public static function verifyPassword(array $admin, string $plain): bool
    {
        $hash = (string) $admin['password_hash'];
        $info = password_get_info($hash);
        if ($info['algo'] !== null) {
            return password_verify($plain, $hash);
        }
        // Legacy plaintext row — upgrade on successful match.
        if (hash_equals($hash, $plain)) {
            self::updatePassword((int) $admin['id'], $plain);
            return true;
        }
        return false;
    }

    /** Set a new password hash (no lockout change). */
    public static function updatePassword(int $id, string $plain): void
    {
        $stmt = Database::connection()->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id');
        $stmt->execute(['h' => password_hash($plain, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /**
     * Full recovery reset: set a new password AND clear the login lockout
     * (failed_attempts / locked_until) — the tool for a forgotten admin
     * password or a locked account.
     */
    public static function resetPassword(int $id, string $plain): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE admin_users
             SET password_hash = :h, failed_attempts = 0, locked_until = NULL
             WHERE id = :id'
        );
        $stmt->execute(['h' => password_hash($plain, PASSWORD_DEFAULT), 'id' => $id]);
    }

    public static function create(string $username, string $plain, string $fullName = '', string $role = 'admin'): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO admin_users (username, password_hash, full_name, role, created_at)
             VALUES (:u, :p, :f, :r, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($plain, PASSWORD_DEFAULT),
            'f' => $fullName,
            'r' => $role,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    /** Every admin account, newest last — for the recovery tool's listing. */
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT id, username, full_name, role, failed_attempts, locked_until, last_login_at, created_at FROM admin_users ORDER BY id ASC')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Case-insensitive uniqueness check (the username column's collation is case-insensitive too). */
    public static function usernameExists(string $username): bool
    {
        return self::findByUsername($username) !== null;
    }

    /** Permanently delete an admin account. Callers guard against self-delete and last-admin. */
    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
