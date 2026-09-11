<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$categoryFilter = trim((string) ($_GET['category'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(service_name LIKE :q OR website LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, SERVICE_STATUSES)) {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $where[] = 'category = :category';
    $params['category'] = $categoryFilter;
}

$sql = 'SELECT * FROM services';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY service_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
$categories = $pdo->query("SELECT DISTINCT category FROM services WHERE category != 'Not Set' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'سرویس‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">سرویس‌ها</h1>
    <a href="add.php" class="btn btn-primary btn-sm">+ افزودن سرویس</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="text" name="q" class="form-control" placeholder="جستجو در نام یا وب‌سایت..." value="<?= e($q) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            <?= optionsHtml(SERVICE_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="category" class="form-select">
            <option value="">همه دسته‌ها</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1">
        <button type="submit" class="btn btn-outline-secondary w-100">فیلتر</button>
    </div>
</form>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">هیچ سرویسی ثبت نشده است.</p>
            <a href="add.php" class="btn btn-primary">افزودن اولین سرویس</a>
        </div>
    </div>
<?php elseif (!$services): ?>
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
                        <th>نام سرویس</th>
                        <th>دسته‌بندی</th>
                        <th>وب‌سایت</th>
                        <th>وضعیت</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($services as $row): ?>
                    <tr>
                        <td><a href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['service_name']) ?></a></td>
                        <td><?= $row['category'] === 'Not Set' ? renderBadge('Not Set') : e($row['category']) ?></td>
                        <td><?= dashOrValue($row['website']) ?></td>
                        <td><?= renderBadge($row['status'], SERVICE_STATUSES) ?></td>
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
