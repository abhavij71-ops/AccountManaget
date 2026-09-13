<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Asia/Tehran');

define('APP_NAME', 'Account Manager');
define('APP_VERSION', '1.2.0');
define('APP_ROOT', __DIR__);
define('DATA_DIR', __DIR__ . '/data');
define('DB_PATH', DATA_DIR . '/database.sqlite');

$__docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '') : '';
$__appRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
$__base = '';
if ($__docRoot !== '' && str_starts_with($__appRoot, $__docRoot)) {
    $__base = rtrim(substr($__appRoot, strlen($__docRoot)), '/');
}
define('APP_BASE_URL', $__base);
unset($__docRoot, $__appRoot, $__base);

define('SESSION_NAME', 'am_session');
define('SESSION_LIFETIME', 60 * 60 * 8);

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
