<?php
declare(strict_types=1);

/**
 * Outbound mail infrastructure. mail_queue holds messages queued by
 * queueMail() (includes/mail.php) for the SMTP sender to pick up later —
 * nothing ever sends synchronously from a request. app_settings is a
 * generic key/value store; its first use is the SMTP connection settings
 * configured on settings.php, with the password kept encrypted (see
 * encryptSecret() in includes/mail.php), never in plain text.
 *
 * setting_key (not "key") to stay well clear of the SQL keyword even though
 * SQLite tolerates it unquoted in practice.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        to_address TEXT NOT NULL,
        subject TEXT NOT NULL,
        body_html TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','failed')),
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        sent_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_status ON mail_queue(status)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        value TEXT
    )');
};
