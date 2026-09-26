<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../accounts/_lib.php';
require_once __DIR__ . '/_lib.php';

requireLogin();
requireWriteAccess();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) {
    flashSet('danger', t('services.not_found'));
    header('Location: index.php');
    exit;
}

$defaults = fetchServiceDefaults($pdo, $id) ?? [];

if (!serviceHasDefaultsTemplate($defaults)) {
    flashSet('danger', t('services.apply_defaults_no_template'));
    header('Location: view.php?id=' . $id);
    exit;
}

// The label each changed field is shown under, and (for history logging) the
// translation key used to describe it — reused for both the preview table
// and the post-apply history entry, so the two can never say different things
// about what "twofa_status" means to a human reading this page.
const APPLY_DEFAULTS_FIELD_LABELS = [
    'twofa_status' => 'services.field_default_twofa_status',
    'twofa_method' => 'services.field_default_twofa_method',
    'passkey_status' => 'services.field_default_passkey_status',
    'security_questions_status' => 'services.field_default_security_questions_status',
    'recovery_status' => 'services.field_default_recovery_status',
    'recovery_email_id' => 'field.recovery_email',
    'recovery_phone_id' => 'emails.recovery_phone_label',
    'sub_type' => 'services.field_default_subscription_type',
    'sub_status' => 'services.field_default_subscription_status',
    'sub_billing_cycle' => 'services.field_default_billing_cycle',
    'sub_currency' => 'services.field_default_currency',
];

/**
 * Computes, without writing anything, exactly what applying this service's
 * template would do to each of its non-archived accounts. Single source of
 * truth for both the preview (GET) and the apply (POST) below, so they can
 * never disagree about what "would change" means — POST re-runs this fresh
 * against current data rather than trusting anything submitted by the form.
 *
 * CORE RULE, enforced here and only here: a field is eligible only when its
 * current value is NULL (no row / no value at all), 'Not Set', or 'Unknown'.
 * Every other value — including 'Not Applicable', itself a deliberate,
 * confirmed state in this product's five-state model — is left untouched.
 * default_identity_type is deliberately absent from this function entirely:
 * an existing account's identity_type is NEVER NULL/Not Set/Unknown, it
 * always holds one of its four real values, so there is nothing this rule
 * could ever legitimately fill there — that field only ever pre-fills the
 * form for a brand-new account.
 *
 * @return array{
 *     total_accounts:int,
 *     field_tally: array<string, array{fill:int, skip:int}>,
 *     plans: list<array{account_id:int, label:string, changes:array<string,array{old:?string,new:string}>, security:array, recovery:array, subscription:array}>
 * }
 */
function planServiceDefaultsApply(PDO $pdo, int $serviceId, array $defaults): array
{
    $isEligible = static fn ($current) => $current === null || in_array($current, ['Not Set', 'Unknown'], true);
    $isEmpty = static fn ($current) => $current === null || trim((string) $current) === '';

    $stmt = $pdo->prepare("SELECT a.id, a.identity_type, a.email_id, a.identity_phone_id, a.username,
            a.visibility, a.owner_user_id,
            e.email_address, p.phone_number
        FROM accounts a
        LEFT JOIN emails e ON e.id = a.email_id
        LEFT JOIN phones p ON p.id = a.identity_phone_id
        WHERE a.service_id = ? AND a.is_archived = 0 AND " . visibilityScope('accounts', 'a') . "
        ORDER BY COALESCE(e.email_address, p.phone_number, a.username, '')");
    $stmt->execute([$serviceId]);
    $visibleAccounts = $stmt->fetchAll();

    // Visible (per visibilityScope() above) is not the same as editable:
    // canEditRecord() also excludes a workspace-visible account a member
    // doesn't own (docs/PERMISSIONS.md). VERIFIED: a member could apply
    // defaults into accounts owned by the owner because this function never
    // checked either. Only the count is ever surfaced below — never which
    // accounts — so this can't be used to fish for another user's private
    // account usernames the way "SECRET-owner-acct" leaked in the preview.
    $accounts = [];
    $skippedForPermission = 0;
    foreach ($visibleAccounts as $account) {
        if (canEditRecord($account['visibility'] ?? null, isset($account['owner_user_id']) ? (int) $account['owner_user_id'] : null)) {
            $accounts[] = $account;
        } else {
            $skippedForPermission++;
        }
    }

    $fieldTally = [];
    $tally = static function (string $field, bool $filled) use (&$fieldTally): void {
        $fieldTally[$field] ??= ['fill' => 0, 'skip' => 0];
        $fieldTally[$field][$filled ? 'fill' : 'skip']++;
    };

    $plans = [];
    foreach ($accounts as $account) {
        $accountId = (int) $account['id'];
        $changes = [];

        $security = fetchAccountSecurity($pdo, $accountId) ?? [
            'twofa_status' => 'Not Set', 'twofa_method' => null, 'passkey_status' => 'Not Set',
            'security_key_status' => 'Not Set', 'security_questions_status' => 'Not Set',
            'last_security_check' => null, 'credential_storage' => null, 'credential_reference' => null,
        ];
        $recovery = fetchAccountRecovery($pdo, $accountId) ?? [
            'status' => 'Not Set', 'recovery_email_id' => null, 'recovery_phone_id' => null,
            'recovery_contact' => null, 'recovery_codes_status' => 'Not Set',
            'recovery_codes_reference' => null, 'backup_method' => null,
            'last_recovery_verification' => null, 'recovery_notes' => null,
        ];
        $subscription = fetchSubscription($pdo, $accountId) ?? [
            'type' => 'Unknown', 'plan' => null, 'status' => 'Unknown', 'price' => null,
            'currency' => null, 'billing_cycle' => 'Not Applicable', 'start_date' => null,
            'renewal_date' => null, 'auto_renewal' => null,
        ];

        if (!empty($defaults['default_twofa_status'])) {
            $filled = $isEligible($security['twofa_status']);
            $tally('twofa_status', $filled);
            if ($filled) {
                $changes['twofa_status'] = ['old' => $security['twofa_status'], 'new' => $defaults['default_twofa_status']];
                $security['twofa_status'] = $defaults['default_twofa_status'];
            }
        }
        if (!empty($defaults['default_twofa_method'])) {
            $filled = $isEmpty($security['twofa_method']);
            $tally('twofa_method', $filled);
            if ($filled) {
                $changes['twofa_method'] = ['old' => $security['twofa_method'], 'new' => $defaults['default_twofa_method']];
                $security['twofa_method'] = $defaults['default_twofa_method'];
            }
        }
        if (!empty($defaults['default_passkey_status'])) {
            $filled = $isEligible($security['passkey_status']);
            $tally('passkey_status', $filled);
            if ($filled) {
                $changes['passkey_status'] = ['old' => $security['passkey_status'], 'new' => $defaults['default_passkey_status']];
                $security['passkey_status'] = $defaults['default_passkey_status'];
            }
        }
        if (!empty($defaults['default_security_questions_status'])) {
            $filled = $isEligible($security['security_questions_status']);
            $tally('security_questions_status', $filled);
            if ($filled) {
                $changes['security_questions_status'] = ['old' => $security['security_questions_status'], 'new' => $defaults['default_security_questions_status']];
                $security['security_questions_status'] = $defaults['default_security_questions_status'];
            }
        }

        if (!empty($defaults['default_recovery_status'])) {
            $filled = $isEligible($recovery['status']);
            $tally('recovery_status', $filled);
            if ($filled) {
                $changes['recovery_status'] = ['old' => $recovery['status'], 'new' => $defaults['default_recovery_status']];
                $recovery['status'] = $defaults['default_recovery_status'];
            }
        }

        // Derivation, separate from the template values above: only into whichever
        // recovery field matches THIS account's own identity anchor — never both —
        // and only when that recovery field is currently empty.
        if (!empty($defaults['recovery_follows_identity'])) {
            if ($account['identity_type'] === 'email' && $account['email_id']) {
                $filled = $isEmpty($recovery['recovery_email_id']);
                $tally('recovery_email_id', $filled);
                if ($filled) {
                    $changes['recovery_email_id'] = ['old' => null, 'new' => $account['email_address']];
                    $recovery['recovery_email_id'] = (int) $account['email_id'];
                }
            } elseif ($account['identity_type'] === 'phone' && $account['identity_phone_id']) {
                $filled = $isEmpty($recovery['recovery_phone_id']);
                $tally('recovery_phone_id', $filled);
                if ($filled) {
                    $changes['recovery_phone_id'] = ['old' => null, 'new' => $account['phone_number']];
                    $recovery['recovery_phone_id'] = (int) $account['identity_phone_id'];
                }
            }
        }

        if (!empty($defaults['default_subscription_type'])) {
            $filled = $isEligible($subscription['type']);
            $tally('sub_type', $filled);
            if ($filled) {
                $changes['sub_type'] = ['old' => $subscription['type'], 'new' => $defaults['default_subscription_type']];
                $subscription['type'] = $defaults['default_subscription_type'];
            }
        }
        if (!empty($defaults['default_subscription_status'])) {
            $filled = $isEligible($subscription['status']);
            $tally('sub_status', $filled);
            if ($filled) {
                $changes['sub_status'] = ['old' => $subscription['status'], 'new' => $defaults['default_subscription_status']];
                $subscription['status'] = $defaults['default_subscription_status'];
            }
        }
        if (!empty($defaults['default_billing_cycle'])) {
            $filled = $isEligible($subscription['billing_cycle']);
            $tally('sub_billing_cycle', $filled);
            if ($filled) {
                $changes['sub_billing_cycle'] = ['old' => $subscription['billing_cycle'], 'new' => $defaults['default_billing_cycle']];
                $subscription['billing_cycle'] = $defaults['default_billing_cycle'];
            }
        }
        if (!empty($defaults['default_currency'])) {
            $filled = $isEmpty($subscription['currency']);
            $tally('sub_currency', $filled);
            if ($filled) {
                $changes['sub_currency'] = ['old' => $subscription['currency'], 'new' => $defaults['default_currency']];
                $subscription['currency'] = $defaults['default_currency'];
            }
        }

        if ($changes) {
            $plans[] = [
                'account_id' => $accountId,
                'label' => accountDisplayIdentity($account),
                'changes' => $changes,
                'security' => $security,
                'recovery' => $recovery,
                'subscription' => $subscription,
            ];
        }
    }

    return [
        'total_accounts' => count($visibleAccounts),
        'field_tally' => $fieldTally,
        'plans' => $plans,
        'skipped_for_permission' => $skippedForPermission,
    ];
}

function renderChangeValue(string $field, ?string $value): string
{
    $enumMap = match ($field) {
        'twofa_status', 'passkey_status', 'security_questions_status' => SECURITY_STATES,
        'recovery_status' => RECOVERY_STATUSES,
        'sub_type' => SUBSCRIPTION_TYPES,
        'sub_status' => SUBSCRIPTION_STATUSES,
        'sub_billing_cycle' => BILLING_CYCLES,
        default => null,
    };
    if ($enumMap !== null && $value !== null) {
        return renderBadge($value, $enumMap);
    }
    return dashOrValue($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: apply-defaults.php?id=' . $id);
        exit;
    }

    // Recomputed fresh against current data — never trust that what the user
    // confirmed a moment ago on the preview page still matches reality.
    $result = planServiceDefaultsApply($pdo, $id, $defaults);

    if (!$result['plans']) {
        $nothingMessage = t('services.apply_defaults_nothing_message');
        if ($result['skipped_for_permission'] > 0) {
            $nothingMessage .= ' ' . $result['skipped_for_permission'] . ' ' . tOr('services.apply_defaults_skipped_permission', "account(s) skipped — you don't have permission to edit them.");
        }
        flashSet('success', $nothingMessage);
        header('Location: view.php?id=' . $id);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $accountsAffected = 0;
        $fieldsFilled = 0;
        foreach ($result['plans'] as $plan) {
            upsertAccountSecurity($pdo, $plan['account_id'], $plan['security']);
            upsertAccountRecovery($pdo, $plan['account_id'], $plan['recovery']);
            upsertSubscription($pdo, $plan['account_id'], $plan['subscription']);

            $fieldLabels = array_map(
                static fn ($f) => t(APPLY_DEFAULTS_FIELD_LABELS[$f]),
                array_keys($plan['changes'])
            );
            log_history($pdo, 'account', $plan['account_id'], 'Service Defaults Applied', null, null, implode(t('common.list_separator'), $fieldLabels));

            $accountsAffected++;
            $fieldsFilled += count($plan['changes']);
        }

        $pdo->commit();
        $successMessage = t('services.apply_defaults_success', ['accounts' => $accountsAffected, 'fields' => $fieldsFilled]);
        if ($result['skipped_for_permission'] > 0) {
            $successMessage .= ' ' . $result['skipped_for_permission'] . ' ' . tOr('services.apply_defaults_skipped_permission', "account(s) skipped — you don't have permission to edit them.");
        }
        flashSet('success', $successMessage);
        header('Location: view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flashSet('danger', t('services.apply_defaults_error') . $e->getMessage());
        header('Location: apply-defaults.php?id=' . $id);
        exit;
    }
}

$result = planServiceDefaultsApply($pdo, $id, $defaults);
$csrf = csrfToken();
$pageTitle = t('services.apply_defaults_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('services.apply_defaults_title')) ?> — <?= e($service['service_name']) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_profile')) ?></a>
</div>

<p class="text-muted"><?= e(t('services.apply_defaults_intro')) ?></p>
<p><?= e(t('services.apply_defaults_account_count', ['count' => $result['total_accounts']])) ?></p>

<?php if ($result['skipped_for_permission'] > 0): ?>
    <p class="text-muted small"><?= (int) $result['skipped_for_permission'] ?> <?= e(tOr('services.apply_defaults_skipped_permission', "account(s) skipped — you don't have permission to edit them.")) ?></p>
<?php endif; ?>

<?php if (!$result['plans']): ?>
    <div class="alert alert-info">
        <strong><?= e(t('services.apply_defaults_nothing_title')) ?></strong>
        <p class="mb-0"><?= e(t('services.apply_defaults_nothing_message')) ?></p>
    </div>
<?php else: ?>
    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('services.apply_defaults_th_field')) ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= e(t('services.apply_defaults_th_field')) ?></th>
                            <th><?= e(t('services.apply_defaults_th_would_fill')) ?></th>
                            <th><?= e(t('services.apply_defaults_th_would_skip')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['field_tally'] as $field => $counts): ?>
                        <tr>
                            <td><?= e(t(APPLY_DEFAULTS_FIELD_LABELS[$field])) ?></td>
                            <td><span class="badge badge-enabled"><?= (int) $counts['fill'] ?></span></td>
                            <td><span class="badge badge-not-set"><?= (int) $counts['skip'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('services.apply_defaults_affected_title')) ?> (<?= count($result['plans']) ?>)</div>
        <div class="card-body">
            <ul class="list-unstyled mb-0">
                <?php foreach ($result['plans'] as $plan): ?>
                    <li class="mb-3 pb-3 border-bottom">
                        <a href="../accounts/view.php?id=<?= (int) $plan['account_id'] ?>"><strong><?= e($plan['label']) ?></strong></a>
                        <ul class="list-unstyled small text-muted mt-1 mb-0">
                            <?php foreach ($plan['changes'] as $field => $change): ?>
                                <li>
                                    <?= e(t(APPLY_DEFAULTS_FIELD_LABELS[$field])) ?>:
                                    <?= renderChangeValue($field, $change['old']) ?> <?= e(t('common.history_to')) ?> <?= renderChangeValue($field, $change['new']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <form method="post" data-confirm="<?= e(t('services.apply_defaults_confirm_dialog')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-primary"><?= e(t('services.apply_defaults_confirm_button', ['count' => count($result['plans'])])) ?></button>
        <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
