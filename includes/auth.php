<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/helpers.php';

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * $_SESSION['user_id'] now identifies a row in the central accounts_users
 * table (includes/platform-db.php), not the old per-workspace `users` table —
 * platform identity is authenticated once, independent of which workspace (if
 * any) is currently selected. Queried against platformDb() specifically so
 * this keeps working on pages like select-workspace.php, reached before any
 * workspace_id exists in the session.
 */
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

    $stmt = platformDb()->prepare('SELECT id, email, full_name, is_active FROM accounts_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    $cache = $user ?: null;
    return $cache;
}

function currentWorkspaceId(): ?int
{
    return isset($_SESSION['workspace_id']) ? (int) $_SESSION['workspace_id'] : null;
}

/**
 * The current user's role in the active workspace, re-verified against
 * memberships on every request (same freshness guarantee requireLogin()
 * already gives currentUser()'s is_active check) rather than trusting the
 * value cached in the session at login/workspace-selection time — so a role
 * change or removal from the workspace takes effect immediately, not just on
 * the next login. Null when there's no logged-in user, no active workspace,
 * or the membership no longer exists.
 */
function currentRole(): ?string
{
    $userId = currentUserId();
    $workspaceId = currentWorkspaceId();
    if ($userId === null || $workspaceId === null) {
        return null;
    }

    static $cache = null;
    if ($cache !== null && $cache['user_id'] === $userId && $cache['workspace_id'] === $workspaceId) {
        return $cache['role'];
    }

    $stmt = platformDb()->prepare('SELECT role FROM memberships WHERE user_id = ? AND workspace_id = ? LIMIT 1');
    $stmt->execute([$userId, $workspaceId]);
    $role = $stmt->fetchColumn();

    $cache = ['user_id' => $userId, 'workspace_id' => $workspaceId, 'role' => $role !== false ? $role : null];
    return $cache['role'];
}

/**
 * Gate for every protected page: requires a logged-in session AND a still-
 * existing, still-active accounts_users row behind it. The second check
 * closes the gap where a deactivated/deleted account keeps a fully valid
 * session until it happens to log out — currentUser() is re-checked on every
 * request instead. It reuses currentUser()'s own static cache, so a caller
 * that also calls currentUser() afterwards (e.g. settings.php) does not
 * trigger a second query. Deliberately workspace-agnostic — it only asserts
 * platform identity; requireRole() below is what additionally requires an
 * active workspace.
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

/**
 * Gate for pages that additionally require an active workspace and (when
 * $roles is non-empty) one of the given roles in it — e.g.
 * requireRole('owner', 'admin'). Call requireRole() with no arguments to
 * require only "is a member of some active workspace," any role.
 *
 * A missing workspace_id or a membership that no longer exists (revoked
 * after the session picked it, or the workspace was deleted) both send the
 * user back to select-workspace.php rather than failing hard — self-healing
 * instead of trapping them behind an access decision that changed under
 * them. Only an actual role mismatch is a hard 403.
 */
function requireRole(string ...$roles): void
{
    requireLogin();

    if (currentWorkspaceId() === null) {
        header('Location: ' . appUrl('select-workspace.php'));
        exit;
    }

    $role = currentRole();
    if ($role === null) {
        unset($_SESSION['workspace_id'], $_SESSION['role']);
        header('Location: ' . appUrl('select-workspace.php'));
        exit;
    }

    if ($roles !== [] && !in_array($role, $roles, true)) {
        http_response_code(403);
        die('شما دسترسی لازم برای مشاهده این صفحه را ندارید.');
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
