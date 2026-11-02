<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_card_pdf.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';

/**
 * DCW Engage - staff download of a member's card (/admin/membership-card?id=<application id>), file views/admin/membership_card.php.
 * Reviewers and owners only. The person is found from the application (as on the review page), so the
 * Member ID never comes from the request. A struck-off member's card is refused. Every print is logged.
 */
requireRole(['membership_reviewer', 'owner']);

$model   = new MemberModel();
$strikes = new MemberStrikeModel();
$scope   = $model->scopeFor(Auth::roles(), (string) Auth::email());
$by      = currentAdminIdentifier();

$id  = (int) ($_GET['id'] ?? 0);
$app = $id ? $model->getApplication($id, $scope) : false;
if (!$app) { http_response_code(404); die('Application not found.'); }

$held     = $model->membershipsForEmail((string) $app['email']);
$memberId = $held ? (string) $held[0]['member_id'] : '';
if ($memberId === '') { http_response_code(404); die('This person has no Member ID yet, so there is no card.'); }

if ($strikes->activeFor($memberId)) {
    http_response_code(403);
    die('This member is struck off. Reinstate them before printing a card.');
}

header('Cache-Control: private, no-store, max-age=0');

try {
    $cards = new MemberCardModel();
    $token = $cards->issue($memberId);   // returns the member's live card; only makes one if they have none yet, never retires it
    $data  = $cards->summary($memberId);
    if ($data === null) {
        throw new RuntimeException('No membership found.');
    }
    AuditLog::record('member.card_printed', Auth::id(), Auth::email(), $memberId, 'Application #' . $app['id']);
    member_card_pdf_send($memberId, $token, $data);
} catch (\Throwable $e) {
    app_log("Staff card PDF failed for $memberId by $by: " . $e->getMessage());
    http_response_code(500);
    die('Something went wrong making this card PDF. This has been logged.');
}
