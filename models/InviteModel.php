<?php
/**
 * DCW Engage - Organizer Invitations
 *
 * Adding someone to the workspace is a two step handshake:
 *
 *   1. An owner creates an invite. We generate a token, email the raw value,
 *      and store only its SHA-256.
 *   2. The recipient opens the link and chooses a password, which creates
 *      the admin_users row.
 *
 * Receiving the token is what proves control of the inbox, so there is no
 * separate email verification step. Nothing exists in admin_users until
 * step 2 completes — an unaccepted invite cannot sign in.
 */

require_once __DIR__ . '/../includes/auth.php';

class InviteModel {
    /** How long an invitation stays usable, unless config overrides it. */
    private const DEFAULT_EXPIRY = '+7 days';

    /**
     * Minimum password length. Defined once on Auth; this alias exists so the
     * views that already reference InviteModel keep working.
     */
    public const MIN_PASSWORD_LENGTH = Auth::MIN_PASSWORD_LENGTH;

    private $db;
    private $expiry;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();

        $config = require __DIR__ . '/../includes/config.php';
        $this->expiry = $config['security']['invite_expiry'] ?? self::DEFAULT_EXPIRY;
    }

    /**
     * The stored form of a token. Sha-256 is the right tool here rather than
     * password_hash: the input is 256 bits of random, so there is nothing to
     * brute force, and a lookup has to be a single indexed query.
     */
    private function hashToken($token) {
        return hash('sha256', $token);
    }

    public function emailHasAccount($email) {
        $stmt = $this->db->prepare("SELECT 1 FROM admin_users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Issue an invitation and return the raw token.
     *
     * This is the only moment the raw token exists in the application; the
     * caller must hand it straight to the mailer. Any earlier pending invite
     * for the same address is revoked first, so re-inviting someone
     * invalidates the previous link instead of leaving two live doors.
     */
    public function create($email, $role, $invitedById, $invitedByEmail) {
        $role = $role === 'owner' ? 'owner' : 'organizer';
        $token = bin2hex(random_bytes(32));

        $this->db->beginTransaction();

        try {
            $this->db->prepare(
                "UPDATE admin_invites SET revoked_at = NOW()
                 WHERE email = :email AND accepted_at IS NULL AND revoked_at IS NULL"
            )->execute(['email' => $email]);

            // Computed by the database rather than by PHP, so a time zone
            // difference between the two cannot shorten or void the window.
            // See the longer note in PasswordResetModel.
            $seconds = max(3600, strtotime($this->expiry) - time());

            $this->db->prepare(
                "INSERT INTO admin_invites
                    (email, token_hash, role, invited_by, invited_by_email, expires_at)
                 VALUES (:email, :hash, :role, :by_id, :by_email, NOW() + INTERVAL :seconds SECOND)"
            )->execute([
                'email'    => $email,
                'hash'     => $this->hashToken($token),
                'role'     => $role,
                'by_id'    => $invitedById,
                'by_email' => $invitedByEmail,
                'seconds'  => $seconds,
            ]);

            $expiresAt = $this->db->query(
                "SELECT expires_at FROM admin_invites WHERE id = " . (int) $this->db->lastInsertId()
            )->fetchColumn();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Look up an invitation that is still usable: not accepted, not revoked,
     * not expired. Returns null for every failure mode, so a caller cannot
     * accidentally tell an expired token apart from a forged one.
     */
    public function findUsableByToken($token) {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM admin_invites
             WHERE token_hash = :hash
               AND accepted_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > NOW()"
        );
        $stmt->execute(['hash' => $this->hashToken($token)]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Turn a usable invitation into an account.
     *
     * The invite is re-checked inside the transaction and the UPDATE is
     * conditional, so two submissions racing each other cannot both create an
     * account: the second one matches zero rows and is rejected.
     *
     * Returns the new admin id, or null if the invite was consumed first.
     */
    public function redeem($token, $password) {
        $this->db->beginTransaction();

        try {
            $invite = $this->findUsableByToken($token);

            if (!$invite) {
                $this->db->rollBack();
                return null;
            }

            // Claim the invite first. Zero affected rows means another request
            // got there between the read above and this write.
            $claim = $this->db->prepare(
                "UPDATE admin_invites SET accepted_at = NOW()
                 WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL"
            );
            $claim->execute(['id' => $invite['id']]);

            if ($claim->rowCount() !== 1) {
                $this->db->rollBack();
                return null;
            }

            $this->db->prepare(
                "INSERT INTO admin_users (email, password_hash, role)
                 VALUES (:email, :hash, :role)"
            )->execute([
                'email' => $invite['email'],
                'hash'  => password_hash($password, PASSWORD_DEFAULT),
                'role'  => $invite['role'],
            ]);

            $adminId = (int) $this->db->lastInsertId();

            $this->db->commit();

            return ['id' => $adminId, 'email' => $invite['email'], 'role' => $invite['role']];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Invitations still waiting to be accepted, newest first. */
    public function listPending() {
        return $this->db->query(
            "SELECT id, email, role, invited_by_email, expires_at, created_at,
                    (expires_at <= NOW()) AS is_expired
             FROM admin_invites
             WHERE accepted_at IS NULL AND revoked_at IS NULL
             ORDER BY created_at DESC"
        )->fetchAll();
    }

    /** Everyone who can currently sign in. */
    public function listOrganizers() {
        return $this->db->query(
            "SELECT id, email, role, created_at, last_login
             FROM admin_users
             ORDER BY role = 'owner' DESC, email ASC"
        )->fetchAll();
    }

    /** Withdraw a pending invitation. Already accepted ones are untouched. */
    public function revoke($id) {
        $stmt = $this->db->prepare(
            "UPDATE admin_invites SET revoked_at = NOW()
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL"
        );
        $stmt->execute(['id' => (int) $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Remove an organizer's account so they can no longer sign in.
     *
     * The row is deleted outright rather than flagged: application_notes keep
     * their author via admin_email_snapshot (admin_id is ON DELETE SET NULL),
     * any pending reset tokens cascade away, and the person's live session
     * dies on its next request because Auth::check() re-reads the row and
     * finds it gone.
     *
     * $actingAdminId is the owner performing the removal. Two removals are
     * refused because they can lock the whole team out:
     *   - removing your own account
     *   - removing the last remaining owner
     *
     * Returns ['ok' => bool, 'reason' => string] so the caller can explain a
     * refusal precisely.
     */
    public function removeOrganizer($id, $actingAdminId) {
        $id = (int) $id;
        $actingAdminId = (int) $actingAdminId;

        if ($id === $actingAdminId) {
            return ['ok' => false, 'reason' => 'self'];
        }

        $stmt = $this->db->prepare("SELECT email, role FROM admin_users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();

        if (!$target) {
            return ['ok' => false, 'reason' => 'missing'];
        }

        if ($target['role'] === 'owner') {
            $ownerCount = (int) $this->db->query(
                "SELECT COUNT(*) FROM admin_users WHERE role = 'owner'"
            )->fetchColumn();

            if ($ownerCount <= 1) {
                return ['ok' => false, 'reason' => 'last_owner'];
            }
        }

        $del = $this->db->prepare("DELETE FROM admin_users WHERE id = :id");
        $del->execute(['id' => $id]);

        return [
            'ok'     => $del->rowCount() === 1,
            'reason' => 'removed',
            'email'  => $target['email'],
            'role'   => $target['role'],
        ];
    }
}
