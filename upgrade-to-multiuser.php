<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';                   // defines runMigrations() (via includes/migrator.php) — db() itself is never called below
require_once __DIR__ . '/includes/platform-db.php'; // defines platformDb()
require_once __DIR__ . '/includes/auth.php';        // csrfToken()/verifyCsrfToken()/e() only — requireLogin()/currentUser() also call db() and must not be used here

/**
 * True once a ws_000001.sqlite file exists, or the central database already
 * lists a workspace — either is proof a previous run of this script
 * finished. Calling platformDb() here also doubles as step 2 ("create the
 * central database"): its own migration is a no-op if already applied.
 */
function upgradeAlreadyApplied(): bool
{
    if (file_exists(DATA_DIR . '/workspaces/ws_000001.sqlite')) {
        return true;
    }

    try {
        $platform = platformDb();
        return ((int) $platform->query('SELECT COUNT(*) FROM workspaces')->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Opens the legacy single-tenant database directly. db() cannot be used here:
 * it now resolves the file to open from $_SESSION['workspace_id'], which does
 * not exist yet on a pre-upgrade install — this script is what creates the
 * first workspace. This is the one place outside db() allowed to touch
 * DB_PATH directly.
 */
function openLegacyDatabase(): PDO
{
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

$error = '';
$success = false;
$noLegacyDb = !file_exists(DB_PATH);
$alreadyDone = !$noLegacyDb && upgradeAlreadyApplied();

if (!$noLegacyDb && !$alreadyDone && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        $backupPath = DB_PATH . '.' . date('Ymd_His') . '.pre-multiuser.bak';
        $workspaceDir = DATA_DIR . '/workspaces';
        $workspacePath = $workspaceDir . '/ws_000001.sqlite';

        try {
            // 1. Back up the legacy database before touching anything else.
            if (!copy(DB_PATH, $backupPath)) {
                throw new RuntimeException('پشتیبان‌گیری از دیتابیس ناموفق بود.');
            }

            // Bring the legacy file's own schema fully up to date before it
            // becomes a workspace file, so ws_000001.sqlite starts out valid.
            $legacyPdo = openLegacyDatabase();
            runMigrations($legacyPdo);

            $users = $legacyPdo->query('SELECT username, password_hash, full_name, is_active, created_at FROM users')->fetchAll();
            if (count($users) === 0) {
                throw new RuntimeException('هیچ کاربری در دیتابیس فعلی یافت نشد.');
            }
            if (count($users) > 1) {
                throw new RuntimeException('بیش از یک کاربر یافت شد؛ این اسکریپت فقط برای یک کاربر مدیر طراحی شده است.');
            }
            $user = $users[0];
            $legacyPdo = null; // release the handle before the file underneath it moves

            // 4 (part one). Copy — not yet remove — database.sqlite into place
            // as the new workspace file. Copying first (instead of a rename)
            // means a failure below never leaves the legacy file missing.
            if (!is_dir($workspaceDir)) {
                mkdir($workspaceDir, 0755, true);
            }
            if (!is_dir($workspaceDir)) {
                throw new RuntimeException('ایجاد پوشه data/workspaces ناموفق بود.');
            }
            if (file_exists($workspacePath)) {
                throw new RuntimeException('فایل ورک‌اسپیس از قبل وجود دارد.');
            }
            if (!copy(DB_PATH, $workspacePath)) {
                throw new RuntimeException('کپی کردن دیتابیس به ورک‌اسپیس جدید ناموفق بود.');
            }

            // 2, 3, 5. Central database + accounts_users + workspace + membership,
            // all inside one transaction so they land together or not at all.
            $platform = platformDb();
            $platform->beginTransaction();
            try {
                $insertUser = $platform->prepare(
                    'INSERT INTO accounts_users (email, password_hash, full_name, is_active, email_verified_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insertUser->execute([
                    // The legacy `users` table has no email column — its username
                    // is carried over as-is. The admin should replace it with a
                    // real address from account settings after the upgrade.
                    $user['username'],
                    $user['password_hash'],
                    $user['full_name'],
                    (int) $user['is_active'],
                    // Grandfathered in: this admin was already trusted under the
                    // single-tenant model, so the upgrade doesn't impose a fresh
                    // email-verification step that never existed before.
                    date('Y-m-d H:i:s'),
                    $user['created_at'],
                ]);
                $accountUserId = (int) $platform->lastInsertId();

                $insertWorkspace = $platform->prepare(
                    'INSERT INTO workspaces (name, slug, db_file, owner_user_id) VALUES (?, ?, ?, ?)'
                );
                $insertWorkspace->execute(['Default Workspace', 'default', 'ws_000001.sqlite', $accountUserId]);
                $workspaceId = (int) $platform->lastInsertId();

                $insertMembership = $platform->prepare(
                    'INSERT INTO memberships (workspace_id, user_id, role) VALUES (?, ?, ?)'
                );
                $insertMembership->execute([$workspaceId, $accountUserId, 'owner']);

                $platform->commit();
            } catch (Throwable $e) {
                $platform->rollBack();
                // Undo the copy so no workspace file is left behind without a
                // matching platform record.
                if (file_exists($workspacePath)) {
                    unlink($workspacePath);
                }
                throw $e;
            }

            // 4 (part two). Only now, with the platform database durably
            // committed, remove the original file — the timestamped backup
            // from step 1 still exists regardless of what happens here.
            if (!unlink(DB_PATH)) {
                error_log('Account Manager: upgrade-to-multiuser succeeded but could not remove the old ' . DB_PATH . ' — remove it manually.');
            }

            $success = true;
        } catch (Throwable $e) {
            error_log('Account Manager: upgrade-to-multiuser failed: ' . $e->getMessage());
            $error = 'ارتقا ناموفق بود: ' . $e->getMessage() . ' هیچ تغییری اعمال نشد؛ فایل اصلی دست‌نخورده باقی ماند.';
        }
    }
}

$csrf = csrfToken();
// No language session concept applies to this bootstrap-level script — default to RTL, matching install.php.
$bs = 'bootstrap.rtl.min.css';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ارتقا به چند-کاربره | <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars('assets/css/' . $bs, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:520px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted text-center mb-4">ارتقا به نسخه چند-کاربره (Multi-Workspace)</p>

            <?php if ($noLegacyDb): ?>
                <div class="alert alert-info">
                    هیچ دیتابیس قدیمی (data/database.sqlite) پیدا نشد. چیزی برای ارتقا وجود ندارد.
                </div>
            <?php elseif ($alreadyDone && !$success): ?>
                <div class="alert alert-info">
                    ارتقا قبلاً انجام شده است. یک ورک‌اسپیس در سیستم مرکزی ثبت شده و/یا فایل
                    data/workspaces/ws_000001.sqlite از قبل وجود دارد.
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    ارتقا با موفقیت انجام شد. کاربر قبلی به accounts_users منتقل شد، ورک‌اسپیس
                    پیش‌فرض ساخته شد و data/database.sqlite به
                    data/workspaces/ws_000001.sqlite منتقل شد. یک نسخه پشتیبان از دیتابیس اصلی
                    نیز کنار آن نگه داشته شده است.
                </div>
                <p class="text-danger small mt-3 mb-0 text-center">
                    برای امنیت بیشتر، فایل upgrade-to-multiuser.php را از روی سرور حذف کنید.
                </p>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <p class="text-muted small">
                    این عملیات یک‌بار مصرف است: دیتابیس فعلی پشتیبان‌گیری می‌شود، دیتابیس مرکزی
                    ساخته می‌شود، کاربر موجود به آن منتقل می‌شود و data/database.sqlite به
                    data/workspaces/ws_000001.sqlite تبدیل می‌شود. در صورت بروز هرگونه خطا،
                    هیچ تغییری اعمال نخواهد شد و فایل اصلی دست‌نخورده باقی می‌ماند.
                </p>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-primary w-100">شروع ارتقا</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
