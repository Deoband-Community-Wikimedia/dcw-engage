<?php
/**
 * DCW Engage - Feedback Model
 *
 * Feedback forms are a second "kind" of form (schema_json -> "kind": "feedback").
 * Their responses live in their own table, feedback_responses, and never touch
 * `applications`: no tracking ID, no status, no draft, no magic link, no review queue.
 */

require_once __DIR__ . '/FormModel.php';   // FormModel::kindOf(): the one place a form's kind is decided

class FeedbackModel {
    /** Retention used when config has no security.feedback_retention_months. */
    const DEFAULT_RETENTION_MONTHS = 12;

    const MAX_TEXT = 500;
    const MAX_LONG = 5000;

    // Abuse limits. The receipt goes to an address that has been verified by link before the form
    // opens (see views/forms/feedback.php), so these caps are no longer what stops a stranger being
    // mailed. They only stop one verified address, or one form, from being flooded with submissions.
    // Verification emails themselves are throttled by EmailVerificationModel::MAX_REQUESTS_PER_HOUR.
    const MAX_PER_EMAIL_PER_HOUR   = 3;
    const MAX_PER_FORM_PER_10_MIN  = 60;

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    public static function isFeedbackSchema($schema): bool {
        return is_array($schema) && ($schema['kind'] ?? '') === 'feedback';
    }

    /**
     * Is this forms row a feedback form? Works on any row that carries one of:
     *   kind         (FormModel::getActiveForms())
     *   schema       (decoded schema, e.g. from getFormById())
     *   schema_json  (raw JSON, e.g. from SELECT * FROM forms)
     * Use this wherever forms are listed as "programs" (home page, member dashboard)
     * so feedback forms are kept out of them.
     *
     * A row that has a slug (form_type) is decided by FormModel::kindOf(), so a membership slug always
     * wins over a stored kind, the same as on the admin dashboard. A row with no slug falls back to the
     * kind or schema it carries.
     */
    public static function isFeedbackRow(array $row): bool {
        if (isset($row['form_type'])) {
            return FormModel::kindOf($row) === 'feedback';
        }
        if (isset($row['kind']) && $row['kind'] !== '') {
            return $row['kind'] === 'feedback';
        }
        $schema = $row['schema'] ?? (isset($row['schema_json']) ? json_decode((string) $row['schema_json'], true) : null);
        return self::isFeedbackSchema($schema);
    }

    public static function retentionMonths(): int {
        try {
            $config = require __DIR__ . '/../includes/config.php';
            $months = (int) ($config['security']['feedback_retention_months'] ?? 0);
            return $months > 0 ? $months : self::DEFAULT_RETENTION_MONTHS;
        } catch (Throwable $e) {
            return self::DEFAULT_RETENTION_MONTHS;
        }
    }

    /**
     * Turn a POST into answers for the schema's fields, and collect validation errors.
     * Only fields that exist in the schema are kept, so nothing else in the POST can be stored.
     * File fields are not supported on feedback forms and are skipped.
     *
     * @return array [answers (name => string|array), errors (name => message)]
     */
    public static function collectAnswers(array $schema, array $post): array {
        $answers = [];
        $errors = [];

        foreach (($schema['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') continue;

            $label    = (string) ($field['label'] ?? $name);
            $type     = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $options  = array_map('strval', (array) ($field['options'] ?? []));
            $raw      = $post[$name] ?? null;
            $value    = '';

            if ($type === 'file') continue;

            switch ($type) {
                case 'checkbox':
                    $value = !empty($raw) ? 'Yes' : '';
                    break;

                case 'checkbox_group':
                    $picked = is_array($raw) ? array_map('strval', $raw) : [];
                    $value  = array_values(array_intersect($options, $picked));
                    break;

                case 'select':
                    $v = is_string($raw) ? $raw : '';
                    if ($v !== '' && !in_array($v, $options, true)) {
                        $errors[$name] = "Please choose one of the listed options for $label.";
                    } else {
                        $value = $v;
                    }
                    break;

                default:
                    $v = is_string($raw) ? trim($raw) : '';
                    // Strip control characters but keep tab, newline and carriage return.
                    $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $v);
                    $limit = $type === 'textarea' ? self::MAX_LONG : self::MAX_TEXT;

                    if (mb_strlen($v) > $limit) {
                        $errors[$name] = "$label is too long (maximum $limit characters).";
                    } elseif ($v !== '') {
                        if ($type === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                            $errors[$name] = "$label must be a valid email address.";
                        } elseif ($type === 'url' && !filter_var($v, FILTER_VALIDATE_URL)) {
                            $errors[$name] = "$label must be a valid link.";
                        } elseif ($type === 'number' && !is_numeric($v)) {
                            $errors[$name] = "$label must be a number.";
                        } elseif ($type === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                            $errors[$name] = "$label must be a valid date.";
                        }
                    }
                    $value = $v;
            }

            if ($required && !isset($errors[$name]) && ($value === '' || $value === [])) {
                $errors[$name] = "$label is required.";
            }

            $answers[$name] = $value;
        }

        return [$answers, $errors];
    }

    public function create(int $formId, string $name, string $email, array $answers): int {
        $stmt = $this->db->prepare(
            "INSERT INTO feedback_responses (form_id, name, email, answers_json) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $formId,
            $name,
            $email,
            json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Responses from this email to this form in the last $minutes (time is compared by the database). */
    public function recentCountForEmail(int $formId, string $email, int $minutes): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM feedback_responses
             WHERE form_id = ? AND email = ? AND created_at > NOW() - INTERVAL ? MINUTE"
        );
        $stmt->execute([$formId, $email, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    public function recentCountForForm(int $formId, int $minutes): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM feedback_responses
             WHERE form_id = ? AND created_at > NOW() - INTERVAL ? MINUTE"
        );
        $stmt->execute([$formId, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    public function countForForm(int $formId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM feedback_responses WHERE form_id = ?");
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    public function listForForm(int $formId, int $limit, int $offset): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM feedback_responses WHERE form_id = :fid ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':fid', $formId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['answers'] = $row['answers_json'] !== null ? (json_decode($row['answers_json'], true) ?: []) : [];
        }
        return $rows;
    }

    public function getById(int $id) {
        $stmt = $this->db->prepare("SELECT * FROM feedback_responses WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM feedback_responses WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /** Feedback forms with their response counts, for the organizer's feedback list. */
    public function feedbackForms(): array {
        $stmt = $this->db->query("
            SELECT f.id, f.form_type, f.is_active, f.deadline_at,
                   JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) AS title,
                   (SELECT COUNT(*) FROM feedback_responses r WHERE r.form_id = f.id) AS response_count
            FROM forms f
            WHERE JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.kind')) = 'feedback'
            ORDER BY f.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * PII scrub: blank name and email on responses older than $months. The cut-off is computed
     * by the database (same reason as invite/reset expiry: PHP and MySQL clocks can disagree).
     * With $dropAnswers the written answers are removed too; by default they are kept, so the
     * feedback itself stays usable once nobody can be identified from it.
     *
     * @return int rows scrubbed
     */
    public function scrubOlderThan(int $months, bool $dropAnswers = false): int {
        $sql = $dropAnswers
            ? "UPDATE feedback_responses SET name = NULL, email = NULL, answers_json = NULL, scrubbed_at = NOW()
               WHERE scrubbed_at IS NULL AND created_at < NOW() - INTERVAL ? MONTH"
            : "UPDATE feedback_responses SET name = NULL, email = NULL, scrubbed_at = NOW()
               WHERE scrubbed_at IS NULL AND created_at < NOW() - INTERVAL ? MONTH";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$months]);
        return $stmt->rowCount();
    }
}
