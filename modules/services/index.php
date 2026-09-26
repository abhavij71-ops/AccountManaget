<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$categoryFilter = trim((string) ($_GET['category'] ?? ''));

$where = [visibilityScope('services')];
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

$filteredCountSql = 'SELECT COUNT(*) FROM services';
if ($where) {
    $filteredCountSql .= ' WHERE ' . implode(' AND ', $where);
}
$filteredCountStmt = $pdo->prepare($filteredCountSql);
$filteredCountStmt->execute($params);
$filteredCount = (int) $filteredCountStmt->fetchColumn();

$page = resolvePage($_GET['page'] ?? null);
$perPage = resolvePerPage($_GET['per_page'] ?? null);
[$page, $limit, $offset] = paginationBounds($filteredCount, $page, $perPage);

$sql = 'SELECT * FROM services';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY service_name';
if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE ' . visibilityScope('services'))->fetchColumn();
$categories = $pdo->query("SELECT DISTINCT category FROM services WHERE category != 'Not Set' AND " . visibilityScope('services') . ' ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = t('services.title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0"><?= e(t('services.title')) ?></h1>
    <?php if (canWrite()): ?>
        <a href="add.php" class="btn btn-primary btn-sm"><?= e(t('services.add')) ?></a>
    <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="text" name="q" class="form-control" placeholder="<?= e(t('services.search_placeholder')) ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value=""><?= e(t('common.all_statuses')) ?></option>
            <?= optionsHtml(SERVICE_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="category" class="form-select">
            <option value=""><?= e(t('services.all_categories')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1">
        <button type="submit" class="btn btn-outline-secondary w-100"><?= e(t('common.filter')) ?></button>
    </div>
</form>

<p class="text-muted small mb-2"><?= e(t('common.records_count', ['count' => $filteredCount])) ?></p>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3"><?= e(t('services.empty')) ?></p>
            <?php if (canWrite()): ?>
                <a href="add.php" class="btn btn-primary"><?= e(t('services.add_first')) ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!$services): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0"><?= e(t('common.no_results')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="card am-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= e(t('services.th_name')) ?></th>
                        <th><?= e(t('services.th_category')) ?></th>
                        <th><?= e(t('services.th_website')) ?></th>
                        <th><?= e(t('common.field_status')) ?></th>
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
                            <a href="view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= e(t('common.view')) ?></a>
                            <a href="edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary"><?= e(t('common.edit')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= renderPagination($filteredCount, $page, $perPage) ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
