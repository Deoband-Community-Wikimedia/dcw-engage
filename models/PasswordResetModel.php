<?php
/**
 * DCW Engage - Password Resets
 *
 * Same shape as InviteModel: a random token goes out by email, only its
 * SHA-256 is stored, and it works once.
 *
 * The differences from an invitation are deliberate. A reset link opens an
 * account that already exists, so it lives for an hour rather than a week,
 * requesting one is rate limited so the endpoint cannot be used to flood
 * somebody's inbox, and completing one stamps the account so sessions opened
 * elsewhere are logged out.
 */

require_once __DIR__ . '/../includes/auth.php';

class PasswordResetModel {
    /** How long a reset link stays usable. */
    private const DEFAULT_EXPIRY = '+1 hour';

    /** Requests allowed per account per hour. */
    private const MAX_REQUESTS_PER_HOUR = 3;

    private $db;
    private $expiry;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();

        $config = require __DIR__ . '/../includes/config.php';
        $this->expiry = $config['security']['password_reset_expiry'] ?? self::DEFAULT_EXPIRY;
    }

    private function hashToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Start a reset.
     *
     * Returns the raw token and the account when one was issued, or null when
     * the address is unknown or the account has already asked too many times.
     *
     * The caller must respond identically in every case. Anything that varies
     * with the outcome turns this endpoint into a way to discover which
     * addresses have accounts.
     */
    public function request($email) {
        $stmt = $this->db->prepare(
            "SELECT id, email FROM admin_users WHERE email = :email"
        );
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            return null;
        }

        if ($this->recentRequestCount($admin['id']) >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $this->db->beginTransaction();

        try {
            // Asking again supersedes the previous link, so a forwarded or
            // shoulder-surfed older email stops working.
            $this->db->prepare(
                "UPDATE password_resets SET invalidated_at = NOW()
                 WHERE admin_id = :id AND used_at IS NULL AND invalidated_at IS NULL"
            )->execute(['id' => $admin['id']]);

            // Expiry is computed by the database, not by PHP.
            //
            // Every comparison that decides whether a link is still alive uses
            // MySQL NOW(). If PHP and MySQL sit in different time zones — which
            // they do far more often than anyone expects — a value computed
            // here and compared there is wrong by the offset between them. At a
            // one hour lifetime that is enough to hand out links which are
            // already expired.
            //
            // Taking the interval as a duration and letting NOW() supply the
            // base keeps both sides on one clock.
            $seconds = max(60, strtotime($this->expiry) - time());

            $this->db->prepare(
                "INSERT INTO password_resets (admin_id, token_hash, expires_at)
                 VALUES (:id, :hash, NOW() + INTERVAL :seconds SECOND)"
            )->execute([
                'id'      => $admin['id'],
                'hash'    => $this->hashToken($token),
                'seconds' => $seconds,
            ]);

            // Read back what the database actually stored, so the time shown
            // in the email is the time the link really dies.
            $expiresAt = $this->db->query(
                "SELECT expires_at FROM password_resets WHERE id = " . (int) $this->db->lastInsertId()
            )->fetchColumn();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'token'      => $token,
            'email'      => $admin['email'],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * How many resets this account has asked for in the last hour, counting
     * superseded ones. Without counting those, each new request would cancel
     * the previous and the limit would never be reached.
     */
    private function recentRequestCount($adminId) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM password_resets
             WHERE admin_id = :id AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute(['id' => $adminId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A reset that is still usable: unused, not superseded, not expired.
     * Returns null for every failure, so callers cannot tell them apart.
     */
    public function findUsableByToken($token) {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT r.*, a.email
             FROM password_resets r
             JOIN admin_users a ON a.id = r.admin_id
             WHERE r.token_hash = :hash
               AND r.used_at IS NULL
               AND r.invalidated_at IS NULL
               AND r.expires_at > NOW()"
        );
        $stmt->execute(['hash' => $this->hashToken($token)]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Set the new password.
     *
     * The token is claimed with a conditional UPDATE inside the transaction,
     * so two submissions racing each other cannot both go through: the second
     * matches zero rows and is rejected.
     *
     * password_changed_at is stamped in the same transaction. Auth compares
     * the session copy against it on every request, so any session still open
     * for this account — including one an attacker is holding — stops working
     * the moment this commits.
     */
    public function complete($token, $newPassword) {
        $this->db->beginTransaction();

        try {
            $reset = $this->findUsableByToken($token);

            if (!$reset) {
                $this->db->rollBack();
                return null;
            }

            $claim = $this->db->prepare(
                "UPDATE password_resets SET used_at = NOW()
                 WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL"
            );
            $claim->execute(['id' => $reset['id']]);

            if ($claim->rowCount() !== 1) {
                $this->db->rollBack();
                return null;
            }

            $this->db->prepare(
                "UPDATE admin_users
                 SET password_hash = :hash, password_changed_at = NOW()
                 WHERE id = :id"
            )->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id'   => $reset['admin_id'],
            ]);

            // Any other link this account was issued is now pointless.
            $this->db->prepare(
                "UPDATE password_resets SET invalidated_at = NOW()
                 WHERE admin_id = :id AND used_at IS NULL AND invalidated_at IS NULL"
            )->execute(['id' => $reset['admin_id']]);

            $this->db->commit();

            return ['id' => (int) $reset['admin_id'], 'email' => $reset['email']];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
