<?php
/**
 * DCW Engage - Membership review + members.
 * Reads the existing applications/forms tables; only form_type 'membership-%'
 * rows are ever visible or changeable through this class.
 *
 * Review queue and Members tab are paged and filtered in SQL:
 *   applicationsPage()  queue rows (scope, slug, status, search), 50 per page
 *   membersPage()       one entry per Member ID, built from the members table, 50 per page
 *   statusCounts() / peopleCount() / countAwaiting()   sidebar and dashboard numbers
 * listApplications(), heldPairs(), membershipsByEmail() and memberInfoMap() load everything and are kept only for
 * older callers; delete them once nothing uses them.
 */
require_once __DIR__ . '/MemberStrikeModel.php';
require_once __DIR__ . '/../includes/audit.php';

class MemberModel {
    const TERM = '+1 year';                       // membership length, confirm with organizers
    const OPEN = ['New', 'Submitted', 'Under Review'];
    const CHAPTERS = ['generic', 'amu', 'jamia', 'photographers'];

    /** Rows per page in the review queue and the Members tab. */
    const PER_PAGE = 50;

    /** A search looks at most this many candidate applications (newest first) before checking answers in PHP. */
    const SEARCH_CANDIDATES = 1500;

    /**
     * members.status values that must NOT get their Member ID emailed by the "Forgot your Member ID?"
     * page (e.g. a membership a reviewer has struck off).
     */
    const ID_REMINDER_EXCLUDED_STATUSES = ['struck_off'];

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
     * First letter of a member ID, as a hint to the chapter it was FIRST issued for: D = DCW,
     * A = AMU, J = Jamia, P = Photographers. Letters must stay unique per chapter. The letter
     * says where the ID came from, not which memberships it covers: one person keeps one ID
     * for every chapter they join, in any order.
     */
    const ID_PREFIX = ['generic' => 'D', 'amu' => 'A', 'jamia' => 'J', 'photographers' => 'P'];

    /** Number of random digits after the letter, e.g. A48213977 (9 characters in total). */
    const MEMBER_ID_DIGITS = 8;

    /** Most rows a list call returns. */
    const LIST_LIMIT = 500;

    /**
     * Key of the Member ID answer in a renewal form's form_data. The form builder makes a key
     * from the question's label, so rewording the question changes it: re-check after any edit.
     */
    const RENEWAL_ID_FIELD = 'membership_id';

    /**
     * Key under which views/forms/renderer.php stores the Member ID of someone joining another
     * chapter on a normal membership form (from the session of a signed-in member, or the ID a
     * guest typed on /membership). It is set by the server, never by a form field.
     */
    const JOIN_ID_FIELD = 'join_member_id';

    /**
     * Keys that can hold the chapter answer on a renewal form, newest first. Older saved
     * applications used 'chapter'; the current form calls it 'your_membership' (confirm this).
     */
    const RENEWAL_CHAPTER_FIELDS = ['your_membership', 'chapter'];

    private $db;

    public function __construct() { $this->db = DB::getInstance()->getConnection(); }

    public static function isRenewal(array $app): bool {
        return str_starts_with($app['form_type'], 'membership-renewal');
    }

    /**
     * Chapter named in a membership form's slug, or null when the slug does not name one.
     *   membership-amu, membership-amu-2027           -> amu   (join forms)
     *   membership-renewal-amu-2027                   -> amu   (a chapter's own renewal form)
     *   membership-renewal-2026                       -> null  (the shared renewal form: chapter is an answer)
     * Used for the chapter of an application AND for which forms a chapter coordinator may manage.
     */
    public static function chapterOfSlug(string $slug): ?string {
        if (!str_starts_with($slug, 'membership-')) return null;
        $rest = substr($slug, strlen('membership-'));
        if (str_starts_with($rest, 'renewal-')) $rest = substr($rest, strlen('renewal-'));
        foreach (self::CHAPTERS as $c) {
            if ($rest === $c || str_starts_with($rest, $c . '-')) return $c;
        }
        return null;
    }

    public static function chapterOf(array $app): ?string {
        $fromSlug = self::chapterOfSlug((string) $app['form_type']);
        if (self::isRenewal($app)) {
            if ($fromSlug !== null) return $fromSlug;   // a chapter's own renewal form
            $d = json_decode($app['form_data'] ?? '', true) ?: [];   // the shared renewal form: read the answer
            $label = '';
            foreach (self::RENEWAL_CHAPTER_FIELDS as $key) {
                if (!empty($d[$key])) { $label = trim((string) $d[$key]); break; }
            }
            return self::LABELS[$label] ?? null;
        }
        return $fromSlug;
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
     * The Member ID attached to an application, or null when there is none.
     *   - renewal form: the answer under RENEWAL_ID_FIELD
     *   - any other membership form: JOIN_ID_FIELD, set by the renderer for someone joining
     *     another chapter (signed-in member, or a guest who typed their ID)
     *   - given but not letter + 8 digits, or an unknown letter -> InvalidArgumentException
     *   - otherwise the ID, trimmed and upper-cased
     */
    public static function applicationMemberId(array $app): ?string {
        $d = json_decode($app['form_data'] ?? '', true) ?: [];
        $key = self::isRenewal($app) ? self::RENEWAL_ID_FIELD : self::JOIN_ID_FIELD;
        $id = strtoupper(trim((string) ($d[$key] ?? '')));
        if ($id === '') return null;
        if (self::chapterFromMemberId($id) === null) {
            throw new InvalidArgumentException('That Member ID is not valid. It is one letter ('
                . implode(', ', array_values(self::ID_PREFIX)) . ') followed by ' . self::MEMBER_ID_DIGITS . ' digits, for example A48213977.');
        }
        return $id;
    }

    /**
     * Member ID entered on a renewal form (answer key RENEWAL_ID_FIELD in form_data).
     * Kept for older callers; returns null for anything that is not a renewal form.
     */
    public static function renewalMemberId(array $app): ?string {
        return self::isRenewal($app) ? self::applicationMemberId($app) : null;
    }

    /**
     * Verify the Member ID on an application (a renewal, or a member joining another chapter).
     *   - no ID given -> null (the caller treats it as a normal application)
     *   - malformed ID -> InvalidArgumentException (from applicationMemberId)
     *   - no member with that ID and this email -> InvalidArgumentException
     *   - joining a chapter the person already holds -> InvalidArgumentException (use renewal)
     *   - otherwise a members row: the one for the chosen chapter if it exists (a plain
     *     renewal), else the person's Generic Community row, else any other membership under
     *     that ID. The ID is then shared instead of a new one being made. ANY chapter's ID works.
     * The applicant's email is the one they verified (magic link, or the signed-in session), so a
     * match proves the ID is theirs.
     * The error is deliberately the same for "no such ID" and "someone else's ID", so IDs can't be probed.
     * Call this when the form is submitted and again on approval.
     */
    public function verifyRenewalMember(array $app): ?array {
        $id = self::applicationMemberId($app);
        if ($id === null) return null;

        $st = $this->db->prepare('SELECT * FROM members WHERE member_id = :m');
        $st->execute(['m' => $id]);
        $mine = array_values(array_filter($st->fetchAll(),
            fn($r) => strcasecmp((string) $r['email'], (string) $app['email']) === 0));

        $chapter = self::chapterOf($app);   // from the chapter answer on the form; may be null if not answered yet
        $row = null;
        foreach ($mine as $r) {
            if ($chapter !== null && $r['chapter'] === $chapter) { $row = $r; break; }   // plain renewal
        }
        if ($row !== null && !self::isRenewal($app)) {
            throw new InvalidArgumentException('You already hold this membership. Use the renewal option to extend it.');
        }
        if ($row === null) {
            foreach ($mine as $r) {
                if ($r['chapter'] === 'generic') { $row = $r; break; }                   // joining with the Generic ID
            }
            if ($row === null && $mine) $row = $mine[0];                                 // joining with a club ID
        }

        if ($row === null) {
            throw new InvalidArgumentException('We could not match that Member ID to your email address and chosen membership. '
                . (self::isRenewal($app)
                    ? 'Please check it, or go back and apply as a new member.'
                    : 'Please check it, or go back to the membership page and leave the Member ID empty.'));
        }
        return $row;
    }

    /**
     * Which chapters this staff member may see. null = all chapters.
     *   owner, membership_reviewer (DCW Generic Reviewers) -> all
     *   membership_coordinator -> only the chapters assigned in membership_scopes
     *   anyone else (including organizers, who only work with forms) -> none
     *
     * $roles is the person's FULL role list (array). An account can hold several
     * roles, and admin_users.role only holds the primary one, so callers must pass
     * the whole list (e.g. from admin_user_roles). A single role string still works.
     */
    public function scopeFor($roles, string $email): ?array {
        $roles = is_array($roles)
            ? $roles
            : array_filter(array_map('trim', explode(',', (string) $roles)));
        if (array_intersect($roles, ['owner', 'membership_reviewer'])) return null;   // all chapters
        if (!in_array('membership_coordinator', $roles, true)) return [];
        return $this->chaptersFor($email);
    }

    public function chaptersFor(string $email): array {
        $st = $this->db->prepare('SELECT chapter FROM membership_scopes WHERE email = :e');
        $st->execute(['e' => strtolower(trim($email))]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Emails of every account that holds the membership_coordinator role.
     * Roles live in admin_user_roles (one row per role); admin_users.role is only the
     * primary role, so a person who is also, say, an Organizer would be missed by
     * checking that column alone. Accounts with no rows in admin_user_roles fall back
     * to their primary role, matching InviteModel::rolesFor().
     */
    public function coordinators(): array {
        $st = $this->db->query(
            "SELECT u.email
               FROM admin_users u
              WHERE EXISTS (SELECT 1 FROM admin_user_roles r
                             WHERE r.admin_id = u.id AND r.role = 'membership_coordinator')
                 OR (u.role = 'membership_coordinator'
                     AND NOT EXISTS (SELECT 1 FROM admin_user_roles r2 WHERE r2.admin_id = u.id))
              ORDER BY u.email"
        );
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public function setChapters(string $email, array $chapters): void {
        $email  = strtolower(trim($email));
        $coords = array_map('strtolower', $this->coordinators());
        if (!in_array($email, $coords, true)) throw new Exception('Not a membership coordinator.');
        $chapters = array_values(array_intersect(self::CHAPTERS, $chapters));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM membership_scopes WHERE email = :e')->execute(['e' => $email]);
            $ins = $this->db->prepare('INSERT INTO membership_scopes (email, chapter) VALUES (:e, :c)');
            foreach ($chapters as $c) $ins->execute(['e' => $email, 'c' => $c]);
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

    // ---- SQL building blocks for the paged lists -----------------------------------------------

    private ?array $membershipFormRows = null;

    /** Membership forms (id, form_type), once per request. The forms table is small. */
    private function membershipForms(): array {
        if ($this->membershipFormRows === null) {
            $this->membershipFormRows = $this->db->query("SELECT id, form_type FROM forms WHERE form_type LIKE 'membership-%'")->fetchAll();
        }
        return $this->membershipFormRows;
    }

    /** Adds a bound value to $p under a fresh name and returns its placeholder. Names are never reused. */
    private function bind(array &$p, $value): string {
        $k = 'q' . count($p);
        $p[$k] = $value;
        return ':' . $k;
    }

    /** Lower-cased text with "_" as a space, the way Wikimedia treats usernames. */
    private function norm($s): string {
        return str_replace('_', ' ', mb_strtolower(trim((string) $s)));
    }

    /**
     * SQL for "the application belongs to one of these chapters" (aliases a = applications). Same rule as
     * inScope()/chapterOf(): the chapter comes from the form slug, or, on the shared renewal form, from the
     * chapter answer in form_data.
     */
    private function scopeWhere(?array $scope, array &$p): string {
        if ($scope === null) return '1=1';
        if (!$scope) return '0=1';

        $own = []; $shared = [];
        foreach ($this->membershipForms() as $f) {
            $slug = (string) $f['form_type'];
            $c = self::chapterOfSlug($slug);
            if ($c !== null) {
                if (in_array($c, $scope, true)) $own[] = (int) $f['id'];
            } elseif (str_starts_with($slug, 'membership-renewal')) {
                $shared[] = (int) $f['id'];
            }
        }

        $parts = [];
        if ($own) $parts[] = 'a.form_id IN (' . implode(',', $own) . ')';

        $labels = [];
        foreach (self::LABELS as $label => $c) {
            if (in_array($c, $scope, true)) $labels[] = $label;
        }
        if ($shared && $labels) {
            // First non-empty chapter answer, newest key first (same order as RENEWAL_CHAPTER_FIELDS).
            $expr = null;
            foreach (array_reverse(self::RENEWAL_CHAPTER_FIELDS) as $key) {
                $one = "JSON_UNQUOTE(JSON_EXTRACT(a.form_data,'" . '$.' . $key . "'))";
                $expr = $expr === null ? $one : "COALESCE(NULLIF(TRIM($one),''), $expr)";
            }
            $ph = [];
            foreach ($labels as $l) $ph[] = $this->bind($p, $l);
            $parts[] = '(a.form_id IN (' . implode(',', $shared) . ') AND TRIM(' . $expr . ') IN (' . implode(',', $ph) . '))';
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '0=1';
    }

    /**
     * WHERE for the review queue (aliases a = applications, f = forms): membership forms only, no plain drafts
     * (a draft shows only once staff sent it back), then chapter scope, form slug and status.
     * $status: 'todo' = New or Submitted, '' = any, else exactly that status.
     */
    private function appsWhere(string $slug, string $status, ?array $scope, array &$p, bool $excludeAccepted): string {
        $w = [
            "f.form_type LIKE 'membership-%'",
            "(a.status <> 'Draft' OR EXISTS (SELECT 1 FROM membership_decisions d WHERE d.application_id = a.id AND d.decision = 'info_requested'))",
            $this->scopeWhere($scope, $p),
        ];
        if ($slug !== '') $w[] = 'f.form_type = ' . $this->bind($p, $slug);
        if ($status === 'todo') {
            $w[] = "a.status IN ('New','Submitted')";
        } elseif ($status !== '') {
            $w[] = 'a.status = ' . $this->bind($p, $status);
        }
        if ($excludeAccepted) $w[] = "a.status <> 'Accepted'";
        return implode(' AND ', $w);
    }

    /** WHERE for the Members tab (alias m = members): chapter scope and club filter. */
    private function membersWhere(string $slug, ?array $scope, array &$p): string {
        $w = ['1=1'];
        if ($scope !== null) {
            if (!$scope) {
                $w[] = '0=1';
            } else {
                $ph = [];
                foreach ($scope as $c) $ph[] = $this->bind($p, $c);
                $w[] = 'm.chapter IN (' . implode(',', $ph) . ')';
            }
        }
        if ($slug !== '') {
            $c = self::chapterOfSlug($slug);
            if ($c !== null) {
                $w[] = 'm.chapter = ' . $this->bind($p, $c);
            } else {   // the shared renewal form names no chapter: use the applications it produced
                $w[] = 'm.application_id IN (SELECT a2.id FROM applications a2 JOIN forms f2 ON f2.id = a2.form_id WHERE f2.form_type = ' . $this->bind($p, $slug) . ')';
            }
        }
        return implode(' AND ', $w);
    }

    // ---- Paged lists ---------------------------------------------------------------------------

    /**
     * One page of the review queue.
     *   $status         'todo', '' or a status (see appsWhere)
     *   $excludeAccepted true for the queue (accepted people live in the Members tab)
     *   $q              search: name, email, tracking ID, Member ID, Wikimedia username (profile), and the VALUES of
     *                   the application's answers (field names never match)
     * Returns ['rows' => [...], 'total' => n, 'page' => n, 'pages' => n]. Rows carry form_data and form_type
     * (needed for the chapter of a shared renewal form) but only for the 50 shown.
     *
     * Without a search everything is done in SQL. With a search, SQL narrows to candidates (name/email/ID/username
     * matches exactly; the answers by a text match that can also hit field names), then PHP keeps only real value
     * matches, so the counts stay right. At most SEARCH_CANDIDATES candidates are looked at, newest first.
     */
    public function applicationsPage(string $slug, string $status, ?array $scope, string $q, int $page, bool $excludeAccepted = true, int $per = self::PER_PAGE): array {
        $per = max(1, $per);
        $page = max(1, $page);
        $q = trim($q);
        $cols = 'a.id, a.applicant_name, a.email, a.status, a.tracking_id, a.created_at, a.form_data, f.form_type';
        $order = 'ORDER BY a.created_at DESC, a.id DESC';

        if ($q === '') {
            $p = [];
            $w = $this->appsWhere($slug, $status, $scope, $p, $excludeAccepted);
            $st = $this->db->prepare("SELECT COUNT(*) FROM applications a JOIN forms f ON f.id = a.form_id WHERE $w");
            $st->execute($p);
            $total = (int) $st->fetchColumn();
            $pages = max(1, (int) ceil($total / $per));
            $page = min($page, $pages);
            $off = ($page - 1) * $per;
            $st = $this->db->prepare("SELECT $cols FROM applications a JOIN forms f ON f.id = a.form_id WHERE $w $order LIMIT $per OFFSET $off");
            $st->execute($p);
            return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
        }

        $needle = $this->norm($q);
        if ($needle === '') return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        $like = '%' . addcslashes($needle, '\\%_') . '%';

        $p = [];
        $w = $this->appsWhere($slug, $status, $scope, $p, $excludeAccepted);
        // Exact SQL matches. Built twice (once for the flag, once for the WHERE) because names are never reused.
        $hit = function () use (&$p, $like): string {
            return '(' . implode(' OR ', [
                "REPLACE(a.applicant_name,'_',' ') LIKE " . $this->bind($p, $like),
                "REPLACE(a.email,'_',' ') LIKE " . $this->bind($p, $like),
                "REPLACE(a.tracking_id,'_',' ') LIKE " . $this->bind($p, $like),
                'EXISTS (SELECT 1 FROM members m WHERE m.email = a.email AND m.member_id LIKE ' . $this->bind($p, $like) . ')',
                'EXISTS (SELECT 1 FROM members m2 JOIN member_profile pr ON pr.member_id = m2.member_id WHERE m2.email = a.email AND REPLACE(pr.wikimedia_username,\'_\',\' \') LIKE ' . $this->bind($p, $like) . ')',
            ]) . ')';
        };
        $flag = $hit();
        $cond = $hit();
        $answers = "REPLACE(LOWER(CAST(a.form_data AS CHAR)),'_',' ') LIKE " . $this->bind($p, $like);
        $limit = self::SEARCH_CANDIDATES;

        $st = $this->db->prepare("SELECT $cols, $flag AS hit_exact FROM applications a JOIN forms f ON f.id = a.form_id
                                  WHERE $w AND ($cond OR $answers) $order LIMIT $limit");
        $st->execute($p);

        $kept = [];
        foreach ($st->fetchAll() as $r) {
            if (!empty($r['hit_exact']) || $this->answersMatch($r['form_data'] ?? null, $needle)) {
                unset($r['hit_exact']);
                $kept[] = $r;
            }
        }
        $total = count($kept);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        return ['rows' => array_slice($kept, ($page - 1) * $per, $per), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** True when the text is found in one of the answers' VALUES (never the field names). */
    private function answersMatch(?string $json, string $needle): bool {
        $a = json_decode((string) $json, true);
        if (!is_array($a)) return false;
        foreach ($a as $v) {
            if (is_array($v)) $v = implode(' ', array_filter($v, 'is_scalar'));
            if (is_scalar($v) && (string) $v !== '' && mb_strpos($this->norm((string) $v), $needle) !== false) return true;
        }
        return false;
    }

    /**
     * One page of the Members tab: one entry per Member ID (one ID is one person, however many clubs).
     * Built from the members table, so chapter scope and club filter are plain SQL. Newest member first.
     * Search: name, email, Member ID or Wikimedia username (profile). Answers are not searched here.
     * Each group: ['mid', 'name', 'email', 'open_id' (application id to open, or null), 'expires' (latest expiry),
     *   'memberships' => [['id' => application id|null, 'key' => chapter, 'label', 'expires'], ...]].
     * Returns ['groups' => [...], 'total' => n, 'page' => n, 'pages' => n].
     */
    public function membersPage(string $slug, ?array $scope, string $q, int $page, int $per = self::PER_PAGE): array {
        $per = max(1, $per);
        $page = max(1, $page);
        $p = [];
        $w = $this->membersWhere($slug, $scope, $p);

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($this->norm($q), '\\%_') . '%';
            $w .= ' AND (' . implode(' OR ', [
                "REPLACE(m.full_name,'_',' ') LIKE " . $this->bind($p, $like),
                "REPLACE(m.email,'_',' ') LIKE " . $this->bind($p, $like),
                'm.member_id LIKE ' . $this->bind($p, $like),
                "EXISTS (SELECT 1 FROM member_profile pr WHERE pr.member_id = m.member_id AND REPLACE(pr.wikimedia_username,'_',' ') LIKE " . $this->bind($p, $like) . ')',
            ]) . ')';
        }

        $st = $this->db->prepare("SELECT COUNT(DISTINCT m.member_id) FROM members m WHERE $w");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $off = ($page - 1) * $per;

        $st = $this->db->prepare("SELECT m.member_id FROM members m WHERE $w
                                  GROUP BY m.member_id ORDER BY MAX(m.joined_at) DESC, m.member_id ASC LIMIT $per OFFSET $off");
        $st->execute($p);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return ['groups' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];

        // All of these people's memberships inside the viewer's scope (not only the ones that matched the search).
        $p2 = [];
        $ph = [];
        foreach ($ids as $id) $ph[] = $this->bind($p2, $id);
        $w2 = 'm.member_id IN (' . implode(',', $ph) . ') AND ' . $this->membersWhere('', $scope, $p2);
        $st = $this->db->prepare("SELECT m.id, m.member_id, m.email, m.full_name, m.chapter, m.expires_at, m.application_id
                                  FROM members m WHERE $w2 ORDER BY (m.chapter = 'generic') DESC, m.id ASC");
        $st->execute($p2);

        $by = [];
        foreach ($st->fetchAll() as $r) {
            $mid = (string) $r['member_id'];
            if (!isset($by[$mid])) {
                $by[$mid] = ['mid' => $mid, 'name' => '', 'email' => '', 'open_id' => null, 'expires' => null, 'memberships' => [], '_max' => 0];
            }
            $g =& $by[$mid];
            $g['memberships'][] = [
                'id' => $r['application_id'] !== null ? (int) $r['application_id'] : null,
                'key' => (string) $r['chapter'],
                'label' => self::CHAPTER_NAMES[$r['chapter']] ?? (string) $r['chapter'],
                'expires' => (string) $r['expires_at'],
            ];
            if ($g['expires'] === null || (string) $r['expires_at'] > $g['expires']) $g['expires'] = (string) $r['expires_at'];
            if ((int) $r['id'] > $g['_max']) {   // the newest membership row names the person and the application to open
                $g['_max'] = (int) $r['id'];
                $g['name'] = (string) $r['full_name'];
                $g['email'] = (string) $r['email'];
                if ($r['application_id'] !== null) $g['open_id'] = (int) $r['application_id'];
            } elseif ($g['open_id'] === null && $r['application_id'] !== null) {
                $g['open_id'] = (int) $r['application_id'];
            }
            unset($g);
        }
        $groups = [];
        foreach ($ids as $id) {
            if (!isset($by[(string) $id])) continue;
            $g = $by[(string) $id];
            unset($g['_max']);
            $groups[] = $g;
        }
        return ['groups' => $groups, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Applications per status for the sidebar, within the chosen form and chapter scope. */
    public function statusCounts(string $slug, ?array $scope): array {
        $p = [];
        $w = $this->appsWhere($slug, '', $scope, $p, false);
        $st = $this->db->prepare("SELECT a.status, COUNT(*) AS n FROM applications a JOIN forms f ON f.id = a.form_id WHERE $w GROUP BY a.status");
        $st->execute($p);
        $out = ['New' => 0, 'Submitted' => 0, 'Under Review' => 0, 'Draft' => 0, 'Accepted' => 0, 'Rejected' => 0];
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** People (distinct Member IDs) for the Members tab badge. */
    public function peopleCount(string $slug, ?array $scope): int {
        $p = [];
        $w = $this->membersWhere($slug, $scope, $p);
        $st = $this->db->prepare("SELECT COUNT(DISTINCT m.member_id) FROM members m WHERE $w");
        $st->execute($p);
        return (int) $st->fetchColumn();
    }

    /** Applications waiting for a first decision (New or Submitted) in the viewer's scope. For the dashboard badge. */
    public function countAwaiting(?array $scope): int {
        $p = [];
        $w = $this->appsWhere('', 'todo', $scope, $p, false);
        $st = $this->db->prepare("SELECT COUNT(*) FROM applications a JOIN forms f ON f.id = a.form_id WHERE $w");
        $st->execute($p);
        return (int) $st->fetchColumn();
    }

    /** "email|chapter" => true for the memberships of these emails only (for the rows on one page). */
    public function heldPairsFor(array $emails): array {
        $emails = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string) $e)), $emails))));
        if (!$emails) return [];
        $st = $this->db->prepare('SELECT email, chapter FROM members WHERE email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')');
        $st->execute($emails);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[strtolower((string) $r['email']) . '|' . $r['chapter']] = true;
        return $out;
    }

    /** lower-cased email => memberships (member_id, chapter, status, expires_at), for the emails on one page. */
    public function membershipsForEmails(array $emails): array {
        $emails = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string) $e)), $emails))));
        if (!$emails) return [];
        $st = $this->db->prepare("SELECT email, member_id, chapter, status, expires_at FROM members
            WHERE email IN (" . implode(',', array_fill(0, count($emails), '?')) . ")
            ORDER BY (chapter = 'generic') DESC, id ASC");
        $st->execute($emails);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[strtolower((string) $r['email'])][] = [
                'member_id' => (string) $r['member_id'], 'chapter' => $r['chapter'],
                'status' => $r['status'], 'expires_at' => $r['expires_at'],
            ];
        }
        return $out;
    }

    /**
     * Applications for the review queue and the members list. LOADS EVERYTHING that matches, then filters in PHP,
     * and stops at 500. Kept for older callers; the review pages use applicationsPage() / membersPage().
     */
    public function listApplications(string $slug, string $status, ?array $scope, string $q = ''): array {
        $sql = "SELECT a.id, a.applicant_name, a.email, a.status, a.tracking_id, a.created_at, a.form_data, f.form_type,
                (SELECT d.decided_at FROM membership_decisions d
                  WHERE d.application_id = a.id AND d.decision = 'approved' ORDER BY d.id DESC LIMIT 1) AS approved_at,
                (SELECT d.decided_by FROM membership_decisions d
                  WHERE d.application_id = a.id AND d.decision = 'approved' ORDER BY d.id DESC LIMIT 1) AS approved_by
                FROM applications a JOIN forms f ON a.form_id = f.id
                WHERE f.form_type LIKE 'membership-%'
                AND (a.status <> 'Draft' OR EXISTS (SELECT 1 FROM membership_decisions d
                     WHERE d.application_id = a.id AND d.decision = 'info_requested'))";
        $p = [];
        if ($slug !== '') { $sql .= ' AND f.form_type = :slug'; $p['slug'] = $slug; }
        if ($status === 'todo') {
            $sql .= " AND a.status IN ('New','Submitted')";
        } elseif ($status !== '') {
            $sql .= ' AND a.status = :st'; $p['st'] = $status;
        }

        $st = $this->db->prepare($sql . ' ORDER BY a.created_at DESC');
        $st->execute($p);
        $rows = array_values(array_filter($st->fetchAll(), fn($r) => self::inScope($r, $scope)));

        $q = trim($q);
        if ($q !== '') $rows = $this->filterBySearch($rows, $q);

        return array_slice($rows, 0, self::LIST_LIMIT);
    }

    /**
     * Keep the rows that match a search. A row matches when the text is found (ignoring case, and
     * treating "_" and " " as the same, as Wikimedia does) in:
     *   - the applicant's name, email or tracking ID
     *   - any answer on the application (including the Wikimedia username they gave)
     *   - the Member ID on the application, or any Member ID held under that email
     *   - the Wikimedia username on the profile of any ID held under that email
     */
    private function filterBySearch(array $rows, string $q): array {
        $norm = fn($s) => str_replace('_', ' ', mb_strtolower(trim((string) $s)));
        $needle = $norm($q);
        if ($needle === '') return $rows;

        $byEmail = $this->membershipsByEmail();

        // Wikimedia usernames from the profiles, by Member ID. Optional: if this lookup fails,
        // everything else still searches.
        $names = [];
        try {
            foreach ($this->db->query('SELECT member_id, wikimedia_username FROM member_profile') as $r) {
                $names[(string) $r['member_id']] = (string) $r['wikimedia_username'];
            }
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('Search: profile usernames not searched: ' . $e->getMessage());
        }

        return array_values(array_filter($rows, function (array $r) use ($needle, $norm, $byEmail, $names): bool {
            $hay = [$r['applicant_name'] ?? '', $r['email'] ?? '', $r['tracking_id'] ?? ''];

            $answers = json_decode((string) ($r['form_data'] ?? ''), true);
            if (is_array($answers)) {
                foreach ($answers as $v) {   // values only, so field names never match
                    if (is_array($v)) $v = implode(' ', array_filter($v, 'is_scalar'));
                    if (is_scalar($v)) $hay[] = (string) $v;
                }
            }

            $stated = self::statedMemberId($r);
            if ($stated !== null) $hay[] = $stated;

            foreach ($byEmail[strtolower((string) ($r['email'] ?? ''))] ?? [] as $m) {
                $hay[] = $m['member_id'];
                $hay[] = $names[$m['member_id']] ?? '';
            }

            foreach ($hay as $s) {
                if ($s !== '' && mb_strpos($norm($s), $needle) !== false) return true;
            }
            return false;
        }));
    }

    public function getApplication(int $id, ?array $scope) {
        $st = $this->db->prepare("SELECT a.*, f.form_type, f.schema_json
            FROM applications a JOIN forms f ON a.form_id = f.id
            WHERE a.id = :id AND f.form_type LIKE 'membership-%'");
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return ($row && self::inScope($row, $scope)) ? $row : false;   // out of scope looks like "not found"
    }

    /**
     * Change one answer in an OPEN application's form_data (used by the reviewer's Wikimedia
     * username fix). The caller has already checked that the field may be edited and has
     * cleaned the value; this method only guarantees the application is still open and that
     * the write cannot clobber a concurrent change: the row is locked and re-read inside a
     * transaction, so only this one key changes. Logged to the app log (the decisions table
     * is left alone, since it records status decisions only).
     */
    public function setAnswer(array $app, string $field, string $value, string $by): void {
        $id = (int) $app['id'];
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT status, form_data FROM applications WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $id]);
            $row = $st->fetch();
            if (!$row) throw new Exception('Application not found.');
            if (!in_array($row['status'], self::OPEN, true)) {
                throw new Exception('This application is no longer open, so its answers cannot be edited.');
            }

            $data = json_decode((string) ($row['form_data'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            $old = (string) ($data[$field] ?? '');
            if ($old === $value) {   // nothing to change
                $this->db->commit();
                return;
            }
            $data[$field] = $value;

            $this->db->prepare('UPDATE applications SET form_data = :d WHERE id = :id')
                ->execute(['d' => json_encode($data, JSON_UNESCAPED_UNICODE), 'id' => $id]);
            $this->db->commit();

            if (function_exists('app_log')) {
                AuditLog::activity('membership.answer_changed', 'Application #' . $id,
                    "$field: '" . mb_strimwidth((string) $old, 0, 200, '…') . "' -> '" . mb_strimwidth((string) $value, 0, 200, '…') . "'", (string) $by);
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Approve: create or extend the member, mark Accepted, log the decision.
     * Returns [member_id, expires_at, shared]. $shared is true when a NEW membership was
     * created under an ID the person already had (so no new ID was made); the email says so.
     */
    public function approve(array $app, string $by): array {
        $chapter = self::chapterOf($app);
        if (!$chapter) throw new Exception('Could not work out the chapter for this application.');
        // No Member ID on the application is simply treated as a normal application;
        // a malformed or unmatched ID throws.
        $verified = $this->verifyRenewalMember($app);
        // A strike covers every chapter the person holds, so check every Member ID they could be approved under.
        // The review page hides the Approve button for them; this also stops bulk approvals and crafted requests.
        $struckId = $this->struckIdFor($app, $verified);
        if ($struckId !== null) {
            throw new Exception("$struckId is struck off, so this application can't be approved. Reinstate them first.");
        }
        $shared = false;

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
                // Someone who already holds a membership in any chapter keeps their ID.
                $useId = $this->sharedIdFor($app, $verified);
                $memberId = $this->insertMember($app, $chapter, $exp, $useId);
                $shared = $useId !== null;
            }
            $this->log($app['id'], 'approved', null, $by);
            $this->db->commit();
            return [$memberId, $exp, $shared];
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
        // Remember it was sent back, so the applicant can resubmit even after the form's deadline.
        $this->db->prepare("UPDATE applications SET sent_back_at = UTC_TIMESTAMP() WHERE id = :id")
                 ->execute(['id' => (int) $app['id']]);
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
     * The Member ID an approval should reuse, or null to generate a new one. One person, one ID,
     * whichever chapter it was first issued for. In order:
     *   1. an ID a reviewer linked to this application,
     *   2. the ID on the application (renewal form, or a member joining another chapter), already verified,
     *   3. any membership held under the same email (the Generic Community one first, then the oldest).
     *      The application's email is always verified, so the same email is the same person.
     */
    /** The first Member ID this application's person holds (or renews) that is struck off, or null. */
    private function struckIdFor(array $app, ?array $verified): ?string {
        $ids = [];
        if ($verified) $ids[] = (string) $verified['member_id'];
        foreach ($this->membershipsForEmail((string) $app['email']) as $m) $ids[] = (string) $m['member_id'];
        $strikes = new MemberStrikeModel();
        foreach (array_unique($ids) as $id) {
            if ($strikes->isStruck($id)) return $id;
        }
        return null;
    }

    private function sharedIdFor(array $app, ?array $verified): ?string {
        try {
            $st = $this->db->prepare('SELECT member_id FROM membership_id_links WHERE application_id = :a');
            $st->execute(['a' => $app['id']]);
            $id = $st->fetchColumn();
            if ($id) return (string) $id;
        } catch (Throwable $e) { app_log_ignored('MemberModel.php:546', $e); /* links table not created yet */ }

        if ($verified) return (string) $verified['member_id'];

        $st = $this->db->prepare("SELECT member_id FROM members WHERE email = :e
            ORDER BY (chapter = 'generic') DESC, id ASC LIMIT 1");
        $st->execute(['e' => $app['email']]);
        $id = $st->fetchColumn();
        return $id ? (string) $id : null;
    }

    /**
     * New member row. With $useId the person keeps an ID they already have (no new one is made).
     * Otherwise: one letter for the chapter, then 8 random digits (D = DCW, A = AMU, J = Jamia,
     * P = Photographers; e.g. A48213977). Random rather than sequential so IDs don't reveal
     * how many members exist or let anyone guess neighbours. Digits may start with 0. Stored
     * as a string. A clash on the (member_id, chapter) unique key is retried with a new number.
     */
    private function insertMember(array $app, string $chapter, string $exp, ?string $useId = null): string {
        $insert = function (string $memberId) use ($app, $chapter, $exp) {
            $this->db->prepare('INSERT INTO members (member_id, email, full_name, chapter, expires_at, application_id)
                VALUES (:m, :e, :n, :c, :x, :a)')
                ->execute(['m' => $memberId, 'e' => $app['email'], 'n' => $app['applicant_name'],
                           'c' => $chapter, 'x' => $exp, 'a' => $app['id']]);
        };

        if ($useId !== null) {
            $insert($useId);
            return $useId;
        }

        $prefix = self::ID_PREFIX[$chapter] ?? null;
        if ($prefix === null) throw new Exception('No member ID letter defined for this chapter.');

        for ($try = 1; $try <= 5; $try++) {
            $digits = str_pad((string) random_int(0, (10 ** self::MEMBER_ID_DIGITS) - 1), self::MEMBER_ID_DIGITS, '0', STR_PAD_LEFT);
            $memberId = $prefix . $digits;
            try {
                $insert($memberId);
                return $memberId;
            } catch (PDOException $e) {
                $dup   = (int) ($e->errorInfo[1] ?? 0) === 1062;   // MySQL/MariaDB duplicate key
                $clash = $dup && isset($e->errorInfo[2]) && str_contains($e->errorInfo[2], 'member_id');
                if (!$clash || $try === 5) throw $e;
            }
        }
        throw new Exception('Could not generate a unique member ID.');
    }

    // ---- Shared IDs: reviewer tools and lookups ---------------------------------------------

    /** Every membership held under one Member ID, Generic Community first. */
    public function membershipsFor(string $memberId): array {
        $st = $this->db->prepare("SELECT * FROM members WHERE member_id = :m ORDER BY (chapter = 'generic') DESC, chapter");
        $st->execute(['m' => $memberId]);
        return $st->fetchAll();
    }

    /** The members row an application produced (same email and chapter), or null. */
    public function memberRowFor(array $app): ?array {
        $chapter = self::chapterOf($app);
        if ($chapter === null) return null;
        $st = $this->db->prepare('SELECT * FROM members WHERE email = :e AND chapter = :c LIMIT 1');
        $st->execute(['e' => $app['email'], 'c' => $chapter]);
        return $st->fetch() ?: null;
    }

    /** Every membership held under one email (any chapter), Generic Community first. */
    public function membershipsForEmail(string $email): array {
        $st = $this->db->prepare("SELECT member_id, chapter, status, expires_at FROM members
            WHERE email = :e ORDER BY (chapter = 'generic') DESC, id ASC");
        $st->execute(['e' => $email]);
        return $st->fetchAll();
    }

    /**
     * The account behind an email address (Generic Community row first), or null when the address
     * holds no membership. Used by the application form gate: a logged-out visitor whose address
     * belongs to a member is asked to sign in instead of being sent a verification code.
     * Returns member_id, full_name and status. Every status counts, struck-off included, so a
     * struck-off member cannot get past the gate as a guest.
     */
    public function accountForEmail(string $email): ?array {
        $st = $this->db->prepare("SELECT member_id, full_name, status FROM members
            WHERE email = :e ORDER BY (chapter = 'generic') DESC, id ASC LIMIT 1");
        $st->execute(['e' => trim($email)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findForIdReminder(string $usernameKey, string $email): ?array {
        $excluded = self::ID_REMINDER_EXCLUDED_STATUSES;
        $sql = 'SELECT m.member_id, m.email, m.full_name
                  FROM members m
                  JOIN member_profile p ON p.member_id = m.member_id
                 WHERE p.username_key = ?
                   AND p.username_verified_at IS NOT NULL
                   AND LOWER(m.email) = ?';
        $params = [$usernameKey, strtolower(trim($email))];
        if ($excluded) {
            $sql .= ' AND m.status NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
            array_push($params, ...$excluded);
        }
        $st = $this->db->prepare($sql . ' ORDER BY (m.chapter = \'generic\') DESC, m.id ASC LIMIT 1');
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        // Struck-off members get no reminder (the page shows the same message either way).
        if ($row && (new MemberStrikeModel())->isStruck((string) $row['member_id'])) return null;
        return $row;
    }

    /**
     * lower-cased email => memberships (member_id, chapter, status, expires_at) for EVERY member.
     * Kept for older callers; the review pages use membershipsForEmails() for the rows on one page.
     */
    public function membershipsByEmail(): array {
        $out = [];
        $q = $this->db->query("SELECT email, member_id, chapter, status, expires_at FROM members
            ORDER BY (chapter = 'generic') DESC, id ASC");
        foreach ($q as $r) {
            $out[strtolower((string) $r['email'])][] = [
                'member_id' => (string) $r['member_id'], 'chapter' => $r['chapter'],
                'status' => $r['status'], 'expires_at' => $r['expires_at'],
            ];
        }
        return $out;
    }

    /** The Member ID attached to an application (typed on the renewal form, or set for a join), or null. Never throws. */
    public static function statedMemberId(array $app): ?string {
        try { return self::applicationMemberId($app); } catch (InvalidArgumentException $e) { return null; }
    }

    /**
     * What approval will do about the Member ID, for the reviewer:
     *   ['id' => 'A48213977', 'error' => null]  this ID is kept / reused (no new one is made)
     *   ['id' => null,        'error' => null]  nothing held yet: a new ID will be generated
     *   ['id' => null,        'error' => '...'] the ID on the application does not check out; approval would fail
     */
    public function idOutcome(array $app): array {
        if (self::chapterOf($app) === null) return ['id' => null, 'error' => null];
        try {
            $verified = $this->verifyRenewalMember($app);
            $own = $this->memberRowFor($app);                       // renewal: the chapter's own ID is kept
            if ($own) return ['id' => (string) $own['member_id'], 'error' => null];
            return ['id' => $this->sharedIdFor($app, $verified), 'error' => null];
        } catch (InvalidArgumentException $e) {
            return ['id' => null, 'error' => $e->getMessage()];
        }
    }

    /** "email|chapter" => true for EVERY membership. Kept for older callers; see heldPairsFor(). */
    public function heldPairs(): array {
        $out = [];
        foreach ($this->db->query('SELECT email, chapter FROM members') as $r) {
            $out[strtolower((string) $r['email']) . '|' . $r['chapter']] = true;
        }
        return $out;
    }

    /** "email|chapter" => ['member_id' => ..., 'expires_at' => ...] for EVERY membership. Kept for older callers. */
    public function memberInfoMap(): array {
        $out = [];
        foreach ($this->db->query('SELECT email, chapter, member_id, expires_at FROM members') as $r) {
            $out[strtolower((string) $r['email']) . '|' . $r['chapter']] =
                ['member_id' => (string) $r['member_id'], 'expires_at' => (string) $r['expires_at']];
        }
        return $out;
    }

    /** Plain-words description of an application, for the review screens. */
    public static function kindLabel(array $app, array $held): string {
        $chapter = self::chapterOf($app);
        $email = strtolower((string) $app['email']);
        if (self::isRenewal($app)) {
            // A "renewal" for a chapter they do not hold yet is really a join.
            $holdsThis = $chapter !== null && isset($held[$email . '|' . $chapter]);
            return ($chapter !== null && !$holdsThis) ? 'Existing member joining another chapter' : 'Renewal';
        }
        foreach (self::CHAPTERS as $c) {
            if ($c !== $chapter && isset($held[$email . '|' . $c])) return 'Existing member joining another chapter';
        }
        return 'New applicant';
    }

    /** The reviewer link on an application, or null. */
    public function linkFor(int $appId): ?array {
        try {
            $st = $this->db->prepare('SELECT member_id, linked_by, linked_at FROM membership_id_links WHERE application_id = :a');
            $st->execute(['a' => $appId]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** A Generic Community member by ID, checked for format, existence and (unless confirmed) email. */
    private function genericRow(string $memberId, string $applicantEmail, bool $confirmOtherEmail): array {
        $id = strtoupper(trim($memberId));
        if (!preg_match('/^[A-Z]\d{' . self::MEMBER_ID_DIGITS . '}$/', $id)) {
            throw new Exception('A Member ID is one letter followed by ' . self::MEMBER_ID_DIGITS . ' digits, for example D48213977.');
        }
        $st = $this->db->prepare("SELECT * FROM members WHERE member_id = :m AND chapter = 'generic'");
        $st->execute(['m' => $id]);
        $g = $st->fetch();
        if (!$g) throw new Exception('No DCW Generic Community member has that ID.');
        if (strcasecmp((string) $g['email'], $applicantEmail) !== 0 && !$confirmOtherEmail) {
            throw new Exception('That ID belongs to a different email address (' . $g['email']
                . '). Tick "Link even if the email differs" if this is the same person.');
        }
        return $g;
    }

    private function clubChapterOrFail(array $app): string {
        $chapter = self::chapterOf($app);
        if ($chapter === null || $chapter === 'generic') {
            throw new Exception('Only club memberships can be linked to a Generic Member ID.');
        }
        return $chapter;
    }

    private function saveLink(int $appId, string $memberId, string $by): void {
        $this->db->prepare('INSERT INTO membership_id_links (application_id, member_id, linked_by)
            VALUES (:a, :m, :b)
            ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), linked_by = VALUES(linked_by), linked_at = NOW()')
            ->execute(['a' => $appId, 'm' => $memberId, 'b' => $by]);
    }

    /** Open club application: remember which Generic ID to reuse at approval. */
    public function linkMemberId(array $app, string $memberId, string $by, bool $confirmOtherEmail): void {
        if (!in_array($app['status'], self::OPEN, true)) {
            throw new Exception('Only open applications can be linked. For an approved membership use "Change Member ID".');
        }
        $this->clubChapterOrFail($app);
        $g = $this->genericRow($memberId, (string) $app['email'], $confirmOtherEmail);
        $this->saveLink((int) $app['id'], (string) $g['member_id'], $by);
    }

    public function unlinkMemberId(int $appId): void {
        $this->db->prepare('DELETE FROM membership_id_links WHERE application_id = :a')->execute(['a' => $appId]);
    }

    /**
     * Already approved club membership (an old request that got its own ID): move it onto the
     * Generic ID. Returns the ID it had before. The member keeps their dates; only the ID changes.
     */
    public function relinkApproved(array $app, string $memberId, string $by, bool $confirmOtherEmail): string {
        $chapter = $this->clubChapterOrFail($app);
        if ($app['status'] !== 'Accepted') throw new Exception('Only an approved membership can have its ID changed.');
        $row = $this->memberRowFor($app);
        if (!$row) throw new Exception('No member record was found for this application.');

        $g = $this->genericRow($memberId, (string) $app['email'], $confirmOtherEmail);
        $new = (string) $g['member_id'];
        if ($row['member_id'] === $new) throw new Exception('This member already has that ID.');

        $st = $this->db->prepare('SELECT id FROM members WHERE member_id = :m AND chapter = :c AND id <> :id');
        $st->execute(['m' => $new, 'c' => $chapter, 'id' => $row['id']]);
        if ($st->fetch()) throw new Exception('Someone already holds that ID in this club.');

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE members SET member_id = :m WHERE id = :id')->execute(['m' => $new, 'id' => $row['id']]);
            $this->saveLink((int) $app['id'], $new, $by);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return (string) $row['member_id'];
    }
}
