<?php
/**
 * DCW Engage - Goodies: members claiming points (tables point_claims, point_claim_messages;
 * migrations/2026_11_goodies_claims.sql).
 *
 * A claim is a request. It never touches the ledger itself. Approving one creates an ordinary award through
 * PointsModel::insertSourceAward() with source_ref 'claim-<id>', so everything that protects awards applies:
 * the second-approval rule (150+ points by a non-owner waits for a different reviewer), struck-off members,
 * the unique key (member, activity, source) that stops a double award, and the audit log.
 *
 * Rules enforced when a member submits (submit()):
 *  - the activity is active, switched on for claiming (activity_types.member_claimable) and not built in
 *  - not struck off. Expired members CAN claim, like they can redeem.
 *  - the event date is a real date, not in the future, and at most WINDOW_DAYS ago (India time, inclusive)
 *  - an evidence link when the activity needs one; any link must be http(s)
 *  - a one-time activity the member already has (or has a live claim for) cannot be claimed again
 *  - the same evidence link cannot back two claims or awards for the same activity (not for a repeating claim)
 *  - the same activity on the same event date cannot be claimed twice
 *  - at most MAX_OPEN claims waiting at once
 *  - a repeating activity (cooldown_days, e.g. "100+ edits in the last 30 days"): the date is always today, and
 *    it can be claimed again only after the cooldown. Only claims that are waiting or approved start the cooldown.
 *
 * Rules enforced when a reviewer decides:
 *  - nobody approves a claim made by their own Member ID, owner or not. (Awards an owner makes themselves
 *    are allowed elsewhere; a claim is the member vouching for their own work, so someone else looks.)
 *  - a decision is one atomic status change, so two reviewers cannot decide the same claim twice
 *  - rejecting and asking for more information both need words, which the member sees
 *  - the claim window is not re-checked here: it limits what a member can submit, not what a reviewer may approve
 *    (reviewer discretion; late cases go to DCW Support and a reviewer can also award on the Award tab)
 *
 * Reviewer privacy: members never learn who the reviewer is. The member-side methods (forMember(),
 * findForMember(), messages()) strip reviewer emails (decided_by, message author) before returning, so a member's
 * page cannot show them. Reviewer-side methods (queue(), approve() ...) keep them, and the audit log records them.
 *
 * Statuses: Submitted (waiting for a reviewer) > Needs info (waiting for the member, who replies and it goes
 * back to Submitted) > Approved | Rejected. The member can Withdraw while it is open.
 *
 * Emails: decisions call Mailer::sendPointClaimUpdate($email, $name, $trackingId, $kind) when that method
 * exists ($kind: approved | rejected | info). Until it is added nothing is sent and nothing breaks.
 *
 * Assumes PDO is in ERRMODE_EXCEPTION. Every query is a prepared statement.
 */
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/MemberStrikeModel.php';
require_once __DIR__ . '/PointsModel.php';

class PointClaimModel
{
    public const WINDOW_DAYS = 30;
    public const MAX_OPEN    = 5;
    public const MAX_NOTE    = 1000;
    public const OPEN        = ['Submitted', 'Needs info'];
    public const STATUSES    = ['Submitted', 'Needs info', 'Approved', 'Rejected', 'Withdrawn'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? DB::getInstance()->getConnection();
    }

    /** The earliest event date a member may claim (India time, inclusive). */
    public static function windowStart(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today = $today ?? new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
        return $today->modify('-' . self::WINDOW_DAYS . ' days');
    }

    // ---- Activities ----------------------------------------------------------------------------

    private static function builtInList(): string
    {
        return "'" . implode("','", PointsModel::BUILT_IN_CODES) . "'";
    }

    /** What a member may claim right now. */
    public function claimableActivities(): array
    {
        return $this->pdo->query(
            'SELECT * FROM activity_types WHERE is_active = 1 AND member_claimable = 1 AND code NOT IN (' . self::builtInList() . ')
             ORDER BY category, points DESC, name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Active activities a member cannot claim: DCW awards these itself. For the "other ways to earn" list. */
    public function otherActivities(): array
    {
        return $this->pdo->query(
            'SELECT * FROM activity_types WHERE is_active = 1 AND (member_claimable = 0 OR code IN (' . self::builtInList() . '))
             ORDER BY category, points DESC, name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** [activity id => "12 Dec 2026"] for claimable activities this member must still wait for (cooldown_days). */
    public function cooldowns(string $memberId): array
    {
        $memberId = strtoupper(trim($memberId));
        $out = [];
        foreach ($this->claimableActivities() as $t) {
            if (($t['cooldown_days'] ?? null) === null) continue;
            $until = $this->cooldownUntil($memberId, (int) $t['id'], (int) $t['cooldown_days']);
            if ($until) $out[(int) $t['id']] = $until->format('j M Y');
        }
        return $out;
    }

    /**
     * The India-time date a member may claim a cooldown activity again, or null when they may claim now.
     * Only claims that are waiting or approved start the cooldown: a rejected or withdrawn claim does not.
     */
    private function cooldownUntil(string $memberId, int $typeId, int $days): ?DateTimeImmutable
    {
        $st = $this->pdo->prepare("SELECT MAX(created_at) FROM point_claims WHERE member_id = :m AND activity_type_id = :t AND status IN ('Submitted','Needs info','Approved')");
        $st->execute([':m' => $memberId, ':t' => $typeId]);
        $last = $st->fetchColumn();
        if (!$last) return null;
        $ist   = new DateTimeZone('Asia/Kolkata');
        $from  = (new DateTimeImmutable((string) $last, new DateTimeZone('UTC')))->setTimezone($ist)->setTime(0, 0);
        $until = $from->modify('+' . $days . ' days');
        return $until > new DateTimeImmutable('today', $ist) ? $until : null;
    }

    // ---- Member side ---------------------------------------------------------------------------

    /**
     * Make a claim.
     * @return array{ok:bool, error?:string, tracking_id?:string}
     */
    public function submit(string $memberId, int $typeId, string $eventDate, string $evidence, string $note): array
    {
        $memberId  = strtoupper(trim($memberId));
        $eventDate = trim($eventDate);
        $evidence  = trim($evidence);
        $note      = trim($note);

        // One claim at a time per member, so two quick clicks cannot slip past the open-claims limit.
        $lock = 'dcw_claim_' . md5($memberId);
        $got = $this->pdo->prepare('SELECT GET_LOCK(:l, 5)');
        $got->execute([':l' => $lock]);
        if ((int) $got->fetchColumn() !== 1) return ['ok' => false, 'error' => 'Please try again in a moment.'];

        try {
            if ((new MemberStrikeModel($this->pdo))->isStruck($memberId)) {
                return ['ok' => false, 'error' => 'Claiming is not available on your account. Please contact DCW Support.'];
            }

            $type = null;
            foreach ($this->claimableActivities() as $t) if ((int) $t['id'] === $typeId) $type = $t;
            if (!$type) return ['ok' => false, 'error' => 'That activity cannot be claimed. Choose one from the list.'];

            $ist   = new DateTimeZone('Asia/Kolkata');
            $today = new DateTimeImmutable('today', $ist);

            // A repeating claim ("100+ edits in the last 30 days"): the date is always today, whatever the form sent,
            // so it cannot be backdated; and it can be made again only after the cooldown.
            $cool = ($type['cooldown_days'] ?? null) === null ? null : (int) $type['cooldown_days'];
            if ($cool !== null) {
                $eventDate = $today->format('Y-m-d');
                $until = $this->cooldownUntil($memberId, $typeId, $cool);
                if ($until) return ['ok' => false, 'error' => 'You can claim "' . $type['name'] . '" again on ' . $until->format('j M Y') . '.'];
            }

            $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, $ist);
            if (!$date || $date->format('Y-m-d') !== $eventDate) return ['ok' => false, 'error' => 'Enter the date it happened.'];
            if ($date > $today) return ['ok' => false, 'error' => 'The date cannot be in the future.'];

            $earliest = self::windowStart($today);
            if ($date < $earliest) {
                return ['ok' => false, 'error' => 'That date is too long ago. Claims must be made within ' . self::WINDOW_DAYS
                    . ' days of the activity, so the earliest date you can claim today is ' . $earliest->format('j M Y')
                    . '. If you have a good reason, write to DCW Support and a reviewer can look at it.'];
            }

            if ($evidence !== '' && !preg_match('#^https?://\S+$#i', $evidence)) return ['ok' => false, 'error' => 'The evidence must be a link starting with http:// or https://.'];
            if ((int) $type['needs_evidence'] && $evidence === '') return ['ok' => false, 'error' => 'This activity needs an evidence link (a diff or a page).'];
            if (mb_strlen($evidence) > 500) return ['ok' => false, 'error' => 'That link is too long.'];
            if (mb_strlen($note) > self::MAX_NOTE) return ['ok' => false, 'error' => 'Please keep your note under ' . self::MAX_NOTE . ' characters.'];

            // Limit on claims waiting.
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM point_claims WHERE member_id = :m AND status IN ('Submitted','Needs info')");
            $st->execute([':m' => $memberId]);
            if ((int) $st->fetchColumn() >= self::MAX_OPEN) {
                return ['ok' => false, 'error' => 'You already have ' . self::MAX_OPEN . ' claims waiting. Please wait for those to be decided first.'];
            }

            // One-time activities.
            if ((int) ($type['one_time'] ?? 0)) {
                $st = $this->pdo->prepare("SELECT 1 FROM point_awards WHERE member_id = :m AND activity_type_id = :t AND status <> 'Rejected' LIMIT 1");
                $st->execute([':m' => $memberId, ':t' => $typeId]);
                $has = (bool) $st->fetchColumn();
                if (!$has) {
                    $st = $this->pdo->prepare("SELECT 1 FROM point_claims WHERE member_id = :m AND activity_type_id = :t AND status IN ('Submitted','Needs info','Approved') LIMIT 1");
                    $st->execute([':m' => $memberId, ':t' => $typeId]);
                    $has = (bool) $st->fetchColumn();
                }
                if ($has) return ['ok' => false, 'error' => '"' . $type['name'] . '" can only be earned once, and you already have it or a claim for it.'];
            }

            // The same thing on the same day.
            $st = $this->pdo->prepare("SELECT 1 FROM point_claims WHERE member_id = :m AND activity_type_id = :t AND event_date = :d AND status IN ('Submitted','Needs info','Approved') LIMIT 1");
            $st->execute([':m' => $memberId, ':t' => $typeId, ':d' => $eventDate]);
            if ($st->fetchColumn()) return ['ok' => false, 'error' => 'You have already claimed this activity for that date.'];

            // The same evidence link. (Not for a repeating claim: its link, such as a contributions page, is the same each time.)
            if ($evidence !== '' && $cool === null) {
                $st = $this->pdo->prepare("SELECT 1 FROM point_claims WHERE activity_type_id = :t AND evidence_url = :e AND status IN ('Submitted','Needs info','Approved') LIMIT 1");
                $st->execute([':t' => $typeId, ':e' => $evidence]);
                $used = (bool) $st->fetchColumn();
                if (!$used) {
                    $st = $this->pdo->prepare("SELECT 1 FROM point_awards WHERE activity_type_id = :t AND evidence_url = :e AND status <> 'Rejected' LIMIT 1");
                    $st->execute([':t' => $typeId, ':e' => $evidence]);
                    $used = (bool) $st->fetchColumn();
                }
                if ($used) return ['ok' => false, 'error' => 'That link has already been used for this activity. Use a different link, or write to DCW Support.'];
            }

            $tracking = $this->insertClaim($memberId, $typeId, $eventDate, $evidence, $note);
            $this->audit('member ' . $memberId, 'goodies.claim_submit', $tracking, $type['name'] . ' (' . (int) $type['points'] . ' pts), ' . $eventDate);
            return ['ok' => true, 'tracking_id' => $tracking];
        } catch (Throwable $e) {
            $this->log('submit failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'We could not save your claim just now. Please try again.'];
        } finally {
            $rel = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
            $rel->execute([':l' => $lock]);
        }
    }

    private function insertClaim(string $memberId, int $typeId, string $eventDate, string $evidence, string $note): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I/L
        for ($try = 0; $try < 5; $try++) {
            $id = 'PC-';
            for ($i = 0; $i < 8; $i++) $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            try {
                $st = $this->pdo->prepare('INSERT INTO point_claims (tracking_id, member_id, activity_type_id, event_date, evidence_url, note) VALUES (:t, :m, :a, :d, :e, :n)');
                $st->execute([':t' => $id, ':m' => $memberId, ':a' => $typeId, ':d' => $eventDate,
                              ':e' => $evidence !== '' ? $evidence : null, ':n' => $note !== '' ? $note : null]);
                return $id;
            } catch (PDOException $e) {
                if ($try === 4 || (string) $e->getCode() !== '23000') throw $e;   // only retry a duplicate tracking ID
            }
        }
        throw new RuntimeException('Could not generate a tracking ID.');
    }

    private const SELECT = 'SELECT c.*, t.name AS activity_name, t.category, t.code AS activity_code, t.points AS activity_points,
                                   t.needs_evidence, a.status AS award_status
                              FROM point_claims c
                              JOIN activity_types t ON t.id = c.activity_type_id
                              LEFT JOIN point_awards a ON a.id = c.award_id';

    /** A claim row as a member may see it: without who decided it. */
    private static function forMemberView(array $row): array
    {
        unset($row['decided_by']);
        return $row;
    }

    /** The member's claims, newest first. Never includes who decided them. */
    public function forMember(string $memberId): array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE c.member_id = :m ORDER BY c.id DESC LIMIT 100');
        $st->execute([':m' => strtoupper(trim($memberId))]);
        return array_map([self::class, 'forMemberView'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** One claim by tracking ID, only if it is this member's. Null looks the same for "not yours" and "not there". */
    public function findForMember(string $tracking, string $memberId): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE c.tracking_id = :t AND c.member_id = :m');
        $st->execute([':t' => strtoupper(trim($tracking)), ':m' => strtoupper(trim($memberId))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::forMemberView($row) : null;
    }

    /**
     * The conversation on a claim. By default it is the member's view: sender, text and time, never who wrote it.
     * Pass $withAuthor = true only from a reviewer page.
     */
    public function messages(int $claimId, bool $withAuthor = false): array
    {
        $cols = $withAuthor ? 'sender, author, body, created_at' : 'sender, body, created_at';
        $st = $this->pdo->prepare('SELECT ' . $cols . ' FROM point_claim_messages WHERE claim_id = :c ORDER BY id');
        $st->execute([':c' => $claimId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The member answers a "Needs info". The claim goes back to the reviewers. */
    public function reply(string $tracking, string $memberId, string $body): array
    {
        $body = trim($body);
        if ($body === '') return ['ok' => false, 'error' => 'Write your reply first.'];
        if (mb_strlen($body) > self::MAX_NOTE) return ['ok' => false, 'error' => 'Please keep your reply under ' . self::MAX_NOTE . ' characters.'];
        $c = $this->findForMember($tracking, $memberId);
        if (!$c || $c['status'] !== 'Needs info') return ['ok' => false, 'error' => 'This claim is not waiting for your reply.'];

        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare("UPDATE point_claims SET status = 'Submitted' WHERE id = :id AND status = 'Needs info'");
            $up->execute([':id' => $c['id']]);
            if ($up->rowCount() !== 1) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'This claim is not waiting for your reply.']; }
            $this->addMessage((int) $c['id'], 'member', 'member ' . $memberId, $body);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('reply failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'We could not send your reply just now. Please try again.'];
        }
        $this->audit('member ' . $memberId, 'goodies.claim_reply', (string) $c['tracking_id'], 'Member replied');
        return ['ok' => true];
    }

    public function withdraw(string $tracking, string $memberId): array
    {
        $c = $this->findForMember($tracking, $memberId);
        if (!$c) return ['ok' => false, 'error' => 'We could not find that claim.'];
        $up = $this->pdo->prepare("UPDATE point_claims SET status = 'Withdrawn', decided_at = :d WHERE id = :id AND status IN ('Submitted','Needs info')");
        $up->execute([':d' => gmdate('Y-m-d H:i:s'), ':id' => $c['id']]);
        if ($up->rowCount() !== 1) return ['ok' => false, 'error' => 'This claim has already been decided, so it cannot be withdrawn.'];
        $this->audit('member ' . $memberId, 'goodies.claim_withdraw', (string) $c['tracking_id'], 'Withdrawn by the member');
        return ['ok' => true];
    }

    // ---- Reviewer side -------------------------------------------------------------------------

    /** Counts per status, e.g. ['Submitted' => 3, 'Needs info' => 1]. */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM point_claims GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** Claims waiting for a reviewer: the badge number. */
    public function awaitingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM point_claims WHERE status = 'Submitted'")->fetchColumn();
    }

    /**
     * The queue. $status: 'open' (Submitted and Needs info, oldest first), one status, or '' for all (newest first).
     * Each row carries the member's name, the activity, the award it created, the verified Wikimedia username,
     * the member's previous claim for the same activity, and its messages (with their authors: reviewers only).
     */
    public function queue(string $status = 'open'): array
    {
        $where = ''; $args = [];
        if ($status === 'open') { $where = " WHERE c.status IN ('Submitted','Needs info')"; }
        elseif (in_array($status, self::STATUSES, true)) { $where = ' WHERE c.status = :s'; $args[':s'] = $status; }
        $order = $status === 'open' ? 'c.id ASC' : 'c.id DESC';

        $st = $this->pdo->prepare(
            'SELECT c.*, t.name AS activity_name, t.category, t.code AS activity_code, t.points AS activity_points, t.needs_evidence,
                    t.cooldown_days,
                    a.status AS award_status,
                    (SELECT m.full_name FROM members m WHERE m.member_id = c.member_id ORDER BY m.id DESC LIMIT 1) AS member_name,
                    (SELECT p.wikimedia_username FROM member_profile p WHERE p.member_id = c.member_id AND p.username_verified_at IS NOT NULL) AS wm_username,
                    (SELECT MAX(c2.created_at) FROM point_claims c2 WHERE c2.member_id = c.member_id AND c2.activity_type_id = c.activity_type_id
                        AND c2.id < c.id AND c2.status IN (\'Submitted\',\'Needs info\',\'Approved\')) AS prev_claim_at
               FROM point_claims c
               JOIN activity_types t ON t.id = c.activity_type_id
               LEFT JOIN point_awards a ON a.id = c.award_id' . $where . ' ORDER BY ' . $order . ' LIMIT 200'
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        if ($rows) {
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ms = $this->pdo->prepare("SELECT claim_id, sender, author, body, created_at FROM point_claim_messages WHERE claim_id IN ($in) ORDER BY id");
            $ms->execute($ids);
            foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) $byId[(int) $m['claim_id']][] = $m;
        }
        foreach ($rows as &$r) $r['messages'] = $byId[(int) $r['id']] ?? [];
        unset($r);
        return $rows;
    }

    private function claim(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE c.id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Approve a claim: it becomes an award (see the class note). The claim window is not checked here.
     * $ownIds are the reviewer's own Member IDs; a claim from one of them is refused, owner or not.
     * $actorIsOwner is true only when the page has checked it: it lets the award skip the second-approval wait.
     * @return array{ok:bool, error?:string, awaiting_second?:bool}
     */
    public function approve(int $claimId, string $actorEmail, bool $actorIsOwner, array $ownIds = []): array
    {
        $c = $this->claim($claimId);
        if (!$c || !in_array($c['status'], self::OPEN, true)) return ['ok' => false, 'error' => 'That claim has already been decided.'];

        $own = array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
        if (isset($own[strtoupper((string) $c['member_id'])])) return ['ok' => false, 'error' => 'You cannot decide your own claim. Another reviewer or owner must.'];
        if ((new MemberStrikeModel($this->pdo))->isStruck((string) $c['member_id'])) return ['ok' => false, 'error' => 'That member is struck off, so points cannot be awarded.'];

        $pm   = new PointsModel($this->pdo);
        $type = $pm->activityType((int) $c['activity_type_id']);
        if (!$type || !(int) $type['is_active']) return ['ok' => false, 'error' => 'That activity is switched off. Switch it on, or reject the claim.'];
        if ((int) ($type['one_time'] ?? 0)) {
            $st = $this->pdo->prepare("SELECT 1 FROM point_awards WHERE member_id = :m AND activity_type_id = :t AND status <> 'Rejected' LIMIT 1");
            $st->execute([':m' => $c['member_id'], ':t' => $c['activity_type_id']]);
            if ($st->fetchColumn()) return ['ok' => false, 'error' => 'This member already has that one-time award. Reject the claim.'];
        }

        // Take the decision first, atomically, so a second reviewer cannot act on the same claim.
        $prior = (string) $c['status'];
        $up = $this->pdo->prepare("UPDATE point_claims SET status = 'Approved', decided_by = :by, decided_at = :d WHERE id = :id AND status IN ('Submitted','Needs info')");
        $up->execute([':by' => $actorEmail, ':d' => gmdate('Y-m-d H:i:s'), ':id' => $claimId]);
        if ($up->rowCount() !== 1) return ['ok' => false, 'error' => 'That claim has already been decided.'];

        $ref = 'claim-' . $claimId;
        try {
            $made = $pm->insertSourceAward(
                (string) $c['member_id'], $type, (int) $type['points'], 'standard', $ref,
                ' (claim ' . $c['tracking_id'] . ')', $actorEmail, $actorIsOwner,
                (string) ($c['evidence_url'] ?? ''), 'Claim ' . $c['tracking_id']
            );
        } catch (Throwable $e) {
            $this->log('approve failed for ' . $c['tracking_id'] . ': ' . $e->getMessage());
            $made = false;
        }
        if (!$made) {
            $this->pdo->prepare('UPDATE point_claims SET status = :s, decided_by = NULL, decided_at = NULL WHERE id = :id')->execute([':s' => $prior, ':id' => $claimId]);
            return ['ok' => false, 'error' => 'Could not create the award. If points were already awarded for this claim, it cannot be awarded twice.'];
        }

        $aw = $this->pdo->prepare('SELECT id, status FROM point_awards WHERE member_id = :m AND activity_type_id = :t AND source_ref = :r');
        $aw->execute([':m' => $c['member_id'], ':t' => $c['activity_type_id'], ':r' => $ref]);
        $award = $aw->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($award) $this->pdo->prepare('UPDATE point_claims SET award_id = :a WHERE id = :id')->execute([':a' => $award['id'], ':id' => $claimId]);

        $waiting = $award && $award['status'] === 'Needs Second Approval';
        $this->audit($actorEmail, 'goodies.claim_approve', (string) $c['tracking_id'],
            $type['name'] . ' (' . (int) $type['points'] . ' pts) for ' . $c['member_id'] . ($waiting ? ', awaiting second approval' : '') . ($actorIsOwner ? ', owner' : ''));
        $this->notify((string) $c['member_id'], (string) $c['tracking_id'], 'approved');
        return ['ok' => true, 'awaiting_second' => $waiting];
    }

    /** @return array{approved:int, skipped:int} */
    public function approveMany(array $ids, string $actorEmail, bool $actorIsOwner, array $ownIds = []): array
    {
        $approved = $skipped = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0 && $this->approve($id, $actorEmail, $actorIsOwner, $ownIds)['ok']) $approved++; else $skipped++;
        }
        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /** Reject with a reason the member will read. Write it without names: members must not learn who reviewed. */
    public function reject(int $claimId, string $reason, string $actorEmail): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Give a reason. The member will read it.'];
        if (mb_strlen($reason) > self::MAX_NOTE) return ['ok' => false, 'error' => 'Please keep the reason under ' . self::MAX_NOTE . ' characters.'];
        $c = $this->claim($claimId);
        if (!$c) return ['ok' => false, 'error' => 'That claim no longer exists.'];

        $up = $this->pdo->prepare("UPDATE point_claims SET status = 'Rejected', decided_by = :by, decided_at = :d, decision_note = :n WHERE id = :id AND status IN ('Submitted','Needs info')");
        $up->execute([':by' => $actorEmail, ':d' => gmdate('Y-m-d H:i:s'), ':n' => $reason, ':id' => $claimId]);
        if ($up->rowCount() !== 1) return ['ok' => false, 'error' => 'That claim has already been decided.'];
        $this->audit($actorEmail, 'goodies.claim_reject', (string) $c['tracking_id'], mb_substr($reason, 0, 200));
        $this->notify((string) $c['member_id'], (string) $c['tracking_id'], 'rejected');
        return ['ok' => true];
    }

    /** Ask the member for more. The claim waits for their reply. */
    public function requestInfo(int $claimId, string $message, string $actorEmail): array
    {
        $message = trim($message);
        if ($message === '') return ['ok' => false, 'error' => 'Write what you need to know. The member will read it.'];
        if (mb_strlen($message) > self::MAX_NOTE) return ['ok' => false, 'error' => 'Please keep the message under ' . self::MAX_NOTE . ' characters.'];
        $c = $this->claim($claimId);
        if (!$c) return ['ok' => false, 'error' => 'That claim no longer exists.'];

        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare("UPDATE point_claims SET status = 'Needs info' WHERE id = :id AND status IN ('Submitted','Needs info')");
            $up->execute([':id' => $claimId]);
            if ($up->rowCount() < 1 && $c['status'] !== 'Needs info') { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'That claim has already been decided.']; }
            if (!in_array($c['status'], self::OPEN, true)) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'That claim has already been decided.']; }
            $this->addMessage($claimId, 'reviewer', $actorEmail, $message);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('requestInfo failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not send the message.'];
        }
        $this->audit($actorEmail, 'goodies.claim_info', (string) $c['tracking_id'], mb_substr($message, 0, 200));
        $this->notify((string) $c['member_id'], (string) $c['tracking_id'], 'info');
        return ['ok' => true];
    }

    // ---- Internals -----------------------------------------------------------------------------

    private function addMessage(int $claimId, string $sender, string $author, string $body): void
    {
        $st = $this->pdo->prepare('INSERT INTO point_claim_messages (claim_id, sender, author, body) VALUES (:c, :s, :a, :b)');
        $st->execute([':c' => $claimId, ':s' => $sender, ':a' => $author, ':b' => $body]);
    }

    /**
     * Email the member about a decision. Never contains the reviewer's words or name (they read the words signed in).
     * Needs Mailer::sendPointClaimUpdate(); until that exists, nothing is sent.
     */
    private function notify(string $memberId, string $tracking, string $kind): void
    {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            if (!class_exists('Mailer') || !method_exists('Mailer', 'sendPointClaimUpdate')) return;
            $st = $this->pdo->prepare('SELECT email, full_name FROM members WHERE member_id = :m ORDER BY id DESC LIMIT 1');
            $st->execute([':m' => $memberId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if ($m && $m['email'] !== '') Mailer::sendPointClaimUpdate((string) $m['email'], (string) $m['full_name'], $tracking, $kind);
        } catch (Throwable $e) {
            $this->log('notify failed: ' . $e->getMessage());
        }
    }

    private function audit(string $actor, string $action, string $target, string $detail): void
    {
        try {
            AuditLog::activity($action, $target, mb_substr($detail, 0, 500), $actor);
        } catch (Throwable $e) {
            $this->log('audit failed: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        app_log('PointClaimModel: ' . $message);
    }
}
