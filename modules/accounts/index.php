<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter = (string) ($_GET['type'] ?? '');
$serviceFilter = (int) ($_GET['service_id'] ?? 0);
$showArchived = isset($_GET['archived']);

$sortable = [
    'service' => 's.service_name',
    'email' => 'e.email_address',
    'username' => 'a.username',
    'status' => 'a.status',
    'plan' => 'sub.plan',
    'twofa' => 'acs.twofa_status',
    'last_verified' => 'a.last_verified',
];
$sort = (string) ($_GET['sort'] ?? 'service');
if (!array_key_exists($sort, $sortable)) {
    $sort = 'service';
}
$dir = (strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc') ? 'DESC' : 'ASC';

$where = [];
$params = [];

if (!$showArchived) {
    $where[] = 'a.is_archived = 0';
}
if ($q !== '') {
    $where[] = '(a.username LIKE :q OR a.display_name LIKE :q OR e.email_address LIKE :q OR s.service_name LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, ACCOUNT_STATUSES)) {
    $where[] = 'a.status = :status';
    $params['status'] = $statusFilter;
}
if ($typeFilter !== '' && array_key_exists($typeFilter, ACCOUNT_TYPES)) {
    $where[] = 'a.account_type = :type';
    $params['type'] = $typeFilter;
}
if ($serviceFilter > 0) {
    $where[] = 'a.service_id = :service_id';
    $params['service_id'] = $serviceFilter;
}

$sql = 'SELECT a.id, a.username, a.display_name, a.status, a.account_type, a.last_verified, a.is_archived,
        s.id AS service_id, s.service_name, e.id AS email_id, e.email_address,
        sub.plan, acs.twofa_status
    FROM accounts a
    JOIN services s ON s.id = a.service_id
    JOIN emails e ON e.id = a.email_id
    LEFT JOIN subscriptions sub ON sub.account_id = a.id
    LEFT JOIN account_security acs ON acs.account_id = a.id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $sortable[$sort] . ' ' . $dir;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();

function accountSortLink(string $col, string $label, string $sort, string $dir): string
{
    $newDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
    $qs = $_GET;
    $qs['sort'] = $col;
    $qs['dir'] = $newDir;
    $arrow = $sort === $col ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . e(http_build_query($qs)) . '" class="text-decoration-none text-dark">' . e($label) . $arrow . '</a>';
}

$pageTitle = 'اکانت‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">اکانت‌ها</h1>
    <div class="d-flex gap-2">
        <a href="costs.php" class="btn btn-outline-secondary btn-sm">هزینه‌ها و تمدیدها</a>
        <a href="quick-add.php" class="btn btn-primary btn-sm">+ افزودن سریع</a>
        <a href="add.php" class="btn btn-outline-primary btn-sm">+ فرم کامل</a>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <input type="text" name="q" class="form-control" placeholder="جستجو..." value="<?= e($q) ?>">
    </div>
    <div class="col-md-2">
        <select name="service_id" class="form-select">
            <option value="">همه سرویس‌ها</option>
            <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $serviceFilter === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            <?= optionsHtml(ACCOUNT_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="type" class="form-select">
            <option value="">همه انواع</option>
            <?= optionsHtml(ACCOUNT_TYPES, $typeFilter) ?>
        </select>
    </div>
    <div class="col-md-2 d-flex align-items-center">
        <div class="form-check">
            <input type="checkbox" name="archived" id="archived" class="form-check-input" value="1" <?= $showArchived ? 'checked' : '' ?>>
            <label for="archived" class="form-check-label">شامل آرشیوشده‌ها</label>
        </div>
    </div>
    <div class="col-md-1">
        <button type="submit" class="btn btn-outline-secondary w-100">فیلتر</button>
    </div>
</form>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">هیچ اکانتی ثبت نشده است.</p>
            <a href="quick-add.php" class="btn btn-primary">افزودن اولین اکانت</a>
        </div>
    </div>
<?php elseif (!$accounts): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">هیچ نتیجه‌ای برای این فیلتر یافت نشد.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card am-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= accountSortLink('service', 'سرویس', $sort, $dir) ?></th>
                        <th><?= accountSortLink('email', 'ایمیل', $sort, $dir) ?></th>
                        <th><?= accountSortLink('username', 'نام کاربری', $sort, $dir) ?></th>
                        <th><?= accountSortLink('status', 'وضعیت', $sort, $dir) ?></th>
                        <th><?= accountSortLink('plan', 'پلن', $sort, $dir) ?></th>
                        <th><?= accountSortLink('twofa', '2FA', $sort, $dir) ?></th>
                        <th><?= accountSortLink('last_verified', 'آخرین تأیید', $sort, $dir) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $row): ?>
                    <tr>
                        <td><a href="../services/view.php?id=<?= (int) $row['service_id'] ?>"><?= e($row['service_name']) ?></a></td>
                        <td><a href="../emails/view.php?id=<?= (int) $row['email_id'] ?>"><?= e($row['email_address']) ?></a></td>
                        <td><?= dashOrValue($row['username']) ?></td>
                        <td>
                            <?= renderBadge($row['status'], ACCOUNT_STATUSES) ?>
                            <?php if ((int) $row['is_archived'] === 1): ?><span class="badge bg-secondary">آرشیو</span><?php endif; ?>
                        </td>
                        <td><?= dashOrValue($row['plan']) ?></td>
                        <td><?= renderBadge($row['twofa_status'], SECURITY_STATES) ?></td>
                        <td><?= dashOrValue($row['last_verified']) ?></td>
                        <td class="text-end">
                            <a href="view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-secondary">مشاهده</a>
                            <a href="edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">ویرایش</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
