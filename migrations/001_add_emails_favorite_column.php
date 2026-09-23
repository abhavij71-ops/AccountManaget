<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='emails'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(emails)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('is_favorite', $columns, true)) {
        return;
    }

    $pdo->exec('ALTER TABLE emails ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_emails_favorite ON emails(is_favorite)');
};
