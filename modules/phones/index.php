<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');

$where = [visibilityScope('phones')];
$params = [];

if ($q !== '') {
    $where[] = '(phone_number LIKE :q OR label LIKE :q OR country LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, PHONE_STATUSES)) {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}

$filteredCountSql = 'SELECT COUNT(*) FROM phones';
if ($where) {
    $filteredCountSql .= ' WHERE ' . implode(' AND ', $where);
}
$filteredCountStmt = $pdo->prepare($filteredCountSql);
$filteredCountStmt->execute($params);
$filteredCount = (int) $filteredCountStmt->fetchColumn();

$page = resolvePage($_GET['page'] ?? null);
$perPage = resolvePerPage($_GET['per_page'] ?? null);
[$page, $limit, $offset] = paginationBounds($filteredCount, $page, $perPage);

$sql = 'SELECT * FROM phones';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY is_primary DESC, phone_number';
if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$phones = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM phones WHERE ' . visibilityScope('phones'))->fetchColumn();

$pageTitle = t('phones.title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0"><?= e(t('phones.title')) ?></h1>
    <?php if (canWrite()): ?>
        <a href="add.php" class="btn btn-primary btn-sm"><?= e(t('phones.add')) ?></a>
    <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <input type="text" name="q" class="form-control" placeholder="<?= e(t('phones.search_placeholder')) ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-md-4">
        <select name="status" class="form-select">
            <option value=""><?= e(t('common.all_statuses')) ?></option>
            <?= optionsHtml(PHONE_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary w-100"><?= e(t('common.filter')) ?></button>
    </div>
</form>

<p class="text-muted small mb-2"><?= e(t('common.records_count', ['count' => $filteredCount])) ?></p>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3"><?= e(t('phones.empty')) ?></p>
            <?php if (canWrite()): ?>
                <a href="add.php" class="btn btn-primary"><?= e(t('phones.add_first')) ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!$phones): ?>
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
                        <th><?= e(t('phones.th_number')) ?></th>
                        <th><?= e(t('phones.th_country')) ?></th>
                        <th><?= e(t('phones.th_label')) ?></th>
                        <th><?= e(t('common.field_status')) ?></th>
                        <th><?= e(t('phones.th_primary')) ?></th>
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
                        <td><?= ((int) $row['is_primary']) === 1 ? '<span class="badge badge-enabled">' . e(t('phones.th_primary')) . '</span>' : '' ?></td>
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
