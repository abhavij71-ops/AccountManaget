<?php
declare(strict_types=1);

/**
 * Adds the identity-anchor columns (identity_type, identity_phone_id) and the
 * matching CHECK constraint to an accounts table created before this change.
 * SQLite cannot ALTER TABLE to add a CHECK, so this rebuilds the table via
 * the standard rename → create → copy → drop pattern. Every pre-existing
 * account is anchored to its (previously mandatory) email_id.
 *
 * Runs inside the transaction runMigrations() already holds open.
 */
return function (PDO $pdo): void {
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('identity_type', $columns, true)) {
        return;
    }

    // legacy_alter_table = ON (set by runMigrations(), includes/migrator.php,
    // once for the whole batch) stops SQLite (>=3.25) from rewriting OTHER
    // tables' REFERENCES clauses to follow this rename — without it,
    // account_security, account_recovery, phone_account, subscriptions,
    // payments, and custom_fields would all end up pointing at
    // "accounts_old" and break the moment it's dropped below. See
    // migrations/002_repair_accounts_old_references.php for the fix-up when
    // this already happened on a database migrated before that pragma was
    // applied correctly.
    $pdo->exec('ALTER TABLE accounts RENAME TO accounts_old');

    // These index/trigger names followed the table into accounts_old on rename.
    $pdo->exec('DROP INDEX IF EXISTS idx_accounts_service');
    $pdo->exec('DROP INDEX IF EXISTS idx_accounts_email');
    $pdo->exec('DROP INDEX IF EXISTS idx_accounts_status');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_accounts_updated_at');

    $pdo->exec("CREATE TABLE accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
        email_id INTEGER REFERENCES emails(id) ON DELETE RESTRICT,
        identity_type TEXT NOT NULL DEFAULT 'email' CHECK (identity_type IN ('email','phone','username','other')),
        identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT,
        username TEXT,
        display_name TEXT,
        external_account_id TEXT,
        account_url TEXT,
        login_url TEXT,
        status TEXT NOT NULL DEFAULT 'Unknown' CHECK (status IN ('Active','Suspended','Disabled','Closed','Abandoned','Pending','Unknown')),
        account_type TEXT NOT NULL DEFAULT 'Not Set' CHECK (account_type IN ('Personal','Work','Business','Project','Other','Not Set')),
        created_date TEXT,
        last_login TEXT,
        last_verified TEXT,
        notes TEXT,
        is_archived INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        CHECK (
            (identity_type = 'email'    AND email_id IS NOT NULL) OR
            (identity_type = 'phone'    AND identity_phone_id IS NOT NULL) OR
            (identity_type = 'username' AND username IS NOT NULL) OR
            (identity_type = 'other')
        )
    )");

    $pdo->exec("INSERT INTO accounts (
            id, service_id, email_id, identity_type, identity_phone_id,
            username, display_name, external_account_id, account_url, login_url,
            status, account_type, created_date, last_login, last_verified,
            notes, is_archived, created_at, updated_at
        )
        SELECT
            id, service_id, email_id, 'email', NULL,
            username, display_name, external_account_id, account_url, login_url,
            status, account_type, created_date, last_login, last_verified,
            notes, is_archived, created_at, updated_at
        FROM accounts_old");

    $pdo->exec('DROP TABLE accounts_old');

    $pdo->exec('CREATE INDEX idx_accounts_service ON accounts(service_id)');
    $pdo->exec('CREATE INDEX idx_accounts_email ON accounts(email_id)');
    $pdo->exec('CREATE INDEX idx_accounts_status ON accounts(status)');
    $pdo->exec('CREATE INDEX idx_accounts_identity_phone ON accounts(identity_phone_id)');
    $pdo->exec("CREATE TRIGGER trg_accounts_updated_at
        AFTER UPDATE ON accounts
        FOR EACH ROW
        BEGIN
            UPDATE accounts SET updated_at = datetime('now') WHERE id = NEW.id;
        END");
};
