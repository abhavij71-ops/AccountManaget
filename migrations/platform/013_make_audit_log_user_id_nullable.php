<?php
declare(strict_types=1);

/**
 * Relaxes audit_log.user_id from NOT NULL to nullable (keeping its FK) so a
 * platform-admin action (admin/index.php, admin/users.php,
 * admin/settings.php) can be logged too. The admin panel authenticates with
 * a single shared password (admin/_guard.php) and is deliberately
 * independent of includes/auth.php's accounts_users/workspace login — there
 * is no real user_id to attribute those events to. logAuditEvent()
 * (includes/audit.php) marks such rows with details.actor = 'platform-admin'
 * so a NULL user_id here is still traceable to who actually did it, not
 * merely "unknown."
 */
return function (PDO $pdo): void {
    $columns = $pdo->query('PRAGMA table_info(audit_log)')->fetchAll(PDO::FETCH_ASSOC);
    $userIdColumn = null;
    foreach ($columns as $column) {
        if ($column['name'] === 'user_id') {
            $userIdColumn = $column;
            break;
        }
    }
    if ($userIdColumn === null || (int) $userIdColumn['notnull'] === 0) {
        return;
    }

    // Requires PRAGMA foreign_keys = OFF and PRAGMA legacy_alter_table = ON
    // to already be set on $pdo — runMigrations() (includes/migrator.php)
    // sets both once for the whole migration batch.
    $pdo->exec('ALTER TABLE audit_log RENAME TO audit_log_old');
    $pdo->exec('DROP INDEX IF EXISTS idx_audit_log_user');
    $pdo->exec('DROP INDEX IF EXISTS idx_audit_log_created');

    $pdo->exec('CREATE TABLE audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES accounts_users(id) ON DELETE CASCADE,
        workspace_id INTEGER,
        action TEXT NOT NULL,
        entity_type TEXT,
        entity_id INTEGER,
        ip TEXT,
        details TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');

    $pdo->exec('INSERT INTO audit_log (id, user_id, workspace_id, action, entity_type, entity_id, ip, details, created_at)
        SELECT id, user_id, workspace_id, action, entity_type, entity_id, ip, details, created_at FROM audit_log_old');

    $pdo->exec('DROP TABLE audit_log_old');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at)');
};
