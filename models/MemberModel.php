<?php
/**
 * DCW Engage - Membership review + members.
 * Reads the existing applications/forms tables; only form_type 'membership-%'
 * rows are ever visible or changeable through this class.
 */
class MemberModel {
    const TERM = '+1 year';                       // membership length, confirm with organizers
    const OPEN = ['New', 'Submitted', 'Under Review'];
    const CHAPTERS = ['generic', 'amu', 'jamia', 'photographers'];

    /** Chapter key -> club display name (used in emails). Keep in sync with LABELS. */
    const CHAPTER_NAMES = [
        'generic'       => 'DCW Generic Community',
        'amu'           => 'Wiki Club AMU',
        'jamia'         => 'Wiki Club Jamia',
        'photographers' => 'DCW Photographers Club',
    ];

    /** Display name -> chapter key (the renewal form stores the display name). Keep in sync with CHAPTER_NAMES. */
    const LABELS = [
        'DCW Generic Community' => 'generic', 'Wiki Club AMU' => 'amu',
        'Wiki Club Jamia' => 'jamia', 'DCW Photographers Club' => 'photographers',
    ];

    /**
     * First letter of a member ID, as a hint to the club: D = DCW, A = AMU, J = Jamia,
     * P = Photographers. Letters must stay unique per chapter.
     */
    const ID_PREFIX = ['generic' => 'D', 'amu' => 'A', 'jamia' => 'J', 'photographers' => 'P'];

    /** Number of random digits after the letter, e.g. A48213977 (9 characters in total). */
    const MEMBER_ID_DIGITS = 8;

    /** Key of the Member ID answer in a renewal form's form_data. Change if the renewal form uses another key. */
    const RENEWAL_ID_FIELD = 'member_id';

    private $db;

    public function __construct() { $this->db = DB::getInstance()->getConnection(); }

    public static function isRenewal(array $app): bool {
        return str_starts_with($app['form_type'], 'membership-renewal');
    }

    public static function chapterOf(array $app): ?string {
        if (self::isRenewal($app)) {
            $d = json_decode($app['form_data'] ?? '', true) ?: [];
            return self::LABELS[$d['chapter'] ?? ''] ?? null;
        }
        $c = substr($app['form_type'], strlen('membership-'));
        return in_array($c, self::CHAPTERS, true) ? $c : null;
    }

    /**
     * True when the chapter is DCW itself (the generic community) or unknown,
     * i.e. NOT a separate club. Emails use this to say "DCW" instead of a club name.
     */
    public static function isDcw(?string $chapter): bool {
        return $chapter === null || $chapter === 'generic';
    }

    /** Chapter key hinted at by a member ID's first letter ('A48213977' -> 'amu'), or null if it doesn't fit the format. */
    public static function chapterFromMemberId(string $memberId): ?string {
        if (!preg_match('/^([A-Z])\d{' . self::MEMBER_ID_DIGITS . '}$/', $memberId, $m)) return null;
        $chapter = array_search($m[1], self::ID_PREFIX, true);
        return $chapter === false ? null : $chapter;
    }

    /**
     * Member ID entered on a renewal form (answer key RENEWAL_ID_FIELD in form_data).
     *   - not a renewal form, or the field is empty -> null: it is handled as a normal application
     *   - given but not letter + 8 digits, or an unknown letter -> InvalidArgumentException
     *   - otherwise the ID, trimmed and upper-cased
     * Call this when the renewal is submitted (to show the applicant the error) and again on approval.
     */
    public static function renewalMemberId(array $app): ?string {
        if (!self::isRenewal($app)) return null;
        $d = json_decode($app['form_data'] ?? '', true) ?: [];
        $id = strtoupper(trim((string) ($d[self::RENEWAL_ID_FIELD] ?? '')));
        if ($id === '') return null;
        if (self::chapterFromMemberId($id) === null) {
            throw new InvalidArgumentException('That Member ID is not valid. It is one letter ('
                . implode(', ', array_values(self::ID_PREFIX)) . ') followed by ' . self::MEMBER_ID_DIGITS . ' digits, for example A48213977.');
        }
        return $id;
    }

    /**
     * Which chapters this staff member may see. null = all chapters.
     *   owner, organizer, membership_reviewer (DCW Generic Reviewers) -> all
     *   membership_coordinator -> only the chapters assigned in membership_scopes
     *   anyone else -> none
     */
    public function scopeFor(string $role, string $email): ?array {
        if (in_array($role, ['owner', 'organizer', 'membership_reviewer'], true)) return null;
        if ($role !== 'membership_coordinator') return [];
        return $this->chaptersFor($email);
    }

    public function chaptersFor(string $email): array {
        $st = $this->db->prepare('SELECT chapter FROM membership_scopes WHERE email = :e');
        $st->execute(['e' => strtolower($email)]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public function coordinators(): array {
        return $this->db->query("SELECT email FROM admin_users WHERE role = 'membership_coordinator' ORDER BY email")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function setChapters(string $email, array $chapters): void {
        if (!in_array($email, $this->coordinators(), true)) throw new Exception('Not a membership coordinator.');
        $chapters = array_values(array_intersect(self::CHAPTERS, $chapters));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM membership_scopes WHERE email = :e')->execute(['e' => strtolower($email)]);
            $ins = $this->db->prepare('INSERT INTO membership_scopes (email, chapter) VALUES (:e, :c)');
            foreach ($chapters as $c) $ins->execute(['e' => strtolower($email), 'c' => $c]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Single rule used by the queue AND the detail/actions, so they cannot disagree. */
    public static function inScope(array $app, ?array $scope): bool {
        return $scope === null || in_array(self::chapterOf($app), $scope, true);
    }

    public function formSlugs(): array {
        return $this->db->query("SELECT form_type FROM forms WHERE form_type LIKE 'membership-%' ORDER BY form_type")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listApplications(string $slug, string $status, ?array $scope): array {
        $sql = "SELECT a.id, a.applicant_name, a.email, a.status, a.tracking_id, a.created_at, a.form_data, f.form_type
                FROM applications a JOIN forms f ON a.form_id = f.id
                WHERE f.form_type LIKE 'membership-%'
                AND (a.status <> 'Draft' OR EXISTS (SELECT 1 FROM membership_decisions d
                     WHERE d.application_id = a.id AND d.decision = 'info_requested'))";
        $p = [];
        if ($slug !== '')   { $sql .= ' AND f.form_type = :slug'; $p['slug'] = $slug; }
        if ($status !== '') { $sql .= ' AND a.status = :st';      $p['st'] = $status; }
        $st = $this->db->prepare($sql . ' ORDER BY a.created_at DESC LIMIT 500');
        $st->execute($p);
        // Chapter comes from the slug, or from the answer on renewals, so filter in PHP.
        return array_values(array_filter($st->fetchAll(), fn($r) => self::inScope($r, $scope)));
    }

    public function getApplication(int $id, ?array $scope) {
        $st = $this->db->prepare("SELECT a.*, f.form_type, f.schema_json
            FROM applications a JOIN forms f ON a.form_id = f.id
            WHERE a.id = :id AND f.form_type LIKE 'membership-%'");
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return ($row && self::inScope($row, $scope)) ? $row : false;   // out of scope looks like "not found"
    }

    /** Approve: create or extend the member, mark Accepted, log the decision. Returns [member_id, expires_at]. */
    public function approve(array $app, string $by): array {
        $chapter = self::chapterOf($app);
        if (!$chapter) throw new Exception('Could not work out the chapter for this application.');
        // A renewal with no Member ID is simply treated as a normal application; a malformed ID throws.
        self::renewalMemberId($app);

        $this->db->beginTransaction();
        try {
            $this->lockOpen($app['id']);
            $st = $this->db->prepare('SELECT * FROM members WHERE email = :e AND chapter = :c FOR UPDATE');
            $st->execute(['e' => $app['email'], 'c' => $chapter]);
            $m = $st->fetch();

            if ($m) {   // renewal (or a returning member): extend from the later of now / current expiry
                $base = max(strtotime($m['expires_at']), time());
                $exp = date('Y-m-d H:i:s', strtotime(self::TERM, $base));
                $this->db->prepare("UPDATE members SET status='active', expires_at=:x, full_name=:n, application_id=:a WHERE id=:id")
                    ->execute(['x' => $exp, 'n' => $app['applicant_name'], 'a' => $app['id'], 'id' => $m['id']]);
                $memberId = $m['member_id'];   // existing members keep their current ID
            } else {
                $exp = date('Y-m-d H:i:s', strtotime(self::TERM));
                $memberId = $this->insertMember($app, $chapter, $exp);
            }
            $this->log($app['id'], 'approved', null, $by);
            $this->db->commit();
            return [$memberId, $exp];
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function reject(array $app, string $reason, string $by): void {
        $this->decide($app, 'Rejected', 'rejected', $reason, $by);
    }

    /**
     * Send back for more information. The application returns to Draft, which the
     * existing state machine lets the applicant edit, and a fresh magic link is made
     * so they can resume it. Resubmitting puts it back in the queue as New.
     */
    public function requestInfo(array $app, string $message, string $by): string {
        $this->decide($app, 'Draft', 'info_requested', $message, $by);
        return $this->newResumeToken((int) $app['id']);
    }

    public function resendLink(array $app): string {
        if (!$this->awaitingApplicant($app)) throw new Exception('This application is not waiting on the applicant.');
        return $this->newResumeToken((int) $app['id']);
    }

    public function awaitingApplicant(array $app): bool {
        return $app['status'] === 'Draft' && $this->lastInfoMessage((int) $app['id']) !== null;
    }

    public function lastInfoMessage(int $appId): ?string {
        $st = $this->db->prepare("SELECT reason FROM membership_decisions
            WHERE application_id = :a AND decision = 'info_requested' ORDER BY id DESC LIMIT 1");
        $st->execute(['a' => $appId]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string) $r;
    }

    public function history(int $appId): array {
        $st = $this->db->prepare('SELECT decision, reason, decided_by, decided_at
            FROM membership_decisions WHERE application_id = :a ORDER BY id DESC');
        $st->execute(['a' => $appId]);
        return $st->fetchAll();
    }

    private function newResumeToken(int $appId): string {
        require_once __DIR__ . '/ApplicationModel.php';
        return (new ApplicationModel())->generateMagicLink($appId, true);
    }

    public function markUnderReview(array $app, string $by): void {
        $this->decide($app, 'Under Review', 'under_review', null, $by);
    }

    private function decide(array $app, string $newStatus, string $decision, ?string $reason, string $by): void {
        $this->db->beginTransaction();
        try {
            $this->lockOpen($app['id'], $newStatus);
            $this->log($app['id'], $decision, $reason, $by);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Status flip that fails if someone else already decided (double-click / two coordinators). */
    private function lockOpen(int $id, string $to = 'Accepted'): void {
        $st = $this->db->prepare("UPDATE applications SET status = :to
            WHERE id = :id AND status IN ('New','Submitted','Under Review')");
        $st->execute(['to' => $to, 'id' => $id]);
        if ($st->rowCount() !== 1) throw new Exception('This application was already decided.');
    }

    private function log(int $appId, string $decision, ?string $reason, string $by): void {
        $this->db->prepare('INSERT INTO membership_decisions (application_id, decision, reason, decided_by)
            VALUES (:a, :d, :r, :b)')->execute(['a' => $appId, 'd' => $decision, 'r' => $reason, 'b' => $by]);
    }

    /**
     * New member with a random ID: one letter for the club, then 8 random digits
     * (D = DCW, A = AMU, J = Jamia, P = Photographers; e.g. A48213977). Random rather than
     * sequential so IDs don't reveal how many members exist or let anyone guess neighbours.
     * Digits may start with 0. Stored as a string. A clash on the member_id unique key is
     * retried with a new number.
     */
    private function insertMember(array $app, string $chapter, string $exp): string {
        $prefix = self::ID_PREFIX[$chapter] ?? null;
        if ($prefix === null) throw new Exception('No member ID letter defined for this chapter.');

        for ($try = 1; $try <= 5; $try++) {
            $digits = str_pad((string) random_int(0, (10 ** self::MEMBER_ID_DIGITS) - 1), self::MEMBER_ID_DIGITS, '0', STR_PAD_LEFT);
            $memberId = $prefix . $digits;
            try {
                $this->db->prepare('INSERT INTO members (member_id, email, full_name, chapter, expires_at, application_id)
                    VALUES (:m, :e, :n, :c, :x, :a)')
                    ->execute(['m' => $memberId, 'e' => $app['email'], 'n' => $app['applicant_name'],
                               'c' => $chapter, 'x' => $exp, 'a' => $app['id']]);
                return $memberId;
            } catch (PDOException $e) {
                $dup   = (int) ($e->errorInfo[1] ?? 0) === 1062;   // MySQL/MariaDB duplicate key
                $clash = $dup && isset($e->errorInfo[2]) && str_contains($e->errorInfo[2], 'member_id');
                if (!$clash || $try === 5) throw $e;
            }
        }
        throw new Exception('Could not generate a unique member ID.');
    }
}
