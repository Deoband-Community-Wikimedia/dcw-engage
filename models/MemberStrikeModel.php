<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/PublicDirectoryCache.php';

/**
 * DCW Engage - striking members off (table member_strikes, see member_strikes.sql).
 *
 * A strike belongs to a Member ID, so it covers every chapter that person holds. While it is in force:
 *   - MemberSession::check() ends their session and refuses new ones,
 *   - MemberAuthModel::authenticate() answers 'struck' (after a correct password) and sends no reset links,
 *   - their card is retired and the public check page shows "Card not valid" (same as an unknown card),
 *   - MemberModel::approve() refuses applications from them,
 *   - they are removed from the public members list (/members) at once.
 * Reinstating lifts it. They get a fresh card on their next visit (the old one stays dead).
 *
 * Who may strike is decided by the page (reviewers and owners). This class only stores and answers.
 * Reads fail soft: if the table is missing, nobody counts as struck and the problem is logged, so
 * deploying before running the SQL does not take member pages down.
 * The one exception is activeIdsStrict(), used by the public members list, which fails closed.
 */
class MemberStrikeModel
{
    private PDO $db;

    /** Per-request answers for isStruck(): [MEMBER ID => bool]. */
    private static array $cache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DB::getInstance()->getConnection();
    }

    private static function norm(string $memberId): string
    {
        return strtoupper(trim($memberId));
    }

    // ---- Reading ---------------------------------------------------------------------------------

    public function isStruck(string $memberId): bool
    {
        $memberId = self::norm($memberId);
        if ($memberId === '') {
            return false;
        }
        if (isset(self::$cache[$memberId])) {
            return self::$cache[$memberId];
        }
        try {
            $st = $this->db->prepare('SELECT 1 FROM member_strikes WHERE member_id = ? AND lifted_at IS NULL LIMIT 1');
            $st->execute([$memberId]);
            return self::$cache[$memberId] = ($st->fetchColumn() !== false);
        } catch (Throwable $e) {
            app_log('Strike check failed: ' . $e->getMessage());
            return false;
        }
    }

    /** The strike in force for a Member ID, or null. */
    public function activeFor(string $memberId): ?array
    {
        try {
            $st = $this->db->prepare('SELECT * FROM member_strikes WHERE member_id = ? AND lifted_at IS NULL ORDER BY id DESC LIMIT 1');
            $st->execute([self::norm($memberId)]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            app_log('Strike read failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Every strike in force: [member_id => row]. */
    public function activeMap(): array
    {
        $out = [];
        try {
            foreach ($this->db->query('SELECT * FROM member_strikes WHERE lifted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['member_id']] = $r;
            }
        } catch (Throwable $e) {
            app_log('Strike list failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * [MEMBER ID => true] for every strike in force. Unlike the other reads this THROWS on failure,
     * so a public page can fail closed instead of listing struck-off people.
     */
    public function activeIdsStrict(): array
    {
        $ids = $this->db->query('SELECT member_id FROM member_strikes WHERE lifted_at IS NULL')
                        ->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys(array_map(fn($i) => self::norm((string) $i), $ids), true);
    }

    /** Strikes in force with the person's name and email, newest first, for the reviewers' list. */
    public function activeList(): array
    {
        try {
            return $this->db->query(
                "SELECT s.*,
                        (SELECT m.full_name FROM members m WHERE m.member_id = s.member_id ORDER BY m.id DESC LIMIT 1) AS full_name,
                        (SELECT m.email     FROM members m WHERE m.member_id = s.member_id ORDER BY m.id DESC LIMIT 1) AS email
                   FROM member_strikes s WHERE s.lifted_at IS NULL ORDER BY s.struck_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            app_log('Strike list failed: ' . $e->getMessage());
            return [];
        }
    }

    // ---- Writing ---------------------------------------------------------------------------------

    /**
     * Strike a Member ID off. In one transaction: record it, retire the live card, and cancel any unused
     * set-password / reset links. Throws InvalidArgumentException with a plain message.
     */
    public function strike(string $memberId, string $reason, string $by): void
    {
        $memberId = self::norm($memberId);
        $reason   = trim($reason);
        if ($memberId === '') {
            throw new InvalidArgumentException('Missing Member ID.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Give a reason. It is kept in the record.');
        }
        if (mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Please keep the reason under 2000 characters.');
        }

        $this->db->beginTransaction();
        try {
            // Locking the member rows also serialises two reviewers acting at once.
            $lock = $this->db->prepare('SELECT id FROM members WHERE member_id = ? FOR UPDATE');
            $lock->execute([$memberId]);
            $rowIds = $lock->fetchAll(PDO::FETCH_COLUMN);
            if (!$rowIds) {
                throw new InvalidArgumentException('No member has that Member ID.');
            }

            $chk = $this->db->prepare('SELECT 1 FROM member_strikes WHERE member_id = ? AND lifted_at IS NULL');
            $chk->execute([$memberId]);
            if ($chk->fetchColumn() !== false) {
                throw new InvalidArgumentException('This member is already struck off.');
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->prepare('INSERT INTO member_strikes (member_id, reason, struck_by, struck_at) VALUES (?, ?, ?, ?)')
                     ->execute([$memberId, $reason, $by, $now]);

            // The card stops working at once.
            $this->db->prepare('UPDATE member_cards SET replaced_at = ? WHERE member_id = ? AND replaced_at IS NULL')
                     ->execute([$now, $memberId]);

            // Links already emailed to them stop working too.
            $in = implode(',', array_fill(0, count($rowIds), '?'));
            $this->db->prepare("UPDATE member_password_tokens SET used_at = ? WHERE used_at IS NULL AND member_row_id IN ($in)")
                     ->execute(array_merge([$now], $rowIds));

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        unset(self::$cache[$memberId]);
        PublicDirectoryCache::clear();   // off the public members list immediately
        AuditLog::activity('member.struck_off', $memberId, $reason, (string) $by);
    }

    /** Lift the strike in force. The old card stays dead; a new one is made on their next visit. */
    public function reinstate(string $memberId, string $reason, string $by): void
    {
        $memberId = self::norm($memberId);
        $reason   = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Give a reason for reinstating. It is kept in the record.');
        }
        if (mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Please keep the reason under 2000 characters.');
        }

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT id FROM member_strikes WHERE member_id = ? AND lifted_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $st->execute([$memberId]);
            $id = $st->fetchColumn();
            if ($id === false) {
                throw new InvalidArgumentException('This member is not struck off.');
            }
            $this->db->prepare('UPDATE member_strikes SET lifted_at = ?, lifted_by = ?, lift_reason = ? WHERE id = ?')
                     ->execute([gmdate('Y-m-d H:i:s'), $by, $reason, $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        unset(self::$cache[$memberId]);
        PublicDirectoryCache::clear();   // back on the public members list
        AuditLog::activity('member.reinstated', $memberId, $reason, (string) $by);
    }
}
