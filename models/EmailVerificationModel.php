<?php
/**
 * DCW Engage - Applicant Email Verification
 *
 * Proving control of an email address is the first step of every application
 * (see #67). Nothing is written to `applications` until it has been done, so a
 * drive-by visitor typing junk into the form can no longer create a row or
 * trigger a draft magic link to an address they do not own.
 *
 * Same shape as InviteModel / PasswordResetModel: a random token goes out by
 * email, only its SHA-256 is stored, and it works once.
 */

class EmailVerificationModel {
    /** How long a verification link stays usable. */
    private const DEFAULT_EXPIRY = '+1 hour';

    /** Links one address may ask for, per form, per hour. Keeps the endpoint
     *  from being used to flood somebody's inbox. */
    private const MAX_REQUESTS_PER_HOUR = 3;

    private $db;
    private $expiry;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();

        $config = require __DIR__ . '/../includes/config.php';
        $this->expiry = $config['security']['email_verify_expiry'] ?? self::DEFAULT_EXPIRY;
    }

    private function hashToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Start a verification for this address on this form.
     *
     * Returns the raw token and expiry when a link was issued, or null when
     * the address has already asked too many times. The caller must respond
     * identically either way.
     */
    public function request($formId, $email) {
        if ($this->recentRequestCount($formId, $email) >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $this->db->beginTransaction();

        try {
            // Asking again supersedes the previous link.
            $this->db->prepare(
                "UPDATE email_verifications SET invalidated_at = NOW()
                 WHERE form_id = :form AND email = :email
                   AND used_at IS NULL AND invalidated_at IS NULL"
            )->execute(['form' => $formId, 'email' => $email]);

            // Expiry is computed by the database so that it and the later
            // NOW() comparison share one clock (see PasswordResetModel).
            $seconds = max(60, strtotime($this->expiry) - time());

            $this->db->prepare(
                "INSERT INTO email_verifications (form_id, email, token_hash, expires_at)
                 VALUES (:form, :email, :hash, NOW() + INTERVAL :seconds SECOND)"
            )->execute([
                'form'    => $formId,
                'email'   => $email,
                'hash'    => $this->hashToken($token),
                'seconds' => $seconds,
            ]);

            $expiresAt = $this->db->query(
                "SELECT expires_at FROM email_verifications WHERE id = " . (int) $this->db->lastInsertId()
            )->fetchColumn();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** Counts superseded links too, otherwise each request would cancel the
     *  previous one and the limit could never be reached. */
    private function recentRequestCount($formId, $email) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM email_verifications
             WHERE form_id = :form AND email = :email
               AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute(['form' => $formId, 'email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Redeem a link. Returns the verified email, or null for every kind of
     * failure (unknown, expired, already used, superseded, wrong form) so the
     * caller cannot tell them apart.
     *
     * The token is claimed with a conditional UPDATE, so two requests racing
     * on the same link cannot both succeed.
     */
    public function consume($formId, $token) {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, email FROM email_verifications
             WHERE token_hash = :hash AND form_id = :form
               AND used_at IS NULL AND invalidated_at IS NULL
               AND expires_at > NOW()"
        );
        $stmt->execute(['hash' => $this->hashToken($token), 'form' => $formId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $claim = $this->db->prepare(
            "UPDATE email_verifications SET used_at = NOW()
             WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL"
        );
        $claim->execute(['id' => $row['id']]);

        return $claim->rowCount() === 1 ? $row['email'] : null;
    }
}
