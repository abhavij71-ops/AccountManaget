# About Account Manager

🇮🇷 [این صفحه به فارسی](ABOUT.fa.md)

## The problem

As the number of online services you use grows, so does the tangle of connections between them: which email you signed up with, which phone number backs up which account, which subscriptions are actually still costing you money, and which accounts you haven't touched — or secured — in years.

None of this is hard to track individually. It's hard to track **together**. A password manager tells you a login exists; it doesn't tell you that the recovery phone on your old work email is a number you no longer own, or that three "free" accounts quietly turned into paid subscriptions, or that your personal Gmail is the recovery address for twelve other accounts you'd forgotten about.

## What Account Manager is

A single, self-hosted place to record the **metadata** of your digital life — not your secrets, your *relationships*:

```
Email
 ├── used to create → Account (GitHub)
 ├── used to create → Account (AWS)
 └── recovery phone  → Phone (+31 ...)

Phone
 ├── recovery for    → Email (work@...)
 └── linked to       → Account (Google)
```

Four entities — **Email, Service, Account, Phone** — and the relationships between them are the whole product. Everything else (security scores, renewal tracking, the Needs Attention list, search) exists to answer questions *about* those relationships.

## What it deliberately is not

- **Not a password manager.** There is no field anywhere in the schema for a password, API key, access token, real recovery code, CVV, or full card number. If you try to put a secret in a Notes field, that's on you — but the product is designed around never needing to.
- **Not multi-user.** One admin account, by design.
- **Not a connector.** It never logs into your real accounts, never scrapes anything, never talks to any external API. Everything in it is what you typed in.

If you need a password manager, use one — and record *which* one, and how to find your entries in it, in this tool's `Credential Storage` / `Credential Reference` fields.

## Who it's for

- Anyone with more than a handful of online accounts who has lost track of which email or phone backs up what
- People doing a personal security review — "how many of my accounts still have 2FA off?" — across accounts, not just within one service's settings page
- Freelancers or small teams managing client accounts across many services, who need a shared reference (self-hosted, so it stays private) for which email/phone was used where
- Anyone who's been burned once by a stale recovery phone number and wants to actually *know*, going forward, where every recovery path leads

## Real use cases

### "I'm about to cancel my old phone number — what's connected to it?"

Open the Phone's profile. You immediately see every Email that uses it as a recovery number, and every Account it's directly linked to. Update each one *before* you cancel the number, not after you're locked out.

### "How much am I actually spending on subscriptions, and in what currencies?"

The dashboard and the dedicated Costs page total your active paid subscriptions **grouped by currency and billing cycle** — a $9.99/month subscription and a €120/year subscription are never silently added together into a meaningless number.

### "Which of my accounts have 2FA disabled?"

The Needs Attention page lists every account and email with 2FA off as a **Critical** item — but skips anything you've already marked Closed, Abandoned, or Archived, so the list stays a real to-do list instead of noise.

### "I inherited/reviewed a pile of exported account data — can I get it in without duplicating everything?"

The CSV import wizard maps your file's columns to the right fields, flags exact and possible duplicates before anything is written, and only ever updates an existing record if you explicitly choose to.

### "Where else did I use this email address?"

Open the Email's profile. The Usage Map shows exactly how many accounts, across how many services and categories, use it — with a direct link to each one.

## Design principles behind the product

1. **Relationships are never more than one click away.** Every profile page links directly to everything it's connected to.
2. **Unknown is not the same as empty, and empty is not the same as N/A.** The five-state model (`Enabled` / `Disabled` / `Unknown` / `Not Set` / `Not Applicable`) is preserved and visually distinguished everywhere — collapsing these into "no value" would hide real information.
3. **Nothing is silently destructive.** Archiving, deleting, and unlinking are three different operations with three different consequences, and each requires confirmation.
4. **An email's security is its own.** The security posture of the accounts that use an email never inflates or deflates that email's own security score.
