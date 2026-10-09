<?php
/**
 * DCW Engage - credentials for the certificates database.
 *
 * SETUP: copy this file to includes/certs_db.php and fill in the real values.
 * certs_db.php is listed in .gitignore. NEVER commit it.
 *
 * Used read-only by models/MemberCertificateModel.php. If the certificates portal
 * is not reachable, the "My certificates" panel simply stays empty.
 */
return [
    'host' => 'localhost',
    'name' => 'your_certificates_database',
    'user' => 'your_certificates_db_user',
    'pass' => 'CHANGE_ME',
];
