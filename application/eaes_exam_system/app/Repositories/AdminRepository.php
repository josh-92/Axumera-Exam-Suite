<?php

namespace App\Repositories;

use App\Core\Database;

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

    /**
     * Verify credentials. Transparently upgrades legacy plaintext passwords
     * (from the original schema) to a bcrypt hash on first successful login.
     */
    public static function verifyPassword(array $admin, string $password): bool
    {
        $stored = (string) $admin['password_hash'];

        $info = password_get_info($stored);
        if ($info['algo'] !== null) {
            return password_verify($password, $stored);
        }

        // Legacy plaintext row inherited from the original database dump.
        if (hash_equals($stored, $password)) {
            self::updatePassword((int) $admin['id'], $password);
            return true;
        }

        return false;
    }

    public static function updatePassword(int $id, string $newPlaintextPassword): void
    {
        $hash = password_hash($newPlaintextPassword, PASSWORD_DEFAULT);
        $stmt = Database::connection()->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id');
        $stmt->execute(['h' => $hash, 'id' => $id]);
    }

    public static function create(string $username, string $password, string $fullName = '', string $role = 'admin'): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO admin_users (username, password_hash, full_name, role, created_at) VALUES (:u, :p, :f, :r, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'f' => $fullName,
            'r' => $role,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }
}
