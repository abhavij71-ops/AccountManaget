<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/migrator.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/plans.php';

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
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . EMAIL_VERIFICATION_TTL_HOURS . ' hours'));
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
 * Creates the workspace's own SQLite file under data/workspaces/ and brings
 * it to the latest schema via the same runMigrations() every workspace
 * database goes through (includes/migrator.php) — a brand-new workspace is
 * not a special case, just an empty one.
 */
function createAndMigrateWorkspaceDatabase(int $workspaceId): void
{
    $workspaceDir = DATA_DIR . '/workspaces';
    if (!is_dir($workspaceDir)) {
        mkdir($workspaceDir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $workspaceDir . '/' . $workspaceId . '.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    runMigrations($pdo);
}

/**
 * Completes a verified signup. The workspace database is built FIRST,
 * outside any central-database transaction (a second SQLite file can't
 * share one) — so if that fails, the only side effect is an inert,
 * membership-less workspaces row, never an activated account pointed at a
 * workspace with no backing file. Only once it succeeds are the account
 * activated and ownership granted, atomically.
 */
function completeEmailVerification(array $verification): void
{
    $platform = platformDb();

    $platform->prepare('INSERT INTO workspaces (name) VALUES (?)')->execute([$verification['workspace_name']]);
    $workspaceId = (int) $platform->lastInsertId();

    createAndMigrateWorkspaceDatabase($workspaceId);

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
