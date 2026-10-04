<?php
/**
 * DCW Engage - Member login (Member ID + password).
 *
 * Members sign in with the Member ID they were given on approval (e.g. A48213977) and a
 * password they choose themselves. Nobody is ever given a password: after approval the member
 * gets a one-time link to set one, and the same kind of link handles "forgot password".
 *
 * Needs sql/member_login.sql (extra columns on members + member_password_tokens).
 * This class only deals with credentials; sessions, pages and CSRF belong in the views.
 */
class MemberAuthModel {
    const MIN_PASSWORD_LENGTH = 10;
    const MAX_PASSWORD_BYTES  = 72;     // bcrypt ignores everything after 72 bytes, so refuse longer ones
    const MAX_FAILED_LOGINS   = 5;      // wrong passwords in a row before the account is locked
    const LOCK_MINUTES        = 15;
    const RESET_COOLDOWN_SECONDS = 120; // at most one link email per member in this time
    const TOKEN_TTL = ['set' => '+7 days', 'reset' => '+1 hour'];

    /**
     * Time zones. Every date and time stored in the database is UTC (the server runs on UTC, and these
     * helpers use UTC explicitly, so the code does not depend on PHP's default time zone).
     * Anything shown to a person is converted to Indian Standard Time (UTC+5:30, no daylight saving)
     * with formatIst().
     */
    const DISPLAY_TZ = 'Asia/Kolkata';

    private $db;

    public function __construct() { $this->db = DB::getInstance()->getConnection(); }

    // ---- time: stored as UTC, shown in IST -----------------------------------------------------------

    /** Current time as a UTC datetime string for the database. */
    private static function utcNow(): string {
        return gmdate('Y-m-d H:i:s');
    }

    /** UTC datetime string for a moment relative to now, e.g. '+15 minutes' or '-120 seconds'. */
    private static function utcIn(string $relative): string {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($relative)->format('Y-m-d H:i:s');
    }

    /** Unix timestamp for a UTC datetime string from the database. */
    private static function utcTs(string $utc): int {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * A stored UTC datetime shown in Indian Standard Time, e.g. formatIst('2026-10-04 10:15:00')
     * gives '4 Oct 2026, 3:45 PM IST'. Pass another PHP date format to change the layout.
     */
    public static function formatIst(?string $utc, string $format = 'j M Y, g:i A'): string {
        if ($utc === null || $utc === '') return '';
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::DISPLAY_TZ))
            ->format($format) . ' IST';
    }

    /** Member IDs are typed by hand: ignore case and stray spaces. */
    public static function normaliseId(string $id): string {
        return strtoupper(trim($id));
    }

    /** Null when the password is acceptable, otherwise a message that is safe to show. */
    public static function passwordError(string $password, ?string $memberId = null): ?string {
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return 'That password is too long. Please use ' . self::MAX_PASSWORD_BYTES . ' characters or fewer.';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Please use at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if ($memberId !== null && strcasecmp($password, $memberId) === 0) {
            return 'Your password cannot be the same as your Member ID.';
        }
        return null;
    }

    public function findByMemberId(string $memberId): ?array {
        $st = $this->db->prepare('SELECT * FROM members WHERE member_id = :m');
        $st->execute(['m' => self::normaliseId($memberId)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Active = approved and not past the expiry date. Use this for anything members get access to. */
    public static function isActive(array $member): bool {
        return ($member['status'] ?? '') === 'active' && self::utcTs((string) $member['expires_at']) > time();
    }

    public static function hasPassword(array $member): bool {
        return !empty($member['password_hash']);
    }

    /**
     * Check a Member ID and password.
     * Returns ['status' => 'ok' | 'invalid' | 'locked', 'member' => row or null]; 'locked' also has
     * 'until', the time the lock ends in IST (e.g. '3:45 PM IST').
     * 'invalid' covers an unknown ID, a member who has not set a password yet and a wrong password,
     * so the page can show one message for all of them and nobody can use it to find valid IDs.
     * The caller starts the session (and regenerates its id) on 'ok'.
     */
    public function authenticate(string $memberId, string $password): array {
        $m = $this->findByMemberId($memberId);

        if ($m && !empty($m['locked_until']) && self::utcTs($m['locked_until']) > time()) {
            // 'until' is the time the lock ends, in IST, ready to show on the login page.
            return ['status' => 'locked', 'member' => null, 'until' => self::formatIst($m['locked_until'], 'g:i A')];
        }

        if ($m && self::hasPassword($m)) {
            $ok = password_verify($password, $m['password_hash']);
        } else {
            // Unknown ID or no password yet: do about the same amount of work, so the
            // response time does not give away whether the Member ID exists.
            password_hash($password, PASSWORD_DEFAULT);
            $ok = false;
        }

        if (!$ok) {
            if ($m) $this->recordFailure($m);
            return ['status' => 'invalid', 'member' => null];
        }

        $now = self::utcNow();
        $sql = 'UPDATE members SET failed_logins = 0, locked_until = NULL, last_login_at = :n';
        $args = ['n' => $now, 'id' => $m['id']];
        if (password_needs_rehash($m['password_hash'], PASSWORD_DEFAULT)) {   // the algorithm default may have moved on
            $sql .= ', password_hash = :h';
            $args['h'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db->prepare($sql . ' WHERE id = :id')->execute($args);

        return ['status' => 'ok', 'member' => $m];
    }

    private function recordFailure(array $m): void {
        $this->db->prepare('UPDATE members SET failed_logins = failed_logins + 1 WHERE id = :id')
            ->execute(['id' => $m['id']]);
        $st = $this->db->prepare('SELECT failed_logins FROM members WHERE id = :id');
        $st->execute(['id' => $m['id']]);
        if ((int) $st->fetchColumn() >= self::MAX_FAILED_LOGINS) {
            $until = self::utcIn('+' . self::LOCK_MINUTES . ' minutes');
            $this->db->prepare('UPDATE members SET locked_until = :u, failed_logins = 0 WHERE id = :id')
                ->execute(['u' => $until, 'id' => $m['id']]);
        }
    }

    // ---- one-time links: set a first password, or reset a forgotten one --------------------------

    /**
     * New one-time token for a member. $purpose: 'set' | 'reset'. Returns the raw token, which goes
     * into the emailed link and is never stored (only its SHA-256 hash is). Earlier unused
     * tokens for the same member stop working.
     */
    public function createToken(int $memberRowId, string $purpose): string {
        if (!isset(self::TOKEN_TTL[$purpose])) throw new InvalidArgumentException('Unknown token purpose.');
        $now = self::utcNow();

        $this->db->prepare('UPDATE member_password_tokens SET used_at = :n WHERE member_row_id = :m AND used_at IS NULL')
            ->execute(['n' => $now, 'm' => $memberRowId]);

        $raw = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO member_password_tokens (member_row_id, token_hash, purpose, expires_at, created_at)
            VALUES (:m, :h, :p, :x, :c)')
            ->execute(['m' => $memberRowId, 'h' => hash('sha256', $raw), 'p' => $purpose,
                       'x' => self::utcIn(self::TOKEN_TTL[$purpose]), 'c' => $now]);
        return $raw;
    }

    /**
     * "Forgot password" / first-time set from the login page. The member must give BOTH their Member ID
     * and the email on file. Returns ['member', 'purpose', 'token'] when a link should be emailed, or
     * null when nothing should be sent (no match, or a link was sent a moment ago). The page shows
     * the same "if those details match, we have emailed a link" message either way.
     */
    public function requestLink(string $memberId, string $email): ?array {
        $m = $this->findByMemberId($memberId);
        if (!$m || strcasecmp((string) $m['email'], trim($email)) !== 0) return null;

        $st = $this->db->prepare('SELECT COUNT(*) FROM member_password_tokens WHERE member_row_id = :m AND created_at > :t');
        $st->execute(['m' => $m['id'], 't' => self::utcIn('-' . self::RESET_COOLDOWN_SECONDS . ' seconds')]);
        if ((int) $st->fetchColumn() > 0) return null;

        $purpose = self::hasPassword($m) ? 'reset' : 'set';
        return ['member' => $m, 'purpose' => $purpose, 'token' => $this->createToken((int) $m['id'], $purpose)];
    }

    /**
     * Called right after an approval: if the member has no password yet, make a 'set' link for them
     * and return ['member' => row, 'token' => raw token] to email. Returns null when they already have
     * a password (a renewal), so renewing members are not asked to set one again.
     */
    public function setLinkIfNeeded(string $memberId): ?array {
        $m = $this->findByMemberId($memberId);
        if (!$m || self::hasPassword($m)) return null;
        return ['member' => $m, 'token' => $this->createToken((int) $m['id'], 'set')];
    }

    /** Token row (with the member's id, email, name and chapter) if it is unused and not expired, else null. */
    public function findValidToken(string $rawToken): ?array {
        $st = $this->db->prepare('SELECT t.id AS token_id, t.purpose, t.member_row_id,
                   m.member_id, m.email, m.full_name, m.chapter
            FROM member_password_tokens t JOIN members m ON m.id = t.member_row_id
            WHERE t.token_hash = :h AND t.used_at IS NULL AND t.expires_at > :n');
        $st->execute(['h' => hash('sha256', $rawToken), 'n' => self::utcNow()]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Set the password using a one-time link. The link works once. Clears any lock and failed-login
     * count, and cancels the member's other unused links. Throws InvalidArgumentException with a
     * message that is safe to show. Returns the token row (member details) on success.
     */
    public function setPassword(string $rawToken, string $password): array {
        $t = $this->findValidToken($rawToken);
        if (!$t) throw new InvalidArgumentException('This link is no longer valid. Please request a new one.');

        $err = self::passwordError($password, $t['member_id']);
        if ($err) throw new InvalidArgumentException($err);

        $now = self::utcNow();
        $this->db->beginTransaction();
        try {
            // Claim the token first, so two simultaneous uses cannot both succeed.
            $claim = $this->db->prepare('UPDATE member_password_tokens SET used_at = :n WHERE id = :id AND used_at IS NULL');
            $claim->execute(['n' => $now, 'id' => $t['token_id']]);
            if ($claim->rowCount() !== 1) throw new InvalidArgumentException('This link is no longer valid. Please request a new one.');

            $this->db->prepare('UPDATE members SET password_hash = :h, password_set_at = :n, failed_logins = 0, locked_until = NULL WHERE id = :id')
                ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'n' => $now, 'id' => $t['member_row_id']]);
            $this->db->prepare('UPDATE member_password_tokens SET used_at = :n WHERE member_row_id = :m AND used_at IS NULL')
                ->execute(['n' => $now, 'm' => $t['member_row_id']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }

        return $t;
    }
}
