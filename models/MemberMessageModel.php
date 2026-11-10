<?php
/**
 * DCW Engage - lookups for "Email a member" (/admin/email-member).
 *
 * Kept apart from MemberModel on purpose: that class is about membership review, and nothing here changes
 * a member. Three jobs:
 *   search()          find people by name, Member ID or Wikimedia username. Never returns an email address,
 *                     so staff pick a person without ever seeing the address.
 *   recipientFor()    the address and name to send to, looked up on the server from the Member ID.
 *   recentSendCount() how many emails one staff account has sent lately (the hourly limit).
 *
 * Matching is done in PHP, like MemberModel::filterBySearch(), so a collation problem in another table can
 * never break the page. "_" and " " are treated as the same, as Wikimedia does.
 *
 * One Member ID can have several rows in `members` (one per chapter). The address used is the Generic
 * Community row's, then the oldest row, the same rule as MemberModel::accountForEmail().
 */
require_once __DIR__ . '/MemberStrikeModel.php';

class MemberMessageModel {
    /** Audit action written for every email that really went out. The hourly limit counts these rows. */
    const AUDIT_ACTION = 'member.email_sent';

    private $db;

    public function __construct() { $this->db = DB::getInstance()->getConnection(); }

    /** True when the text looks like a Member ID: one letter and 8 digits, e.g. A48213977. */
    public static function isMemberId(string $id): bool {
        return (bool) preg_match('/^[A-Z]\d{8}$/', $id);
    }

    /**
     * People matching the text, best 15 by name. Each result:
     *   member_id, name, chapters (list of chapter keys), active (bool), username, struck (bool)
     * Needs at least 2 characters. No email address is returned.
     */
    public function search(string $q, int $limit = 15): array {
        $norm = fn($s) => str_replace('_', ' ', mb_strtolower(trim((string) $s)));
        $needle = $norm($q);
        if (mb_strlen($needle) < 2) return [];

        $people = [];
        $rows = $this->db->query("SELECT member_id, full_name, chapter, status, expires_at FROM members
                                   ORDER BY (chapter = 'generic') DESC, id ASC");
        foreach ($rows as $r) {
            $id = (string) $r['member_id'];
            if (!isset($people[$id])) {
                $people[$id] = ['member_id' => $id, 'name' => (string) $r['full_name'],
                                'chapters' => [], 'active' => false, 'username' => ''];
            }
            $people[$id]['chapters'][] = (string) $r['chapter'];
            if ($r['status'] === 'active' && strtotime((string) $r['expires_at']) > time()) {
                $people[$id]['active'] = true;
            }
        }

        // Wikimedia usernames from the profiles. Optional: if this lookup fails, name and ID still search.
        try {
            foreach ($this->db->query('SELECT member_id, wikimedia_username FROM member_profile') as $r) {
                $id = (string) $r['member_id'];
                if (isset($people[$id])) $people[$id]['username'] = (string) $r['wikimedia_username'];
            }
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('Email a member: profile usernames not searched: ' . $e->getMessage());
        }

        $hits = array_values(array_filter($people, function (array $p) use ($needle, $norm): bool {
            foreach ([$p['name'], $p['member_id'], $p['username']] as $s) {
                if ($s !== '' && mb_strpos($norm($s), $needle) !== false) return true;
            }
            return false;
        }));
        usort($hits, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $hits = array_slice($hits, 0, max(1, $limit));

        $strikes = new MemberStrikeModel();
        foreach ($hits as &$h) $h['struck'] = $strikes->isStruck($h['member_id']);
        unset($h);

        return $hits;
    }

    /**
     * Who to write to, for one Member ID, or null when no member has it:
     *   member_id, name, email, struck (bool)
     * The address comes from here and never from the request.
     */
    public function recipientFor(string $memberId): ?array {
        $memberId = strtoupper(trim($memberId));
        if (!self::isMemberId($memberId)) return null;

        $st = $this->db->prepare("SELECT member_id, full_name, email FROM members
                                   WHERE member_id = :m ORDER BY (chapter = 'generic') DESC, id ASC LIMIT 1");
        $st->execute(['m' => $memberId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        return [
            'member_id' => (string) $r['member_id'],
            'name'      => (string) $r['full_name'],
            'email'     => (string) $r['email'],
            'struck'    => (new MemberStrikeModel())->isStruck((string) $r['member_id']),
        ];
    }

    /**
     * Emails this staff account has sent in the last $minutes, counted from the audit log. A soft limit: audit
     * writes are best-effort, so this is a brake on a runaway or misused account, not an exact meter.
     */
    public function recentSendCount(string $actorEmail, int $minutes = 60): int {
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_log
                                   WHERE action = :a AND actor_email = :e
                                     AND created_at > (NOW() - INTERVAL " . max(1, (int) $minutes) . " MINUTE)");
        $st->execute(['a' => self::AUDIT_ACTION, 'e' => $actorEmail]);
        return (int) $st->fetchColumn();
    }
}
