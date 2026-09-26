<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/migrator.php';

/**
 * The one place a workspace gets created: inserts the workspaces row
 * (name, a unique slug, db_file, owner_user_id — all NOT NULL per
 * migrations/platform/001_create_platform_schema.php), creates its SQLite
 * file at workspaceDatabasePath(), applies the core schema
 * (includes/schema.php), then brings it up to the latest version with
 * runMigrations() — the same function every existing workspace database
 * is upgraded through. Returns the new workspace's id.
 *
 * Does NOT create a memberships row — granting the owner role is the
 * caller's job (it varies: registration grants 'owner' immediately,
 * a future "create workspace from an existing account" flow might not).
 */
function createWorkspace(string $name, int $ownerUserId): int
{
    $platform = platformDb();
    $slug = generateUniqueWorkspaceSlug($platform, $name);

    $platform->beginTransaction();
    try {
        // db_file is NOT NULL + UNIQUE, but its real value (derived from
        // this row's own id via workspaceDatabasePath()) can't be known
        // until the row exists. Insert a unique placeholder, then correct
        // it once lastInsertId() is known — both inside this one
        // transaction, so nothing ever reads the placeholder value.
        $placeholder = 'pending:' . bin2hex(random_bytes(8));
        $platform->prepare(
            'INSERT INTO workspaces (name, slug, db_file, owner_user_id) VALUES (?, ?, ?, ?)'
        )->execute([$name, $slug, $placeholder, $ownerUserId]);
        $workspaceId = (int) $platform->lastInsertId();

        $platform->prepare('UPDATE workspaces SET db_file = ? WHERE id = ?')
            ->execute([basename(workspaceDatabasePath($workspaceId)), $workspaceId]);

        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }

    $workspacePath = workspaceDatabasePath($workspaceId);
    $workspaceDir = dirname($workspacePath);
    if (!is_dir($workspaceDir)) {
        mkdir($workspaceDir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $workspacePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    applyCoreSchema($pdo);
    runMigrations($pdo);

    return $workspaceId;
}

/**
 * ASCII-slugifies $name and appends -2, -3, ... until the result isn't
 * already taken. Falls back to "workspace" when the name has no ASCII
 * alphanumerics at all (e.g. a Persian/Arabic-only workspace name) — the
 * slug is an internal unique key here, not something shown to users, so a
 * generic fallback plus a numeric suffix is fine.
 */
function generateUniqueWorkspaceSlug(PDO $platform, string $name): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    if ($base === '') {
        $base = 'workspace';
    }

    $stmt = $platform->prepare('SELECT 1 FROM workspaces WHERE slug = ? LIMIT 1');
    $slug = $base;
    $suffix = 1;
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}
