<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

define('PLATFORM_DB_PATH', DATA_DIR . '/platform.sqlite');

/**
 * Opens the central platform database (docs/ROADMAP-SAAS.md Phase 10):
 * accounts_users, workspaces and memberships. Separate connection and
 * separate migration trail from the per-workspace db() in db.php — the two
 * never share a schema_version table or a migrations directory.
 */
function platformDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    $duringMigration = false;
    try {
        $pdo = new PDO('sqlite:' . PLATFORM_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $duringMigration = true;
        runPlatformMigrations($pdo);
    } catch (Throwable $e) {
        error_log('Account Manager: ' . ($duringMigration ? 'platform schema migration failed: ' : 'platform database connection failed: ') . $e->getMessage());
        http_response_code(500);
        if ($duringMigration) {
            die('A platform database migration failed. The database was left unchanged. Please check the server error log and contact the administrator.');
        }
        die(APP_DEBUG ? $e->getMessage() : 'Platform database connection failed.');
    }

    return $pdo;
}

/**
 * Same read-version / run-pending / bump-version contract as
 * includes/migrator.php's runMigrations(), but scoped to platform.sqlite
 * and migrations/platform/ so workspace and platform migrations never mix.
 */
function runPlatformMigrations(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');

    $version = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
    if ($version === false) {
        $pdo->exec('INSERT INTO schema_version (version) VALUES (0)');
        $version = 0;
    }
    $version = (int) $version;

    $migrations = loadPlatformMigrations();
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
 * Loads migrations/platform/NNN_description.php files keyed by their
 * numeric prefix. Each file must `return function (PDO $pdo): void { ... };`.
 */
function loadPlatformMigrations(): array
{
    $migrations = [];
    foreach (glob(__DIR__ . '/../migrations/platform/*.php') as $file) {
        if (!preg_match('/^(\d+)_/', basename($file), $m)) {
            continue;
        }
        $migrations[(int) $m[1]] = require $file;
    }
    return $migrations;
}
