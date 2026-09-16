<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang.php';
requireLogin();

$user = currentUser();

$navItems = [
    ['label' => t('nav.dashboard'), 'href' => 'index.php', 'prefix' => 'index.php'],
    ['label' => t('nav.emails'), 'href' => 'modules/emails/index.php', 'prefix' => 'modules/emails/'],
    ['label' => t('nav.services'), 'href' => 'modules/services/index.php', 'prefix' => 'modules/services/'],
    ['label' => t('nav.accounts'), 'href' => 'modules/accounts/index.php', 'prefix' => 'modules/accounts/'],
    ['label' => t('nav.phones'), 'href' => 'modules/phones/index.php', 'prefix' => 'modules/phones/'],
    ['label' => t('nav.needs_attention'), 'href' => 'needs-attention.php', 'prefix' => 'needs-attention.php'],
    ['label' => t('nav.review'), 'href' => 'review.php', 'prefix' => 'review.php'],
    ['label' => t('nav.search'), 'href' => 'search.php', 'prefix' => 'search.php'],
    ['label' => t('nav.import_export'), 'href' => 'import-export.php', 'prefix' => ['import-export.php', 'modules/import/']],
    ['label' => t('nav.settings'), 'href' => 'settings.php', 'prefix' => 'settings.php'],
];

$relativeScriptPath = ltrim(substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strlen(APP_BASE_URL)), '/');
$currentRelativeUrl = $relativeScriptPath . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$flash = function_exists('flashGet') ? flashGet() : null;
$bs = currentTextDirection() === 'rtl' ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= e(currentLanguage()) ?>" dir="<?= e(currentTextDirection()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' . e(APP_NAME) : e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(appUrl('assets/css/' . $bs)) ?>">
    <link rel="stylesheet" href="<?= e(appUrl('assets/css/app.css')) ?>">
</head>
<body>
<div class="d-flex">
    <nav class="am-sidebar d-none d-md-flex flex-column p-3" style="width:250px;">
        <a href="<?= e(appUrl('index.php')) ?>" class="brand text-decoration-none fs-5 d-block">
            <?= e(APP_NAME) ?>
        </a>
        <span class="am-version text-decoration-none d-block mb-4">v<?= e(APP_VERSION) ?></span>
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
                <?= e(t('nav.menu')) ?>
            </button>
            <div class="am-quicksearch position-relative flex-grow-1 mx-3" style="max-width:320px;">
                <input type="text" id="am-quicksearch-input" class="form-control form-control-sm" autocomplete="off"
                       placeholder="<?= e(t('search.placeholder')) ?>">
                <div id="am-quicksearch-results" class="list-group position-absolute shadow-sm"
                     style="display:none; top:100%; inset-inline:0; z-index:1050; max-height:400px; overflow-y:auto;"></div>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <div class="am-lang-switch d-flex align-items-center gap-1">
                    <?php foreach (supportedLanguages() as $code => $label): ?>
                        <a href="<?= e(appUrl('set-language.php?lang=' . $code . '&redirect=' . urlencode($currentRelativeUrl))) ?>"
                           class="badge text-decoration-none <?= currentLanguage() === $code ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($user): ?>
                    <span class="text-muted"><?= e($user['full_name'] ?: $user['username']) ?></span>
                    <a href="<?= e(appUrl('logout.php')) ?>" class="btn btn-sm btn-outline-danger"><?= e(t('nav.logout')) ?></a>
                <?php endif; ?>
            </div>
        </header>

        <script>
        (function () {
            var input = document.getElementById('am-quicksearch-input');
            var resultsBox = document.getElementById('am-quicksearch-results');
            if (!input || !resultsBox) {
                return;
            }
            var noResultsTpl = <?= json_encode(t('search.no_results', ['q' => ':q'])) ?>;
            var searchUrl = <?= json_encode(appUrl('search-api.php')) ?>;
            var debounceTimer = null;
            var activeController = null;

            function hideResults() {
                resultsBox.style.display = 'none';
                resultsBox.innerHTML = '';
            }

            function renderResults(data) {
                resultsBox.innerHTML = '';
                if (!data.results || !data.results.length) {
                    var empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted small';
                    empty.textContent = noResultsTpl.replace(':q', data.q);
                    resultsBox.appendChild(empty);
                    resultsBox.style.display = '';
                    return;
                }
                var lastType = null;
                data.results.forEach(function (r) {
                    if (r.type !== lastType) {
                        var groupHeader = document.createElement('div');
                        groupHeader.className = 'list-group-item list-group-item-secondary small fw-bold py-1';
                        groupHeader.textContent = r.type_label;
                        resultsBox.appendChild(groupHeader);
                        lastType = r.type;
                    }
                    var link = document.createElement('a');
                    link.href = r.url;
                    link.className = 'list-group-item list-group-item-action py-2';
                    var title = document.createElement('div');
                    title.textContent = r.title;
                    link.appendChild(title);
                    if (r.subtitle) {
                        var subtitle = document.createElement('div');
                        subtitle.className = 'small text-muted';
                        subtitle.textContent = r.subtitle;
                        link.appendChild(subtitle);
                    }
                    resultsBox.appendChild(link);
                });
                resultsBox.style.display = '';
            }

            input.addEventListener('input', function () {
                var q = input.value.trim();
                window.clearTimeout(debounceTimer);
                if (q === '') {
                    hideResults();
                    return;
                }
                debounceTimer = window.setTimeout(function () {
                    if (activeController) {
                        activeController.abort();
                    }
                    activeController = new AbortController();
                    fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: activeController.signal })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            activeController = null;
                            renderResults(data);
                        })
                        .catch(function () {
                            activeController = null;
                        });
                }, 300);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    hideResults();
                }
            });
            document.addEventListener('click', function (e) {
                if (e.target !== input && !resultsBox.contains(e.target)) {
                    hideResults();
                }
            });
        })();
        </script>

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
