# Account Manager

**A self-hosted PHP + SQLite system for tracking every email, service, account, and phone number you own — and how they connect to each other.**

🇮🇷 [مطالعه در فارسی](README.fa.md)

> Account Manager is **not** a password manager. It never stores passwords, API keys, CVVs, or full card numbers — only a pointer to *where* your credentials live (e.g. "KeePass / GitHub Work"). See [Security & Non-Goals](#security--non-goals).

---

## Why this exists

If you've ever tried to answer "which of my emails did I use to sign up for this?" or "how many paid subscriptions am I actually running?" by scrolling through your own memory, this tool is for you. It's a single place to record:

- Every **Email** you use, with its own security posture and a computed security score
- Every **Service** (website/platform) you have an account on
- Every **Account**, linked to exactly one Email and one Service, with its own security, recovery, subscription, and payment-reference details
- Every **Phone number**, and every Email/Account it's connected to

...and to instantly see, from any one of those, everything it touches — never more than one click away.

Read more in [docs/ABOUT.md](docs/ABOUT.md) — what it's for, who it's for, and real use cases. The full original product specification (Persian) that this application was built from is in [masster.md](masster.md).

## Features

- **Relationship-first browsing** — from any Email, Service, Account, or Phone profile, jump straight to everything connected to it
- **Email Security Score** — computed only from the email's own 2FA/passkey/recovery state, never influenced by the accounts that use it
- **Needs Attention engine** — Critical / Warning / Informational issues (disabled 2FA, missing recovery, upcoming or overdue renewals, incomplete profiles), with Closed/Abandoned/Archived records correctly excluded
- **Dashboard** — entity counts, independent Email vs. Account security overviews, subscription renewals, cost breakdown by currency, recent activity, quick actions
- **Global search** — partial match across email addresses, usernames, service names, phone numbers, tags, notes, custom fields, and payment references
- **Tags, custom fields (per account, unlimited), and free-text notes** on every entity
- **Full audit history** on every meaningful change — status changes, 2FA changes, subscription changes, links/unlinks, archive/delete
- **CSV Import** with column mapping, validation, and New / Exact Duplicate / Possible Duplicate detection — never overwrites an existing record without your explicit confirmation
- **Archive vs. Delete vs. Unlink**, correctly distinguished everywhere, each with a confirmation prompt
- Five-state field model (**Enabled / Disabled / Unknown / Not Set / Not Applicable**) kept visually distinct throughout — an unknown value is never displayed as if it were a known one
- Bootstrap 5 RTL + locally-hosted Vazirmatn font — no external CDN calls, works offline once installed

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Pure PHP 8.x — no framework, no Composer required |
| Database | SQLite (via PDO, prepared statements everywhere) |
| Frontend | Bootstrap 5 RTL + vanilla JS (no build step, no external CDN) |
| Font | Vazirmatn, self-hosted |
| Auth | Session-based, single admin account |
| Hosting | Any shared PHP 8.x host with SQLite support — no SSH needed |

## Quick start

```bash
# 1. Upload the whole project to your PHP host (or run locally with PHP's built-in server)
php -S localhost:8000

# 2. Open install.php in your browser and create the admin account
#    e.g. http://localhost:8000/install.php

# 3. Delete install.php once setup is complete
```

Full step-by-step instructions (including shared-hosting notes and the required `data/` protection) are in **[docs/INSTALLATION.md](docs/INSTALLATION.md)**.

## Project structure

```
accountmanager/
├── install.php, login.php, logout.php, index.php   ← entry points (project root)
├── search.php, needs-attention.php, settings.php,
│   import-export.php                                ← other root-level pages
├── config.php, db.php                                ← configuration + PDO connection
├── includes/                                          ← shared helpers, header/footer, auth
│   └── partials/                                      ← reusable UI fragments (e.g. renewals widget)
├── modules/
│   ├── emails/  services/  phones/  accounts/         ← index/add/edit/view per entity
│   └── import/                                        ← CSV import wizard
├── assets/                                            ← Bootstrap 5 RTL (local), Vazirmatn font, app.css
└── data/                                               ← SQLite database (blocked from direct web access)
```

## Security & Non-Goals

- **No secrets are ever stored.** No password, API key, access token, real recovery code, CVV, or full card number field exists anywhere in the schema or UI — only `Credential Storage` / `Credential Reference` free-text pointers (e.g. "1Password / Work vault") and a card's **last 4 digits**.
- All database access goes through PDO prepared statements.
- CSRF tokens on every state-changing form; passwords hashed with `password_hash()`.
- `data/` (where the SQLite file lives) is blocked from direct web access via `.htaccess`.
- This is intentionally **not**: a password manager, multi-user system, or a tool that connects to your actual accounts. It only stores metadata *about* them.

## Roadmap

Deferred by design, in scope for a future release:

- TXT file import
- Export (CSV/TXT) for Emails, Accounts, Services, Subscriptions, and Security Reports
- Reusable import templates

## Contributing

Issues and pull requests are welcome. Please keep new code consistent with the existing style: no framework, PDO prepared statements only, and no secrets in the schema, UI, or history log.

## License

MIT — see [LICENSE](LICENSE).

## Contact & Support

- [ruwadmarketing.com](https://ruwadmarketing.com)
- [navidiranian.com](https://navidiranian.com)
- [navidiranian.co.ir](https://navidiranian.co.ir)
- [joomlafaris.co.ir](https://joomlafaris.co.ir)
- [cmssupport.ir](https://cmssupport.ir)
- [cmsbaz.ir](https://cmsbaz.ir)
