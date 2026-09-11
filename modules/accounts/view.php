<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$account = $id ? fetchAccountById($pdo, $id) : null;

if (!$account) {
    flashSet('danger', 'اکانت مورد نظر یافت نشد.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'verify') {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('UPDATE accounts SET last_verified = ? WHERE id = ?');
        $stmt->execute([$today, $id]);
        log_history($pdo, 'account', $id, 'Account Updated', 'last_verified', $account['last_verified'], $today);
        flashSet('success', 'اکانت به‌عنوان تأییدشده ثبت شد.');
    } elseif ($action === 'toggle_archive') {
        $newValue = ((int) $account['is_archived']) === 1 ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE accounts SET is_archived = ? WHERE id = ?');
        $stmt->execute([$newValue, $id]);
        log_history($pdo, 'account', $id, $newValue === 1 ? 'Account Archived' : 'Account Unarchived');
        flashSet('success', $newValue === 1 ? 'اکانت آرشیو شد.' : 'اکانت از آرشیو خارج شد.');
    } elseif ($action === 'add_custom_field') {
        $key = trim((string) ($_POST['field_key'] ?? ''));
        $value = trim((string) ($_POST['field_value'] ?? ''));
        if ($key === '') {
            flashSet('danger', 'نام فیلد سفارشی الزامی است.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO custom_fields (account_id, field_key, field_value) VALUES (?, ?, ?)');
                $stmt->execute([$id, $key, $value !== '' ? $value : null]);
                log_history($pdo, 'account', $id, 'Custom Field Added', $key, null, $value);
                flashSet('success', 'فیلد سفارشی اضافه شد.');
            } catch (Throwable $e) {
                flashSet('danger', str_contains($e->getMessage(), 'UNIQUE') ? 'فیلدی با این نام قبلاً ثبت شده است.' : 'خطا در افزودن فیلد.');
            }
        }
    } elseif ($action === 'remove_custom_field') {
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT field_key FROM custom_fields WHERE id = ? AND account_id = ?');
        $stmt->execute([$fieldId, $id]);
        $key = $stmt->fetchColumn();
        if ($key) {
            $stmt = $pdo->prepare('DELETE FROM custom_fields WHERE id = ? AND account_id = ?');
            $stmt->execute([$fieldId, $id]);
            log_history($pdo, 'account', $id, 'Custom Field Removed', (string) $key);
            flashSet('success', 'فیلد سفارشی حذف شد.');
        }
    } elseif ($action === 'add_tag') {
        $tagName = trim((string) ($_POST['tag_name'] ?? ''));
        if ($tagName !== '') {
            attachTag($pdo, 'account', $id, $tagName);
            log_history($pdo, 'account', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', 'برچسب اضافه شد.');
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'account', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'account', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', 'برچسب حذف شد.');
    } elseif ($action === 'link_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        if ($phoneId > 0) {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO phone_account (phone_id, account_id) VALUES (?, ?)');
            $stmt->execute([$phoneId, $id]);
            $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
            $ph->execute([$phoneId]);
            log_history($pdo, 'account', $id, 'Phone Linked', null, null, (string) $ph->fetchColumn());
            flashSet('success', 'شماره تلفن متصل شد.');
        }
    } elseif ($action === 'unlink_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
        $ph->execute([$phoneId]);
        $phoneNumber = $ph->fetchColumn();
        $stmt = $pdo->prepare('DELETE FROM phone_account WHERE phone_id = ? AND account_id = ?');
        $stmt->execute([$phoneId, $id]);
        if ($phoneNumber) {
            log_history($pdo, 'account', $id, 'Phone Unlinked', null, (string) $phoneNumber, null);
        }
        flashSet('success', 'اتصال شماره تلفن قطع شد.');
    } elseif ($action === 'delete') {
        $label = $account['service_name'] . ' — ' . ($account['username'] ?: $account['email_address']);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['account', $id]);
        flashSet('success', 'اکانت «' . $label . '» برای همیشه حذف شد.');
        header('Location: index.php');
        exit;
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$account = fetchAccountById($pdo, $id);
$security = fetchAccountSecurity($pdo, $id);
$recovery = fetchAccountRecovery($pdo, $id);
$completeness = calcAccountCompleteness($account, $security, $recovery);

$recoveryEmail = null;
if (!empty($recovery['recovery_email_id'])) {
    $stmt = $pdo->prepare('SELECT id, email_address FROM emails WHERE id = ?');
    $stmt->execute([$recovery['recovery_email_id']]);
    $recoveryEmail = $stmt->fetch() ?: null;
}
$recoveryPhone = null;
if (!empty($recovery['recovery_phone_id'])) {
    $stmt = $pdo->prepare('SELECT id, phone_number FROM phones WHERE id = ?');
    $stmt->execute([$recovery['recovery_phone_id']]);
    $recoveryPhone = $stmt->fetch() ?: null;
}

$subscription = fetchSubscription($pdo, $id);
$payment = fetchPayment($pdo, $id);
$subType = $subscription['type'] ?? 'Unknown';
$isFreeSubscription = $subType === 'Free';

$stmt = $pdo->prepare('SELECT * FROM custom_fields WHERE account_id = ? ORDER BY field_key');
$stmt->execute([$id]);
$customFields = $stmt->fetchAll();

$tags = fetchEntityTags($pdo, 'account', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT p.id, p.phone_number, p.label FROM phones p
    JOIN phone_account pa ON pa.phone_id = p.id WHERE pa.account_id = ? ORDER BY p.phone_number');
$stmt->execute([$id]);
$linkedPhones = $stmt->fetchAll();
$linkedPhoneIds = array_column($linkedPhones, 'id');

$availablePhones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();
$availablePhones = array_filter($availablePhones, static fn ($p) => !in_array((int) $p['id'], $linkedPhoneIds, true));

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['account', $id]);
$historyRows = $stmt->fetchAll();

$openLoginUrl = $account['login_url'] ?: $account['account_url'];
$credentialLooksLikeUrl = $security && !empty($security['credential_reference']) && preg_match('/^https?:\/\//i', (string) $security['credential_reference']);

$csrf = csrfToken();
$pageTitle = $account['service_name'] . ' — ' . ($account['username'] ?: $account['email_address']);
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">
            <a href="../services/view.php?id=<?= (int) $account['service_id'] ?>" class="text-decoration-none"><?= e($account['service_name']) ?></a>
            <span class="text-muted">—</span>
            <?= e($account['username'] ?: $account['email_address']) ?>
            <?php if ((int) $account['is_archived'] === 1): ?>
                <span class="badge bg-secondary">آرشیوشده</span>
            <?php endif; ?>
        </h1>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= renderBadge($account['status'], ACCOUNT_STATUSES) ?>
            <?= renderBadge($account['account_type'], ACCOUNT_TYPES) ?>
            <a href="../emails/view.php?id=<?= (int) $account['email_id'] ?>" class="small"><?= e($account['email_address']) ?></a>
        </div>
    </div>
    <div class="text-end">
        <div class="text-muted small mb-1">کامل بودن پروفایل: <?= (int) $completeness ?>%</div>
        <div class="text-muted small">آخرین تأیید: <?= dashOrValue($account['last_verified']) ?></div>
    </div>
</div>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm">ویرایش</a>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="verify">
        <button type="submit" class="btn btn-outline-success btn-sm">تأیید (Verify)</button>
    </form>
    <?php if ($openLoginUrl): ?>
        <a href="<?= e($openLoginUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">باز کردن صفحه ورود</a>
    <?php endif; ?>
    <?php if ($credentialLooksLikeUrl): ?>
        <a href="<?= e($security['credential_reference']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">باز کردن مرجع Credential</a>
    <?php endif; ?>
    <form method="post" class="d-inline" data-confirm="<?= (int) $account['is_archived'] === 1 ? 'این اکانت از آرشیو خارج شود؟' : 'این اکانت آرشیو شود؟ اکانت حذف نمی‌شود و بعداً قابل بازگردانی است.' ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="toggle_archive">
        <button type="submit" class="btn btn-outline-warning btn-sm">
            <?= (int) $account['is_archived'] === 1 ? 'خروج از آرشیو' : 'آرشیو' ?>
        </button>
    </form>
    <form method="post" class="d-inline" data-confirm="این اکانت برای همیشه حذف شود؟ این عملیات قابل بازگشت نیست.">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-outline-danger btn-sm">حذف</button>
    </form>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">اطلاعات پایه</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">نام نمایشی</dt><dd class="col-7"><?= dashOrValue($account['display_name']) ?></dd>
                    <dt class="col-5">شناسه اکانت</dt><dd class="col-7"><?= dashOrValue($account['external_account_id']) ?></dd>
                    <dt class="col-5">آدرس اکانت</dt><dd class="col-7"><?= $account['account_url'] ? '<a href="' . e($account['account_url']) . '" target="_blank" rel="noopener">' . e($account['account_url']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-5">آدرس ورود</dt><dd class="col-7"><?= $account['login_url'] ? '<a href="' . e($account['login_url']) . '" target="_blank" rel="noopener">' . e($account['login_url']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-5">تاریخ ایجاد</dt><dd class="col-7"><?= dashOrValue($account['created_date']) ?></dd>
                    <dt class="col-5">آخرین ورود</dt><dd class="col-7"><?= dashOrValue($account['last_login']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">امنیت</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">تأیید دومرحله‌ای (2FA)</dt><dd class="col-6"><?= renderBadge($security['twofa_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">روش 2FA</dt><dd class="col-6"><?= dashOrValue($security['twofa_method'] ?? null) ?></dd>
                    <dt class="col-6">Passkey</dt><dd class="col-6"><?= renderBadge($security['passkey_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">کلید امنیتی</dt><dd class="col-6"><?= renderBadge($security['security_key_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">سوالات امنیتی</dt><dd class="col-6"><?= renderBadge($security['security_questions_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">آخرین بررسی امنیتی</dt><dd class="col-6"><?= dashOrValue($security['last_security_check'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">مرجع اطلاعات ورود (Credential Reference)</div>
            <div class="card-body">
                <p class="text-muted small">طبق اصل امنیتی محصول، این سیستم رمز عبور یا کد بازیابی واقعی ذخیره نمی‌کند — فقط محل نگهداری آن.</p>
                <dl class="row mb-0">
                    <dt class="col-5">محل نگهداری</dt><dd class="col-7"><?= dashOrValue($security['credential_storage'] ?? null) ?></dd>
                    <dt class="col-5">مرجع</dt><dd class="col-7"><?= dashOrValue($security['credential_reference'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">یادداشت</div>
            <div class="card-body">
                <?= $account['notes'] ? nl2br(e($account['notes'])) : '<span class="text-muted fst-italic">یادداشتی ثبت نشده است.</span>' ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">بازیابی (Recovery)</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">وضعیت بازیابی</dt><dd class="col-6"><?= renderBadge($recovery['status'] ?? null, RECOVERY_STATUSES) ?></dd>
                    <dt class="col-6">ایمیل بازیابی</dt>
                    <dd class="col-6"><?= $recoveryEmail ? '<a href="../emails/view.php?id=' . (int) $recoveryEmail['id'] . '">' . e($recoveryEmail['email_address']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-6">تلفن بازیابی</dt>
                    <dd class="col-6"><?= $recoveryPhone ? '<a href="../phones/view.php?id=' . (int) $recoveryPhone['id'] . '">' . e($recoveryPhone['phone_number']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-6">مخاطب بازیابی</dt><dd class="col-6"><?= dashOrValue($recovery['recovery_contact'] ?? null) ?></dd>
                    <dt class="col-6">وضعیت کدهای بازیابی</dt><dd class="col-6"><?= renderBadge($recovery['recovery_codes_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">مرجع کدهای بازیابی</dt><dd class="col-6"><?= dashOrValue($recovery['recovery_codes_reference'] ?? null) ?></dd>
                    <dt class="col-6">روش پشتیبان</dt><dd class="col-6"><?= dashOrValue($recovery['backup_method'] ?? null) ?></dd>
                    <dt class="col-6">آخرین تأیید بازیابی</dt><dd class="col-6"><?= dashOrValue($recovery['last_recovery_verification'] ?? null) ?></dd>
                </dl>
                <?php if (!empty($recovery['recovery_notes'])): ?>
                    <hr>
                    <div class="small"><?= nl2br(e($recovery['recovery_notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">شماره تلفن‌های متصل</div>
            <div class="card-body">
                <?php if (!$linkedPhones): ?>
                    <p class="text-muted">هیچ شماره تلفنی به این اکانت متصل نیست.</p>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($linkedPhones as $ph): ?>
                            <li class="d-flex justify-content-between align-items-center mb-1">
                                <a href="../phones/view.php?id=<?= (int) $ph['id'] ?>"><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' — ' . e($ph['label']) : '' ?></a>
                                <form method="post" class="d-inline" data-confirm="اتصال این شماره تلفن به این اکانت قطع شود؟ خود شماره تلفن حذف نمی‌شود.">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="unlink_phone">
                                    <input type="hidden" name="phone_id" value="<?= (int) $ph['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">قطع اتصال</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($availablePhones): ?>
                    <form method="post" class="d-flex gap-2 mt-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="link_phone">
                        <select name="phone_id" class="form-select form-select-sm">
                            <?php foreach ($availablePhones as $ph): ?>
                                <option value="<?= (int) $ph['id'] ?>"><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">اتصال</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Subscription</span>
                <?= renderBadge($subType, SUBSCRIPTION_TYPES) ?>
            </div>
            <div class="card-body">
                <?php if ($isFreeSubscription): ?>
                    <p class="mb-0 text-muted">
                        این اکانت رایگان است.
                        <?php if (!empty($subscription['plan'])): ?> پلن: <?= e($subscription['plan']) ?><?php endif; ?>
                    </p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-5">وضعیت</dt><dd class="col-7"><?= renderBadge($subscription['status'] ?? null, SUBSCRIPTION_STATUSES) ?></dd>
                        <dt class="col-5">پلن</dt><dd class="col-7"><?= dashOrValue($subscription['plan'] ?? null) ?></dd>
                        <dt class="col-5">قیمت</dt>
                        <dd class="col-7">
                            <?php if (isset($subscription['price']) && $subscription['price'] !== null): ?>
                                <?= e((string) ($subscription['currency'] ?? '')) ?> <?= e((string) $subscription['price']) ?>
                                / <?= renderBadge($subscription['billing_cycle'] ?? null, BILLING_CYCLES) ?>
                            <?php else: ?>
                                <?= dashOrValue(null) ?>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5">تاریخ شروع</dt><dd class="col-7"><?= dashOrValue($subscription['start_date'] ?? null) ?></dd>
                        <dt class="col-5">تاریخ تمدید</dt><dd class="col-7"><?= dashOrValue($subscription['renewal_date'] ?? null) ?></dd>
                        <dt class="col-5">تمدید خودکار</dt>
                        <dd class="col-7">
                            <?php
                            $subAuto = $subscription['auto_renewal'] ?? null;
                            echo $subAuto === null ? renderBadge('Unknown') : ($subAuto ? renderBadge('Enabled', ['Enabled' => 'بله']) : renderBadge('Disabled', ['Disabled' => 'خیر']));
                            ?>
                        </dd>
                    </dl>
                    <?php if ($subType === 'Trial' && (!empty($subscription['start_date']) || !empty($subscription['renewal_date']))): ?>
                        <div class="alert alert-info mt-3 mb-0 py-2 small">
                            دوره آزمایشی: از <?= dashOrValue($subscription['start_date'] ?? null) ?> تا <?= dashOrValue($subscription['renewal_date'] ?? null) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">Payment</div>
            <div class="card-body">
                <?php if ($isFreeSubscription): ?>
                    <p class="text-muted small mb-0 fst-italic">این Subscription رایگان است — نیازی به اطلاعات پرداخت نیست.</p>
                <?php else: ?>
                    <p class="text-muted small">شماره کامل کارت یا CVV هرگز در این سیستم ذخیره نمی‌شود — فقط ۴ رقم آخر.</p>
                    <dl class="row mb-0">
                        <dt class="col-5">پرداخت لازم است؟</dt>
                        <dd class="col-7"><?= ((int) ($payment['payment_required'] ?? 0)) === 1 ? renderBadge('Enabled', ['Enabled' => 'بله']) : renderBadge('Disabled', ['Disabled' => 'خیر']) ?></dd>
                        <dt class="col-5">روش پرداخت</dt><dd class="col-7"><?= dashOrValue($payment['payment_method'] ?? null) ?></dd>
                        <dt class="col-5">برند کارت</dt><dd class="col-7"><?= dashOrValue($payment['card_brand'] ?? null) ?></dd>
                        <dt class="col-5">شماره کارت</dt><dd class="col-7"><?= !empty($payment['last4']) ? '•••• ' . e($payment['last4']) : dashOrValue(null) ?></dd>
                        <dt class="col-5">مرجع پرداخت</dt><dd class="col-7"><?= dashOrValue($payment['payment_reference'] ?? null) ?></dd>
                        <dt class="col-5">تمدید خودکار پرداخت</dt>
                        <dd class="col-7">
                            <?php
                            $payAuto = $payment['auto_renewal'] ?? null;
                            echo $payAuto === null ? renderBadge('Unknown') : ($payAuto ? renderBadge('Enabled', ['Enabled' => 'بله']) : renderBadge('Disabled', ['Disabled' => 'خیر']));
                            ?>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold">فیلدهای سفارشی (Custom Fields)</div>
    <div class="card-body">
        <?php if (!$customFields): ?>
            <p class="text-muted">هیچ فیلد سفارشی‌ای ثبت نشده است.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($customFields as $cf): ?>
                        <tr>
                            <td class="fw-bold text-nowrap"><?= e($cf['field_key']) ?></td>
                            <td><?= dashOrValue($cf['field_value']) ?></td>
                            <td class="text-end text-nowrap">
                                <form method="post" data-confirm="این فیلد سفارشی حذف شود؟">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="remove_custom_field">
                                    <input type="hidden" name="field_id" value="<?= (int) $cf['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-2 mt-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_custom_field">
            <div class="col-md-4">
                <input type="text" name="field_key" class="form-control form-control-sm" placeholder="نام فیلد (مثلاً Team)">
            </div>
            <div class="col-md-6">
                <input type="text" name="field_value" class="form-control form-control-sm" placeholder="مقدار">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">افزودن</button>
            </div>
        </form>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold">برچسب‌ها (Tags)</div>
    <div class="card-body">
        <?php if (!$tags): ?>
            <p class="text-muted">هیچ برچسبی ثبت نشده است.</p>
        <?php else: ?>
            <div class="d-flex gap-2 flex-wrap mb-2">
                <?php foreach ($tags as $tag): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="remove_tag">
                        <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                        <button type="submit" class="badge bg-light text-dark border" style="cursor:pointer;">
                            <?= e($tag['name']) ?> &times;
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_tag">
            <input type="text" name="tag_name" class="form-control form-control-sm" list="tag-list" placeholder="نام برچسب">
            <datalist id="tag-list">
                <?php foreach ($allTagNames as $tn): ?><option value="<?= e($tn) ?>"><?php endforeach; ?>
            </datalist>
            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">افزودن برچسب</button>
        </form>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold">تاریخچه (History)</div>
    <div class="card-body">
        <?php if (!$historyRows): ?>
            <p class="text-muted mb-0">هنوز تغییری ثبت نشده است.</p>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($historyRows as $h): ?>
                    <li class="mb-2 pb-2 border-bottom">
                        <div class="d-flex justify-content-between">
                            <strong><?= e(historyActionLabel($h['action'])) ?></strong>
                            <span class="text-muted small"><?= e($h['created_at']) ?></span>
                        </div>
                        <?php if ($h['field_name'] || $h['old_value'] !== null || $h['new_value'] !== null): ?>
                            <div class="small text-muted">
                                <?= $h['field_name'] ? e($h['field_name']) . ': ' : '' ?>
                                از <?= dashOrValue($h['old_value']) ?> به <?= dashOrValue($h['new_value']) ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
