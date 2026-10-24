<?php
/**
 * DCW Engage - Award confirmation
 *
 * After a scholarship is awarded, the awardee opens a private link (/confirm/<token>), says whether they will take
 * up the place, and (if so) answers the program's logistics questions: where from, when, mode of travel, food,
 * stay and so on. Organizers read the responses in Application review and can download them as a CSV.
 *
 * - Settings are per program (award_confirm_settings): on/off, a deadline (typed and shown in IST, stored in UTC),
 *   a short intro, and the questions, one per line:   Label | type | required/optional | options (for a select)
 * - The answers are encrypted (security.field_encryption_key) and never stored in plain text.
 * - The application's own status does not change: an awardee who declines stays "Accepted", with the response
 *   "Declined". A freed place is filled by a reviewer accepting whoever they choose; nothing is automatic.
 *
 * Until the migration has been run, the "all..." readers return [] and the whole feature stays off.
 */
require_once __DIR__ . '/FormModel.php';   // deadline helpers (UTC stored, IST shown)

class AwardConfirmationModel {
    public const MAX_FIELDS = 25;
    public const FIELDS_MAX_CHARS = 3000;
    public const INTRO_MAX = 2000;
    public const VALUE_MAX = 1000;
    public const REASON_MAX = 1000;

    /** Question types the line format accepts. */
    private const TYPES = ['text', 'textarea', 'date', 'tel', 'number', 'email', 'select'];

    /** What a new program starts with. Organizers edit this in the "Award confirmation" panel. */
    public const DEFAULT_FIELDS = <<<'TXT'
Travelling from (city) | text | required
Date you will arrive | date | required
Date you will leave | date | required
Mode of travel | select | required | Train, Bus, Own vehicle
Food preference | select | required | Vegetarian, Non-vegetarian, Vegan, No preference
Are you local to the event city? | select | required | Yes, No
Do you need accommodation arranged? | select | required | Yes, No | if Are you local to the event city? = No
Stay preferences | textarea | optional | | if Do you need accommodation arranged? = Yes
Accessibility needs or other requests | textarea | optional
Emergency contact (name and phone) | text | required
TXT;

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Questions: the one-per-line format
    // ------------------------------------------------------------------

    /**
     * Parse the questions text into fields:
     *   [['name' => 'q0', 'label', 'type', 'required' => bool, 'options' => [], 'showif' => null|['name','label','value']], ...].
     * Blank lines are ignored. Throws InvalidArgumentException with a message that is safe to show the organizer.
     *
     * Line format:  Question | type | required or optional | choices (a select only) | if Earlier question = Choice
     * The last part is optional: the question is then shown (and asked) only when the awardee gave that answer to an
     * EARLIER select question. Leave the choices part empty for other types:  Stay preferences | textarea | optional | | if ...
     */
    public static function parseFields(string $text): array {
        $fields = [];
        $seen = [];
        foreach (preg_split('/\R/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;
            $at = 'Line ' . ($i + 1) . ': ';

            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0];
            $type = strtolower($parts[1] ?? 'text');
            $req = strtolower($parts[2] ?? 'optional');

            if ($label === '') throw new InvalidArgumentException($at . 'the question is empty.');
            if (mb_strlen($label) > 150) throw new InvalidArgumentException($at . 'the question is longer than 150 characters.');
            if (!in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException($at . "\"$type\" is not a type. Use one of: " . implode(', ', self::TYPES) . '.');
            }
            if (!in_array($req, ['required', 'optional'], true)) {
                throw new InvalidArgumentException($at . "the third part must be \"required\" or \"optional\".");
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) throw new InvalidArgumentException($at . 'this question is already asked above.');
            $seen[$key] = true;

            $options = [];
            if ($type === 'select') {
                $options = array_values(array_unique(array_filter(array_map('trim', explode(',', $parts[3] ?? '')), 'strlen')));
                if (count($options) < 2) {
                    throw new InvalidArgumentException($at . 'a select needs at least two choices, separated by commas, as the fourth part.');
                }
                if (count($options) > 20) throw new InvalidArgumentException($at . 'a select can have at most 20 choices.');
            }

            // Optional "only if" rule: it must point at a select question listed ABOVE this one, and at one of its choices.
            $showif = null;
            if (($parts[4] ?? '') !== '') {
                if (!preg_match('/^if\s+(.+?)\s*=\s*(.+)$/iu', $parts[4], $m)) {
                    throw new InvalidArgumentException($at . 'the fifth part must look like:  if Earlier question = Choice');
                }
                [$ctlLabel, $ctlValue] = [trim($m[1]), trim($m[2])];
                $ctl = null;
                foreach ($fields as $prev) {
                    if (mb_strtolower($prev['label']) === mb_strtolower($ctlLabel)) { $ctl = $prev; break; }
                }
                if ($ctl === null) {
                    throw new InvalidArgumentException($at . "\"$ctlLabel\" must be a question listed above this one.");
                }
                if ($ctl['type'] !== 'select') {
                    throw new InvalidArgumentException($at . "\"$ctlLabel\" is not a select, so it cannot decide whether this question is shown.");
                }
                if (!in_array($ctlValue, $ctl['options'], true)) {
                    throw new InvalidArgumentException($at . "\"$ctlValue\" is not one of the choices of \"$ctlLabel\".");
                }
                $showif = ['name' => $ctl['name'], 'label' => $ctl['label'], 'value' => $ctlValue];
            }

            $fields[] = ['name' => 'q' . count($fields), 'label' => $label, 'type' => $type,
                         'required' => $req === 'required', 'options' => $options, 'showif' => $showif];
            if (count($fields) > self::MAX_FIELDS) {
                throw new InvalidArgumentException('Ask at most ' . self::MAX_FIELDS . ' questions.');
            }
        }
        return $fields;
    }

    /**
     * Which questions are shown, given the current answers ($values: field name => answer).
     * A question with an "only if" rule is shown when the question it depends on is itself shown and has that answer,
     * so a chain (local? -> accommodation? -> stay preferences) hides everything below a "no".
     * Returns [field name => bool]. Used for what the page shows AND for what the server accepts, so a hidden
     * question can never be required, validated or saved.
     */
    public static function visibleMap(array $fields, array $values): array {
        $vis = [];
        foreach ($fields as $f) {
            $c = $f['showif'] ?? null;
            if ($c === null) { $vis[$f['name']] = true; continue; }
            $v = $values[$c['name']] ?? '';
            $v = is_scalar($v) ? trim((string) $v) : '';
            $vis[$f['name']] = !empty($vis[$c['name']]) && $v === $c['value'];
        }
        return $vis;
    }

    // ------------------------------------------------------------------
    // Encryption of the answers (AES-256-GCM, key from config security.field_encryption_key)
    // ------------------------------------------------------------------

    private static function key(): string {
        $config = require __DIR__ . '/../includes/config.php';
        $k = (string) ($config['security']['field_encryption_key'] ?? '');
        if (strlen($k) < 16) {
            throw new RuntimeException('security.field_encryption_key is missing or too short in config.php.');
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(array $data): string {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Could not encrypt the answers.');
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    /** The stored blob back to ['answers' => [['label','value'],...], 'reason' => '']. Empty on any problem. */
    public static function decrypt($blob): array {
        $empty = ['answers' => [], 'reason' => ''];
        $blob = (string) $blob;
        if ($blob === '' || strpos($blob, 'v1:') !== 0) return $empty;
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) return $empty;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $data = $plain === false ? null : json_decode($plain, true);
        if (!is_array($data)) return $empty;
        return ['answers' => is_array($data['answers'] ?? null) ? $data['answers'] : [], 'reason' => (string) ($data['reason'] ?? '')];
    }

    // ------------------------------------------------------------------
    // Settings (per program)
    // ------------------------------------------------------------------

    /** Every program's settings, keyed by form id. [] if the table does not exist yet. */
    public function allSettings(): array {
        try {
            $rows = $this->db->query("SELECT * FROM award_confirm_settings")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[(int) $r['form_id']] = $r;
        return $out;
    }

    /** True when the program has confirmation switched on and has a deadline. */
    public static function isOn($row): bool {
        return is_array($row) && !empty($row['enabled']) && !empty($row['deadline_at']);
    }

    /**
     * Save a program's settings. $deadlineInput is what was typed in the datetime-local box, in IST.
     * Throws an Exception with a message that is safe to show the organizer.
     */
    public function saveSettings($formId, $enabled, $deadlineInput, $fieldsText, $introText, $by): void {
        $norm = fn($s) => trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
        $fieldsText = $norm($fieldsText);
        $introText = $norm($introText);
        if (mb_strlen($fieldsText) > self::FIELDS_MAX_CHARS) throw new Exception('The questions are too long.');
        if (mb_strlen($introText) > self::INTRO_MAX) {
            throw new Exception('The introduction can be at most ' . number_format(self::INTRO_MAX) . ' characters.');
        }
        self::parseFields($fieldsText);   // throws a readable message on a bad line

        $deadlineInput = trim((string) $deadlineInput);
        $ts = FormModel::istInputToTs($deadlineInput);
        if ($deadlineInput !== '' && $ts === null) throw new Exception('That is not a valid deadline.');
        if ($enabled) {
            if ($ts === null) throw new Exception('Set a confirmation deadline (IST) before turning confirmation on.');
            // A deadline that is already stored may stay as it is (so other edits can be saved after it passes);
            // a new or changed deadline must be in the future.
            $current = $this->settingsFor((int) $formId);
            $currentTs = FormModel::utcToTs($current['deadline_at'] ?? null);
            if ($ts !== $currentTs && $ts <= time()) throw new Exception('The deadline must be in the future.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO award_confirm_settings (form_id, enabled, deadline_at, intro_text, fields_text, saved_by, saved_at)
            VALUES (:f, :en, :dl, :intro, :fields, :by, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), deadline_at = VALUES(deadline_at),
                intro_text = VALUES(intro_text), fields_text = VALUES(fields_text),
                saved_by = VALUES(saved_by), saved_at = UTC_TIMESTAMP()
        ");
        $stmt->execute([
            'f' => (int) $formId, 'en' => $enabled ? 1 : 0,
            'dl' => $ts === null ? null : gmdate('Y-m-d H:i:s', $ts),
            'intro' => $introText, 'fields' => $fieldsText,
            'by' => mb_substr((string) $by, 0, 190),
        ]);
    }

    private function settingsFor(int $formId): ?array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM award_confirm_settings WHERE form_id = :f");
            $stmt->execute(['f' => $formId]);
            $r = $stmt->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Awardees: link, response, state
    // ------------------------------------------------------------------

    /** Every confirmation row, keyed by application id. [] if the table does not exist yet. */
    public function allByApplication(): array {
        try {
            $rows = $this->db->query("SELECT id, application_id, response, responded_at, reminder_count, last_reminded_at, answers_enc FROM award_confirmations")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[(int) $r['application_id']] = $r;
        return $out;
    }

    /** This awardee's row, creating it (with a new private link) the first time. */
    public function ensureFor(int $applicationId): array {
        $this->db->prepare("INSERT IGNORE INTO award_confirmations (application_id, token) VALUES (:a, :t)")
                 ->execute(['a' => $applicationId, 't' => bin2hex(random_bytes(32))]);
        $stmt = $this->db->prepare("SELECT * FROM award_confirmations WHERE application_id = :a");
        $stmt->execute(['a' => $applicationId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Could not create the confirmation link.');
        return $row;
    }

    /** The awardee's private link. The same link every time, so earlier emails keep working. */
    public function urlFor(int $applicationId, string $appUrl): string {
        return rtrim($appUrl, '/') . '/confirm/' . $this->ensureFor($applicationId)['token'];
    }

    /** Everything the public page needs for one link, or false when the link is unknown. */
    public function byToken(string $token) {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return false;
        try {
            $stmt = $this->db->prepare("
                SELECT c.id AS conf_id, c.application_id, c.token, c.response, c.answers_enc, c.responded_at,
                       a.email, a.applicant_name, a.tracking_id, a.status, a.form_id,
                       f.form_type, f.notify_emails,
                       JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) AS form_title,
                       s.enabled, s.deadline_at, s.fields_text, s.intro_text
                FROM award_confirmations c
                JOIN applications a ON a.id = c.application_id
                JOIN forms f ON f.id = a.form_id
                LEFT JOIN award_confirm_settings s ON s.form_id = a.form_id
                WHERE c.token = :t
            ");
            $stmt->execute(['t' => $token]);
            return $stmt->fetch() ?: false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Record the awardee's response. $answers is a list of ['label' => ..., 'value' => ...] (empty when declining).
     * Everything personal is stored encrypted.
     */
    public function respond(int $confId, string $response, array $answers, string $reason): void {
        if (!in_array($response, ['confirmed', 'declined'], true)) throw new InvalidArgumentException('Unknown response.');
        $blob = self::encrypt(['answers' => $answers, 'reason' => $reason]);
        $this->db->prepare("UPDATE award_confirmations SET response = :r, answers_enc = :b, responded_at = UTC_TIMESTAMP() WHERE id = :id")
                 ->execute(['r' => $response, 'b' => $blob, 'id' => $confId]);
    }

    /** A reminder (or the first link) was emailed. */
    public function markReminded(int $applicationId): void {
        $this->db->prepare("UPDATE award_confirmations SET reminder_count = reminder_count + 1, last_reminded_at = UTC_TIMESTAMP() WHERE application_id = :a")
                 ->execute(['a' => $applicationId]);
    }

    /**
     * Where an application stands:
     *   confirmed | declined | awaiting (link out, deadline not passed) | overdue (no answer, deadline passed)
     *   | none (not an awarded application, or confirmation is not in use for it).
     */
    public static function stateOf($conf, $settings, string $status): string {
        if ($status !== 'Accepted') return 'none';
        $response = is_array($conf) ? (string) ($conf['response'] ?? 'pending') : 'pending';
        if ($response === 'confirmed' || $response === 'declined') return $response;
        if (!self::isOn($settings)) return is_array($conf) ? 'awaiting' : 'none';
        $ts = FormModel::utcToTs($settings['deadline_at']);
        return ($ts !== null && $ts <= time()) ? 'overdue' : 'awaiting';
    }
}
