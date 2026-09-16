# Development Guide — Account Manager

🇮🇷 [فارسی](DEVELOPMENT.fa.md)

For installation see [INSTALLATION.md](INSTALLATION.md); for the product overview see [ABOUT.md](ABOUT.md).

---

## 1. Architectural principles (non-negotiable)

These five choices are deliberate. Reject any change that breaks one.

1. **No framework.** Plain PHP 8.1+. No Composer, no Node, no build step. Reason: it must run on any shared host via FTP upload.
2. **No runtime external dependencies.** Bootstrap, Vazirmatn and the JS bundle are all local. No CDN calls, no external APIs.
3. **Prepared statements only.** Never concatenate a variable into SQL. The single permitted exception is an `ORDER BY` column name, and only through an allow-list (see the pattern in `modules/accounts/index.php`).
4. **Never store secrets.** Not in a table, not in the UI, not in `history`, not in exports. Only `credential_storage` and `credential_reference` as pointers to where the credential actually lives.
5. **Never collapse the five-state model.** `Enabled` / `Disabled` / `Unknown` / `Not Set` / `Not Applicable` carry different meanings. `Unknown` must never behave like `Not Set`, and `Not Applicable` must never reduce a completeness score.

---

## 2. File map

| Path | Responsibility |
|---|---|
| `config.php` | Constants, paths, session bootstrap, `APP_BASE_URL` detection |
| `db.php` | PDO connection + lightweight runtime migrations |
| `install.php` | Schema creation + admin user — single-use, delete after install |
| `includes/auth.php` | Session, `requireLogin()` (also re-checks the account is still active on every request), `safeInternalRedirect()`, CSRF |
| `includes/helpers.php` | Enum maps, badges, pagination, tags, `log_history()` |
| `includes/lang.php` | The `t()` function, language resolution, text direction |
| `includes/security-score.php` | Email security score calculation |
| `includes/needs-attention.php` | Three-tier rule engine |
| `includes/renewals.php` | Upcoming/overdue renewal queries |
| `includes/import.php` | CSV parsing, validation, duplicate detection |
| `lang/fa.php`, `lang/en.php` | Translation files |
| `modules/<entity>/` | Per-entity CRUD |

---

## 3. Adding a field

Order matters. Skipping a step produces a silent bug.

1. **Schema** — edit the relevant `CREATE TABLE` in `installSchemaStatements()` inside `install.php`.
2. **Migration** — existing installs never re-run `install.php`, so add a guarded `ALTER TABLE ... ADD COLUMN` in `db.php` (see the `emails.is_favorite` pattern).
3. **Add and Edit forms** — add the field to the `$form` array, the validation logic, the `INSERT`/`UPDATE`, and the HTML.
4. **View page** — render it; use `dashOrValue()` for empty values and `renderBadge()` for enums.
5. **History** — if the field is significant, call `log_history()` in the edit path.
6. **Translation** — add the label key to **both** `lang/fa.php` and `lang/en.php`.
7. **Search** — if searchable, add it to the `search.php` query.
8. **Import** — if importable, add it to the column map in `includes/import.php`.

---

## 4. Adding an entity

1. Create the table with the standard columns (`id`, `created_at`, `updated_at`) and register the `updated_at` trigger in `installTriggerStatements()`.
2. Create `modules/<entity>/` with `index.php`, `add.php`, `edit.php`, `view.php`.
3. Add the nav item to `$navItems` in `includes/header.php`.
4. Add the new `entity_type` to the CHECK constraints on `taggables` and `history`.
5. Extend `entityProfileUrl()` and `entityDisplayLabel()` in `helpers.php`.
6. Add the entity to `search.php`.

---

## 5. Code conventions

**Always escape output.** Use `e()`, not raw `htmlspecialchars`:

```php
<?= e($account['username']) ?>
```

**Every POST form needs CSRF:**

```php
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
```
Server side:
```php
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { /* reject */ }
```

**Dynamic sorting through an allow-list only:**

```php
$allowed = ['username', 'status', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowed, true) ? $_GET['sort'] : 'created_at';
```

**Success messages via flash, never a direct echo:**

```php
flashSet('success', t('common.saved'));
header('Location: ' . appUrl('modules/emails/index.php'));
exit;
```

---

## 6. Migrating existing installs

`install.php` runs once. To change the schema on a live install, use the guarded pattern in `db.php`:

```php
$cols = $pdo->query("PRAGMA table_info(emails)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('is_favorite', $cols, true)) {
    $pdo->exec("ALTER TABLE emails ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0");
}
```

**SQLite caveat:** `ALTER TABLE` support is limited. Dropping a column or changing a CHECK constraint requires creating a new table, copying the data and renaming. Back up `data/database.sqlite` before any non-trivial migration.

---

## 7. Manual pre-release checklist

There is no automated test suite, so run this by hand:

1. Fresh install: delete `data/database.sqlite` → run `install.php` → log in
2. All four entities: add, edit, view, archive, delete
3. Link and unlink a Phone to an Email and an Account — confirm Unlink never deletes the entity
4. Switch language on every page — confirm the redirect returns to the same page
5. Import a CSV containing one duplicate — confirm nothing is overwritten without confirmation
6. Mobile viewport (under 768px) — offcanvas menu and scrollable tables
7. Needs Attention — confirm Closed/Abandoned records never appear as active issues

---

## 8. Cutting a release

1. Bump `APP_VERSION` in `config.php`.
2. Add a new section to `CHANGELOG.md` (Add / Fix / Note).
3. If user-facing behaviour changed, update `README.md` and `README.fa.md` together.
4. Tag and commit.
