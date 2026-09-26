<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/subscriptions.php';
require_once __DIR__ . '/includes/payments/ZarinPalGateway.php';

requireRole('owner');

$subscriptionId = (int) ($_GET['subscription_id'] ?? 0);
$authority = (string) ($_GET['Authority'] ?? '');
$status = (string) ($_GET['Status'] ?? '');

// Scoped to the current workspace AND still pending — never act on a
// subscription id that isn't actually this workspace's own open request.
$stmt = platformDb()->prepare(
    "SELECT ws.*, p.monthly_price FROM workspace_subscriptions ws JOIN plans p ON p.code = ws.plan_code
     WHERE ws.id = ? AND ws.workspace_id = ? AND ws.status = 'pending' AND ws.payment_method = 'zarinpal'"
);
$stmt->execute([$subscriptionId, currentWorkspaceId()]);
$subscription = $stmt->fetch();

$success = false;
$message = '';

if ($subscription === false || $subscription['payment_reference'] !== $authority) {
    $message = tOr('billing.callback_not_found', 'This payment session could not be found.');
} elseif ($status !== 'OK') {
    rejectWorkspaceSubscription($subscriptionId);
    $message = tOr('billing.callback_cancelled', 'The payment was cancelled.');
} else {
    try {
        $amountRials = (int) round((float) $subscription['monthly_price'] * 10);
        $gateway = new ZarinPalGateway();
        $result = $gateway->verifyPayment($amountRials, $authority);
        markSubscriptionPaymentReference($subscriptionId, $result['ref_id']);
        activateWorkspaceSubscription($subscriptionId);
        $success = true;
    } catch (Throwable $e) {
        error_log('Account Manager: ZarinPal verification failed: ' . $e->getMessage());
        rejectWorkspaceSubscription($subscriptionId);
        $message = tOr('billing.callback_failed', 'Payment verification failed.');
    }
}

$pageTitle = tOr('billing.title', 'Billing');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(tOr('billing.title', 'Billing')) ?></h1>
<?php if ($success): ?>
    <div class="alert alert-success"><?= e(tOr('billing.callback_success', 'Payment confirmed — your plan has been upgraded.')) ?></div>
<?php else: ?>
    <div class="alert alert-danger"><?= e($message) ?></div>
<?php endif; ?>
<a href="plans.php" class="btn btn-outline-secondary"><?= e(tOr('plans.title', 'Plans')) ?></a>
<?php require __DIR__ . '/includes/footer.php'; ?>
