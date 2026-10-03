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
 *
 * ROLES
 * An account can hold several roles. They live in admin_user_roles (one row
 * per role). admin_users.role still exists and always holds the account's
 * PRIMARY role, the first match in self::ROLES, so code that has not yet
 * moved to multi-role checks keeps working. Pending invites store the whole
 * list as comma-separated text in admin_invites.roles.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/MemberModel.php';

class InviteModel {
    /** How long an invitation stays usable, unless config overrides it. */
    private const DEFAULT_EXPIRY = '+7 days';

    /**
     * Minimum password length. Defined once on Auth; this alias exists so the
     * views that already reference InviteModel keep working.
     */
    public const MIN_PASSWORD_LENGTH = Auth::MIN_PASSWORD_LENGTH;

    /**
     * Every role an account can hold, highest privilege first. Defined once
     * on Auth; the first role a person holds becomes their primary role.
     */
    public const ROLES = Auth::ROLES;

    /**
     * Chapters a membership coordinator can be limited to (key => label).
     * Read from MemberModel so there is one list for the whole app.
     */
    public const CHAPTERS = MemberModel::CHAPTER_NAMES;

    private $db;
    private $expiry;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();

        $config = require __DIR__ . '/../includes/config.php';
        $this->expiry = $config['security']['invite_expiry'] ?? self::DEFAULT_EXPIRY;
    }

    /**
     * Turn whatever a form or column gave us (array, CSV string, junk) into a
     * clean list: known roles only, no duplicates, highest privilege first.
     * Returns an empty array when nothing valid is left; callers decide
     * whether that is an error.
     */
    public static function normalizeRoles($roles) {
        if (is_string($roles)) {
            $roles = explode(',', $roles);
        }
        if (!is_array($roles)) {
            return [];
        }

        $picked = [];
        foreach ($roles as $role) {
            $role = trim((string) $role);
            if (in_array($role, self::ROLES, true)) {
                $picked[$role] = true;
            }
        }

        $out = [];
        foreach (self::ROLES as $role) {
            if (isset($picked[$role])) {
                $out[] = $role;
            }
        }
        return $out;
    }

    /** Known chapter keys only, no duplicates, in CHAPTERS order. Accepts an array or CSV. */
    public static function normalizeChapters($chapters) {
        if (is_string($chapters)) {
            $chapters = explode(',', $chapters);
        }
        if (!is_array($chapters)) {
            return [];
        }

        $picked = [];
        foreach ($chapters as $chapter) {
            $chapter = trim((string) $chapter);
            if (isset(self::CHAPTERS[$chapter])) {
                $picked[$chapter] = true;
            }
        }

        $out = [];
        foreach (array_keys(self::CHAPTERS) as $key) {
            if (isset($picked[$key])) {
                $out[] = $key;
            }
        }
        return $out;
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
     * $roles is an array of role names (a single string also works). This is
     * the only moment the raw token exists in the application; the caller
     * must hand it straight to the mailer. Any earlier pending invite for the
     * same address is revoked first, so re-inviting someone invalidates the
     * previous link instead of leaving two live doors.
     *
     * CALLER CONTRACT: only an 'owner' should be able to invite anyone. This
     * method does not enforce that itself, so the check belongs in the view
     * that collects the invite form (team.php).
     */
    public function create($email, $roles, $invitedById, $invitedByEmail, $chapters = []) {
        $roles = self::normalizeRoles($roles);
        if (!$roles) {
            throw new InvalidArgumentException('At least one valid role is required.');
        }

        $primary = $roles[0];
        $token = bin2hex(random_bytes(32));

        // Chapters only mean something for a coordinator; drop them otherwise
        // so an invite never carries access its roles don't use.
        $chapters = in_array('membership_coordinator', $roles, true)
            ? self::normalizeChapters($chapters)
            : [];

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
                    (email, token_hash, role, roles, chapters, invited_by, invited_by_email, expires_at)
                 VALUES (:email, :hash, :role, :roles, :chapters, :by_id, :by_email, NOW() + INTERVAL :seconds SECOND)"
            )->execute([
                'email'    => $email,
                'hash'     => $this->hashToken($token),
                'role'     => $primary,
                'roles'    => implode(',', $roles),
                'chapters' => implode(',', $chapters),
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

        return ['token' => $token, 'expires_at' => $expiresAt, 'roles' => $roles, 'chapters' => $chapters];
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
     * Returns the new admin's details, or null if the invite was consumed
     * first.
     */
    public function redeem($token, $password) {
        $this->db->beginTransaction();

        try {
            $invite = $this->findUsableByToken($token);

            if (!$invite) {
                $this->db->rollBack();
                return null;
            }

            // Never create an account from a blank or unknown role list. That
            // is what a too-narrow column produces, and such an account could
            // sign in but see nothing. Throwing rolls the transaction back,
            // so the invite stays unused and can be fixed or re-sent.
            $stored = (($invite['roles'] ?? '') !== '') ? $invite['roles'] : $invite['role'];
            $roles = self::normalizeRoles($stored);
            if (!$roles) {
                throw new RuntimeException('Invite #' . (int) $invite['id'] . ' has no valid role.');
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
                'role'  => $roles[0],
            ]);

            $adminId = (int) $this->db->lastInsertId();
            $this->writeRoles($adminId, $roles);

            $this->db->commit();

            // Hand a coordinator the chapters chosen at invite time, through
            // the same MemberModel call Membership Access uses. This runs
            // after the commit so it cannot undo a valid account; if it fails
            // the account still exists and an owner can tick chapters on
            // Membership Access. 'chapters_saved' tells the caller which.
            $chapters = in_array('membership_coordinator', $roles, true)
                ? self::normalizeChapters($invite['chapters'] ?? '')
                : [];
            $chaptersSaved = true;
            if ($chapters) {
                try {
                    (new MemberModel())->setChapters($invite['email'], $chapters);
                } catch (Throwable $e) {
                    $chaptersSaved = false;
                    error_log('Invite redeem: could not set chapters for ' . $invite['email'] . ': ' . $e->getMessage());
                }
            }

            return [
                'id' => $adminId, 'email' => $invite['email'], 'role' => $roles[0], 'roles' => $roles,
                'chapters' => $chapters, 'chapters_saved' => $chaptersSaved,
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Invitations still waiting to be accepted, newest first. */
    public function listPending() {
        $rows = $this->db->query(
            "SELECT id, email, role, roles, chapters, invited_by_email, expires_at, created_at,
                    (expires_at <= NOW()) AS is_expired
             FROM admin_invites
             WHERE accepted_at IS NULL AND revoked_at IS NULL
             ORDER BY created_at DESC"
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['role_list'] = self::normalizeRoles($row['roles'] !== '' ? $row['roles'] : $row['role']);
            $row['chapter_list'] = self::normalizeChapters($row['chapters'] ?? '');
        }
        unset($row);

        return $rows;
    }

    /** Everyone who can currently sign in, with their full role list. */
    public function listOrganizers() {
        $rows = $this->db->query(
            "SELECT u.id, u.email, u.role, u.created_at, u.last_login,
                    GROUP_CONCAT(r.role) AS roles_csv
             FROM admin_users u
             LEFT JOIN admin_user_roles r ON r.admin_id = u.id
             GROUP BY u.id
             ORDER BY (u.role = 'owner') DESC, u.email ASC"
        )->fetchAll();

        foreach ($rows as &$row) {
            $csv = $row['roles_csv'] ?? '';
            $row['role_list'] = self::normalizeRoles($csv !== '' ? $csv : $row['role']);
        }
        unset($row);

        return $rows;
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
     * Replace an existing account's roles.
     *
     * $actingAdminId is the owner making the change. Refused, with a reason
     * the caller can explain:
     *   - 'empty'      no valid role was supplied
     *   - 'self'       changing your own roles (avoids locking yourself out)
     *   - 'missing'    the account no longer exists
     *   - 'last_owner' taking the owner role from the last remaining owner
     *
     * Returns ['ok' => bool, 'reason' => string, plus email/old/new on success].
     */
    public function setRoles($id, $roles, $actingAdminId) {
        $id = (int) $id;
        $roles = self::normalizeRoles($roles);

        if (!$roles) {
            return ['ok' => false, 'reason' => 'empty'];
        }
        if ($id === (int) $actingAdminId) {
            return ['ok' => false, 'reason' => 'self'];
        }

        $stmt = $this->db->prepare("SELECT email, role FROM admin_users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();

        if (!$target) {
            return ['ok' => false, 'reason' => 'missing'];
        }

        $old = $this->rolesFor($id, $target['role']);

        if (in_array('owner', $old, true) && !in_array('owner', $roles, true)
            && $this->ownerCount() <= 1) {
            return ['ok' => false, 'reason' => 'last_owner'];
        }

        $this->db->beginTransaction();

        try {
            $this->writeRoles($id, $roles);
            $this->db->prepare("UPDATE admin_users SET role = :role WHERE id = :id")
                ->execute(['role' => $roles[0], 'id' => $id]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['ok' => true, 'reason' => 'updated', 'email' => $target['email'], 'old' => $old, 'new' => $roles];
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

        $roles = $this->rolesFor($id, $target['role']);

        if (in_array('owner', $roles, true) && $this->ownerCount() <= 1) {
            return ['ok' => false, 'reason' => 'last_owner'];
        }

        $this->db->beginTransaction();

        try {
            // Explicit, so removal does not depend on the FK cascade existing.
            $this->db->prepare("DELETE FROM admin_user_roles WHERE admin_id = :id")
                ->execute(['id' => $id]);

            $del = $this->db->prepare("DELETE FROM admin_users WHERE id = :id");
            $del->execute(['id' => $id]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'ok'     => $del->rowCount() === 1,
            'reason' => 'removed',
            'email'  => $target['email'],
            'role'   => implode(', ', $roles),
        ];
    }

    /** An account's roles from admin_user_roles, falling back to its primary role. */
    private function rolesFor($adminId, $primaryRole) {
        $stmt = $this->db->prepare("SELECT role FROM admin_user_roles WHERE admin_id = :id");
        $stmt->execute(['id' => (int) $adminId]);

        $roles = self::normalizeRoles($stmt->fetchAll(PDO::FETCH_COLUMN));
        return $roles ?: self::normalizeRoles($primaryRole);
    }

    /** How many accounts currently hold the owner role. */
    private function ownerCount() {
        return (int) $this->db->query(
            "SELECT COUNT(DISTINCT admin_id) FROM admin_user_roles WHERE role = 'owner'"
        )->fetchColumn();
    }

    /**
     * Make admin_user_roles match $roles exactly for one account. Callers wrap
     * this in their own transaction.
     */
    private function writeRoles($adminId, array $roles) {
        $this->db->prepare("DELETE FROM admin_user_roles WHERE admin_id = :id")
            ->execute(['id' => (int) $adminId]);

        $insert = $this->db->prepare(
            "INSERT INTO admin_user_roles (admin_id, role) VALUES (:id, :role)"
        );
        foreach ($roles as $role) {
            $insert->execute(['id' => (int) $adminId, 'role' => $role]);
        }
    }
}
