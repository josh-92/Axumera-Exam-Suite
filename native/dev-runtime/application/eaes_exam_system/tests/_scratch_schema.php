<?php

/**
 * tests/_scratch_schema.php
 * -------------------------
 * Shared support helper for the DB-backed integration tests.
 *
 * scratchDatabase($name) creates (or recreates) a scratch MySQL database
 * and applies the REAL schema.sql plus the full migration chain — exactly
 * the pipeline installer/install.php runs on a fresh install (schema.sql,
 * then every migration except the three whose DDL schema.sql already
 * ships). Tests use this so they validate against the production schema
 * rather than a hand-rolled approximation, and so a drift in schema.sql
 * or a migration is caught here.
 *
 * Not a runnable test — include it:
 *
 *   require_once __DIR__ . '/_scratch_schema.php';
 *   $server = scratchDatabase('eaes_my_test');
 *
 * The caller is responsible for DROPping the database afterwards.
 */

declare(strict_types=1);

use App\Core\Database;

/** Migrations whose DDL is already part of schema.sql (see installer). */
function scratchCoveredBySchema(): array
{
    return [
        '2026_07_26_add_question_shuffling.sql',
        '2026_07_26_add_integrity_tracking.sql',
        '2026_08_09_add_student_archiving.sql',
    ];
}

/** Strip comment-only lines so a schema file can be exec()'d wholesale. */
function scratchStripComments(string $sql): string
{
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3); // UTF-8 BOM
    }
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

/**
 * Create a fresh scratch database and apply schema.sql + every migration
 * (in filename order) exactly like the installer does.
 *
 * @return PDO Connection with the scratch DB selected.
 */
function scratchDatabase(string $dbName): PDO
{
    $host = '127.0.0.1';
    $port = 3306;
    $dbUser = 'root';
    $dbPass = '';

    try {
        $server = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
    } catch (Throwable $e) {
        echo "Cannot connect to MySQL ({$host}:{$port}): {$e->getMessage()}\n";
        exit(1);
    }

    $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $server->exec("USE `{$dbName}`");

    $root = dirname(__DIR__);
    $schema = (string) file_get_contents($root . '/database/schema.sql');
    $server->exec(scratchStripComments($schema));

    // schema.sql begins with `SET time_zone = "+00:00"` — that is session-
    // scoped to the connection that applies it (the installer). The app's own
    // connections use the server default, and PHP runs on APP_TIMEZONE, so
    // restore the server default here or every NOW()-vs-time() comparison in
    // the test (deadline math, autosave) drifts by the timezone offset.
    $server->exec("SET time_zone = 'SYSTEM'");

    $covered = scratchCoveredBySchema();
    $files = glob($root . '/database/migrations/*.sql');
    sort($files);
    foreach ($files as $migrationFile) {
        if (in_array(basename($migrationFile), $covered, true)) {
            continue;
        }
        $server->exec((string) file_get_contents($migrationFile));
    }

    return $server;
}

/**
 * Wire the app's repositories (Database::connection()) to the scratch DB.
 */
function scratchUseConnection(PDO $server): void
{
    Database::useConnection($server);
}
