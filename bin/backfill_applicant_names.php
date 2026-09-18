<?php
/**
 * Repair historical applications whose applicant_name was saved as the
 * fallback "Applicant". Run from the repository root:
 *
 *   php bin/backfill_applicant_names.php
 *   php bin/backfill_applicant_names.php --dry-run
 *
 * Safe to run multiple times: it only modifies rows currently named
 * "Applicant" where a valid applicant name can be resolved from form_data.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal.\n");
}

require_once __DIR__ . '/../includes/init.php';

$isDryRun = in_array('--dry-run', $argv ?? [], true);
if ($isDryRun) {
    echo "[DRY RUN MODE] No database changes will be committed.\n\n";
}

$db = DB::getInstance()->getConnection();
$select = $db->query("
    SELECT a.id, a.form_data, f.schema_json
    FROM applications a
    LEFT JOIN forms f ON a.form_id = f.id
    WHERE a.applicant_name = 'Applicant'
");
$update = $db->prepare('UPDATE applications SET applicant_name = :name WHERE id = :id AND applicant_name = \'Applicant\'');
$updated = 0;
$skipped = 0;

while ($application = $select->fetch(PDO::FETCH_ASSOC)) {
    $data = json_decode($application['form_data'] ?? '', true);
    if (!is_array($data)) {
        $skipped++;
        continue;
    }

    $schema = json_decode($application['schema_json'] ?? '', true);
    if (!is_array($schema)) {
        $schema = [];
    }

    $resolvedName = resolveApplicantName($data, $schema, '');
    if ($resolvedName === '' || $resolvedName === 'Applicant') {
        $skipped++;
        continue;
    }

    if ($isDryRun) {
        echo "[Dry-run] Would update App #{$application['id']}: 'Applicant' -> '{$resolvedName}'\n";
        $updated++;
    } else {
        $update->execute(['name' => $resolvedName, 'id' => $application['id']]);
        $updated += $update->rowCount();
    }
}

echo "\n" . ($isDryRun ? "Simulated update for" : "Updated") . " {$updated} application(s); skipped {$skipped}.\n";
