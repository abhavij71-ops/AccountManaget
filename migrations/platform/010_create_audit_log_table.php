<?php
declare(strict_types=1);

/**
 * A durable trail for sensitive operations — workspace data export,
 * workspace deletion, account deletion (see includes/audit.php's
 * logAuditEvent()) — that must survive the deletion of the very workspace
 * or account it describes. Deliberately no foreign keys on
 * workspace_id/user_id: an audit row is meant to outlive the row it refers
 * to, not be cascade-deleted along with it.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER,
        user_id INTEGER,
        action TEXT NOT NULL,
        details TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_workspace ON audit_log(workspace_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(user_id)');
};
