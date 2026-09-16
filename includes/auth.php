<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function currentUser(): ?array
{
    $id = currentUserId();
    if ($id === null) {
        return null;
    }

    static $cache = null;
    if ($cache !== null && $cache['id'] === $id) {
        return $cache;
    }

    $stmt = db()->prepare('SELECT id, username, full_name, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    $cache = $user ?: null;
    return $cache;
}

/**
 * Gate for every protected page: requires a logged-in session AND a still-
 * existing, still-active user row behind it. The second check closes the gap
 * where a deactivated/deleted account keeps a fully valid session until it
 * happens to log out — currentUser() is re-checked on every request instead.
 * It reuses currentUser()'s own static cache, so a caller that also calls
 * currentUser() afterwards (e.g. settings.php) does not trigger a second query.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $requestPath = $_SERVER['REQUEST_URI'] ?? '/';
        $basePrefix = (APP_BASE_URL !== '' ? APP_BASE_URL : '') . '/';
        $relativePath = str_starts_with($requestPath, $basePrefix)
            ? substr($requestPath, strlen($basePrefix))
            : ltrim($requestPath, '/');
        header('Location: ' . appUrl('login.php?redirect=' . urlencode($relativePath)));
        exit;
    }

    if (currentUser() === null) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        flashSet('danger', t('login.account_disabled'));
        header('Location: ' . appUrl('login.php'));
        exit;
    }
}

function appUrl(string $path = ''): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Whitelist-validates a same-app redirect target: relative "*.php" paths
 * only, with an optional query string. Rejects absolute URLs, scheme-relative
 * (//host), a leading slash, and path traversal — the open-redirect vectors
 * (login.php?redirect=https://evil.com and variants) — falling back to
 * $fallback on anything that doesn't match. Callers still wrap the result in
 * appUrl() themselves, same as before this was extracted.
 */
function safeInternalRedirect(string $path, string $fallback = 'index.php'): string
{
    $isSafe = $path !== ''
        && !str_contains($path, '://')
        && !str_starts_with($path, '//')
        && !str_starts_with($path, '/')
        && !str_contains($path, '..')
        && preg_match('#^[A-Za-z0-9_\-./]+\.php(\?[^\s]*)?$#', $path) === 1;

    return $isSafe ? $path : $fallback;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
