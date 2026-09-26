<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();
requireWriteAccess();

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

function respond(bool $ok, array $extra = []): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['ok' => $ok], $extra));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$redirect = (string) ($_POST['redirect'] ?? 'index.php');
if (!preg_match('/^index\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $redirect)) {
    $redirect = 'index.php';
}

$id = (int) ($_POST['id'] ?? 0);

if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || !$id) {
    if ($isAjax) {
        http_response_code(400);
        respond(false);
    }
    flashSet('danger', t('msg.invalid_request'));
    header('Location: ' . $redirect);
    exit;
}

$pdo = db();
$email = fetchEmailById($pdo, $id);

if (!$email) {
    if ($isAjax) {
        http_response_code(404);
        respond(false);
    }
    flashSet('danger', t('emails.not_found'));
    header('Location: index.php');
    exit;
}

// Same 404 as view.php for a private record this user cannot see — "exists
// but hidden" and "doesn't exist" must stay indistinguishable here too, not
// just on the profile page (VERIFIED: a member could flip is_favorite on
// the owner's private email with no visibility check at all).
if (!canSeeRecord($email['visibility'] ?? null, isset($email['owner_user_id']) ? (int) $email['owner_user_id'] : null)) {
    notFoundResponse(t('emails.not_found'));
}

// The record is visible, but toggling it is still a write — same gate
// view.php's other POST actions go through (requireEditRecord()), so a
// viewer, or a member who doesn't own a workspace-visible email, gets a 403
// instead of silently flipping the favorite flag.
requireEditRecord($email);

$newValue = ((int) $email['is_favorite']) ? 0 : 1;
$stmt = $pdo->prepare('UPDATE emails SET is_favorite = ? WHERE id = ?');
$stmt->execute([$newValue, $id]);

if ($isAjax) {
    respond(true, ['is_favorite' => (bool) $newValue]);
}

header('Location: ' . $redirect);
exit;
