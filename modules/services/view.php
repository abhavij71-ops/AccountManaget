<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/security-score.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) {
    flashSet('danger', 'سرویس مورد نظر یافت نشد.');
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
            attachTag($pdo, 'service', $id, $tagName);
            log_history($pdo, 'service', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', 'برچسب اضافه شد.');
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'service', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'service', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', 'برچسب حذف شد.');
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['service', $id]);
            flashSet('success', 'سرویس «' . $service['service_name'] . '» برای همیشه حذف شد.');
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            flashSet('danger', 'این سرویس دارای اکانت‌های مرتبط است و قابل حذف نیست — ابتدا آن اکانت‌ها را حذف یا منتقل کنید.');
        }
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$tags = fetchEntityTags($pdo, 'service', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['service', $id]);
$historyRows = $stmt->fetchAll();

$csrf = csrfToken();

$stmt = $pdo->prepare('SELECT a.id, a.username, a.status, a.last_verified, e.id AS email_id, e.email_address,
        sub.plan, sub.type AS sub_type, acs.twofa_status
    FROM accounts a
    JOIN emails e ON e.id = a.email_id
    LEFT JOIN subscriptions sub ON sub.account_id = a.id
    LEFT JOIN account_security acs ON acs.account_id = a.id
    WHERE a.service_id = ?
    ORDER BY e.email_address');
$stmt->execute([$id]);
$accounts = $stmt->fetchAll();

$accountsCount = count($accounts);
$emailsCount = count(array_unique(array_column($accounts, 'email_id')));
$paidCount = count(array_filter($accounts, static fn ($a) => $a['sub_type'] === 'Paid'));
$issuesCount = count(array_filter($accounts, static fn ($a) => in_array($a['status'], ['Suspended', 'Disabled'], true) || $a['twofa_status'] === 'Disabled'));

$twofaBreakdown = tallySecurityStates($accounts, 'twofa_status');

$pageTitle = $service['service_name'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1"><?= e($service['service_name']) ?></h1>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= $service['category'] === 'Not Set' ? renderBadge('Not Set') : '<span class="badge bg-light text-dark border">' . e($service['category']) . '</span>' ?>
            <?= renderBadge($service['status'], SERVICE_STATUSES) ?>
            <?php if ($service['website']): ?>
                <a href="<?= e($service['website']) ?>" target="_blank" rel="noopener" class="small">وب‌سایت &#8599;</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm">ویرایش</a>
        <form method="post" class="d-inline" data-confirm="این سرویس برای همیشه حذف شود؟ این عملیات قابل بازگشت نیست.">
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
                <div class="text-muted small mb-1">تعداد اکانت‌ها</div>
                <div class="h3 mb-0"><?= (int) $accountsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">تعداد ایمیل‌ها</div>
                <div class="h3 mb-0"><?= (int) $emailsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">اکانت‌های Paid</div>
                <div class="h3 mb-0"><?= (int) $paidCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">مسائل (Issues)</div>
                <div class="h3 mb-0"><?= (int) $issuesCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">اطلاعات سرویس</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">آدرس ورود</dt><dd class="col-7"><?= dashOrValue($service['login_url']) ?></dd>
                    <dt class="col-5">هدف استفاده</dt><dd class="col-7"><?= dashOrValue($service['purpose']) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">یادداشت</div>
            <div class="card-body">
                <?= $service['notes'] ? nl2br(e($service['notes'])) : '<span class="text-muted fst-italic">یادداشتی ثبت نشده است.</span>' ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">وضعیت امنیتی اکانت‌های این سرویس (2FA)</div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled">فعال: <?= (int) $twofaBreakdown['Enabled'] ?></span>
                <span class="badge badge-disabled">غیرفعال: <?= (int) $twofaBreakdown['Disabled'] ?></span>
                <span class="badge badge-unknown">نامشخص: <?= (int) $twofaBreakdown['Unknown'] ?></span>
                <span class="badge badge-not-set">تنظیم‌نشده: <?= (int) $twofaBreakdown['Not Set'] ?></span>
                <span class="badge badge-not-applicable">غیرقابل‌اعمال: <?= (int) $twofaBreakdown['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold">اکانت‌های این سرویس</div>
    <div class="card-body">
        <?php if (!$accounts): ?>
            <p class="text-muted mb-0">هنوز اکانتی برای این سرویس ثبت نشده است.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>ایمیل</th><th>نام کاربری</th><th>وضعیت</th><th>پلن</th><th>2FA</th><th>آخرین تأیید</th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><a href="../emails/view.php?id=<?= (int) $acc['email_id'] ?>"><?= e($acc['email_address']) ?></a></td>
                            <td><a href="../accounts/view.php?id=<?= (int) $acc['id'] ?>"><?= $acc['username'] ? e($acc['username']) : 'مشاهده اکانت' ?></a></td>
                            <td><?= renderBadge($acc['status'], ACCOUNT_STATUSES) ?></td>
                            <td><?= dashOrValue($acc['plan']) ?></td>
                            <td><?= renderBadge($acc['twofa_status'], SECURITY_STATES) ?></td>
                            <td><?= dashOrValue($acc['last_verified']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
