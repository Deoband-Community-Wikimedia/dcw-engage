<?php
/**
 * Renders an in-progress, unsaved form schema exactly as the public
 * renderer would (see #47) — no separate preview template to keep in sync
 * with renderer.php over time. The builder POSTs its live, not-yet-saved
 * schema here; nothing is read from or written to the `forms` table, and
 * renderer.php itself refuses to run its submission-handling branch
 * whenever $previewSchema is set (see the guard there), so this can never
 * accidentally create a real application no matter what's in the POST body.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !CSRF::validate($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Invalid preview request.');
}

$schema = json_decode($_POST['schema_json'] ?? '', true);

if (!is_array($schema) || empty($schema['fields']) || !is_array($schema['fields'])) {
    http_response_code(400);
    die('Nothing to preview yet — add at least one question first.');
}

$schema['title'] = trim($schema['title'] ?? '') !== '' ? $schema['title'] : 'Untitled Form';

global $formType, $previewSchema;
$formType = 'preview';
$previewSchema = $schema;

require __DIR__ . '/../forms/renderer.php';
