<?php

declare(strict_types=1);

/**
 * database/migrate.php
 * --------------------
 * Apply pending migrations to an existing EAES database.
 *
 *   php database/migrate.php
 *
 * Design goals:
 *  1. Idempotent — applied files are tracked in `schema_migrations`, so each
 *     migration executes exactly once and the runner is safe to run
 *     repeatedly. Migrations whose DDL is already shipped by schema.sql are
 *     detected by the objects they create and recorded without re-running.
 *  2. Database readiness — a running mysqld process is NOT a ready database.
 *     The runner connects with retry/backoff and only proceeds once a real
 *     connection can execute a query (SELECT 1).
 *  3. Connection recovery — if the connection is lost mid-run (e.g. the
 *     classic "MySQL server has gone away", SQLSTATE 2006/2013), the runner
 *     reconnects, re-reads the ledger and checks the schema, and only then
 *     decides whether the interrupted migration actually completed. It never
 *     blindly re-runs a migration, and it never reports success without the
 *     ledger recording the migration.
 *  4. State honesty — every step prints what actually happened. A migration
 *     that cannot be applied aborts the run with a real error (exit 1).
 *
 * Fresh installs via installer/install.php apply the same chain after
 * loading schema.sql and record every migration, so a fresh install reports
 * "No pending migrations." here.
 */

$appRoot = dirname(__DIR__);

// CLI-safe bootstrap: the HTTP bootstrap intentionally redirects uninstalled
// / unlicensed requests and would terminate this runner before it executes.
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
// The obfuscated core calls b_k5t(); PHP function names are case-insensitive,
// so defining it here (matching database/migrate_legacy.php) is enough.
if (!function_exists('b_k5t')) {
    function b_k5t(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }
}

$db = config('db');

// ---------------------------------------------------------------------------
// Connection helpers
// ---------------------------------------------------------------------------

const DB_CONNECT_ATTEMPTS = 15;
const DB_CONNECT_DELAY_SECONDS = 2;
// MySQL/MariaDB driver error codes that mean "the server went away / was
// restarted". NOTE: the SQLSTATE 'HY000' is deliberately NOT listed — it is
// the generic SQLSTATE for ordinary SQL errors too, and treating it as a
// lost connection would mask real migration failures.
const DB_LOST_CONNECTION_CODES = ['2002', '2006', '2013', '1152', '1153'];

function dbDsn(array $db, bool $withDatabase): string
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int) $db['port'], $db['charset'] ?? 'utf8mb4');
    if ($withDatabase && !empty($db['name'])) {
        $dsn .= ';dbname=' . $db['name'];
    }
    return $dsn;
}

/**
 * Connect to the database, waiting (with retry) until it is genuinely ready
 * to answer queries. Throws RuntimeException when the server never becomes
 * ready — a clear, actionable error instead of a raw 2006 "gone away".
 */
function dbConnect(array $db, bool $withDatabase = true, int $attempts = DB_CONNECT_ATTEMPTS, int $delaySeconds = DB_CONNECT_DELAY_SECONDS): PDO
{
    $lastError = 'Database is not reachable.';
    for ($i = 1; $i <= $attempts; $i++) {
        try {
            $pdo = new PDO(dbDsn($db, $withDatabase), $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // A real query, not just a socket accept: prove the server can
            // execute SQL before we claim readiness.
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e->getMessage();
        }
        if ($i < $attempts) {
            sleep($delaySeconds);
        }
    }
    throw new RuntimeException(
        'Database readiness check failed after ' . $attempts . ' attempt(s): ' . $lastError
        . ' — make sure the MariaDB/MySQL service is running and reachable on '
        . $db['host'] . ':' . (int) $db['port'] . '.'
    );
}

/** Is the connection still able to execute a query? */
function dbAlive(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function isLostConnection(Throwable $e): bool
{
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'server has gone away') || str_contains($message, 'lost connection')) {
        return true;
    }
    if ($e instanceof PDOException) {
        // errorInfo[1] is the driver-specific MySQL/MariaDB error code
        // (e.g. 2006 for "server has gone away"); getCode() only returns
        // the generic SQLSTATE.
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());
        return in_array($code, DB_LOST_CONNECTION_CODES, true);
    }
    return false;
}

// ---------------------------------------------------------------------------
// Ledger helpers
// ---------------------------------------------------------------------------

function ensureLedger(PDO $pdo): void
{
    // AXE 1.0 shipped a `version`-based ledger; the current ledger is
    // `filename`-based. If an older table exists, upgrade it in place so
    // existing migration history is preserved (nothing is ever re-run).
    if (tableExists($pdo, 'schema_migrations')) {
        if (columnExists($pdo, 'schema_migrations', 'version') && !columnExists($pdo, 'schema_migrations', 'filename')) {
            $pdo->exec('ALTER TABLE schema_migrations CHANGE COLUMN version filename VARCHAR(255) NOT NULL');
            // Uniqueness is already enforced by the old unique key (now on
            // the renamed column); renaming it is cosmetic and best-effort.
            try {
                $pdo->exec('ALTER TABLE schema_migrations DROP KEY uniq_migration_version');
                $pdo->exec('ALTER TABLE schema_migrations ADD UNIQUE KEY uniq_migration (filename)');
            } catch (Throwable $e) {
                // Old key name already gone or the rename raced — the column
                // rename above is the part that matters.
            }
            echo "      upgraded schema_migrations ledger (version -> filename)\n";
        }
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_migration (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function readApplied(PDO $pdo): array
{
    $rows = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $rows);
}

function recordApplied(PDO $pdo, string $filename): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$filename]);
    // Verify the record actually landed (a lost write must not be reported
    // as applied).
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE filename = ?');
    $check->execute([$filename]);
    if ((int) $check->fetchColumn() !== 1) {
        throw new RuntimeException('Failed to record migration in schema_migrations: ' . $filename);
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

echo "Connecting to database '{$db['name']}' on {$db['host']}:{$db['port']}...\n";
$pdo = dbConnect($db);
echo "Database ready.\n";

ensureLedger($pdo);
$applied = readApplied($pdo);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

// Migrations whose DDL is already part of schema.sql (fresh installs get it
// from there; existing installs that predate the feature may still need the
// migration, which we detect by the object it creates). The same predicates
// are used after a lost connection to determine whether an interrupted
// migration had actually completed.
$coveredBySchema = [
    '2026_07_26_add_question_shuffling.sql' => static fn (PDO $pdo): bool => tableExists($pdo, 'exam_question_shuffles'),
    '2026_07_26_add_integrity_tracking.sql' => static fn (PDO $pdo): bool => columnExists($pdo, 'exam_attempts', 'violation_count'),
    '2026_08_05_decouple_question_bank.sql' => static fn (PDO $pdo): bool => columnExists($pdo, 'questions', 'status'),
    '2026_08_06_add_question_bank_crud.sql' => static fn (PDO $pdo): bool => tableExists($pdo, 'exam_question_assignments'),
    '2026_08_07_add_student_passwords.sql'  => static fn (PDO $pdo): bool => columnExists($pdo, 'students', 'password_hash'),
    '2026_08_09_add_student_archiving.sql'  => static fn (PDO $pdo): bool => columnExists($pdo, 'students', 'deleted_at'),
];

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        continue;
    }

    // Already shipped by schema.sql on this install? Record and move on.
    if (isset($coveredBySchema[$name]) && $coveredBySchema[$name]($pdo)) {
        echo "skip  {$name} (already present in schema)\n";
        recordApplied($pdo, $name);
        $applied[] = $name;
        continue;
    }

    echo "apply {$name}\n";
    $sql = (string) file_get_contents($file);

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (isLostConnection($e)) {
            echo "      connection lost mid-migration ({$e->getMessage()}); reconnecting...\n";
            $pdo = dbConnect($db); // reconnect with readiness retry
            ensureLedger($pdo);
            $applied = readApplied($pdo);

            if (in_array($name, $applied, true)) {
                // The migration finished before the connection dropped (its
                // ledger insert landed). Never run it twice.
                echo "      {$name} already recorded after reconnect — skipping.\n";
                continue;
            }
            if (isset($coveredBySchema[$name]) && $coveredBySchema[$name]($pdo)) {
                // The DDL completed but the ledger write was lost. Record the
                // observed state instead of re-running idempotent DDL blindly.
                echo "      {$name} schema changes already present after reconnect — recording.\n";
                recordApplied($pdo, $name);
                $applied[] = $name;
                continue;
            }
            // Nothing recorded and no schema evidence: the migration did not
            // complete. The migration SQL is written idempotently (ADD COLUMN
            // IF NOT EXISTS, DROP INDEX IF EXISTS...), so one safe re-attempt
            // is acceptable; a second failure is a real error.
            echo "      retrying {$name} once after reconnect...\n";
            try {
                $pdo->exec($sql);
            } catch (Throwable $retry) {
                fwrite(STDERR, 'Migration failed [' . $name . ']: ' . $retry->getMessage() . "\n");
                exit(1);
            }
        } else {
            fwrite(STDERR, 'Migration failed [' . $name . ']: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    recordApplied($pdo, $name);
    $applied[] = $name;
    $ran++;
    echo "      ok\n";
}

echo $ran === 0 ? "No pending migrations.\n" : "Done — {$ran} migration(s) applied.\n";
exit(0);
