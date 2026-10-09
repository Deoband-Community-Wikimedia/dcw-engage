<?php
/**
 * DCW Engage - Form Model
 * 
 * Handles parsing and validation of dynamic JSON form schemas.
 */

require_once __DIR__ . '/MemberModel.php';   // chapterOfSlug(): which chapter a membership slug belongs to

class FormModel {
    /**
     * form_type values that count as membership forms. Plain organizers must
     * not see or open these. VERIFY against your data:
     *   SELECT form_type, COUNT(*) FROM forms GROUP BY form_type;
     * and list every membership value here (e.g. 'membership-renewal-2026').
     */
    const MEMBERSHIP_TYPES = ['membership'];

    // Any slug starting with one of these is a membership form too, so a new
    // 'membership-renewal-2027' is covered without editing a list. This matches
    // ApplicationModel, which already treats 'membership-renewal*' as renewals.
    const MEMBERSHIP_PREFIXES = ['membership-', 'membership_'];

    /**
     * Timezone deadlines are typed and shown in. Deadlines are STORED in UTC
     * (forms.deadline_at) and PHP runs in UTC (see includes/init.php); this is
     * only for input and display.
     */
    const DISPLAY_TZ = 'Asia/Kolkata';

    public static function isMembershipType($formType) {
        $formType = (string) $formType;
        if (in_array($formType, self::MEMBERSHIP_TYPES, true)) {
            return true;
        }
        foreach (self::MEMBERSHIP_PREFIXES as $prefix) {
            if (str_starts_with($formType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Deadline time helpers: stored in UTC, typed and shown in IST.
    // ---------------------------------------------------------------------

    /** Stored UTC 'Y-m-d H:i:s' string -> Unix timestamp, or null when empty/unparseable. */
    public static function utcToTs($utc): ?int {
        if ($utc === null || trim((string) $utc) === '') return null;
        try {
            return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Stored UTC deadline -> value for <input type="datetime-local">, in IST ('' when none). */
    public static function utcToIstInput($utc): string {
        $ts = self::utcToTs($utc);
        if ($ts === null) return '';
        return (new DateTimeImmutable('@' . $ts))
            ->setTimezone(new DateTimeZone(self::DISPLAY_TZ))
            ->format('Y-m-d\TH:i');
    }

    /** A datetime-local value typed in IST -> Unix timestamp, or null if it is not a valid date and time. */
    public static function istInputToTs($input): ?int {
        $input = str_replace(' ', 'T', trim((string) $input));
        if ($input === '') return null;
        $tz = new DateTimeZone(self::DISPLAY_TZ);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $input, $tz);
            $err = DateTimeImmutable::getLastErrors();
            if ($dt && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                return $dt->getTimestamp();
            }
        }
        return null;
    }

    /** Stored UTC deadline -> text in IST, e.g. "9 Oct 2026, 5:30 PM IST" ('' when none). */
    public static function formatIst($utc): string {
        $ts = self::utcToTs($utc);
        if ($ts === null) return '';
        return (new DateTimeImmutable('@' . $ts))
            ->setTimezone(new DateTimeZone(self::DISPLAY_TZ))
            ->format('j M Y, g:i A') . ' IST';
    }

    /**
     * Is this form accepting submissions right now?
     * Open = active AND (no deadline OR deadline still in the future).
     * The stored deadline is UTC and is compared as UTC against time().
     */
    public static function isOpen(array $form): bool {
        if (isset($form['is_active']) && !(int) $form['is_active']) return false;
        if (empty($form['deadline_at'])) return true;
        $ts = self::utcToTs($form['deadline_at']);
        if ($ts === null) return true;   // unreadable deadline: treat as no deadline
        return $ts > time();
    }

    /** True when the form is switched on but has been closed automatically by its deadline. */
    public static function closedByDeadline(array $form): bool {
        if (empty($form['is_active']) || empty($form['deadline_at'])) return false;
        $ts = self::utcToTs($form['deadline_at']);
        return $ts !== null && $ts <= time();
    }

    private static $coordChapters = null;

    /**
     * Chapters the signed-in membership coordinator is assigned to (membership_scopes), cached for
     * the request. Empty for anyone who does not hold the coordinator role.
     */
    public static function coordinatorChapters(): array {
        if (!Auth::hasAnyRole(['membership_coordinator'])) return [];
        if (self::$coordChapters === null) {
            try {
                self::$coordChapters = (new MemberModel())->chaptersFor((string) Auth::email());
            } catch (Throwable $e) {
                self::$coordChapters = [];
            }
        }
        return self::$coordChapters;
    }

    /** True when the signed-in coordinator is assigned to the chapter this membership slug belongs to. */
    public static function coordinatesSlug(string $formType): bool {
        $chapter = MemberModel::chapterOfSlug($formType);
        return $chapter !== null && in_array($chapter, self::coordinatorChapters(), true);
    }

    /**
     * Can the signed-in user open this form in the form manager?
     *   membership forms -> membership_reviewer, owner (every chapter);
     *                       membership_coordinator (only forms of their own chapters)
     *   all other forms  -> organizer, owner
     * One rule, used by the dashboard grid and form_manager.php (and the
     * builder) so they can't drift apart.
     */
    public static function userCanOpen(array $form): bool {
        if (self::isMembershipType($form['form_type'])) {
            return Auth::hasAnyRole(['membership_reviewer', 'owner'])
                || self::coordinatesSlug((string) $form['form_type']);
        }
        return Auth::hasAnyRole(['organizer', 'owner']);
    }

    /**
     * Can the signed-in user create this form / edit its schema (also checked against a NEW slug, so a
     * form cannot be renamed into somebody else's chapter)?
     *   membership forms -> owner; membership_coordinator for forms of their own chapters
     *   all other forms  -> organizer, owner
     */
    public static function userCanEdit(array $form): bool {
        if (self::isMembershipType($form['form_type'])) {
            return Auth::hasAnyRole(['owner']) || self::coordinatesSlug((string) $form['form_type']);
        }
        return Auth::hasAnyRole(['organizer', 'owner']);
    }

    /** Deleting a form (and all its responses): owners for membership forms, organizers and owners for the rest. */
    public static function userCanDelete(array $form): bool {
        if (self::isMembershipType($form['form_type'])) {
            return Auth::hasAnyRole(['owner']);
        }
        return Auth::hasAnyRole(['organizer', 'owner']);
    }

    /** Message for the builder when userCanEdit() refuses a slug. */
    public static function editDeniedMessage(string $formType): string {
        if (!self::isMembershipType($formType)) {
            return 'Only organizers can create or edit that kind of form.';
        }
        if (Auth::hasAnyRole(['membership_coordinator'])) {
            return 'You can only manage membership forms for your own chapters. Start the URL slug with '
                . 'membership-<chapter> or membership-renewal-<chapter>- (for example membership-amu or '
                . 'membership-renewal-amu-2027).';
        }
        return 'That URL slug is reserved for membership forms.';
    }

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /**
     * Fetch an OPEN form by its type (e.g., 'scholarship').
     * A form that is switched off, or whose deadline has passed, comes back as
     * false, so the renderer falls through to getAnyFormByType() -> closed.php.
     */
    public function getFormByType($formType) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE form_type = :type AND is_active = 1");
        $stmt->execute(['type' => $formType]);
        $form = $stmt->fetch();

        if (!$form || !self::isOpen($form)) {
            return false;
        }

        $form['schema'] = json_decode($form['schema_json'], true);
        return $form;
    }

    /**
     * Fetch a form by type regardless of its active state or deadline.
     * Used to tell a closed form apart from one that never existed, so the
     * public renderer can show the right message instead of a blanket 404.
     */
    public function getAnyFormByType($formType) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE form_type = :type");
        $stmt->execute(['type' => $formType]);
        $form = $stmt->fetch();

        if ($form) {
            $form['schema'] = json_decode($form['schema_json'], true);
        }

        return $form;
    }

    public function validateSubmission($schema, $postData, $skipRequired = false) {
        $errors = [];

        foreach ($schema['fields'] as $field) {
            $name = $field['name'];
            $isRequired = $field['required'] ?? false;
            $type = $field['type'] ?? 'text';

            // Check required — skipped entirely for a draft save, which is
            // allowed to be incomplete by definition.
            if ($isRequired && !$skipRequired) {
                if ($type === 'file') {
                    // Check if file was uploaded or if existing path is provided (for resume portal)
                    if (empty($_FILES[$name]['name']) && empty($postData[$name])) {
                        $errors[$name] = ($field['label'] ?? $name) . " is required.";
                    }
                } else {
                    if (empty($postData[$name])) {
                        $errors[$name] = ($field['label'] ?? $name) . " is required.";
                    }
                }
            }
        }
        
        return $errors;
    }

    /**
     * Fetch all forms for the admin grid (includes deadline_at via f.*)
     */
    public function getAllForms() {
        $stmt = $this->db->query("
            SELECT f.*,
                   JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) as title,
                   (SELECT COUNT(*) FROM applications a WHERE a.form_id = f.id) as applicant_count
            FROM forms f
            ORDER BY f.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Open forms only (active and not past their deadline), with title and
     * description pulled from the schema. Powers the public homepage listing
     * at engage.dcwwiki.org. Filtered in PHP so the deadline uses the same
     * clock as everything else.
     */
    public function getActiveForms() {
        $stmt = $this->db->query("
            SELECT form_type, is_active, deadline_at,
                   JSON_UNQUOTE(JSON_EXTRACT(schema_json, '$.title')) as title,
                   JSON_UNQUOTE(JSON_EXTRACT(schema_json, '$.description')) as description
            FROM forms
            WHERE is_active = 1
            ORDER BY created_at DESC
        ");
        return array_values(array_filter($stmt->fetchAll(), [self::class, 'isOpen']));
    }

    /**
     * Fetch a form by ID
     */
    public function getFormById($id) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $form = $stmt->fetch();
        
        if ($form) {
            $form['schema'] = json_decode($form['schema_json'], true);
            $form['title'] = $form['schema']['title'] ?? 'Untitled Form';
        }
        
        return $form;
    }

    /**
     * Toggle active status
     */
    public function toggleFormStatus($id, $isActive) {
        $stmt = $this->db->prepare("UPDATE forms SET is_active = :status WHERE id = :id");
        return $stmt->execute(['status' => $isActive ? 1 : 0, 'id' => $id]);
    }

    /**
     * Set (or clear) the deadline. $deadline is null or '' to clear, a Unix
     * timestamp (int) to set, or a stored-format UTC 'Y-m-d H:i:s' string.
     * Always written as UTC. No "must be later" rule here; that rule belongs
     * to extendDeadline().
     */
    public function setDeadline($id, $deadline) {
        if ($deadline === null || (is_string($deadline) && trim($deadline) === '')) {
            $value = null;
        } else {
            $ts = is_int($deadline) ? $deadline : self::utcToTs($deadline);
            if ($ts === null) {
                throw new InvalidArgumentException('That is not a valid deadline date.');
            }
            $value = gmdate('Y-m-d H:i:s', $ts);
        }
        $stmt = $this->db->prepare("UPDATE forms SET deadline_at = :d WHERE id = :id");
        return $stmt->execute(['d' => $value, 'id' => $id]);
    }

    /**
     * Extend a form's deadline. $newDeadline is what the organizer typed in the
     * datetime-local box, in IST. It must be in the future and later than the
     * current one (so "extend" can't shorten it). Pass null or an empty string
     * to remove the deadline entirely.
     * Works on a form that has already closed by deadline: is_active is never
     * touched, so extending re-opens it (unless an admin switched it off).
     * Same permission as closing/re-opening: anyone who can open the form.
     */
    public function extendDeadline($id, $newDeadline) {
        $form = $this->getFormById($id);
        if (!$form) {
            throw new InvalidArgumentException('Form not found.');
        }
        if (!self::userCanOpen($form)) {
            throw new Exception('You are not allowed to change this form.');
        }

        if ($newDeadline === null || trim((string) $newDeadline) === '') {
            return $this->setDeadline($id, null);
        }

        $ts = self::istInputToTs($newDeadline);
        if ($ts === null || $ts <= time()) {
            throw new InvalidArgumentException('The new deadline must be a valid date in the future.');
        }
        $currentTs = self::utcToTs($form['deadline_at'] ?? null);
        if ($currentTs !== null && $ts <= $currentTs) {
            throw new InvalidArgumentException('The new deadline must be later than the current one.');
        }
        return $this->setDeadline($id, $ts);
    }

    /**
     * Delete a form entirely (Cascades to applications)
     */
    public function deleteForm($id) {
        $stmt = $this->db->prepare("DELETE FROM forms WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
