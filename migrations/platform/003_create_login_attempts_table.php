<?php
declare(strict_types=1);

/**
 * Creates the login_attempts table used for brute-force lockout tracking
 * (login.php): every attempt — success or failure — is logged with the
 * client IP and the submitted login identifier, so a lockout can be keyed
 * on either one independently.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        username TEXT NOT NULL,
        attempted_at TEXT NOT NULL DEFAULT (datetime('now')),
        success INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_username ON login_attempts(username, attempted_at)');
};
