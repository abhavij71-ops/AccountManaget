<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * The single place any API key/password in this app is read from — an
 * environment variable first, then a fallback file that lives OUTSIDE the
 * web root (spec: "API keys in an environment variable or a file outside
 * the webroot, never in code"). The fallback file is plain PHP returning
 * an array, e.g.:
 *
 *   <?php
 *   return [
 *       'ZARINPAL_MERCHANT_ID' => '...',
 *       'ADMIN_PASSWORD' => '...',
 *   ];
 *
 * kept one directory ABOVE APP_ROOT (config.php) — never inside it, so no
 * web server configuration change could ever make it reachable over HTTP.
 */
function loadSecret(string $key): string
{
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    static $fileSecrets = null;
    if ($fileSecrets === null) {
        $path = dirname(APP_ROOT) . '/account-manager-secrets.php';
        $fileSecrets = is_file($path) ? (array) require $path : [];
    }

    return (string) ($fileSecrets[$key] ?? '');
}
