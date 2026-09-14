<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM phones WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$phone = $stmt->fetch();

if (!$phone) {
    flashSet('danger', t('phones.not_found'));
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM phone_security WHERE phone_id = ?');
$stmt->execute([$id]);
$phoneSecurity = $stmt->fetch() ?: [];

$errors = [];
$form = [
    'phone_number' => $phone['phone_number'],
    'country' => (string) ($phone['country'] ?? ''),
    'label' => (string) ($phone['label'] ?? ''),
    'status' => $phone['status'],
    'is_primary' => ((int) $phone['is_primary']) === 1 ? '1' : '',
    'notes' => (string) ($phone['notes'] ?? ''),
    'sim_pin_status' => $phoneSecurity['sim_pin_status'] ?? 'Not Set',
    'port_out_lock' => $phoneSecurity['port_out_lock'] ?? 'Not Set',
    'carrier' => (string) ($phoneSecurity['carrier'] ?? ''),
    'esim' => isset($phoneSecurity['esim']) && $phoneSecurity['esim'] !== null ? (string) (int) $phoneSecurity['esim'] : '',
    'last_security_check' => (string) ($phoneSecurity['last_security_check'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'is_primary') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['is_primary'] = isset($_POST['is_primary']) ? '1' : '';

    if ($form['phone_number'] === '') {
        $errors[] = t('phones.number_required');
    }
    if (!array_key_exists($form['status'], PHONE_STATUSES)) {
        $errors[] = t('phones.status_invalid');
    }
    foreach (['sim_pin_status', 'port_out_lock'] as $f) {
        if (!array_key_exists($form[$f], SECURITY_STATES)) {
            $errors[] = t('msg.invalid_security_status');
            break;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($form['status'] !== $phone['status']) {
                log_history($pdo, 'phone', $id, 'Status Changed', 'status', $phone['status'], $form['status']);
            }
            $baseDiffFields = ['phone_number', 'country', 'label', 'notes'];
            foreach ($baseDiffFields as $f) {
                $old = (string) ($phone[$f] ?? '');
                if ($old !== $form[$f]) {
                    log_history($pdo, 'phone', $id, 'Phone Updated', $f, $old !== '' ? $old : null, $form[$f] !== '' ? $form[$f] : null);
                }
            }
            $oldPrimary = ((int) $phone['is_primary']) === 1 ? '1' : '0';
            $newPrimary = $form['is_primary'] === '1' ? '1' : '0';
            if ($oldPrimary !== $newPrimary) {
                log_history($pdo, 'phone', $id, 'Phone Updated', 'is_primary', $oldPrimary, $newPrimary);
            }

            $stmt = $pdo->prepare('UPDATE phones SET
                phone_number = :phone_number, country = :country, label = :label,
                status = :status, is_primary = :is_primary, notes = :notes
                WHERE id = :id');
            $stmt->execute([
                'phone_number' => $form['phone_number'],
                'country' => $form['country'] !== '' ? $form['country'] : null,
                'label' => $form['label'] !== '' ? $form['label'] : null,
                'status' => $form['status'],
                'is_primary' => $form['is_primary'] === '1' ? 1 : 0,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'id' => $id,
            ]);

            $securityParams = [
                'phone_id' => $id,
                'sim_pin_status' => $form['sim_pin_status'],
                'port_out_lock' => $form['port_out_lock'],
                'carrier' => $form['carrier'] !== '' ? $form['carrier'] : null,
                'esim' => $form['esim'] !== '' ? (int) $form['esim'] : null,
                'last_security_check' => $form['last_security_check'] !== '' ? $form['last_security_check'] : null,
            ];
            if ($phoneSecurity) {
                $stmt = $pdo->prepare('UPDATE phone_security SET
                    sim_pin_status = :sim_pin_status, port_out_lock = :port_out_lock, carrier = :carrier,
                    esim = :esim, last_security_check = :last_security_check
                    WHERE phone_id = :phone_id');
            } else {
                $stmt = $pdo->prepare('INSERT INTO phone_security (phone_id, sim_pin_status, port_out_lock, carrier, esim, last_security_check)
                    VALUES (:phone_id, :sim_pin_status, :port_out_lock, :carrier, :esim, :last_security_check)');
            }
            $stmt->execute($securityParams);

            $pdo->commit();
            flashSet('success', t('msg.saved_changes'));
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = t('phones.duplicate_number_other');
            } else {
                $errors[] = t('msg.save_error') . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('phones.edit_title_prefix') . $phone['phone_number'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('phones.edit_title_prefix')) ?><?= e($phone['phone_number']) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_profile')) ?></a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="card am-card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('phones.field_number_required')) ?></label>
                <input type="text" name="phone_number" class="form-control" required value="<?= e($form['phone_number']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('phones.field_country')) ?></label>
                <input type="text" name="country" class="form-control" value="<?= e($form['country']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('phones.field_label')) ?></label>
                <input type="text" name="label" class="form-control" value="<?= e($form['label']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(PHONE_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="is_primary" id="is_primary" class="form-check-input" value="1" <?= $form['is_primary'] === '1' ? 'checked' : '' ?>>
                    <label for="is_primary" class="form-check-label"><?= e(t('phones.field_is_primary')) ?></label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('common.field_notes')) ?></label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('phones.security_heading')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('phones.field_sim_pin_status')) ?></label>
                <select name="sim_pin_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['sim_pin_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('phones.field_port_out_lock')) ?></label>
                <select name="port_out_lock" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['port_out_lock']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('phones.field_carrier')) ?></label>
                <input type="text" name="carrier" class="form-control" value="<?= e($form['carrier']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('phones.field_esim')) ?></label>
                <select name="esim" class="form-select">
                    <option value="" <?= $form['esim'] === '' ? 'selected' : '' ?>><?= e(t('enum.Unknown')) ?></option>
                    <option value="1" <?= $form['esim'] === '1' ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                    <option value="0" <?= $form['esim'] === '0' ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.last_security_check')) ?></label>
                <input type="date" name="last_security_check" class="form-control" value="<?= e($form['last_security_check']) ?>">
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save_changes')) ?></button>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
