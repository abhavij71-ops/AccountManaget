# Roadmap: From Personal Tool to Small SaaS

🇮🇷 [فارسی](ROADMAP-SAAS.fa.md)

This document lays out, phase by phase, how Account Manager would become a team/SaaS product — including the architectural decisions and the things that deliberately should not be built.

---

## Read this before starting

The original product spec (section 52) explicitly put multi-user out of scope for v1. That was the right call, and it is why the app came out clean and fast. But turning it into a SaaS is **a fundamental architectural change, not an added module**.

Two consequences to accept up front:

**1. The security liability multiplies.** This app doesn't store passwords, but it stores something an attacker sometimes values more: a complete map of *who, with which email, on which services, with what 2FA status and what recovery method*. That is a full reconnaissance document. Once that map exists for dozens of organizations on one server, you have become a high-value target.

**2. Shared hosting stops being enough.** For a single-team self-hosted build, fine. But a real SaaS needs reliable cron, outbound email, automated backups and control over the PHP version. From phase 15 onward you need a VPS.

---

## Phase 10 — Ownership layer (prerequisite for everything)

None of the later phases are possible without this. Right now no record has an owner.

### Architectural decision: one database per workspace

There are two options:

| Approach | Advantage | Drawback |
|---|---|---|
| **Shared schema** — a `workspace_id` column on every table | One file, easy cross-tenant reporting | One forgotten `WHERE` = data leak between customers |
| **One SQLite file per workspace** ✅ | Physical isolation, export = copy one file, delete = delete one file, per-tenant write lock | Schema migration must loop over every file |

**For SQLite the second option is clearly better.** SQLite's main weakness — the single-writer lock — is *solved* rather than aggravated by this layout: each team gets its own lock, and one busy customer never affects the others.

```
data/
├── platform.sqlite            ← users, workspaces, memberships, subscriptions
└── workspaces/
    ├── ws_000001.sqlite       ← first team's data (the current 16 tables)
    ├── ws_000002.sqlite
    └── ...
```

### Central database

```sql
CREATE TABLE accounts_users (          -- platform users
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    full_name TEXT,
    preferred_language TEXT DEFAULT 'fa',
    timezone TEXT DEFAULT 'Asia/Tehran',
    is_active INTEGER NOT NULL DEFAULT 1,
    email_verified_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE workspaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    db_file TEXT NOT NULL UNIQUE,      -- ws_000001.sqlite
    owner_user_id INTEGER NOT NULL REFERENCES accounts_users(id),
    plan TEXT NOT NULL DEFAULT 'free',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES accounts_users(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('owner','admin','member','viewer')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (workspace_id, user_id)
);
```

### Required code changes

1. `db.php` must open the right file based on `$_SESSION['workspace_id']`.
2. Build a **migration runner**: a script that reads each file's schema version and applies any outstanding migrations. Without it, every schema change becomes impossible from tomorrow onward.
3. A `schema_version` table inside each workspace file.
4. Migrate the current install: rename the existing database to `ws_000001.sqlite` and create a workspace for it.

---

## Phase 11 — Teams and roles

### Four roles, no more

| Role | Permissions |
|---|---|
| **Owner** | Everything + delete workspace + manage subscription |
| **Admin** | All data + invite/remove members |
| **Member** | Create and edit own data; view shared data |
| **Viewer** | Read-only |

Do not build fine-grained per-entity permissions — the original spec rejected it in section 52, and in practice no small team ever uses it.

### Record ownership inside a workspace

```sql
-- on all four main tables
ALTER TABLE emails   ADD COLUMN owner_user_id INTEGER;
ALTER TABLE accounts ADD COLUMN owner_user_id INTEGER;
ALTER TABLE services ADD COLUMN owner_user_id INTEGER;
ALTER TABLE phones   ADD COLUMN owner_user_id INTEGER;

-- visibility level
ALTER TABLE emails   ADD COLUMN visibility TEXT NOT NULL DEFAULT 'workspace'
    CHECK (visibility IN ('private','workspace'));
```

**Rule:** `private` means only the record's owner and the workspace owner can see it. `workspace` means every member can.

### Member invitations

```sql
CREATE TABLE invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    role TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    accepted_at TEXT,
    invited_by INTEGER NOT NULL
);
```

Generate tokens with `bin2hex(random_bytes(32))` and expire them after 7 days.

---

## Phase 12 — Intra-team privacy

This phase is usually forgotten, and its absence destroys trust later.

1. **An employee must be able to mark a personal record `private`.** If an admin can see everything, employees stop entering real data and the whole dataset becomes worthless.
2. **The admin should know what they cannot see.** Show "3 private records" without revealing content — transparency without violating privacy.
3. **When a member leaves,** offer three explicit choices: transfer ownership to another person / archive / delete. Never delete automatically.

---

## Phase 13 — SaaS-grade security hardening

Mandatory before the first real customer:

1. **Login rate limiting** — temporary lockout after 5 failed attempts, keyed on both IP and username
2. **2FA for the app itself** — TOTP via `RobThree/TwoFactorAuth` or a direct implementation (the algorithm is simple). Without it you cannot sell to any organization
3. **Session management** — list of active devices plus a "sign out everywhere" button
4. **An audit log separate from History** — `history` records data changes; the audit log must record *access*: who, when, which record they viewed
5. **Automated backups** — a daily cron that copies each workspace file with compression and a timestamp
6. **Security headers** — `Content-Security-Policy`, `X-Frame-Options: DENY`, `Strict-Transport-Security`
7. **Encryption at rest** — if the VPS is shared, at least evaluate SQLCipher

---

## Phase 14 — Product differentiation (the most important commercial phase)

So far you have built an organized tool. But nobody pays for "a tidier spreadsheet." These three features are what make the product sellable:

### 1. Employee offboarding workflow — the strongest option

The question every IT manager faces roughly weekly: **"Ali left the company — what did he have access to?"**

A page that, given one member, generates a full checklist: every account they owned, every service their work email is registered on, every paid subscription in their name, every number registered as a recovery method. With "done" checkboxes and a PDF export for the file.

**This is a real, sellable pain.** For 10–100 person companies in Iran and Iraq that no international tool (Torii, Zluri, BetterCloud) serves, this is an empty market.

### 2. Periodic access review

A quarterly campaign: the system sends each member their list of accounts and asks them to confirm each is still needed. Result: the data stays current and the manager gets a compliance report.

### 3. Forgotten subscription discovery (shadow SaaS)

A report: "You have 4 paid subscriptions with no login in the last 90 days — $120/month total." This feature alone justifies your own subscription price and is the best sales argument you have.

---

## Phase 15 — Notifications and automation

1. **Email** — member invitations, password resets, renewal warnings. Over SMTP (not `mail()`) with a simple table-backed queue
2. **SMS/WhatsApp** — more effective than email for the Iranian and Iraqi markets
3. **A single cron** — one `cron.php` that runs nightly: check renewals, recompute security scores, back up, purge expired tokens
4. **Outbound webhooks** — connect to Slack or Bale for Critical alerts

---

## Phase 16 — SaaS mechanics

1. **Self-service signup** with email verification and automatic workspace creation
2. **Plans with enforced limits** — e.g. free up to 3 members and 50 accounts / pro unlimited. The limit must be enforced in code, not just stated on the pricing page
3. **Payments** — for Iran: ZarinPal or a direct bank gateway. For Iraq: manual payment plus admin activation is perfectly acceptable
4. **Platform admin panel** — workspace list, subscription status, file size, ability to suspend
5. **Full export and account deletion** — the user must be able to download their own SQLite file and delete their account. This is critical for trust

---

## What not to build

1. **Fine-grained per-field or per-record permissions** — the complexity is exponential and no small team uses it
2. **Password storage** — the moment you do this, your product becomes a weak password manager competing with Bitwarden and 1Password, and losing. Keep the spec's final principle
3. **Automatic account discovery via OAuth** — requires a security review per service, token custody and legal liability. Not worth it at this scale
4. **A native mobile app** — a PWA over the same code delivers 90% of the value at 5% of the cost
5. **A framework rewrite** — as long as plain PHP works, Laravel adds only complexity and hosting cost

---

## Suggested order and sizing

| Phase | Topic | Relative size | Required for SaaS? |
|---|---|---|---|
| 10 | Ownership layer + migration runner | Large | Yes — absolute prerequisite |
| 11 | Teams and roles | Large | Yes |
| 12 | Intra-team privacy | Medium | Yes |
| 13 | Security hardening | Medium | Yes — before the first customer |
| 14 | Differentiation (offboarding) | Medium | Yes — without it there is no sale |
| 15 | Notifications and automation | Medium | Partly |
| 16 | SaaS mechanics and payments | Large | Only for the hosted edition |

**Lowest-risk path:** build phases 10–14 and sell first as a **licensed self-hosted edition**. The customer installs it on their own host, you don't take custody of other people's sensitive data, and you get real market feedback. If demand proves out, add phase 16 and hosting.
