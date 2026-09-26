<?php
declare(strict_types=1);

/**
 * invitations.token was stored in plaintext (unlike email_verifications/
 * password_resets, which have always stored only a sha256 hash — see
 * migrations/platform/007_create_registration_tables.php) — a plaintext
 * invite link readable straight from the database. members.php/
 * accept-invite.php now store/look up token_hash instead, following
 * exactly the password_resets pattern.
 *
 * Existing plaintext tokens are never hashed in place here: every one of
 * these rows is invalidated outright instead (expires_at set to right
 * now), rather than carrying the old plaintext token forward in any form
 * — hashing it would just move the same "recoverable from the row"
 * concern onto a value derived from it by a known scheme. A workspace
 * owner/admin re-sends/regenerates from members.php, which issues a fresh
 * token under the new hashed scheme.
 */
return function (PDO $pdo): void {
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='invitations'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(invitations)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('token_hash', $columns, true)) {
        return;
    }

    // Every existing invite — accepted or still pending — is invalidated
    // outright rather than having its plaintext token carried forward.
    $pdo->exec("UPDATE invitations SET expires_at = datetime('now')");

    // Requires PRAGMA foreign_keys = OFF and PRAGMA legacy_alter_table = ON
    // to already be set on $pdo — runMigrations() (includes/migrator.php)
    // sets both once for the whole migration batch, outside any
    // transaction, since SQLite silently ignores both pragmas once a
    // transaction is already open.
    $pdo->exec('ALTER TABLE invitations RENAME TO invitations_old');
    $pdo->exec('DROP INDEX IF EXISTS idx_invitations_workspace');

    $pdo->exec('CREATE TABLE invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        role TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        accepted_at TEXT,
        invited_by INTEGER NOT NULL
    )');

    // token_hash gets a random per-row placeholder — never a hash of the
    // old plaintext token — purely to satisfy NOT NULL UNIQUE on rows that
    // were just expired above; it can never validate against any token a
    // caller could actually present.
    $pdo->exec("INSERT INTO invitations (id, workspace_id, email, role, token_hash, expires_at, accepted_at, invited_by)
        SELECT id, workspace_id, email, role, 'invalidated-' || lower(hex(randomblob(32))), expires_at, accepted_at, invited_by
        FROM invitations_old");

    $pdo->exec('DROP TABLE invitations_old');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invitations_workspace ON invitations(workspace_id)');
};
