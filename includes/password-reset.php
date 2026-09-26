<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/mail.php';

const PASSWORD_RESET_TTL_HOURS = 2;

const PASSWORD_RESET_REQUEST_MAX_PER_EMAIL = 3;
const PASSWORD_RESET_REQUEST_MAX_PER_IP = 10;
const PASSWORD_RESET_REQUEST_WINDOW_HOURS = 1;

/**
 * True when either this email address or this IP has already made its cap
 * of forgot-password.php requests within the last
 * PASSWORD_RESET_REQUEST_WINDOW_HOURS — checked BEFORE a new reset token is
 * ever created, so a flood never queues more mail regardless of whether the
 * email exists. Same "one bare bool, axis never revealed" contract as
 * includes/login-lockout.php's isLoginLocked() — forgot-password.php's
 * response is neutral either way, so which limit tripped (or whether the
 * email is even registered) is never exposed to the caller.
 */
function isPasswordResetRequestLocked(string $email, string $ip): bool
{
    $since = date('Y-m-d H:i:s', strtotime('-' . PASSWORD_RESET_REQUEST_WINDOW_HOURS . ' hours'));
    $platform = platformDb();

    $emailStmt = $platform->prepare(
        'SELECT COUNT(*) FROM password_reset_requests WHERE email = ? COLLATE NOCASE AND requested_at >= ?'
    );
    $emailStmt->execute([$email, $since]);
    if ((int) $emailStmt->fetchColumn() >= PASSWORD_RESET_REQUEST_MAX_PER_EMAIL) {
        return true;
    }

    $ipStmt = $platform->prepare(
        'SELECT COUNT(*) FROM password_reset_requests WHERE ip = ? AND requested_at >= ?'
    );
    $ipStmt->execute([$ip, $since]);
    return (int) $ipStmt->fetchColumn() >= PASSWORD_RESET_REQUEST_MAX_PER_IP;
}

/**
 * Records one forgot-password.php submission and opportunistically purges
 * anything older than the window — same self-bounding pattern as
 * includes/login-lockout.php's recordLoginAttempt(), no cron job needed.
 * Called for every submitted email (whether or not it's registered), never
 * only for ones that turn out to belong to a real account — otherwise an
 * attacker could probe unlimited nonexistent emails from one IP without
 * ever being counted toward the per-IP cap.
 */
function recordPasswordResetRequest(string $email, string $ip): void
{
    $platform = platformDb();
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . PASSWORD_RESET_REQUEST_WINDOW_HOURS . ' hours'));
    $platform->prepare('DELETE FROM password_reset_requests WHERE requested_at < ?')->execute([$cutoff]);
    $platform->prepare('INSERT INTO password_reset_requests (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
}

/**
 * Invalidates any earlier unused reset for this user before issuing a new
 * one — same "a fresh request replaces the old one" rule members.php
 * already applies to invitations — then returns the plain token to email.
 */
function createPasswordReset(int $userId): string
{
    $platform = platformDb();
    $platform->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . PASSWORD_RESET_TTL_HOURS . ' hours'));
    $platform->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), $expiresAt]);

    return $token;
}

/**
 * @return array{id:int,user_id:int}|null null for an unknown, already-used,
 *     or expired token — never distinguished to the caller.
 */
function findValidPasswordReset(string $token): ?array
{
    $stmt = platformDb()->prepare(
        "SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now')"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sets the new password, burns the token, and signs out every existing
 * session for this user — same reasoning as settings.php's "sign out
 * everywhere": a password reset almost always means the old password (and
 * anything authenticated with it) should stop working immediately.
 */
function consumePasswordReset(int $resetId, int $userId, string $newPassword): void
{
    $platform = platformDb();
    $platform->beginTransaction();
    try {
        $platform->prepare('UPDATE accounts_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $platform->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = ?")->execute([$resetId]);
        $platform->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }
}
