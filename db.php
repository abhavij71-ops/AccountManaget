<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        applySchemaUpgrades($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        die(t('db.connection_error', ['error' => $e->getMessage()]));
    }

    return $pdo;
}

function applySchemaUpgrades(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='emails'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(emails)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_favorite', $columns, true)) {
        $pdo->exec('ALTER TABLE emails ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_emails_favorite ON emails(is_favorite)');
    }
}
