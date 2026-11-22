<?php
/**
 * Closes sent-back reimbursement claims that the applicant never answered.
 *
 * Run once a day from cron.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';

$model = new ReimbursementModel();
$closed = $model->autoCloseStaleInfoRequests();

foreach ($closed as $info) {
    AuditLog::record(
        'reimbursement.auto_closed', null, 'system', $info['email'],
        'Tracking: ' . $info['tracking_id'] . ' | No reply within ' . ReimbursementModel::INFO_EXPIRY_DAYS . ' days'
    );
    // Same "Rejected" email a reviewer's rejection sends; the note is what the applicant reads.
    Mailer::sendReimbursementStatusUpdate(
        $info['email'], $info['applicant_name'], $info['tracking_id'],
        $info['event_name'], 'Rejected', $info['note']
    );
}

echo count($closed) . " request(s) closed.\n";
