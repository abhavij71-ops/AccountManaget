<?php
declare(strict_types=1);

/**
 * Self-service signup (register.php) and password recovery
 * (forgot-password.php / reset-password.php) — both single-use, hashed-
 * token flows in the central database, hashing the token at rest the same
 * way sessions.token_hash already does (includes/auth.php's
 * trackActiveSession()), since these tokens travel over email and could
 * end up logged by a relay along the way.
 *
 * email_verifications.workspace_name is stashed here rather than written
 * onto accounts_users or workspaces directly, because neither the
 * workspace nor its owner membership exists yet at registration time —
 * both are only created once the token is verified (verify-email.php).
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL UNIQUE,
        workspace_name TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        verified_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_verifications_user ON email_verifications(user_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id)');
};
