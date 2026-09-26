<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/plans.php';
require_once __DIR__ . '/includes/subscriptions.php';
require_once __DIR__ . '/includes/payments/ZarinPalGateway.php';

requireRole('owner');

$workspaceId = currentWorkspaceId();
$plans = platformDb()->query('SELECT code, name, monthly_price FROM plans WHERE monthly_price > 0 ORDER BY monthly_price ASC')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    $planCode = (string) ($_POST['plan_code'] ?? '');
    $method = (string) ($_POST['payment_method'] ?? '');

    $plan = null;
    foreach ($plans as $p) {
        if ($p['code'] === $planCode) {
            $plan = $p;
            break;
        }
    }

    if ($plan === null) {
        $errors[] = tOr('billing.plan_invalid', 'Choose a valid plan.');
    } elseif (!in_array($method, ['zarinpal', 'manual'], true)) {
        $errors[] = tOr('billing.method_invalid', 'Choose a valid payment method.');
    }

    if (!$errors && $method === 'manual') {
        $reference = trim((string) ($_POST['manual_reference'] ?? ''));
        createWorkspaceSubscriptionRequest($workspaceId, $planCode, 'manual', $reference !== '' ? $reference : null, 'pending');
        flashSet('success', tOr('billing.manual_submitted', 'Your payment request has been submitted for admin approval.'));
        header('Location: billing.php');
        exit;
    }

    if (!$errors && $method === 'zarinpal') {
        // Plan prices are entered in Tomans (this app targets the Iranian
        // market); ZarinPal's API takes Rials, hence the *10 — not a
        // currency-exchange conversion, just Iran's own 1 Toman = 10 Rials.
        $amountRials = (int) round((float) $plan['monthly_price'] * 10);
        $subscriptionId = createWorkspaceSubscriptionRequest($workspaceId, $planCode, 'zarinpal', null, 'pending');
        try {
            $gateway = new ZarinPalGateway();
            $result = $gateway->requestPayment(
                $amountRials,
                'Account Manager — ' . $plan['name'] . ' plan',
                appUrl('billing-callback.php?subscription_id=' . $subscriptionId)
            );
            markSubscriptionPaymentReference($subscriptionId, $result['authority']);
            header('Location: ' . $result['pay_url']);
            exit;
        } catch (Throwable $e) {
            error_log('Account Manager: ZarinPal payment request failed: ' . $e->getMessage());
            rejectWorkspaceSubscription($subscriptionId);
            $errors[] = tOr('billing.zarinpal_failed', 'Could not start the payment. Please try again later or use manual payment.');
        }
    }
}

$csrf = csrfToken();
$pageTitle = tOr('billing.title', 'Billing');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(tOr('billing.title', 'Billing')) ?></h1>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card am-card" style="max-width:520px;">
    <div class="card-body">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="mb-3">
                <label class="form-label"><?= e(tOr('billing.field_plan', 'Plan')) ?></label>
                <select name="plan_code" class="form-select" required>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= e($p['code']) ?>"><?= e($p['name']) ?> — <?= number_format((float) $p['monthly_price'], 2) ?>/mo</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= e(tOr('billing.field_payment_method', 'Payment method')) ?></label>
                <select name="payment_method" id="payment_method" class="form-select" required>
                    <option value="zarinpal">ZarinPal</option>
                    <option value="manual"><?= e(tOr('billing.method_manual', 'Manual payment (admin approval)')) ?></option>
                </select>
            </div>

            <div class="mb-3" id="manual-reference-group" style="display:none;">
                <label class="form-label"><?= e(tOr('billing.field_manual_reference', 'Payment reference / note')) ?></label>
                <input type="text" name="manual_reference" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary"><?= e(tOr('billing.submit_button', 'Continue')) ?></button>
        </form>
    </div>
</div>

<script>
(function () {
    var select = document.getElementById('payment_method');
    var group = document.getElementById('manual-reference-group');
    if (!select || !group) { return; }
    function update() { group.style.display = select.value === 'manual' ? '' : 'none'; }
    select.addEventListener('change', update);
    update();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
