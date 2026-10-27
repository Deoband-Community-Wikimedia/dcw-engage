<?php
/**
 * Renders an in-progress, unsaved form schema exactly as the public
 * renderer would (see #47) — no separate preview template to keep in sync
 * with renderer.php over time. The builder POSTs its live, not-yet-saved
 * schema here; nothing is read from or written to the `forms` table, and
 * renderer.php itself refuses to run its submission-handling branch
 * whenever $previewSchema is set (see the guard there), so this can never
 * accidentally create a real application no matter what's in the POST body.
 *
 * Because the preview goes through renderer.php, it also shows the form the way applicants
 * will see it: the applicant's name is asked once, at the email gate, and carried forward, so a
 * question that only asks for the name is left out of the preview too.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/FormModel.php';

// Same people who can open the builder (builder.php): owners, organizers and membership coordinators.
// Nothing is read from or written to the database here, so this grants no access to any form.
Auth::requireLogin();
requireRole(['owner', 'organizer', 'membership_coordinator']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !CSRF::validate($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Invalid preview request.');
}

$schema = json_decode($_POST['schema_json'] ?? '', true);

if (!is_array($schema) || (!is_array($schema['sections'] ?? null) && !is_array($schema['fields'] ?? null))) {
    http_response_code(400);
    die('Nothing to preview yet — add at least one question first.');
}

$schema['title'] = trim($schema['title'] ?? '') !== '' ? $schema['title'] : 'Untitled Form';
$schema['fields'] = FormModel::schemaFields($schema);
if (empty($schema['fields'])) {
    http_response_code(400);
    die('Nothing to preview yet — add at least one question first.');
}

global $formType, $previewSchema;
$formType = 'preview';
$previewSchema = $schema;

require __DIR__ . '/../forms/renderer.php';
