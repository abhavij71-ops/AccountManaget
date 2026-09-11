<?php
declare(strict_types=1);

const EMAIL_TYPES = [
    'Personal' => 'شخصی',
    'Work' => 'کاری',
    'Business' => 'تجاری',
    'Project' => 'پروژه‌ای',
    'Secondary' => 'دوم',
    'Temporary' => 'موقت',
    'Other' => 'سایر',
    'Not Set' => 'تنظیم‌نشده',
];

const EMAIL_STATUSES = [
    'Active' => 'فعال',
    'Suspended' => 'معلق',
    'Disabled' => 'غیرفعال',
    'Abandoned' => 'رهاشده',
    'Unknown' => 'نامشخص',
];

const SECURITY_STATES = [
    'Enabled' => 'فعال',
    'Disabled' => 'غیرفعال',
    'Unknown' => 'نامشخص',
    'Not Set' => 'تنظیم‌نشده',
    'Not Applicable' => 'غیرقابل‌اعمال',
];

const SERVICE_STATUSES = EMAIL_STATUSES;

const PHONE_STATUSES = EMAIL_STATUSES;

const ACCOUNT_TYPES = [
    'Personal' => 'شخصی',
    'Work' => 'کاری',
    'Business' => 'تجاری',
    'Project' => 'پروژه‌ای',
    'Other' => 'سایر',
    'Not Set' => 'تنظیم‌نشده',
];

const ACCOUNT_STATUSES = [
    'Active' => 'فعال',
    'Suspended' => 'معلق',
    'Disabled' => 'غیرفعال',
    'Closed' => 'بسته‌شده',
    'Abandoned' => 'رهاشده',
    'Pending' => 'در انتظار',
    'Unknown' => 'نامشخص',
];

const RECOVERY_STATUSES = [
    'Verified' => 'تأییدشده',
    'Not Verified' => 'تأییدنشده',
    'Unknown' => 'نامشخص',
    'Not Set' => 'تنظیم‌نشده',
    'Not Applicable' => 'غیرقابل‌اعمال',
];

const SUBSCRIPTION_TYPES = [
    'Free' => 'رایگان',
    'Paid' => 'پولی',
    'Trial' => 'آزمایشی',
    'Promotional' => 'پروموشن',
    'Lifetime' => 'مادام‌العمر',
    'Enterprise' => 'سازمانی',
    'Unknown' => 'نامشخص',
    'Not Applicable' => 'غیرقابل‌اعمال',
];

const SUBSCRIPTION_STATUSES = [
    'Active' => 'فعال',
    'Cancelled' => 'لغوشده',
    'Expired' => 'منقضی‌شده',
    'Paused' => 'متوقف‌شده',
    'Unknown' => 'نامشخص',
    'Not Applicable' => 'غیرقابل‌اعمال',
];

const BILLING_CYCLES = [
    'Monthly' => 'ماهانه',
    'Yearly' => 'سالانه',
    'Weekly' => 'هفتگی',
    'Quarterly' => 'فصلی',
    'One-Time' => 'یک‌بار',
    'Custom' => 'سفارشی',
    'Unknown' => 'نامشخص',
    'Not Applicable' => 'غیرقابل‌اعمال',
];

const HISTORY_ACTION_LABELS = [
    'Account Created' => 'ایجاد اکانت',
    'Account Updated' => 'به‌روزرسانی اکانت',
    'Status Changed' => 'تغییر وضعیت',
    '2FA Changed' => 'تغییر ۲مرحله‌ای',
    'Account Archived' => 'آرشیو شدن اکانت',
    'Account Unarchived' => 'خروج از آرشیو',
    'Email Linked' => 'اتصال ایمیل',
    'Email Unlinked' => 'قطع اتصال ایمیل',
    'Phone Linked' => 'اتصال شماره تلفن',
    'Phone Unlinked' => 'قطع اتصال شماره تلفن',
    'Custom Field Added' => 'افزودن فیلد سفارشی',
    'Custom Field Removed' => 'حذف فیلد سفارشی',
    'Tag Added' => 'افزودن برچسب',
    'Tag Removed' => 'حذف برچسب',
    'Subscription Changed' => 'تغییر Subscription',
    'Email Created' => 'ایجاد ایمیل',
    'Email Updated' => 'به‌روزرسانی ایمیل',
    'Service Created' => 'ایجاد سرویس',
    'Service Updated' => 'به‌روزرسانی سرویس',
    'Phone Created' => 'ایجاد شماره تلفن',
    'Phone Updated' => 'به‌روزرسانی شماره تلفن',
    'Imported' => 'وارد شده از فایل',
];

function badgeClassFor(string $value): string
{
    return match ($value) {
        'Active', 'Enabled', 'Verified' => 'badge-enabled',
        'Disabled', 'Not Verified', 'Cancelled' => 'badge-disabled',
        'Suspended', 'Paused' => 'badge-status-suspended',
        'Closed', 'Expired' => 'badge-status-closed',
        'Abandoned' => 'badge-status-abandoned',
        'Pending' => 'badge-status-pending',
        'Unknown' => 'badge-unknown',
        'Not Set' => 'badge-not-set',
        'Not Applicable' => 'badge-not-applicable',
        // Any other recognized-but-neutral value (Type/Category enums like Work,
        // Personal, Paid, Monthly, ...) is a known value, not an unknown one — it
        // must not be styled identically to the literal "Unknown" state.
        default => 'badge-category',
    };
}

function renderBadge(?string $value, array $labelMap = []): string
{
    $value = ($value === null || $value === '') ? 'Not Set' : $value;
    $label = $labelMap[$value] ?? $value;
    return '<span class="badge ' . badgeClassFor($value) . '">' . e($label) . '</span>';
}

function optionsHtml(array $map, ?string $selected = null): string
{
    $html = '';
    foreach ($map as $value => $label) {
        $sel = ($selected === $value) ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

function dashOrValue(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '<span class="text-muted fst-italic">—</span>';
    }
    return e($value);
}

function flashSet(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function oldInput(array $source, string $key, string $default = ''): string
{
    return (string) ($source[$key] ?? $default);
}

function log_history(
    PDO $pdo,
    string $entityType,
    int $entityId,
    string $action,
    ?string $fieldName = null,
    ?string $oldValue = null,
    ?string $newValue = null
): void {
    $stmt = $pdo->prepare('INSERT INTO history (entity_type, entity_id, action, field_name, old_value, new_value, changed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$entityType, $entityId, $action, $fieldName, $oldValue, $newValue, currentUserId()]);
}

function historyActionLabel(string $action): string
{
    return HISTORY_ACTION_LABELS[$action] ?? $action;
}

function fetchEntityTags(PDO $pdo, string $entityType, int $entityId): array
{
    $stmt = $pdo->prepare('SELECT t.id, t.name FROM tags t
        JOIN taggables tg ON tg.tag_id = t.id
        WHERE tg.entity_type = ? AND tg.entity_id = ? ORDER BY t.name');
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll();
}

function attachTag(PDO $pdo, string $entityType, int $entityId, string $tagName): void
{
    $tagName = trim($tagName);
    if ($tagName === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ? COLLATE NOCASE');
    $stmt->execute([$tagName]);
    $tagId = $stmt->fetchColumn();

    if (!$tagId) {
        $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
        $stmt->execute([$tagName]);
        $tagId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO taggables (tag_id, entity_type, entity_id) VALUES (?, ?, ?)');
    $stmt->execute([(int) $tagId, $entityType, $entityId]);
}

function detachTag(PDO $pdo, string $entityType, int $entityId, int $tagId): void
{
    $stmt = $pdo->prepare('DELETE FROM taggables WHERE tag_id = ? AND entity_type = ? AND entity_id = ?');
    $stmt->execute([$tagId, $entityType, $entityId]);
}

function navItemIsActive(string $relativePath, string|array $prefixes): bool
{
    foreach ((array) $prefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            return true;
        }
    }
    return false;
}

function entityProfileUrl(string $entityType, int $entityId): string
{
    $paths = [
        'email' => 'modules/emails/view.php?id=',
        'service' => 'modules/services/view.php?id=',
        'account' => 'modules/accounts/view.php?id=',
        'phone' => 'modules/phones/view.php?id=',
    ];
    return appUrl(($paths[$entityType] ?? '#') . $entityId);
}

function entityDisplayLabel(PDO $pdo, string $entityType, int $entityId): ?string
{
    $sql = match ($entityType) {
        'email' => 'SELECT email_address FROM emails WHERE id = ?',
        'service' => 'SELECT service_name FROM services WHERE id = ?',
        'phone' => 'SELECT phone_number FROM phones WHERE id = ?',
        'account' => "SELECT s.service_name || ' — ' || COALESCE(NULLIF(a.username, ''), e.email_address) AS label
            FROM accounts a JOIN services s ON s.id = a.service_id JOIN emails e ON e.id = a.email_id WHERE a.id = ?",
        default => null,
    };
    if ($sql === null) {
        return null;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entityId]);
    $label = $stmt->fetchColumn();
    return $label !== false ? (string) $label : null;
}
