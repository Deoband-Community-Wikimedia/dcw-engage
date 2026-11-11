<?php
/**
 * DCW Engage - Goodies: non-expiring member points, and their redemption for goodies.
 *
 * The rules this class enforces:
 *  - points_ledger is append-only. A balance is SUM(points) for a Member ID. Nothing here updates
 *    or deletes a ledger row; corrections are new rows (refund, reversal).
 *  - Points reach the ledger only through an approved award. An award made by a non-owner reviewer worth
 *    SECOND_APPROVAL_AT or more needs a second reviewer, and never the one who created it. Awards made by
 *    an owner (to anyone, including themselves) are approved straight away, because the community has too
 *    few organisers to always find a second reviewer. An owner awarding their own Member ID is logged as
 *    goodies.award_self so it stays easy to review.
 *  - The same exemption applies when approving: an owner may approve any award waiting for a second
 *    reviewer, including one they created and one made to their own Member ID. That is logged as
 *    goodies.approve_self so it stays easy to review. Non-owners still need a different reviewer and can
 *    never approve awards to their own Member IDs.
 *  - Redemption is one transaction under a per-member lock, so two quick clicks cannot spend the
 *    same points twice.
 *  - Reviewer actions are written to the audit log (AuditLog::activity). The ledger carries the actor's email as a snapshot.
 *  - Bulk awards (several members, one activity) go through the same rules, one member at a time, under one
 *    batch reference so a double click cannot award twice. Bulk point edits revise past awards exactly like a
 *    single edit does, all in one transaction.
 *  - Stock (optional per goody, per size when the goody has sizes) lives in goodies.stock_json. NULL means
 *    not tracked (unlimited). A redemption takes its units from stock inside its transaction, under a row lock
 *    on the goody, so the last item cannot be sold twice. Cancelling an order puts the items back.
 *  - An order is a cart: one redemptions row, its goodies in redemption_items. Part of an order can be refunded
 *    (redemption_refunds) while it is Requested or Ordered. A goody may cap the units per order (max_per_order).
 *  - Pictures (optional per goody) live in goodies.images_json, in display order, the first being the cover.
 *    The files are in uploads/goodies/. GoodyImages checks, resizes and stores them; this class only keeps
 *    the list (goodyImages(), setGoodyImages()) and hands it to the pages as $goody['images'].
 *  - What a member sees as "points history" is ledgerDetailed() (names joined in, balance after each row) and
 *    pendingForMember() (awards still waiting for a second reviewer). The member pages show them through
 *    includes/points_ui.php. nextGoal() and ordersInProgress() feed the dashboard card and the goodies page.
 *
 * Assumes PDO is in ERRMODE_EXCEPTION, as the other models do. Keep every query a prepared statement.
 * A Member ID is one person across every chapter they hold (letter + 8 digits, e.g. A48213977), so
 * points follow the person, not the chapter.
 */
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/MemberStrikeModel.php';
require_once __DIR__ . '/GoodyImages.php';

class PointsModel
{
    /** Awards at or above this many points, made by a non-owner reviewer, need a second, different reviewer. */
    public const SECOND_APPROVAL_AT = 150;

    /** The most people one bulk award can include. */
    public const BULK_MAX = 200;

    /** Most different goodies/sizes in one order, and most units of a goody that has no "max per order". */
    public const MAX_LINES = 20;
    public const MAX_UNITS = 50;

    /** Allowed redemption status moves. Cancelling refunds the points. */
    public const REDEMPTION_FLOW = [
        'Requested' => ['Ordered', 'Cancelled'],
        'Ordered'   => ['Shipped', 'Cancelled'],
        'Shipped'   => ['Delivered'],
        'Delivered' => [],
        'Cancelled' => [],
    ];

    /** Key used in goodies.stock_json for a goody that has no sizes/options. */
    public const STOCK_ALL = '_all';

    /**
     * Activities the code looks up by their code (Conversation Hour awards, the TaW edition check). Reviewers
     * can rename them, change their points or switch them off, but not delete them.
     * Whether an activity can be awarded only once is the activity's own one_time setting.
     */
    public const BUILT_IN_CODES = ['ch_attend', 'ch_host', 'taw_graduate'];

    public const CATEGORIES = ['learn' => 'Learning', 'contribute' => 'Contributing', 'community' => 'Community', 'organisation' => 'Organising'];

    /**
     * Train a Wikipedian: one award for graduating from the whole programme, and only from the April 2026
     * TaW onwards (earlier editions don't count). The evidence must be that TaW's page on dcwwiki.org.
     */
    public const TAW_FIRST_EDITION = '2026-04';

    private PDO $pdo;

    /** Per-request lookups for Conversation Hour awards, built on first use. Matching is done in PHP. */
    private ?array $knownMembers = null;     // MEMBER ID => true
    private ?array $verifiedUsers = null;    // lower-case verified Wikimedia username => MEMBER ID
    private ?array $struckMembers = null;    // MEMBER ID => strike row
    private ?array $directory = null;        // MEMBER ID => ['name' => ..., 'username' => ...]

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? DB::getInstance()->getConnection();
    }

    // ---- Reading -------------------------------------------------------------------------------

    public function balance(string $memberId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE member_id = :m');
        $st->execute([':m' => $memberId]);
        return (int) $st->fetchColumn();
    }

    /** Newest first. The bare ledger; the member pages use ledgerDetailed() instead. */
    public function history(string $memberId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT points, entry_type, reason, created_at FROM points_ledger
             WHERE member_id = :m ORDER BY id DESC LIMIT ' . max(1, (int) $limit)
        );
        $st->execute([':m' => $memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Everything the member sees as "points history", newest first, each row with what it was for and the
     * balance right after it. history() stays as it is for any caller that wants the bare ledger.
     *
     * Joins: the award (activity name, category), the Conversation Hour session an award came from
     * (source_ref 'ch-session-<id>', 11 characters before the id), and the order (goody name, tracking ID).
     * A member has at most a few hundred rows, so the caller filters and pages in PHP.
     */
    public function ledgerDetailed(string $memberId, int $limit = 2000): array
    {
        $st = $this->pdo->prepare(
            "SELECT l.id, l.points, l.entry_type, l.reason, l.created_at, l.award_id, l.redemption_id, l.refund_id,
                    a.tier, t.name AS activity_name, t.category, t.code AS activity_code,
                    s.title AS session_title, s.held_on AS session_date,
                    r.tracking_id AS order_tracking
               FROM points_ledger l
               LEFT JOIN point_awards a   ON a.id = l.award_id
               LEFT JOIN activity_types t ON t.id = a.activity_type_id
               LEFT JOIN ch_sessions s    ON a.source_ref LIKE 'ch-session-%'
                                         AND s.id = CAST(SUBSTRING(a.source_ref, 12) AS UNSIGNED)
               LEFT JOIN redemptions r    ON r.id = l.redemption_id
              WHERE l.member_id = :m
              ORDER BY l.id DESC
              LIMIT " . max(1, (int) $limit)
        );
        $st->execute([':m' => $memberId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // What each order / refund was for: a refund names its own units, an order is summarised ("T-shirt (M) +2 more").
        $orderIds = $refundIds = [];
        foreach ($rows as $r) {
            if ($r['redemption_id'] !== null) $orderIds[(int) $r['redemption_id']] = true;
            if ((int) $r['refund_id'] > 0) $refundIds[(int) $r['refund_id']] = true;
        }
        $orderItems = [];
        foreach (array_chunk(array_keys($orderIds), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->pdo->prepare("SELECT i.redemption_id, i.qty, i.variant, g.name FROM redemption_items i JOIN goodies g ON g.id = i.goody_id WHERE i.redemption_id IN ($in) ORDER BY i.id");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i) $orderItems[(int) $i['redemption_id']][] = $i;
        }
        $refundNames = [];
        foreach (array_chunk(array_keys($refundIds), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->pdo->prepare("SELECT f.id, f.qty, i.variant, g.name FROM redemption_refunds f JOIN redemption_items i ON i.id = f.item_id JOIN goodies g ON g.id = i.goody_id WHERE f.id IN ($in)");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) $refundNames[(int) $f['id']] = self::itemLabel((string) $f['name'], (string) ($f['variant'] ?? ''), (int) $f['qty']);
        }

        // Balance after each row: start from today's balance and step back through the newer rows.
        $running = $this->balance($memberId);
        foreach ($rows as &$r) {
            $r['goody_name'] = (int) $r['refund_id'] > 0
                ? ($refundNames[(int) $r['refund_id']] ?? '')
                : ($r['redemption_id'] !== null ? self::orderSummary($orderItems[(int) $r['redemption_id']] ?? []) : '');
            $r['balance_after'] = $running;
            $running -= (int) $r['points'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Awards that are saved but do not count yet (waiting for a second reviewer), newest first.
     * Same fields as ledgerDetailed() so the same display code reads both; entry_type is 'pending'.
     */
    public function pendingForMember(string $memberId): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.id, a.points, 'pending' AS entry_type, '' AS reason, a.created_at,
                    t.name AS activity_name, t.category, t.code AS activity_code,
                    s.title AS session_title, s.held_on AS session_date
               FROM point_awards a
               JOIN activity_types t   ON t.id = a.activity_type_id
               LEFT JOIN ch_sessions s ON a.source_ref LIKE 'ch-session-%'
                                      AND s.id = CAST(SUBSTRING(a.source_ref, 12) AS UNSIGNED)
              WHERE a.member_id = :m AND a.status = 'Needs Second Approval'
              ORDER BY a.id DESC"
        );
        $st->execute([':m' => $memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The nearest goody the member cannot afford yet, and how many they can afford now.
     * Sold-out goodies are skipped, so the goal never points at something that cannot be ordered.
     * $goodies is goodies(true).
     * @return array{next:?array, affordable:int}
     */
    public static function nextGoal(array $goodies, int $balance, bool $canRedeem = true): array
    {
        $next = null;
        $affordable = 0;
        foreach ($goodies as $g) {
            if (!self::inStock($g)) continue;
            $cost = (int) $g['points_cost'];
            if ($cost > $balance) {
                if ($next === null || $cost < (int) $next['points_cost']) $next = $g;
            } elseif ($canRedeem) {
                $affordable++;
            }
        }
        return ['next' => $next, 'affordable' => $affordable];
    }

    /** Orders still on their way: everything except Delivered and Cancelled. */
    public static function ordersInProgress(array $orders): array
    {
        return array_values(array_filter($orders, fn($o) => !in_array((string) $o['status'], ['Delivered', 'Cancelled'], true)));
    }

    public function activityTypes(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM activity_types' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY category, points DESC, name';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activityType(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM activity_types WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function activityTypeByCode(string $code): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM activity_types WHERE code = :c');
        $st->execute([':c' => $code]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function award(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM point_awards WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Awards waiting for a second reviewer, oldest first. */
    public function awardsAwaitingSecond(): array
    {
        return $this->pdo->query(
            "SELECT a.*, t.name AS activity_name FROM point_awards a
             JOIN activity_types t ON t.id = a.activity_type_id
             WHERE a.status = 'Needs Second Approval' ORDER BY a.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Front-page figures for the reviewer. outstanding is DCW's liability at 1 point = ₹1. */
    public function totals(): array
    {
        $row = $this->pdo->query(
            "SELECT COALESCE(SUM(points), 0) AS outstanding,
                    COALESCE(SUM(CASE WHEN entry_type IN ('award','revaluation') THEN points END), 0) AS awarded,
                    COALESCE(-SUM(CASE WHEN entry_type IN ('redemption','refund') THEN points END), 0) AS spent
             FROM points_ledger"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['awaiting_second'] = (int) $this->pdo->query("SELECT COUNT(*) FROM point_awards WHERE status = 'Needs Second Approval'")->fetchColumn();
        $row['requested']       = (int) $this->pdo->query("SELECT COUNT(*) FROM redemptions WHERE status = 'Requested'")->fetchColumn();
        return array_map('intval', $row);
    }

    // ---- Awards --------------------------------------------------------------------------------

    /**
     * Checks that do not depend on the member: the evidence link and the TaW edition. Null when fine.
     */
    private function checkEvidence(array $type, string $evidence): ?string
    {
        if ($evidence !== '' && !preg_match('#^https?://\S+$#i', $evidence)) return 'Evidence must be a link starting with http:// or https://.';
        if ((int) $type['needs_evidence'] && $evidence === '') return 'This activity needs an evidence link (a diff or page).';
        if ((string) $type['code'] === 'taw_graduate') {
            $edition = self::tawEdition($evidence);
            if ($edition === null) {
                return 'Link the page of the TaW they graduated from, for example https://dcwwiki.org/Train_a_Wikipedian,_April_2026';
            }
            if ($edition < self::TAW_FIRST_EDITION) {
                return 'Only TaW April 2026 and later count. Earlier editions are not awarded.';
            }
        }
        return null;
    }

    /** True when the activity is one-time and this member already has it (rejected awards do not count). */
    private function hasOneTime(array $type, string $memberId): bool
    {
        if (!(int) ($type['one_time'] ?? 0)) return false;
        $st = $this->pdo->prepare("SELECT 1 FROM point_awards WHERE member_id = :m AND activity_type_id = :t AND status <> 'Rejected' LIMIT 1");
        $st->execute([':m' => $memberId, ':t' => (int) $type['id']]);
        return (bool) $st->fetchColumn();
    }

    /**
     * A reviewer awards points for an activity. An award made by an owner is approved straight away,
     * whatever its size. An award made by any other reviewer is approved straight away under
     * SECOND_APPROVAL_AT; otherwise it waits for a different reviewer.
     *
     * $actorIsOwner is true only when the caller has checked that the actor is an owner. The model cannot
     * check the role itself (it only receives an email), so the page decides.
     * $isSelf is true when the award is to the actor's own Member ID; it only changes the audit action
     * (goodies.award_self), so such awards stay easy to review.
     * @return array{ok:bool, error?:string, status?:string, points?:int}
     */
    public function createAward(string $memberId, int $typeId, string $evidence, string $note, string $actorEmail, bool $actorIsOwner = false, bool $isSelf = false): array
    {
        $type = $this->activityType($typeId);
        if (!$type || !(int) $type['is_active']) return ['ok' => false, 'error' => 'That activity is not available.'];
        if ($err = $this->checkEvidence($type, $evidence)) return ['ok' => false, 'error' => $err];
        if ($this->hasOneTime($type, $memberId)) return ['ok' => false, 'error' => '"' . $type['name'] . '" can only be awarded once, and this member already has it.'];

        $points = (int) $type['points'];
        $needsSecond = $points >= self::SECOND_APPROVAL_AT && !$actorIsOwner;
        $status = $needsSecond ? 'Needs Second Approval' : 'Approved';

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO point_awards (member_id, activity_type_id, points, evidence_url, note, status, created_by_email, first_reviewer_email, decided_at)
                 VALUES (:m, :t, :p, :e, :n, :s, :a, :a2, :d)'
            );
            // :a and :a2 carry the same email: with native prepared statements a placeholder can't be used twice.
            $st->execute([
                ':m' => $memberId, ':t' => $typeId, ':p' => $points,
                ':e' => $evidence !== '' ? $evidence : null, ':n' => $note !== '' ? mb_substr($note, 0, 500) : null,
                ':s' => $status, ':a' => $actorEmail, ':a2' => $actorEmail, ':d' => $needsSecond ? null : gmdate('Y-m-d H:i:s'),
            ]);
            $awardId = (int) $this->pdo->lastInsertId();
            if (!$needsSecond) $this->writeAwardLedger($awardId, $memberId, $points, (string) $type['name'], $actorEmail);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('createAward failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the award. If it was already awarded for this event, it cannot be awarded twice.'];
        }
        $this->audit($actorEmail, ($actorIsOwner && $isSelf) ? 'goodies.award_self' : 'goodies.award', $memberId,
            $type['name'] . ' (' . $points . ' pts, ' . $status . ($actorIsOwner ? ', owner award' : '') . ')');
        return ['ok' => true, 'status' => $status, 'points' => $points];
    }

    /**
     * The second reviewer approves a large award. For a non-owner it must never be the person who created it.
     *
     * $actorIsOwner is true only when the caller has checked that the actor is an owner (the model only
     * receives an email, so the page decides). An owner may approve an award they created themselves; that
     * is logged as goodies.approve_self so it stays easy to review.
     */
    public function approveAward(int $awardId, string $actorEmail, bool $actorIsOwner = false): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'SELECT a.*, t.name AS activity_name FROM point_awards a
                 JOIN activity_types t ON t.id = a.activity_type_id WHERE a.id = :id FOR UPDATE'
            );
            $st->execute([':id' => $awardId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a || $a['status'] !== 'Needs Second Approval') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'That award is no longer waiting for approval.'];
            }
            $isCreator = strcasecmp((string) $a['first_reviewer_email'], $actorEmail) === 0;
            if ($isCreator && !$actorIsOwner) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'A different reviewer must approve this award.'];
            }
            $up = $this->pdo->prepare("UPDATE point_awards SET status = 'Approved', second_reviewer_email = :a, decided_at = :d WHERE id = :id");
            $up->execute([':a' => $actorEmail, ':d' => gmdate('Y-m-d H:i:s'), ':id' => $awardId]);
            $this->writeAwardLedger($awardId, (string) $a['member_id'], (int) $a['points'], (string) $a['activity_name'], $actorEmail);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('approveAward failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not approve the award.'];
        }
        $this->audit($actorEmail, ($actorIsOwner && $isCreator) ? 'goodies.approve_self' : 'goodies.approve', (string) $a['member_id'],
            $a['activity_name'] . ' (' . $a['points'] . ' pts' . ($actorIsOwner ? ', owner approval' : '') . ')');
        return ['ok' => true];
    }

    /**
     * Approve several awards waiting for a second reviewer. Each is approved on its own (its own transaction
     * and audit entry), so one that cannot be approved does not stop the others.
     * $ownIds are Member IDs the actor may not approve for (a non-owner reviewer's own IDs).
     * $actorIsOwner is true when the page has checked that the actor is an owner: $ownIds is then ignored and
     * awards the owner created themselves can be approved too.
     * @return array{approved:int, skipped:int}
     */
    public function approveAwards(array $awardIds, string $actorEmail, array $ownIds = [], bool $actorIsOwner = false): array
    {
        $own = $actorIsOwner ? [] : array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
        $approved = $skipped = 0;
        foreach (array_unique(array_map('intval', $awardIds)) as $id) {
            $a = $id > 0 ? $this->award($id) : null;
            if (!$a || isset($own[strtoupper((string) $a['member_id'])])) { $skipped++; continue; }
            if ($this->approveAward($id, $actorEmail, $actorIsOwner)['ok']) $approved++; else $skipped++;
        }
        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /** @return array{rejected:int, skipped:int} */
    public function rejectAwards(array $awardIds, string $actorEmail): array
    {
        $rejected = $skipped = 0;
        foreach (array_unique(array_map('intval', $awardIds)) as $id) {
            if ($id > 0 && $this->rejectAward($id, $actorEmail)) $rejected++; else $skipped++;
        }
        return ['rejected' => $rejected, 'skipped' => $skipped];
    }

    /**
     * The TaW edition ('YYYY-MM') a dcwwiki.org Train a Wikipedian page link points to, or null.
     * Accepts https://dcwwiki.org/Train_a_Wikipedian,_April_2026 and the same with spaces or %2C.
     */
    public static function tawEdition(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'dcwwiki.org' && $host !== 'www.dcwwiki.org') return null;
        $page = rawurldecode((string) ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        if (!preg_match('/Train[ _]a[ _]Wikipedian,?[ _]+([A-Za-z]+)[ _]+(\d{4})/i', $page, $m)) return null;
        $month = DateTimeImmutable::createFromFormat('!F Y', ucfirst(strtolower($m[1])) . ' ' . $m[2])
              ?: DateTimeImmutable::createFromFormat('!M Y', ucfirst(strtolower($m[1])) . ' ' . $m[2]);
        return $month ? $month->format('Y-m') : null;
    }

    public function rejectAward(int $awardId, string $actorEmail): bool
    {
        $st = $this->pdo->prepare("UPDATE point_awards SET status = 'Rejected', second_reviewer_email = :a, decided_at = :d WHERE id = :id AND status = 'Needs Second Approval'");
        $st->execute([':a' => $actorEmail, ':d' => gmdate('Y-m-d H:i:s'), ':id' => $awardId]);
        if ($st->rowCount() > 0) $this->audit($actorEmail, 'goodies.reject', '#' . $awardId, 'Award rejected');
        return $st->rowCount() > 0;
    }

    /** Take points back (a bad award, a code-of-conduct breach). Only ever negative; it is a new ledger row. */
    public function reverse(string $memberId, int $points, string $reason, string $actorEmail): array
    {
        if ($points <= 0) return ['ok' => false, 'error' => 'Enter a positive number of points to take back.'];
        if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required.'];
        $st = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, reason, actor_email) VALUES (:m, :p, 'reversal', :r, :a)");
        $st->execute([':m' => $memberId, ':p' => -$points, ':r' => 'Reversal: ' . mb_substr(trim($reason), 0, 230), ':a' => $actorEmail]);
        $this->audit($actorEmail, 'goodies.reverse', $memberId, '-' . $points . ' pts: ' . mb_substr(trim($reason), 0, 200));
        return ['ok' => true];
    }

    private function writeAwardLedger(int $awardId, string $memberId, int $points, string $label, string $actorEmail): void
    {
        $st = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, award_id, reason, actor_email) VALUES (:m, :p, 'award', :aw, :r, :a)");
        $st->execute([':m' => $memberId, ':p' => $points, ':aw' => $awardId, ':r' => mb_substr('Award: ' . $label, 0, 255), ':a' => $actorEmail]);
    }

    // ---- Bulk awards -----------------------------------------------------------------------------
    //
    // Flow: the reviewer picks several members and ONE activity, bulkPlan() sorts them into buckets without
    // saving anything, the page shows that preview, and bulkAward() saves the "ready" bucket on confirm.
    // Each person is saved on their own (a failure for one does not stop the rest). Every award in a batch
    // carries source_ref = 'bulk-' + the batch token, and the unique key (member, activity, source_ref) means
    // a double click or a replayed form cannot award anyone twice.
    // The second-approval rule applies per person: an owner's awards are approved at once, a non-owner's
    // awards of SECOND_APPROVAL_AT points or more wait for a different reviewer.

    /**
     * Split typed text into entries, one per line: "Name or Member ID or username", optionally followed by
     * an evidence link for that person ("Asha Rao https://en.wikipedia.org/wiki/Special:Diff/123").
     * @return list<array{typed:string, evidence:string}>
     */
    public static function parseBulkLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $evidence = '';
            if (preg_match('/^(.*?)[\s,;|]+(https?:\/\/\S+)$/u', $line, $m)) {
                $line = $m[1];
                $evidence = $m[2];
            }
            $typed = trim($line, " \t,;|");
            if ($typed !== '') $out[] = ['typed' => $typed, 'evidence' => $evidence];
        }
        return $out;
    }

    /**
     * What a bulk award would do, without doing it.
     * $ownIds are the actor's own Member IDs: a non-owner's are left out, an owner's are awarded and flagged 'self'.
     * @return array{error?:string, type?:array, points?:int, ready:list<array{member_id:string,name:string,evidence:string,self:bool}>,
     *               ambiguous:list<array{typed:string,error:string}>, notfound:list<string>, struck:list<string>,
     *               already:list<string>, own:list<string>, invalid:list<array{name:string,error:string}>, dupes:int,
     *               needs_second:bool}
     */
    public function bulkPlan(array $entries, int $typeId, string $sharedEvidence, array $ownIds, bool $isOwner): array
    {
        $plan = ['ready' => [], 'ambiguous' => [], 'notfound' => [], 'struck' => [], 'already' => [], 'own' => [], 'invalid' => [], 'dupes' => 0, 'needs_second' => false];
        $type = $this->activityType($typeId);
        if (!$type || !(int) $type['is_active']) return ['error' => 'Choose an activity first.'] + $plan;
        if (!$entries) return ['error' => 'Add at least one member.'] + $plan;
        if (count($entries) > self::BULK_MAX) return ['error' => 'Up to ' . self::BULK_MAX . ' people at a time, please. You added ' . count($entries) . '.'] + $plan;

        $sharedEvidence = trim($sharedEvidence);
        $own = array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
        $struck = $this->struckMembers();
        $seen = [];
        $points = (int) $type['points'];

        foreach ($entries as $e) {
            $hit = $this->findMember((string) $e['typed']);
            if (!isset($hit['member_id'])) {
                if (strpos((string) $hit['error'], 'No member found') === 0) $plan['notfound'][] = (string) $e['typed'];
                else $plan['ambiguous'][] = ['typed' => (string) $e['typed'], 'error' => (string) $hit['error']];
                continue;
            }
            $mid = (string) $hit['member_id'];
            $label = $hit['name'] . ' (' . $mid . ')';
            if (isset($seen[$mid])) { $plan['dupes']++; continue; }
            $seen[$mid] = true;

            $isSelf = isset($own[$mid]);
            if ($isSelf && !$isOwner) { $plan['own'][] = $label; continue; }
            if (isset($struck[$mid])) { $plan['struck'][] = $label; continue; }
            $evidence = trim((string) ($e['evidence'] ?? '')) !== '' ? trim((string) $e['evidence']) : $sharedEvidence;
            if ($err = $this->checkEvidence($type, $evidence)) { $plan['invalid'][] = ['name' => $label, 'error' => $err]; continue; }
            if ($this->hasOneTime($type, $mid)) { $plan['already'][] = $label; continue; }
            $plan['ready'][] = ['member_id' => $mid, 'name' => (string) $hit['name'], 'evidence' => $evidence, 'self' => $isSelf];
        }
        $plan['type'] = ['id' => (int) $type['id'], 'name' => (string) $type['name']];
        $plan['points'] = $points;
        $plan['needs_second'] = $points >= self::SECOND_APPROVAL_AT && !$isOwner;
        return $plan;
    }

    /**
     * Save the "ready" people of a plan. Everything is re-checked here (strikes, one-time awards, evidence),
     * because time may have passed since the preview.
     * @param list<array{member_id:string,name:string,evidence:string,self:bool}> $ready
     * @return array{ok:bool, error?:string, awarded:int, pending:int, skipped:int, each:int}
     */
    public function bulkAward(array $ready, int $typeId, string $note, string $actorEmail, bool $actorIsOwner, string $token): array
    {
        $type = $this->activityType($typeId);
        $res = ['ok' => false, 'awarded' => 0, 'pending' => 0, 'skipped' => 0, 'each' => 0];
        if (!$type || !(int) $type['is_active']) return ['error' => 'That activity is not available.'] + $res;
        if (!$ready) return ['error' => 'There is nobody to award.'] + $res;

        $points = (int) $type['points'];
        $needsSecond = $points >= self::SECOND_APPROVAL_AT && !$actorIsOwner;
        $ref = 'bulk-' . substr(preg_replace('/[^a-z0-9]/i', '', $token), 0, 24);
        $struck = $this->struckMembers();
        $note = mb_substr(trim($note), 0, 500);
        $ids = [];
        $selfCount = 0;

        foreach (array_slice($ready, 0, self::BULK_MAX) as $r) {
            $mid = (string) $r['member_id'];
            $evidence = (string) ($r['evidence'] ?? '');
            if (isset($struck[$mid]) || $this->checkEvidence($type, $evidence) || $this->hasOneTime($type, $mid)) { $res['skipped']++; continue; }
            try {
                $made = $this->insertSourceAward($mid, $type, $points, 'standard', $ref, ' (bulk award)', $actorEmail, $actorIsOwner, $evidence, $note);
            } catch (Throwable $e) {
                $this->log('bulkAward failed for ' . $mid . ': ' . $e->getMessage());
                $made = false;
            }
            if (!$made) { $res['skipped']++; continue; }
            if ($needsSecond) $res['pending']++; else $res['awarded']++;
            $ids[] = $mid;
            if (!empty($r['self']) && $actorIsOwner) $selfCount++;
        }
        $res['ok'] = true;
        $res['each'] = $points;

        if ($ids) {
            $this->audit($actorEmail, 'goodies.bulk_award', count($ids) . ' member(s)',
                $type['name'] . ' (' . $points . ' pts each, ' . ($needsSecond ? 'awaiting second approval' : 'approved') . ($actorIsOwner ? ', owner award' : '') . '): ' . implode(' ', $ids));
            if ($selfCount) $this->audit($actorEmail, 'goodies.award_self', 'bulk award', $type['name'] . ' (' . $points . ' pts, included in a bulk award)');
        }
        return $res;
    }

    // ---- Conversation Hour awards ----------------------------------------------------------------
    //
    // Attendance is logged in Community records (ch_sessions, ch_attendance). Awarding reads it and never
    // changes it. For a session, "Award now" gives:
    //   - each member logged PRESENT (excused rows earn nothing) the ch_attend points, and
    //   - each host (ch_session_hosts) the ch_host points, or below_min_points when fewer than
    //     min_attendees others were present. A host gets the host award instead of the attend award.
    // A row is matched to a member by its Member ID, else by the member's VERIFIED Wikimedia username, the
    // same rule as CommunityRecordsModel. Rows that match nobody are listed, never guessed.
    // Each award carries source_ref = chRef(session); the unique key (member, activity, source_ref) means a
    // second run only adds people logged since, and a double click cannot award twice.
    // Skipped: the Member IDs passed as $ownIds (the page passes a non-owner reviewer's own IDs, so another
    // reviewer runs it for them; it passes none for an owner) and struck-off members.
    // When the actor is an owner, every award from the run is approved at once, whatever the size.

    /** source_ref for the awards of one Conversation Hour session. */
    public static function chRef(int $sessionId): string
    {
        return 'ch-session-' . $sessionId;
    }

    /** The most recent Conversation Hour sessions, newest first. */
    public function chSessions(int $limit = 12): array
    {
        return $this->pdo->query('SELECT id, title, held_on FROM ch_sessions ORDER BY held_on DESC, id DESC LIMIT ' . max(1, (int) $limit))
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Member IDs set as hosts of a session. */
    public function chHosts(int $sessionId): array
    {
        $st = $this->pdo->prepare('SELECT member_id FROM ch_session_hosts WHERE session_id = :s ORDER BY id');
        $st->execute([':s' => $sessionId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addChHost(int $sessionId, string $memberId, string $actorEmail): array
    {
        $memberId = strtoupper(trim($memberId));
        if (!$this->chSession($sessionId)) return ['ok' => false, 'error' => 'That session no longer exists.'];
        if (!isset($this->knownMembers()[$memberId])) return ['ok' => false, 'error' => 'No member was found with that Member ID.'];
        $st = $this->pdo->prepare('INSERT IGNORE INTO ch_session_hosts (session_id, member_id, added_by) VALUES (:s, :m, :a)');
        $st->execute([':s' => $sessionId, ':m' => $memberId, ':a' => $actorEmail]);
        if ($st->rowCount() > 0) $this->audit($actorEmail, 'goodies.ch_host_add', 'CH session #' . $sessionId, $memberId);
        return ['ok' => true];
    }

    /** Removing a host never takes back points already awarded; use "Take points back" for that. */
    public function removeChHost(int $sessionId, string $memberId, string $actorEmail): void
    {
        $st = $this->pdo->prepare('DELETE FROM ch_session_hosts WHERE session_id = :s AND member_id = :m');
        $st->execute([':s' => $sessionId, ':m' => strtoupper(trim($memberId))]);
        if ($st->rowCount() > 0) $this->audit($actorEmail, 'goodies.ch_host_remove', 'CH session #' . $sessionId, strtoupper(trim($memberId)));
    }

    /**
     * What "Award now" would do for a session, without doing it. $ownIds are Member IDs to leave for another
     * reviewer (a non-owner reviewer's own IDs; an owner passes none).
     * @return array{present:int, award:list<array{member_id:string, code:string, points:int}>, done:int,
     *               own:list<string>, struck:list<string>, unresolved:list<string>, gone:list<string>,
     *               hosts:list<string>, host_points:int, missing:list<string>}
     *   present     rows logged present for the session (matched or not)
     *   award       awards to make now
     *   done        awards already made for this session
     *   own         the reviewer's own Member IDs, left for another reviewer
     *   struck      struck-off members, skipped
     *   unresolved  present rows that match no member (no Member ID and no verified username)
     *   gone        awarded for attending, but no longer logged present: take the points back by hand if wrong
     *   missing     activity codes (ch_attend, ch_host) that are switched off or missing, so nothing is awarded for them
     */
    public function chPlan(int $sessionId, array $ownIds = []): array
    {
        $own = array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
        $plan = ['present' => 0, 'award' => [], 'done' => 0, 'own' => [], 'struck' => [], 'unresolved' => [],
                 'gone' => [], 'hosts' => $this->chHosts($sessionId), 'host_points' => 0, 'missing' => []];

        $st = $this->pdo->prepare("SELECT member_id, wikimedia_username FROM ch_attendance WHERE session_id = :s AND status = 'present'");
        $st->execute([':s' => $sessionId]);
        $attending = [];   // MEMBER ID => true
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plan['present']++;
            $mid = $this->resolveMember((string) $row['member_id'], (string) $row['wikimedia_username']);
            if ($mid === null) {
                $plan['unresolved'][] = trim((string) $row['member_id']) !== '' ? trim((string) $row['member_id']) : trim((string) $row['wikimedia_username']);
            } else {
                $attending[$mid] = true;
            }
        }
        $hosts = array_fill_keys($plan['hosts'], true);
        $attendees = array_diff_key($attending, $hosts);   // a host is awarded as host, not as attendee

        $types = [];
        foreach (['ch_attend', 'ch_host'] as $code) {
            $t = $this->activityTypeByCode($code);
            if ($t && (int) $t['is_active']) $types[$code] = $t; else $plan['missing'][] = $code;
        }
        if (isset($types['ch_host'])) {
            $t = $types['ch_host'];
            $others = $plan['present'] - count(array_intersect_key($attending, $hosts));
            $short = $t['min_attendees'] !== null && $others < (int) $t['min_attendees'] && $t['below_min_points'] !== null;
            $plan['host_points'] = $short ? (int) $t['below_min_points'] : (int) $t['points'];
            $plan['host_tier'] = $short ? 'below_min' : 'standard';
        }

        // Awards already made for this session: [activity_type_id][MEMBER ID] => true (any status: the unique key covers all).
        $st = $this->pdo->prepare('SELECT member_id, activity_type_id FROM point_awards WHERE source_ref = :r');
        $st->execute([':r' => self::chRef($sessionId)]);
        $made = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $made[(int) $r['activity_type_id']][strtoupper((string) $r['member_id'])] = true;

        $struck = $this->struckMembers();
        $want = [];
        if (isset($types['ch_attend'])) foreach (array_keys($attendees) as $m) $want[] = [$m, 'ch_attend', (int) $types['ch_attend']['points'], 'standard'];
        if (isset($types['ch_host'])) foreach (array_keys($hosts) as $m) $want[] = [$m, 'ch_host', $plan['host_points'], $plan['host_tier'] ?? 'standard'];
        foreach ($want as [$m, $code, $points, $tier]) {
            if (isset($made[(int) $types[$code]['id']][$m])) $plan['done']++;
            elseif (isset($own[$m])) $plan['own'][] = $m;
            elseif (isset($struck[$m])) $plan['struck'][] = $m;
            elseif ($points > 0) $plan['award'][] = ['member_id' => $m, 'code' => $code, 'points' => $points, 'tier' => $tier];
        }
        if (isset($types['ch_attend'])) {
            foreach (array_keys($made[(int) $types['ch_attend']['id']] ?? []) as $m) {
                if (!isset($attendees[$m])) $plan['gone'][] = $m;
            }
        }
        return $plan;
    }

    /**
     * Award everything chPlan() lists for a session. Safe to repeat: each award is unique per member,
     * activity and session, so a second run (or a double click) only adds what is new.
     *
     * $ownIds are left for another reviewer. $actorIsOwner is true when the page has checked that the actor
     * is an owner: every award from the run is then approved at once, even when it reaches
     * SECOND_APPROVAL_AT, so an owner is never stuck waiting for a second reviewer.
     * @return array{ok:bool, error?:string, awarded?:int, plan?:array}
     */
    public function awardForSession(int $sessionId, string $actorEmail, array $ownIds = [], bool $actorIsOwner = false): array
    {
        $session = $this->chSession($sessionId);
        if (!$session) return ['ok' => false, 'error' => 'That session no longer exists.'];

        $lock = 'dcw_ch_award_' . $sessionId;
        $got = $this->pdo->prepare('SELECT GET_LOCK(:l, 10)');
        $got->execute([':l' => $lock]);
        if ((int) $got->fetchColumn() !== 1) return ['ok' => false, 'error' => 'Another reviewer is awarding this session right now. Try again in a moment.'];

        try {
            $plan = $this->chPlan($sessionId, $ownIds);
            $label = ' (' . $session['title'] . ', ' . date('j M Y', strtotime((string) $session['held_on'])) . ')';
            $awarded = 0;
            foreach ($plan['award'] as $a) {
                $type = $this->activityTypeByCode($a['code']);
                if ($type && $this->insertSourceAward($a['member_id'], $type, $a['points'], $a['tier'], self::chRef($sessionId), $label, $actorEmail, $actorIsOwner)) $awarded++;
            }
        } finally {
            $rel = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
            $rel->execute([':l' => $lock]);
        }

        if ($awarded > 0) {
            $this->audit($actorEmail, 'goodies.ch_award', 'CH session #' . $sessionId, $awarded . ' award(s): ' . $session['title'] . ($actorIsOwner ? ' (owner award)' : ''));
        }
        return ['ok' => true, 'awarded' => $awarded, 'plan' => $plan];
    }

    /**
     * One automatic award and, when it needs no second reviewer, its ledger row. False if it already exists.
     * $autoApprove skips the second-approval rule (the actor is an owner).
     * $evidence and $noteOverride are for bulk awards and claims: the evidence link, and a note to store instead of the label.
     * Public so PointClaimModel can turn an approved claim into an award with the same rules as every other award.
     */
    public function insertSourceAward(string $memberId, array $type, int $points, string $tier, string $sourceRef, string $label, string $actorEmail, bool $autoApprove = false, string $evidence = '', string $noteOverride = ''): bool
    {
        $needsSecond = $points >= self::SECOND_APPROVAL_AT && !$autoApprove;
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO point_awards (member_id, activity_type_id, source_ref, points, tier, evidence_url, note, status, created_by_email, first_reviewer_email, decided_at)
                 VALUES (:m, :t, :r, :p, :tier, :e, :n, :s, :a, :a2, :d)'
            );
            $st->execute([
                ':m' => $memberId, ':t' => (int) $type['id'], ':r' => $sourceRef, ':p' => $points, ':tier' => $tier,
                ':e' => $evidence !== '' ? $evidence : null,
                ':n' => mb_substr($noteOverride !== '' ? $noteOverride : ltrim($label, ' '), 0, 500), ':s' => $needsSecond ? 'Needs Second Approval' : 'Approved',
                ':a' => $actorEmail, ':a2' => $actorEmail, ':d' => $needsSecond ? null : gmdate('Y-m-d H:i:s'),
            ]);
            if (!$needsSecond) {
                $this->writeAwardLedger((int) $this->pdo->lastInsertId(), $memberId, $points, $type['name'] . $label, $actorEmail);
            }
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1062) return false;   // already awarded (another run got there first)
            throw $e;
        }
    }

    private function chSession(int $sessionId): ?array
    {
        $st = $this->pdo->prepare('SELECT id, title, held_on FROM ch_sessions WHERE id = :id');
        $st->execute([':id' => $sessionId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The Member ID an attendance row belongs to, or null. Same rule as CommunityRecordsModel. */
    private function resolveMember(string $memberId, string $username): ?string
    {
        $memberId = strtoupper(trim($memberId));
        if ($memberId !== '' && isset($this->knownMembers()[$memberId])) return $memberId;
        $username = mb_strtolower(trim($username));
        if ($username !== '') return $this->verifiedUsers()[$username] ?? null;
        return null;
    }

    /**
     * Find a member from what a reviewer typed: a Member ID, a verified Wikimedia username (with or without
     * "User:"), or a full name (ignoring case and extra spaces). Several people with the same name, or only
     * partial matches, are listed back instead of guessed. Matching is done in PHP, like the rest of this class.
     * @return array{member_id?:string, name?:string, error?:string}
     */
    public function findMember(string $typed): array
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $typed));
        $q = trim((string) preg_replace('/^(user:|@)/i', '', $q));
        if ($q === '') return ['error' => 'Enter a name, Wikimedia username or Member ID.'];

        $dir = $this->memberDirectory();
        $id = strtoupper($q);
        if (isset($dir[$id])) return ['member_id' => $id, 'name' => $dir[$id]['name']];
        $byUser = $this->verifiedUsers()[mb_strtolower($q)] ?? null;
        if ($byUser !== null && isset($dir[$byUser])) return ['member_id' => $byUser, 'name' => $dir[$byUser]['name']];

        $key = mb_strtolower($q);
        $exact = $near = [];
        foreach ($dir as $mid => $m) {
            $name = mb_strtolower($m['name']);
            if ($name === $key) {
                $exact[$mid] = $m;
            } elseif (($name !== '' && mb_strpos($name, $key) !== false)
                   || ($m['username'] !== '' && mb_strpos(mb_strtolower($m['username']), $key) !== false)) {
                $near[$mid] = $m;
            }
        }
        $list = fn(array $rows) => implode(', ', array_map(fn($mid, $m) => $m['name'] . ' (' . $mid . ')', array_keys($rows), $rows));
        if (count($exact) === 1) {
            $mid = (string) array_key_first($exact);
            return ['member_id' => $mid, 'name' => $exact[$mid]['name']];
        }
        if ($exact) return ['error' => 'More than one member is called "' . $q . '": ' . $list(array_slice($exact, 0, 8, true)) . '. Enter the Member ID of the right one.'];
        if ($near && count($near) <= 8) return ['error' => 'No exact match for "' . $q . '". Did you mean ' . $list($near) . '? Enter the full name or the Member ID.'];
        if ($near) return ['error' => count($near) . ' members match "' . $q . '". Type more of the name, or the Member ID.'];
        return ['error' => 'No member found for "' . $q . '". Try the full name, the verified Wikimedia username or the Member ID.'];
    }

    /**
     * Every member once: [MEMBER ID => ['name' => full name, 'username' => verified Wikimedia username or '']],
     * sorted by name. For findMember() and the reviewer's type-ahead list.
     */
    public function memberDirectory(): array
    {
        if ($this->directory === null) {
            $users = array_flip($this->verifiedUsers());   // MEMBER ID => lower-case username
            $dir = [];
            foreach ($this->pdo->query('SELECT member_id, full_name FROM members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mid = strtoupper(trim((string) $r['member_id']));
                if ($mid === '' || isset($dir[$mid])) continue;
                $dir[$mid] = ['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $r['full_name'])), 'username' => ''];
            }
            if ($users) {
                // The username as the member wrote it (verifiedUsers() keeps it lower-case for matching).
                foreach ($this->pdo->query('SELECT member_id, wikimedia_username FROM member_profile WHERE username_verified_at IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $mid = strtoupper((string) $r['member_id']);
                    if (isset($dir[$mid], $users[$mid])) $dir[$mid]['username'] = trim((string) $r['wikimedia_username']);
                }
            }
            uasort($dir, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $this->directory = $dir;
        }
        return $this->directory;
    }

    private function knownMembers(): array
    {
        if ($this->knownMembers === null) {
            $this->knownMembers = [];
            foreach ($this->pdo->query('SELECT DISTINCT member_id FROM members')->fetchAll(PDO::FETCH_COLUMN) as $m) {
                $this->knownMembers[strtoupper((string) $m)] = true;
            }
        }
        return $this->knownMembers;
    }

    private function verifiedUsers(): array
    {
        if ($this->verifiedUsers === null) {
            $this->verifiedUsers = [];
            $rows = $this->pdo->query('SELECT member_id, wikimedia_username FROM member_profile WHERE username_verified_at IS NOT NULL ORDER BY member_id')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $u = mb_strtolower(trim((string) $r['wikimedia_username']));
                if ($u !== '' && !isset($this->verifiedUsers[$u])) $this->verifiedUsers[$u] = strtoupper((string) $r['member_id']);
            }
        }
        return $this->verifiedUsers;
    }

    private function struckMembers(): array
    {
        if ($this->struckMembers === null) {
            $this->struckMembers = [];
            foreach ((new MemberStrikeModel($this->pdo))->activeMap() as $m => $row) $this->struckMembers[strtoupper((string) $m)] = $row;
        }
        return $this->struckMembers;
    }

    // ---- Activities (what can earn points, and how many) ---------------------------------------
    //
    // Managed on /admin/goodies?tab=activities, so nothing about points is hard-coded (#208).
    // Changing an activity's points REVISES every award already made for it: each award's points are set to
    // the new value, and each member's balance moves by the difference through a 'revaluation' ledger row
    // (the ledger stays append-only). Awards waiting for a second reviewer are revised too; rejected ones
    // are left alone. A Conversation Hour host award keeps its tier: one made under the minimum attendance
    // follows below_min_points. Bulk edits (savePointChanges) behave exactly the same, for many activities
    // in one transaction.

    /** Approved and waiting awards per activity: [activity_type_id => count]. */
    public function activityUsage(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT activity_type_id, COUNT(*) AS n FROM point_awards WHERE status <> 'Rejected' GROUP BY activity_type_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['activity_type_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Create ($id = null) or update an activity. $in holds the form fields: name, category, points,
     * needs_evidence, one_time, min_attendees, below_min_points, is_active, member_claimable and
     * cooldown_days (a member can claim it again only after this many days).
     * @return array{ok:bool, error?:string, revised?:int, members?:int, change?:int}
     */
    public function saveActivity(?int $id, array $in, string $actorEmail): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        $category = (string) ($in['category'] ?? '');
        $points = (int) ($in['points'] ?? 0);
        $minRaw = trim((string) ($in['min_attendees'] ?? ''));
        $belowRaw = trim((string) ($in['below_min_points'] ?? ''));
        $coolRaw = trim((string) ($in['cooldown_days'] ?? ''));
        $min = $minRaw === '' ? null : (int) $minRaw;
        $below = $belowRaw === '' ? null : (int) $belowRaw;
        $cool = $coolRaw === '' ? null : (int) $coolRaw;

        if ($name === '' || mb_strlen($name) > 160) return ['ok' => false, 'error' => 'Give the activity a name (up to 160 characters).'];
        if (!isset(self::CATEGORIES[$category])) return ['ok' => false, 'error' => 'Choose a category.'];
        if ($points < 1 || $points > 10000) return ['ok' => false, 'error' => 'Points must be between 1 and 10000.'];
        if (($min === null) !== ($below === null)) return ['ok' => false, 'error' => 'Set both the minimum attendance and the points below it, or neither.'];
        if ($min !== null && ($min < 1 || $min > 1000)) return ['ok' => false, 'error' => 'The minimum attendance must be between 1 and 1000.'];
        if ($below !== null && ($below < 0 || $below >= $points)) return ['ok' => false, 'error' => 'Points below the minimum must be less than the full points.'];
        if ($cool !== null && ($cool < 1 || $cool > 365)) return ['ok' => false, 'error' => 'The cooldown must be between 1 and 365 days.'];
        if ($cool !== null && !empty($in['one_time'])) return ['ok' => false, 'error' => 'An activity cannot be both once-only and repeatable with a cooldown. Clear one of them.'];

        $fields = [
            ':n' => $name, ':c' => $category, ':p' => $points,
            ':e' => !empty($in['needs_evidence']) ? 1 : 0, ':o' => !empty($in['one_time']) ? 1 : 0,
            ':min' => $min, ':below' => $below, ':act' => !empty($in['is_active']) ? 1 : 0,
            ':claim' => !empty($in['member_claimable']) ? 1 : 0,   // members may claim it (needs the member_claimable column)
            ':cool' => $cool,
        ];

        if (!$id) {
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'activity';
            $base = substr($base, 0, 34);
            $code = $base;
            for ($i = 2; $this->activityTypeByCode($code); $i++) $code = $base . '_' . $i;
            try {
                $st = $this->pdo->prepare(
                    'INSERT INTO activity_types (code, name, category, points, needs_evidence, one_time, min_attendees, below_min_points, is_active, member_claimable, cooldown_days)
                     VALUES (:code, :n, :c, :p, :e, :o, :min, :below, :act, :claim, :cool)'
                );
                $st->execute($fields + [':code' => $code]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) return ['ok' => false, 'error' => 'An activity with that name already exists.'];
                throw $e;
            }
            $this->audit($actorEmail, 'goodies.activity_add', $name, $points . ' pts');
            return ['ok' => true, 'revised' => 0, 'members' => 0, 'change' => 0];
        }

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM activity_types WHERE id = :id FOR UPDATE');
            $st->execute([':id' => $id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'That activity no longer exists.'];
            }
            $st = $this->pdo->prepare(
                'UPDATE activity_types SET name = :n, category = :c, points = :p, needs_evidence = :e, one_time = :o,
                        min_attendees = :min, below_min_points = :below, is_active = :act, member_claimable = :claim,
                        cooldown_days = :cool WHERE id = :id'
            );
            $st->execute($fields + [':id' => $id]);

            $rev = ['revised' => 0, 'members' => 0, 'change' => 0];
            if ($points !== (int) $old['points'] || $below !== ($old['below_min_points'] === null ? null : (int) $old['below_min_points'])) {
                $rev = $this->revalue($id, $name, $points, $below, $actorEmail);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('saveActivity failed: ' . $e->getMessage());
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) return ['ok' => false, 'error' => 'An activity with that name already exists.'];
            return ['ok' => false, 'error' => 'Could not save the activity. Nothing was changed.'];
        }
        $this->audit($actorEmail, 'goodies.activity_edit', $name,
            $old['points'] . ' -> ' . $points . ' pts' . ($rev['revised'] ? ', ' . $rev['revised'] . ' award(s) revised (' . sprintf('%+d', $rev['change']) . ' pts)' : ''));
        return ['ok' => true] + $rev;
    }

    /**
     * Bulk edit, step 1: what changing the points of several activities would do, without doing it.
     * $changes is [activity id => ['points' => int, 'below' => ?int]]; unchanged activities are ignored.
     * Past awards ARE revised on save (see above), so the preview counts them.
     * @return array{rows:list<array>, changes:array, errors:list<string>, awards:int, members:int, net:int, negative:int}
     *   rows      one per changed activity: id, name, old, new, revised (awards), members, net (points), crosses
     *   changes   the cleaned changes to pass to savePointChanges()
     *   negative  members whose balance would end up below zero because of a cut
     *   crosses   the activity now reaches SECOND_APPROVAL_AT: past awards are revised without a second reviewer
     */
    public function previewPointChanges(array $changes): array
    {
        $out = ['rows' => [], 'changes' => [], 'errors' => [], 'awards' => 0, 'members' => 0, 'net' => 0, 'negative' => 0];
        $memberNet = [];
        foreach ($changes as $id => $c) {
            $t = $this->activityType((int) $id);
            if (!$t) { $out['errors'][] = 'An activity no longer exists.'; continue; }
            $name = (string) $t['name'];
            $points = (int) ($c['points'] ?? 0);
            $oldPoints = (int) $t['points'];
            $oldBelow = $t['below_min_points'] === null ? null : (int) $t['below_min_points'];
            $below = $oldBelow === null ? null : (isset($c['below']) && $c['below'] !== '' ? (int) $c['below'] : null);

            if ($points < 1 || $points > 10000) { $out['errors'][] = $name . ': points must be between 1 and 10000.'; continue; }
            if ($oldBelow !== null && $below === null) { $out['errors'][] = $name . ': enter the points under the minimum too.'; continue; }
            if ($below !== null && ($below < 0 || $below >= $points)) { $out['errors'][] = $name . ': points under the minimum must be less than the full points.'; continue; }
            if ($points === $oldPoints && $below === $oldBelow) continue;

            $st = $this->pdo->prepare("SELECT member_id, points, tier, status FROM point_awards WHERE activity_type_id = :t AND status <> 'Rejected'");
            $st->execute([':t' => (int) $id]);
            $revised = 0; $net = 0; $mem = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $new = ($a['tier'] === 'below_min' && $below !== null) ? $below : $points;
                $diff = $new - (int) $a['points'];
                if ($diff === 0) continue;
                $revised++;
                if ($a['status'] === 'Approved') {
                    $net += $diff;
                    $m = strtoupper((string) $a['member_id']);
                    $mem[$m] = true;
                    $memberNet[$m] = ($memberNet[$m] ?? 0) + $diff;
                }
            }
            $out['rows'][] = [
                'id' => (int) $id, 'name' => $name, 'old' => $oldPoints, 'new' => $points,
                'revised' => $revised, 'members' => count($mem), 'net' => $net,
                'crosses' => $points >= self::SECOND_APPROVAL_AT && $oldPoints < self::SECOND_APPROVAL_AT && $revised > 0,
            ];
            $out['changes'][(int) $id] = ['points' => $points, 'below' => $below];
            $out['awards'] += $revised;
            $out['net'] += $net;
        }
        $out['members'] = count($memberNet);

        // Members a cut would push below zero (their points may already have been spent).
        $cutIds = array_keys(array_filter($memberNet, fn($n) => $n < 0));
        foreach (array_chunk($cutIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare("SELECT member_id, COALESCE(SUM(points), 0) AS bal FROM points_ledger WHERE member_id IN ($in) GROUP BY member_id");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int) $r['bal'] + $memberNet[strtoupper((string) $r['member_id'])] < 0) $out['negative']++;
            }
        }
        return $out;
    }

    /**
     * Bulk edit, step 2: save the new points of several activities in ONE transaction, revising past awards
     * exactly as saveActivity() does. All or nothing. Only points (and the host "under the minimum" points) change.
     * @return array{ok:bool, error?:string, activities?:int, revised?:int, members?:int, change?:int}
     */
    public function savePointChanges(array $changes, string $actorEmail): array
    {
        $pv = $this->previewPointChanges($changes);
        if ($pv['errors']) return ['ok' => false, 'error' => implode(' ', $pv['errors'])];
        if (!$pv['changes']) return ['ok' => false, 'error' => 'Nothing has changed.'];

        $revised = 0; $change = 0; $names = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($pv['changes'] as $id => $c) {
                $st = $this->pdo->prepare('SELECT name FROM activity_types WHERE id = :id FOR UPDATE');
                $st->execute([':id' => $id]);
                $name = $st->fetchColumn();
                if ($name === false) throw new RuntimeException('Activity ' . $id . ' no longer exists.');
                $up = $this->pdo->prepare('UPDATE activity_types SET points = :p, below_min_points = :b WHERE id = :id');
                $up->execute([':p' => $c['points'], ':b' => $c['below'], ':id' => $id]);
                $rev = $this->revalue((int) $id, (string) $name, (int) $c['points'], $c['below'], $actorEmail);
                $revised += $rev['revised']; $change += $rev['change'];
                $names[] = $name;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('savePointChanges failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save. Nothing was changed.'];
        }
        foreach ($pv['rows'] as $row) {
            $this->audit($actorEmail, 'goodies.activity_edit', $row['name'], $row['old'] . ' -> ' . $row['new'] . ' pts (bulk edit), ' . $row['revised'] . ' award(s) revised (' . sprintf('%+d', $row['net']) . ' pts)');
        }
        $this->audit($actorEmail, 'goodies.activity_bulk_edit', count($pv['changes']) . ' activities',
            $revised . ' award(s) revised, ' . $pv['members'] . ' member(s), ' . sprintf('%+d', $change) . ' pts in total');
        return ['ok' => true, 'activities' => count($pv['changes']), 'revised' => $revised, 'members' => $pv['members'], 'change' => $change];
    }

    /**
     * Inside saveActivity()'s transaction: set every non-rejected award of this activity to the new value,
     * and move approved awards' balances by the difference with one 'revaluation' ledger row each.
     */
    private function revalue(int $typeId, string $name, int $points, ?int $below, string $actorEmail): array
    {
        $st = $this->pdo->prepare("SELECT id, member_id, points, tier, status FROM point_awards
                                    WHERE activity_type_id = :t AND status <> 'Rejected' FOR UPDATE");
        $st->execute([':t' => $typeId]);
        $up = $this->pdo->prepare('UPDATE point_awards SET points = :p WHERE id = :id');
        $lg = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, reason, actor_email)
                                   VALUES (:m, :p, 'revaluation', :r, :a)");
        $revised = 0; $change = 0; $members = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $new = ($a['tier'] === 'below_min' && $below !== null) ? $below : $points;
            $diff = $new - (int) $a['points'];
            if ($diff === 0) continue;
            $up->execute([':p' => $new, ':id' => $a['id']]);
            $revised++;
            if ($a['status'] === 'Approved') {
                $lg->execute([
                    ':m' => $a['member_id'], ':p' => $diff, ':a' => $actorEmail,
                    ':r' => mb_substr('Points revised: ' . $name . ' (' . $a['points'] . ' to ' . $new . ')', 0, 255),
                ]);
                $change += $diff;
                $members[$a['member_id']] = true;
            }
        }
        return ['revised' => $revised, 'members' => count($members), 'change' => $change];
    }

    /** Delete an activity that has never been awarded. Built-in ones, and used ones, can only be switched off. */
    public function deleteActivity(int $id, string $actorEmail): array
    {
        $t = $this->activityType($id);
        if (!$t) return ['ok' => false, 'error' => 'That activity no longer exists.'];
        if (in_array((string) $t['code'], self::BUILT_IN_CODES, true)) {
            return ['ok' => false, 'error' => '"' . $t['name'] . '" is used by the app itself, so it can only be switched off.'];
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM point_awards WHERE activity_type_id = :t');
        $st->execute([':t' => $id]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'error' => '"' . $t['name'] . '" has been awarded before, so it stays on record. Switch it off instead.'];
        }
        $this->pdo->prepare('DELETE FROM activity_types WHERE id = :id')->execute([':id' => $id]);
        $this->audit($actorEmail, 'goodies.activity_delete', (string) $t['name'], (string) $t['points'] . ' pts');
        return ['ok' => true];
    }

    // ---- Catalogue -----------------------------------------------------------------------------

    /** goodies.stock_json as [variant or STOCK_ALL => qty], or null when stock is not tracked. */
    public static function decodeStock($json): ?array
    {
        if ($json === null || $json === '' || $json === false) return null;
        $a = json_decode((string) $json, true);
        if (!is_array($a)) return null;
        return array_map(fn($n) => max(0, (int) $n), $a);
    }

    /** Items left for one variant ('' when the goody has no sizes). Null = stock is not tracked. */
    public static function stockFor(array $goody, string $variant = ''): ?int
    {
        $s = $goody['stock'] ?? null;
        if ($s === null) return null;
        $key = !empty($goody['variants']) ? $variant : self::STOCK_ALL;
        return (int) ($s[$key] ?? 0);
    }

    /** False only when stock is tracked and nothing is left in any size. */
    public static function inStock(array $goody): bool
    {
        $s = $goody['stock'] ?? null;
        return $s === null || array_sum($s) > 0;
    }

    /** Each goody comes with 'variants', 'stock' (null = not tracked), 'images' (the first is the cover) and 'max_per_order' (null = no limit). */
    public function goodies(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM goodies' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY points_cost, name';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['variants'] = $r['variants_json'] ? (json_decode((string) $r['variants_json'], true) ?: []) : [];
            $r['stock'] = self::decodeStock($r['stock_json'] ?? null);
            $r['images'] = GoodyImages::decode($r['images_json'] ?? null);
            $r['max_per_order'] = ($r['max_per_order'] ?? null) === null ? null : max(1, (int) $r['max_per_order']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Create ($id = null) or update a goody. $variantsCsv is "S, M, L".
     * $stock is null when stock is not tracked, otherwise [size => quantity] (or [STOCK_ALL => quantity] when
     * the goody has no sizes). Sizes missing from $stock count as 0.
     * $maxPerOrder is the most units one order may hold (1 to 99), or null for no limit.
     * Pictures are saved separately (setGoodyImages), so this never changes them.
     * @return array{ok:bool, error?:string, id?:int}
     */
    public function saveGoody(?int $id, string $name, string $description, int $cost, string $variantsCsv, bool $active, string $actorEmail, ?array $stock = null, ?int $maxPerOrder = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Give the goody a name (up to 120 characters).'];
        if ($cost < 1 || $cost > 100000) return ['ok' => false, 'error' => 'The cost must be between 1 and 100000 points.'];
        $variants = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim($v), 0, 60), explode(',', $variantsCsv)), fn($v) => $v !== '')));
        $json = $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null;
        $desc = mb_substr(trim($description), 0, 500);
        $cap = $maxPerOrder === null ? null : max(1, min(99, $maxPerOrder));

        $stockJson = null;
        if ($stock !== null) {
            $clean = [];
            if ($variants) {
                foreach ($variants as $v) $clean[$v] = max(0, min(1000000, (int) ($stock[$v] ?? 0)));
            } else {
                $clean[self::STOCK_ALL] = max(0, min(1000000, (int) ($stock[self::STOCK_ALL] ?? 0)));
            }
            $stockJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }

        try {
            if ($id) {
                $st = $this->pdo->prepare('UPDATE goodies SET name = :n, description = :d, points_cost = :c, variants_json = :v, stock_json = :k, max_per_order = :mx, is_active = :a WHERE id = :id');
                $st->execute([':n' => $name, ':d' => $desc, ':c' => $cost, ':v' => $json, ':k' => $stockJson, ':mx' => $cap, ':a' => $active ? 1 : 0, ':id' => $id]);
                $goodyId = $id;
            } else {
                $st = $this->pdo->prepare('INSERT INTO goodies (name, description, points_cost, variants_json, stock_json, max_per_order, is_active) VALUES (:n, :d, :c, :v, :k, :mx, :a)');
                $st->execute([':n' => $name, ':d' => $desc, ':c' => $cost, ':v' => $json, ':k' => $stockJson, ':mx' => $cap, ':a' => $active ? 1 : 0]);
                $goodyId = (int) $this->pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            // goodies.name is unique (uniq_goody_name). Any other database error goes up to the page as usual.
            if (($e->errorInfo[1] ?? 0) === 1062) return ['ok' => false, 'error' => 'A goody with that name already exists.'];
            throw $e;
        }
        $this->audit($actorEmail, $id ? 'goodies.catalogue_edit' : 'goodies.catalogue_add', $name,
            $cost . ' pts, ' . ($active ? 'active' : 'inactive') . ', stock ' . ($stockJson ?? 'not tracked') . ', max per order ' . ($cap ?? 'none'));
        return ['ok' => true, 'id' => $goodyId];
    }

    /** The pictures saved for a goody, in display order (the first is the cover). */
    public function goodyImages(int $id): array
    {
        $st = $this->pdo->prepare('SELECT images_json FROM goodies WHERE id = :id');
        $st->execute([':id' => $id]);
        return GoodyImages::decode($st->fetchColumn());
    }

    /**
     * Replace a goody's picture list. The caller has already stored the new files (GoodyImages::process) and
     * deletes the dropped ones only after this succeeds.
     */
    public function setGoodyImages(int $id, array $images, string $actorEmail): void
    {
        $st = $this->pdo->prepare('UPDATE goodies SET images_json = :j WHERE id = :id');
        $st->execute([':j' => GoodyImages::encode($images), ':id' => $id]);
        $this->audit($actorEmail, 'goodies.catalogue_images', '#' . $id, count($images) . ' picture(s)');
    }

    // ---- Redemptions ---------------------------------------------------------------------------
    //
    // An order is ONE row in redemptions: one GD- tracking ID, one address, one status, one parcel. Its goodies are
    // lines in redemption_items. Points are debited once for the whole order (ledger refund_id 0).
    // Part of an order can be refunded (a goody that is out of stock, say) while it is Requested or Ordered: that is a
    // row in redemption_refunds (units, points, reason) with its own ledger row (refund_id = that row's id), and
    // the rest of the order ships. When nothing is left the order becomes Cancelled by itself.
    // Each goody may have a "max per order" (goodies.max_per_order), counted across all its sizes.

    /** One goody, one unit: the old single-item call, now a cart of one. */
    public function redeem(string $memberId, int $goodyId, string $variant, array $shipping): array
    {
        return $this->redeemCart($memberId, [['goody_id' => $goodyId, 'variant' => $variant, 'qty' => 1]], $shipping);
    }

    /**
     * A member spends points on a cart. Prices, stock, limits and the balance are all read here, never taken from the
     * browser. All or nothing: one order and one debit, or no change at all.
     * @param list<array{goody_id:int, variant:string, qty:int}> $cart
     * @return array{ok:bool, error?:string, tracking_id?:string, units?:int}
     */
    public function redeemCart(string $memberId, array $cart, array $shipping): array
    {
        $want = [];   // "goodyId|variant" => the line, repeated lines merged
        foreach ($cart as $c) {
            if (!is_array($c)) continue;
            $gid = (int) ($c['goody_id'] ?? 0);
            $qty = (int) ($c['qty'] ?? 0);
            $var = trim((string) ($c['variant'] ?? ''));
            if ($gid < 1 || $qty < 1) continue;
            $k = $gid . '|' . $var;
            $want[$k] = ['goody_id' => $gid, 'variant' => $var, 'qty' => ($want[$k]['qty'] ?? 0) + $qty];
        }
        if (!$want) return ['ok' => false, 'error' => 'Your cart is empty.'];
        if (count($want) > self::MAX_LINES) return ['ok' => false, 'error' => 'Please keep an order to ' . self::MAX_LINES . ' different goodies or sizes.'];
        $units = [];  // goody id => units across all sizes
        foreach ($want as $w) $units[$w['goody_id']] = ($units[$w['goody_id']] ?? 0) + $w['qty'];

        // A per-member advisory lock: two simultaneous clicks queue up instead of both seeing the old balance.
        $lock = 'dcw_pts_' . md5($memberId);
        $got = $this->pdo->prepare('SELECT GET_LOCK(:l, 5)');
        $got->execute([':l' => $lock]);
        if ((int) $got->fetchColumn() !== 1) return ['ok' => false, 'error' => 'Please try again in a moment.'];

        try {
            $this->pdo->beginTransaction();
            $fail = function (string $msg): array {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                return ['ok' => false, 'error' => $msg];
            };
            try {
                // Stock is shared by every member, so lock the goody rows (in id order, so two carts cannot deadlock).
                $ids = array_keys($units);
                sort($ids);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $this->pdo->prepare("SELECT * FROM goodies WHERE id IN ($in) ORDER BY id FOR UPDATE");
                $st->execute($ids);
                $goodies = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) $goodies[(int) $g['id']] = $g;

                $total = 0; $lines = []; $stocks = []; $touched = []; $unitCount = 0;
                foreach ($want as $w) {
                    $g = $goodies[$w['goody_id']] ?? null;
                    if (!$g || !(int) $g['is_active']) return $fail('A goody in your cart is no longer available. Please check your cart.');
                    $gid = (int) $g['id'];
                    $variants = $g['variants_json'] ? (json_decode((string) $g['variants_json'], true) ?: []) : [];
                    $variant = $w['variant'];
                    if ($variants) {
                        if (!in_array($variant, $variants, true)) return $fail('Please choose a size or option for ' . $g['name'] . '.');
                    } else {
                        $variant = '';
                    }
                    $label = self::itemLabel((string) $g['name'], $variant);
                    $cap = ($g['max_per_order'] ?? null) === null ? self::MAX_UNITS : (int) $g['max_per_order'];
                    if ($units[$gid] > $cap) return $fail('You can order at most ' . $cap . ' of "' . $g['name'] . '" in one order.');

                    if (!array_key_exists($gid, $stocks)) $stocks[$gid] = self::decodeStock($g['stock_json'] ?? null);
                    if ($stocks[$gid] !== null) {
                        $key = $variants ? $variant : self::STOCK_ALL;
                        $left = (int) ($stocks[$gid][$key] ?? 0);
                        if ($left < $w['qty']) {
                            return $fail($left < 1 ? 'Sorry, ' . $label . ' is out of stock. Nothing was spent.'
                                                   : 'Only ' . $left . ' of ' . $label . ' left. Nothing was spent.');
                        }
                        $stocks[$gid][$key] = $left - $w['qty'];
                        $touched[$gid] = true;
                    }
                    $each = (int) $g['points_cost'];
                    $total += $each * $w['qty'];
                    $unitCount += $w['qty'];
                    $lines[] = ['goody_id' => $gid, 'variant' => $variant, 'qty' => $w['qty'], 'points_each' => $each, 'name' => (string) $g['name']];
                }
                if ($this->balance($memberId) < $total) return $fail('You do not have enough points for this order. Nothing was spent.');

                foreach (array_keys($touched) as $gid) {
                    $this->pdo->prepare('UPDATE goodies SET stock_json = :s WHERE id = :id')
                        ->execute([':s' => json_encode($stocks[$gid], JSON_UNESCAPED_UNICODE), ':id' => $gid]);
                }

                $tracking = $this->insertRedemption($memberId, $total, $shipping);
                $rid = (int) $this->pdo->lastInsertId();
                $ins = $this->pdo->prepare('INSERT INTO redemption_items (redemption_id, goody_id, variant, qty, points_each) VALUES (:r, :g, :v, :q, :p)');
                foreach ($lines as $l) {
                    $ins->execute([':r' => $rid, ':g' => $l['goody_id'], ':v' => $l['variant'] !== '' ? $l['variant'] : null, ':q' => $l['qty'], ':p' => $l['points_each']]);
                }
                $summary = self::orderSummary($lines);
                $lg = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, redemption_id, refund_id, reason, actor_email) VALUES (:m, :p, 'redemption', :r, 0, :why, :a)");
                $lg->execute([':m' => $memberId, ':p' => -$total, ':r' => $rid, ':why' => mb_substr('Redeemed: ' . $summary . ' (' . $tracking . ')', 0, 255), ':a' => $memberId]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $this->log('redeemCart failed: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'Could not place the order. Your points have not been spent.'];
            }
            // The member's own action, so the actor is the member (no staff account is involved).
            $this->audit('member ' . $memberId, 'goodies.redeem', $tracking, $summary . ', ' . $unitCount . ' unit(s), ' . $total . ' pts');
            $this->mailMember($memberId, 'sendOrderPlaced', [$tracking, array_map(fn($l) => ['name' => $l['name'], 'variant' => $l['variant'], 'qty' => $l['qty']], $lines), $total]);
            return ['ok' => true, 'tracking_id' => $tracking, 'units' => $unitCount];
        } finally {
            $rel = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
            $rel->execute([':l' => $lock]);
        }
    }

    /** Inserts the order under a fresh public ID (GD- plus 8 characters, unrelated to the row id). */
    private function insertRedemption(string $memberId, int $cost, array $shipping): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I/L
        for ($try = 0; $try < 5; $try++) {
            $id = 'GD-';
            for ($i = 0; $i < 8; $i++) $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            try {
                $st = $this->pdo->prepare('INSERT INTO redemptions (tracking_id, member_id, goody_id, variant, points_spent, shipping_json) VALUES (:t, :m, NULL, NULL, :c, :s)');
                $st->execute([':t' => $id, ':m' => $memberId, ':c' => $cost, ':s' => json_encode($shipping, JSON_UNESCAPED_UNICODE)]);
                return $id;
            } catch (PDOException $e) {
                if ($try === 4 || (string) $e->getCode() !== '23000') throw $e;   // only retry a duplicate tracking ID
            }
        }
        throw new RuntimeException('Could not generate a tracking ID.');
    }

    /** "T-shirt (M) ×2": a goody, its size and (above one) the units. */
    public static function itemLabel(string $name, string $variant = '', int $qty = 1): string
    {
        return $name . ($variant !== '' ? ' (' . $variant . ')' : '') . ($qty > 1 ? ' ×' . $qty : '');
    }

    /** One line for an order: "T-shirt (M)", or "T-shirt (M) +2 more". Each item has name, variant and qty. */
    public static function orderSummary(array $items): string
    {
        $items = array_values($items);
        if (!$items) return 'Order';
        $first = self::itemLabel((string) $items[0]['name'], (string) ($items[0]['variant'] ?? ''), (int) ($items[0]['qty'] ?? 1));
        return count($items) > 1 ? $first . ' +' . (count($items) - 1) . ' more' : $first;
    }

    /**
     * Adds to each order: 'items' (name, variant, qty, qty_refunded, qty_open, points_each, stock_left, refunds),
     * 'goody_name' (a one-line summary, kept so older pages keep working), 'refunded_points' and 'net_points'.
     */
    private function attachItems(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $it = $this->pdo->prepare("SELECT i.id, i.redemption_id, i.goody_id, i.variant, i.qty, i.qty_refunded, i.points_each,
                                          g.name, g.variants_json, g.stock_json
                                     FROM redemption_items i JOIN goodies g ON g.id = i.goody_id
                                    WHERE i.redemption_id IN ($in) ORDER BY i.id");
        $it->execute($ids);
        $items = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $stock = self::decodeStock($i['stock_json'] ?? null);
            $hasVariants = !empty($i['variants_json']) && (json_decode((string) $i['variants_json'], true) ?: []);
            $i['id'] = (int) $i['id'];
            $i['qty'] = (int) $i['qty'];
            $i['qty_refunded'] = (int) $i['qty_refunded'];
            $i['qty_open'] = max(0, $i['qty'] - $i['qty_refunded']);
            $i['points_each'] = (int) $i['points_each'];
            $i['stock_left'] = $stock === null ? null : (int) ($stock[$hasVariants ? (string) ($i['variant'] ?? '') : self::STOCK_ALL] ?? 0);
            $i['refunds'] = [];
            unset($i['variants_json'], $i['stock_json']);
            $items[(int) $i['redemption_id']][$i['id']] = $i;
        }

        $rf = $this->pdo->prepare("SELECT id, redemption_id, item_id, qty, points, reason, created_at FROM redemption_refunds WHERE redemption_id IN ($in) ORDER BY id");
        $rf->execute($ids);
        $refunded = [];
        foreach ($rf->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $rid = (int) $f['redemption_id'];
            $refunded[$rid] = ($refunded[$rid] ?? 0) + (int) $f['points'];
            if (isset($items[$rid][(int) $f['item_id']])) $items[$rid][(int) $f['item_id']]['refunds'][] = $f;
        }

        foreach ($rows as &$r) {
            $rid = (int) $r['id'];
            $list = array_values($items[$rid] ?? []);
            $r['items'] = $list;
            $r['goody_name'] = self::orderSummary($list);
            $r['refunded_points'] = (int) ($refunded[$rid] ?? 0);
            $r['net_points'] = max(0, (int) $r['points_spent'] - $r['refunded_points']);
        }
        unset($r);
        return $rows;
    }

    public function redemptionsForMember(string $memberId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.id, r.tracking_id, r.points_spent, r.status, r.created_at,
                    r.delivery_method, r.courier, r.awb, r.tracking_url, r.handover_note
             FROM redemptions r
             WHERE r.member_id = :m ORDER BY r.id DESC LIMIT 50'
        );
        $st->execute([':m' => $memberId]);
        return $this->attachItems($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** For the reviewer queue. $status = '' means every status. */
    public function redemptionQueue(string $status = ''): array
    {
        $sql = 'SELECT r.* FROM redemptions r';
        $args = [];
        if ($status !== '' && isset(self::REDEMPTION_FLOW[$status])) { $sql .= ' WHERE r.status = :s'; $args[':s'] = $status; }
        $st = $this->pdo->prepare($sql . ' ORDER BY r.id DESC LIMIT 200');
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['shipping'] = $r['shipping_json'] ? (json_decode((string) $r['shipping_json'], true) ?: []) : [];
        unset($r);
        return $this->attachItems($rows);
    }

    /** The lines of an order for an email: name, variant, qty (only what is still open when $onlyOpen). */
    private function emailLines(int $redemptionId, bool $onlyOpen): array
    {
        $st = $this->pdo->prepare('SELECT g.name, i.variant, i.qty, i.qty_refunded FROM redemption_items i JOIN goodies g ON g.id = i.goody_id WHERE i.redemption_id = :r ORDER BY i.id');
        $st->execute([':r' => $redemptionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $q = $onlyOpen ? (int) $i['qty'] - (int) $i['qty_refunded'] : (int) $i['qty'];
            if ($q < 1) continue;
            $out[] = ['name' => (string) $i['name'], 'variant' => (string) ($i['variant'] ?? ''), 'qty' => $q];
        }
        return $out;
    }

    private function refundedTotal(int $redemptionId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM redemption_refunds WHERE redemption_id = :r');
        $st->execute([':r' => $redemptionId]);
        return (int) $st->fetchColumn();
    }

    /** The still-open lines of an order, locked, in goody order (so stock locks follow the same order as redeemCart). */
    private function openItems(int $redemptionId): array
    {
        $st = $this->pdo->prepare('SELECT id, goody_id, variant, qty, qty_refunded, points_each, (qty - qty_refunded) AS qty_open
                                     FROM redemption_items WHERE redemption_id = :r AND qty > qty_refunded ORDER BY goody_id, id FOR UPDATE');
        $st->execute([':r' => $redemptionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Refund $qty units of one line, INSIDE an open transaction that already holds the order's row lock.
     * Writes the refund row, bumps qty_refunded, writes the ledger credit and (when $restock) puts the units back
     * in stock. Returns the points refunded.
     */
    private function refundInTx(array $item, int $qty, string $reason, bool $restock, string $actorEmail, array $order): int
    {
        $points = $qty * (int) $item['points_each'];
        $this->pdo->prepare('INSERT INTO redemption_refunds (redemption_id, item_id, qty, points, reason, restocked, refunded_by) VALUES (:r, :i, :q, :p, :why, :rs, :by)')
            ->execute([':r' => $order['id'], ':i' => $item['id'], ':q' => $qty, ':p' => $points, ':why' => mb_substr($reason, 0, 255), ':rs' => $restock ? 1 : 0, ':by' => $actorEmail]);
        $refundId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE redemption_items SET qty_refunded = qty_refunded + :q WHERE id = :id')->execute([':q' => $qty, ':id' => $item['id']]);

        $g = $this->pdo->prepare('SELECT name, stock_json FROM goodies WHERE id = :id' . ($restock ? ' FOR UPDATE' : ''));
        $g->execute([':id' => $item['goody_id']]);
        $goody = $g->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'goody', 'stock_json' => null];

        $label = self::itemLabel((string) $goody['name'], (string) ($item['variant'] ?? ''), $qty);
        $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, redemption_id, refund_id, reason, actor_email) VALUES (:m, :p, 'refund', :r, :f, :why, :a)")
            ->execute([':m' => $order['member_id'], ':p' => $points, ':r' => $order['id'], ':f' => $refundId,
                       ':why' => mb_substr('Refund: ' . $order['tracking_id'] . ' (' . $label . ')', 0, 255), ':a' => $actorEmail]);

        if ($restock) {
            $stock = self::decodeStock($goody['stock_json']);
            if ($stock !== null) {
                $key = (string) ($item['variant'] ?? '') !== '' ? (string) $item['variant'] : self::STOCK_ALL;
                if (array_key_exists($key, $stock)) {
                    $stock[$key] += $qty;
                    $this->pdo->prepare('UPDATE goodies SET stock_json = :s WHERE id = :id')
                        ->execute([':s' => json_encode($stock, JSON_UNESCAPED_UNICODE), ':id' => $item['goody_id']]);
                }
            }
        }
        return $points;
    }

    /**
     * Refund some or all units of one order line (for example the goody is out of stock) and let the rest of the
     * order ship. Only while the order is Requested or Ordered. $restock puts the units back in stock: leave it off
     * when the reason is that they are out of stock. When the last open unit is refunded the order becomes Cancelled.
     * The member is emailed after the change is saved ('email' says whether it went; null = could not tell).
     * @return array{ok:bool, error?:string, points?:int, cancelled?:bool, email?:bool}
     */
    public function refundItem(int $itemId, int $qty, string $reason, bool $restock, string $actorEmail): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Give a reason. The member reads it.'];
        if (mb_strlen($reason) > 255) return ['ok' => false, 'error' => 'Please keep the reason under 255 characters.'];

        $st = $this->pdo->prepare('SELECT redemption_id FROM redemption_items WHERE id = :id');
        $st->execute([':id' => $itemId]);
        $rid = (int) $st->fetchColumn();
        if ($rid < 1) return ['ok' => false, 'error' => 'That item no longer exists.'];

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM redemptions WHERE id = :id FOR UPDATE');
            $st->execute([':id' => $rid]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order || !in_array($order['status'], ['Requested', 'Ordered'], true)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Items can only be refunded before the order is shipped.'];
            }
            $st = $this->pdo->prepare('SELECT id, goody_id, variant, qty, qty_refunded, points_each, (qty - qty_refunded) AS qty_open FROM redemption_items WHERE id = :id AND redemption_id = :r FOR UPDATE');
            $st->execute([':id' => $itemId, ':r' => $rid]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item || $qty < 1 || $qty > (int) $item['qty_open']) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Choose how many units to refund (1 to ' . (int) ($item['qty_open'] ?? 0) . ').'];
            }
            $points = $this->refundInTx($item, $qty, $reason, $restock, $actorEmail, $order);
            $left = $this->pdo->prepare('SELECT COALESCE(SUM(qty - qty_refunded), 0) FROM redemption_items WHERE redemption_id = :r');
            $left->execute([':r' => $rid]);
            $cancelled = (int) $left->fetchColumn() === 0;
            if ($cancelled) $this->pdo->prepare("UPDATE redemptions SET status = 'Cancelled' WHERE id = :id")->execute([':id' => $rid]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('refundItem failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not refund the item.'];
        }

        $this->audit($actorEmail, 'goodies.redemption_refund', (string) $order['tracking_id'],
            $qty . ' unit(s), ' . $points . ' pts' . ($restock ? ', back in stock' : '') . ': ' . mb_substr($reason, 0, 150) . ($cancelled ? ' (order now cancelled)' : ''));

        if ($cancelled) {
            $email = $this->mailMember((string) $order['member_id'], 'sendOrderCancelled', [(string) $order['tracking_id'], $this->emailLines($rid, false), $this->refundedTotal($rid)]);
        } else {
            $n = $this->pdo->prepare('SELECT g.name, i.variant FROM redemption_items i JOIN goodies g ON g.id = i.goody_id WHERE i.id = :id');
            $n->execute([':id' => $itemId]);
            $row = $n->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'item', 'variant' => ''];
            $email = $this->mailMember((string) $order['member_id'], 'sendOrderRefunded',
                [(string) $order['tracking_id'], [['name' => (string) $row['name'], 'variant' => (string) ($row['variant'] ?? ''), 'qty' => $qty]], $points]);
        }
        return ['ok' => true, 'points' => $points, 'cancelled' => $cancelled, 'email' => $email];
    }

    /**
     * Move a redemption along. Cancelling refunds every unit still open (and puts them back in stock).
     * To refund only part of an order use refundItem().
     *
     * Marking an order Shipped needs $dispatch, because the member is told how it is coming:
     *   method           'courier' or 'hand'
     *   courier, awb     required for a courier (awb is the courier's consignment number, not our GD- ID)
     *   tracking_url     optional link from the courier's site (http or https)
     *   handover_note    required by hand: who hands it over, where and when
     * The member is emailed after the status is saved (shipped, cancelled). A failed email never undoes the move;
     * 'email' in the result says whether it went (null = no email for this move), and resendDispatchEmail() tries again.
     * @return array{ok:bool, error?:string, email?:?bool}
     */
    public function setRedemptionStatus(int $id, string $to, string $actorEmail, array $dispatch = []): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM redemptions WHERE id = :id FOR UPDATE');
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !in_array($to, self::REDEMPTION_FLOW[$r['status']] ?? [], true)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'That status change is not allowed.'];
            }

            $d = null;
            if ($to === 'Shipped') {
                $d = self::cleanDispatch($dispatch);
                if (isset($d['error'])) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => $d['error']];
                }
                $this->pdo->prepare(
                    'UPDATE redemptions SET status = :s, delivery_method = :m, courier = :c, awb = :a, tracking_url = :u,
                            handover_note = :h, dispatched_by = :by, dispatched_at = :at, email_sent_at = NULL WHERE id = :id'
                )->execute([':s' => $to, ':m' => $d['method'], ':c' => $d['courier'], ':a' => $d['awb'], ':u' => $d['tracking_url'],
                            ':h' => $d['handover_note'], ':by' => $actorEmail, ':at' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
            } else {
                $this->pdo->prepare('UPDATE redemptions SET status = :s WHERE id = :id')->execute([':s' => $to, ':id' => $id]);
            }

            if ($to === 'Cancelled') {
                foreach ($this->openItems($id) as $it) {
                    $this->refundInTx($it, (int) $it['qty_open'], 'Order cancelled', true, $actorEmail, $r);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('setRedemptionStatus failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the order.'];
        }
        $detail = $r['status'] . ' -> ' . $to;
        if ($d) $detail .= $d['method'] === 'hand' ? ' (by hand: ' . $d['handover_note'] . ')' : ' (' . $d['courier'] . ' ' . $d['awb'] . ')';
        $this->audit($actorEmail, 'goodies.redemption_' . strtolower($to), (string) $r['tracking_id'], $detail);

        $email = null;
        if ($to === 'Shipped') {
            $email = $this->sendDispatchEmail($id);
        } elseif ($to === 'Cancelled') {
            $email = $this->mailMember((string) $r['member_id'], 'sendOrderCancelled', [(string) $r['tracking_id'], $this->emailLines($id, false), $this->refundedTotal($id)]);
        }
        return ['ok' => true, 'email' => $email];
    }

    /** Checks and trims the dispatch details. Returns the clean fields, or ['error' => ...]. */
    public static function cleanDispatch(array $in): array
    {
        $method = (string) ($in['method'] ?? '');
        $c = trim((string) ($in['courier'] ?? ''));
        $a = trim((string) ($in['awb'] ?? ''));
        $u = trim((string) ($in['tracking_url'] ?? ''));
        $h = trim((string) ($in['handover_note'] ?? ''));

        if ($method === 'courier') {
            if ($c === '' || $a === '') return ['error' => 'Enter the courier and the consignment (AWB) number.'];
            if (mb_strlen($c) > 60 || mb_strlen($a) > 80) return ['error' => 'The courier name or AWB number is too long.'];
            if ($u !== '' && (!preg_match('#^https?://\S+$#i', $u) || mb_strlen($u) > 500)) return ['error' => 'The tracking link must start with http:// or https://.'];
            return ['method' => 'courier', 'courier' => $c, 'awb' => $a, 'tracking_url' => $u !== '' ? $u : null, 'handover_note' => null];
        }
        if ($method === 'hand') {
            if ($h === '') return ['error' => 'Say who is handing it over, and where or when.'];
            if (mb_strlen($h) > 255) return ['error' => 'Please keep the hand-over details under 255 characters.'];
            return ['method' => 'hand', 'courier' => null, 'awb' => null, 'tracking_url' => null, 'handover_note' => $h];
        }
        return ['error' => 'Choose how it is being sent: courier or by hand.'];
    }

    /** Email the member how their order is coming (the units still open). Records email_sent_at on success. Returns whether it went out. */
    private function sendDispatchEmail(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT * FROM redemptions WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['delivery_method']) return false;

        $ok = $this->mailMember((string) $r['member_id'], 'sendOrderDispatched', [
            (string) $r['tracking_id'], $this->emailLines($id, true), (string) $r['delivery_method'],
            (string) ($r['courier'] ?? ''), (string) ($r['awb'] ?? ''), (string) ($r['tracking_url'] ?? ''), (string) ($r['handover_note'] ?? ''),
        ]);
        if ($ok) $this->pdo->prepare('UPDATE redemptions SET email_sent_at = :t WHERE id = :id')->execute([':t' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
        return $ok;
    }

    /** "Send email again" for an order that was shipped but whose email did not go out. */
    public function resendDispatchEmail(int $id, string $actorEmail): array
    {
        $st = $this->pdo->prepare('SELECT status, delivery_method, tracking_id FROM redemptions WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['delivery_method'] || !in_array($r['status'], ['Shipped', 'Delivered'], true)) {
            return ['ok' => false, 'error' => 'That order has not been shipped yet.'];
        }
        if (!$this->sendDispatchEmail($id)) return ['ok' => false, 'error' => 'The email could not be sent. Check the mail settings and try again.'];
        $this->audit($actorEmail, 'goodies.dispatch_email', (string) $r['tracking_id'], 'Dispatch email sent again');
        return ['ok' => true];
    }

    /**
     * Email a member through the Mailer facade ($method is a Mailer::sendX name; the member's email and name
     * are looked up from the members table and put first). Best effort: returns false when nothing went out.
     */
    private function mailMember(string $memberId, string $method, array $args): bool
    {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            if (!class_exists('Mailer') || !method_exists('Mailer', $method)) return false;
            $st = $this->pdo->prepare('SELECT email, full_name FROM members WHERE member_id = :m ORDER BY id DESC LIMIT 1');
            $st->execute([':m' => $memberId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m || trim((string) $m['email']) === '') return false;
            return (bool) Mailer::$method((string) $m['email'], (string) $m['full_name'], ...$args);
        } catch (Throwable $e) {
            $this->log('email ' . $method . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * For the retention cron: removes shipping details from orders that are finished (Delivered or
     * Cancelled) and untouched for $days days. Wire it into your existing cron/ scrubbing job.
     */
    public function scrubShipping(int $days = 30): int
    {
        $st = $this->pdo->prepare(
            "UPDATE redemptions SET shipping_json = NULL
             WHERE shipping_json IS NOT NULL AND status IN ('Delivered','Cancelled')
               AND updated_at < (NOW() - INTERVAL " . max(1, (int) $days) . ' DAY)'
        );
        $st->execute();
        return $st->rowCount();
    }

    // ---- Support -------------------------------------------------------------------------------

    /** Written to the same audit log as the rest of Engage (AuditLog::activity). Never breaks the action itself. */
    private function audit(string $actorEmail, string $action, string $target, string $detail): void
    {
        try {
            AuditLog::activity($action, $target, mb_substr($detail, 0, 500), $actorEmail);
        } catch (Throwable $e) {
            $this->log('audit failed: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        app_log('PointsModel: ' . $message);
    }
}
