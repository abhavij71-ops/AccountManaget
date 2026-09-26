<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/secrets.php';
require_once __DIR__ . '/includes/platform-db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/renewals.php';
require_once __DIR__ . '/includes/security-score.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/notify.php';

/**
 * Every nightly job, run in order and logged as one row in cron_runs
 * (central database). This used to be three separate scripts — cron.php,
 * cron-backup.php, cron-mail.php — each gated by its own getenv()-only
 * token, which meant every one of them was permanently unusable on any
 * host that can't set environment variables (most shared hosting). Merged
 * into this one file with one token, read through loadSecret()
 * (includes/secrets.php) so it also works from account-manager-secrets.php
 * — see docs/SECRETS.md. Safe to merge now that includes/migrator.php's
 * loadMigrations() caches per process: this script opens many workspace
 * databases in a single PHP process, and without that cache a second
 * workspace's migration run would re-`require` every migration file and
 * fatal on any one that declares a top-level named function.
 *
 *   1. Flush the mail queue (max 50 messages — sendQueuedMail()). This
 *      alone was cron-mail.php's entire job; that file added nothing this
 *      step didn't already do, so it's gone with no replacement needed.
 *   2. Check each workspace's upcoming/overdue renewals and queue a
 *      notification to that workspace's owners/admins.
 *   3. Recompute security scores per workspace.
 *   4. Purge expired invitation tokens, idle session tokens, and old
 *      login_attempts rows from the central database.
 *   5. Back up every workspace database plus the central platform
 *      database, compressed, into data/backups/, then purge backups older
 *      than BACKUP_RETENTION_DAYS — cron-backup.php's logic, now native
 *      code here instead of a separate script this file had to hand its
 *      own token to just to get past that script's own gate.
 *
 * Invocation: `php cron.php` from a real terminal needs no token. Over
 * HTTP, pass ?token=<CRON_TOKEN> or an X-Cron-Token header.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $cronToken = loadSecret('CRON_TOKEN');
    $providedToken = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
    if ($cronToken === '' || !hash_equals($cronToken, $providedToken)) {
        http_response_code(403);
        die('Forbidden');
    }
}

const BACKUP_RETENTION_DAYS = 30;

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
 * triggered context.
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
 * includes/security-score.php. Gated behind columnExists() and simply
 * no-ops (reports 0 updated) until such a column exists.
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

/**
 * Flushes WAL into the main file via a throwaway raw connection, then
 * gzip-copies the now self-consistent file. Checkpointing first means the
 * -wal/-shm sidecar files never need to be part of the backup at all.
 * Folded in from the old cron-backup.php verbatim.
 */
function backupOneDatabase(string $sourcePath, string $destPathGz): bool
{
    if (!file_exists($sourcePath)) {
        return false;
    }

    try {
        $pdo = new PDO('sqlite:' . $sourcePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        error_log('Account Manager: cron.php backup checkpoint failed for ' . $sourcePath . ': ' . $e->getMessage());
        return false;
    } finally {
        $pdo = null; // release the connection/lock before copying the file below
    }

    $source = fopen($sourcePath, 'rb');
    if ($source === false) {
        return false;
    }
    $dest = gzopen($destPathGz, 'wb9');
    if ($dest === false) {
        fclose($source);
        return false;
    }
    while (!feof($source)) {
        $chunk = fread($source, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        gzwrite($dest, $chunk);
    }
    fclose($source);
    gzclose($dest);
    return true;
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
    ->execute([dbNow(), $isCli ? 'cli' : 'http']);
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
        $renewals = fetchRenewals($pdo, 30, false);
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
        $stmt->execute([dbNow('-' . SESSION_LIFETIME . ' seconds')]);
        $purgedSessions = $stmt->rowCount();
    }

    if (tableExists($platform, 'login_attempts')) {
        $stmt = $platform->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $stmt->execute([dbNow('-90 days')]);
        $purgedAttempts = $stmt->rowCount();
    }

    return "invitations={$purgedInvites} sessions={$purgedSessions} login_attempts={$purgedAttempts}";
});

// 5. Backups (formerly cron-backup.php) — every workspace file plus the
// central platform database, gzip-compressed, with old backups purged.
cronStep($report, $hadFailure, 'backup', function () use ($workspaceFiles) {
    $backupDir = DATA_DIR . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $sources = [];
    if (file_exists(DATA_DIR . '/platform.sqlite')) {
        $sources['platform'] = DATA_DIR . '/platform.sqlite';
    }
    foreach ($workspaceFiles as $workspaceFile) {
        $sources[basename($workspaceFile, '.sqlite')] = $workspaceFile;
    }

    $okCount = 0;
    $failCount = 0;
    foreach ($sources as $label => $sourcePath) {
        $destPath = $backupDir . '/' . $label . '_' . $timestamp . '.sqlite.gz';
        if (backupOneDatabase($sourcePath, $destPath)) {
            $okCount++;
        } else {
            $failCount++;
        }
    }

    $cutoff = time() - (BACKUP_RETENTION_DAYS * 86400);
    $purged = 0;
    foreach (glob($backupDir . '/*.sqlite.gz') ?: [] as $backupFile) {
        if (filemtime($backupFile) < $cutoff && unlink($backupFile)) {
            $purged++;
        }
    }

    return "{$okCount} backed up, {$failCount} failed, {$purged} old backup(s) purged";
});

$status = $hadFailure ? 'partial' : 'success';
$platform->prepare('UPDATE cron_runs SET finished_at = ?, status = ?, summary = ? WHERE id = ?')
    ->execute([dbNow(), $status, json_encode($report), $runId]);

foreach ($report as $label => $result) {
    echo ($result['ok'] ? 'OK   ' : 'FAIL ') . str_pad($label, 18) . $result['detail'] . "\n";
}
echo "Run #{$runId}: {$status}\n";

exit($hadFailure ? 1 : 0);
