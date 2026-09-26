<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Asia/Tehran');

define('APP_NAME', 'Account Manager');
define('APP_VERSION', '2.1.0');
define('APP_DEBUG', false);
define('APP_ROOT', __DIR__);
define('DATA_DIR', __DIR__ . '/data');
define('DB_PATH', DATA_DIR . '/database.sqlite');

/**
 * The single source of truth for where workspace $id's SQLite file lives
 * on disk — "ws_" + a 6-digit zero-padded id, matching the naming db()
 * has always actually used. Every other place that built this path by
 * hand (includes/workspaces.php, includes/plans.php, admin/index.php,
 * settings.php) must call this instead: several of them had drifted to a
 * bare "<id>.sqlite", silently pointing at a file db() would never open.
 * Defined here, not in db.php or an includes/ file, so it's available
 * everywhere config.php already is — which is everywhere — with no new
 * require_once needed at any call site.
 */
function workspaceDatabasePath(int $workspaceId): string
{
    return DATA_DIR . '/workspaces/ws_' . str_pad((string) $workspaceId, 6, '0', STR_PAD_LEFT) . '.sqlite';
}

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

// Read once, reused below for both the session cookie's Secure flag and the
// HSTS header — HTTPS-detection logic lives in exactly one place.
$__isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

// Security headers, sent on every request (this file is the one thing every
// entry point requires first) rather than per-page.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// default-src 'self' as the baseline, with exactly two allowances beyond
// it: 'unsafe-inline' on script-src and style-src, because this app uses
// inline <script> blocks (e.g. includes/header.php's quick-search, several
// modules/*/edit.php pages) and inline style="..." attributes in a number
// of places, with no nonce/hash infrastructure to avoid it. Everything
// else — images, fonts, XHR/fetch targets, form submissions, frames,
// <base>, and plugins/objects — stays same-origin or is refused outright.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
if ($__isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// cron.php's shared token, the SMTP/SMS-secret encryption key, the admin
// panel password, and the ZarinPal merchant id are no longer read here with
// getenv() only — shared hosting usually can't set environment variables at
// all, which meant every cron call was refused and an SMTP password could
// never be saved. All four are read through loadSecret() (includes/
// secrets.php) at the point each is actually used: an environment variable
// first, falling back to account-manager-secrets.php one directory above
// APP_ROOT. See docs/SECRETS.md.

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $__isHttps,
    ]);
    session_start();
}
unset($__isHttps);
