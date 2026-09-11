<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Per-entity importable field definitions. 'key' is the internal field name
 * used throughout the import pipeline (mapping, validation, row application).
 * 'enum' (when present) is validated case-insensitively against its keys.
 * Security/Recovery/Payment fields are intentionally NOT importable here —
 * those stay behind the normal Full Edit form so a bad CSV can never silently
 * plant incorrect security state.
 */
function importEntityFields(string $entity): array
{
    return match ($entity) {
        'email' => [
            ['key' => 'email_address', 'label' => 'Email Address', 'required' => true, 'enum' => null],
            ['key' => 'display_name', 'label' => 'Display Name', 'required' => false, 'enum' => null],
            ['key' => 'provider', 'label' => 'Provider', 'required' => false, 'enum' => null],
            ['key' => 'type', 'label' => 'Type', 'required' => false, 'enum' => EMAIL_TYPES],
            ['key' => 'purpose', 'label' => 'Purpose', 'required' => false, 'enum' => null],
            ['key' => 'status', 'label' => 'Status', 'required' => false, 'enum' => EMAIL_STATUSES],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false, 'enum' => null],
        ],
        'service' => [
            ['key' => 'service_name', 'label' => 'Service Name', 'required' => true, 'enum' => null],
            ['key' => 'website', 'label' => 'Website', 'required' => false, 'enum' => null],
            ['key' => 'login_url', 'label' => 'Login URL', 'required' => false, 'enum' => null],
            ['key' => 'category', 'label' => 'Category', 'required' => false, 'enum' => null],
            ['key' => 'status', 'label' => 'Status', 'required' => false, 'enum' => SERVICE_STATUSES],
            ['key' => 'purpose', 'label' => 'Purpose', 'required' => false, 'enum' => null],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false, 'enum' => null],
        ],
        'phone' => [
            ['key' => 'phone_number', 'label' => 'Phone Number', 'required' => true, 'enum' => null],
            ['key' => 'country', 'label' => 'Country', 'required' => false, 'enum' => null],
            ['key' => 'label', 'label' => 'Label', 'required' => false, 'enum' => null],
            ['key' => 'status', 'label' => 'Status', 'required' => false, 'enum' => PHONE_STATUSES],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false, 'enum' => null],
        ],
        'account' => [
            ['key' => 'service_name', 'label' => 'Service Name (must already exist)', 'required' => true, 'enum' => null],
            ['key' => 'email_address', 'label' => 'Email Address (must already exist)', 'required' => true, 'enum' => null],
            ['key' => 'username', 'label' => 'Username', 'required' => false, 'enum' => null],
            ['key' => 'display_name', 'label' => 'Display Name', 'required' => false, 'enum' => null],
            ['key' => 'external_account_id', 'label' => 'Account ID', 'required' => false, 'enum' => null],
            ['key' => 'status', 'label' => 'Status', 'required' => false, 'enum' => ACCOUNT_STATUSES],
            ['key' => 'account_type', 'label' => 'Account Type', 'required' => false, 'enum' => ACCOUNT_TYPES],
            ['key' => 'plan', 'label' => 'Plan', 'required' => false, 'enum' => null],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false, 'enum' => null],
        ],
        default => [],
    };
}

const IMPORT_ENTITY_LABELS = [
    'email' => 'ایمیل‌ها',
    'service' => 'سرویس‌ها',
    'phone' => 'شماره تلفن‌ها',
    'account' => 'اکانت‌ها',
];

function normalizeHeaderName(string $name): string
{
    $name = mb_strtolower(trim($name));
    return preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;
}

/**
 * Reads a CSV file into headers + associative rows. Normalizes line endings
 * and strips a UTF-8 BOM (common from Excel exports) before parsing.
 */
function parseCsvFile(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        return ['headers' => [], 'rows' => [], 'error' => 'امکان خواندن فایل وجود نداشت.'];
    }

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = array_filter(explode("\n", $content), static fn ($l) => trim($l) !== '');
    $lines = array_values($lines);

    if (!$lines) {
        return ['headers' => [], 'rows' => [], 'error' => 'فایل خالی است.'];
    }

    $headers = str_getcsv(array_shift($lines));
    $headers = array_map(static fn ($h) => trim((string) $h), $headers);

    if (count($lines) > 2000) {
        return ['headers' => $headers, 'rows' => [], 'error' => 'فایل بیش از ۲۰۰۰ ردیف دارد. لطفاً آن را به فایل‌های کوچک‌تر تقسیم کنید.'];
    }

    $rows = [];
    foreach ($lines as $line) {
        $cols = str_getcsv($line);
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

function guessColumnMapping(array $headers, array $fieldDefs): array
{
    $normalizedHeaders = [];
    foreach ($headers as $h) {
        $normalizedHeaders[normalizeHeaderName($h)] = $h;
    }

    $mapping = [];
    foreach ($fieldDefs as $field) {
        $candidates = [normalizeHeaderName($field['key']), normalizeHeaderName($field['label'])];
        $match = null;
        foreach ($candidates as $c) {
            if (isset($normalizedHeaders[$c])) {
                $match = $normalizedHeaders[$c];
                break;
            }
        }
        $mapping[$field['key']] = $match;
    }
    return $mapping;
}

function applyMapping(array $row, array $mapping): array
{
    $mapped = [];
    foreach ($mapping as $fieldKey => $csvColumn) {
        $mapped[$fieldKey] = $csvColumn !== null && isset($row[$csvColumn]) ? trim((string) $row[$csvColumn]) : '';
    }
    return $mapped;
}

/**
 * These DB columns are NOT NULL with a default (per the sec.0 schema) — an
 * explicit NULL in an INSERT overrides that default and violates the
 * constraint, so an empty mapped value must fall back to the same default
 * the manual Add forms already use, not to NULL.
 */
function importFieldEmptyDefault(string $entity, string $key): ?string
{
    $defaults = [
        'email' => ['type' => 'Not Set', 'status' => 'Unknown'],
        'service' => ['category' => 'Not Set', 'status' => 'Unknown'],
        'phone' => ['status' => 'Unknown'],
        'account' => ['status' => 'Unknown', 'account_type' => 'Not Set'],
    ];
    return $defaults[$entity][$key] ?? null;
}

function normalizeEnumValue(string $raw, array $enumMap): ?string
{
    if ($raw === '') {
        return null;
    }
    foreach (array_keys($enumMap) as $key) {
        if (mb_strtolower($key) === mb_strtolower($raw)) {
            return $key;
        }
    }
    return null;
}

/**
 * Returns a list of human-readable error strings; empty = the row is valid
 * and safe to write. For 'account', also confirms the referenced Service and
 * Email already exist (this import path never auto-creates them).
 */
function validateImportRow(string $entity, array $mapped, PDO $pdo): array
{
    $errors = [];
    $fields = importEntityFields($entity);

    foreach ($fields as $field) {
        $value = $mapped[$field['key']] ?? '';
        if ($field['required'] && $value === '') {
            $errors[] = $field['label'] . ' الزامی است.';
            continue;
        }
        if ($value !== '' && $field['enum'] !== null && normalizeEnumValue($value, $field['enum']) === null) {
            $errors[] = 'مقدار نامعتبر برای ' . $field['label'] . ': «' . $value . '»';
        }
    }

    if ($entity === 'email' && ($mapped['email_address'] ?? '') !== '' && !filter_var($mapped['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'آدرس ایمیل معتبر نیست.';
    }

    if ($entity === 'account') {
        if (($mapped['service_name'] ?? '') !== '') {
            $stmt = $pdo->prepare('SELECT id FROM services WHERE service_name = ? COLLATE NOCASE');
            $stmt->execute([$mapped['service_name']]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'سرویسی با نام «' . $mapped['service_name'] . '» یافت نشد؛ ابتدا آن را اضافه کنید.';
            }
        }
        if (($mapped['email_address'] ?? '') !== '') {
            $stmt = $pdo->prepare('SELECT id FROM emails WHERE email_address = ? COLLATE NOCASE');
            $stmt->execute([$mapped['email_address']]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'ایمیلی با آدرس «' . $mapped['email_address'] . '» یافت نشد؛ ابتدا آن را اضافه کنید.';
            }
        }
    }

    return $errors;
}

/**
 * Classifies a valid row as New / Exact Duplicate / Possible Duplicate
 * against existing data (spec sec. 42) — Exact means every mapped field
 * already matches the existing record; Possible means the natural key
 * matches but at least one other mapped field differs.
 */
function detectDuplicateStatus(string $entity, array $mapped, PDO $pdo): array
{
    $existing = null;

    if ($entity === 'email') {
        $stmt = $pdo->prepare('SELECT * FROM emails WHERE email_address = ? COLLATE NOCASE');
        $stmt->execute([$mapped['email_address']]);
        $existing = $stmt->fetch() ?: null;
    } elseif ($entity === 'service') {
        $stmt = $pdo->prepare('SELECT * FROM services WHERE service_name = ? COLLATE NOCASE');
        $stmt->execute([$mapped['service_name']]);
        $existing = $stmt->fetch() ?: null;
    } elseif ($entity === 'phone') {
        $stmt = $pdo->prepare('SELECT * FROM phones WHERE phone_number = ?');
        $stmt->execute([$mapped['phone_number']]);
        $existing = $stmt->fetch() ?: null;
    } elseif ($entity === 'account') {
        $svc = $pdo->prepare('SELECT id FROM services WHERE service_name = ? COLLATE NOCASE');
        $svc->execute([$mapped['service_name']]);
        $serviceId = $svc->fetchColumn();
        $eml = $pdo->prepare('SELECT id FROM emails WHERE email_address = ? COLLATE NOCASE');
        $eml->execute([$mapped['email_address']]);
        $emailId = $eml->fetchColumn();
        if ($serviceId && $emailId) {
            $stmt = $pdo->prepare('SELECT * FROM accounts WHERE service_id = ? AND email_id = ? AND username = ? COLLATE NOCASE');
            $stmt->execute([$serviceId, $emailId, $mapped['username'] ?? '']);
            $existing = $stmt->fetch() ?: null;
        }
    }

    if (!$existing) {
        return ['status' => 'New', 'existing_id' => null, 'diff_fields' => []];
    }

    $diffFields = [];
    foreach (importEntityFields($entity) as $field) {
        $key = $field['key'];
        if (in_array($key, ['service_name', 'email_address'], true) && $entity === 'account') {
            continue;
        }
        $newValue = $mapped[$key] ?? '';
        $oldValue = (string) ($existing[$key] ?? '');
        if ($newValue !== '' && $newValue !== $oldValue) {
            $diffFields[] = $key;
        }
    }

    return [
        'status' => $diffFields ? 'Possible Duplicate' : 'Exact Duplicate',
        'existing_id' => (int) $existing['id'],
        'diff_fields' => $diffFields,
    ];
}

/**
 * Applies one row's decided action. Never overwrites an existing record
 * unless the caller explicitly chose 'update' — 'skip' and unmapped 'create'
 * are the only other paths, matching spec sec. 42's no-silent-overwrite rule.
 *
 * On 'update', a field the user never mapped to any CSV column is left out
 * of the SET clause entirely (the existing value is untouched) — only fields
 * the user actually chose to map can change an existing record, and only a
 * blank cell in a mapped column resets that field to its empty state.
 */
function importRow(PDO $pdo, string $entity, array $mapped, array $mapping, string $action, ?int $existingId, string $source): array
{
    if ($action === 'skip') {
        return ['status' => 'skipped', 'id' => $existingId];
    }

    $table = ['email' => 'emails', 'service' => 'services', 'phone' => 'phones', 'account' => 'accounts'][$entity];
    $createdLabel = ['email' => 'Email Created', 'service' => 'Service Created', 'phone' => 'Phone Created', 'account' => 'Account Created'][$entity];
    $updatedLabel = ['email' => 'Email Updated', 'service' => 'Service Updated', 'phone' => 'Phone Updated', 'account' => 'Account Updated'][$entity];

    $data = [];
    foreach (importEntityFields($entity) as $field) {
        $key = $field['key'];
        if ($entity === 'account' && in_array($key, ['service_name', 'email_address'], true)) {
            continue;
        }
        if ($action === 'update' && empty($mapping[$key])) {
            continue;
        }
        $value = $mapped[$key] ?? '';
        if ($value === '') {
            $data[$key] = importFieldEmptyDefault($entity, $key);
            continue;
        }
        $data[$key] = ($field['enum'] !== null) ? normalizeEnumValue($value, $field['enum']) : $value;
    }

    if ($entity === 'account') {
        $svc = $pdo->prepare('SELECT id FROM services WHERE service_name = ? COLLATE NOCASE');
        $svc->execute([$mapped['service_name']]);
        $data['service_id'] = (int) $svc->fetchColumn();
        $eml = $pdo->prepare('SELECT id FROM emails WHERE email_address = ? COLLATE NOCASE');
        $eml->execute([$mapped['email_address']]);
        $data['email_id'] = (int) $eml->fetchColumn();
        $plan = $data['plan'] ?? null;
        unset($data['plan']);
    }

    if ($action === 'create' || ($action === 'update' && !$existingId)) {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        $newId = (int) $pdo->lastInsertId();

        if ($entity === 'account' && !empty($plan)) {
            $stmt = $pdo->prepare('INSERT INTO subscriptions (account_id, plan) VALUES (?, ?)');
            $stmt->execute([$newId, $plan]);
        }

        log_history($pdo, $entity, $newId, $createdLabel);
        log_history($pdo, $entity, $newId, 'Imported', 'source', null, $source);
        return ['status' => 'created', 'id' => $newId];
    }

    if ($action === 'update' && $existingId) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE id = :id');
        $stmt->execute($data + ['id' => $existingId]);

        if ($entity === 'account' && !empty($plan)) {
            $exists = $pdo->prepare('SELECT id FROM subscriptions WHERE account_id = ?');
            $exists->execute([$existingId]);
            if ($exists->fetchColumn()) {
                $pdo->prepare('UPDATE subscriptions SET plan = ? WHERE account_id = ?')->execute([$plan, $existingId]);
            } else {
                $pdo->prepare('INSERT INTO subscriptions (account_id, plan) VALUES (?, ?)')->execute([$existingId, $plan]);
            }
        }

        log_history($pdo, $entity, $existingId, $updatedLabel, null, null, 'به‌روزرسانی شده از فایل Import');
        log_history($pdo, $entity, $existingId, 'Imported', 'source', null, $source);
        return ['status' => 'updated', 'id' => $existingId];
    }

    return ['status' => 'skipped', 'id' => $existingId];
}
