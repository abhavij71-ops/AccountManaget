<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/mail.php';

const PASSWORD_RESET_TTL_HOURS = 2;

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
