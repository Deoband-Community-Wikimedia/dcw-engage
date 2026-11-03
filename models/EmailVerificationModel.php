<?php
/**
 * DCW Engage - Applicant Email Verification (OTP)
 *
 * Proving control of an email address is the first step of every application
 * (see #67). Nothing is written to `applications` until it has been done.
 *
 * An 8-character one-time code is emailed to the applicant, who types it into
 * the page they already have open. Only an HMAC of the code is stored, and it
 * works once.
 *
 * Security rests on three things: the short expiry, the per-row attempt cap,
 * and the hourly cap on codes issued per address. consume() burns an attempt
 * atomically BEFORE comparing, so concurrent guesses cannot exceed the cap.
 *
 * The applicant's name (typed on the gate form) travels with the code so later
 * emails can greet them by name. It is optional (NULL for older rows).
 *
 * $formId is nullable throughout this class: NULL means "not tied to any
 * specific event's application form" (global reimbursement flow). Every query
 * compares form_id with the NULL-safe operator (<=>) because `= NULL` never
 * matches, which would silently break rate-limiting, superseding and
 * redemption for formless verifications.
 */

class EmailVerificationModel {
    /** How long a code stays usable (overridable via security.email_verify_expiry). */
    private const DEFAULT_EXPIRY = '+10 minutes';

    /** Codes one address may ask for, per form, per hour. */
    private const MAX_REQUESTS_PER_HOUR = 3;

    /** Minimum gap between two codes for the same address and form. */
    public const RESEND_COOLDOWN_SECONDS = 120;

    /** Wrong guesses allowed per code before it is invalidated. */
    private const MAX_ATTEMPTS = 5;

    /** Code length and alphabet. Omits 0/O, 1/I/L to avoid misreads. */
    private const CODE_LENGTH = 8;
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** The placeholder shipped in config.example.php; never acceptable as a real secret. */
    private const PLACEHOLDER_SECRET = 'replace-with-a-long-random-string';

    /** Shortest otp_secret accepted. Generate one with: php -r 'echo bin2hex(random_bytes(32));' */
    private const MIN_SECRET_LENGTH = 32;

    private $db;
    private $expiry;
    private $secret;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();

        $config = require __DIR__ . '/../includes/config.php';
        $this->expiry = $config['security']['email_verify_expiry'] ?? self::DEFAULT_EXPIRY;

        // Refuse to run with a missing, placeholder or short key: a forgotten config line
        // would otherwise quietly give a guessable HMAC key.
        $this->secret = (string) ($config['security']['otp_secret'] ?? '');
        if ($this->secret === ''
            || $this->secret === self::PLACEHOLDER_SECRET
            || strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            throw new \RuntimeException(
                "Set security.otp_secret to a random string of at least " . self::MIN_SECRET_LENGTH . " characters."
            );
        }
    }

    /** Keyed hash, bound to form + email so a leaked hash can't be replayed
     *  against another address and an 8-char code can't be rainbow-tabled. */
    private function hashCode($formId, $email, $code) {
        return hash_hmac(
            'sha256',
            ($formId === null ? 'global' : (string) $formId) . '|' . strtolower($email) . '|' . $code,
            $this->secret
        );
    }

    /**
     * Random code from ALPHABET. Regenerated until it contains at least one letter and one
     * digit, so every code looks alphanumeric (about 9% of purely random codes would be
     * letters only). 
     */
    private function generateCode(): string {
        $max = strlen(self::ALPHABET) - 1;
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }
        } while (!preg_match('/[2-9]/', $code) || !preg_match('/[A-Z]/', $code));
        return $code;
    }

    /** Accepts what people actually type: lowercase, spaces, hyphens. */
    private function normalizeCode($code): ?string {
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code));
        $pattern = '/^[' . self::ALPHABET . ']{' . self::CODE_LENGTH . '}$/';
        return preg_match($pattern, $code) ? $code : null;
    }

    /** For emails/UI: "ABCD-EFGH". */
    public static function formatCode(string $code): string {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /**
     * Start a verification for this address on this form.
     *
     * $name is the applicant's name as typed on the gate form (optional).
     *
     * Returns the raw code and expiry when one was issued, or null when the
     * resend cooldown is still running or the address has asked too many
     * times. The caller must respond identically either way.
     */
    public function request($formId, $email, $name = null) {
        if ($this->resendWaitSeconds($formId, $email) > 0) {
            return null;
        }
        if ($this->recentRequestCount($formId, $email) >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }

        $name = ($name === null || trim((string) $name) === '') ? null : trim((string) $name);

        $code = $this->generateCode();

        $this->db->beginTransaction();

        try {
            // Asking again supersedes the previous code.
            $this->db->prepare(
                "UPDATE email_verifications SET invalidated_at = NOW()
                 WHERE form_id <=> :form AND email = :email
                   AND used_at IS NULL AND invalidated_at IS NULL"
            )->execute(['form' => $formId, 'email' => $email]);

            // Expiry is computed by the database so it and the later NOW()
            // comparison share one clock. A malformed config value must fail
            // loudly rather than silently issuing 60-second codes.
            $parsedExpiry = strtotime($this->expiry);
            if ($parsedExpiry === false) {
                throw new \RuntimeException(
                    "Invalid email_verify_expiry config value: '{$this->expiry}'. " .
                    "Expected a strtotime()-parseable relative format, e.g. '+10 minutes'."
                );
            }
            $seconds = max(60, $parsedExpiry - time());

            $this->db->prepare(
                "INSERT INTO email_verifications (form_id, email, name, token_hash, attempts, expires_at)
                 VALUES (:form, :email, :name, :hash, 0, NOW() + INTERVAL :seconds SECOND)"
            )->execute([
                'form'    => $formId,
                'email'   => $email,
                'name'    => $name,
                'hash'    => $this->hashCode($formId, $email, $code),
                'seconds' => $seconds,
            ]);

            $expiresAt = $this->db->query(
                "SELECT expires_at FROM email_verifications WHERE id = " . (int) $this->db->lastInsertId()
            )->fetchColumn();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Throttle for emails that are NOT a code (for example the "sign in instead" nudge sent to an
     * address that already belongs to a member). It shares the resend cooldown and the hourly cap with
     * request(), so such an email can never be used to flood an inbox.
     *
     * It writes a row that is already invalidated and already expired, so consume() can never match it,
     * and stores no name. It does not touch any real code that is outstanding.
     *
     * Returns true when the email may be sent, false when the cooldown or the hourly cap says no.
     * The caller must respond identically either way.
     */
    public function requestNudge($formId, $email): bool {
        if ($this->resendWaitSeconds($formId, $email) > 0) {
            return false;
        }
        if ($this->recentRequestCount($formId, $email) >= self::MAX_REQUESTS_PER_HOUR) {
            return false;
        }

        $this->db->prepare(
            "INSERT INTO email_verifications (form_id, email, name, token_hash, attempts, expires_at, invalidated_at)
             VALUES (:form, :email, NULL, :hash, 0, NOW(), NOW())"
        )->execute([
            'form'  => $formId,
            'email' => $email,
            'hash'  => hash_hmac('sha256', bin2hex(random_bytes(16)), $this->secret),
        ]);

        return true;
    }

    /**
     * Seconds the applicant still has to wait before another code can be sent
     * (0 when they can ask now). Use it to drive the "Resend" countdown on the
     * code-entry page. Safe to expose: it reveals only the caller's own timer.
     */
    public function resendWaitSeconds($formId, $email): int {
        $stmt = $this->db->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW())
             FROM email_verifications
             WHERE form_id <=> :form AND email = :email"
        );
        $stmt->execute(['form' => $formId, 'email' => $email]);
        $elapsed = $stmt->fetchColumn();

        if ($elapsed === null || $elapsed === false) {
            return 0;   // never asked before
        }
        return max(0, self::RESEND_COOLDOWN_SECONDS - (int) $elapsed);
    }

    /** Counts superseded codes too, otherwise each request would cancel the
     *  previous one and the limit could never be reached. */
    private function recentRequestCount($formId, $email) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM email_verifications
             WHERE form_id <=> :form AND email = :email
               AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute(['form' => $formId, 'email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Redeem a code. Returns ['email' => ..., 'name' => ...] (name is '' when
     * none was given), or null for every kind of failure (unknown, wrong,
     * expired, used, superseded, too many attempts) so the caller cannot tell
     * them apart.
     *
     * Flow: find the active row for (form, email), atomically spend one
     * attempt, THEN compare. Spending first means parallel guesses each cost
     * an attempt and the cap cannot be raced. When the last attempt is spent
     * on a wrong guess the row is invalidated.
     *
     * CALLER CONTRACT: call this from a POST (the code-entry form), with the
     * email carried over from the gate form.
     */
    public function consume($formId, $email, $code) {
        $code = $this->normalizeCode($code);
        if ($code === null || !is_string($email) || $email === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, email, name, token_hash FROM email_verifications
             WHERE form_id <=> :form AND email = :email
               AND used_at IS NULL AND invalidated_at IS NULL
               AND expires_at > NOW() AND attempts < :max
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['form' => $formId, 'email' => $email, 'max' => self::MAX_ATTEMPTS]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Spend an attempt atomically before looking at the code.
        $spend = $this->db->prepare(
            "UPDATE email_verifications SET attempts = attempts + 1
             WHERE id = :id AND attempts < :max
               AND used_at IS NULL AND invalidated_at IS NULL
               AND expires_at > NOW()"
        );
        $spend->execute(['id' => $row['id'], 'max' => self::MAX_ATTEMPTS]);

        if ($spend->rowCount() !== 1) {
            return null;
        }

        if (!hash_equals($row['token_hash'], $this->hashCode($formId, $email, $code))) {
            // Kill the code once its attempts are exhausted.
            $this->db->prepare(
                "UPDATE email_verifications SET invalidated_at = NOW()
                 WHERE id = :id AND attempts >= :max AND used_at IS NULL AND invalidated_at IS NULL"
            )->execute(['id' => $row['id'], 'max' => self::MAX_ATTEMPTS]);
            return null;
        }

        // Correct code: claim it so two requests can't both succeed.
        $claim = $this->db->prepare(
            "UPDATE email_verifications SET used_at = NOW()
             WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL
               AND expires_at > NOW()"
        );
        $claim->execute(['id' => $row['id']]);

        return $claim->rowCount() === 1
            ? ['email' => $row['email'], 'name' => (string) ($row['name'] ?? '')]
            : null;
    }

    /**
     * Delete verification rows for feedback forms that expired more than $days ago.
     * The rate limit only looks at the last hour, so older rows are never needed.
     * Rows with form_id NULL (reimbursement flow) are not touched: the JOIN
     * drops them.
     * @return int rows deleted
     */
    public function purgeFeedbackRows(int $days = 1): int {
        $stmt = $this->db->prepare(
            "DELETE ev FROM email_verifications ev
             JOIN forms f ON f.id = ev.form_id
             WHERE JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.kind')) = 'feedback'
               AND ev.expires_at < NOW() - INTERVAL ? DAY"
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
