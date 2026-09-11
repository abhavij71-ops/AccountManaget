# Installation Guide

🇮🇷 [نصب به فارسی](INSTALLATION.fa.md)

## Requirements

- PHP **8.0 or newer** with the `pdo_sqlite` and `mbstring` extensions enabled (both are standard on virtually every shared host and in default PHP builds)
- Any web server that can run PHP and honor `.htaccess` (Apache with `mod_rewrite`/`mod_authz_core` is the common case on shared hosting; see the Nginx note below if you're not on Apache)
- Write access for the PHP process to the `data/` directory
- No Node.js, no Composer, no SSH access required

## 1. Get the files onto your server

Upload the entire project (all files and folders) to your web root, or to a subfolder if you want the app to live at `https://yourdomain.com/accounts/`. The app works correctly from either location — it detects its own base URL automatically.

If you're testing locally instead, PHP's built-in server is enough:

```bash
cd accountmanager
php -S localhost:8000
```

## 2. Run the installer

Open `install.php` in your browser, e.g. `https://yourdomain.com/install.php`.

- The first visit creates the SQLite database and all tables automatically.
- You'll then be asked to create the admin account: username, optional full name, and a password (minimum 8 characters).
- On success you're shown a confirmation screen with a link to the login page.

If you reload `install.php` after an admin account already exists, it will simply tell you the system is already installed — it will **never** overwrite your data or admin account.

## 3. Delete `install.php`

Once installation is complete, delete `install.php` from the server (or at minimum move it outside the web root). Leaving it in place is not a data risk — it refuses to touch an installed system — but it's an unnecessary route to remove.

```bash
rm install.php
```

## 4. Confirm `data/` is protected

The `data/` folder holds `database.sqlite`, your entire database. It ships with:

- `data/.htaccess` — denies all direct web access (works with both Apache 2.2 and 2.4 syntax)
- `data/index.php` — a defensive second layer that returns a 403 even if `.htaccess` is somehow ignored

After installing, verify this yourself: visiting `https://yourdomain.com/data/database.sqlite` directly in a browser **must** return a 403 Forbidden, not the raw file. If it doesn't, your host may have `AllowOverride None` set for your directory — contact your host or move `data/` above the web root and update `DATA_DIR` in `config.php` accordingly.

### If you're on Nginx instead of Apache

`.htaccess` has no effect on Nginx. Add a block like this to your server config instead:

```nginx
location /data/ {
    deny all;
    return 403;
}
```

## 5. Log in

Go to `login.php` (or just the site root — you'll be redirected) and sign in with the admin account you created.

## Updating

This project has no database migration tool. To add fields introduced in a future release, you would need to run the relevant `ALTER TABLE` statements yourself against `data/database.sqlite`, or start a fresh installation. Always back up `data/database.sqlite` before touching the schema.

## Backing up

The entire application state lives in a single file: `data/database.sqlite`. Back that one file up on whatever schedule suits you (a simple cron copying it off-server is enough — SQLite's file format is safe to copy while the file isn't mid-write, and PHP-FPM/Apache worker turnover between requests makes a mid-write copy extremely unlikely for a low-traffic personal tool).

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Blank white page | PHP error display is off by default (`display_errors=0` in `config.php`); check your host's PHP error log |
| "خطا در اتصال به دیتابیس" / DB connection error | The PHP process can't write to `data/` — check folder permissions (0755 on the folder is usually enough) |
| Login always fails | Password is case-sensitive; if you're certain it's right, re-run `install.php` to confirm "already installed" shows (proves the DB is intact), then check the server's PHP session configuration (`session.save_path` must be writable) |
| CSS/fonts don't load | The app was moved to a different path after install; `config.php` derives the base URL from `DOCUMENT_ROOT`, so a symlinked or aliased document root can confuse it — see the `APP_BASE_URL` computation in `config.php` |
