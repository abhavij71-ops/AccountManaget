<?php
declare(strict_types=1);

/**
 * Brings $pdo's schema up to the latest version. Reads the current version
 * from schema_version, runs every migrations/NNN_*.php file numbered above
 * it in order inside a single transaction, then records the new version.
 * A fresh (empty) database simply has no migrations whose guards match
 * anything, so this ends at the latest version as a no-op (the early
 * `return` below matters for more than performance: it's also what keeps
 * this function from touching the filesystem or any pragma at all on the
 * overwhelmingly common case of "already up to date").
 *
 * PRAGMA foreign_keys and PRAGMA legacy_alter_table are both silent no-ops
 * once a transaction is open — SQLite does not apply either mid-transaction
 * — so neither is ever set from inside an individual migration file
 * anymore; both are toggled here, once, wrapping the whole batch. The
 * pre-migration backup is taken here too, before beginTransaction(), for
 * the same reason: PRAGMA wal_checkpoint(TRUNCATE) is rejected with
 * "database table is locked" once a transaction has already opened (it
 * needs an exclusive lock a read inside an open transaction already
 * blocks). Whatever migration(s) actually ran are verified with
 * PRAGMA foreign_key_check before commit — a rebuild that quietly left a
 * table referencing a table that no longer exists (exactly what happened
 * without this) fails loudly here instead of shipping.
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

    $dbPath = resolveDatabaseFilePath($pdo);
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    copy($dbPath, $dbPath . '.' . date('Ymd_His') . '.pre-migration.bak');

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('PRAGMA legacy_alter_table = ON');
    try {
        $pdo->beginTransaction();
        try {
            foreach ($pending as $migrationVersion => $migration) {
                $migration($pdo);
                $version = $migrationVersion;
            }

            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
            if ($violations) {
                throw new RuntimeException(
                    'Migration left dangling foreign key references: ' . json_encode($violations)
                );
            }

            $pdo->exec('UPDATE schema_version SET version = ' . $version);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } finally {
        // Runs after either the commit or the rollback above — the
        // migration batch never leaves these two set to anything but the
        // connection's normal, enforced defaults.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
    }
}

/**
 * Loads migrations/NNN_description.php files keyed by their numeric prefix.
 * Each file must `return function (PDO $pdo): void { ... };`. Cached in a
 * static so `require` only ever executes each file once per process —
 * without this, migrating a second workspace database in the same process
 * (cron iterating workspaces, the admin panel, a fresh signup) re-`require`s
 * every migration file again, and any one of them that declares a named
 * top-level function (not just a closure) fatals with "Cannot redeclare".
 */
function loadMigrations(): array
{
    static $migrations = null;
    if ($migrations !== null) {
        return $migrations;
    }

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
 * The on-disk path of $pdo's own main database, read from SQLite itself
 * rather than assumed to be the single-tenant DB_PATH constant — the bug
 * this replaces (backupDatabaseFile()) copied DB_PATH unconditionally,
 * which is simply the wrong file for any connection opened against a
 * workspace or platform database instead of the legacy single database.
 */
function resolveDatabaseFilePath(PDO $pdo): string
{
    foreach ($pdo->query('PRAGMA database_list')->fetchAll() as $row) {
        if ($row['name'] === 'main') {
            return (string) $row['file'];
        }
    }
    throw new RuntimeException('Could not resolve the main database file path via PRAGMA database_list.');
}
