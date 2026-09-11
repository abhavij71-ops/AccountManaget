<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireLogin();

$user = currentUser();

$navItems = [
    ['label' => 'داشبورد', 'href' => 'index.php', 'prefix' => 'index.php'],
    ['label' => 'ایمیل‌ها', 'href' => 'modules/emails/index.php', 'prefix' => 'modules/emails/'],
    ['label' => 'سرویس‌ها', 'href' => 'modules/services/index.php', 'prefix' => 'modules/services/'],
    ['label' => 'اکانت‌ها', 'href' => 'modules/accounts/index.php', 'prefix' => 'modules/accounts/'],
    ['label' => 'شماره تلفن‌ها', 'href' => 'modules/phones/index.php', 'prefix' => 'modules/phones/'],
    ['label' => 'نیازمند بررسی', 'href' => 'needs-attention.php', 'prefix' => 'needs-attention.php'],
    ['label' => 'جستجو', 'href' => 'search.php', 'prefix' => 'search.php'],
    ['label' => 'ورود / خروجی اطلاعات', 'href' => 'import-export.php', 'prefix' => ['import-export.php', 'modules/import/']],
    ['label' => 'تنظیمات', 'href' => 'settings.php', 'prefix' => 'settings.php'],
];

$relativeScriptPath = ltrim(substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strlen(APP_BASE_URL)), '/');
$flash = function_exists('flashGet') ? flashGet() : null;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' . e(APP_NAME) : e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(appUrl('assets/css/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(appUrl('assets/css/app.css')) ?>">
</head>
<body>
<div class="d-flex">
    <nav class="am-sidebar d-none d-md-flex flex-column p-3" style="width:250px;">
        <a href="<?= e(appUrl('index.php')) ?>" class="brand text-decoration-none fs-5 mb-4 d-block">
            <?= e(APP_NAME) ?>
        </a>
        <ul class="nav nav-pills flex-column">
            <?php foreach ($navItems as $item): ?>
                <?php $isActive = navItemIsActive($relativeScriptPath, $item['prefix']); ?>
                <li class="nav-item">
                    <a href="<?= e(appUrl($item['href'])) ?>" class="nav-link<?= $isActive ? ' active' : '' ?>">
                        <?= e($item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="flex-grow-1">
        <header class="am-topbar d-flex align-items-center justify-content-between px-4 py-2">
            <button class="btn btn-outline-secondary d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#am-mobile-nav">
                منو
            </button>
            <div class="ms-auto d-flex align-items-center gap-3">
                <?php if ($user): ?>
                    <span class="text-muted"><?= e($user['full_name'] ?: $user['username']) ?></span>
                    <a href="<?= e(appUrl('logout.php')) ?>" class="btn btn-sm btn-outline-danger">خروج</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="offcanvas offcanvas-start am-sidebar" tabindex="-1" id="am-mobile-nav">
            <div class="offcanvas-header">
                <span class="brand"><?= e(APP_NAME) ?></span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body">
                <ul class="nav nav-pills flex-column">
                    <?php foreach ($navItems as $item): ?>
                        <?php $isActive = navItemIsActive($relativeScriptPath, $item['prefix']); ?>
                        <li class="nav-item">
                            <a href="<?= e(appUrl($item['href'])) ?>" class="nav-link<?= $isActive ? ' active' : '' ?>">
                                <?= e($item['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <main class="am-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
