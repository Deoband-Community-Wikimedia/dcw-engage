<?php
require_once __DIR__ . '/MemberModel.php';
require_once __DIR__ . '/MemberStrikeModel.php';
require_once __DIR__ . '/PublicDirectoryCache.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';

/**
 * DCW Engage - the public members directory (/members).
 *
 * Everyone with a verified Wikimedia username is listed, current members and alumni, with their clubs.
 * Left out: struck-off members, and anyone who opted out (table member_directory_optout).
 * A third group, 'legacy', holds people imported once from the old Meta-Wiki list (table legacy_members)
 * who have no verified username on Engage yet. A legacy entry disappears as soon as the same username
 * is on Engage, whether listed, struck off or opted out, so opt-outs and strikes always win.
 * Only Wikimedia usernames and club keys leave this class through build() / cached().
 * No Member ID, email, real name or date.
 *
 * People are merged by username (exact, case-sensitive, underscores read as spaces). If ANY Member ID
 * behind a username is struck off or opted out, the whole username is hidden, so a second ID can
 * never leak it. (member_profile already allows a username on one Member ID only, so this is a backstop.)
 *
 * build() fails CLOSED: if a strike or opt-out lookup fails it throws, and the page shows an error
 * rather than risk listing someone who must not be listed. The small read helpers used by the
 * profile page and the admin page fail soft.
 */
class PublicDirectoryModel
{
    const TTL = 300;   // seconds the public page may be served from cache

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DB::getInstance()->getConnection();
    }

    private static function nameKey(string $username): string
    {
        // Case-sensitive on purpose: Wikimedia treats "Alice" and "ALICE" as different accounts.
        return trim(str_replace('_', ' ', $username));
    }

    private static function normId(string $memberId): string
    {
        return strtoupper(trim($memberId));
    }

    // ---- The public list -------------------------------------------------------------------------

    /**
     * ['current' => [...], 'alumni' => [...]], each entry:
     *   ['name' => 'Example User', 'clubs' => ['amu', 'generic'], 'former' => ['jamia']]
     * For alumni 'clubs' is empty and 'former' lists what they belonged to.
     */
    public function build(): array
    {
        $struck   = (new MemberStrikeModel())->activeIdsStrict();   // throws on failure
        $optedOut = $this->optedOutIds();                           // throws on failure

        $rows = $this->db->query(
            "SELECT m.member_id, m.chapter, m.status, m.expires_at, p.wikimedia_username, p.username_verified_at
               FROM members m
               JOIN member_profile p ON p.member_id = m.member_id
              WHERE p.wikimedia_username IS NOT NULL AND p.wikimedia_username <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Work out which usernames must not appear at all.
        $hiddenNames = [];
        foreach ($rows as $r) {
            $id = self::normId((string) $r['member_id']);
            if (isset($struck[$id]) || isset($optedOut[$id])
                || in_array($r['status'], MemberModel::ID_REMINDER_EXCLUDED_STATUSES, true)) {
                $hiddenNames[self::nameKey((string) $r['wikimedia_username'])] = true;
            }
        }

        $now    = time();
        $people = [];
        foreach ($rows as $r) {
            if (!in_array($r['chapter'], MemberModel::CHAPTERS, true)) continue;
            if (empty($r['username_verified_at'])) continue;   // never list an unverified username
            $name = trim(str_replace('_', ' ', (string) $r['wikimedia_username']));
            $key  = self::nameKey($name);
            if ($name === '' || isset($hiddenNames[$key])) continue;

            $people[$key] ??= ['name' => $name, 'active' => [], 'former' => []];
            // "Current" = marked active AND not past its expiry (nothing flips status when a term lapses).
            $isActive = $r['status'] === 'active' && strtotime((string) $r['expires_at']) > $now;
            $people[$key][$isActive ? 'active' : 'former'][$r['chapter']] = true;
        }

        $ordered = fn(array $set) => array_values(array_intersect(MemberModel::CHAPTERS, array_keys($set)));
        $out = ['current' => [], 'alumni' => []];
        foreach ($people as $p) {
            $active = $ordered($p['active']);
            $former = array_values(array_diff($ordered($p['former']), $active));   // lapsed club, still active elsewhere
            $out[$active ? 'current' : 'alumni'][] = ['name' => $p['name'], 'clubs' => $active, 'former' => $former];
        }

        // Legacy entries: only those not already on Engage (listed, struck off or opted out).
        $legacy = [];
        foreach ($this->legacyRows() as $r) {
            $key = self::nameKey((string) $r['username']);
            if ($key === '' || isset($hiddenNames[$key]) || isset($people[$key])) continue;
            $legacy[$key] ??= ['name' => $key, 'clubs' => []];
            if (in_array($r['chapter'], MemberModel::CHAPTERS, true)) {
                $legacy[$key]['clubs'][$r['chapter']] = true;
            }
        }
        $out['legacy'] = [];
        foreach ($legacy as $l) {
            $out['legacy'][] = ['name' => $l['name'], 'clubs' => $ordered($l['clubs']), 'former' => []];
        }

        foreach ($out as &$list) {
            usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($list);
        return $out;
    }

    // ---- Legacy list (imported once from Meta-Wiki) ----------------------------------------------

    /** Every legacy row [id, username, chapter]. Fails soft: if the table is missing, there are none. */
    private function legacyRows(): array
    {
        try {
            return $this->db->query('SELECT id, username, chapter FROM legacy_members ORDER BY username')
                            ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            app_log('Directory legacy list not read: ' . $e->getMessage());
            return [];
        }
    }

    /** Remove one legacy entry (a takedown request, or someone who is not a member). */
    public function removeLegacy(int $id, string $by): void
    {
        $st = $this->db->prepare('SELECT username FROM legacy_members WHERE id = ?');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false) {
            throw new InvalidArgumentException('That entry no longer exists.');
        }
        // All rows for the same username go together, so a person in two clubs is not left half-listed.
        $this->db->prepare('DELETE FROM legacy_members WHERE username = ?')->execute([$name]);
        PublicDirectoryCache::clear();
        AuditLog::activity('member.directory_legacy_removed', (string) $name, null, $by);
    }

    /**
     * Change one legacy person: rename them and/or set exactly which clubs they show under.
     * $id is any one of their legacy_members rows. Rows are matched by nameKey(), the same way
     * build() merges them. A manual override: it lasts only until the same username appears on Engage.
     *
     * NOTE: the INSERT below sets only username and chapter. If legacy_members has other NOT NULL
     * columns without a default, add them here.
     */
    public function updateLegacy(int $id, string $username, array $chapters, string $by): void
    {
        $new = self::nameKey($username);
        if ($new === '' || mb_strlen($new) > 85) {
            throw new InvalidArgumentException('Enter a valid username.');
        }
        $chapters = array_values(array_unique(array_filter(
            $chapters, fn($c) => is_string($c) && in_array($c, MemberModel::CHAPTERS, true)
        )));
        if (!$chapters) {
            throw new InvalidArgumentException('Pick at least one club, or use Remove.');
        }

        $all = $this->legacyRows();
        $old = null;
        foreach ($all as $r) {
            if ((int) $r['id'] === $id) { $old = self::nameKey((string) $r['username']); break; }
        }
        if ($old === null) {
            throw new InvalidArgumentException('That entry no longer exists.');
        }

        $mine = [];
        foreach ($all as $r) {
            $k = self::nameKey((string) $r['username']);
            if ($k === $old) {
                $mine[] = $r;
            } elseif ($k === $new) {
                throw new InvalidArgumentException('Another entry already uses that username.');
            }
        }

        $before = [];
        $kept   = [];
        foreach ($mine as $r) $before[] = (string) $r['chapter'];

        $this->db->beginTransaction();
        try {
            foreach ($mine as $r) {
                $ch = (string) $r['chapter'];
                if (in_array($ch, $chapters, true) && !isset($kept[$ch])) {
                    $kept[$ch] = true;   // keep this row, just fix the name
                    $this->db->prepare('UPDATE legacy_members SET username = ? WHERE id = ?')
                             ->execute([$new, (int) $r['id']]);
                } else {                 // unticked club, duplicate, or an unknown chapter value
                    $this->db->prepare('DELETE FROM legacy_members WHERE id = ?')->execute([(int) $r['id']]);
                }
            }
            foreach ($chapters as $ch) {
                if (!isset($kept[$ch])) {
                    $this->db->prepare('INSERT INTO legacy_members (username, chapter) VALUES (?, ?)')
                             ->execute([$new, $ch]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        PublicDirectoryCache::clear();
        AuditLog::activity(
            'member.directory_legacy_updated',
            $new,
            implode(',', $before) . ' -> ' . implode(',', $chapters) . ($new !== $old ? " (was $old)" : ''),
            $by
        );
    }

    /** build() behind a short file cache. Throws if it has to build and the build fails. */
    public function cached(): array
    {
        $hit = PublicDirectoryCache::read(self::TTL);
        if ($hit !== null) return $hit;
        $d = $this->build();
        PublicDirectoryCache::write($d);
        return $d;
    }

    // ---- Opting out ------------------------------------------------------------------------------

    /** [MEMBER ID => true] for everyone who opted out. THROWS on failure (used by build()). */
    private function optedOutIds(): array
    {
        $ids = $this->db->query('SELECT member_id FROM member_directory_optout')->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys(array_map(fn($i) => self::normId((string) $i), $ids), true);
    }

    /** true = listed, false = opted out, null = could not tell (e.g. the SQL has not been run yet). */
    public function listingState(string $memberId): ?bool
    {
        try {
            $st = $this->db->prepare('SELECT 1 FROM member_directory_optout WHERE member_id = ? LIMIT 1');
            $st->execute([self::normId($memberId)]);
            return $st->fetchColumn() === false;
        } catch (Throwable $e) {
            app_log('Directory listing state failed: ' . $e->getMessage());
            return null;
        }
    }

    /** List (true) or unlist (false) a Member ID. Takes effect on /members straight away. */
    public function setListed(string $memberId, bool $listed, string $by): void
    {
        $id = self::normId($memberId);
        if ($id === '') {
            throw new InvalidArgumentException('Missing Member ID.');
        }
        if ($listed) {
            $this->db->prepare('DELETE FROM member_directory_optout WHERE member_id = ?')->execute([$id]);
        } else {
            $this->db->prepare(
                'INSERT INTO member_directory_optout (member_id, hidden_at, hidden_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE hidden_at = VALUES(hidden_at), hidden_by = VALUES(hidden_by)'
            )->execute([$id, gmdate('Y-m-d H:i:s'), $by]);
        }
        PublicDirectoryCache::clear();
        AuditLog::activity('member.directory_listing', $id, $listed ? 'listed' : 'unlisted', $by);
    }

    // ---- For organisers --------------------------------------------------------------------------

    /**
     * Numbers and the opt-out list for the admin page. Fails soft: a problem in one part leaves that
     * part empty and is logged.
     * ['current' => int|null, 'alumni' => int|null, 'optedOut' => [rows], 'noUsername' => int|null]
     */
    public function overview(): array
    {
        $o = ['current' => null, 'alumni' => null, 'legacyShown' => null, 'legacy' => [], 'optedOut' => [], 'noUsername' => null];

        try {
            $d = $this->build();
            $o['current'] = count($d['current']);
            $o['alumni']  = count($d['alumni']);
            $o['legacyShown'] = count($d['legacy']);
            $shown = [];
            foreach ($d['legacy'] as $l) $shown[$l['name']] = true;
            foreach ($this->legacyRows() as $r) {
                $r['shown'] = isset($shown[self::nameKey((string) $r['username'])]);
                $o['legacy'][] = $r;
            }
        } catch (Throwable $e) {
            app_log('Directory overview build failed: ' . $e->getMessage());
        }

        try {
            $o['optedOut'] = $this->db->query(
                "SELECT o.member_id, o.hidden_at,
                        (SELECT m.full_name FROM members m WHERE m.member_id = o.member_id ORDER BY m.id DESC LIMIT 1) AS full_name,
                        (SELECT p.wikimedia_username FROM member_profile p WHERE p.member_id = o.member_id LIMIT 1) AS username
                   FROM member_directory_optout o ORDER BY o.hidden_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            app_log('Directory opt-out list failed: ' . $e->getMessage());
        }

        try {
            $o['noUsername'] = (int) $this->db->query(
                "SELECT COUNT(DISTINCT m.member_id) FROM members m
                  LEFT JOIN member_profile p ON p.member_id = m.member_id
                        AND p.username_verified_at IS NOT NULL AND p.wikimedia_username <> ''
                  WHERE p.member_id IS NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            app_log('Directory no-username count failed: ' . $e->getMessage());
        }

        return $o;
    }
}
