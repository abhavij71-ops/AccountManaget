<?php
declare(strict_types=1);

/**
 * Adds TOTP-based two-factor authentication: two nullable columns on
 * accounts_users (both plain ALTER TABLE ADD COLUMN — no CHECK constraint,
 * so no rebuild needed, unlike the visibility columns migration) plus a
 * table for hashed, single-use recovery codes.
 */
return function (PDO $pdo): void {
    $columns = $pdo->query('PRAGMA table_info(accounts_users)')->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('totp_secret', $columns, true)) {
        $pdo->exec('ALTER TABLE accounts_users ADD COLUMN totp_secret TEXT');
    }
    if (!in_array('totp_enabled_at', $columns, true)) {
        $pdo->exec('ALTER TABLE accounts_users ADD COLUMN totp_enabled_at TEXT');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS totp_recovery_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        code_hash TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_totp_recovery_codes_user ON totp_recovery_codes(user_id)');
};
