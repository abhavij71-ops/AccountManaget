# Permissions

Source of truth for who may do what in a workspace. Every permission
function in `includes/auth.php` and `includes/helpers.php` implements one
row of this matrix — if the two ever disagree, this file is right and the
code has drifted.

## Matrix

| action                          | owner | admin | member        | viewer |
|----------------------------------|-------|-------|---------------|--------|
| see workspace-visible record    | yes   | yes   | yes           | yes    |
| see private record               | yes   | no*   | only if owner | no     |
| create record                    | yes   | yes   | yes           | no     |
| edit/delete/archive record       | yes   | yes   | only if owner | no     |
| bulk operations                  | yes   | yes   | own rows only | no     |
| import                           | yes   | yes   | no            | no     |
| export                           | yes   | yes   | visible rows  | no     |

\* **DECISION:** admin does **not** see other users' private records. An
admin still sees a private record they own themselves (same rule as
member), and still gets the "N private records hidden" notice for whatever
private records belong to someone else.

A role that isn't recognized at all (revoked membership, corrupted session,
etc.) is treated the same as the most restrictive applicable row — every
function below fails closed, never open.

## Where each row lives in code

| matrix row | function | file |
|---|---|---|
| see workspace-visible / see private record (row filtering) | `visibilityScope()` | `includes/helpers.php` |
| see workspace-visible / see private record (single row) | `canSeeRecord()` | `includes/helpers.php` |
| ("N private records hidden" notice) | `hiddenPrivateRecordsCount()` | `includes/helpers.php` |
| create record | `canWrite()`, `requireWriteAccess()` | `includes/auth.php` |
| edit/delete/archive record | `canEditRecord()`, `requireEditRecord()` | `includes/helpers.php` |

`bulk operations`, `import`, and `export` are recorded here as the source
of truth for future work but have no dedicated function yet — endpoints
implementing them should gate on `canWrite()` (import/create-shaped bulk
work) or `visibilityScope()` (read-shaped export/bulk work) until a more
specific function is added, at which point this table gets a new row.

Note also `canManageRecordVisibility()` (`includes/helpers.php`): it gates
a different action — who may *change* a record's `visibility` field
(private/workspace), not who may see or edit the record — and isn't a row
in this matrix. It currently allows the record's own owner (any role) or
a workspace owner/admin.

## Function reference

### `canWrite(): bool` — `includes/auth.php`
"create record" row. `true` for owner/admin/member, `false` for viewer or
no recognized role.

### `requireWriteAccess(): void` — `includes/auth.php`
403s (via the same rendering `requireRole()` uses) unless `canWrite()` is
true. For endpoints that create/write with no existing record to check
ownership against — call after `requireRole()` has already confirmed a
logged-in user in an active workspace.

### `canEditRecord(?string $visibility, ?int $ownerUserId): bool` — `includes/helpers.php`
"edit/delete/archive record" row. `true` for owner/admin unconditionally;
for member, only when `$ownerUserId` matches the current user; `false` for
viewer. `$visibility` is accepted for signature symmetry with
`canSeeRecord()`/`canManageRecordVisibility()` but is not part of this
decision — write access depends on ownership and role only.

### `requireEditRecord(array $row): void` — `includes/helpers.php`
403s unless `canEditRecord()` is true for `$row['visibility']` /
`$row['owner_user_id']`. Use only after existence/read-access has already
been established (e.g. via `notFoundResponse()` + `canSeeRecord()`) — this
function only gates the write, not whether the record is visible at all.

### `visibilityScope(string $table, ?string $alias = null): string` — `includes/helpers.php`
SQL fragment restricting a list query to rows the current user may see.
`1=1` for owner; workspace-visible-or-own-private for everyone else
(admin included, per the DECISION above).

### `canSeeRecord(?string $visibility, ?int $ownerUserId): bool` — `includes/helpers.php`
Same rule as `visibilityScope()`, for one already-fetched row instead of a
list query.

### `hiddenPrivateRecordsCount(string $table): int` — `includes/helpers.php`
Count of rows `visibilityScope()`/`canSeeRecord()` are currently hiding
from the caller (private, not owned by them). Always `0` for owner, and
now non-zero for admin where applicable (previously always `0` for admin
too, before the DECISION above).
