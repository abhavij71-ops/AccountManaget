<?php
declare(strict_types=1);

/**
 * history.changed_by was declared REFERENCES users(id) — but the
 * workspace's own `users` table is dead in v2 (only
 * upgrade-to-multiuser.php ever reads it; nothing writes to it), while
 * log_history() has always written currentUserId(), the PLATFORM
 * accounts_users.id, into changed_by. On any database where that
 * workspace users table is empty or has no row with that id — every fresh
 * install, every newly registered workspace, and any invited member even
 * in an upgraded workspace — inserting into history fails outright with
 * "FOREIGN KEY constraint failed", breaking every write that logs history.
 * It only ever worked for the legacy single-tenant admin, whose id
 * happened to be 1 in both tables.
 *
 * Rebuilds history with changed_by as a plain INTEGER: still the platform
 * accounts_users.id, just no (and can't be a) foreign key tying two
 * separate SQLite files together — the same cross-database-reference
 * pattern already used by owner_user_id and by
 * review_items.responsible_user_id (migrations/009_create_review_campaign_tables.php).
 * Every column, index, and row is preserved.
 *
 * No PRAGMA toggling here — runMigrations() (includes/migrator.php) sets
 * foreign_keys=OFF and legacy_alter_table=ON once for the whole migration
 * batch, outside any transaction, since SQLite ignores both mid-transaction.
 */
return function (PDO $pdo): void {
    $historyDdl = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'history'")->fetchColumn();
    if ($historyDdl === false || !str_contains((string) $historyDdl, 'REFERENCES users')) {
        return;
    }

    $pdo->exec('ALTER TABLE history RENAME TO history_old');

    // These index names followed the table into history_old on rename.
    $pdo->exec('DROP INDEX IF EXISTS idx_history_entity');
    $pdo->exec('DROP INDEX IF EXISTS idx_history_created');

    $pdo->exec("CREATE TABLE history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT NOT NULL CHECK (entity_type IN ('email', 'service', 'account', 'phone')),
        entity_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        field_name TEXT,
        old_value TEXT,
        new_value TEXT,
        changed_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec('INSERT INTO history (id, entity_type, entity_id, action, field_name, old_value, new_value, changed_by, created_at)
        SELECT id, entity_type, entity_id, action, field_name, old_value, new_value, changed_by, created_at FROM history_old');

    $pdo->exec('DROP TABLE history_old');

    $pdo->exec('CREATE INDEX idx_history_entity ON history(entity_type, entity_id)');
    $pdo->exec('CREATE INDEX idx_history_created ON history(created_at)');
};
