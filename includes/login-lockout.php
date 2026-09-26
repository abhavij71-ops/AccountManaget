<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/helpers.php';

const LOGIN_LOCKOUT_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_WINDOW_MINUTES = 15;
const LOGIN_ATTEMPTS_RETENTION_HOURS = 24;

/**
 * True when either this IP or this login identifier has LOGIN_LOCKOUT_MAX_ATTEMPTS
 * failed attempts logged within the last LOGIN_LOCKOUT_WINDOW_MINUTES minutes.
 * A rolling window, not a stored lockout-until timestamp — access is restored
 * automatically as the qualifying failures age past the window, up to fifteen
 * minutes after the last of them.
 *
 * Deliberately returns one bare bool: which axis (IP vs. username) actually
 * tripped it is never exposed to the caller, so the login page can never
 * reveal — even implicitly — which of the two is the one locked out.
 *
 * Shared by both the tenant login (login.php, via includes/auth.php) and the
 * platform admin login (admin/login.php, via admin/_guard.php) — same
 * login_attempts table, same rules. The admin panel has no real per-operator
 * username to key on (a single shared ADMIN_PASSWORD), so it calls this with
 * a fixed literal username of 'platform-admin' instead.
 */
function isLoginLocked(string $ip, string $username): bool
{
    $since = dbNow('-' . LOGIN_LOCKOUT_WINDOW_MINUTES . ' minutes');

    $ipStmt = platformDb()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?'
    );
    $ipStmt->execute([$ip, $since]);
    if ((int) $ipStmt->fetchColumn() >= LOGIN_LOCKOUT_MAX_ATTEMPTS) {
        return true;
    }

    $usernameStmt = platformDb()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? COLLATE NOCASE AND success = 0 AND attempted_at >= ?'
    );
    $usernameStmt->execute([$username, $since]);
    return (int) $usernameStmt->fetchColumn() >= LOGIN_LOCKOUT_MAX_ATTEMPTS;
}

/**
 * Logs one login attempt and opportunistically purges anything older than
 * LOGIN_ATTEMPTS_RETENTION_HOURS. This app has no cron runner yet (see
 * docs/ROADMAP-SAAS.md Phase 15), so the table keeps itself bounded here
 * instead of depending on a scheduled job that doesn't exist.
 */
function recordLoginAttempt(string $ip, string $username, bool $success): void
{
    $platform = platformDb();

    $cutoff = dbNow('-' . LOGIN_ATTEMPTS_RETENTION_HOURS . ' hours');
    $platform->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$cutoff]);

    $platform->prepare('INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, ?)')
        ->execute([$ip, $username, $success ? 1 : 0]);
}
