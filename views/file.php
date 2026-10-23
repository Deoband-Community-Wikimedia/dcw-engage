<?php
/**
 * DCW Engage - uploaded files, served only to people allowed to see them.
 *
 *   /file?p=uploads/NAME          staff, signed in
 *   /file?p=uploads/NAME&t=TOKEN  an applicant, with the resume link of the application the file belongs to
 *
 * Direct requests to /uploads/ are refused by .htaccess, so this is the only way to read a file.
 * Who may read it depends on what the file belongs to:
 *   reimbursement receipt        support reviewers, finance, owners
 *   internet support receipt     support reviewers, finance, owners
 *   answer on an application     staff who can open that form (FormModel::userCanOpen), or the applicant
 *                                holding a valid resume link for that application
 * Anything else (unknown file, no permission) gets the same 404, so nobody can probe which files exist.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/ApplicationModel.php';

function file_not_found(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("File not found.\n");
}

$path  = (string) ($_GET['p'] ?? '');
$token = (string) ($_GET['t'] ?? '');

// Only plain file names directly inside uploads/ (FileUploader never writes anywhere else).
if (!preg_match('#^uploads/[A-Za-z0-9._-]+$#', $path) || str_contains($path, '..')) {
    file_not_found();
}
$uploadDir = realpath(__DIR__ . '/../uploads');
$full      = realpath(__DIR__ . '/../' . $path);
if ($uploadDir === false || $full === false || !is_file($full)
    || strpos($full, $uploadDir . DIRECTORY_SEPARATOR) !== 0) {
    file_not_found();
}

$db      = DB::getInstance()->getConnection();
$isStaff = Auth::check();
$allowed = false;

// Receipts: the finance and support reviewer roles.
foreach (['reimbursement_line_items', 'internet_requests'] as $table) {
    $st = $db->prepare("SELECT 1 FROM $table WHERE receipt_path = :p LIMIT 1");
    $st->execute(['p' => $path]);
    if ($st->fetchColumn()) {
        $allowed = $isStaff && Auth::hasAnyRole(['support_reviewer', 'finance', 'owner']);
        if (!$allowed) file_not_found();
        break;
    }
}

// Application answers. LIKE only narrows the search, on the file name alone because answers are stored
// with json_encode(), which writes "/" as "\/" ('_' is a LIKE wildcard, which only widens it);
// the exact match is checked on the decoded answers below.
if (!$allowed) {
    $st = $db->prepare(
        "SELECT a.id, a.form_data, f.id AS form_id, f.form_type
           FROM applications a JOIN forms f ON f.id = a.form_id
          WHERE a.form_data LIKE :p"
    );
    $st->execute(['p' => '%' . basename($path) . '%']);
    foreach ($st->fetchAll() as $row) {
        $data = json_decode((string) $row['form_data'], true);
        if (!is_array($data) || !in_array($path, $data, true)) continue;

        if ($isStaff) {
            $form = (new FormModel())->getFormById((int) $row['form_id']);
            if ($form && FormModel::userCanOpen($form)) { $allowed = true; break; }
        }
        if ($token !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            $app = (new ApplicationModel())->getApplicationByToken($token);
            if ($app && (int) $app['id'] === (int) $row['id']) { $allowed = true; break; }
        }
    }
}

if (!$allowed) {
    file_not_found();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($full) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: attachment; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
readfile($full);
exit;
