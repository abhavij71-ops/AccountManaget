# Security Policy

## What this application stores — and what it never does

Account Manager is explicitly **not** a password manager (see [docs/ABOUT.md](docs/ABOUT.md)). By design, no field in the database schema or user interface accepts:

- Passwords
- API keys or access tokens
- Real MFA/recovery codes
- CVV or full card numbers

Payment records only ever store a card's **last 4 digits**, enforced at the database level with a `CHECK` constraint. Everywhere else, the app only stores *pointers* — e.g. "Credential Storage: KeePass".

If you find a field, form, or export that stores or exposes a secret, please report it — see below.

## Application-level protections already in place

- All SQL access goes through PDO prepared statements (no string-built queries anywhere)
- Passwords are hashed with PHP's `password_hash()` (bcrypt) and verified with `password_verify()`
- Every state-changing form is protected by a per-session CSRF token
- Output is escaped via `htmlspecialchars()` on every user-controlled value rendered into HTML
- Destructive actions (Delete, Archive, Unlink) require an explicit client-side confirmation in addition to the server-side check
- The `data/` directory (containing the SQLite database) is blocked from direct web access via `.htaccess`, with a `data/index.php` fallback in case `.htaccess` is ignored by the host
- Session cookies are `HttpOnly`, `SameSite=Lax`, and marked `Secure` automatically when served over HTTPS

## Reporting a vulnerability

This is a self-hosted, single-admin tool with no central server or hosted instance operated by the maintainers — each deployment is independent and under the operator's own control. If you find a security issue in the application code itself (not in a specific deployment), please open a GitHub issue with a clear description and reproduction steps, or reach out through one of the contact channels listed in the [README](README.md#contact--support).

Please do not include real personal data, credentials, or any sensitive information in a public issue.

## Your responsibility as an operator

- Keep PHP and your web server up to date
- Ensure `data/` is genuinely inaccessible from the web after installing (see [docs/INSTALLATION.md](docs/INSTALLATION.md#4-confirm-data-is-protected))
- Delete `install.php` after setup
- Serve the application over HTTPS
- Back up `data/database.sqlite` regularly, and store backups with the same care you'd give any file containing account metadata
