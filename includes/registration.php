<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/workspaces.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/helpers.php';

const EMAIL_VERIFICATION_TTL_HOURS = 24;

/**
 * Creates the accounts_users row — inactive until the email is verified —
 * plus a pending email_verifications row remembering the desired workspace
 * name for later, and queues the verification email. Returns the new
 * user's id.
 */
function registerPendingUser(string $email, string $password, string $fullName, string $workspaceName): int
{
    $platform = platformDb();
    $platform->prepare('INSERT INTO accounts_users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 0)')
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $fullName]);
    $userId = (int) $platform->lastInsertId();

    $token = bin2hex(random_bytes(32));
    $expiresAt = dbNow('+' . EMAIL_VERIFICATION_TTL_HOURS . ' hours');
    $platform->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, workspace_name, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$userId, hash('sha256', $token), $workspaceName, $expiresAt]);

    $verifyUrl = appUrl('verify-email.php?token=' . $token);
    queueMail(
        $email,
        t('register.verification_email_subject'),
        '<p>' . e(t('register.verification_email_intro')) . '</p>'
        . '<p><a href="' . e($verifyUrl) . '">' . e($verifyUrl) . '</a></p>'
        . '<p>' . e(t('register.verification_email_expiry', ['hours' => EMAIL_VERIFICATION_TTL_HOURS])) . '</p>'
    );

    return $userId;
}

/**
 * @return array{id:int,user_id:int,workspace_name:string}|null null for an
 *     unknown, already-used, or expired token — callers must show the same
 *     generic message for all three so this can't be used to probe tokens.
 */
function findValidEmailVerification(string $token): ?array
{
    $stmt = platformDb()->prepare(
        "SELECT id, user_id, workspace_name FROM email_verifications
         WHERE token_hash = ? AND verified_at IS NULL AND expires_at > datetime('now')"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Same shape as findValidEmailVerification() but keyed by id and with NO
 * expiry check — this is what backs the admin panel's manual "Approve"
 * button (admin/users.php), so a broken mail setup (the token email never
 * arrived, or arrived after expiring) can never permanently lock someone
 * out. Still requires verified_at IS NULL, so an already-completed signup
 * can't be re-approved.
 */
function findPendingEmailVerificationById(int $id): ?array
{
    $stmt = platformDb()->prepare(
        'SELECT id, user_id, workspace_name FROM email_verifications WHERE id = ? AND verified_at IS NULL'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Self-service registration (register.php) is off by default and can only
 * be switched on from the admin panel, and only immediately after a
 * successful SMTP test send (admin/settings.php) — enforced there, not
 * here; this is just the stored flag both sides read/write.
 */
function isRegistrationEnabled(): bool
{
    return getAppSetting('registration_enabled', '0') === '1';
}

/**
 * Completes a verified signup: createWorkspace() (includes/workspaces.php)
 * inserts the workspaces row (name, slug, db_file, owner_user_id — all
 * NOT NULL) and builds its database, all as one step, before anything in
 * the central database is touched for the account itself — so if it
 * fails, the only side effect is an inert workspace with no membership
 * yet, never an activated account pointed at a workspace with no backing
 * file. Only once it succeeds are the account activated and ownership
 * granted, atomically.
 */
function completeEmailVerification(array $verification): void
{
    $platform = platformDb();

    $workspaceId = createWorkspace($verification['workspace_name'], $verification['user_id']);

    // Every workspace defaults to the 'free' plan (see the plans migration)
    // — checked here too, not just at invite/add-account time, so a plan
    // whose limit somehow can't fit even a single owner never silently
    // grants membership anyway.
    if (!checkPlanLimit('members', $workspaceId)) {
        throw new RuntimeException('New workspace already exceeds its plan\'s member limit.');
    }

    $platform->beginTransaction();
    try {
        $platform->prepare('UPDATE accounts_users SET is_active = 1 WHERE id = ?')->execute([$verification['user_id']]);
        $platform->prepare("INSERT INTO memberships (workspace_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$workspaceId, $verification['user_id']]);
        $platform->prepare("UPDATE email_verifications SET verified_at = datetime('now') WHERE id = ?")
            ->execute([$verification['id']]);
        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }
}
