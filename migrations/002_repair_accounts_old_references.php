<?php
declare(strict_types=1);

/**
 * Repairs databases where the identity-anchor migration hit the SQLite
 * >=3.25 bug above before the legacy_alter_table fix existed: renaming
 * "accounts" silently rewrote six other tables' REFERENCES clauses to point
 * at "accounts_old", which was then dropped, leaving every one of them
 * referencing a table that no longer exists. With PRAGMA foreign_keys = ON
 * (set on every connection — see db()), any INSERT/UPDATE against those
 * tables now fails with "no such table: main.accounts_old".
 *
 * Detection and repair are the same query: any table whose stored DDL still
 * mentions accounts_old is broken and gets rebuilt; once every affected
 * table is fixed, the scan finds nothing and this becomes a no-op — no
 * separate "has this run" flag needed.
 *
 * rebuildTableReferencingAccounts is a local closure, not a top-level named
 * function — loadMigrations() (includes/migrator.php) `require`s this file
 * at most once per process now, but this migration is kept safe on its own
 * too: a named top-level function here would fatal with "Cannot redeclare"
 * the moment anything `require`s this file a second time.
 */
return function (PDO $pdo): void {
    $affected = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND sql LIKE '%accounts_old%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$affected) {
        return;
    }

    /**
     * Rebuilds one table via rename -> create (correct DDL) -> copy -> drop
     * -> recreate indexes/trigger. Requires PRAGMA foreign_keys = OFF and
     * PRAGMA legacy_alter_table = ON to already be set on $pdo —
     * runMigrations() sets both once for the whole migration batch, outside
     * any transaction, since SQLite silently ignores both pragmas once a
     * transaction is already open.
     *
     * @param array{ddl:string,columns:string[],indexes:array<string,string>,trigger:bool} $spec
     */
    $rebuildTableReferencingAccounts = function (PDO $pdo, string $table, array $spec): void {
        $oldTable = $table . '_old';
        $cols = implode(', ', $spec['columns']);

        $pdo->exec("ALTER TABLE {$table} RENAME TO {$oldTable}");

        foreach (array_keys($spec['indexes']) as $indexName) {
            $pdo->exec("DROP INDEX IF EXISTS {$indexName}");
        }
        if ($spec['trigger']) {
            $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_updated_at");
        }

        $pdo->exec($spec['ddl']);

        $pdo->exec("INSERT INTO {$table} ({$cols}) SELECT {$cols} FROM {$oldTable}");

        $pdo->exec("DROP TABLE {$oldTable}");

        foreach ($spec['indexes'] as $createIndexSql) {
            $pdo->exec($createIndexSql);
        }
        if ($spec['trigger']) {
            $pdo->exec("CREATE TRIGGER trg_{$table}_updated_at
                AFTER UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    UPDATE {$table} SET updated_at = datetime('now') WHERE id = NEW.id;
                END");
        }
    };

    // DDL copied verbatim from installSchemaStatements() in includes/schema.php —
    // the correct, never-corrupted definition of each table, restoring
    // `REFERENCES accounts(id)`.
    $rebuilds = [
        'account_security' => [
            'ddl' => "CREATE TABLE account_security (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
                twofa_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (twofa_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
                twofa_method TEXT,
                passkey_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (passkey_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
                security_key_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (security_key_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
                security_questions_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (security_questions_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
                last_security_check TEXT,
                credential_storage TEXT,
                credential_reference TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            'columns' => ['id', 'account_id', 'twofa_status', 'twofa_method', 'passkey_status', 'security_key_status', 'security_questions_status', 'last_security_check', 'credential_storage', 'credential_reference', 'created_at', 'updated_at'],
            'indexes' => [],
            'trigger' => true,
        ],
        'account_recovery' => [
            'ddl' => "CREATE TABLE account_recovery (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
                status TEXT NOT NULL DEFAULT 'Not Set' CHECK (status IN ('Verified','Not Verified','Unknown','Not Set','Not Applicable')),
                recovery_email_id INTEGER REFERENCES emails(id) ON DELETE SET NULL,
                recovery_phone_id INTEGER REFERENCES phones(id) ON DELETE SET NULL,
                recovery_contact TEXT,
                recovery_codes_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (recovery_codes_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
                recovery_codes_reference TEXT,
                backup_method TEXT,
                last_recovery_verification TEXT,
                recovery_notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            'columns' => ['id', 'account_id', 'status', 'recovery_email_id', 'recovery_phone_id', 'recovery_contact', 'recovery_codes_status', 'recovery_codes_reference', 'backup_method', 'last_recovery_verification', 'recovery_notes', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_account_recovery_email' => 'CREATE INDEX idx_account_recovery_email ON account_recovery(recovery_email_id)',
                'idx_account_recovery_phone' => 'CREATE INDEX idx_account_recovery_phone ON account_recovery(recovery_phone_id)',
            ],
            'trigger' => true,
        ],
        'phone_account' => [
            'ddl' => "CREATE TABLE phone_account (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_id INTEGER NOT NULL REFERENCES phones(id) ON DELETE CASCADE,
                account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (phone_id, account_id)
            )",
            'columns' => ['id', 'phone_id', 'account_id', 'created_at'],
            'indexes' => [
                'idx_phone_account_account' => 'CREATE INDEX idx_phone_account_account ON phone_account(account_id)',
                'idx_phone_account_phone' => 'CREATE INDEX idx_phone_account_phone ON phone_account(phone_id)',
            ],
            'trigger' => false,
        ],
        'subscriptions' => [
            'ddl' => "CREATE TABLE subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
                type TEXT NOT NULL DEFAULT 'Unknown' CHECK (type IN ('Free','Paid','Trial','Promotional','Lifetime','Enterprise','Unknown','Not Applicable')),
                plan TEXT,
                status TEXT NOT NULL DEFAULT 'Unknown' CHECK (status IN ('Active','Cancelled','Expired','Paused','Unknown','Not Applicable')),
                price REAL,
                currency TEXT,
                billing_cycle TEXT NOT NULL DEFAULT 'Not Applicable' CHECK (billing_cycle IN ('Monthly','Yearly','Weekly','Quarterly','One-Time','Custom','Unknown','Not Applicable')),
                start_date TEXT,
                renewal_date TEXT,
                auto_renewal INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            'columns' => ['id', 'account_id', 'type', 'plan', 'status', 'price', 'currency', 'billing_cycle', 'start_date', 'renewal_date', 'auto_renewal', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_subscriptions_renewal' => 'CREATE INDEX idx_subscriptions_renewal ON subscriptions(renewal_date)',
                'idx_subscriptions_type' => 'CREATE INDEX idx_subscriptions_type ON subscriptions(type)',
                'idx_subscriptions_currency' => 'CREATE INDEX idx_subscriptions_currency ON subscriptions(currency)',
            ],
            'trigger' => true,
        ],
        'payments' => [
            'ddl' => "CREATE TABLE payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
                payment_required INTEGER NOT NULL DEFAULT 0,
                payment_method TEXT,
                card_brand TEXT,
                last4 TEXT CHECK (last4 IS NULL OR (length(last4) = 4 AND last4 GLOB '[0-9][0-9][0-9][0-9]')),
                payment_reference TEXT,
                auto_renewal INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            'columns' => ['id', 'account_id', 'payment_required', 'payment_method', 'card_brand', 'last4', 'payment_reference', 'auto_renewal', 'created_at', 'updated_at'],
            'indexes' => [],
            'trigger' => true,
        ],
        'custom_fields' => [
            'ddl' => "CREATE TABLE custom_fields (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                field_key TEXT NOT NULL,
                field_value TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (account_id, field_key)
            )",
            'columns' => ['id', 'account_id', 'field_key', 'field_value', 'created_at', 'updated_at'],
            'indexes' => [
                'idx_custom_fields_account' => 'CREATE INDEX idx_custom_fields_account ON custom_fields(account_id)',
            ],
            'trigger' => true,
        ],
    ];

    foreach ($rebuilds as $table => $spec) {
        if (in_array($table, $affected, true)) {
            $rebuildTableReferencingAccounts($pdo, $table, $spec);
        }
    }

    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($violations) {
        error_log('Account Manager: PRAGMA foreign_key_check found remaining violations after repairAccountsOldReferences(): ' . json_encode($violations));
    }
};
