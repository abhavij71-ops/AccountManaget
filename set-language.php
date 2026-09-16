<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

setLanguage((string) ($_GET['lang'] ?? ''));

$redirect = (string) ($_GET['redirect'] ?? '');
header('Location: ' . appUrl(safeInternalRedirect($redirect)));
exit;
