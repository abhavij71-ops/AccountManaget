<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Asia/Tehran');

define('APP_NAME', 'Account Manager');
define('APP_VERSION', '2.2.0');
define('APP_DEBUG', false);
define('APP_ROOT', __DIR__);

// DATA_DIR is configurable — an environment variable, or a 'DATA_DIR' entry
// in account-manager-secrets.php (includes/secrets.php), same as every
// other secret — defaulting to APP_ROOT/data exactly as before. This is
// what lets DATA_DIR move outside the web root entirely on a host where
// .htaccess can't be relied on to protect it (see docs/INSTALLATION.md's
// "Nginx" and "moving data/ outside the web root" sections). Every path
// under data/ anywhere else in this app derives from this one constant.
require_once __DIR__ . '/includes/secrets.php';
$__dataDir = rtrim(loadSecret('DATA_DIR'), '/\\');
define('DATA_DIR', $__dataDir !== '' ? $__dataDir : APP_ROOT . '/data');
unset($__dataDir);

define('DB_PATH', DATA_DIR . '/database.sqlite');

/**
 * Exposure self-check (VERIFIED bug: on a server that ignores .htaccess,
 * data/platform.sqlite and every workspace's .sqlite file downloaded with
 * HTTP 200 for an anonymous visitor). Writes a random token under DATA_DIR,
 * then tries to fetch it back over HTTP through the app's own base URL —
 * a token that comes back means the web server is NOT blocking data/.
 *
 * Returns 'unknown' rather than a false "safe" whenever the loopback
 * request itself can't be trusted: no HTTP client available, the request
 * errored/timed out (common on shared hosting that blocks outbound
 * loopback requests), or DATA_DIR has been moved outside APP_ROOT
 * entirely — in which case there is no URL under this app that could ever
 * serve it, so it's unconditionally safe and the probe is skipped rather
 * than attempted.
 *
 * @return 'exposed'|'protected'|'unknown'
 */
function checkDataDirExposure(): string
{
    $appRootReal = realpath(APP_ROOT);
    $dataDirReal = realpath(DATA_DIR);
    if ($appRootReal === false || $dataDirReal === false) {
        return 'unknown';
    }
    if (!str_starts_with($dataDirReal . DIRECTORY_SEPARATOR, $appRootReal . DIRECTORY_SEPARATOR)) {
        return 'protected';
    }

    $token = bin2hex(random_bytes(16));
    $probeFile = DATA_DIR . '/.probe';
    if (@file_put_contents($probeFile, $token) === false) {
        return 'unknown';
    }

    $relativePath = str_replace('\\', '/', ltrim(substr($dataDirReal, strlen($appRootReal)), '\\/'));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = '/' . trim(APP_BASE_URL, '/') . '/' . $relativePath . '/.probe';
    $path = (string) preg_replace('#/{2,}#', '/', $path);
    $probeUrl = $scheme . '://' . $host . $path;

    $body = false;
    $failed = true;
    if (function_exists('curl_init')) {
        $ch = curl_init($probeUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $failed = curl_errno($ch) !== 0 || $body === false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['timeout' => 3, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($probeUrl, false, $context);
        $failed = $body === false;
    }

    @unlink($probeFile);

    if ($failed) {
        return 'unknown';
    }

    return trim((string) $body) === $token ? 'exposed' : 'protected';
}

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
// panel password, the ZarinPal merchant id, and (above) DATA_DIR itself are
// no longer read here with getenv() only — shared hosting usually can't set
// environment variables at all, which meant every cron call was refused and
// an SMTP password could never be saved. All five are read through
// loadSecret() (includes/secrets.php) at the point each is actually used: an
// environment variable first, falling back to account-manager-secrets.php
// one directory above APP_ROOT. See docs/SECRETS.md.

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
