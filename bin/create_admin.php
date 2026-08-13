<?php
/**
 * DCW Engage - Create or reset an organizer account
 *
 * Terminal only. Run it once after importing database.sql, otherwise there
 * is no way to sign in to the workspace.
 *
 *   php bin/create_admin.php
 *   php bin/create_admin.php organiser@dcwwiki.org
 *
 * New accounts are created as owners, because reaching this script already
 * requires shell access to the server. Once one owner exists, everybody else
 * should be added through Team in the workspace instead, which sends an
 * emailed invitation rather than requiring a terminal.
 *
 * Running it again for an email that already exists resets that password and
 * leaves the role alone, so it doubles as the recovery path until we build
 * one in the UI.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal.");
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

/**
 * Read a line from the terminal.
 */
function prompt($label) {
    echo $label;
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}

$email = $argv[1] ?? prompt("Organizer email: ");

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("ERROR: '$email' is not a valid email address.\n");
}

$minLength = Auth::MIN_PASSWORD_LENGTH;
$password = prompt("Password (min $minLength characters): ");

if (strlen($password) < $minLength) {
    die("ERROR: Password must be at least $minLength characters.\n");
}

if ($password !== prompt("Confirm password: ")) {
    die("ERROR: Passwords did not match.\n");
}

$db = DB::getInstance()->getConnection();

// The role is deliberately absent from the UPDATE branch: resetting a
// password must never silently promote an existing organizer to owner.
$stmt = $db->prepare(
    "INSERT INTO admin_users (email, password_hash, role) VALUES (:email, :hash, 'owner')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)"
);

$stmt->execute([
    'email' => $email,
    'hash'  => password_hash($password, PASSWORD_DEFAULT)
]);

echo "\n[✓] Organizer account ready for $email\n";
echo "    Sign in at /admin/login\n";
