<?php
declare(strict_types=1);

/**
 * Creates the invitations table from docs/ROADMAP-SAAS.md Phase 11 —
 * pending workspace invitations, tokenized and time-limited, sent by a
 * workspace owner/admin to an email address that may or may not already
 * have a platform account.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        role TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        accepted_at TEXT,
        invited_by INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invitations_workspace ON invitations(workspace_id)');
};
