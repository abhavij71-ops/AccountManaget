<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/migrator.php';
require_once __DIR__ . '/helpers.php';

/**
 * True when the workspace is suspended (workspaces.is_suspended = 1 in the
 * platform DB, set/cleared only by admin/index.php) — the single source of
 * truth both db() (db.php) and select-workspace.php check before letting
 * anyone into the workspace's own data.
 */
function isWorkspaceSuspended(int $workspaceId): bool
{
    $stmt = platformDb()->prepare('SELECT is_suspended FROM workspaces WHERE id = ? LIMIT 1');
    $stmt->execute([$workspaceId]);
    $value = $stmt->fetchColumn();
    return $value !== false && (int) $value === 1;
}

/**
 * Renders a standalone "this workspace is suspended" page and exits —
 * never notFoundResponse()/forbiddenResponse() (a suspended workspace is
 * neither missing nor a role mismatch) and never a redirect to login.php
 * (the user stays logged in). Self-contained HTML with its own inline
 * fa/en/ar copy, same as select-workspace.php, rather than pulling in
 * includes/header.php — header.php's own nav/widgets assume a working,
 * non-suspended $pdo further down the very call stack this is guarding.
 *
 * Only an owner gets the "pay to reactivate" link to billing.php — matching
 * billing.php's own requireRole('owner') gate, and satisfying "the owner
 * may reach only billing.php while suspended" for free: billing.php never
 * calls db() itself (it only touches platformDb()), so it's the one page
 * this block never even runs in front of. A "switch workspace" link is
 * offered only when this user actually belongs to more than one workspace.
 */
function renderWorkspaceSuspendedPage(int $workspaceId): never
{
    $nameStmt = platformDb()->prepare('SELECT name FROM workspaces WHERE id = ? LIMIT 1');
    $nameStmt->execute([$workspaceId]);
    $workspaceName = (string) ($nameStmt->fetchColumn() ?: '');

    $otherStmt = platformDb()->prepare('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND workspace_id != ?');
    $otherStmt->execute([currentUserId(), $workspaceId]);
    $hasOtherWorkspaces = (int) $otherStmt->fetchColumn() > 0;

    $isOwner = currentRole() === 'owner';

    $lang = currentLanguage();
    $strings = [
        'fa' => [
            'title' => 'ورک‌اسپیس معلق شده است',
            'message' => 'ورک‌اسپیس «:name» به‌طور موقت معلق شده و در حال حاضر در دسترس نیست.',
            'owner_hint' => 'برای فعال‌سازی دوباره، هزینه اشتراک را پرداخت کنید.',
            'billing_button' => 'رفتن به صفحه پرداخت',
            'switch_button' => 'ورود به ورک‌اسپیس دیگر',
            'logout' => 'خروج از حساب',
        ],
        'en' => [
            'title' => 'Workspace suspended',
            'message' => 'The workspace ":name" has been suspended and is not currently available.',
            'owner_hint' => 'Pay to reactivate it.',
            'billing_button' => 'Go to billing',
            'switch_button' => 'Switch workspace',
            'logout' => 'Log out',
        ],
        'ar' => [
            'title' => 'مساحة العمل معلقة',
            'message' => 'تم تعليق مساحة العمل «:name» وهي غير متاحة حاليًا.',
            'owner_hint' => 'ادفع لإعادة تفعيلها.',
            'billing_button' => 'الذهاب إلى الفواتير',
            'switch_button' => 'التبديل إلى مساحة عمل أخرى',
            'logout' => 'تسجيل الخروج',
        ],
    ];
    $T = $strings[$lang] ?? $strings['fa'];
    $dir = currentTextDirection();
    $bs = $dir === 'rtl' ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
    $message = str_replace(':name', $workspaceName, $T['message']);

    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($T['title']) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL . '/assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:460px;">
        <div class="card-body p-4 text-center">
            <h1 class="h4 mb-3"><?= e($T['title']) ?></h1>
            <p class="text-muted"><?= e($message) ?></p>
            <?php if ($isOwner): ?>
                <p class="text-muted small"><?= e($T['owner_hint']) ?></p>
                <a href="<?= e(appUrl('billing.php')) ?>" class="btn btn-primary w-100 mb-2"><?= e($T['billing_button']) ?></a>
            <?php endif; ?>
            <?php if ($hasOtherWorkspaces): ?>
                <a href="<?= e(appUrl('select-workspace.php')) ?>" class="btn btn-outline-secondary w-100 mb-2"><?= e($T['switch_button']) ?></a>
            <?php endif; ?>
            <a href="<?= e(appUrl('logout.php')) ?>" class="btn btn-outline-secondary w-100"><?= e($T['logout']) ?></a>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

/**
 * The one place a workspace gets created: inserts the workspaces row
 * (name, a unique slug, db_file, owner_user_id — all NOT NULL per
 * migrations/platform/001_create_platform_schema.php), creates its SQLite
 * file at workspaceDatabasePath(), applies the core schema
 * (includes/schema.php), then brings it up to the latest version with
 * runMigrations() — the same function every existing workspace database
 * is upgraded through. Returns the new workspace's id.
 *
 * Does NOT create a memberships row — granting the owner role is the
 * caller's job (it varies: registration grants 'owner' immediately,
 * a future "create workspace from an existing account" flow might not).
 */
function createWorkspace(string $name, int $ownerUserId): int
{
    $platform = platformDb();
    $slug = generateUniqueWorkspaceSlug($platform, $name);

    $platform->beginTransaction();
    try {
        // db_file is NOT NULL + UNIQUE, but its real value (derived from
        // this row's own id via workspaceDatabasePath()) can't be known
        // until the row exists. Insert a unique placeholder, then correct
        // it once lastInsertId() is known — both inside this one
        // transaction, so nothing ever reads the placeholder value.
        $placeholder = 'pending:' . bin2hex(random_bytes(8));
        $platform->prepare(
            'INSERT INTO workspaces (name, slug, db_file, owner_user_id) VALUES (?, ?, ?, ?)'
        )->execute([$name, $slug, $placeholder, $ownerUserId]);
        $workspaceId = (int) $platform->lastInsertId();

        $platform->prepare('UPDATE workspaces SET db_file = ? WHERE id = ?')
            ->execute([basename(workspaceDatabasePath($workspaceId)), $workspaceId]);

        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }

    $workspacePath = workspaceDatabasePath($workspaceId);
    $workspaceDir = dirname($workspacePath);
    if (!is_dir($workspaceDir)) {
        mkdir($workspaceDir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $workspacePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    applyCoreSchema($pdo);
    runMigrations($pdo);

    return $workspaceId;
}

/**
 * One-time per-workspace repair: every row in the visibility-scoped tables
 * (VISIBILITY_SCOPED_TABLES, includes/helpers.php) inserted before every
 * INSERT site set owner_user_id explicitly has owner_user_id NULL — and
 * canSeeRecord() treats a NULL owner on a 'private' record as "nobody but
 * owner/admin can see it", silently hiding it from the member who marked it
 * private. The workspace's creator (workspaces.owner_user_id, platform DB)
 * is the only reasonable owner to backfill onto those rows.
 *
 * This can't be a migrations/NNN_*.php file: runMigrations() (includes/
 * migrator.php) only ever hands a migration the workspace's own $pdo, and
 * this needs platformDb() too (to look up who owns the workspace) — a
 * workspace SQLite file has no FK, and no query access at all, into the
 * platform one. So instead it runs from db() (db.php), the one function
 * that already has both the workspace PDO and, via $workspaceId, a route
 * to platformDb() — called once right after runMigrations() there.
 *
 * Guarded by a one-row marker table stored IN the workspace database
 * itself, deliberately NOT schema_version: this repairs data, it isn't a
 * schema change, and it must run exactly once per workspace ever
 * regardless of how many migrations run before or after it — tying it to
 * schema_version's version counter would re-run it (or skip it) on
 * whatever unrelated version number happened to be current the day this
 * shipped.
 */
function backfillOwnerUserId(PDO $pdo, int $workspaceId): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS owner_backfill_state (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        completed_at TEXT NOT NULL
    )');

    if ($pdo->query('SELECT 1 FROM owner_backfill_state WHERE id = 1')->fetchColumn()) {
        return;
    }

    $stmt = platformDb()->prepare('SELECT owner_user_id FROM workspaces WHERE id = ?');
    $stmt->execute([$workspaceId]);
    $ownerUserId = $stmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        if ($ownerUserId !== false) {
            foreach (VISIBILITY_SCOPED_TABLES as $table) {
                $pdo->prepare("UPDATE {$table} SET owner_user_id = ? WHERE owner_user_id IS NULL")
                    ->execute([(int) $ownerUserId]);
            }
        }
        $pdo->prepare("INSERT INTO owner_backfill_state (id, completed_at) VALUES (1, datetime('now'))")->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * ASCII-slugifies $name and appends -2, -3, ... until the result isn't
 * already taken. Falls back to "workspace" when the name has no ASCII
 * alphanumerics at all (e.g. a Persian/Arabic-only workspace name) — the
 * slug is an internal unique key here, not something shown to users, so a
 * generic fallback plus a numeric suffix is fine.
 */
function generateUniqueWorkspaceSlug(PDO $platform, string $name): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    if ($base === '') {
        $base = 'workspace';
    }

    $stmt = $platform->prepare('SELECT 1 FROM workspaces WHERE slug = ? LIMIT 1');
    $slug = $base;
    $suffix = 1;
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}
