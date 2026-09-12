<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter = (string) ($_GET['type'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(email_address LIKE :q OR display_name LIKE :q OR provider LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, EMAIL_STATUSES)) {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}
if ($typeFilter !== '' && array_key_exists($typeFilter, EMAIL_TYPES)) {
    $where[] = 'type = :type';
    $params['type'] = $typeFilter;
}

$sql = 'SELECT * FROM emails';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY is_favorite DESC, email_address';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$emails = $stmt->fetchAll();

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM emails')->fetchColumn();

$csrf = csrfToken();
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$favoriteRedirect = 'index.php' . ($queryString !== '' ? '?' . $queryString : '');

$pageTitle = 'ایمیل‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">ایمیل‌ها</h1>
    <a href="add.php" class="btn btn-primary btn-sm">+ افزودن ایمیل</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="text" name="q" class="form-control" placeholder="جستجو در آدرس، نام یا ارائه‌دهنده..." value="<?= e($q) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            <?= optionsHtml(EMAIL_STATUSES, $statusFilter) ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="type" class="form-select">
            <option value="">همه انواع</option>
            <?= optionsHtml(EMAIL_TYPES, $typeFilter) ?>
        </select>
    </div>
    <div class="col-md-1">
        <button type="submit" class="btn btn-outline-secondary w-100">فیلتر</button>
    </div>
</form>

<?php if (!$totalCount): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">هیچ ایمیلی ثبت نشده است.</p>
            <a href="add.php" class="btn btn-primary">افزودن اولین ایمیل</a>
        </div>
    </div>
<?php elseif (!$emails): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">هیچ نتیجه‌ای برای این فیلتر یافت نشد.</p>
        </div>
    </div>
<?php else: ?>
    <form id="bulk-select-form" method="post" action="bulk-edit.php" class="d-flex justify-content-end mb-2">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button type="submit" id="bulk-edit-submit" class="btn btn-outline-primary btn-sm">ویرایش سریع انتخاب‌شده‌ها</button>
    </form>

    <div class="card am-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:2rem;"><input type="checkbox" class="form-check-input" id="am-select-all" aria-label="انتخاب همه"></th>
                        <th style="width:2.5rem;"></th>
                        <th>آدرس ایمیل</th>
                        <th>نام نمایشی</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>آخرین تأیید</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($emails as $row): ?>
                    <?php $isFavorite = (bool) $row['is_favorite']; ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input am-select-checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" form="bulk-select-form" aria-label="انتخاب <?= e($row['email_address']) ?>">
                        </td>
                        <td class="text-center">
                            <form method="post" action="toggle-favorite.php" class="am-favorite-form d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="redirect" value="<?= e($favoriteRedirect) ?>">
                                <button type="submit" class="am-favorite-btn<?= $isFavorite ? ' is-favorite' : '' ?>" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" title="<?= $isFavorite ? 'حذف از موارد ویژه' : 'افزودن به موارد ویژه' ?>"><?= $isFavorite ? '★' : '☆' ?></button>
                            </form>
                        </td>
                        <td><a href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['email_address']) ?></a></td>
                        <td><?= dashOrValue($row['display_name']) ?></td>
                        <td><?= renderBadge($row['type'], EMAIL_TYPES) ?></td>
                        <td><?= renderBadge($row['status'], EMAIL_STATUSES) ?></td>
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

<script>
document.querySelectorAll('form.am-favorite-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('button');
        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) { throw new Error('bad response'); }
            return res.json();
        }).then(function (data) {
            if (!data.ok) { throw new Error('toggle failed'); }
            btn.classList.toggle('is-favorite', data.is_favorite);
            btn.textContent = data.is_favorite ? '★' : '☆';
            btn.setAttribute('aria-pressed', data.is_favorite ? 'true' : 'false');
            btn.title = data.is_favorite ? 'حذف از موارد ویژه' : 'افزودن به موارد ویژه';
        }).catch(function () {
            form.submit();
        });
    });
});

(function () {
    var selectAll = document.getElementById('am-select-all');
    var checkboxes = document.querySelectorAll('.am-select-checkbox');
    var bulkSubmit = document.getElementById('bulk-edit-submit');
    if (!checkboxes.length || !bulkSubmit) { return; }

    function updateBulkButton() {
        var anyChecked = Array.prototype.some.call(checkboxes, function (cb) { return cb.checked; });
        bulkSubmit.disabled = !anyChecked;
        if (selectAll) {
            selectAll.checked = anyChecked && Array.prototype.every.call(checkboxes, function (cb) { return cb.checked; });
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBulkButton();
        });
    }
    checkboxes.forEach(function (cb) { cb.addEventListener('change', updateBulkButton); });
    updateBulkButton();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
