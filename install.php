<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

function installSchemaStatements(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS phones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_number TEXT NOT NULL UNIQUE,
            country TEXT,
            label TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\',
            is_primary INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS phone_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL UNIQUE REFERENCES phones(id) ON DELETE CASCADE,
            sim_pin_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (sim_pin_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            port_out_lock TEXT NOT NULL DEFAULT \'Not Set\' CHECK (port_out_lock IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            carrier TEXT,
            esim INTEGER,
            last_security_check TEXT,
            security_score INTEGER CHECK (security_score IS NULL OR (security_score BETWEEN 0 AND 100)),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_address TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name TEXT,
            provider TEXT,
            type TEXT NOT NULL DEFAULT \'Not Set\' CHECK (type IN (\'Personal\',\'Work\',\'Business\',\'Project\',\'Secondary\',\'Temporary\',\'Other\',\'Not Set\')),
            purpose TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Suspended\',\'Disabled\',\'Abandoned\',\'Unknown\')),
            created_date TEXT,
            last_verified TEXT,
            notes TEXT,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_emails_status ON emails(status)',
        'CREATE INDEX IF NOT EXISTS idx_emails_type ON emails(type)',
        'CREATE INDEX IF NOT EXISTS idx_emails_favorite ON emails(is_favorite)',

        'CREATE TABLE IF NOT EXISTS email_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id INTEGER NOT NULL UNIQUE REFERENCES emails(id) ON DELETE CASCADE,
            twofa_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (twofa_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            twofa_method TEXT,
            passkey_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (passkey_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_key_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_key_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_questions_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_questions_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            last_security_check TEXT,
            recovery_email_id INTEGER REFERENCES emails(id) ON DELETE SET NULL,
            recovery_phone_id INTEGER REFERENCES phones(id) ON DELETE SET NULL,
            recovery_codes_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (recovery_codes_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_codes_reference TEXT,
            backup_method TEXT,
            last_recovery_verification TEXT,
            security_score INTEGER CHECK (security_score IS NULL OR (security_score BETWEEN 0 AND 100)),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_email_security_recovery_email ON email_security(recovery_email_id)',
        'CREATE INDEX IF NOT EXISTS idx_email_security_recovery_phone ON email_security(recovery_phone_id)',

        'CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name TEXT NOT NULL,
            website TEXT,
            login_url TEXT,
            category TEXT NOT NULL DEFAULT \'Not Set\',
            status TEXT NOT NULL DEFAULT \'Unknown\',
            purpose TEXT,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_services_name_unique ON services(service_name COLLATE NOCASE)',
        'CREATE INDEX IF NOT EXISTS idx_services_category ON services(category)',

        'CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
            email_id INTEGER REFERENCES emails(id) ON DELETE RESTRICT,
            identity_type TEXT NOT NULL DEFAULT \'email\' CHECK (identity_type IN (\'email\',\'phone\',\'username\',\'other\')),
            identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT,
            username TEXT,
            display_name TEXT,
            external_account_id TEXT,
            account_url TEXT,
            login_url TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Suspended\',\'Disabled\',\'Closed\',\'Abandoned\',\'Pending\',\'Unknown\')),
            account_type TEXT NOT NULL DEFAULT \'Not Set\' CHECK (account_type IN (\'Personal\',\'Work\',\'Business\',\'Project\',\'Other\',\'Not Set\')),
            created_date TEXT,
            last_login TEXT,
            last_verified TEXT,
            notes TEXT,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            CHECK (
                (identity_type = \'email\'    AND email_id IS NOT NULL) OR
                (identity_type = \'phone\'    AND identity_phone_id IS NOT NULL) OR
                (identity_type = \'username\' AND username IS NOT NULL) OR
                (identity_type = \'other\')
            )
        )',
        'CREATE INDEX IF NOT EXISTS idx_accounts_service ON accounts(service_id)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_email ON accounts(email_id)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_status ON accounts(status)',
        'CREATE INDEX IF NOT EXISTS idx_accounts_identity_phone ON accounts(identity_phone_id)',

        'CREATE TABLE IF NOT EXISTS account_security (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            twofa_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (twofa_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            twofa_method TEXT,
            passkey_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (passkey_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_key_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_key_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            security_questions_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (security_questions_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            last_security_check TEXT,
            credential_storage TEXT,
            credential_reference TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS account_recovery (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (status IN (\'Verified\',\'Not Verified\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_email_id INTEGER REFERENCES emails(id) ON DELETE SET NULL,
            recovery_phone_id INTEGER REFERENCES phones(id) ON DELETE SET NULL,
            recovery_contact TEXT,
            recovery_codes_status TEXT NOT NULL DEFAULT \'Not Set\' CHECK (recovery_codes_status IN (\'Enabled\',\'Disabled\',\'Unknown\',\'Not Set\',\'Not Applicable\')),
            recovery_codes_reference TEXT,
            backup_method TEXT,
            last_recovery_verification TEXT,
            recovery_notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_account_recovery_email ON account_recovery(recovery_email_id)',
        'CREATE INDEX IF NOT EXISTS idx_account_recovery_phone ON account_recovery(recovery_phone_id)',

        'CREATE TABLE IF NOT EXISTS phone_email (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL REFERENCES phones(id) ON DELETE CASCADE,
            email_id INTEGER NOT NULL REFERENCES emails(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (phone_id, email_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_phone_email_email ON phone_email(email_id)',
        'CREATE INDEX IF NOT EXISTS idx_phone_email_phone ON phone_email(phone_id)',

        'CREATE TABLE IF NOT EXISTS phone_account (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_id INTEGER NOT NULL REFERENCES phones(id) ON DELETE CASCADE,
            account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (phone_id, account_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_phone_account_account ON phone_account(account_id)',
        'CREATE INDEX IF NOT EXISTS idx_phone_account_phone ON phone_account(phone_id)',

        'CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT \'Unknown\' CHECK (type IN (\'Free\',\'Paid\',\'Trial\',\'Promotional\',\'Lifetime\',\'Enterprise\',\'Unknown\',\'Not Applicable\')),
            plan TEXT,
            status TEXT NOT NULL DEFAULT \'Unknown\' CHECK (status IN (\'Active\',\'Cancelled\',\'Expired\',\'Paused\',\'Unknown\',\'Not Applicable\')),
            price REAL,
            currency TEXT,
            billing_cycle TEXT NOT NULL DEFAULT \'Not Applicable\' CHECK (billing_cycle IN (\'Monthly\',\'Yearly\',\'Weekly\',\'Quarterly\',\'One-Time\',\'Custom\',\'Unknown\',\'Not Applicable\')),
            start_date TEXT,
            renewal_date TEXT,
            auto_renewal INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_renewal ON subscriptions(renewal_date)',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_type ON subscriptions(type)',
        'CREATE INDEX IF NOT EXISTS idx_subscriptions_currency ON subscriptions(currency)',

        'CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
            payment_required INTEGER NOT NULL DEFAULT 0,
            payment_method TEXT,
            card_brand TEXT,
            last4 TEXT CHECK (last4 IS NULL OR (length(last4) = 4 AND last4 GLOB \'[0-9][0-9][0-9][0-9]\')),
            payment_reference TEXT,
            auto_renewal INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',

        'CREATE TABLE IF NOT EXISTS taggables (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
            entity_type TEXT NOT NULL CHECK (entity_type IN (\'email\',\'service\',\'account\',\'phone\')),
            entity_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (tag_id, entity_type, entity_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_taggables_entity ON taggables(entity_type, entity_id)',

        'CREATE TABLE IF NOT EXISTS custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            field_key TEXT NOT NULL,
            field_value TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (account_id, field_key)
        )',
        'CREATE INDEX IF NOT EXISTS idx_custom_fields_account ON custom_fields(account_id)',

        'CREATE TABLE IF NOT EXISTS history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL CHECK (entity_type IN (\'email\',\'service\',\'account\',\'phone\')),
            entity_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            field_name TEXT,
            old_value TEXT,
            new_value TEXT,
            changed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )',
        'CREATE INDEX IF NOT EXISTS idx_history_entity ON history(entity_type, entity_id)',
        'CREATE INDEX IF NOT EXISTS idx_history_created ON history(created_at)',
    ];
}

function installTriggerStatements(): array
{
    $tablesWithUpdatedAt = [
        'users', 'emails', 'email_security', 'services', 'accounts',
        'account_security', 'account_recovery', 'phones', 'phone_security', 'subscriptions',
        'payments', 'custom_fields',
    ];

    $statements = [];
    foreach ($tablesWithUpdatedAt as $table) {
        $statements[] = "CREATE TRIGGER IF NOT EXISTS trg_{$table}_updated_at
            AFTER UPDATE ON {$table}
            FOR EACH ROW
            BEGIN
                UPDATE {$table} SET updated_at = datetime('now') WHERE id = NEW.id;
            END";
    }

    return $statements;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function adminExists(PDO $pdo): bool
{
    if (!tableExists($pdo, 'users')) {
        return false;
    }
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    return ((int) $stmt->fetchColumn()) > 0;
}

$error = '';
$success = false;
$alreadyInstalled = false;

$pdo = db();

if (adminExists($pdo)) {
    $alreadyInstalled = true;
}

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $fullName = trim((string) ($_POST['full_name'] ?? ''));

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($username === '' || mb_strlen($username) < 3) {
        $error = 'نام کاربری باید حداقل ۳ کاراکتر باشد.';
    } elseif ($password === '' || mb_strlen($password) < 8) {
        $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'رمز عبور و تکرار آن یکسان نیستند.';
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->exec('PRAGMA foreign_keys = OFF');
            foreach (installSchemaStatements() as $sql) {
                $pdo->exec($sql);
            }
            foreach (installTriggerStatements() as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec('PRAGMA foreign_keys = ON');

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$username, $hash, $fullName !== '' ? $fullName : null]);

            $pdo->commit();
            $success = true;
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'خطا در نصب: ' . $e->getMessage();
        }
    }
} elseif (!$alreadyInstalled) {
    try {
        $pdo->beginTransaction();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach (installSchemaStatements() as $sql) {
            $pdo->exec($sql);
        }
        foreach (installTriggerStatements() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'خطا در ایجاد schema: ' . $e->getMessage();
    }
}

$csrf = csrfToken();
// No language session exists yet at install time — default to RTL.
$bs = 'bootstrap.rtl.min.css';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نصب | <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars('assets/css/' . $bs, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:460px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted text-center mb-4">نصب اولیه سیستم</p>

            <?php if ($alreadyInstalled && !$success): ?>
                <div class="alert alert-info">
                    سیستم قبلاً نصب شده است. برای ورود از صفحه ورود استفاده کنید.
                </div>
                <a href="login.php" class="btn btn-primary w-100">رفتن به صفحه ورود</a>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    نصب با موفقیت انجام شد. اکنون می‌توانید وارد شوید.
                </div>
                <a href="login.php" class="btn btn-primary w-100">رفتن به صفحه ورود</a>
                <p class="text-danger small mt-3 mb-0 text-center">
                    برای امنیت بیشتر، فایل install.php را از روی سرور حذف کنید.
                </p>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <p class="text-muted small">دیتابیس و جداول با موفقیت آماده شدند. برای تکمیل نصب، حساب مدیر سیستم را بسازید.</p>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">نام کاربری مدیر</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               minlength="3" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="full_name" class="form-label">نام کامل (اختیاری)</label>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">رمز عبور</label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">تکرار رمز عبور</label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">ایجاد حساب مدیر و تکمیل نصب</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
