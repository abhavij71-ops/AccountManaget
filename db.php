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
        http_response_code(500);
        die(t('db.connection_error', ['error' => $e->getMessage()]));
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
    migratePhoneSecurityTable($pdo);
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
