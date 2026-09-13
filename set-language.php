<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

setLanguage((string) ($_GET['lang'] ?? ''));

$redirect = (string) ($_GET['redirect'] ?? '');
$isSafeRedirect = $redirect !== ''
    && !str_contains($redirect, '://')
    && !str_starts_with($redirect, '//')
    && !str_starts_with($redirect, '/')
    && !str_contains($redirect, '..')
    && preg_match('#^[A-Za-z0-9_\-./]+\.php(\?[^\s]*)?$#', $redirect) === 1;

header('Location: ' . appUrl($isSafeRedirect ? $redirect : 'index.php'));
exit;
