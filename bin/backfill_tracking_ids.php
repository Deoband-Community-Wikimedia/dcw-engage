<?php
/**
 * DCW Engage - Backfill tracking IDs on an existing install
 *
 * Terminal only. Run once, after applying the `tracking_id` column ALTER
 * documented in database.sql (see #32), on a database that already has
 * application rows — a fresh import never needs this, the column is on
 * the CREATE TABLE itself so every row gets one at insert time.
 *
 *   php bin/backfill_tracking_ids.php
 *
 * Safe to run more than once: it only ever touches rows where tracking_id
 * is still NULL.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal.");
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/ApplicationModel.php';

$appModel = new ApplicationModel();
$updated = $appModel->backfillMissingTrackingIds();

echo "[✓] Assigned tracking IDs to $updated application(s).\n";
