<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));

$results = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    // Every entity query below is restricted to what the current user's role
    // may see (docs/PERMISSIONS.md) — without this, search surfaced private
    // records owned by someone else to any member/viewer who could guess or
    // stumble onto matching text (VERIFIED: an owner's private email was
    // returned to a member and a viewer here despite being correctly hidden
    // from the list/view pages).
    $emailsScope = visibilityScope('emails');
    $servicesScope = visibilityScope('services');
    $accountsScope = visibilityScope('accounts', 'a');
    $phonesScope = visibilityScope('phones');

    $stmt = $pdo->prepare("SELECT id, email_address, type, status FROM emails
        WHERE (email_address LIKE :q OR display_name LIKE :q OR provider LIKE :q OR purpose LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'email' AND tg.entity_id = emails.id AND t.name LIKE :q))
           AND ($emailsScope)
        ORDER BY email_address LIMIT 8");
    $stmt->execute(['q' => $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type' => 'email',
            'type_label' => t('nav.emails'),
            'id' => (int) $row['id'],
            'title' => $row['email_address'],
            'subtitle' => enumLabel($row['type'], EMAIL_TYPES) . ' — ' . enumLabel($row['status'], EMAIL_STATUSES),
            'url' => appUrl('modules/emails/view.php?id=' . $row['id']),
        ];
    }

    $stmt = $pdo->prepare("SELECT id, service_name, category, status FROM services
        WHERE (service_name LIKE :q OR category LIKE :q OR website LIKE :q OR purpose LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'service' AND tg.entity_id = services.id AND t.name LIKE :q))
           AND ($servicesScope)
        ORDER BY service_name LIMIT 8");
    $stmt->execute(['q' => $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type' => 'service',
            'type_label' => t('nav.services'),
            'id' => (int) $row['id'],
            'title' => $row['service_name'],
            'subtitle' => trim(($row['category'] ? $row['category'] . ' — ' : '') . enumLabel($row['status'], SERVICE_STATUSES)),
            'url' => appUrl('modules/services/view.php?id=' . $row['id']),
        ];
    }

    // LEFT JOINs (not INNER, unlike search.php) — an account's email_id/identity_phone_id
    // can legitimately be null under the Identity Anchor model (phone/username/other anchors),
    // and those accounts must still be findable here.
    $stmt = $pdo->prepare("SELECT a.id, a.username, a.display_name, a.identity_type, a.identity_value,
            s.service_name, e.email_address, p.phone_number
        FROM accounts a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN emails e ON e.id = a.email_id
        LEFT JOIN phones p ON p.id = a.identity_phone_id
        WHERE (a.username LIKE :q OR a.display_name LIKE :q OR a.external_account_id LIKE :q OR a.notes LIKE :q
           OR a.identity_value LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'account' AND tg.entity_id = a.id AND t.name LIKE :q)
           OR EXISTS (SELECT 1 FROM custom_fields cf
                      WHERE cf.account_id = a.id AND (cf.field_key LIKE :q OR cf.field_value LIKE :q))
           OR EXISTS (SELECT 1 FROM payments p2 WHERE p2.account_id = a.id AND p2.payment_reference LIKE :q))
           AND ($accountsScope)
        ORDER BY a.username LIMIT 8");
    $stmt->execute(['q' => $like]);
    foreach ($stmt->fetchAll() as $row) {
        $identity = match ($row['identity_type']) {
            'phone' => (string) ($row['phone_number'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'other' => (string) ($row['identity_value'] ?? ''),
            default => (string) ($row['email_address'] ?? ''),
        };
        $results[] = [
            'type' => 'account',
            'type_label' => t('nav.accounts'),
            'id' => (int) $row['id'],
            'title' => $row['username'] ?: ($row['display_name'] ?: ('#' . $row['id'])),
            'subtitle' => trim($row['service_name'] . ($identity !== '' ? ' — ' . $identity : '')),
            'url' => appUrl('modules/accounts/view.php?id=' . $row['id']),
        ];
    }

    $stmt = $pdo->prepare("SELECT id, phone_number, label, status FROM phones
        WHERE (phone_number LIKE :q OR label LIKE :q OR notes LIKE :q
           OR EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                      WHERE tg.entity_type = 'phone' AND tg.entity_id = phones.id AND t.name LIKE :q))
           AND ($phonesScope)
        ORDER BY phone_number LIMIT 8");
    $stmt->execute(['q' => $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type' => 'phone',
            'type_label' => t('nav.phones'),
            'id' => (int) $row['id'],
            'title' => $row['phone_number'],
            'subtitle' => trim(($row['label'] ? $row['label'] . ' — ' : '') . enumLabel($row['status'], PHONE_STATUSES)),
            'url' => appUrl('modules/phones/view.php?id=' . $row['id']),
        ];
    }

    $results = array_slice($results, 0, 8);
}

echo json_encode(['q' => $q, 'count' => count($results), 'results' => $results]);
