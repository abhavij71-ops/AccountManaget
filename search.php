<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));

$emailResults = [];
$serviceResults = [];
$accountResults = [];
$phoneResults = [];
$totalCount = 0;

if ($q !== '') {
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare("SELECT id, email_address, display_name, type, status FROM emails
        WHERE email_address LIKE :q OR display_name LIKE :q OR provider LIKE :q OR purpose LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'email' AND tg.entity_id = emails.id AND t.name LIKE :q)
        ORDER BY email_address LIMIT 30");
    $stmt->execute(['q' => $like]);
    $emailResults = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, service_name, category, status FROM services
        WHERE service_name LIKE :q OR category LIKE :q OR website LIKE :q OR purpose LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'service' AND tg.entity_id = services.id AND t.name LIKE :q)
        ORDER BY service_name LIMIT 30");
    $stmt->execute(['q' => $like]);
    $serviceResults = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, phone_number, label, status FROM phones
        WHERE phone_number LIKE :q OR label LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'phone' AND tg.entity_id = phones.id AND t.name LIKE :q)
        ORDER BY phone_number LIMIT 30");
    $stmt->execute(['q' => $like]);
    $phoneResults = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT a.id, a.username, a.display_name, a.external_account_id, a.status,
            s.id AS service_id, s.service_name, e.id AS email_id, e.email_address
        FROM accounts a
        JOIN services s ON s.id = a.service_id
        JOIN emails e ON e.id = a.email_id
        WHERE a.username LIKE :q OR a.display_name LIKE :q OR a.external_account_id LIKE :q OR a.notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'account' AND tg.entity_id = a.id AND t.name LIKE :q)
           OR EXISTS (SELECT 1 FROM custom_fields cf
                      WHERE cf.account_id = a.id AND (cf.field_key LIKE :q OR cf.field_value LIKE :q))
           OR EXISTS (SELECT 1 FROM payments p WHERE p.account_id = a.id AND p.payment_reference LIKE :q)
        ORDER BY a.username LIMIT 30");
    $stmt->execute(['q' => $like]);
    $accountResults = $stmt->fetchAll();

    $totalCount = count($emailResults) + count($serviceResults) + count($accountResults) + count($phoneResults);
}

$pageTitle = 'جستجو';
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4">جستجوی سراسری</h1>

<form method="get" class="mb-4">
    <div class="input-group">
        <input type="text" name="q" class="form-control form-control-lg" value="<?= e($q) ?>"
               placeholder="جستجو در ایمیل، نام کاربری، سرویس، تلفن، برچسب‌ها، یادداشت‌ها، فیلدهای سفارشی، مرجع پرداخت..." autofocus>
        <button type="submit" class="btn btn-primary">جستجو</button>
    </div>
</form>

<?php if ($q === ''): ?>
    <p class="text-muted">عبارت مورد نظر را وارد کنید.</p>
<?php elseif ($totalCount === 0): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">هیچ نتیجه‌ای برای «<?= e($q) ?>» یافت نشد.</p>
        </div>
    </div>
<?php else: ?>
    <p class="text-muted"><?= (int) $totalCount ?> نتیجه برای «<?= e($q) ?>»</p>

    <?php if ($emailResults): ?>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">ایمیل‌ها (<?= count($emailResults) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($emailResults as $row): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="modules/emails/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['email_address']) ?></a>
                        <div class="d-flex gap-2">
                            <?= renderBadge($row['type'], EMAIL_TYPES) ?>
                            <?= renderBadge($row['status'], EMAIL_STATUSES) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($serviceResults): ?>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">سرویس‌ها (<?= count($serviceResults) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($serviceResults as $row): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="modules/services/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['service_name']) ?></a>
                        <div class="d-flex gap-2">
                            <span class="badge bg-light text-dark border"><?= e($row['category']) ?></span>
                            <?= renderBadge($row['status'], SERVICE_STATUSES) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($accountResults): ?>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">اکانت‌ها (<?= count($accountResults) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($accountResults as $row): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="modules/accounts/view.php?id=<?= (int) $row['id'] ?>">
                            <?= e($row['email_address']) ?>
                            <span class="text-muted">&larr;</span>
                            <?= e($row['service_name']) ?>
                            <span class="text-muted">&larr;</span>
                            <?= e($row['username'] ?: ($row['display_name'] ?: ('#' . $row['id']))) ?>
                        </a>
                        <?= renderBadge($row['status'], ACCOUNT_STATUSES) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($phoneResults): ?>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">شماره تلفن‌ها (<?= count($phoneResults) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($phoneResults as $row): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="modules/phones/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['phone_number']) ?><?= $row['label'] ? ' — ' . e($row['label']) : '' ?></a>
                        <?= renderBadge($row['status'], PHONE_STATUSES) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
