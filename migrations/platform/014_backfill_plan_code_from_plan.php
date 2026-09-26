<?php
declare(strict_types=1);

/**
 * workspaces has carried both `plan` (migrations/platform/001_create_platform_schema.php)
 * and `plan_code` (migrations/platform/008_create_plans_table.php) since 008
 * shipped. A codebase-wide check of every read/write of the two (see the
 * task report) found `plan` is dead: nothing anywhere reads it, and nothing
 * explicitly writes it either — every workspace row only ever gets it from
 * the column's own `DEFAULT 'free'` firing on INSERT, never from
 * application code. `plan_code` is the only one includes/plans.php
 * actually reads.
 *
 * There is therefore nothing to migrate FROM in the ordinary case — this
 * only guards the edge case of a row where plan_code somehow ended up NULL
 * despite its own NOT NULL DEFAULT (e.g. a hand-run UPDATE), copying
 * whatever `plan` already holds into it rather than leaving it unset.
 * `plan` itself is left in place — never dropped, per spec — even though
 * nothing reads it going forward either.
 */
return function (PDO $pdo): void {
    $pdo->exec("UPDATE workspaces SET plan_code = plan WHERE plan_code IS NULL AND plan IS NOT NULL");
};
