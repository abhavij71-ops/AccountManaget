<?php
declare(strict_types=1);

/**
 * Adds two platform-level tables, both distinct from anything workspace-
 * scoped:
 *
 * - sessions: one row per active login (not PHP's own session storage) —
 *   powers the "active devices" list and "sign out everywhere" in
 *   settings.php. Keyed by a hash of a random per-login device token, never
 *   the plain token itself.
 * - audit_log: access records (who viewed or exported which record, and
 *   when) — distinct from the workspace `history` table, which records data
 *   *changes*, not access. Lives centrally because it spans every workspace
 *   a user touches, not just one.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL UNIQUE,
        ip TEXT,
        user_agent TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        last_seen_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        workspace_id INTEGER,
        action TEXT NOT NULL,
        entity_type TEXT,
        entity_id INTEGER,
        ip TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at)');
};
