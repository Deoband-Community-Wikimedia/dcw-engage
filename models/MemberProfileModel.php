<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/wiki_username.php';

/**
 * Member profile, keyed by Member ID (one person, however many clubs they belong to).
 *
 * Today it holds the Wikimedia username and whether a reviewer or coordinator has verified it.
 * Every change is written to app_log with the old and new value and who made it.
 *
 * Rules:
 *  - A username belongs to one Member ID only (unique on the lower-cased name).
 *  - Changing the username to a different account clears verification.
 *    Fixing only capitalisation or underscores keeps it.
 *  - Who may do what is decided by the pages (member: own profile; staff: chapter scope).
 *    This model only stores.
 */
class MemberProfileModel
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DB::getInstance()->getConnection();
    }

    // ---- Reading -------------------------------------------------------------------------------

    public function get(string $memberId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM member_profile WHERE member_id = ?');
        $st->execute([$memberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Profiles for many Member IDs in one query: [member_id => row]. */
    public function mapFor(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('strval', $memberIds), 'strlen')));
        if (!$memberIds) return [];
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $st = $this->db->prepare("SELECT * FROM member_profile WHERE member_id IN ($in)");
        $st->execute($memberIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['member_id']] = $r;
        return $out;
    }

    /** The verified username for a Member ID, or '' if there is none. */
    public function verifiedUsername(string $memberId): string
    {
        $p = $this->get($memberId);
        return ($p && !empty($p['wikimedia_username']) && !empty($p['username_verified_at']))
            ? (string) $p['wikimedia_username'] : '';
    }

    // ---- Writing -------------------------------------------------------------------------------

    /** A member editing their own username. Verification is never granted here. */
    public function saveByMember(string $memberId, string $raw, string $by): void
    {
        $this->store($memberId, $raw, $by, false);
    }

    /** Staff editing a username, optionally verifying it in the same step. */
    public function saveByStaff(string $memberId, string $raw, string $by, bool $verify): void
    {
        $this->store($memberId, $raw, $by, $verify);
    }

    /** Mark the current username verified, or remove verification. */
    public function setVerified(string $memberId, string $by, bool $verified): void
    {
        $p = $this->get($memberId);
        if (!$p || empty($p['wikimedia_username'])) {
            throw new InvalidArgumentException('There is no Wikimedia username on this profile yet.');
        }
        $st = $this->db->prepare(
            'UPDATE member_profile SET username_verified_at = ?, username_verified_by = ?, updated_by = ? WHERE member_id = ?'
        );
        $st->execute([$verified ? gmdate('Y-m-d H:i:s') : null, $verified ? $by : null, $by, $memberId]);
        AuditLog::activity('member.username_' . ($verified ? 'verified' : 'unverified'), $memberId, (string) $p['wikimedia_username'], (string) $by);
    }

    public const CARD_TITLE_MAX = 120;

    /**
     * Staff only: the title printed on the card instead of the Member ID (for example for a founder or chair).
     * An empty title removes it, and the card shows the Member ID again. Creates the profile row if there is none.
     */
    public function setCardTitle(string $memberId, string $title, string $by): void
    {
        $memberId = trim($memberId);
        if ($memberId === '') throw new InvalidArgumentException('Missing Member ID.');

        $title = strip_tags(preg_replace('/[\x00-\x1F]/u', ' ', $title));
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if (mb_strlen($title) > self::CARD_TITLE_MAX) {
            throw new InvalidArgumentException('Please keep the card title to ' . self::CARD_TITLE_MAX . ' characters or fewer.');
        }

        $old = $this->get($memberId);
        $st = $this->db->prepare(
            'INSERT INTO member_profile (member_id, card_title, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE card_title = VALUES(card_title), updated_by = VALUES(updated_by)'
        );
        $st->execute([$memberId, $title !== '' ? $title : null, $by]);
        AuditLog::activity('member.card_title', $memberId, "'" . ($old['card_title'] ?? '') . "' -> '$title'", (string) $by);
    }

    private function store(string $memberId, string $raw, string $by, bool $verify): void
    {
        $memberId = trim($memberId);
        if ($memberId === '') throw new InvalidArgumentException('Missing Member ID.');

        $name = self::clean($raw);
        $key  = self::usernameKey($name);

        $st = $this->db->prepare('SELECT member_id FROM member_profile WHERE username_key = ? AND member_id <> ?');
        $st->execute([$key, $memberId]);
        if ($st->fetchColumn()) throw new InvalidArgumentException(self::TAKEN);

        $old = $this->get($memberId);
        $sameAccount = $old && ($old['username_key'] ?? '') === $key;

        if ($verify) {
            $vAt = gmdate('Y-m-d H:i:s'); $vBy = $by;
        } elseif ($sameAccount) {
            $vAt = $old['username_verified_at']; $vBy = $old['username_verified_by'];
        } else {
            $vAt = null; $vBy = null;   // new or different account: needs verifying again
        }

        try {
            if ($old) {
                $up = $this->db->prepare(
                    'UPDATE member_profile SET wikimedia_username = ?, username_key = ?, username_verified_at = ?,
                            username_verified_by = ?, updated_by = ? WHERE member_id = ?'
                );
                $up->execute([$name, $key, $vAt, $vBy, $by, $memberId]);
            } else {
                $in = $this->db->prepare(
                    'INSERT INTO member_profile (member_id, wikimedia_username, username_key, username_verified_at,
                            username_verified_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $in->execute([$memberId, $name, $key, $vAt, $vBy, $by]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new InvalidArgumentException(self::TAKEN);   // lost a race
            throw $e;
        }

        AuditLog::activity('member.username_changed', $memberId,
            "'" . ($old['wikimedia_username'] ?? '') . "' -> '$name'" . ($verify ? ' (verified)' : ''), (string) $by);
    }

    // ---- About you: bio, languages, contribution areas ----------------------------------------

    public const BIO_MAX = 280;
    public const LANGUAGES_MAX = 10;

    /** Fixed list of contribution areas: key => label. Only these keys are ever stored. */
    public const AREAS = [
        'wikipedia'   => 'Wikipedia',
        'commons'     => 'Wikimedia Commons',
        'wikidata'    => 'Wikidata',
        'wikisource'  => 'Wikisource',
        'translation' => 'Translation',
        'technical'   => 'Technical work',
        'events'      => 'Events and outreach',
        'other'       => 'Other',
    ];

    /**
     * Save the optional "About you" fields. Creates the profile row if there is none yet.
     * $languages is free text (commas, semicolons or new lines between languages).
     * $areas is a list of AREAS keys; anything else is dropped.
     */
    public function saveAbout(string $memberId, string $bio, string $languages, array $areas, string $by): void
    {
        $memberId = trim($memberId);
        if ($memberId === '') throw new InvalidArgumentException('Missing Member ID.');

        $bio = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', str_replace("\r", '', $bio)));
        $bio = preg_replace("/\n{3,}/", "\n\n", $bio);
        if (mb_strlen($bio) > self::BIO_MAX) {
            throw new InvalidArgumentException('Please keep your bio to ' . self::BIO_MAX . ' characters or fewer.');
        }

        $langs = [];
        foreach (preg_split('/[,;\n]+/u', $languages) ?: [] as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', strip_tags($l)));
            if ($l === '') continue;
            if (mb_strlen($l) > 40) throw new InvalidArgumentException('A language name is too long. Please list them separated by commas.');
            $langs[mb_strtolower($l)] = $l;   // de-duplicate, ignoring case
        }
        if (count($langs) > self::LANGUAGES_MAX) {
            throw new InvalidArgumentException('Please list at most ' . self::LANGUAGES_MAX . ' languages.');
        }

        $areaKeys = array_values(array_intersect(array_keys(self::AREAS), array_map('strval', $areas)));

        $st = $this->db->prepare(
            'INSERT INTO member_profile (member_id, bio, languages, contribution_areas, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE bio = VALUES(bio), languages = VALUES(languages),
                    contribution_areas = VALUES(contribution_areas), updated_by = VALUES(updated_by)'
        );
        $st->execute([
            $memberId,
            $bio !== '' ? $bio : null,
            $langs ? implode(', ', $langs) : null,
            $areaKeys ? implode(',', $areaKeys) : null,
            $by,
        ]);
        AuditLog::activity('member.profile_saved', $memberId, null, (string) $by);
    }

    /** Languages of a profile row, as a list. */
    public static function languagesOf(?array $row): array
    {
        $v = trim((string) ($row['languages'] ?? ''));
        return $v === '' ? [] : array_map('trim', explode(',', $v));
    }

    /** Contribution-area keys of a profile row, as a list (only keys that still exist in AREAS). */
    public static function areasOf(?array $row): array
    {
        $v = trim((string) ($row['contribution_areas'] ?? ''));
        return $v === '' ? [] : array_values(array_intersect(array_keys(self::AREAS), explode(',', $v)));
    }

    private const TAKEN = 'That Wikimedia username is already linked to another member. If it is yours, please contact DCW Support.';

    // ---- Helpers -------------------------------------------------------------------------------

    /** Normalise and validate a typed username. Throws InvalidArgumentException with a plain message. */
    public static function clean(string $raw): string
    {
        $u = trim(WikiUsername::normalize($raw));
        if ($u === '') throw new InvalidArgumentException('The username cannot be empty.');
        if (mb_strlen($u) > 255) throw new InvalidArgumentException('That username is too long.');
        if (preg_match('/^(user|user talk)\s*:/i', $u)) {
            throw new InvalidArgumentException('Enter the username only, without "User:".');
        }
        if (preg_match('/[#<>\[\]|{}\/\x00-\x1F]/u', $u)) {
            throw new InvalidArgumentException('A Wikimedia username cannot contain # < > [ ] | { } / characters.');
        }
        return $u;
    }

    /**
     * What uniqueness is checked on: the normalised name, exactly as Wikimedia identifies the account
     * (first letter capitalised, underscores as spaces). Case and accents after the first letter
     * matter, because "Alice" and "ALICE" are different accounts. The column is utf8mb4_bin.
     */
    public static function usernameKey(string $u): string
    {
        return WikiUsername::normalize($u);
    }

    private static function slug(string $u): string
    {
        return rawurlencode(str_replace(' ', '_', $u));
    }

    /** Global account page (home wiki, registration, wikis with edits, blocks). */
    public static function globalUrl(string $u): string
    {
        return 'https://meta.wikimedia.org/wiki/Special:CentralAuth/' . self::slug($u);
    }

    /** Contributions across all Wikimedia projects (XTools). */
    public static function contribsUrl(string $u): string
    {
        return 'https://xtools.wmcloud.org/globalcontribs/' . self::slug($u);
    }
}
