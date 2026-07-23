<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';

// POST only, so a stray link or an image tag on another site cannot sign
// an organizer out mid review.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !CSRF::validate($_POST['csrf_token'] ?? '')) {
    header('Location: /admin/dashboard');
    exit;
}

Auth::logout();

header('Location: /admin/login');
exit;
