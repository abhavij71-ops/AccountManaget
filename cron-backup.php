<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Backs up every workspace database plus the central platform database,
 * compressed, into data/backups/, then purges backups older than
 * BACKUP_RETENTION_DAYS. Meant to be triggered from outside the app — a
 * system crontab hitting this URL with curl, or a hosting provider's
 * "cron via URL" feature — since this app has no built-in scheduler yet
 * (see docs/ROADMAP-SAAS.md Phase 15). Token-protected rather than
 * session-protected: there is no logged-in user in this context at all.
 *
 * Configure by setting the CRON_BACKUP_TOKEN environment variable (see
 * config.php) to a long random value, then call this URL with either
 * ?token=<value> or an X-Cron-Token: <value> header. Treat the token as a
 * secret and prefer calling over HTTPS — it grants read access to a
 * compressed copy of every database this install holds.
 */

header('Content-Type: text/plain; charset=UTF-8');

$providedToken = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
if (CRON_BACKUP_TOKEN === '' || !hash_equals(CRON_BACKUP_TOKEN, $providedToken)) {
    http_response_code(403);
    die('Forbidden');
}

const BACKUP_RETENTION_DAYS = 30;

/**
 * Flushes WAL into the main file (same pattern as includes/migrator.php's
 * backupDatabaseFile()) via a throwaway raw connection — never db()/
 * platformDb(), since those tie to session/workspace state that doesn't
 * exist in this unauthenticated, cron-triggered context — then gzip-copies
 * the now self-consistent main file. Checkpointing first means the -wal/
 * -shm sidecar files never need to be part of the backup at all.
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
        error_log('Account Manager: cron-backup checkpoint failed for ' . $sourcePath . ': ' . $e->getMessage());
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

$backupDir = DATA_DIR . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Ymd_His');

$sources = [];
if (file_exists(DATA_DIR . '/platform.sqlite')) {
    $sources['platform'] = DATA_DIR . '/platform.sqlite';
}
foreach (glob(DATA_DIR . '/workspaces/*.sqlite') ?: [] as $workspaceFile) {
    $sources[basename($workspaceFile, '.sqlite')] = $workspaceFile;
}

echo "Backup started " . date('Y-m-d H:i:s') . "\n";

foreach ($sources as $label => $sourcePath) {
    $destPath = $backupDir . '/' . $label . '_' . $timestamp . '.sqlite.gz';
    echo (backupOneDatabase($sourcePath, $destPath) ? 'OK   ' : 'FAIL ') . $label . "\n";
}

$cutoff = time() - (BACKUP_RETENTION_DAYS * 86400);
$purged = 0;
foreach (glob($backupDir . '/*.sqlite.gz') ?: [] as $backupFile) {
    if (filemtime($backupFile) < $cutoff && unlink($backupFile)) {
        $purged++;
    }
}

echo "Purged {$purged} backup(s) older than " . BACKUP_RETENTION_DAYS . " days.\n";
echo "Done.\n";
