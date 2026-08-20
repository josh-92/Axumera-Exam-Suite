<?php

namespace App\Core;

class Logger
{
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARNING', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    /** Structured audit trail — also persisted to the activity_log table when DB is available. */
    public static function audit(string $actorType, ?string $actorIdentifier, string $action, array $details = []): void
    {
        self::write('AUDIT', "$actorType:$actorIdentifier $action " . json_encode($details));

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO activity_log (actor_type, actor_identifier, action, details, ip_address, created_at)
                 VALUES (:actor_type, :actor_identifier, :action, :details, :ip, NOW())'
            );
            $stmt->execute([
                'actor_type'       => $actorType,
                'actor_identifier' => $actorIdentifier,
                'action'           => $action,
                'details'          => json_encode($details),
                'ip'               => self::clientIp(),
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging break the request; fall back to file log only.
            self::write('ERROR', 'Audit DB write failed: ' . $e->getMessage());
        }
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', $_SERVER[$key]);
                return trim($parts[0]);
            }
        }
        return '0.0.0.0';
    }

    private static function write(string $level, string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . date('Y-m-d') . '.log';
        $line = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
