<?php
/**
 * DCW Engage - read-only list of certificates issued on certificates.dcwwiki.org.
 *
 * Reads ONLY the database view `member_certificates_v` (created on the certificates
 * database, see docs/member_certificates_view.sql). Because Engage never touches the
 * portal's own tables, changes to the portal's schema (multi-organisation work and so on)
 * only mean updating the view's SQL, never this file.
 *
 * Columns the view must provide:
 *   certificate_id, email, event_name, role_name, issued_at
 * Optional columns, shown automatically when present:
 *   org_name, base_url
 */
class MemberCertificateModel
{
    private PDO $pdo;

    public function __construct()
    {
        $file = __DIR__ . '/../includes/certs_db.php';
        if (!is_file($file)) {
            throw new RuntimeException('includes/certs_db.php is missing');
        }
        $c = require $file;

        $this->pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
    }

    /** Every certificate issued to this (verified) email, newest first. */
    public function listForMember(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT * FROM member_certificates_v WHERE email = :email ORDER BY issued_at DESC'
        );
        $st->execute([':email' => $email]);
        return $st->fetchAll();
    }
}
