<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(phone_number LIKE :q OR label LIKE :q OR country LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, PHONE_STATUSES)) {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}

$sql = 'SELECT * FROM phones';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY is_primary DESC, phone_number';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$phones = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM phones')->fetchColumn();

$pageTitle = 'شماره تلفن‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">شماره تلفن‌ها</h1>
    <a href="add.php" class="btn btn-primary btn-sm">+ افزودن شماره تلفن</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <input type="text" name="q" class="form-control" placeholder="جستجو در شماره، برچسب یا کشور..." value="<?= e($q) ?>">
    </div>
    <div class="col-md-4">
        <select name="status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            <?= optionsHtml(PHONE_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary w-100">فیلتر</button>
    </div>
</form>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">هیچ شماره تلفنی ثبت نشده است.</p>
            <a href="add.php" class="btn btn-primary">افزودن اولین شماره تلفن</a>
        </div>
    </div>
<?php elseif (!$phones): ?>
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
                        <th>شماره تلفن</th>
                        <th>کشور</th>
                        <th>برچسب</th>
                        <th>وضعیت</th>
                        <th>اصلی</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($phones as $row): ?>
                    <tr>
                        <td><a href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['phone_number']) ?></a></td>
                        <td><?= dashOrValue($row['country']) ?></td>
                        <td><?= dashOrValue($row['label']) ?></td>
                        <td><?= renderBadge($row['status'], PHONE_STATUSES) ?></td>
                        <td><?= ((int) $row['is_primary']) === 1 ? '<span class="badge badge-enabled">اصلی</span>' : '' ?></td>
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
