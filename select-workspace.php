<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

// Deliberately requireLogin(), not requireRole(): requireRole() redirects
// here when there's no active workspace, so gating this page behind it would
// be an infinite redirect loop. This page only needs platform identity.
requireLogin();

$redirect = isset($_GET['redirect']) ? (string) $_GET['redirect'] : '';

$membershipStmt = platformDb()->prepare(
    'SELECT m.workspace_id, m.role, w.name
     FROM memberships m
     JOIN workspaces w ON w.id = m.workspace_id
     WHERE m.user_id = ?
     ORDER BY w.name COLLATE NOCASE'
);
$membershipStmt->execute([currentUserId()]);
$workspaces = $membershipStmt->fetchAll();

// Defensive fast path: login.php only sends users here when they have more
// than one membership, but this page handles 0/1 gracefully too — e.g. a
// stale bookmark reached after being removed from every workspace but one.
if (count($workspaces) === 1) {
    $_SESSION['workspace_id'] = (int) $workspaces[0]['workspace_id'];
    $_SESSION['role'] = $workspaces[0]['role'];
    if (isWorkspaceSuspended((int) $workspaces[0]['workspace_id'])) {
        renderWorkspaceSuspendedPage((int) $workspaces[0]['workspace_id']);
    }
    header('Location: ' . appUrl(safeInternalRedirect($redirect)));
    exit;
}

$errorKey = '';
if ($workspaces && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = (string) ($_POST['redirect'] ?? '');
    $chosenId = (int) ($_POST['workspace_id'] ?? 0);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorKey = 'invalid_request';
    } else {
        $chosen = null;
        foreach ($workspaces as $w) {
            if ((int) $w['workspace_id'] === $chosenId) {
                $chosen = $w;
                break;
            }
        }
        if ($chosen === null) {
            // Never trust the posted workspace_id on its own — re-check it
            // against this user's own membership list fetched above.
            $errorKey = 'invalid_choice';
        } else {
            $_SESSION['workspace_id'] = (int) $chosen['workspace_id'];
            $_SESSION['role'] = $chosen['role'];
            if (isWorkspaceSuspended((int) $chosen['workspace_id'])) {
                renderWorkspaceSuspendedPage((int) $chosen['workspace_id']);
            }
            header('Location: ' . appUrl(safeInternalRedirect($redirect)));
            exit;
        }
    }
}

$csrf = csrfToken();
$lang = currentLanguage();

// Self-contained, not lang/*.php: this page's copy doesn't have translation
// keys yet, so it carries its own small fa/en/ar table instead of guessing
// at keys that may not exist in the shared files.
$strings = [
    'fa' => [
        'title' => 'انتخاب ورک‌اسپیس',
        'subtitle' => 'شما به بیش از یک ورک‌اسپیس دسترسی دارید. یکی را برای ادامه انتخاب کنید.',
        'continue' => 'ورود به این ورک‌اسپیس',
        'none_title' => 'دسترسی به ورک‌اسپیسی یافت نشد',
        'none_message' => 'شما در حال حاضر عضو هیچ ورک‌اسپیسی نیستید. با مدیر سیستم تماس بگیرید.',
        'logout' => 'خروج از حساب',
        'invalid_request' => 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.',
        'invalid_choice' => 'ورک‌اسپیس انتخاب‌شده معتبر نیست.',
        'roles' => ['owner' => 'مالک', 'admin' => 'مدیر', 'member' => 'عضو', 'viewer' => 'بازدیدکننده'],
    ],
    'en' => [
        'title' => 'Select a workspace',
        'subtitle' => 'You have access to more than one workspace. Pick one to continue.',
        'continue' => 'Enter this workspace',
        'none_title' => 'No workspace access',
        'none_message' => 'You are not currently a member of any workspace. Please contact your administrator.',
        'logout' => 'Log out',
        'invalid_request' => 'Invalid request. Please try again.',
        'invalid_choice' => 'The selected workspace is not valid.',
        'roles' => ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'],
    ],
    'ar' => [
        'title' => 'اختر مساحة عمل',
        'subtitle' => 'لديك حق الوصول إلى أكثر من مساحة عمل واحدة. اختر واحدة للمتابعة.',
        'continue' => 'الدخول إلى مساحة العمل هذه',
        'none_title' => 'لا يوجد وصول إلى مساحة عمل',
        'none_message' => 'أنت لست عضوًا حاليًا في أي مساحة عمل. يرجى التواصل مع المسؤول.',
        'logout' => 'تسجيل الخروج',
        'invalid_request' => 'طلب غير صالح. حاول مرة أخرى.',
        'invalid_choice' => 'مساحة العمل المختارة غير صالحة.',
        'roles' => ['owner' => 'مالك', 'admin' => 'مسؤول', 'member' => 'عضو', 'viewer' => 'مشاهد'],
    ],
];
$T = $strings[$lang] ?? $strings['fa'];
$errorMessage = $errorKey !== '' ? $T[$errorKey] : '';
$bs = currentTextDirection() === 'rtl' ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= e(currentLanguage()) ?>" dir="<?= e(currentTextDirection()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($T['title']) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:460px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e($T['title']) ?></p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!$workspaces): ?>
                <div class="alert alert-info">
                    <strong><?= e($T['none_title']) ?></strong>
                    <p class="mb-0"><?= e($T['none_message']) ?></p>
                </div>
                <a href="<?= e(appUrl('logout.php')) ?>" class="btn btn-outline-secondary w-100"><?= e($T['logout']) ?></a>
            <?php else: ?>
                <p class="text-muted small"><?= e($T['subtitle']) ?></p>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                    <div class="list-group mb-3">
                        <?php foreach ($workspaces as $w): ?>
                            <label class="list-group-item d-flex align-items-center gap-2">
                                <input type="radio" name="workspace_id" value="<?= (int) $w['workspace_id'] ?>" class="form-check-input mt-0" required>
                                <span class="flex-grow-1"><?= e($w['name']) ?></span>
                                <span class="badge bg-secondary"><?= e($T['roles'][$w['role']] ?? $w['role']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= e($T['continue']) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
