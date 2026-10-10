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
 *
 * Methods:
 *   countForMember()   total number of certificates (dashboard chip and "View all" link)
 *   latestForMember()  the newest few (dashboard preview)
 *   searchForMember()  filtered, paginated list (/member/certificates)
 *   yearsForMember()   years that have at least one certificate (year filter)
 *   listForMember()    everything, kept for any older callers
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

    /** How many certificates this email has. */
    public function countForMember(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM member_certificates_v WHERE email = :email');
        $st->execute([':email' => $email]);
        return (int) $st->fetchColumn();
    }

    /** The newest $limit certificates, newest first. */
    public function latestForMember(string $email, int $limit = 5): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $st = $this->pdo->prepare(
            'SELECT * FROM member_certificates_v WHERE email = :email ORDER BY issued_at DESC LIMIT :lim'
        );
        $st->bindValue(':email', $email);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Filtered, paginated list.
     *   $q     matches event name or certificate ID (case-insensitive, partial)
     *   $year  0 = any year, otherwise the year of issue
     * Returns ['rows' => [...], 'total' => int] where total counts ALL matches (for the pager).
     */
    public function searchForMember(string $email, string $q = '', int $year = 0, int $limit = 20, int $offset = 0): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['rows' => [], 'total' => 0];
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where  = ['email = :email'];
        $params = [':email' => $email];

        $q = trim($q);
        if ($q !== '') {
            // Escape LIKE wildcards so a typed % or _ is searched literally.
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '\\%_') . '%';
            $where[] = '(event_name LIKE :q1 OR certificate_id LIKE :q2)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }
        if ($year >= 1990 && $year <= 2100) {
            // Range comparison keeps any index on issued_at usable.
            $where[] = 'issued_at >= :from AND issued_at < :to';
            $params[':from'] = sprintf('%04d-01-01 00:00:00', $year);
            $params[':to']   = sprintf('%04d-01-01 00:00:00', $year + 1);
        }
        $w = implode(' AND ', $where);

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM member_certificates_v WHERE $w");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $st = $this->pdo->prepare(
            "SELECT * FROM member_certificates_v WHERE $w ORDER BY issued_at DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Years in which this email received at least one certificate, newest first. */
    public function yearsForMember(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT DISTINCT YEAR(issued_at) AS y FROM member_certificates_v
             WHERE email = :email AND issued_at IS NOT NULL ORDER BY y DESC'
        );
        $st->execute([':email' => $email]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
