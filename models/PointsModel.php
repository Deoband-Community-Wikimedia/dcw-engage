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
require_once __DIR__ . '/MemberStrikeModel.php';

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

    /** Activities a member can be awarded only once, ever (a rejected award doesn't count). */
    public const ONE_TIME_CODES = ['first_edit', 'taw_graduate'];

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
        if ((string) $type['code'] === 'taw_graduate') {
            $edition = self::tawEdition($evidence);
            if ($edition === null) {
                return ['ok' => false, 'error' => 'Link the page of the TaW they graduated from, for example https://dcwwiki.org/Train_a_Wikipedian,_April_2026'];
            }
            if ($edition < self::TAW_FIRST_EDITION) {
                return ['ok' => false, 'error' => 'Only TaW April 2026 and later count. Earlier editions are not awarded.'];
            }
        }
        if (in_array((string) $type['code'], self::ONE_TIME_CODES, true)) {
            $st = $this->pdo->prepare("SELECT 1 FROM point_awards WHERE member_id = :m AND activity_type_id = :t AND status <> 'Rejected' LIMIT 1");
            $st->execute([':m' => $memberId, ':t' => $typeId]);
            if ($st->fetchColumn()) return ['ok' => false, 'error' => '"' . $type['name'] . '" can only be awarded once, and this member already has it.'];
        }

        $points = (int) $type['points'];
        $needsSecond = $points >= self::SECOND_APPROVAL_AT;
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
    // Skipped: the reviewer's own Member IDs (another reviewer runs it for them) and struck-off members.

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
     * What "Award now" would do for a session, without doing it. $ownIds are the reviewer's own Member IDs.
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
        }

        // Awards already made for this session: [activity_type_id][MEMBER ID] => true (any status: the unique key covers all).
        $st = $this->pdo->prepare('SELECT member_id, activity_type_id FROM point_awards WHERE source_ref = :r');
        $st->execute([':r' => self::chRef($sessionId)]);
        $made = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $made[(int) $r['activity_type_id']][strtoupper((string) $r['member_id'])] = true;

        $struck = $this->struckMembers();
        $want = [];
        if (isset($types['ch_attend'])) foreach (array_keys($attendees) as $m) $want[] = [$m, 'ch_attend', (int) $types['ch_attend']['points']];
        if (isset($types['ch_host'])) foreach (array_keys($hosts) as $m) $want[] = [$m, 'ch_host', $plan['host_points']];
        foreach ($want as [$m, $code, $points]) {
            if (isset($made[(int) $types[$code]['id']][$m])) $plan['done']++;
            elseif (isset($own[$m])) $plan['own'][] = $m;
            elseif (isset($struck[$m])) $plan['struck'][] = $m;
            elseif ($points > 0) $plan['award'][] = ['member_id' => $m, 'code' => $code, 'points' => $points];
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
     * @return array{ok:bool, error?:string, awarded?:int, plan?:array}
     */
    public function awardForSession(int $sessionId, string $actorEmail, array $ownIds = []): array
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
                if ($type && $this->insertSourceAward($a['member_id'], $type, $a['points'], self::chRef($sessionId), $label, $actorEmail)) $awarded++;
            }
        } finally {
            $rel = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
            $rel->execute([':l' => $lock]);
        }

        if ($awarded > 0) {
            $this->audit($actorEmail, 'goodies.ch_award', 'CH session #' . $sessionId, $awarded . ' award(s): ' . $session['title']);
        }
        return ['ok' => true, 'awarded' => $awarded, 'plan' => $plan];
    }

    /** One automatic award and, when it needs no second reviewer, its ledger row. False if it already exists. */
    private function insertSourceAward(string $memberId, array $type, int $points, string $sourceRef, string $label, string $actorEmail): bool
    {
        $needsSecond = $points >= self::SECOND_APPROVAL_AT;
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO point_awards (member_id, activity_type_id, source_ref, points, note, status, created_by_email, first_reviewer_email, decided_at)
                 VALUES (:m, :t, :r, :p, :n, :s, :a, :a2, :d)'
            );
            $st->execute([
                ':m' => $memberId, ':t' => (int) $type['id'], ':r' => $sourceRef, ':p' => $points,
                ':n' => mb_substr(ltrim($label, ' '), 0, 500), ':s' => $needsSecond ? 'Needs Second Approval' : 'Approved',
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
            // The member's own action, so the actor is the member (no staff account is involved).
            $this->audit('member ' . $memberId, 'goodies.redeem', $tracking, $g['name'] . ($variant !== '' ? ' (' . $variant . ')' : '') . ', ' . $cost . ' pts');
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
