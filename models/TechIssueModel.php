<?php
/**
 * DCW Engage - technical issues.
 * Members and team people report problems; the technical team (and owners) work them.
 *
 * Privacy: a member report stores the Member ID only to match it to its owner. The technical
 * team is shown just "Member" (reporter_label), never an ID, email, name or chapter, and
 * replies reach the member through their own dashboard thread.
 */
class TechIssueModel {
    /** Roles that can work the issue queue and run diagnostics. */
    const STAFF_ROLES = ['technical_manager', 'owner'];

    const CATEGORIES = [
        'login'   => 'Signing in',
        'forms'   => 'Forms and applications',
        'support' => 'Support requests',
        'email'   => 'Emails',
        'display' => 'Page looks or works wrong',
        'other'   => 'Something else',
    ];

    const SEVERITIES = [
        'low'    => 'Minor',
        'normal' => 'Gets in the way',
        'high'   => 'Blocks me completely',
    ];

    const STATUSES = ['Open', 'Investigating', 'Waiting on reporter', 'Resolved'];

    /** Reports one person may send per hour. Stops a stuck form or a bad actor flooding the queue. */
    const MAX_PER_HOUR = 5;

    private $db;

    public function __construct() { $this->db = DB::getInstance()->getConnection(); }

    /** @return array [cleanData, errorMessageOrNull] */
    public static function validate(array $in): array {
        $category = (string) ($in['category'] ?? '');
        $severity = (string) ($in['severity'] ?? '');
        $title    = trim((string) ($in['title'] ?? ''));
        $body     = trim((string) ($in['description'] ?? ''));
        $page     = trim((string) ($in['page_url'] ?? ''));

        if (!isset(self::CATEGORIES[$category])) return [[], 'Choose what the problem is about.'];
        if (!isset(self::SEVERITIES[$severity])) return [[], 'Choose how badly it affects you.'];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) return [[], 'Give the problem a short title (3 to 160 characters).'];
        if (mb_strlen($body) < 10)   return [[], 'Describe what happened in a few words, so the technical team can reproduce it.'];
        if (mb_strlen($body) > 4000) return [[], 'The description is too long. Keep it under 4000 characters.'];

        return [[
            'category' => $category, 'severity' => $severity, 'title' => $title,
            'description' => $body, 'page_url' => mb_substr($page, 0, 255),
        ], null];
    }

    // ---- Creating and replying ----------------------------------------------------------------

    public function create(string $type, string $ref, string $label, array $d): string {
        if ($this->recentCount($type, $ref) >= self::MAX_PER_HOUR) {
            throw new RuntimeException('You have sent several reports in the last hour. Please wait a while, or reply on one you already sent.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        for ($try = 1; $try <= 5; $try++) {
            $tracking = 'TI-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->db->beginTransaction();
                $this->db->prepare('INSERT INTO tech_issues
                    (tracking_id, reporter_type, reporter_ref, reporter_label, category, severity, title, page_url, user_agent,
                     status, last_sender, created_at, updated_at)
                    VALUES (:t, :rt, :rr, :rl, :c, :s, :ti, :p, :ua, \'Open\', \'reporter\', :n, :n2)')
                    ->execute(['t' => $tracking, 'rt' => $type, 'rr' => $ref, 'rl' => $label, 'c' => $d['category'],
                               's' => $d['severity'], 'ti' => $d['title'], 'p' => $d['page_url'] ?: null,
                               'ua' => $ua ?: null, 'n' => $now, 'n2' => $now]);
                $id = (int) $this->db->lastInsertId();
                $this->insertMessage($id, 'reporter', $d['description'], null, false, $now);
                $this->db->commit();
                return $tracking;
            } catch (PDOException $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $dup = (int) ($e->errorInfo[1] ?? 0) === 1062;
                if (!$dup || $try === 5) throw $e;
            }
        }
        throw new RuntimeException('Could not create the report.');
    }

    /** The reporter adds a message. A resolved report reopens; a "waiting on you" one goes back to the team. */
    public function reporterReply(array $issue, string $body): void {
        $now = gmdate('Y-m-d H:i:s');
        $status = $issue['status'];
        if ($status === 'Resolved') $status = 'Open';
        elseif ($status === 'Waiting on reporter') $status = 'Investigating';

        $this->db->beginTransaction();
        try {
            $this->insertMessage((int) $issue['id'], 'reporter', $body, null, false, $now);
            $this->db->prepare("UPDATE tech_issues SET status = :s, last_sender = 'reporter', updated_at = :n WHERE id = :id")
                ->execute(['s' => $status, 'n' => $now, 'id' => $issue['id']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** The technical team replies or leaves an internal note, and may change the status in the same step. */
    public function staffReply(array $issue, string $body, bool $internal, string $status, string $byEmail): void {
        if (!in_array($status, self::STATUSES, true)) $status = $issue['status'];
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->insertMessage((int) $issue['id'], 'tech', $body, $byEmail, $internal, $now);
            if ($internal) {
                // An internal note never changes who spoke last, so the reporter is not told to look.
                $this->db->prepare('UPDATE tech_issues SET status = :s, updated_at = :n WHERE id = :id')
                    ->execute(['s' => $status, 'n' => $now, 'id' => $issue['id']]);
            } else {
                $this->db->prepare("UPDATE tech_issues SET status = :s, last_sender = 'tech', updated_at = :n WHERE id = :id")
                    ->execute(['s' => $status, 'n' => $now, 'id' => $issue['id']]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function setStatus(int $id, string $status): void {
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Unknown status.');
        $this->db->prepare('UPDATE tech_issues SET status = :s, updated_at = :n WHERE id = :id')
            ->execute(['s' => $status, 'n' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    private function insertMessage(int $issueId, string $sender, string $body, ?string $by, bool $internal, string $now): void {
        $this->db->prepare('INSERT INTO tech_issue_messages (issue_id, sender, body, author_email, is_internal, created_at)
            VALUES (:i, :s, :b, :a, :x, :n)')
            ->execute(['i' => $issueId, 's' => $sender, 'b' => $body, 'a' => $by, 'x' => $internal ? 1 : 0, 'n' => $now]);
    }

    private function recentCount(string $type, string $ref): int {
        $st = $this->db->prepare('SELECT COUNT(*) FROM tech_issues WHERE reporter_type = :t AND reporter_ref = :r AND created_at >= :since');
        $st->execute(['t' => $type, 'r' => $ref, 'since' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        return (int) $st->fetchColumn();
    }

    // ---- Reading: technical team ---------------------------------------------------------------

    /** $filter: 'open' (default), 'resolved' or 'all'. */
    public function listForStaff(string $filter = 'open'): array {
        $where = $filter === 'resolved' ? "WHERE status = 'Resolved'" : ($filter === 'all' ? '' : "WHERE status <> 'Resolved'");
        return $this->db->query("SELECT id, tracking_id, reporter_label, category, severity, title, status, last_sender, created_at, updated_at
            FROM tech_issues $where
            ORDER BY (status = 'Resolved'), FIELD(severity, 'high', 'normal', 'low'), updated_at DESC LIMIT 300")->fetchAll();
    }

    public function openCount(): int {
        return (int) $this->db->query("SELECT COUNT(*) FROM tech_issues WHERE status <> 'Resolved'")->fetchColumn();
    }

    public function getByTracking(string $tracking) {
        $st = $this->db->prepare('SELECT * FROM tech_issues WHERE tracking_id = :t');
        $st->execute(['t' => $tracking]);
        return $st->fetch();
    }

    // ---- Reading: the reporter -----------------------------------------------------------------

    public function listFor(string $type, string $ref): array {
        $st = $this->db->prepare('SELECT tracking_id, title, status, last_sender, created_at, updated_at
            FROM tech_issues WHERE reporter_type = :t AND reporter_ref = :r ORDER BY updated_at DESC LIMIT 50');
        $st->execute(['t' => $type, 'r' => $ref]);
        return $st->fetchAll();
    }

    /** Only the owner of a report can open it. Anyone else gets false, the same as "not found". */
    public function getOwned(string $tracking, string $type, string $ref) {
        $st = $this->db->prepare('SELECT * FROM tech_issues WHERE tracking_id = :t AND reporter_type = :rt AND reporter_ref = :rr');
        $st->execute(['t' => $tracking, 'rt' => $type, 'rr' => $ref]);
        return $st->fetch();
    }

    /** Reports where the technical team answered last and the matter is not resolved. */
    public function awaitingReporter(string $type, string $ref): array {
        $st = $this->db->prepare("SELECT tracking_id, title FROM tech_issues
            WHERE reporter_type = :t AND reporter_ref = :r AND last_sender = 'tech' AND status <> 'Resolved'
            ORDER BY updated_at DESC");
        $st->execute(['t' => $type, 'r' => $ref]);
        return $st->fetchAll();
    }

    public function messages(int $issueId, bool $includeInternal): array {
        $sql = 'SELECT sender, body, author_email, is_internal, created_at FROM tech_issue_messages WHERE issue_id = :i'
             . ($includeInternal ? '' : ' AND is_internal = 0') . ' ORDER BY id';
        $st = $this->db->prepare($sql);
        $st->execute(['i' => $issueId]);
        return $st->fetchAll();
    }
}
