<?php
declare(strict_types=1);

/**
 * Tracks every forgot-password.php submission (not just successful ones —
 * every attempt, so an unlimited email or IP can't be probed to bypass the
 * per-email cap) so isPasswordResetRequestLocked() (includes/
 * password-reset.php) can cap both how many times a single email address
 * is targeted and how many requests a single IP can make, in a rolling
 * window — same self-bounding shape as login_attempts.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        ip TEXT NOT NULL,
        requested_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_requests_email ON password_reset_requests(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_requests_ip ON password_reset_requests(ip)');
};
