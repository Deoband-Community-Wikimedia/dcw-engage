<?php
/**
 * PII Scrubbing Cron Job
 *
 * Two jobs, run in order:
 *   1. Scrub personal data from applications that have aged out of the
 *      retention window (Rejected or Accepted, older than 6 months).
 *      Membership applications are skipped: staff look members up through
 *      them for as long as the membership runs (MemberModel::TERM), which is
 *      longer than this window.
 *   2. Sweep /uploads for orphaned files — anything on disk that no row
 *      references. These are left behind by rejected or errored submissions
 *      and would otherwise never be cleaned up. Applications, reimbursement
 *      receipts and internet support receipts all share /uploads, so all
 *      three count as references.
 *
 * Terminal / cron only.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal/cron.");
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/FormModel.php';

$db = DB::getInstance()->getConnection();
$uploadDir = realpath(__DIR__ . '/../uploads');

// Files never touched by the orphan sweep.
$protectedFiles = ['.htaccess', '.gitkeep'];

// Orphans younger than this are left alone, so a file that was just uploaded
// for an in-flight submission is never deleted out from under it.
$orphanGraceSeconds = 24 * 60 * 60;

echo "[*] DCW Engage - Initiating PII Scrubbing Process...\n";

/**
 * Turn a stored 'uploads/...' value into an absolute path inside the upload
 * directory, or null if it does not resolve to a real file within it. Guards
 * against a stored value trying to escape the uploads folder.
 */
function resolveUploadPath($value, $uploadDir) {
    if (!is_string($value) || strpos($value, 'uploads/') !== 0 || $uploadDir === false) {
        return null;
    }
    $full = realpath(__DIR__ . '/../' . $value);
    if ($full === false || strpos($full, $uploadDir . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $full;
}

// ---------------------------------------------------------------------------
// 1. Scrub aged-out applications
// ---------------------------------------------------------------------------
try {
    // The cut-off is computed by the database, so it uses the same clock as
    // updated_at. Rows that were already scrubbed are skipped: scrubbing
    // touches updated_at, so they would otherwise come back every 6 months.
    $stmt = $db->query(
        "SELECT a.id, a.form_data, f.form_type
           FROM applications a
           JOIN forms f ON f.id = a.form_id
          WHERE a.status IN ('Rejected', 'Accepted')
            AND a.updated_at < NOW() - INTERVAL 6 MONTH
            AND a.email NOT LIKE '[REDACTED]%'"
    );
    $applications = $stmt->fetchAll();

    $updateStmt = $db->prepare(
        "UPDATE applications SET email = :email, applicant_name = '[REDACTED]', form_data = :data WHERE id = :id"
    );

    $scrubbedCount = 0;
    $failedCount = 0;

    foreach ($applications as $app) {
        if (FormModel::isMembershipType($app['form_type'])) {
            continue;
        }

        // One row at a time, so a problem with one application never stops
        // the rest from being scrubbed.
        try {
            $formData = json_decode($app['form_data'], true);
            $filesToDelete = [];

            if (is_array($formData)) {
                foreach ($formData as $key => $value) {
                    // File path: mark it gone; the file is deleted once the row is saved.
                    $filePath = resolveUploadPath($value, $uploadDir);
                    if ($filePath !== null) {
                        $filesToDelete[] = $filePath;
                        $formData[$key] = '[FILE_DELETED]';
                        continue;
                    }

                    // Every other answer is treated as potential PII and redacted.
                    // Redacting by value rather than by a hardcoded list of field
                    // names means a personal field the organizer named something
                    // unexpected (father_name, dob, city, ...) is still cleaned.
                    $formData[$key] = '[REDACTED_PII]';
                }
            }

            // The email must stay unique per form (UNIQUE form_id + email), so the
            // row id goes into the placeholder. A plain '[REDACTED]' would collide
            // with the second scrubbed application on the same form.
            $updateStmt->execute([
                'email' => '[REDACTED]-' . $app['id'],
                'data'  => json_encode($formData),
                'id'    => $app['id'],
            ]);

            // Only now that the row no longer points at them are the files removed.
            foreach ($filesToDelete as $filePath) {
                @unlink($filePath);
            }

            $scrubbedCount++;
        } catch (Exception $e) {
            $failedCount++;
            echo "[-] Could not scrub application {$app['id']}: " . $e->getMessage() . "\n";
        }
    }

    echo "[+] Scrubbed PII from $scrubbedCount application(s)" . ($failedCount ? ", $failedCount failed" : '') . ".\n";

} catch (Exception $e) {
    echo "[-] FATAL ERROR during scrub: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 2. Sweep orphaned upload files
// ---------------------------------------------------------------------------
try {
    if ($uploadDir === false || !is_dir($uploadDir)) {
        echo "[*] No uploads directory to sweep.\n";
    } else {
        // Build the set of files still referenced by ANY row, not just the
        // applications scrubbed above — an orphan is a file referenced by none.
        // If any of these lookups fails, the exception skips the whole sweep:
        // with an incomplete set, live files would look like orphans.
        $referenced = [];
        $allStmt = $db->query("SELECT form_data FROM applications");
        foreach ($allStmt->fetchAll() as $row) {
            $data = json_decode($row['form_data'], true);
            if (is_array($data)) {
                foreach ($data as $value) {
                    $filePath = resolveUploadPath($value, $uploadDir);
                    if ($filePath !== null) {
                        $referenced[$filePath] = true;
                    }
                }
            }
        }

        // Reimbursement and internet support receipts live in the same folder.
        foreach ([
            "SELECT receipt_path FROM reimbursement_line_items WHERE receipt_path <> ''",
            "SELECT receipt_path FROM internet_requests WHERE receipt_path <> ''",
        ] as $sql) {
            foreach ($db->query($sql)->fetchAll() as $row) {
                $filePath = resolveUploadPath($row['receipt_path'], $uploadDir);
                if ($filePath !== null) {
                    $referenced[$filePath] = true;
                }
            }
        }

        $deletedOrphans = 0;
        $now = time();

        foreach (scandir($uploadDir) as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $protectedFiles, true)) {
                continue;
            }

            $full = $uploadDir . DIRECTORY_SEPARATOR . $entry;

            // Only sweep plain files, never subdirectories or dotfiles.
            if (!is_file($full) || $entry[0] === '.') {
                continue;
            }

            // Referenced by a live application — keep it.
            if (isset($referenced[$full])) {
                continue;
            }

            // Too new to be sure it is really an orphan — leave it for the
            // next run rather than risk deleting an in-flight upload.
            if (($now - filemtime($full)) < $orphanGraceSeconds) {
                continue;
            }

            if (@unlink($full)) {
                $deletedOrphans++;
                echo "    - removed orphan: $entry\n";
            }
        }

        echo "[+] Removed $deletedOrphans orphaned upload file(s).\n";
    }
} catch (Exception $e) {
    echo "[-] FATAL ERROR during orphan sweep: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 3. Goodies: clear delivery details from finished orders
// ---------------------------------------------------------------------------
// Name, phone and address are kept only until an order is Delivered or Cancelled, then 30 days more.
try {
    require_once __DIR__ . '/../models/PointsModel.php';
    $cleared = (new PointsModel($db))->scrubShipping(30);
    echo "[+] Cleared delivery details from $cleared finished goodies order(s).\n";
} catch (Throwable $e) {
    // Before the goodies tables exist this simply has nothing to do.
    echo "[-] Goodies delivery-details scrub skipped: " . $e->getMessage() . "\n";
}
