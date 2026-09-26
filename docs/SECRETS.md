# Secrets

Account Manager needs a handful of secret values — a shared cron token, an
encryption key, the platform-admin password, a payment-gateway merchant id.
None of them are ever written into the source code. Each one is read
through a single function, `loadSecret()` (`includes/secrets.php`):

1. First, an environment variable of the same name.
2. If that isn't set (or is empty) — the common case on shared hosting,
   where you often can't set environment variables at all — a plain PHP
   file, `account-manager-secrets.php`, kept **one directory above** the
   application's own folder.

You only need one of the two methods per secret; most shared-hosting users
will use the file for everything.

## Where to put the file

If the app is deployed at, say:

```
/home/youruser/public_html/          <- the app itself (this repo's files)
```

then the secrets file goes at:

```
/home/youruser/account-manager-secrets.php
```

— one level up from the app's own folder, **outside** anything a web
server could ever be configured to serve directly. This is deliberate:
even a server misconfiguration that started serving `.php` files as plain
text somewhere inside the app folder could never expose this file, because
it isn't inside that folder at all.

On typical cPanel/shared hosting, `public_html/` (or whatever your document
root is set to) *is* the app's own folder, so the secrets file sits right
next to it, one level up — never inside `public_html/`.

## Sample file

Create `account-manager-secrets.php` with exactly this shape:

```php
<?php
return [
    'CRON_TOKEN' => 'replace-with-a-long-random-value',
    'ADMIN_PASSWORD' => 'replace-with-a-strong-password',
    'ZARINPAL_MERCHANT_ID' => 'your-zarinpal-merchant-id',
    'MAIL_ENCRYPTION_KEY' => 'replace-with-a-long-random-value',
];
```

Fill in only the keys you actually need. A key that's missing (or left as
an empty string) is treated as "not configured": cron requests are
refused, the admin panel can never be signed into, ZarinPal payments can't
start, or the SMTP/SMS password can't be saved — respectively. The admin
dashboard also shows a warning banner listing whichever of these (besides
`ADMIN_PASSWORD`, which you'd already need to be signed in with) are still
empty.

## Generating each value

| Key | What it's for | How to generate it |
|---|---|---|
| `CRON_TOKEN` | Authorizes `cron.php` when it's triggered over HTTP — a hosting provider's "cron via URL" feature, or `curl` from a system crontab. Not needed at all when running `php cron.php` from an actual terminal. | A random value: `php -r "echo bin2hex(random_bytes(32));"` (or `openssl rand -hex 32`). |
| `ADMIN_PASSWORD` | The one shared password for the platform-operator panel at `/admin/` — entirely separate from any tenant's own login. | A strong, unique password — your password manager's generator, or `openssl rand -base64 24`. |
| `ZARINPAL_MERCHANT_ID` | Identifies your merchant account to the ZarinPal payment gateway (`billing.php`). | Not generated — copy it from your ZarinPal merchant dashboard. |
| `MAIL_ENCRYPTION_KEY` | Encrypts the SMTP password and the SMS provider API key before either is stored in the database. | A random value: `openssl rand -hex 32`. Changing it after a password/key has already been saved makes that saved value undecryptable — re-enter and re-save it afterward. |

## Verifying it worked

Sign in at `/admin/` with `ADMIN_PASSWORD` — the dashboard warns about any
of the other three that's still empty. `CRON_TOKEN` and
`MAIL_ENCRYPTION_KEY` matter immediately (outgoing mail can't be sent, and
`cron.php` refuses every HTTP request, without them); `ZARINPAL_MERCHANT_ID`
only matters once you actually want to accept payments.
