# Identity Model & Proposed Change — Account Manager

🇮🇷 [فارسی](IDENTITY-MODEL.fa.md)

This document answers one design question — **does Phone have an independent identity?** — and proposes a change that is necessary for Iranian services such as Divar.

---

## 1. Current state

### Phone as an entity — yes, independent

```sql
CREATE TABLE phones (
    id, phone_number UNIQUE, country, label, status, is_primary, notes, ...
);
CREATE TABLE phone_email   (phone_id, email_id);    -- many-to-many
CREATE TABLE phone_account (phone_id, account_id);  -- many-to-many
```

Phone has its own table, is created and edited on its own, has a profile page, accepts tags, and can link to multiple Emails and multiple Accounts simultaneously. By that measure it is a fully first-class entity.

### Phone as an account identity — no, it is not

```sql
CREATE TABLE accounts (
    service_id INTEGER NOT NULL REFERENCES services(id),
    email_id   INTEGER NOT NULL REFERENCES emails(id),   -- ← mandatory
    ...
);
```

`email_id` is `NOT NULL`. That means **no account can exist without an email**. The Phone↔Account relation goes through the `phone_account` join table, which is a side link — not an identity owner.

Net result: Phone is an **attribute** of an account, not its **identity anchor**.

---

## 2. Why this is a real problem

Many Iranian and regional services register by phone number only and have no email field at all:

| Service | Registration method |
|---|---|
| Divar | Mobile number only |
| Snapp / Tapsi | Mobile number only |
| Telegram | Mobile number only |
| WhatsApp | Mobile number only |
| Bale / Eitaa / Rubika | Mobile number only |
| Iranian banking apps | Mobile number + national ID |

To record a Divar account today, the user must do one of three bad things:

1. Create a fake email (e.g. `divar-placeholder@none.local`) → pollutes the database and corrupts Email statistics
2. Force-link an unrelated email → creates a false relationship, which is exactly what the product's "Relationship Visibility" principle exists to prevent
3. Not record the account at all → the product's core purpose fails

All three are bad. This is a schema limitation, not a deliberate design decision.

---

## 3. Proposed change: identity anchor

The correct concept is that every account has exactly **one identity anchor**, which is either an Email or a Phone.

### Schema change

```sql
-- 1. make email_id optional
-- 2. add phone_id as an alternative anchor
-- 3. store the anchor type explicitly

ALTER TABLE accounts ADD COLUMN identity_type TEXT NOT NULL DEFAULT 'email'
    CHECK (identity_type IN ('email','phone','username','other'));
ALTER TABLE accounts ADD COLUMN identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT;
```

Plus the integrity constraint:

```sql
CHECK (
    (identity_type = 'email'    AND email_id IS NOT NULL) OR
    (identity_type = 'phone'    AND identity_phone_id IS NOT NULL) OR
    (identity_type = 'username' AND username IS NOT NULL) OR
    (identity_type = 'other')
)
```

> **SQLite note:** you cannot add a CHECK to an existing table. You must create a new table, copy the data and rename. Back up `data/database.sqlite` first.

### Migrating existing data

```sql
UPDATE accounts SET identity_type = 'email' WHERE email_id IS NOT NULL;
```
Every current record correctly becomes `email`. No data is lost.

---

## 4. Knock-on changes

| Area | Required change |
|---|---|
| Add / Quick Add form | Pick "identity type" first; show the Email or Phone field accordingly |
| Edit form | Same pattern, plus the ability to change the anchor type |
| Account Profile | Header shows "Identity" with a matching icon instead of a fixed Email |
| Accounts list | The Email column becomes an "Identity" column |
| Email Profile → Usage Map | Unchanged (only counts accounts with `identity_type='email'`) |
| Phone Profile | New section: "Accounts created with this number" — distinct from "Linked accounts" |
| Needs Attention | New rule: phone-anchored account with no recovery = Critical (losing the SIM means losing the account entirely) |
| Global Search | Also search `identity_phone_id` |
| CSV Import | Add `identity_type` and `phone` to the column map |
| Security Score | A phone anchor needs its own security score (SIM PIN status, port-out lock) |

---

## 5. Positive side effect: a security score for Phone

Once a Phone can anchor an identity, the security of the number itself starts to matter. A `phone_security` table parallel to `email_security` becomes justified:

```sql
CREATE TABLE phone_security (
    phone_id INTEGER UNIQUE REFERENCES phones(id) ON DELETE CASCADE,
    sim_pin_status      TEXT DEFAULT 'Not Set',   -- five-state
    port_out_lock       TEXT DEFAULT 'Not Set',   -- carrier port-out lock
    carrier             TEXT,
    esim                INTEGER,
    last_security_check TEXT,
    security_score      INTEGER
);
```

This matters practically for the Iranian market: SIM-swap is the primary way phone-anchored accounts are lost, and a user who doesn't know which of their accounts are protected only by a phone number cannot assess that risk.

---

## 6. Short answer

**Does Phone have an independent identity?**
As an entity — yes. As an account identity anchor — no, and that is a real limitation worth fixing.

**Should the change be made?**
Yes. Given the target market (Iranian and Iraqi users), phone-anchored accounts are not an edge case but a substantial share of real usage. Without this change, the app cannot correctly record a large portion of a user's accounts.

**Priority:** high — before large volumes of data are entered, since migrating later gets harder.
