<?php
declare(strict_types=1);

/**
 * Adds the service_defaults table (Service Defaults feature) for databases
 * created before this change. A brand-new table 1:1 with services — no
 * rename/copy dance, just guard against re-creating it on every request.
 */
return function (PDO $pdo): void {
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
};
