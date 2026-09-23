<?php
declare(strict_types=1);

/**
 * Brings $pdo's schema up to the latest version. Reads the current version
 * from schema_version, runs every migrations/NNN_*.php file numbered above
 * it in order inside a single transaction, then records the new version.
 * A fresh (empty) database simply has no migrations whose guards match
 * anything, so this ends at the latest version as a no-op.
 */
function runMigrations(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');

    $version = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
    if ($version === false) {
        $pdo->exec('INSERT INTO schema_version (version) VALUES (0)');
        $version = 0;
    }
    $version = (int) $version;

    $migrations = loadMigrations();
    ksort($migrations);

    $pending = array_filter($migrations, fn (int $v): bool => $v > $version, ARRAY_FILTER_USE_KEY);
    if (!$pending) {
        return;
    }

    $pdo->beginTransaction();
    try {
        foreach ($pending as $migrationVersion => $migration) {
            $migration($pdo);
            $version = $migrationVersion;
        }
        $pdo->exec('UPDATE schema_version SET version = ' . $version);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Loads migrations/NNN_description.php files keyed by their numeric prefix.
 * Each file must `return function (PDO $pdo): void { ... };`.
 */
function loadMigrations(): array
{
    $migrations = [];
    foreach (glob(__DIR__ . '/../migrations/*.php') as $file) {
        if (!preg_match('/^(\d+)_/', basename($file), $m)) {
            continue;
        }
        $migrations[(int) $m[1]] = require $file;
    }
    return $migrations;
}

/**
 * Flushes WAL into the main database file and copies it to a .bak sibling
 * so a failed or interrupted migration can always be recovered from.
 */
function backupDatabaseFile(PDO $pdo): void
{
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    copy(DB_PATH, DB_PATH . '.bak');
}
