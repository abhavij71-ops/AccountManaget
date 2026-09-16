<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        applySchemaUpgrades($pdo);
    } catch (PDOException $e) {
        error_log('Account Manager: database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die(APP_DEBUG ? t('db.connection_error', ['error' => $e->getMessage()]) : t('db.connection_error_generic'));
    }

    return $pdo;
}

function applySchemaUpgrades(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='emails'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(emails)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_favorite', $columns, true)) {
        $pdo->exec('ALTER TABLE emails ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_emails_favorite ON emails(is_favorite)');
    }

    migrateAccountsIdentityAnchor($pdo);
    repairAccountsOldReferences($pdo);
    migrateAccountsIdentityValue($pdo);
    migratePhoneSecurityTable($pdo);
    migrateServiceDefaultsTable($pdo);
}

/**
 * Adds the service_defaults table (Service Defaults feature) for databases
 * created before this change. A brand-new table 1:1 with services — no
 * rename/copy dance, just guard against re-creating it on every request.
 */
function migrateServiceDefaultsTable(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='service_defaults'")->fetchColumn();
    if ($tableExists) {
        return;
    }

    $pdo->exec("CREATE TABLE service_defaults (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER NOT NULL UNIQUE REFERENCES services(id) ON DELETE CASCADE,
        default_identity_type TEXT CHECK (default_identity_type IS NULL OR default_identity_type IN ('email','phone','username','other')),
        default_twofa_status TEXT CHECK (default_twofa_status IS NULL OR default_twofa_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
        default_twofa_method TEXT,
        default_passkey_status TEXT CHECK (default_passkey_status IS NULL OR default_passkey_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
        default_security_questions_status TEXT CHECK (default_security_questions_status IS NULL OR default_security_questions_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
        default_recovery_status TEXT CHECK (default_recovery_status IS NULL OR default_recovery_status IN ('Verified','Not Verified','Unknown','Not Set','Not Applicable')),
        recovery_follows_identity INTEGER NOT NULL DEFAULT 0,
        default_subscription_type TEXT CHECK (default_subscription_type IS NULL OR default_subscription_type IN ('Free','Paid','Trial','Promotional','Lifetime','Enterprise','Unknown','Not Applicable')),
        default_subscription_status TEXT CHECK (default_subscription_status IS NULL OR default_subscription_status IN ('Active','Cancelled','Expired','Paused','Unknown','Not Applicable')),
        default_billing_cycle TEXT CHECK (default_billing_cycle IS NULL OR default_billing_cycle IN ('Monthly','Yearly','Weekly','Quarterly','One-Time','Custom','Unknown','Not Applicable')),
        default_currency TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TRIGGER trg_service_defaults_updated_at
        AFTER UPDATE ON service_defaults
        FOR EACH ROW
        BEGIN
            UPDATE service_defaults SET updated_at = datetime('now') WHERE id = NEW.id;
        END");
}

/**
 * Adds identity_value (free-text label for identity_type='other') for
 * databases created before this change. Plain ADD COLUMN — no rename, no
 * rebuild, no CHECK change, no backfill needed since it's nullable.
 *
 * Deliberately NOT enforced by a CHECK: an 'other'-anchored account with no
 * recorded value is a legitimate state in this product's five-state model
 * (missing knowledge), not an error to forbid at the schema level — PHP-side
 * validation already treats it as optional, and needs-attention.php surfaces
 * it as an Informational nudge instead.
 */
function migrateAccountsIdentityValue(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('identity_value', $columns, true)) {
        return;
    }

    $pdo->exec('ALTER TABLE accounts ADD COLUMN identity_value TEXT');
}

/**
 * Adds the phone_security table (IDENTITY-MODEL.md sec. 5) for databases
 * created before this change. A brand-new table needs no rename/copy dance —
 * just guard against re-creating it on every request.
 */
function migratePhoneSecurityTable(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='phone_security'")->fetchColumn();
    if ($tableExists) {
        return;
    }

    $pdo->exec("CREATE TABLE phone_security (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone_id INTEGER NOT NULL UNIQUE REFERENCES phones(id) ON DELETE CASCADE,
        sim_pin_status TEXT NOT NULL DEFAULT 'Not Set' CHECK (sim_pin_status IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
        port_out_lock TEXT NOT NULL DEFAULT 'Not Set' CHECK (port_out_lock IN ('Enabled','Disabled','Unknown','Not Set','Not Applicable')),
        carrier TEXT,
        esim INTEGER,
        last_security_check TEXT,
        security_score INTEGER CHECK (security_score IS NULL OR (security_score BETWEEN 0 AND 100)),
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TRIGGER trg_phone_security_updated_at
        AFTER UPDATE ON phone_security
        FOR EACH ROW
        BEGIN
            UPDATE phone_security SET updated_at = datetime('now') WHERE id = NEW.id;
        END");
}

/**
 * Adds the identity-anchor columns (identity_type, identity_phone_id) and the
 * matching CHECK constraint to an accounts table created before this change.
 * SQLite cannot ALTER TABLE to add a CHECK, so this rebuilds the table via
 * the standard rename → create → copy → drop pattern. Every pre-existing
 * account is anchored to its (previously mandatory) email_id.
 */
function migrateAccountsIdentityAnchor(PDO $pdo): void
{
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('identity_type', $columns, true)) {
        return;
    }

    backupDatabaseFile($pdo);

    // legacy_alter_table = ON stops SQLite (>=3.25) from rewriting OTHER tables'
    // REFERENCES clauses to follow this rename — without it, account_security,
    // account_recovery, phone_account, subscriptions, payments, and custom_fields
    // would all end up pointing at "accounts_old" and break the moment it's
    // dropped below. See repairAccountsOldReferences() for the fix-up when this
    // already happened on a database migrated before this pragma was added.
    $pdo->exec('PRAGMA legacy_alter_table = ON');
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
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

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
    }
}

/**
 * Repairs databases where migrateAccountsIdentityAnchor() hit the SQLite
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
 */
function repairAccountsOldReferences(PDO $pdo): void
{
    $affected = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND sql LIKE '%accounts_old%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$affected) {
        return;
    }

    backupDatabaseFile($pdo);

    // DDL copied verbatim from installSchemaStatements() in install.php — the correct,
    // never-corrupted definition of each table, restoring `REFERENCES accounts(id)`.
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
            rebuildTableReferencingAccounts($pdo, $table, $spec);
        }
    }

    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($violations) {
        error_log('Account Manager: PRAGMA foreign_key_check found remaining violations after repairAccountsOldReferences(): ' . json_encode($violations));
    }
}

/**
 * Rebuilds one table via rename -> create (correct DDL) -> copy -> drop ->
 * recreate indexes/trigger, guarded by legacy_alter_table so this rename
 * doesn't itself corrupt some other table's REFERENCES clause the same way.
 *
 * @param array{ddl:string,columns:string[],indexes:array<string,string>,trigger:bool} $spec
 */
function rebuildTableReferencingAccounts(PDO $pdo, string $table, array $spec): void
{
    $oldTable = $table . '_old';
    $cols = implode(', ', $spec['columns']);

    $pdo->exec('PRAGMA legacy_alter_table = ON');
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
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

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
    }
}

/**
 * Flushes WAL into the main database file and copies it to a .bak sibling
 * so a failed or interrupted migration can always be recovered from.
 */
function backupDatabaseFile(PDO $pdo): void
{
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    copy(DB_PATH, DB_PATH . '.bak');
}
