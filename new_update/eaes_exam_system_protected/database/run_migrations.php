<?php

declare(strict_types=1);

/**
 * Migration runner for AXUMERA database updates.
 * Safe, idempotent execution of versioned DDL migrations.
 */

// Migration execution is a local CLI maintenance operation. Do not include
// the HTTP bootstrap: its installer/license redirects intentionally terminate
// normal web requests before this runner can execute.
$appRoot = dirname(__DIR__);
require_once $appRoot . '/app/Autoload.php';
$GLOBALS['__eaes_config'] = require $appRoot . '/app/config.php';
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        $value = $GLOBALS['__eaes_config'];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

use App\Core\Database;

try {
    $db = config('db');
    $pdo = Database::connectWith(
        (string) $db['host'],
        (int) $db['port'],
        (string) $db['name'],
        (string) $db['user'],
        (string) $db['pass'],
        (string) $db['charset']
    );

    // Ensure schema_migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `version` VARCHAR(100) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_migration_version` (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $stmt = $pdo->query("SELECT `version` FROM `schema_migrations`");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $appliedSet = array_flip($applied);

    $migrationsDir = __DIR__ . '/migrations';
    if (!is_dir($migrationsDir)) {
        echo "No migrations directory found.\n";
        exit(0);
    }

    $files = glob($migrationsDir . '/*.sql');
    if ($files === false) {
        $files = [];
    }
    sort($files);

    $appliedCount = 0;
    foreach ($files as $file) {
        $filename = basename($file, '.sql');

        // Skip historical legacy incompatible migration branch if present
        if (str_starts_with($filename, '2026_07_26') ||
            str_starts_with($filename, '2026_07_29') ||
            str_starts_with($filename, '2026_07_30') ||
            str_starts_with($filename, '2026_07_31') ||
            str_starts_with($filename, '2026_08_01') ||
            str_starts_with($filename, '2026_08_02') ||
            str_starts_with($filename, '2026_08_03') ||
            str_starts_with($filename, '2026_08_04_add_enterprise')) {
            // These scripts target the legacy admins/users table schema and duplicate schema.sql content.
            continue;
        }

        if (isset($appliedSet[$filename])) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            continue;
        }
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        echo "Applying migration: {$filename}...\n";

        try {
            $pdo->exec($sql);
            // Most migrations leave ledger ownership to this runner. A small
            // number of existing baseline migrations register themselves;
            // do not turn that valid idempotent registration into a duplicate
            // key failure.
            $recorded = $pdo->prepare("SELECT 1 FROM `schema_migrations` WHERE `version` = :version LIMIT 1");
            $recorded->execute(['version' => $filename]);
            if (!$recorded->fetchColumn()) {
                $ins = $pdo->prepare("INSERT INTO `schema_migrations` (`version`) VALUES (:version)");
                $ins->execute(['version' => $filename]);
            }
            $appliedCount++;
            echo "Successfully applied: {$filename}\n";
        } catch (Throwable $ex) {
            fwrite(STDERR, "Migration failed [{$filename}]: " . $ex->getMessage() . "\n");
            exit(1);
        }
    }

    echo "Migration execution completed. Applied {$appliedCount} new migration(s).\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration runner error: " . $e->getMessage() . "\n");
    exit(1);
}
