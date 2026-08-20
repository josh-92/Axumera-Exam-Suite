<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Central database lifeline — PDO singleton with prepared-statement helpers.
 * Replaces the old raw mysqli + string-concatenated queries.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = config('db');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        try {
            self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Logger::error('Database connection failed: ' . $e->getMessage());
            self::fail();
        }

        return self::$instance;
    }

    /** Connect using explicit credentials (used by the installer, before .env exists). */
    public static function connectWith(string $host, int $port, string $name, string $user, string $pass, string $charset = 'utf8mb4'): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private static function fail(): never
    {
        http_response_code(503);
        $debug = config('app.debug');
        echo '<h1>Service Unavailable</h1><p>The exam system could not reach its database.</p>';
        if ($debug) {
            echo '<p>Check your <code>.env</code> database credentials.</p>';
        }
        exit;
    }
}
