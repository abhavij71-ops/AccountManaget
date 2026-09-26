<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/secrets.php';
require_once __DIR__ . '/../includes/login-lockout.php';

/**
 * Platform-operator access, deliberately independent of includes/auth.php's
 * accounts_users/workspace login — this must keep working (and keep
 * denying) regardless of who is or isn't logged into any tenant workspace.
 * A single shared password (ADMIN_PASSWORD, via includes/secrets.php —
 * never hardcoded) rather than its own user table: there is exactly one
 * operator role here, not many.
 */
function isAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function requireAdminAuth(): void
{
    if (!isAdminAuthenticated()) {
        header('Location: ' . APP_BASE_URL . '/admin/login.php');
        exit;
    }
}

function adminCsrfToken(): string
{
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

function verifyAdminCsrfToken(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['admin_csrf_token']) && hash_equals($_SESSION['admin_csrf_token'], $token);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * @return string[] names of required secrets (loadSecret(), includes/
 *     secrets.php) that are still empty — surfaced as a dashboard warning
 *     since each one silently disables a whole feature otherwise (cron
 *     requests refused, SMTP/SMS password can't be saved, ZarinPal
 *     payments can't start) rather than erroring loudly anywhere obvious.
 *     ADMIN_PASSWORD is deliberately excluded: if it were empty, this
 *     admin session could never have been authenticated in the first
 *     place, so reaching this code already proves it's set.
 */
function missingSecrets(): array
{
    $required = ['CRON_TOKEN', 'MAIL_ENCRYPTION_KEY', 'ZARINPAL_MERCHANT_ID'];
    $missing = [];
    foreach ($required as $key) {
        if (loadSecret($key) === '') {
            $missing[] = $key;
        }
    }
    return $missing;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    return number_format($value, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
}
