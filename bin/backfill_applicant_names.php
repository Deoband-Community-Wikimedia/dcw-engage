<?php
/**
 * Repair historical applications whose applicant_name was saved as the
 * fallback "Applicant". Run from the repository root:
 *
 *   php bin/backfill_applicant_names.php
 *
 * Make a database backup first. The script only changes rows currently named
 * "Applicant" and only when it finds a matching name-like key in form_data.
 */
require_once __DIR__ . '/../includes/init.php';

$db = DB::getInstance()->getConnection();
$select = $db->query("SELECT id, form_data FROM applications WHERE applicant_name = 'Applicant'");
$update = $db->prepare('UPDATE applications SET applicant_name = :name WHERE id = :id AND applicant_name = \'Applicant\'');
$updated = 0;
$skipped = 0;

foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $application) {
    $data = json_decode($application['form_data'] ?? '', true);
    if (!is_array($data)) {
        $skipped++;
        continue;
    }

    $name = '';
    foreach ($data as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $normalizedKey = strtolower((string) $key);
        if (!preg_match('/(^|_)(full_?name|applicant_?name|your_?name|name)($|_)/', $normalizedKey)
            && !preg_match('/name.*age|age.*name/', $normalizedKey)) {
            continue;
        }

        $candidate = trim((string) $value);
        if ($candidate !== '') {
            $name = $candidate;
            break;
        }
    }

    if ($name === '') {
        $skipped++;
        continue;
    }

    $update->execute(['name' => $name, 'id' => $application['id']]);
    $updated += $update->rowCount();
}

echo "Updated {$updated} application(s); skipped {$skipped}.\n";
