<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

unset($_SESSION['admin_authenticated']);
header('Location: ' . APP_BASE_URL . '/admin/login.php');
exit;
