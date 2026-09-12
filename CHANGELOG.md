# Changelog

This project was built in sequential phases, each adding a coherent slice of functionality. Dates are omitted since this reflects build order, not a dated release history. Starting with v1.1.0, changes are tracked under semantic version numbers (`APP_VERSION` in `config.php`, shown in the sidebar and in Settings → درباره سیستم).

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
