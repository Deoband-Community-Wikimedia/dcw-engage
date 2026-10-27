<?php
/**
 * DCW Engage - Goodies: non-expiring member points, and their redemption for goodies.
 *
 * The rules this class enforces:
 *  - points_ledger is append-only. A balance is SUM(points) for a Member ID. Nothing here updates
 *    or deletes a ledger row; corrections are new rows (refund, reversal).
 *  - Points reach the ledger only through an approved award. An award worth SECOND_APPROVAL_AT or
 *    more needs a second reviewer, and never the one who created it.
 *  - Redemption is one transaction under a per-member lock, so two quick clicks cannot spend the
 *    same points twice.
 *  - Reviewer actions are written to the audit log (AuditLog::activity). The ledger carries the actor's email as a snapshot.
 *
 * Assumes PDO is in ERRMODE_EXCEPTION, as the other models do. Keep every query a prepared statement.
 * A Member ID is one person across every chapter they hold (letter + 8 digits, e.g. A48213977), so
 * points follow the person, not the chapter.
 */
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';

class PointsModel
{
    /** Awards at or above this many points need a second, different reviewer. */
    public const SECOND_APPROVAL_AT = 150;

    /** Allowed redemption status moves. Cancelling refunds the points. */
    public const REDEMPTION_FLOW = [
        'Requested' => ['Ordered', 'Cancelled'],
        'Ordered'   => ['Shipped', 'Cancelled'],
        'Shipped'   => ['Delivered'],
        'Delivered' => [],
        'Cancelled' => [],
    ];

    private PDO $pdo;

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

    /** Newest first. */
    public function history(string $memberId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT points, entry_type, reason, created_at FROM points_ledger
             WHERE member_id = :m ORDER BY id DESC LIMIT ' . max(1, (int) $limit)
        );
        $st->execute([':m' => $memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
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
                    COALESCE(SUM(CASE WHEN entry_type = 'award' THEN points END), 0) AS awarded,
                    COALESCE(-SUM(CASE WHEN entry_type IN ('redemption','refund') THEN points END), 0) AS spent
             FROM points_ledger"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['awaiting_second'] = (int) $this->pdo->query("SELECT COUNT(*) FROM point_awards WHERE status = 'Needs Second Approval'")->fetchColumn();
        $row['requested']       = (int) $this->pdo->query("SELECT COUNT(*) FROM redemptions WHERE status = 'Requested'")->fetchColumn();
        return array_map('intval', $row);
    }

    // ---- Awards --------------------------------------------------------------------------------

    /**
     * A reviewer awards points for an activity. Under SECOND_APPROVAL_AT the award is approved
     * straight away; otherwise it waits for a different reviewer.
     * @return array{ok:bool, error?:string, status?:string, points?:int}
     */
    public function createAward(string $memberId, int $typeId, string $evidence, string $note, string $actorEmail): array
    {
        $type = $this->activityType($typeId);
        if (!$type || !(int) $type['is_active']) return ['ok' => false, 'error' => 'That activity is not available.'];
        if ($evidence !== '' && !preg_match('#^https?://\S+$#i', $evidence)) return ['ok' => false, 'error' => 'Evidence must be a link starting with http:// or https://.'];
        if ((int) $type['needs_evidence'] && $evidence === '') return ['ok' => false, 'error' => 'This activity needs an evidence link (a diff or page).'];

        $points = (int) $type['points'];
        $needsSecond = $points >= self::SECOND_APPROVAL_AT;
        $status = $needsSecond ? 'Needs Second Approval' : 'Approved';

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO point_awards (member_id, activity_type_id, points, evidence_url, note, status, created_by_email, first_reviewer_email, decided_at)
                 VALUES (:m, :t, :p, :e, :n, :s, :a, :a, :d)'
            );
            $st->execute([
                ':m' => $memberId, ':t' => $typeId, ':p' => $points,
                ':e' => $evidence !== '' ? $evidence : null, ':n' => $note !== '' ? mb_substr($note, 0, 500) : null,
                ':s' => $status, ':a' => $actorEmail, ':d' => $needsSecond ? null : gmdate('Y-m-d H:i:s'),
            ]);
            $awardId = (int) $this->pdo->lastInsertId();
            if (!$needsSecond) $this->writeAwardLedger($awardId, $memberId, $points, (string) $type['name'], $actorEmail);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('createAward failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the award. If it was already awarded for this event, it cannot be awarded twice.'];
        }
        $this->audit($actorEmail, 'goodies.award', $memberId, $type['name'] . ' (' . $points . ' pts, ' . $status . ')');
        return ['ok' => true, 'status' => $status, 'points' => $points];
    }

    /** The second reviewer approves a large award. Never the person who created it. */
    public function approveAward(int $awardId, string $actorEmail): array
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
            if (strcasecmp((string) $a['first_reviewer_email'], $actorEmail) === 0) {
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
        $this->audit($actorEmail, 'goodies.approve', (string) $a['member_id'], $a['activity_name'] . ' (' . $a['points'] . ' pts)');
        return ['ok' => true];
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

    // ---- Catalogue -----------------------------------------------------------------------------

    public function goodies(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM goodies' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY points_cost, name';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['variants'] = $r['variants_json'] ? (json_decode((string) $r['variants_json'], true) ?: []) : [];
        return $rows;
    }

    /** Create ($id = null) or update a goody. $variantsCsv is "S, M, L". */
    public function saveGoody(?int $id, string $name, string $description, int $cost, string $variantsCsv, bool $active, string $actorEmail): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Give the goody a name (up to 120 characters).'];
        if ($cost < 1 || $cost > 100000) return ['ok' => false, 'error' => 'The cost must be between 1 and 100000 points.'];
        $variants = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim($v), 0, 60), explode(',', $variantsCsv)), fn($v) => $v !== '')));
        $json = $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null;
        $desc = mb_substr(trim($description), 0, 500);
        try {
            if ($id) {
                $st = $this->pdo->prepare('UPDATE goodies SET name = :n, description = :d, points_cost = :c, variants_json = :v, is_active = :a WHERE id = :id');
                $st->execute([':n' => $name, ':d' => $desc, ':c' => $cost, ':v' => $json, ':a' => $active ? 1 : 0, ':id' => $id]);
            } else {
                $st = $this->pdo->prepare('INSERT INTO goodies (name, description, points_cost, variants_json, is_active) VALUES (:n, :d, :c, :v, :a)');
                $st->execute([':n' => $name, ':d' => $desc, ':c' => $cost, ':v' => $json, ':a' => $active ? 1 : 0]);
            }
        } catch (PDOException $e) {
            // goodies.name is unique (uniq_goody_name). Any other database error goes up to the page as usual.
            if (($e->errorInfo[1] ?? 0) === 1062) return ['ok' => false, 'error' => 'A goody with that name already exists.'];
            throw $e;
        }
        $this->audit($actorEmail, $id ? 'goodies.catalogue_edit' : 'goodies.catalogue_add', $name, $cost . ' pts, ' . ($active ? 'active' : 'inactive'));
        return ['ok' => true];
    }

    // ---- Redemptions ---------------------------------------------------------------------------

    /**
     * A member spends points. $shipping holds name, phone and address (already validated by the page).
     * @return array{ok:bool, error?:string, tracking_id?:string}
     */
    public function redeem(string $memberId, int $goodyId, string $variant, array $shipping): array
    {
        // A per-member advisory lock: two simultaneous clicks queue up instead of both seeing the old balance.
        $lock = 'dcw_pts_' . md5($memberId);
        $got = $this->pdo->prepare('SELECT GET_LOCK(:l, 5)');
        $got->execute([':l' => $lock]);
        if ((int) $got->fetchColumn() !== 1) return ['ok' => false, 'error' => 'Please try again in a moment.'];

        try {
            $st = $this->pdo->prepare('SELECT * FROM goodies WHERE id = :id AND is_active = 1');
            $st->execute([':id' => $goodyId]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if (!$g) return ['ok' => false, 'error' => 'That goody is not available.'];

            $variants = $g['variants_json'] ? (json_decode((string) $g['variants_json'], true) ?: []) : [];
            if ($variants && !in_array($variant, $variants, true)) return ['ok' => false, 'error' => 'Please choose a size or option.'];
            if (!$variants) $variant = '';

            $cost = (int) $g['points_cost'];
            if ($this->balance($memberId) < $cost) return ['ok' => false, 'error' => 'You do not have enough points for this yet.'];

            $this->pdo->beginTransaction();
            try {
                $tracking = $this->insertRedemption($memberId, $goodyId, $variant, $cost, $shipping);
                $rid = (int) $this->pdo->lastInsertId();
                $lg = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, redemption_id, reason, actor_email) VALUES (:m, :p, 'redemption', :r, :why, :a)");
                $lg->execute([':m' => $memberId, ':p' => -$cost, ':r' => $rid, ':why' => mb_substr('Redeemed: ' . $g['name'] . ' (' . $tracking . ')', 0, 255), ':a' => $memberId]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $this->log('redeem failed: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'Could not place the order. Your points have not been spent.'];
            }
            return ['ok' => true, 'tracking_id' => $tracking];
        } finally {
            $rel = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
            $rel->execute([':l' => $lock]);
        }
    }

    /** Inserts the redemption under a fresh public ID (GD- plus 8 characters, unrelated to the row id). */
    private function insertRedemption(string $memberId, int $goodyId, string $variant, int $cost, array $shipping): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I/L
        for ($try = 0; $try < 5; $try++) {
            $id = 'GD-';
            for ($i = 0; $i < 8; $i++) $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            try {
                $st = $this->pdo->prepare('INSERT INTO redemptions (tracking_id, member_id, goody_id, variant, points_spent, shipping_json) VALUES (:t, :m, :g, :v, :c, :s)');
                $st->execute([':t' => $id, ':m' => $memberId, ':g' => $goodyId, ':v' => $variant !== '' ? $variant : null, ':c' => $cost, ':s' => json_encode($shipping, JSON_UNESCAPED_UNICODE)]);
                return $id;
            } catch (PDOException $e) {
                if ($try === 4 || (string) $e->getCode() !== '23000') throw $e;   // only retry a duplicate tracking ID
            }
        }
        throw new RuntimeException('Could not generate a tracking ID.');
    }

    public function redemptionsForMember(string $memberId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.tracking_id, r.variant, r.points_spent, r.status, r.created_at, g.name AS goody_name
             FROM redemptions r JOIN goodies g ON g.id = r.goody_id
             WHERE r.member_id = :m ORDER BY r.id DESC LIMIT 50'
        );
        $st->execute([':m' => $memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** For the reviewer queue. $status = '' means every status. */
    public function redemptionQueue(string $status = ''): array
    {
        $sql = 'SELECT r.*, g.name AS goody_name FROM redemptions r JOIN goodies g ON g.id = r.goody_id';
        $args = [];
        if ($status !== '' && isset(self::REDEMPTION_FLOW[$status])) { $sql .= ' WHERE r.status = :s'; $args[':s'] = $status; }
        $st = $this->pdo->prepare($sql . ' ORDER BY r.id DESC LIMIT 200');
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['shipping'] = $r['shipping_json'] ? (json_decode((string) $r['shipping_json'], true) ?: []) : [];
        return $rows;
    }

    /** Move a redemption along. Cancelling refunds the points with a new ledger row. */
    public function setRedemptionStatus(int $id, string $to, string $actorEmail): array
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
            $this->pdo->prepare('UPDATE redemptions SET status = :s WHERE id = :id')->execute([':s' => $to, ':id' => $id]);
            if ($to === 'Cancelled') {
                $lg = $this->pdo->prepare("INSERT INTO points_ledger (member_id, points, entry_type, redemption_id, reason, actor_email) VALUES (:m, :p, 'refund', :r, :why, :a)");
                $lg->execute([':m' => $r['member_id'], ':p' => (int) $r['points_spent'], ':r' => $id, ':why' => 'Refund: ' . $r['tracking_id'], ':a' => $actorEmail]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->log('setRedemptionStatus failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the order.'];
        }
        $this->audit($actorEmail, 'goodies.redemption_' . strtolower($to), (string) $r['tracking_id'], $r['status'] . ' -> ' . $to);
        return ['ok' => true];
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
