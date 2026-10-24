<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/MemberAuthModel.php';
require_once __DIR__ . '/MemberModel.php';
require_once __DIR__ . '/MemberProfileModel.php';
require_once __DIR__ . '/MemberStrikeModel.php';

/**
 * DCW Engage - membership cards (the QR code a member shows).
 *
 * A card is a random 32-character token in the link /card/<token>. The token says nothing about the member;
 * it only points to a row in member_cards. A member has one live card (replaced_at IS NULL). Replacing the
 * card retires the old token, so a printed or screenshotted old code stops working at once. Striking a member
 * off (MemberStrikeModel) retires it too, and lookup() refuses struck members as well.
 *
 * What the public check shows is decided by lookup(): the member's verified Wikimedia username (never their
 * real name), each chapter (with its logo) and whether it is current. Nothing else leaves this class for the
 * public page. A member with no verified username is shown without a name line.
 *
 * Office-bearers: a person listed in OFFICE_BEARERS (matched on the VERIFIED Wikimedia username only) gets an
 * 'office' entry from lookup()/summary(). The public page then shows their role instead of a routine
 * membership check. The role is a statement about the person, so it does not depend on chapter expiry dates.
 * A struck-off member is still refused, office or not.
 *
 * "Current" uses MemberAuthModel::isActive(), the same rule the rest of the app uses: members.status is
 * 'active' and expires_at (a UTC datetime) is in the future. Status 'active' with a past date is "expired".
 *
 * Logos: assets/chapters/<chapter key>.svg and/or .png. A chapter with no file of its own (for now the
 * Photographers club) uses the generic file, the DCW logo. The screen and the public page prefer the SVG
 * (sharp at any size); the PDF prefers the PNG, because mPDF's SVG support is only partial.
 *
 * Tables: member_cards and member_card_hits (member_cards.sql).
 */
class MemberCardModel
{
    /** Public checks allowed per connection per minute. */
    public const RATE_LIMIT = 30;

    /** Where chapter logos live, under the project root (also the public path). */
    private const LOGO_DIR = '/assets/chapters/';

    /**
     * Office-bearers shown with a role statement instead of a routine membership check.
     * Key: verified Wikimedia username, lower-case, spaces as underscores (Wikimedia treats them as the same).
     * Value: 'title' (the headline) and 'detail' (the line under it).
     * To add someone, add a line. Remove a line to return that person to the routine check.
     */
    public const OFFICE_BEARERS = [
        'aafi' => [
            'title'  => 'Founder, Deoband Community Wikimedia',
            'detail' => 'Holds a Permanent voting seat on the DCW Executive Board.',
        ],
        'muntaqibah' => [
            'title'  => 'Member, DCW Executive Board',
            'detail' => 'Holds a voting seat on the DCW Board.',
        ],
        'shadab' => [
            'title'  => 'Member, DCW Executive Board',
            'detail' => 'Holds a voting seat on the DCW Board.',
        ],
    ];

    private PDO $db;
    private MemberStrikeModel $strikes;

    public function __construct(?PDO $db = null)
    {
        $this->db      = $db ?? DB::getInstance()->getConnection();
        $this->strikes = new MemberStrikeModel($this->db);
    }

    // ---- Logos -----------------------------------------------------------------------------------

    /**
     * File name of the logo for a chapter. Tries each extension in order for the chapter's own file first,
     * then for generic. Null if nothing exists.
     */
    private static function logoFile(string $chapter, array $exts): ?string
    {
        $dir = dirname(__DIR__) . self::LOGO_DIR;
        foreach ([$chapter, 'generic'] as $key) {
            if (!preg_match('/^[a-z]+$/', $key)) {
                continue;
            }
            foreach ($exts as $ext) {
                if (is_file($dir . $key . '.' . $ext)) {
                    return $key . '.' . $ext;
                }
            }
        }
        return null;
    }

    /** File name of the logo shown on screen (SVG, else PNG), or null if there is none. */
    public static function logoName(string $chapter): ?string
    {
        return self::logoFile($chapter, ['svg', 'png']);
    }

    /** Public URL of a chapter's logo (with a version so a replaced file shows up), or null. */
    public static function logoUrl(string $chapter): ?string
    {
        $name = self::logoName($chapter);
        if ($name === null) {
            return null;
        }
        $v = @filemtime(dirname(__DIR__) . self::LOGO_DIR . $name);
        return self::LOGO_DIR . $name . ($v ? '?v=' . $v : '');
    }

    /** The logo as a data: URI, for the PDF (PNG preferred, SVG if that is all there is). */
    public static function logoDataUri(string $chapter): ?string
    {
        $name = self::logoFile($chapter, ['png', 'svg']);
        if ($name === null) {
            return null;
        }
        $bytes = @file_get_contents(dirname(__DIR__) . self::LOGO_DIR . $name);
        if ($bytes === false) {
            return null;
        }
        $mime = substr($name, -4) === '.svg' ? 'image/svg+xml' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    // ---- Office-bearers --------------------------------------------------------------------------

    /** A username reduced to the form used as an OFFICE_BEARERS key: lower-case, spaces as underscores. */
    private static function usernameKey(string $username): string
    {
        return mb_strtolower(str_replace(' ', '_', trim($username)), 'UTF-8');
    }

    /**
     * The office held by a person, ['title' => ..., 'detail' => ...], or null for an ordinary member.
     * Pass only a VERIFIED username (summary() does); an empty username never matches.
     */
    public static function officeFor(string $username): ?array
    {
        $key = self::usernameKey($username);
        if ($key === '') {
            return null;
        }
        return self::OFFICE_BEARERS[$key] ?? null;
    }

    // ---- Public page helpers ---------------------------------------------------------------------

    /**
     * Rate limit for the public check page. Counts requests per connection per minute.
     * The address is only stored as a hash that changes every day. If the database cannot be reached
     * this lets the request through (the lookup that follows will report the real problem).
     */
    public static function allow(): bool
    {
        try {
            $db     = DB::getInstance()->getConnection();
            $hash   = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . gmdate('Y-m-d') . '|dcw-engage-card');
            $window = gmdate('Y-m-d H:i:00');

            $db->prepare('INSERT INTO member_card_hits (ip_hash, window_start, hits) VALUES (?, ?, 1)
                          ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$hash, $window]);
            $q = $db->prepare('SELECT hits FROM member_card_hits WHERE ip_hash = ? AND window_start = ?');
            $q->execute([$hash, $window]);
            $hits = (int) $q->fetchColumn();

            if (random_int(1, 100) === 1) {   // tidy up now and then
                $db->prepare('DELETE FROM member_card_hits WHERE window_start < ?')
                   ->execute([gmdate('Y-m-d H:i:s', time() - 3600)]);
            }
            return $hits <= self::RATE_LIMIT;
        } catch (Throwable $e) {
            app_log('Card rate limit failed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * What /card/<token> shows, or null if the token is unknown, was replaced, or the member is struck off
     * (all three look the same to the visitor). Shape: ['overall' => valid|expired|inactive,
     * 'name' => 'Aafi' (the verified Wikimedia username, or '' if there is none),
     * 'office' => ['title', 'detail'] for an office-bearer, or null,
     * 'memberships' => [['name', 'state' => active|expired|inactive, 'date', 'logo']]]
     */
    public function lookup(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $this->db->prepare('SELECT member_id FROM member_cards WHERE token = ? AND replaced_at IS NULL');
        $st->execute([$token]);
        $memberId = $st->fetchColumn();
        if ($memberId === false) {
            return null;
        }
        if ($this->strikes->isStruck((string) $memberId)) {
            return null;
        }
        $s = $this->summary((string) $memberId);
        if ($s === null) {
            return null;
        }
        return [
            'overall'     => $s['overall'],
            'name'        => $s['username'],
            'office'      => $s['office'],
            'memberships' => $s['memberships'],
        ];
    }

    /** The full link a QR code should carry. Set ENGAGE_BASE_URL in config to override the default. */
    public static function url(string $token): string
    {
        $base = defined('ENGAGE_BASE_URL') ? rtrim((string) ENGAGE_BASE_URL, '/') : 'https://engage.dcwwiki.org';
        return $base . '/card/' . $token;
    }

    // ---- The member's own card -------------------------------------------------------------------

    /** The live token for a Member ID, or null if no card has been made yet. */
    public function liveToken(string $memberId): ?string
    {
        $st = $this->db->prepare('SELECT token FROM member_cards WHERE member_id = ? AND replaced_at IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$memberId]);
        $t = $st->fetchColumn();
        return $t === false ? null : (string) $t;
    }

    /** The member's live token, creating the first card if there is none. */
    public function issue(string $memberId): string
    {
        return $this->inTransaction($memberId, function () use ($memberId): string {
            $token = $this->liveToken($memberId);
            if ($token === null) {
                $token = $this->insertCard($memberId);
                AuditLog::activity('member.card_issued', $memberId);
            }
            return $token;
        });
    }

    /** Retire the live card and make a new one. The old QR code stops working immediately. */
    public function replace(string $memberId, string $by): string
    {
        return $this->inTransaction($memberId, function () use ($memberId, $by): string {
            $this->db->prepare('UPDATE member_cards SET replaced_at = ? WHERE member_id = ? AND replaced_at IS NULL')
                     ->execute([gmdate('Y-m-d H:i:s'), $memberId]);
            $token = $this->insertCard($memberId);
            AuditLog::activity('member.card_replaced', $memberId, null, (string) $by);
            return $token;
        });
    }

    /**
     * Everything the member's own card page and PDF need, or null if the Member ID has no usable membership:
     *   member_id, full_name, short_name, overall (valid|expired|inactive),
     *   username (the VERIFIED Wikimedia username, or ''),
     *   office (['title', 'detail'] if the verified username is in OFFICE_BEARERS, else null),
     *   primary     => ['chapter', 'name', 'logo', 'state']   the chapter shown on the card header
     *   memberships => [['chapter', 'name', 'state', 'date', 'logo']]   primary first, then current, expired, other
     *
     * Primary = the earliest membership under the ID that is still current, or the earliest overall if none
     * is (the same "first joined" idea the login uses for the password).
     */
    public function summary(string $memberId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, chapter, status, expires_at, full_name FROM members
             WHERE member_id = ? AND status <> 'rejected' ORDER BY id ASC"
        );
        $st->execute([$memberId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        $list = [];
        foreach ($rows as $r) {
            if (MemberAuthModel::isActive($r)) {
                $state = 'active';
            } elseif ((string) $r['status'] === 'active') {
                $state = 'expired';          // approved, but past its end date
            } else {
                $state = 'inactive';
            }
            $key    = trim((string) $r['chapter']);
            $list[] = [
                'id'      => (int) $r['id'],
                'chapter' => $key,
                'name'    => MemberModel::CHAPTER_NAMES[$key] ?? ($key !== '' ? $key : 'DCW membership'),
                'state'   => $state,
                'date'    => self::istDate((string) ($r['expires_at'] ?? '')),
                'logo'    => self::logoUrl($key),
            ];
        }

        $primary = $list[0];
        foreach ($list as $e) {
            if ($e['state'] === 'active') {
                $primary = $e;
                break;
            }
        }
        $rank = ['active' => 0, 'expired' => 1, 'inactive' => 2];
        $rest = array_values(array_filter($list, fn($e) => $e['id'] !== $primary['id']));
        usort($rest, fn($a, $b) => [$rank[$a['state']], $a['id']] <=> [$rank[$b['state']], $b['id']]);
        $ordered = array_merge([$primary], $rest);

        $states  = array_column($list, 'state');
        $overall = in_array('active', $states, true) ? 'valid' : (in_array('expired', $states, true) ? 'expired' : 'inactive');

        $username = '';
        try {
            $username = (new MemberProfileModel($this->db))->verifiedUsername($memberId);
        } catch (Throwable $e) {
            app_log('Card username read failed: ' . $e->getMessage());
        }

        $full = trim((string) end($rows)['full_name']);
        return [
            'member_id'   => $memberId,
            'full_name'   => $full,
            'short_name'  => self::shortName($full),
            'overall'     => $overall,
            'username'    => $username,
            'office'      => self::officeFor($username),
            'primary'     => ['chapter' => $primary['chapter'], 'name' => $primary['name'], 'logo' => $primary['logo'], 'state' => $primary['state']],
            'memberships' => $ordered,
        ];
    }

    /** "Asha Kumari Singh" -> "Asha S."; one word stays as it is. */
    public static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts) {
            return 'Member';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        return $parts[0] . ' ' . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.';
    }

    /** A stored UTC datetime as a date in India time, e.g. "31 Mar 2027". Empty if there is none. */
    private static function istDate(string $utc): string
    {
        $utc = trim($utc);
        if ($utc === '' || strpos($utc, '0000-00-00') === 0) {
            return '';
        }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(MemberAuthModel::DISPLAY_TZ))->format('j M Y');
        } catch (Throwable $e) {
            return '';
        }
    }

    // ---- Internals -------------------------------------------------------------------------------

    private function insertCard(string $memberId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO member_cards (member_id, token, created_at) VALUES (?, ?, ?)')
                 ->execute([$memberId, $token, gmdate('Y-m-d H:i:s')]);
        return $token;
    }

    /**
     * Run $work with the member's rows locked, so two requests at once cannot make two live cards.
     * Also proves the Member ID exists and is not struck off.
     */
    private function inTransaction(string $memberId, callable $work): string
    {
        $memberId = trim($memberId);
        if ($memberId === '') {
            throw new InvalidArgumentException('Missing Member ID.');
        }
        if ($this->strikes->isStruck($memberId)) {
            throw new InvalidArgumentException('This membership has ended.');
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT id FROM members WHERE member_id = ? AND status <> 'rejected' FOR UPDATE");
            $lock->execute([$memberId]);
            if (!$lock->fetchAll()) {
                throw new InvalidArgumentException('No membership found for this Member ID.');
            }
            $out = $work();
            $this->db->commit();
            return $out;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
