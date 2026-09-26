# Changelog

This project was built in sequential phases, each adding a coherent slice of functionality. Dates are omitted since this reflects build order, not a dated release history. Starting with v1.1.0, changes are tracked under semantic version numbers (`APP_VERSION` in `config.php`, shown in the sidebar and in Settings → درباره سیستم).

**Two tracks, one file, going forward.** As of the `single-user`/`main` split (see [docs/BRANCHING.md](docs/BRANCHING.md)), `v1.x.x` (single-user, fixes only) and `v2.x.x` (SaaS, active development) both get new entries, but at very different paces. Everything below this point (v1.0.0 through v1.12.0) is shared history — both editions were that codebase before the split. From here on:

- New `v2.x.x` entries keep being added at the top, exactly as before.
- New `v1.x.x` entries (rare — fixes only) get their own short, clearly-labeled run wherever they land chronologically, e.g. a one-line `## v1.12.1 (single-user)` heading — never silently interleaved with `v2.x.x` entries under a bare version number that leaves the reader guessing which edition it applies to.

One file, not two, because the shared v1.0.0–v1.12.0 history has real value as a single continuous record, and a reader tracing *when* something was fixed shouldn't have to cross-reference two files to get the full timeline. The `(single-user)`/no-suffix convention (SaaS entries stay unmarked, since `main`'s changelog is the default reading) is what keeps the two tracks visually distinguishable without duplicating the file.

## v2.1.0

Implements most of `docs/ROADMAP-SAAS.md` Phase 13 ("SaaS-grade security hardening"), plus the Phase 12 "when a member leaves" workflow and a small privacy-transparency notice. Entirely additive on top of v2.0.0 — no manual upgrade step, no breaking changes; every new table/column is picked up automatically by the existing migration runner.

- **Add:** Login rate limiting. `login_attempts` table (central database); 5 failed attempts within 15 minutes locks out by IP *and* by username independently — either alone is enough to block a request — with one generic message that never reveals which of the two actually tripped it. Purges attempts older than 24h opportunistically (this app has no cron runner yet).
- **Add:** TOTP two-factor authentication, implemented directly per RFC 6238/RFC 4226 — no external library, since this project has no Composer. `includes/totp.php` covers HOTP, Base32, the `otpauth://` URI, and ten hashed single-use recovery codes. Enrollment and disable live on Settings, showing the secret and setup URI as plain text rather than a hand-rolled QR/SVG encoder — a correct QR encoder needs Reed-Solomon error correction and precise module placement, easy to get subtly wrong with no way to verify against a real scanner in this environment. `verify-totp.php` is the new second login step, reusing the same lockout mechanism as the password step.
- **Add:** Session management. A central `sessions` table tracks every active login (hashed device token, IP, user agent, last-seen) independently of PHP's own session storage; Settings gained an "active devices" list and a "sign out everywhere" button, which — read literally — signs out every device including the one that clicked it. Tracking is folded into `requireLogin()` itself, so it applies across the entire app without touching every page individually.
- **Add:** Audit log. A central `audit_log` table and an `auditLog()` helper record *access* — who viewed or exported which record, and when — distinct from the existing `history` table, which records data *changes*. Not yet called from any view or export page; that wiring is separate follow-up work.
- **Add:** Security response headers on every request — `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, a `Content-Security-Policy` (`default-src 'self'`, with `'unsafe-inline'` on `script-src`/`style-src` only, matching this app's actual inline `<script>`/`style="..."` usage — nothing broader), and `Strict-Transport-Security` gated on HTTPS actually being active.
- **Add:** `cron-backup.php` — a token-protected URL endpoint (`CRON_BACKUP_TOKEN` environment variable, `hash_equals()`) for an external cron job to trigger, since this app has no built-in scheduler. Checkpoints each database's WAL via a throwaway raw `PDO` connection (the same pattern `includes/migrator.php`'s `backupDatabaseFile()` already uses), gzip-copies it into `data/backups/`, and purges backups older than 30 days.
- **Add:** "When a member leaves" now offers the promised three explicit choices instead of an immediate delete. `remove-member.php` counts the departing member's owned records, then requires transferring ownership to another member, archiving everything, or deleting everything (typed-email confirmation) — all inside one transaction, with owned accounts deleted before owned emails/services/phones to avoid a spurious foreign-key failure.
- **Add:** A one-line, count-only notice — "N private records created by other members are not shown to you" — on the Emails and Accounts lists and the dashboard, visible only to `member`/`viewer` roles (always silent for `owner`/`admin`, since nothing is hidden from them).
- **Fix:** `includes/header.php` displayed `$user['username']` in the top bar — a key `currentUser()` has not returned since the `accounts_users` migration in v2.0.0 — instead of `$user['email']`. Found while auditing the file for the new CSP work.

## v2.0.0

Multi-workspace / SaaS foundation — implements `docs/ROADMAP-SAAS.md` Phase 10 ("Ownership layer") and most of Phase 11 ("Teams and roles"). This is a breaking architectural change: a single `data/database.sqlite` becomes a central `data/platform.sqlite` (platform users, workspaces, memberships, invitations) plus one SQLite file per workspace under `data/workspaces/`. An existing single-tenant install must run the new `upgrade-to-multiuser.php` once before upgrading.

- **Add: versioned migration system.** `includes/migrator.php` + `migrations/NNN_*.php` replace the old inline `applySchemaUpgrades()` in `db.php` with a `schema_version`-tracked runner: each migration is its own file returning `function (PDO $pdo): void {}`, applied in order inside one transaction. Purely a refactor of existing migrations — same behavior, same order, nothing about how an existing database gets upgraded has changed.
- **Add: central platform database.** `includes/platform-db.php` opens `data/platform.sqlite` (its own separate `schema_version` and `migrations/platform/` trail) with `accounts_users`, `workspaces`, and `memberships` tables per the roadmap's Phase 10 schema.
- **Add: `db()` is now workspace-scoped.** It resolves `data/workspaces/ws_XXXXXX.sqlite` from `$_SESSION['workspace_id']` instead of a single fixed file, caches the connection per request, and runs the versioned migrations on it when opened. `PRAGMA busy_timeout = 5000` was added alongside the existing `journal_mode = WAL`.
- **Add: `upgrade-to-multiuser.php`** — a one-time, install.php-style script: backs up the legacy database, creates the central database, moves the existing admin into `accounts_users`, moves `database.sqlite` to `ws_000001.sqlite`, and creates the owning membership. All-or-nothing — a failure at any step leaves the original file untouched (the risky steps copy before ever deleting the original).
- **Add: platform-level authentication and roles.** `currentUser()` now authenticates against `accounts_users`, not the retired per-workspace `users` table. New `currentWorkspaceId()` and `currentRole()` (four roles: owner/admin/member/viewer, re-verified from `memberships` every request rather than trusted from the session). New `requireRole(...$roles)` gate, self-healing to `select-workspace.php` when no workspace is active rather than failing hard. `login.php` now authenticates by email against `accounts_users` and routes a multi-workspace user through the new `select-workspace.php` picker.
- **Add: per-record visibility.** `emails`, `services`, `accounts`, and `phones` each gained `owner_user_id` and `visibility` (`'private'` / `'workspace'`, default `'workspace'`) columns (`migrations/007_add_visibility_columns.php`). `visibilityScope()` (`includes/helpers.php`) is applied to every list and search query, including total-count and filter-dropdown queries — owners/admins see everything, members/viewers see workspace-visible records plus their own private ones. Every `view.php` now returns a genuine 404 (not a redirect, not a 403) for a record that either doesn't exist or simply isn't visible to the viewer, so existence never leaks. Every `edit.php` now requires at least `member` (a `viewer` can never edit), and shows a visibility toggle only to a record's own owner or a workspace owner/admin.
- **Add: workspace member management and invitations.** `members.php` (owner/admin only): role changes, member removal, and an invite form, with guardrails against self-lockout and a workspace ever having zero owners. `accept-invite.php` resolves a `bin2hex(random_bytes(32))` token (7-day expiry) into either a one-click join (existing account) or a signup form (new account) — invalid, expired, and already-accepted tokens are all indistinguishable to the visitor. **Email sending is not implemented yet** (tracked for Phase 15) — `members.php` instead lists each pending invite's full accept link for manual copying.
- **Fix:** `settings.php`'s password-change flow was left querying the retired `users` table (and displaying a `username` field `currentUser()` no longer returns) after the `accounts_users` migration above — silently broken until this release. Now reads/writes `accounts_users` via `platformDb()`.

**Known limitations, tracked as follow-up work:**
- No `add.php` yet sets `owner_user_id` on creation — every new record defaults to `owner_user_id = NULL` / `visibility = 'workspace'`, so the private-visibility feature can currently only be applied by an owner/admin editing an existing record, not chosen at creation time.
- Invitation emails are not sent (Phase 15) — links must be copied and shared manually from `members.php`.
- Phases 12 (intra-team privacy nuances) and 13 (SaaS-grade security hardening — login rate limiting, 2FA, session management, audit log) from the roadmap are not part of this release.

## v1.12.0

- **Add:** "Apply defaults to existing accounts" — retroactively backfills a Service's default template (v1.11.0) onto accounts that already existed when the template was set, or that were created before the template changed.
  - New `modules/services/apply-defaults.php`: a preview-then-confirm flow. `planServiceDefaultsApply()` (new, same file) computes — without writing anything — exactly which of a service's non-archived accounts would change and which fields, and the same function is re-run fresh on POST rather than trusting what the preview page submitted, so the applied result can never drift from current data.
  - **Same fill-only rule as account creation:** a field is only touched when its current value is `NULL`, `'Not Set'`, or `'Unknown'` — `'Not Applicable'` and every other real value is a confirmed state and is left untouched. `default_identity_type` is deliberately excluded: an existing account's `identity_type` is never one of those three fillable states, so this action has nothing legitimate to do there.
  - The `recovery_follows_identity` derivation from v1.11.0 applies here too: an eligible account's Recovery Email/Phone is backfilled from whichever identity it's actually anchored to — never both.
  - New `serviceHasDefaultsTemplate()` (`modules/services/_lib.php`) gates the new "Apply defaults to existing accounts" button on the Service profile (`view.php`) — hidden entirely when the template has no usable values, since there'd be nothing to apply.
  - Every account changed by this action gets a `history` entry ("Service Defaults Applied") naming exactly which fields were filled, so this bulk action leaves the same audit trail a manual edit would.

## v1.11.0

- **Add:** Service Defaults — a per-service template that pre-fills the Account creation forms, so re-entering the same ~30 fields for every account of the same service is no longer necessary.
  - New `service_defaults` table, 1:1 with `services` (`ON DELETE CASCADE`): identity type, 2FA/passkey/security-questions/recovery status, 2FA method, `recovery_follows_identity`, subscription type/status/billing cycle, and currency — every `default_*` column nullable, since `NULL` means "no template for this field," not "Not Set." `modules/services/_lib.php` (new) gained `fetchServiceDefaults()`/`upsertServiceDefaults()`, mirroring the existing `account_security`/`account_recovery` upsert helpers.
  - Service Add/Edit gained a "Default values for new accounts" section; every enum field there gets an extra "— No default —" option (selected by default) alongside the service's existing enum constants — nothing retyped. Service view shows the configured template read-only, distinguishing an unset field (`—`) from an explicit stored value, since those mean different things here.
  - **Consumption, with a hard rule: inherited values only ever pre-fill the form — nothing is written to the database until the user submits.** Picking a Service on the Account Add or Quick-add form fetches that service's template (new read-only endpoint, `modules/services/get-defaults.php`) and fills in the matching empty fields client-side, each marked with a small "from service template" label that disappears the instant the user edits that field (a real keystroke is a trusted DOM event; the script's own pre-fill isn't, so the two are never confused). Quick-add only has an Identity type field in common with the template — everything else is on the full Add form.
  - Separately, when a service's `recovery_follows_identity` is on, the new account's Recovery Email or Recovery Phone is pre-filled from whichever identity (email or phone) was actually chosen — never both — marked "same as identity" rather than "from service template," since that value is derived, not copied from the template.
  - New Needs Attention rule (Warning): an account whose recovery email/phone is the *same record* as its identity email/phone — a "complete-looking" profile that is in fact a single point of failure. Ships together with the derivation above on purpose, since auto-filling recovery from identity is exactly what can quietly create this state.
  - Templates apply at creation only — `edit.php` is intentionally untouched; a template that changed after an account was created must not retroactively rewrite that account's history.

## v1.10.2

- **Fix (security):** Open redirect on the post-login destination. `login.php`'s `redirect` parameter (GET and POST) was used directly as the `Location` target after a successful login with no validation, so `login.php?redirect=https://evil.com` — or a scheme-relative `//evil.com`, or URL-encoded variants of either — sent a user straight to an attacker-controlled page immediately after authenticating on the real site. `set-language.php` already had the correct whitelist guard for its own `redirect` parameter; that guard is now `safeInternalRedirect()`, a single shared implementation in `includes/auth.php` used by both files, accepting only a relative `*.php` path and rejecting absolute URLs, `//host`, a leading slash, and `..` traversal. `requireLogin()`'s own "return here after login" redirect (built from `REQUEST_URI`) was adjusted to strip the app's base path first so it still passes this stricter, relative-only whitelist.
- **Fix (security):** Database connection failures no longer disclose the server's absolute filesystem path. `db()` (`db.php`) used to `die()` with the raw `PDOException` message — which includes the full path to `data/database.sqlite` — shown to any visitor who hit a connection error. A new `APP_DEBUG` constant (`config.php`, defaults to `false`) now gates this: the real exception is always written to the PHP error log, but the browser only ever sees a generic message unless `APP_DEBUG` is explicitly turned on for local debugging.
- **Fix (security):** `requireLogin()` (`includes/auth.php`) now re-verifies on every request — not just at login — that the session's user row still exists and `is_active = 1`, closing the gap where a deactivated or deleted account's session stayed fully valid until it happened to log out. Reuses `currentUser()`'s existing static cache, so this adds no extra query per request. The now-redundant local `is_active` re-check that `settings.php`'s backup-download handler had as a workaround for this exact gap was removed. Changing your password now also calls `session_regenerate_id(true)`, retiring the old session ID immediately. Scope: this protects the current session only — a password change or deactivation does not yet invalidate *other* concurrent sessions for the same account; that requires a `session_version` column and is tracked separately for Part B.
- **Fix:** CSV/TXT import no longer corrupts on a quoted field containing an embedded newline (e.g. a multi-line Notes column from an Excel export). `parseDelimitedFile()` (`includes/import.php`) used to read the whole file into memory and split it on `"\n"` before parsing each line with `str_getcsv()` — a newline inside quotes split one logical row into two and corrupted every row after it. Rewritten to stream the file with `fopen()`/`fgetcsv()`, which tracks open quotes across physical lines natively. Same 2000-row cap, same BOM stripping, same return shape, so `parseCsvFile()`, `parseTxtFile()`, and `parseImportFile()` needed no changes.

## v1.10.1

- **Fix (critical):** `migrateAccountsIdentityAnchor()`'s `accounts` rebuild (v1.5.0) hit an undocumented SQLite ≥3.25 behavior — `ALTER TABLE accounts RENAME TO accounts_old` silently rewrote the `REFERENCES accounts(id)` clause in six other tables (`account_security`, `account_recovery`, `phone_account`, `subscriptions`, `payments`, `custom_fields`) to `REFERENCES accounts_old(id)`. Once `accounts_old` was dropped at the end of that migration, every one of those six tables referenced a table that no longer existed, and any `INSERT`/`UPDATE` against them (e.g. saving an Account edit) failed with `SQLSTATE[HY000]: General error: 1 no such table: main.accounts_old`, since `db()` enables `PRAGMA foreign_keys = ON` on every connection.
  - New `repairAccountsOldReferences()` in `db.php`: detects any table whose stored DDL still mentions `accounts_old`, backs up the database, and rebuilds each affected table (rename → create with the correct DDL → copy rows → drop → recreate its indexes/trigger) to restore the real `accounts` reference. Self-guarding — once repaired, the detection query matches nothing and it's a no-op on every later request.
  - `migrateAccountsIdentityAnchor()` now sets `PRAGMA legacy_alter_table = ON` before the rename (and back `OFF` in `finally`), which prevents SQLite from rewriting other tables' `REFERENCES` clauses in the first place — protecting any fresh install that hasn't hit this yet.

## v1.10.0

- **Add:** Jalali/Gregorian date display wired into every profile page (`modules/emails/view.php`, `modules/accounts/view.php`, `modules/services/view.php`, `modules/phones/view.php`, `index.php`) — `created_date`, `last_verified`, `last_login`, `start_date`, `renewal_date`, and every history log's timestamp now render through `formatDate()` (added in v1.9.0) instead of the raw Gregorian ISO string. Storage and `<input type="date">` values are untouched — Gregorian ISO always.
- **Add:** Account Review (`review.php`) — walks accounts whose `last_verified` is more than 6 months old (or never verified) one at a time, with Confirmed / Changed (→ edit) / No longer have it (→ Closed) actions and a progress counter. Linked from the sidebar.
- **Add:** "Download backup" on Settings — downloads a timestamped copy of `data/database.sqlite`, gated by `requireLogin()` plus an `is_active` re-check (this app has no roles table, so that recheck stands in as the closest honest "role" gate for a single-admin system).
- **Add:** The record count shown at the bottom of the Emails/Services/Accounts/Phones lists (next to pagination) is now also shown at the top of each list.

## v1.9.0

- **Add:** Global quick-search in the top bar — a debounced (~300ms) search input with a live results dropdown, grouped by entity type, each result linking straight to its profile. New `search-api.php` (root) returns max 8 JSON results by reusing `search.php`'s query logic, with one correctness fix along the way: the Accounts query now `LEFT JOIN`s Emails/Phones instead of `search.php`'s `INNER JOIN`, so phone/username/other-anchored accounts (Identity Anchor model, v1.5.0) are actually findable. Plain inline JS, no library.
- **Add:** Jalali calendar display infrastructure — new `app.calendar` translation key (`jalali` for fa, `gregorian` for en/ar) and `formatDate(?string $iso, bool $withTime = false)` in `includes/helpers.php`, which converts a stored Gregorian ISO date/datetime to the active language's calendar for display only (via `IntlDateFormatter` when the `intl` extension is available, otherwise a pure-PHP Gregorian→Jalali fallback, `gregorianToJalali()`). Database storage and `<input type="date">` values remain Gregorian ISO always — this is a display-layer conversion only. Not yet wired into any page.

## v1.8.0

- **Add:** Inline "+ New" record creation for Service and Email directly from the Accounts Quick-add and full Add forms — no more abandoning the form to go create a missing Service first. Two new lightweight JSON endpoints, `modules/services/create-inline.php` and `modules/emails/create-inline.php` (POST-only, CSRF-checked, one required field each, everything else left to schema defaults), plus a small inline-JS helper (no library) that posts via `fetch()` and appends the new option to the select without a page reload.

## v1.7.0

- **Add:** "Security Report" export type — a single combined CSV with three sections (email security scores via `calcEmailSecurityScore()`, per-account 2FA status, and the full Needs Attention issue list), reusing `includes/security-score.php` and `includes/needs-attention.php` rather than duplicating their logic.
- **Add:** Backend support for TXT (tab-delimited) file import in `includes/import.php` — `parseCsvFile()` was refactored onto a shared `parseDelimitedFile()` parser (behavior unchanged for existing callers), with new `parseTxtFile()` and a `parseImportFile()` entry point that dispatches by file extension. Every later pipeline stage (mapping, validation, duplicate detection, confirm, import) already worked on the parsed data regardless of source format, so nothing else needed to change. Note: the CSV import wizard UI (`modules/import/*.php`) does not call this yet — that wiring is a follow-up.
- Minor cleanup: `modules/export/download.php`'s duplicated email/phone/username identity-resolution logic (in the Accounts and Subscriptions exports) was factored into one shared closure.

## v1.6.0

- **Add:** CSV Export — `modules/export/index.php` lets you pick Emails, Services, Accounts, Phones, or Subscriptions and download a CSV via `modules/export/download.php`. The Export card on the Import/Export hub, previously just descriptive text, is now wired to a real button.
- Every exported CSV is prefixed with a UTF-8 BOM (`\xEF\xBB\xBF`) so Persian and Arabic text opens correctly in Windows Excel instead of being mangled.
- No sensitive field is included in any export — columns mirror what each entity's own list/profile page already shows (no credential storage/reference, recovery, or other security-table fields). The Subscriptions export's `last4` column is masked the same way the Account profile UI already displays it (`•••• 1234`); every other payment field passes through unmasked.
- Accounts/Subscriptions exports resolve the account's Identity Anchor (email/phone/username/other, added in v1.5.0) into readable Identity Type and Identity columns instead of assuming an email.
- New translation keys across `lang/en.php`, `lang/fa.php`, `lang/ar.php`; `ie.export_description` updated in place since export is no longer "a future phase."

## v1.5.0

- **Add:** Identity Anchor model for Accounts (see `docs/IDENTITY-MODEL.md`) — an Account is no longer required to have an Email. `accounts.email_id` is now nullable, and two columns were added: `identity_type` (`email` / `phone` / `username` / `other`) and `identity_phone_id` (references `phones`), with a table-level `CHECK` ensuring the field matching the chosen identity type is actually filled in. Existing accounts are migrated to `identity_type = 'email'` automatically (SQLite can't add a `CHECK` via `ALTER TABLE`, so the migration rebuilds the table via rename → create → copy → drop, guarded to run once, with `data/database.sqlite` backed up to `.bak` first).
- **Add:** Add/Edit/Quick-add account forms gained an "Identity type" selector at the top, with the Email or Phone field shown or hidden inline (no JS library) depending on the choice, and matching server-side validation.
- **Add:** The Accounts list's Email column is now an Identity column (with its own filter dropdown), and the Account profile header links to whichever Email or Phone actually anchors that account instead of assuming Email.
- **Add:** Phone profile gained "Accounts created with this number" — accounts anchored to that phone via `identity_phone_id` — kept entirely separate from the pre-existing phone↔account link-list section.
- **Add:** Phone Security (`docs/IDENTITY-MODEL.md` sec. 5) — a new `phone_security` table (SIM PIN status, port-out lock, carrier, eSIM, last security check, security score; both statuses five-state) with a guarded migration, a `calcPhoneSecurityScore()` mirroring the existing email security score function, and a matching security section on the Phone view/edit pages.
- **Add:** New translation keys for all of the above across `lang/en.php`, `lang/fa.php`, and `lang/ar.php`.

## v1.4.0

- **Add:** Arabic translation (`lang/ar.php`) — full coverage, same 568 keys as `lang/en.php`, `dir` set to `rtl`.
- **Add:** Languages are now discovered dynamically. `supportedLanguages()` (`includes/lang.php`) globs `lang/*.php` and reads each file's own `app.native_name` key, so adding a language no longer requires a code change — just drop a new `lang/<code>.php` file. Replaces the old `SUPPORTED_LANGUAGES` constant.
- **Add:** LTR Bootstrap support — `header.php`, `login.php`, and `install.php` now pick `bootstrap.min.css` or `bootstrap.rtl.min.css` based on the current text direction, instead of always loading the RTL build. (`install.php` has no language session yet, so it still defaults to RTL.) Requires placing a matching-version `assets/css/bootstrap.min.css` alongside the existing RTL build.
- **Fix:** Last remaining hardcoded Persian strings moved into the translation system — the `db.php` connection-failure message (new `db.connection_error` key; `lang.php` is now required early enough in `db.php` to use `t()`, since `config.php` already starts the session) and the list separator used by the CSV import wizard when reporting missing required fields / differing duplicate fields (new `common.list_separator` key).

## v1.3.0

- **Add:** Full translation coverage (fa/en) of essentially the entire application — the four entity modules' Add/Edit/View pages and their security/recovery/subscription/payment sections, the Emails bulk quick-edit and favorite toggle, the Accounts quick-add/bulk-assign/costs pages, the full CSV import wizard (all 4 steps plus the underlying validation/duplicate-detection messages), the dashboard, global search, Needs Attention (including every rule-engine issue message), Settings, and Login. ~590 translation keys across `lang/fa.php` and `lang/en.php`. `install.php` is intentionally left Persian-only — it runs before any session-based language preference can exist, so translating it would have no observable effect, and it's meant to be deleted after first use anyway.
- **Fix:** `renderBadge()`'s new translation layer (added in v1.2.0) was silently overriding a caller-supplied custom label — three Yes/No badges on the Account profile (subscription auto-renewal, payment required, payment auto-renewal) were showing the generic Enabled/Disabled translation instead of Yes/No. Added a dedicated `yesNoBadge()` helper that never goes through the enum-translation path, and fixed the three call sites.
- **Fix:** two view-page labels ("Security Key" on Email/Account profiles, "Label" on the Phone profile) had briefly picked up the wrong translation key during the pass above — resolved before commit, view pages now match the original Persian wording exactly when in Persian.

## v1.2.0

- **Add:** Pagination on the four main list pages (Emails, Services, Accounts, Phone Numbers) — page numbers with prev/next, and a records-per-page selector (10 / 25 / 50 / 100 / 250 / all). All existing filters and sort order are preserved across page/per-page changes. Implemented as shared helpers (`resolvePage`, `resolvePerPage`, `paginationBounds`, `renderPagination`) in `includes/helpers.php`.
- **Add:** Multi-language scaffolding — a `t()` translation function (`includes/lang.php`), `lang/fa.php` and `lang/en.php` translation files, and a language switcher in the top bar that persists the choice in the session. Currently covers: the sidebar/nav, the four list pages touched by this release (titles, buttons, filters, table headers, empty states), all closed-enum status/type badges and dropdowns app-wide, and the history log labels. Per-page forms (Add/Edit/View for every module) are **not yet translated** — that is a larger follow-up pass, tracked below.
- **Note:** the only bundled Bootstrap build is the RTL one (`assets/css/bootstrap.rtl.min.css`); English pages render `dir="ltr"` correctly (verified — Bootstrap 5's spacing utilities use CSS logical properties, so layout follows `dir` automatically), but a dedicated LTR-optimized visual pass has not been done.

## v1.1.0

- **Fix:** `upsertEmailSecurity`/`upsertSubscription`/`upsertPayment`/`upsertAccountSecurity`/`upsertAccountRecovery` used `[...$data, 'x' => $y]` array spread, which throws `Cannot unpack array with string keys` on PHP versions before 8.1. Replaced with `array_merge()` so saving Email/Account edits works on the documented PHP 8.0 minimum.
- **Add:** Favorite/star toggle on the Emails dashboard — one click (AJAX, no page reload) stars an email; starred emails sort to the top. New `emails.is_favorite` column, added automatically to existing databases on next load (no manual migration needed).
- **Add:** Bulk quick-edit for Emails — select multiple emails from the dashboard and edit just Status and Type on a dedicated page, either by setting one shared value for all of them or by editing each row individually.
- **Add:** Bulk service-to-email assignment for Accounts — select one or more Services and one or more Emails on a new "تخصیص گروهی" page; creates one Account per Service×Email combination, skipping combinations that already have an Account.

## v1.0.0 — Initial release (Phases 0–9)

## Phase 0 — Foundation
SQLite schema for all core entities (Email, Email Security, Service, Account, Account Security, Recovery, Phone, Subscription, Payment, Tag, Custom Field, History) with correct many-to-many relations for Phone↔Email and Phone↔Account. Installer, PDO connection layer, session-based auth, Bootstrap 5 RTL + locally-hosted Vazirmatn header/footer, and `data/` protection.

## Phase 1 — Email, Service, Phone modules
Full CRUD (index/add/edit/view) for the three base entities, closed enums for Status/Type fields, visually distinct badges for the five-state model, and empty states for every list.

## Phase 2 — Account module
Quick Add and Full Edit, the complete Account Profile (security, recovery, credential reference, custom fields, notes, tags, history), Account Status kept fully independent of Subscription Status.

## Phase 3 — Security scoring
Email Security Score isolated from any Account that uses the email. Service Security Overview reworked to report all five states separately instead of collapsing anything into "Unknown".

## Phase 4 — Subscription & Payment
Full Subscription and Payment sections on the Account Profile, conditional UI for Free plans, cost reporting grouped by currency and billing cycle (never summed across either), and the reusable Renewals widget.

## Phase 5 — Tags, custom fields, notes, history everywhere
Tag attach/detach extended to Email, Service, and Phone (previously Account-only). A single `log_history()` helper adopted across every module. Phone↔Email linking UI added (the join table existed since Phase 0 but had no UI until this phase).

## Phase 6 — Relationship visibility
Email Usage Map, global Search across all entities and their tags/notes/custom fields/payment references, and a pass that added the direct one-click links between profiles that were still missing (Service → Account, Account → Service, Phone → Account/Service).

## Phase 7 — Needs Attention & Dashboard
Three-tier issue engine (Critical/Warning/Informational) applied to both Emails and Accounts, correctly excluding Closed/Abandoned/Archived records. Root dashboard with independent Email/Account security overviews, subscription renewals and costs, recent activity, and quick actions.

## Phase 8 — CSV Import
Full wizard: file upload → column detection → mapping → preview → validation → New/Exact/Possible-Duplicate detection → confirmation → import, for Emails, Services, Phones, and Accounts. Never overwrites an existing record without an explicit per-row choice.

## Phase 9 — Final polish
Delete added for every entity (distinct from Archive and Unlink, with confirmation dialogs everywhere sec. 51 requires them), the last two dead navigation links (`settings.php`, `import-export.php` hub) completed, a full mobile-responsiveness pass, and a final audit confirming the five-state model is never collapsed anywhere in the codebase.

## Not yet built (by design)

- TXT file import
- Export (CSV/TXT) for Emails, Accounts, Services, Subscriptions, Security Reports
- Reusable import templates
- Translating `install.php` (deliberately skipped — see v1.3.0 note)
- A dedicated LTR Bootstrap build for a fully polished English layout
