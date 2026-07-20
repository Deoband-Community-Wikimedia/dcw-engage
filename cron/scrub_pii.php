<?php
/**
 * PII Scrubbing Cron Job
 * Target: Deletes sensitive user data from old or rejected applications
 * to ensure long-term global data compliance.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal/cron.");
}

require_once __DIR__ . '/../includes/init.php';

$db = DB::getInstance()->getConnection();

echo "[*] DCW Engage - Initiating PII Scrubbing Process...\n";

// Target applications rejected or accepted older than 6 months
$targetDate = date('Y-m-d H:i:s', strtotime('-6 months'));

try {
    $stmt = $db->prepare("SELECT id, form_data FROM applications WHERE status IN ('Rejected', 'Accepted') AND updated_at < :target");
    $stmt->execute(['target' => $targetDate]);
    $applications = $stmt->fetchAll();

    $scrubbedCount = 0;

    foreach ($applications as $app) {
        // Sanitize JSON
        $formData = json_decode($app['form_data'], true);
        if ($formData) {
            foreach ($formData as $key => $value) {
                // If the value is a string and starts with 'uploads/', it's a file path
                if (is_string($value) && strpos($value, 'uploads/') === 0) {
                    $fullPath = __DIR__ . '/../' . $value;
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                    $formData[$key] = '[FILE_DELETED]';
                }
                
                // Nullify known PII fields in JSON
                elseif (in_array(strtolower($key), ['email', 'full_name', 'name', 'phone', 'address', 'resume'])) {
                    $formData[$key] = '[REDACTED_PII]';
                }
            }
        }
        
        $updateStmt = $db->prepare("UPDATE applications SET email = '[REDACTED]', applicant_name = '[REDACTED]', form_data = :data WHERE id = :id");
        $updateStmt->execute([
            'data' => json_encode($formData),
            'id' => $app['id']
        ]);
        
        $scrubbedCount++;
    }

    echo "[+] SUCCESS: Successfully scrubbed PII from $scrubbedCount applications.\n";

} catch (Exception $e) {
    echo "[-] FATAL ERROR: " . $e->getMessage() . "\n";
}
