<?php
declare(strict_types=1);

/**
 * Rebuilds one table with owner_user_id and visibility appended to its
 * column list, guarded by legacy_alter_table so the rename doesn't corrupt
 * the REFERENCES clause of any OTHER table pointing at it — same pattern as
 * migrations/003_migrate_accounts_identity_anchor.php's own rebuild, and for
 * the same reason: SQLite's ALTER TABLE ADD COLUMN cannot add a CHECK
 * constraint, so a NOT NULL ... CHECK (...) column needs a full rebuild.
 * Runs inside the transaction runMigrations() already holds open.
 *
 * @param string[] $columns original column list, used for both the INSERT and
 *     the SELECT so the two new columns get their table defaults instead of
 *     being copied (owner_user_id -> NULL, visibility -> 'workspace').
 * @param array<string,string> $indexes indexName => full CREATE INDEX statement
 */
function addVisibilityColumnsToTable(
    PDO $pdo,
    string $table,
    string $createSql,
    array $columns,
    array $indexes,
    bool $hasUpdatedAtTrigger
): void {
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $existingColumns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('owner_user_id', $existingColumns, true)) {
        return;
    }

    $oldTable = $table . '_old';
    $cols = implode(', ', $columns);

    $pdo->exec('PRAGMA legacy_alter_table = ON');
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec("ALTER TABLE {$table} RENAME TO {$oldTable}");

        // These index/trigger names followed the table into {$oldTable} on rename.
        foreach (array_keys($indexes) as $indexName) {
            $pdo->exec("DROP INDEX IF EXISTS {$indexName}");
        }
        if ($hasUpdatedAtTrigger) {
            $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_updated_at");
        }

        $pdo->exec($createSql);

        $pdo->exec("INSERT INTO {$table} ({$cols}) SELECT {$cols} FROM {$oldTable}");

        $pdo->exec("DROP TABLE {$oldTable}");

        foreach ($indexes as $createIndexSql) {
            $pdo->exec($createIndexSql);
        }
        if ($hasUpdatedAtTrigger) {
            $pdo->exec("CREATE TRIGGER trg_{$table}_updated_at
                AFTER UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    UPDATE {$table} SET updated_at = datetime('now') WHERE id = NEW.id;
                END");
        }
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
    }
}

return function (PDO $pdo): void {
    $tables = [
        'emails' => [
            'ddl' => "CREATE TABLE emails (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_address TEXT NOT NULL UNIQUE COLLATE NOCASE,
                display_name TEXT,
                provider TEXT,
                type TEXT NOT NULL DEFAULT 'Not Set' CHECK (type IN ('Personal','Work','Business','Project','Secondary','Temporary','Other','Not Set')),
                purpose TEXT,
                status TEXT NOT NULL DEFAULT 'Unknown' CHECK (status IN ('Active','Suspended','Disabled','Abandoned','Unknown')),
                created_date TEXT,
                last_verified TEXT,
                notes TEXT,
                is_favorite INTEGER NOT NULL DEFAULT 0,
                is_archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                owner_user_id INTEGER,
                visibility TEXT NOT NULL DEFAULT 'workspace' CHECK (visibility IN ('private','workspace'))
            )",
            'columns' => ['id', 'email_address', 'display_name', 'provider', 'type', 'purpose', 'status', 'created_date', 'last_verified', 'notes', 'is_favorite', 'is_archived', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_emails_status' => 'CREATE INDEX idx_emails_status ON emails(status)',
                'idx_emails_type' => 'CREATE INDEX idx_emails_type ON emails(type)',
                'idx_emails_favorite' => 'CREATE INDEX idx_emails_favorite ON emails(is_favorite)',
            ],
            'trigger' => true,
        ],
        'services' => [
            'ddl' => "CREATE TABLE services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_name TEXT NOT NULL,
                website TEXT,
                login_url TEXT,
                category TEXT NOT NULL DEFAULT 'Not Set',
                status TEXT NOT NULL DEFAULT 'Unknown',
                purpose TEXT,
                notes TEXT,
                is_archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                owner_user_id INTEGER,
                visibility TEXT NOT NULL DEFAULT 'workspace' CHECK (visibility IN ('private','workspace'))
            )",
            'columns' => ['id', 'service_name', 'website', 'login_url', 'category', 'status', 'purpose', 'notes', 'is_archived', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_services_name_unique' => 'CREATE UNIQUE INDEX idx_services_name_unique ON services(service_name COLLATE NOCASE)',
                'idx_services_category' => 'CREATE INDEX idx_services_category ON services(category)',
            ],
            'trigger' => true,
        ],
        'phones' => [
            'ddl' => "CREATE TABLE phones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT NOT NULL UNIQUE,
                country TEXT,
                label TEXT,
                status TEXT NOT NULL DEFAULT 'Unknown',
                is_primary INTEGER NOT NULL DEFAULT 0,
                notes TEXT,
                is_archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                owner_user_id INTEGER,
                visibility TEXT NOT NULL DEFAULT 'workspace' CHECK (visibility IN ('private','workspace'))
            )",
            'columns' => ['id', 'phone_number', 'country', 'label', 'status', 'is_primary', 'notes', 'is_archived', 'created_at', 'updated_at'],
            'indexes' => [],
            'trigger' => true,
        ],
        'accounts' => [
            'ddl' => "CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
                email_id INTEGER REFERENCES emails(id) ON DELETE RESTRICT,
                identity_type TEXT NOT NULL DEFAULT 'email' CHECK (identity_type IN ('email','phone','username','other')),
                identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT,
                identity_value TEXT,
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
                owner_user_id INTEGER,
                visibility TEXT NOT NULL DEFAULT 'workspace' CHECK (visibility IN ('private','workspace')),
                CHECK (
                    (identity_type = 'email'    AND email_id IS NOT NULL) OR
                    (identity_type = 'phone'    AND identity_phone_id IS NOT NULL) OR
                    (identity_type = 'username' AND username IS NOT NULL) OR
                    (identity_type = 'other')
                )
            )",
            'columns' => ['id', 'service_id', 'email_id', 'identity_type', 'identity_phone_id', 'identity_value', 'username', 'display_name', 'external_account_id', 'account_url', 'login_url', 'status', 'account_type', 'created_date', 'last_login', 'last_verified', 'notes', 'is_archived', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_accounts_service' => 'CREATE INDEX idx_accounts_service ON accounts(service_id)',
                'idx_accounts_email' => 'CREATE INDEX idx_accounts_email ON accounts(email_id)',
                'idx_accounts_status' => 'CREATE INDEX idx_accounts_status ON accounts(status)',
                'idx_accounts_identity_phone' => 'CREATE INDEX idx_accounts_identity_phone ON accounts(identity_phone_id)',
            ],
            'trigger' => true,
        ],
    ];

    foreach ($tables as $table => $spec) {
        addVisibilityColumnsToTable($pdo, $table, $spec['ddl'], $spec['columns'], $spec['indexes'], $spec['trigger']);
    }
};
