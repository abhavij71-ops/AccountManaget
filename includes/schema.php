<?php
declare(strict_types=1);

/**
 * The core schema — every table and trigger a workspace database needs on
 * day one, before any versioned migration ever runs. Moved out of
 * install.php so it has exactly one home: install.php's legacy single-
 * tenant bootstrap and includes/workspaces.php's createWorkspace() both
 * call applyCoreSchema() below instead of each keeping their own copy of
 * this list.
 */
function installSchemaStatements(): array
{
    return [
        // emails/services/phones/accounts below carry owner_user_id/visibility
        // directly (they didn't before) so the new idx_*_visibility_owner
        // indexes — added alongside them, for visibilityScope()'s (includes/
        // helpers.php) WHERE clause on every list/search/export query — can be
        // created in the same applyCoreSchema() pass a fresh workspace runs,
        // rather than only after migrations/007_add_visibility_columns.php
        // (which still exists, unchanged, for a workspace database created
        // before this: it rebuilds the table only when owner_user_id is
        // missing, so it's a safe no-op here since a table created by the
        // statements below already has both columns from the start).

        // No `users` table here anymore — it was the legacy single-tenant
        // admin table, dead weight on every v2 workspace (nothing ever
        // wrote to it; log_history() has always recorded the PLATFORM
        // accounts_users.id in history.changed_by, not a row here). A
        // workspace upgraded from v1 via upgrade-to-multiuser.php keeps
        // its existing `users` table untouched — that script still reads
        // it — this only stops a brand-new workspace from getting one it
        // would never use. See migrations/010_drop_history_users_fk.php
        // for the matching fix to already-existing workspace databases.

        'CREATE TABLE IF NOT EXISTS phones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_number TEXT NOT NULL UNIQUE,
            country TEXT,
            label TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\',
            is_primary INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            owner_user_id INTEGER,
            visibility TEXT NOT NULL DEFAULT \'workspace\' CHECK (visibility IN (\'private\',\'workspace\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_phones_visibility_owner ON phones(visibility, owner_user_id)',

        'CREATE TABLE IF NOT EXISTS phone_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL UNIQUE REFERENCES phones(id) ON DELETE CASCADE,
            sim_pin_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (sim_pin_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            port_out_lock TEXT NOT NULL DEFAULT \'Not Set\' CHECK (port_out_lock IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            carrier TEXT,
            esim INTEGER,
            last_security_check TEXT,
            security_score INTEGER CHECK (security_score IS NULL OR (security_score BETWEEN 0 AND 100)),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_address TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name TEXT,
            provider TEXT,
            type TEXT NOT NULL DEFAULT \'Not Set\' CHECK (type IN (\'Personal\',\'Work\',\'Business\',\'Project\',\'Secondary\',\'Temporary\',\'Other\',\'Not Set\')),
            purpose TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Suspended\',\'Disabled\',\'Abandoned\',\'Unknown\')),
            created_date TEXT,
            last_verified TEXT,
            notes TEXT,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            owner_user_id INTEGER,
            visibility TEXT NOT NULL DEFAULT \'workspace\' CHECK (visibility IN (\'private\',\'workspace\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_emails_status ON emails(status)',
        'CREATE INDEX IF NOT EXISTS idx_emails_type ON emails(type)',
        'CREATE INDEX IF NOT EXISTS idx_emails_favorite ON emails(is_favorite)',
        'CREATE INDEX IF NOT EXISTS idx_emails_visibility_owner ON emails(visibility, owner_user_id)',

        'CREATE TABLE IF NOT EXISTS email_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id INTEGER NOT NULL UNIQUE REFERENCES emails(id) ON DELETE CASCADE,
            twofa_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (twofa_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            twofa_method TEXT,
            passkey_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (passkey_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_key_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_key_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_questions_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_questions_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            last_security_check TEXT,
            recovery_email_id INTEGER REFERENCES emails(id) ON DELETE SET NULL,
            recovery_phone_id INTEGER REFERENCES phones(id) ON DELETE SET NULL,
            recovery_codes_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (recovery_codes_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_codes_reference TEXT,
            backup_method TEXT,
            last_recovery_verification TEXT,
            security_score INTEGER CHECK (security_score IS NULL OR (security_score BETWEEN 0 AND 100)),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_email_security_recovery_email ON email_security(recovery_email_id)',
        'CREATE INDEX IF NOT EXISTS idx_email_security_recovery_phone ON email_security(recovery_phone_id)',

        'CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name TEXT NOT NULL,
            website TEXT,
            login_url TEXT,
            category TEXT NOT NULL DEFAULT \'Not Set\',
            status TEXT NOT NULL DEFAULT \'Unknown\',
            purpose TEXT,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            owner_user_id INTEGER,
            visibility TEXT NOT NULL DEFAULT \'workspace\' CHECK (visibility IN (\'private\',\'workspace\'))
        )',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_services_name_unique ON services(service_name COLLATE NOCASE)',
        'CREATE INDEX IF NOT EXISTS idx_services_category ON services(category)',
        'CREATE INDEX IF NOT EXISTS idx_services_visibility_owner ON services(visibility, owner_user_id)',

        'CREATE TABLE IF NOT EXISTS service_defaults (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER NOT NULL UNIQUE REFERENCES services(id) ON DELETE CASCADE,
            default_identity_type TEXT CHECK (default_identity_type IS NULL OR default_identity_type IN (\'email\',\'phone\',\'username\',\'other\')),
            default_twofa_status TEXT CHECK (default_twofa_status IS NULL OR default_twofa_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            default_twofa_method TEXT,
            default_passkey_status TEXT CHECK (default_passkey_status IS NULL OR default_passkey_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            default_security_questions_status TEXT CHECK (default_security_questions_status IS NULL OR default_security_questions_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            default_recovery_status TEXT CHECK (default_recovery_status IS NULL OR default_recovery_status IN (\'Verified\',\'Not Verified\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_follows_identity INTEGER NOT NULL DEFAULT 0,
            default_subscription_type TEXT CHECK (default_subscription_type IS NULL OR default_subscription_type IN (\'Free\',\'Paid\',\'Trial\',\'Promotional\',\'Lifetime\',\'Enterprise\',\'Unknown\',\'Not Applicable\')),
            default_subscription_status TEXT CHECK (default_subscription_status IS NULL OR default_subscription_status IN (\'Active\',\'Cancelled\',\'Expired\',\'Paused\',\'Unknown\',\'Not Applicable\')),
            default_billing_cycle TEXT CHECK (default_billing_cycle IS NULL OR default_billing_cycle IN (\'Monthly\',\'Yearly\',\'Weekly\',\'Quarterly\',\'One-Time\',\'Custom\',\'Unknown\',\'Not Applicable\')),
            default_currency TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
            email_id INTEGER REFERENCES emails(id) ON DELETE RESTRICT,
            identity_type TEXT NOT NULL DEFAULT \'email\' CHECK (identity_type IN (\'email\',\'phone\',\'username\',\'other\')),
            identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT,
            identity_value TEXT,
            username TEXT,
            display_name TEXT,
            external_account_id TEXT,
            account_url TEXT,
            login_url TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Suspended\',\'Disabled\',\'Closed\',\'Abandoned\',\'Pending\',\'Unknown\')),
            account_type TEXT NOT NULL DEFAULT \'Not Set\' CHECK (account_type IN (\'Personal\',\'Work\',\'Business\',\'Project\',\'Other\',\'Not Set\')),
            created_date TEXT,
            last_login TEXT,
            last_verified TEXT,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            owner_user_id INTEGER,
            visibility TEXT NOT NULL DEFAULT \'workspace\' CHECK (visibility IN (\'private\',\'workspace\')),
            CHECK (
                (identity_type = \'email\'    AND email_id IS NOT NULL) OR
                (identity_type = \'phone\'    AND identity_phone_id IS NOT NULL) OR
                (identity_type = \'username\' AND username IS NOT NULL) OR
                (identity_type = \'other\')
            )
        )',
        'CREATE INDEX IF NOT EXISTS idx_accounts_service ON accounts(service_id)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_email ON accounts(email_id)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_status ON accounts(status)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_identity_phone ON accounts(identity_phone_id)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_visibility_owner ON accounts(visibility, owner_user_id)',

        'CREATE TABLE IF NOT EXISTS account_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            twofa_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (twofa_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            twofa_method TEXT,
            passkey_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (passkey_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_key_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_key_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_questions_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_questions_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            last_security_check TEXT,
            credential_storage TEXT,
            credential_reference TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS account_recovery (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (status IN (\'Verified\',\'Not Verified\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_email_id INTEGER REFERENCES emails(id) ON DELETE SET NULL,
            recovery_phone_id INTEGER REFERENCES phones(id) ON DELETE SET NULL,
            recovery_contact TEXT,
            recovery_codes_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (recovery_codes_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_codes_reference TEXT,
            backup_method TEXT,
            last_recovery_verification TEXT,
            recovery_notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_account_recovery_email ON account_recovery(recovery_email_id)',
        'CREATE INDEX IF NOT EXISTS idx_account_recovery_phone ON account_recovery(recovery_phone_id)',

        'CREATE TABLE IF NOT EXISTS phone_email (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL REFERENCES phones(id) ON DELETE CASCADE,
            email_id INTEGER NOT NULL REFERENCES emails(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (phone_id, email_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_phone_email_email ON phone_email(email_id)',
        'CREATE INDEX IF NOT EXISTS idx_phone_email_phone ON phone_email(phone_id)',

        'CREATE TABLE IF NOT EXISTS phone_account (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL REFERENCES phones(id) ON DELETE CASCADE,
            account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (phone_id, account_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_phone_account_account ON phone_account(account_id)',
        'CREATE INDEX IF NOT EXISTS idx_phone_account_phone ON phone_account(phone_id)',

        'CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT \'Unknown\' CHECK (type IN (\'Free\',\'Paid\',\'Trial\',\'Promotional\',\'Lifetime\',\'Enterprise\',\'Unknown\',\'Not Applicable\')),
            plan TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Cancelled\',\'Expired\',\'Paused\',\'Unknown\',\'Not Applicable\')),
            price REAL,
            currency TEXT,
            billing_cycle TEXT NOT NULL DEFAULT \'Not Applicable\' CHECK (billing_cycle IN (\'Monthly\',\'Yearly\',\'Weekly\',\'Quarterly\',\'One-Time\',\'Custom\',\'Unknown\',\'Not Applicable\')),
            start_date TEXT,
            renewal_date TEXT,
            auto_renewal INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_renewal ON subscriptions(renewal_date)',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_type ON subscriptions(type)',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_currency ON subscriptions(currency)',

        'CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            payment_required INTEGER NOT NULL DEFAULT 0,
            payment_method TEXT,
            card_brand TEXT,
            last4 TEXT CHECK (last4 IS NULL OR (length(last4) = 4 AND last4 GLOB \'[0-9][0-9][0-9][0-9]\')),
            payment_reference TEXT,
            auto_renewal INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS taggables (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
            entity_type TEXT NOT NULL CHECK (entity_type IN (\'email\',\'service\',\'account\',\'phone\')),
            entity_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (tag_id, entity_type, entity_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_taggables_entity ON taggables(entity_type, entity_id)',

        'CREATE TABLE IF NOT EXISTS custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            field_key TEXT NOT NULL,
            field_value TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (account_id, field_key)
        )',
        'CREATE INDEX IF NOT EXISTS idx_custom_fields_account ON custom_fields(account_id)',

        // changed_by holds currentUserId() — a PLATFORM accounts_users.id
        // (includes/helpers.php's log_history()) — never a row in a
        // workspace table, so it's a plain INTEGER with no (and can't be
        // a) foreign key tying two separate SQLite files together. Same
        // cross-database-reference pattern as owner_user_id elsewhere in
        // this schema.
        'CREATE TABLE IF NOT EXISTS history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL CHECK (entity_type IN (\'email\',\'service\',\'account\',\'phone\')),
            entity_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            field_name TEXT,
            old_value TEXT,
            new_value TEXT,
            changed_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_history_entity ON history(entity_type, entity_id)',
        'CREATE INDEX IF NOT EXISTS idx_history_created ON history(created_at)',
    ];
}

function installTriggerStatements(): array
{
    // No 'users' here — that table is no longer created for new
    // workspaces (see installSchemaStatements() above), and CREATE
    // TRIGGER ... ON users would fail outright against a table that
    // doesn't exist.
    $tablesWithUpdatedAt = [
        'emails', 'email_security', 'services', 'service_defaults', 'accounts',
        'account_security', 'account_recovery', 'phones', 'phone_security', 'subscriptions',
        'payments', 'custom_fields',
    ];

    $statements = [];
    foreach ($tablesWithUpdatedAt as $table) {
        $statements[] = "CREATE TRIGGER IF NOT EXISTS trg_{$table}_updated_at
            AFTER UPDATE ON {$table}
            FOR EACH ROW
            BEGIN
                UPDATE {$table} SET updated_at = datetime('now') WHERE id = NEW.id;
            END";
    }

    return $statements;
}

/**
 * Applies the core schema (every CREATE TABLE/INDEX/TRIGGER above) to a
 * fresh PDO connection — no transaction, no pragma toggling, because
 * there is nothing here but IF-NOT-EXISTS creates against what is, in
 * every real caller, an empty database file. Followed by runMigrations()
 * (includes/migrator.php) in every caller, which is what brings the
 * result up to the latest versioned schema on top of this baseline.
 */
function applyCoreSchema(PDO $pdo): void
{
    foreach (installSchemaStatements() as $sql) {
        $pdo->exec($sql);
    }
    foreach (installTriggerStatements() as $sql) {
        $pdo->exec($sql);
    }
}
