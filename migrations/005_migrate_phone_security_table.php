<?php
declare(strict_types=1);

/**
 * Adds the phone_security table (IDENTITY-MODEL.md sec. 5) for databases
 * created before this change. A brand-new table needs no rename/copy dance —
 * just guard against re-creating it on every request.
 */
return function (PDO $pdo): void {
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
};
