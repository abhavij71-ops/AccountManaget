<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/audit.php';

requireAdminAuth();

// SMTP and SMS provider settings are platform-wide (app_settings lives in
// the central database, shared by every tenant) — they belong here, behind
// requireAdminAuth(), never behind a tenant-workspace role check. See the
// note left in settings.php where these used to live: $isOwner there means
// "owner of the current workspace", and every self-registered user owns
// one, which let any stranger who'd just signed up redirect every tenant's
// outgoing mail through their own server.

$smtpError = '';
$smtpTestError = '';
$smtpTestSuccess = false;
$registrationTestError = '';
$registrationTestSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_smtp_settings') {
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = (int) ($_POST['smtp_port'] ?? 0);
        $username = trim((string) ($_POST['smtp_username'] ?? ''));
        $password = (string) ($_POST['smtp_password'] ?? '');
        $encryption = (string) ($_POST['smtp_encryption'] ?? 'starttls');
        $fromAddress = trim((string) ($_POST['smtp_from_address'] ?? ''));
        $fromName = trim((string) ($_POST['smtp_from_name'] ?? ''));

        if ($host === '' || $port <= 0 || $fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $smtpError = 'Host, a valid port, and a valid "from" address are required.';
        } elseif (!in_array($encryption, ['starttls', 'none'], true)) {
            $smtpError = 'Choose a valid encryption mode.';
        } else {
            saveSmtpSettings([
                'smtp_host' => $host,
                'smtp_port' => (string) $port,
                'smtp_username' => $username,
                'smtp_encryption' => $encryption,
                'smtp_from_address' => $fromAddress,
                'smtp_from_name' => $fromName,
            ], $password !== '' ? $password : null);
            logAuditEvent('smtp.changed', null, null, [
                'actor' => 'platform-admin',
                'host' => $host,
                'password_changed' => $password !== '',
            ], userId: null);
            header('Location: ' . APP_BASE_URL . '/admin/settings.php');
            exit;
        }
    }

    if ($action === 'send_smtp_test') {
        // Tests whatever is currently SAVED, not whatever is sitting
        // unsaved in the form — save first, then test.
        $testRecipient = trim((string) ($_POST['smtp_test_email'] ?? ''));
        if ($testRecipient === '' || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            $smtpTestError = 'Enter a valid email address to send the test to.';
        } else {
            $smtp = getSmtpSettings();
            if ($smtp['smtp_host'] === '' || $smtp['smtp_from_address'] === '') {
                $smtpTestError = 'Save SMTP settings below first.';
            } else {
                try {
                    smtpSendMessage($smtp, $testRecipient, 'Account Manager SMTP test', '<p>This is a test message confirming outgoing mail works.</p>');
                    $smtpTestSuccess = true;
                } catch (Throwable $e) {
                    $smtpTestError = 'Test send failed: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'save_sms_provider_settings') {
        $senderNumber = trim((string) ($_POST['sms_sender_number'] ?? ''));
        $apiKey = (string) ($_POST['sms_api_key'] ?? '');

        setAppSetting('sms_sender_number', $senderNumber);
        if ($apiKey !== '') {
            setAppSetting('sms_api_key_encrypted', encryptSecret($apiKey));
        }
        header('Location: ' . APP_BASE_URL . '/admin/settings.php');
        exit;
    }

    if ($action === 'disable_registration') {
        // Turning it off never needs a test send — only turning it on does.
        setAppSetting('registration_enabled', '0');
        header('Location: ' . APP_BASE_URL . '/admin/settings.php');
        exit;
    }

    if ($action === 'test_and_enable_registration') {
        $testRecipient = trim((string) ($_POST['test_email'] ?? ''));
        if ($testRecipient === '' || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            $registrationTestError = 'Enter a valid email address to send the test to.';
        } else {
            $smtp = getSmtpSettings();
            if ($smtp['smtp_host'] === '' || $smtp['smtp_from_address'] === '') {
                $registrationTestError = 'Configure and save SMTP settings first (below).';
            } else {
                try {
                    // A synchronous send, not queueMail() — a "test" needs
                    // to prove delivery right now, not report success just
                    // because a row was written to mail_queue.
                    smtpSendMessage(
                        $smtp,
                        $testRecipient,
                        'Account Manager SMTP test',
                        '<p>This is a test message confirming outgoing mail works. Self-service registration has been enabled.</p>'
                    );
                    setAppSetting('registration_enabled', '1');
                    $registrationTestSuccess = true;
                } catch (Throwable $e) {
                    $registrationTestError = 'Test send failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$registrationEnabled = isRegistrationEnabled();
$smtpSettings = getSmtpSettings();
$smsSenderNumber = getAppSetting('sms_sender_number');
$smsApiKeyConfigured = getAppSetting('sms_api_key_encrypted') !== '';
$csrf = adminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Settings</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Settings</h1>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Workspaces</a>
            <a href="users.php" class="btn btn-outline-secondary btn-sm">Pending users</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Sign out</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-bold">Outgoing mail (SMTP)</div>
        <div class="card-body">
            <p class="text-muted small">Platform-wide — every tenant's verification and password-reset email goes through this. The password is encrypted before it is stored and is never shown again; leave it blank to keep the current one.</p>

            <?php if ($smtpError !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($smtpError) ?></div>
            <?php endif; ?>

            <form method="post" class="row g-2 mb-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_smtp_settings">
                <div class="col-md-8">
                    <label class="form-label">SMTP host</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= e($smtpSettings['smtp_host']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= e($smtpSettings['smtp_port']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" name="smtp_username" class="form-control" value="<?= e($smtpSettings['smtp_username']) ?>" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="smtp_password" class="form-control" autocomplete="new-password"
                           placeholder="<?= $smtpSettings['smtp_password_encrypted'] !== '' ? 'Leave blank to keep the current password' : '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-select">
                        <option value="starttls" <?= $smtpSettings['smtp_encryption'] === 'starttls' ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="none" <?= $smtpSettings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">From address</label>
                    <input type="email" name="smtp_from_address" class="form-control" value="<?= e($smtpSettings['smtp_from_address']) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">From name</label>
                    <input type="text" name="smtp_from_name" class="form-control" value="<?= e($smtpSettings['smtp_from_name']) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Save SMTP settings</button>
                </div>
            </form>

            <?php if ($smtpTestSuccess): ?>
                <div class="alert alert-success py-2">Test email sent successfully.</div>
            <?php endif; ?>
            <?php if ($smtpTestError !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($smtpTestError) ?></div>
            <?php endif; ?>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="send_smtp_test">
                <div class="col-md-8">
                    <label class="form-label">Send test email to</label>
                    <input type="email" name="smtp_test_email" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary w-100">Send test email</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-bold">SMS provider (Kavenegar)</div>
        <div class="card-body">
            <p class="text-muted small">One shared account for the whole install, platform-wide like SMTP above. The API key is encrypted before it is stored and is never shown again; leave it blank to keep the current one.</p>
            <form method="post" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_sms_provider_settings">
                <div class="col-md-8">
                    <label class="form-label">API key</label>
                    <input type="password" name="sms_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?= $smsApiKeyConfigured ? 'Leave blank to keep the current key' : '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sender line</label>
                    <input type="text" name="sms_sender_number" class="form-control" value="<?= e($smsSenderNumber) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save SMS settings</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-bold">Self-service registration</div>
        <div class="card-body">
            <?php if ($registrationTestSuccess): ?>
                <div class="alert alert-success py-2">Test email sent successfully — registration is now enabled.</div>
            <?php endif; ?>
            <?php if ($registrationTestError !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($registrationTestError) ?></div>
            <?php endif; ?>

            <p>
                Status:
                <?= $registrationEnabled
                    ? '<span class="badge bg-success">Enabled</span>'
                    : '<span class="badge bg-secondary">Disabled (default)</span>' ?>
            </p>

            <?php if ($registrationEnabled): ?>
                <p class="text-muted small">Anyone can create their own account and workspace from register.php while this is on.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="disable_registration">
                    <button type="submit" class="btn btn-outline-danger">Disable registration</button>
                </form>
            <?php else: ?>
                <p class="text-muted small">
                    Enabling requires sending a real test email first — a broken SMTP setup must never
                    be discovered by a new user's verification email silently vanishing.
                </p>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="test_and_enable_registration">
                    <div class="col-md-8">
                        <label class="form-label">Send test email to</label>
                        <input type="email" name="test_email" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Send test &amp; enable</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
