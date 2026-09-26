<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/login-lockout.php';

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

    trackActiveSession();
}

/**
 * Lazily creates and keeps alive this browser's row in the central sessions
 * table — deliberately folded into requireLogin() itself (called by every
 * protected page already) rather than needing login.php/logout.php to
 * change too. The plain device token lives only in $_SESSION; the table
 * stores a SHA-256 hash of it. SHA-256, not password_hash(), is the correct
 * choice here: the token is a 256-bit random value, not a low-entropy
 * secret a human chose, so a fast hash is fine and avoids bcrypt overhead
 * on every single request.
 *
 * A row that's disappeared (deleted by "sign out everywhere" from another
 * device) signs this device out too, the same way requireLogin() already
 * reacts to a deactivated account.
 */
function trackActiveSession(): void
{
    $userId = currentUserId();
    if ($userId === null) {
        return;
    }

    $platform = platformDb();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (empty($_SESSION['device_token'])) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['device_token'] = $token;
        $platform->prepare(
            'INSERT INTO sessions (user_id, token_hash, ip, user_agent) VALUES (?, ?, ?, ?)'
        )->execute([$userId, hash('sha256', $token), $ip, $userAgent]);
        return;
    }

    $tokenHash = hash('sha256', (string) $_SESSION['device_token']);
    $stmt = $platform->prepare('SELECT id FROM sessions WHERE user_id = ? AND token_hash = ? LIMIT 1');
    $stmt->execute([$userId, $tokenHash]);
    $sessionId = $stmt->fetchColumn();

    if ($sessionId === false) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        flashSet('danger', tOr('login.session_revoked', 'You were signed out, possibly from another device.'));
        header('Location: ' . appUrl('login.php'));
        exit;
    }

    $platform->prepare("UPDATE sessions SET ip = ?, user_agent = ?, last_seen_at = datetime('now') WHERE id = ?")
        ->execute([$ip, $userAgent, (int) $sessionId]);
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
        forbiddenResponse();
    }
}

/**
 * Renders a real HTTP 403 exactly the way requireRole() always has —
 * pulled out into its own function so requireWriteAccess() and
 * requireEditRecord() (includes/helpers.php) reuse the identical response
 * instead of duplicating these two lines a third and fourth time.
 */
function forbiddenResponse(): void
{
    http_response_code(403);
    die('شما دسترسی لازم برای مشاهده این صفحه را ندارید.');
}

/**
 * "create record" row of the permission matrix — docs/PERMISSIONS.md is the
 * source of truth this and every other permission function here implements.
 * Everyone but a viewer may write (and no recognized role at all fails
 * closed, same as every other currentRole() caller). This only answers
 * "may this user create/write at all" — whether they may write to one
 * SPECIFIC existing record is the separate, narrower question
 * canEditRecord() (includes/helpers.php) answers.
 */
function canWrite(): bool
{
    $role = currentRole();
    return $role === 'owner' || $role === 'admin' || $role === 'member';
}

/**
 * Gate for endpoints that create or otherwise write with no existing record
 * to check ownership against (add.php, bulk-assign.php, create-inline.php,
 * import) — refuses a viewer with the same 403 requireRole() uses. See
 * docs/PERMISSIONS.md. Call after requireRole() has already established a
 * logged-in user in an active workspace; this only adds the write check on
 * top of that, it does not re-verify login or workspace membership itself.
 */
function requireWriteAccess(): void
{
    if (!canWrite()) {
        forbiddenResponse();
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

// LOGIN_LOCKOUT_MAX_ATTEMPTS, isLoginLocked(), recordLoginAttempt(), etc.
// moved to includes/login-lockout.php (required above) so admin/login.php
// can reuse the exact same login_attempts-backed lockout without pulling in
// this entire file — it redeclares its own e() (admin/_guard.php), which
// would fatal if this file's own e() below loaded alongside it.

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
