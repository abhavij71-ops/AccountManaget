<?php
declare(strict_types=1);

/**
 * Creates the platform-level tables from docs/ROADMAP-SAAS.md Phase 10:
 * accounts_users (platform users), workspaces, and memberships — the
 * central database that sits alongside the per-workspace SQLite files.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        full_name TEXT,
        preferred_language TEXT DEFAULT 'fa',
        timezone TEXT DEFAULT 'Asia/Tehran',
        is_active INTEGER NOT NULL DEFAULT 1,
        email_verified_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        db_file TEXT NOT NULL UNIQUE,
        owner_user_id INTEGER NOT NULL REFERENCES accounts_users(id),
        plan TEXT NOT NULL DEFAULT 'free',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS memberships (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
        role TEXT NOT NULL CHECK (role IN ('owner','admin','member','viewer')),
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (workspace_id, user_id)
    )");
};
