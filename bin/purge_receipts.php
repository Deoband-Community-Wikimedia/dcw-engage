<?php
/**
 * DCW Engage - Receipt purge (run from cron, e.g. hourly)
 *
 * Deletes receipt files for reimbursement requests that are Paid AND whose
 * finance user confirmed they downloaded the receipts
 * (reimbursement_requests.receipts_downloaded_at). Nothing else is ever
 * touched: no confirmation, no deletion.
 *
 * Each stored path is blanked once its file is gone, so re-runs are no-ops.
 * If a file can't be deleted it is left in place and retried next run.
 *
 * Suggested crontab entry (hourly):
 *   15 * * * * /usr/bin/php /path/to/dcw-engage/bin/purge_receipts.php >> /path/to/purge_receipts.log 2>&1
 *
 * Bootstrap: this assumes includes/init.php loads config and the DB class,
 * as the web views do. If bin/create_admin.php uses a different bootstrap,
 * mirror that here instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';

$root = realpath(__DIR__ . '/..');
$uploadsRoot = realpath($root . '/uploads');

if ($uploadsRoot === false) {
    fwrite(STDERR, "uploads/ directory not found under $root — nothing purged.\n");
    exit(1);
}

$model = new ReimbursementModel();
$rows = $model->listReceiptsReadyForPurge();

$deleted = 0;
$failed = 0;

foreach ($rows as $row) {
    $relative = $row['receipt_path'];
    $full = realpath($root . '/' . $relative);

    // File already gone (e.g. removed by hand): just clear the stale path.
    if ($full === false) {
        $model->markReceiptPurged($row['id']);
        continue;
    }

    // Only ever delete inside uploads/ — never trust a stored path blindly.
    if (strpos($full, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        app_log("purge_receipts: refusing to delete outside uploads/: $relative");
        $failed++;
        continue;
    }

    if (@unlink($full)) {
        $model->markReceiptPurged($row['id']);
        $deleted++;
    } else {
        app_log("purge_receipts: could not delete $relative");
        $failed++;
    }
}

echo date('c') . " purge_receipts: deleted $deleted, failed $failed, checked " . count($rows) . "\n";
exit($failed > 0 ? 1 : 0);
