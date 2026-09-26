<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/platform-db.php';
require_once __DIR__ . '/includes/renewals.php';
require_once __DIR__ . '/includes/security-score.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/notify.php';

/**
 * Every nightly job, run in order and logged as one row in cron_runs
 * (central database):
 *   1. Flush the mail queue (max 50 messages — includes/mail.php's
 *      sendQueuedMail(), never more than one batch per run).
 *   2. Check each workspace's upcoming/overdue renewals (fetchRenewals(),
 *      includes/renewals.php) and queue a notification email to that
 *      workspace's owners/admins when there's something to flag. There is
 *      no dedicated notifications table in this codebase yet, so
 *      "create notifications" is implemented as queueMail() — the same
 *      queue job 1 drains — rather than inventing a new table this script
 *      can't also migrate (see note above recomputeSecurityScores() for the
 *      same reasoning applied to score storage).
 *   3. Recompute security scores (calcEmailSecurityScore()/
 *      calcPhoneSecurityScore(), includes/security-score.php) for every
 *      email/phone that has a security row, per workspace.
 *   4. Purge expired invitation tokens, idle session tokens, and old
 *      login_attempts rows from the central database.
 *   5. Run cron-backup.php's own backup+retention logic in-process.
 *
 * Invocation: `php cron.php` from a real terminal needs no token. Over
 * HTTP, pass ?token=<CRON_TOKEN> or an X-Cron-Token header — same
 * shared-secret pattern as cron-backup.php's CRON_BACKUP_TOKEN, but its own
 * separate secret (set the CRON_TOKEN environment variable) so leaking one
 * job's token doesn't hand over the other.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $cronToken = (string) (getenv('CRON_TOKEN') ?: '');
    $providedToken = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
    if ($cronToken === '' || !hash_equals($cronToken, $providedToken)) {
        http_response_code(403);
        die('Forbidden');
    }
}

/**
 * Runs $job, records whether it threw, and never lets one job's failure
 * stop the rest of the nightly run.
 */
function cronStep(array &$report, bool &$hadFailure, string $label, callable $job): void
{
    try {
        $report[$label] = ['ok' => true, 'detail' => (string) $job()];
    } catch (Throwable $e) {
        $hadFailure = true;
        $report[$label] = ['ok' => false, 'detail' => $e->getMessage()];
        error_log('Account Manager: cron.php step "' . $label . '" failed: ' . $e->getMessage());
    }
}

/**
 * A raw, throwaway connection to one workspace's SQLite file — never
 * db()/platformDb() for workspace data, since both tie to session/current-
 * workspace state that doesn't exist in this unauthenticated, cron-
 * triggered context (same reasoning as cron-backup.php's
 * backupOneDatabase()).
 */
function openWorkspaceDb(string $path): ?PDO
{
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (Throwable $e) {
        error_log('Account Manager: cron.php could not open workspace database ' . $path . ': ' . $e->getMessage());
        return null;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $col) {
        if ($col['name'] === $column) {
            return true;
        }
    }
    return false;
}

/**
 * Queues one notification email per owner/admin of the workspace this file
 * belongs to. Per-workspace SQLite files are assumed to be named
 * "{workspace id}.sqlite" (matching how currentWorkspaceId()/workspaces.id
 * are used elsewhere in the app); a non-numeric filename can't be resolved
 * back to a workspace row, so notification is skipped for it (the
 * renewals themselves are still counted — only the email step is skipped).
 */
function notifyRenewalsForWorkspace(PDO $platform, string $workspaceFileStem, array $renewals): int
{
    if (!$renewals['overdue'] && !$renewals['upcoming']) {
        return 0;
    }
    if (!ctype_digit($workspaceFileStem)) {
        return 0;
    }

    $stmt = $platform->prepare(
        "SELECT u.id, u.email FROM memberships m JOIN accounts_users u ON u.id = m.user_id
         WHERE m.workspace_id = ? AND m.role IN ('owner', 'admin')"
    );
    $stmt->execute([(int) $workspaceFileStem]);
    $recipients = $stmt->fetchAll();
    if (!$recipients) {
        return 0;
    }

    $rowsHtml = '';
    foreach ($renewals['overdue'] as $r) {
        $rowsHtml .= '<li><strong>Overdue</strong> — ' . htmlspecialchars((string) $r['service_name'], ENT_QUOTES, 'UTF-8')
            . ' (' . htmlspecialchars((string) $r['renewal_date'], ENT_QUOTES, 'UTF-8') . ')</li>';
    }
    foreach ($renewals['upcoming'] as $r) {
        $rowsHtml .= '<li>Upcoming — ' . htmlspecialchars((string) $r['service_name'], ENT_QUOTES, 'UTF-8')
            . ' (' . htmlspecialchars((string) $r['renewal_date'], ENT_QUOTES, 'UTF-8') . ')</li>';
    }
    $bodyHtml = '<p>' . count($renewals['overdue']) . ' overdue and ' . count($renewals['upcoming'])
        . ' upcoming renewal(s) in the next ' . (int) $renewals['upcoming_days'] . ' days:</p><ul>' . $rowsHtml . '</ul>';
    $subject = 'Subscription renewals need attention';

    // 'renewal' severity — the one non-critical case SMS is still allowed
    // for (includes/notify.php), on top of whatever channel each recipient
    // has chosen for themselves.
    foreach ($recipients as $recipient) {
        notifyUser((int) $recipient['id'], (string) $recipient['email'], $subject, $bodyHtml, 'renewal');
    }
    return count($recipients);
}

/**
 * Refreshes cached security-score columns from the pure calculators in
 * includes/security-score.php. Those functions only ever computed a score
 * from an in-memory array before now — there's no confirmed
 * emails.security_score/phones.security_score column in the schema this
 * script is allowed to inspect, so both are gated behind columnExists()
 * and simply no-op (report 0 updated) until such a column exists, rather
 * than guessing at a schema change here.
 */
function recomputeSecurityScores(PDO $pdo): int
{
    $updated = 0;

    if (tableExists($pdo, 'email_security') && columnExists($pdo, 'emails', 'security_score')) {
        $stmt = $pdo->query('SELECT e.id, es.* FROM emails e JOIN email_security es ON es.email_id = e.id');
        $updateStmt = $pdo->prepare('UPDATE emails SET security_score = ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $row) {
            $updateStmt->execute([calcEmailSecurityScore($row), $row['id']]);
            $updated++;
        }
    }

    if (tableExists($pdo, 'phone_security') && columnExists($pdo, 'phones', 'security_score')) {
        $stmt = $pdo->query('SELECT p.id, ps.* FROM phones p JOIN phone_security ps ON ps.phone_id = p.id');
        $updateStmt = $pdo->prepare('UPDATE phones SET security_score = ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $row) {
            $updateStmt->execute([calcPhoneSecurityScore($row), $row['id']]);
            $updated++;
        }
    }

    return $updated;
}

$platform = platformDb();
$platform->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'success', 'partial', 'failed')),
    triggered_by TEXT NOT NULL CHECK (triggered_by IN ('cli', 'http')),
    summary TEXT
)");

$platform->prepare('INSERT INTO cron_runs (started_at, triggered_by) VALUES (?, ?)')
    ->execute([date('Y-m-d H:i:s'), $isCli ? 'cli' : 'http']);
$runId = (int) $platform->lastInsertId();

$report = [];
$hadFailure = false;

// 1. Mail queue.
cronStep($report, $hadFailure, 'mail_queue', function () {
    $result = sendQueuedMail(50);
    return "sent={$result['sent']} failed={$result['failed']}" . ($result['skipped_reason'] ? ' (' . $result['skipped_reason'] . ')' : '');
});

$workspaceFiles = glob(DATA_DIR . '/workspaces/*.sqlite') ?: [];

// 2. Renewals -> notifications.
cronStep($report, $hadFailure, 'renewals', function () use ($workspaceFiles, $platform) {
    $checked = 0;
    $notified = 0;
    foreach ($workspaceFiles as $file) {
        $pdo = openWorkspaceDb($file);
        if ($pdo === null) {
            continue;
        }
        $renewals = fetchRenewals($pdo, 30);
        $notified += notifyRenewalsForWorkspace($platform, basename($file, '.sqlite'), $renewals);
        $checked++;
        $pdo = null;
    }
    return "{$checked} workspace(s) checked, {$notified} notification(s) queued";
});

// 3. Security scores.
cronStep($report, $hadFailure, 'security_scores', function () use ($workspaceFiles) {
    $updated = 0;
    foreach ($workspaceFiles as $file) {
        $pdo = openWorkspaceDb($file);
        if ($pdo === null) {
            continue;
        }
        $updated += recomputeSecurityScores($pdo);
        $pdo = null;
    }
    return "{$updated} score(s) recomputed";
});

// 4. Purge expired tokens / old login_attempts.
cronStep($report, $hadFailure, 'purge', function () use ($platform) {
    $purgedInvites = 0;
    $purgedSessions = 0;
    $purgedAttempts = 0;

    if (tableExists($platform, 'invitations')) {
        $stmt = $platform->prepare("DELETE FROM invitations WHERE accepted_at IS NULL AND expires_at < datetime('now')");
        $stmt->execute();
        $purgedInvites = $stmt->rowCount();
    }

    if (tableExists($platform, 'sessions')) {
        $stmt = $platform->prepare('DELETE FROM sessions WHERE last_seen_at < ?');
        $stmt->execute([date('Y-m-d H:i:s', time() - SESSION_LIFETIME)]);
        $purgedSessions = $stmt->rowCount();
    }

    if (tableExists($platform, 'login_attempts')) {
        $stmt = $platform->prepare('DELETE FROM login_attempts WHERE created_at < ?');
        $stmt->execute([date('Y-m-d H:i:s', strtotime('-90 days'))]);
        $purgedAttempts = $stmt->rowCount();
    }

    return "invitations={$purgedInvites} sessions={$purgedSessions} login_attempts={$purgedAttempts}";
});

// 5. Backups — cron-backup.php's own logic, reused in-process. Only
// reachable once CRON_BACKUP_TOKEN is confirmed non-empty, so the token
// check inside cron-backup.php (which die()s the whole process on
// mismatch) is guaranteed to pass with the token we hand it ourselves.
cronStep($report, $hadFailure, 'backup', function () {
    if (CRON_BACKUP_TOKEN === '') {
        return 'skipped: CRON_BACKUP_TOKEN not configured';
    }
    $_GET['token'] = CRON_BACKUP_TOKEN;
    ob_start();
    require_once __DIR__ . '/cron-backup.php';
    return trim((string) ob_get_clean());
});

$status = $hadFailure ? 'partial' : 'success';
$platform->prepare('UPDATE cron_runs SET finished_at = ?, status = ?, summary = ? WHERE id = ?')
    ->execute([date('Y-m-d H:i:s'), $status, json_encode($report), $runId]);

foreach ($report as $label => $result) {
    echo ($result['ok'] ? 'OK   ' : 'FAIL ') . str_pad($label, 18) . $result['detail'] . "\n";
}
echo "Run #{$runId}: {$status}\n";

exit($hadFailure ? 1 : 0);
