<?php
declare(strict_types=1);

require_once __DIR__ . '/lang.php';

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

/**
 * Resolves the display label for a closed-enum DB value: prefers the
 * active language's 'enum.<value>' translation, falling back to the
 * Persian label baked into the constant maps above (and finally the
 * raw value itself) so untranslated values never disappear.
 */
function enumLabel(string $value, array $fallbackMap = []): string
{
    $key = 'enum.' . $value;
    $translated = t($key);
    return $translated !== $key ? $translated : ($fallbackMap[$value] ?? $value);
}

function renderBadge(?string $value, array $labelMap = []): string
{
    $value = ($value === null || $value === '') ? 'Not Set' : $value;
    $label = enumLabel($value, $labelMap);
    return '<span class="badge ' . badgeClassFor($value) . '">' . e($label) . '</span>';
}

/**
 * Renders a Yes/No/Unknown badge for a tri-state boolean flag (e.g. auto-renewal,
 * payment required). Deliberately bypasses renderBadge()/enumLabel() so the
 * Yes/No wording is never swapped out for the generic Enabled/Disabled enum
 * translation.
 */
function yesNoBadge(?bool $value): string
{
    if ($value === null) {
        return renderBadge('Unknown');
    }
    $class = $value ? 'badge-enabled' : 'badge-disabled';
    $label = $value ? t('common.yes') : t('common.no');
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function optionsHtml(array $map, ?string $selected = null): string
{
    $html = '';
    foreach ($map as $value => $label) {
        $label = enumLabel($value, $map);
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
    $key = 'history.' . $action;
    $translated = t($key);
    return $translated !== $key ? $translated : (HISTORY_ACTION_LABELS[$action] ?? $action);
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

const PAGINATION_PER_PAGE_OPTIONS = [10, 25, 50, 100, 250];
const PAGINATION_DEFAULT_PER_PAGE = 25;

/** @return int|'all' */
function resolvePerPage(?string $raw)
{
    if ($raw === 'all') {
        return 'all';
    }
    $n = (int) $raw;
    return in_array($n, PAGINATION_PER_PAGE_OPTIONS, true) ? $n : PAGINATION_DEFAULT_PER_PAGE;
}

function resolvePage(?string $raw): int
{
    $n = (int) $raw;
    return $n > 0 ? $n : 1;
}

/**
 * Clamps the requested page against the real page count and returns
 * [$page, $limit, $offset] — $limit is null when $perPage is 'all' (no LIMIT clause).
 *
 * @param int|'all' $perPage
 * @return array{0:int,1:?int,2:int}
 */
function paginationBounds(int $totalCount, int $page, $perPage): array
{
    if ($perPage === 'all') {
        return [1, null, 0];
    }
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min(max(1, $page), $totalPages);
    return [$page, $perPage, ($page - 1) * $perPage];
}

function pageUrl(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    return '?' . e(http_build_query($params));
}

/** @param int|'all' $perPage */
function renderPagination(int $totalCount, int $page, $perPage): string
{
    $totalPages = $perPage === 'all' ? 1 : max(1, (int) ceil($totalCount / $perPage));

    $html = '<div class="am-pagination d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">';

    $html .= '<form method="get" class="d-flex align-items-center gap-2">';
    foreach ($_GET as $key => $value) {
        if ($key === 'per_page' || $key === 'page') {
            continue;
        }
        foreach ((array) $value as $v) {
            $name = is_array($value) ? $key . '[]' : $key;
            $html .= '<input type="hidden" name="' . e($name) . '" value="' . e((string) $v) . '">';
        }
    }
    $html .= '<label class="text-muted small mb-0">' . t('pagination.show') . '</label>';
    $html .= '<select name="per_page" class="form-select form-select-sm w-auto" onchange="this.form.submit()">';
    foreach (PAGINATION_PER_PAGE_OPTIONS as $opt) {
        $html .= '<option value="' . $opt . '" ' . ($perPage === $opt ? 'selected' : '') . '>' . $opt . '</option>';
    }
    $html .= '<option value="all" ' . ($perPage === 'all' ? 'selected' : '') . '>' . t('pagination.all') . '</option>';
    $html .= '</select>';
    $html .= '<span class="text-muted small">' . t('pagination.of_records', ['count' => $totalCount]) . '</span>';
    $html .= '</form>';

    if ($totalPages > 1) {
        $html .= '<nav aria-label="pagination"><ul class="pagination pagination-sm mb-0">';

        $prevDisabled = $page <= 1 ? ' disabled' : '';
        $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . pageUrl(['page' => max(1, $page - 1)]) . '">' . t('pagination.prev') . '</a></li>';

        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . pageUrl(['page' => 1]) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $active = $p === $page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . pageUrl(['page' => $p]) . '">' . $p . '</a></li>';
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . pageUrl(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
        }

        $nextDisabled = $page >= $totalPages ? ' disabled' : '';
        $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . pageUrl(['page' => min($totalPages, $page + 1)]) . '">' . t('pagination.next') . '</a></li>';

        $html .= '</ul></nav>';
    }

    $html .= '</div>';
    return $html;
}
