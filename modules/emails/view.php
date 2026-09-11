<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$email = $id ? fetchEmailById($pdo, $id) : null;

if (!$email) {
    flashSet('danger', 'ایمیل مورد نظر یافت نشد.');
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

    if ($action === 'add_tag') {
        $tagName = trim((string) ($_POST['tag_name'] ?? ''));
        if ($tagName !== '') {
            attachTag($pdo, 'email', $id, $tagName);
            log_history($pdo, 'email', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', 'برچسب اضافه شد.');
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'email', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'email', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', 'برچسب حذف شد.');
    } elseif ($action === 'link_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        if ($phoneId > 0) {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO phone_email (phone_id, email_id) VALUES (?, ?)');
            $stmt->execute([$phoneId, $id]);
            $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
            $ph->execute([$phoneId]);
            log_history($pdo, 'email', $id, 'Phone Linked', null, null, (string) $ph->fetchColumn());
            flashSet('success', 'شماره تلفن متصل شد.');
        }
    } elseif ($action === 'unlink_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
        $ph->execute([$phoneId]);
        $phoneNumber = $ph->fetchColumn();
        $stmt = $pdo->prepare('DELETE FROM phone_email WHERE phone_id = ? AND email_id = ?');
        $stmt->execute([$phoneId, $id]);
        if ($phoneNumber) {
            log_history($pdo, 'email', $id, 'Phone Unlinked', null, (string) $phoneNumber, null);
        }
        flashSet('success', 'اتصال شماره تلفن قطع شد.');
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM emails WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['email', $id]);
            flashSet('success', 'ایمیل «' . $email['email_address'] . '» برای همیشه حذف شد.');
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            flashSet('danger', 'این ایمیل دارای اکانت‌های مرتبط است و قابل حذف نیست — ابتدا آن اکانت‌ها را حذف یا به ایمیل دیگری منتقل کنید.');
        }
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$email = fetchEmailById($pdo, $id);
$security = fetchEmailSecurity($pdo, $id);
$securityScore = calcEmailSecurityScore($security);
$completeness = calcEmailCompleteness($email, $security);
$issueCount = countEmailSecurityIssues($security);

$recoveryEmail = null;
if (!empty($security['recovery_email_id'])) {
    $stmt = $pdo->prepare('SELECT id, email_address FROM emails WHERE id = ?');
    $stmt->execute([$security['recovery_email_id']]);
    $recoveryEmail = $stmt->fetch() ?: null;
}

$recoveryPhone = null;
if (!empty($security['recovery_phone_id'])) {
    $stmt = $pdo->prepare('SELECT id, phone_number, label FROM phones WHERE id = ?');
    $stmt->execute([$security['recovery_phone_id']]);
    $recoveryPhone = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare('SELECT a.id, a.username, a.status, s.id AS service_id, s.service_name
    FROM accounts a JOIN services s ON s.id = a.service_id
    WHERE a.email_id = ? ORDER BY s.service_name');
$stmt->execute([$id]);
$accounts = $stmt->fetchAll();

$accountsCount = count($accounts);
$stmt = $pdo->prepare('SELECT COUNT(DISTINCT service_id) FROM accounts WHERE email_id = ?');
$stmt->execute([$id]);
$servicesCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts a JOIN subscriptions sub ON sub.account_id = a.id
    WHERE a.email_id = ? AND sub.type = 'Paid'");
$stmt->execute([$id]);
$paidAccountsCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT DISTINCT s.category FROM accounts a JOIN services s ON s.id = a.service_id
    WHERE a.email_id = ? AND s.category != 'Not Set' ORDER BY s.category");
$stmt->execute([$id]);
$usedCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

$linkedPhonesStmt = $pdo->prepare('SELECT p.id, p.phone_number, p.label FROM phones p
    JOIN phone_email pe ON pe.phone_id = p.id WHERE pe.email_id = ? ORDER BY p.phone_number');
$linkedPhonesStmt->execute([$id]);
$linkedPhones = $linkedPhonesStmt->fetchAll();
$linkedPhoneIds = array_column($linkedPhones, 'id');

$availablePhones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();
$availablePhones = array_filter($availablePhones, static fn ($p) => !in_array((int) $p['id'], $linkedPhoneIds, true));

$tags = fetchEntityTags($pdo, 'email', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['email', $id]);
$historyRows = $stmt->fetchAll();

$csrf = csrfToken();
$pageTitle = $email['email_address'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1"><?= e($email['email_address']) ?></h1>
        <div class="d-flex gap-2 flex-wrap">
            <?= renderBadge($email['type'], EMAIL_TYPES) ?>
            <?= renderBadge($email['status'], EMAIL_STATUSES) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm">ویرایش</a>
        <form method="post" class="d-inline" data-confirm="این ایمیل برای همیشه حذف شود؟ این عملیات قابل بازگشت نیست.">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-danger btn-sm">حذف</button>
        </form>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">امتیاز امنیتی</div>
                <div class="h3 mb-0"><?= $securityScore === null ? '—' : e((string) $securityScore) . '/100' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">کامل بودن پروفایل</div>
                <div class="h3 mb-0"><?= e((string) $completeness) ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">اکانت‌ها / سرویس‌ها</div>
                <div class="h3 mb-0"><?= (int) $accountsCount ?> / <?= (int) $servicesCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">اکانت‌های Paid / مسائل امنیتی</div>
                <div class="h3 mb-0"><?= (int) $paidAccountsCount ?> / <?= (int) $issueCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold">نقشه استفاده از ایمیل (Email Usage Map)</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">تعداد اکانت</dt><dd class="col-sm-9"><?= (int) $accountsCount ?></dd>
            <dt class="col-sm-3">تعداد سرویس</dt><dd class="col-sm-9"><?= (int) $servicesCount ?></dd>
            <dt class="col-sm-3">دسته‌بندی‌های استفاده‌شده</dt>
            <dd class="col-sm-9">
                <?php if (!$usedCategories): ?>
                    <?= dashOrValue(null) ?>
                <?php else: ?>
                    <?php foreach ($usedCategories as $cat): ?>
                        <span class="badge bg-light text-dark border"><?= e($cat) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </dd>
            <dt class="col-sm-3">اکانت‌های Paid</dt><dd class="col-sm-9"><?= (int) $paidAccountsCount ?></dd>
            <dt class="col-sm-3">مسائل نیازمند توجه</dt><dd class="col-sm-9"><?= (int) $issueCount ?></dd>
        </dl>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">هویت</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">نام نمایشی</dt><dd class="col-7"><?= dashOrValue($email['display_name']) ?></dd>
                    <dt class="col-5">ارائه‌دهنده</dt><dd class="col-7"><?= dashOrValue($email['provider']) ?></dd>
                    <dt class="col-5">هدف استفاده</dt><dd class="col-7"><?= dashOrValue($email['purpose']) ?></dd>
                    <dt class="col-5">تاریخ ایجاد</dt><dd class="col-7"><?= dashOrValue($email['created_date']) ?></dd>
                    <dt class="col-5">آخرین تأیید</dt><dd class="col-7"><?= dashOrValue($email['last_verified']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">یادداشت</div>
            <div class="card-body">
                <?= $email['notes'] ? nl2br(e($email['notes'])) : '<span class="text-muted fst-italic">یادداشتی ثبت نشده است.</span>' ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">امنیت ایمیل</div>
            <div class="card-body">
                <dl class="row mb-0 align-items-center">
                    <dt class="col-6">تأیید دومرحله‌ای (2FA)</dt><dd class="col-6"><?= renderBadge($security['twofa_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">روش 2FA</dt><dd class="col-6"><?= dashOrValue($security['twofa_method'] ?? null) ?></dd>
                    <dt class="col-6">Passkey</dt><dd class="col-6"><?= renderBadge($security['passkey_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">کلید امنیتی</dt><dd class="col-6"><?= renderBadge($security['security_key_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">سوالات امنیتی</dt><dd class="col-6"><?= renderBadge($security['security_questions_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">آخرین بررسی امنیتی</dt><dd class="col-6"><?= dashOrValue($security['last_security_check'] ?? null) ?></dd>
                    <dt class="col-6">روش پشتیبان</dt><dd class="col-6"><?= dashOrValue($security['backup_method'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">بازیابی (Recovery)</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">ایمیل بازیابی</dt>
                    <dd class="col-6">
                        <?php if ($recoveryEmail): ?>
                            <a href="view.php?id=<?= (int) $recoveryEmail['id'] ?>"><?= e($recoveryEmail['email_address']) ?></a>
                        <?php else: ?>
                            <span class="text-muted fst-italic">—</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6">تلفن بازیابی</dt>
                    <dd class="col-6">
                        <?php if ($recoveryPhone): ?>
                            <a href="../phones/view.php?id=<?= (int) $recoveryPhone['id'] ?>"><?= e($recoveryPhone['phone_number']) ?></a>
                        <?php else: ?>
                            <span class="text-muted fst-italic">—</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6">وضعیت کدهای بازیابی</dt><dd class="col-6"><?= renderBadge($security['recovery_codes_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6">مرجع کدهای بازیابی</dt><dd class="col-6"><?= dashOrValue($security['recovery_codes_reference'] ?? null) ?></dd>
                    <dt class="col-6">آخرین تأیید بازیابی</dt><dd class="col-6"><?= dashOrValue($security['last_recovery_verification'] ?? null) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold">شماره تلفن‌های متصل</div>
    <div class="card-body">
        <?php if (!$linkedPhones): ?>
            <p class="text-muted">هیچ شماره تلفنی به این ایمیل متصل نیست.</p>
        <?php else: ?>
            <ul class="list-unstyled">
                <?php foreach ($linkedPhones as $ph): ?>
                    <li class="d-flex justify-content-between align-items-center mb-1">
                        <a href="../phones/view.php?id=<?= (int) $ph['id'] ?>"><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' — ' . e($ph['label']) : '' ?></a>
                        <form method="post" class="d-inline" data-confirm="اتصال این شماره تلفن به این ایمیل قطع شود؟ خود شماره تلفن حذف نمی‌شود.">
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
    <div class="card-header bg-white fw-bold">اکانت‌های استفاده‌کننده از این ایمیل</div>
    <div class="card-body">
        <?php if (!$accounts): ?>
            <p class="text-muted mb-0">هنوز اکانتی با این ایمیل ثبت نشده است.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>سرویس</th><th>نام کاربری</th><th>وضعیت</th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><a href="../services/view.php?id=<?= (int) $acc['service_id'] ?>"><?= e($acc['service_name']) ?></a></td>
                            <td><a href="../accounts/view.php?id=<?= (int) $acc['id'] ?>"><?= $acc['username'] ? e($acc['username']) : 'مشاهده اکانت' ?></a></td>
                            <td><?= renderBadge($acc['status'], ACCOUNT_STATUSES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
