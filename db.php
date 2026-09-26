<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/migrator.php';
require_once __DIR__ . '/includes/workspaces.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (empty($_SESSION['workspace_id'])) {
        header('Location: ' . APP_BASE_URL . '/select-workspace.php');
        exit;
    }
    $workspaceId = (int) $_SESSION['workspace_id'];

    // Refuses a suspended workspace before its own SQLite file is even
    // touched — billing.php is the sole exception, and only because it
    // never calls db() in the first place (see renderWorkspaceSuspendedPage()'s
    // own docblock, includes/workspaces.php).
    if (isWorkspaceSuspended($workspaceId)) {
        renderWorkspaceSuspendedPage($workspaceId);
    }

    $workspacePath = workspaceDatabasePath($workspaceId);
    $workspaceDir = dirname($workspacePath);

    if (!is_dir($workspaceDir)) {
        mkdir($workspaceDir, 0755, true);
    }

    $duringMigration = false;
    try {
        $pdo = new PDO('sqlite:' . $workspacePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $duringMigration = true;
        runMigrations($pdo);
        backfillOwnerUserId($pdo, $workspaceId);
    } catch (Throwable $e) {
        // Throwable, not PDOException: a migration can throw a non-PDO error too
        // (runMigrations() deliberately rethrows via `catch (Throwable $e) { ... throw
        // $e; }` after rolling back), and that must not slip past this catch and become
        // an uncaught fatal error on every single page of the app.
        error_log('Account Manager: ' . ($duringMigration ? 'schema migration failed: ' : 'database connection failed: ') . $e->getMessage());
        http_response_code(500);
        if ($duringMigration) {
            // Never the real exception or DB_PATH here — migrations run their risky steps
            // (rename/rebuild) inside a transaction with rollback, and backupDatabaseFile()
            // runs before any of those, so this is a safe, true thing to tell the user
            // regardless of what actually failed.
            die('A database migration failed. The database was left unchanged and a backup exists. Please check the server error log and contact the administrator.');
        }
        die(APP_DEBUG ? t('db.connection_error', ['error' => $e->getMessage()]) : t('db.connection_error_generic'));
    }

    return $pdo;
}
