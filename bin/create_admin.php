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
 * Running it again for an email that already exists resets that password,
 * so it doubles as the recovery path until we build one in the UI.
 */
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be executed via terminal.");
}

require_once __DIR__ . '/../includes/init.php';

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

$password = prompt("Password (min 12 characters): ");

if (strlen($password) < 12) {
    die("ERROR: Password must be at least 12 characters.\n");
}

if ($password !== prompt("Confirm password: ")) {
    die("ERROR: Passwords did not match.\n");
}

$db = DB::getInstance()->getConnection();

$stmt = $db->prepare(
    "INSERT INTO admin_users (email, password_hash) VALUES (:email, :hash)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)"
);

$stmt->execute([
    'email' => $email,
    'hash'  => password_hash($password, PASSWORD_DEFAULT)
]);

echo "\n[✓] Organizer account ready for $email\n";
echo "    Sign in at /admin/login\n";
