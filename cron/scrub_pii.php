<?php
/**
 * PII Scrubbing Cron Job
 *
 * Two jobs, run in order:
 *   1. Scrub personal data from applications that have aged out of the
 *      retention window (Rejected or Accepted, older than 6 months).
 *   2. Sweep /uploads for orphaned files — anything on disk that no
 *      application row references. These are left behind by rejected or
 *      errored submissions and would otherwise never be cleaned up.
 *
 * Terminal / cron only.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal/cron.");
}

require_once __DIR__ . '/../includes/init.php';

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
$targetDate = date('Y-m-d H:i:s', strtotime('-6 months'));

try {
    $stmt = $db->prepare("SELECT id, form_data FROM applications WHERE status IN ('Rejected', 'Accepted') AND updated_at < :target");
    $stmt->execute(['target' => $targetDate]);
    $applications = $stmt->fetchAll();

    $scrubbedCount = 0;

    foreach ($applications as $app) {
        $formData = json_decode($app['form_data'], true);

        if (is_array($formData)) {
            foreach ($formData as $key => $value) {
                // File path: delete the file, then mark it gone.
                $filePath = resolveUploadPath($value, $uploadDir);
                if ($filePath !== null) {
                    @unlink($filePath);
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

        $updateStmt = $db->prepare("UPDATE applications SET email = '[REDACTED]', applicant_name = '[REDACTED]', form_data = :data WHERE id = :id");
        $updateStmt->execute([
            'data' => json_encode($formData),
            'id' => $app['id']
        ]);

        $scrubbedCount++;
    }

    echo "[+] Scrubbed PII from $scrubbedCount application(s).\n";

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
        // Build the set of files still referenced by ANY application, not just
        // the ones scrubbed above — an orphan is a file referenced by none.
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
