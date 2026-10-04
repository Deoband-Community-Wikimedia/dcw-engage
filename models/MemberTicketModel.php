<?php
/**
 * DCW Engage - member complaints, suggestions and questions.
 *
 * Member side: every read and write is scoped by the Member ID taken from the session,
 * never by an ID from the URL.
 *
 * Staff side ("DCW Support"): $isOwner = false means DCW Support, who can never read tickets
 * flagged about_staff (complaints about team members). That rule lives in the queries
 * themselves, not just in the menu. The same goes for hide_name: when a member hid their
 * name, the staff queries blank every field that could identify them, so no view can leak it.
 */
class MemberTicketModel
{
    // The role that works these conversations. The key matches admin_user_roles.role;
    // the label is what people see. To rename the key later, change it here and run
    // UPDATE on admin_user_roles / admin_users.
    const SUPPORT_ROLE  = 'member_support';
    const SUPPORT_LABEL = 'DCW Support';

    const MAX_OPEN    = 5;    // open conversations per member
    const COOLDOWN    = 20;   // seconds between one member's messages on a conversation
    const MAX_BODY    = 4000;
    const MAX_SUBJECT = 160;
    const STATUSES = [
        'complaint'  => ['Open', 'Awaiting member', 'Resolved'],
        'question'   => ['Open', 'Awaiting member', 'Resolved'],
        'suggestion' => ['Received', 'Under consideration', 'Planned', 'Done', 'Not planned'],
    ];
    const CLOSED   = ['Resolved', 'Done', 'Not planned'];
    const LABELS   = ['complaint' => 'Complaint', 'suggestion' => 'Suggestion', 'question' => 'Question'];
    const PREFIX   = ['complaint' => 'CM', 'suggestion' => 'SG', 'question' => 'HQ'];

    // Columns staff may read. Identity columns are blanked when the member hid their name.
    private const STAFF_COLS = "id, tracking_id, type, subject, status, about_staff, hide_name, last_sender,
        assigned_to, created_at, updated_at,
        IF(hide_name = 1, '', member_id)    AS member_id,
        IF(hide_name = 1, '', member_name)  AS member_name,
        IF(hide_name = 1, '', member_email) AS member_email";

    private $db;

    public function __construct()
    {
        $this->db = DB::getInstance()->getConnection();
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function isClosed(string $s): bool { return in_array($s, self::CLOSED, true); }

    private function trackingId(string $type): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $id = self::PREFIX[$type] . '-';
            for ($i = 0; $i < 6; $i++) $id .= $chars[random_int(0, strlen($chars) - 1)];
            $s = $this->db->prepare('SELECT 1 FROM member_tickets WHERE tracking_id = ?');
            $s->execute([$id]);
        } while ($s->fetchColumn());
        return $id;
    }

    // ---------------- member side ----------------

    public function openCount(string $memberId): int
    {
        $in = implode(',', array_fill(0, count(self::CLOSED), '?'));
        $s = $this->db->prepare("SELECT COUNT(*) FROM member_tickets WHERE member_id = ? AND status NOT IN ($in)");
        $s->execute(array_merge([$memberId], self::CLOSED));
        return (int) $s->fetchColumn();
    }

    /** @return array{ok:bool,error?:string,tracking_id?:string} */
    public function create(array $member, string $type, string $subject, string $body, bool $aboutStaff, bool $hideName): array
    {
        if (!isset(self::STATUSES[$type])) return ['ok' => false, 'error' => 'Unknown type.'];
        $subject = trim($subject); $body = trim($body);
        if ($subject === '' || $body === '') return ['ok' => false, 'error' => 'Please fill in both the subject and the message.'];
        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) return ['ok' => false, 'error' => 'That is too long. Please shorten it.'];

        if ($this->openCount((string) $member['member_id']) >= self::MAX_OPEN) {
            return ['ok' => false, 'error' => 'You already have ' . self::MAX_OPEN . ' open conversations. Please wait for a reply, or add to an existing one.'];
        }

        $tracking = $this->trackingId($type);
        $now = self::now();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO member_tickets
                (tracking_id, member_id, member_email, member_name, type, subject, status, about_staff, hide_name, last_sender, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tracking, $member['member_id'], strtolower((string) $member['email']), (string) ($member['full_name'] ?? ''),
                    $type, $subject, self::STATUSES[$type][0],
                    ($type === 'complaint' && $aboutStaff) ? 1 : 0,
                    ($type === 'suggestion' && $hideName) ? 1 : 0,
                    'member', $now, $now]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('INSERT INTO member_ticket_messages (ticket_id, sender, author, body, created_at) VALUES (?,?,?,?,?)')
                ->execute([$id, 'member', $member['member_id'], $body, $now]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return ['ok' => true, 'tracking_id' => $tracking];
    }

    public function listForMember(string $memberId): array
    {
        $s = $this->db->prepare('SELECT * FROM member_tickets WHERE member_id = ? ORDER BY updated_at DESC');
        $s->execute([$memberId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getForMember(string $tracking, string $memberId)
    {
        $s = $this->db->prepare('SELECT * FROM member_tickets WHERE tracking_id = ? AND member_id = ?');
        $s->execute([$tracking, $memberId]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    /** Internal notes and staff names are never selected. */
    public function messagesForMember(int $ticketId): array
    {
        $s = $this->db->prepare("SELECT sender, body, created_at FROM member_ticket_messages
                                 WHERE ticket_id = ? AND sender IN ('member','staff') ORDER BY id");
        $s->execute([$ticketId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok:bool,error?:string} */
    public function memberReply(array $ticket, string $memberId, string $body): array
    {
        $body = trim($body);
        if ($body === '') return ['ok' => false, 'error' => 'Write a message first.'];
        if (mb_strlen($body) > self::MAX_BODY) return ['ok' => false, 'error' => 'That message is too long.'];

        // Replying to a closed complaint/question reopens it, so it counts against the open limit.
        if ($ticket['type'] !== 'suggestion' && self::isClosed((string) $ticket['status'])
            && $this->openCount($memberId) >= self::MAX_OPEN) {
            return ['ok' => false, 'error' => 'You already have ' . self::MAX_OPEN . ' open conversations. Please wait for a reply before reopening this one.'];
        }

        $s = $this->db->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP())
                                 FROM member_ticket_messages WHERE ticket_id = ? AND sender = 'member'");
        $s->execute([$ticket['id']]);
        $ago = $s->fetchColumn();
        if ($ago !== null && $ago !== false && (int) $ago < self::COOLDOWN) {
            return ['ok' => false, 'error' => 'Please wait a few seconds before sending another message.'];
        }

        $now = self::now();
        $this->db->prepare('INSERT INTO member_ticket_messages (ticket_id, sender, author, body, created_at) VALUES (?,?,?,?,?)')
            ->execute([$ticket['id'], 'member', $memberId, $body, $now]);
        // A reply reopens a resolved complaint/question and always puts the ball back with DCW.
        $status = ($ticket['type'] !== 'suggestion' && $ticket['status'] !== 'Open') ? 'Open' : $ticket['status'];
        $this->db->prepare("UPDATE member_tickets SET status = ?, last_sender = 'member', updated_at = ? WHERE id = ? AND member_id = ?")
            ->execute([$status, $now, $ticket['id'], $memberId]);
        return ['ok' => true];
    }

    // ---------------- staff side (DCW Support / owners) ----------------

    public function listForStaff(bool $isOwner, string $type = '', string $status = '', string $assignee = ''): array
    {
        $where = ['1=1']; $args = [];
        if (!$isOwner)       $where[] = 'about_staff = 0';
        if ($type !== '')    { $where[] = 'type = ?';   $args[] = $type; }
        if ($status === 'open') {
            $where[] = 'status NOT IN (' . implode(',', array_fill(0, count(self::CLOSED), '?')) . ')';
            $args = array_merge($args, self::CLOSED);
        } elseif ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
        if ($assignee === 'none') $where[] = 'assigned_to IS NULL';
        elseif ($assignee !== '') { $where[] = 'assigned_to = ?'; $args[] = $assignee; }

        $s = $this->db->prepare('SELECT ' . self::STAFF_COLS . ' FROM member_tickets WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at ASC LIMIT 300');
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getForStaff(int $id, bool $isOwner)
    {
        $s = $this->db->prepare('SELECT ' . self::STAFF_COLS . ' FROM member_tickets WHERE id = ?' . ($isOwner ? '' : ' AND about_staff = 0'));
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    /** $ticket comes from getForStaff(); for hidden-name suggestions the member's ID is blanked on every message. */
    public function messagesForStaff(array $ticket): array
    {
        $s = $this->db->prepare('SELECT sender, author, body, created_at FROM member_ticket_messages WHERE ticket_id = ? ORDER BY id');
        $s->execute([$ticket['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($ticket['hide_name'])) {
            foreach ($rows as &$r) { if ($r['sender'] === 'member') $r['author'] = ''; }
            unset($r);
        }
        return $rows;
    }

    /** @return bool false when the message is empty or too long */
    public function staffReply(array $ticket, string $author, string $body, bool $internalNote): bool
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) return false;
        $now = self::now();
        $this->db->prepare('INSERT INTO member_ticket_messages (ticket_id, sender, author, body, created_at) VALUES (?,?,?,?,?)')
            ->execute([$ticket['id'], $internalNote ? 'note' : 'staff', $author, $body, $now]);
        if ($internalNote) return true;
        $status = ($ticket['type'] !== 'suggestion' && $ticket['status'] === 'Open') ? 'Awaiting member' : $ticket['status'];
        $this->db->prepare("UPDATE member_tickets SET status = ?, last_sender = 'staff', updated_at = ? WHERE id = ?")
            ->execute([$status, $now, $ticket['id']]);
        return true;
    }

    public function setStatus(array $ticket, string $status): bool
    {
        if (!in_array($status, self::STATUSES[$ticket['type']], true)) return false;
        $this->db->prepare('UPDATE member_tickets SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, self::now(), $ticket['id']]);
        return true;
    }

    /**
     * $ticket comes from getForStaff(), so scoping has already happened. The assignee must be
     * someone allowed to work this ticket; conversations about staff go to owners only.
     * An empty email unassigns.
     */
    public function assign(array $ticket, ?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $allowed = array_map('strtolower', $this->assignees(!empty($ticket['about_staff'])));
            if (!in_array($email, $allowed, true)) return false;
        }
        $this->db->prepare('UPDATE member_tickets SET assigned_to = ? WHERE id = ?')->execute([$email ?: null, $ticket['id']]);
        return true;
    }

    /**
     * Emails of accounts that can work tickets (DCW Support or owner), for the assignee dropdown.
     * Same shape as MemberModel::coordinators(): roles live in admin_user_roles, with the
     * primary role on admin_users as a fallback for accounts that have no rows there.
     */
    public function assignees(bool $ownersOnly = false): array
    {
        $roles = $ownersOnly ? ['owner'] : [self::SUPPORT_ROLE, 'owner'];
        $in = implode(',', array_fill(0, count($roles), '?'));
        try {
            $s = $this->db->prepare(
                "SELECT u.email FROM admin_users u
                  WHERE EXISTS (SELECT 1 FROM admin_user_roles r
                                 WHERE r.admin_id = u.id AND r.role IN ($in))
                     OR (u.role IN ($in)
                         AND NOT EXISTS (SELECT 1 FROM admin_user_roles r2 WHERE r2.admin_id = u.id))
                  ORDER BY u.email"
            );
            $s->execute(array_merge($roles, $roles));
            return $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /** Real contact details, used only to send the notification email. Never shown to staff. */
    public function notifyContact(int $id)
    {
        $s = $this->db->prepare('SELECT tracking_id, member_email, member_name FROM member_tickets WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * A failed email must never undo a saved reply. $kind: 'reply' or 'status'.
 * Takes the ticket's numeric ID, not the staff-facing row, because that row has the
 * member's address blanked when they asked to stay anonymous.
 */
function ticket_notify(int $ticketId, string $kind): void
{
    try {
        $c = (new MemberTicketModel())->notifyContact($ticketId);
        if (!$c) return;
        require_once __DIR__ . '/../includes/mailer.php';
        Mailer::sendMemberTicketUpdate($c['member_email'], (string) $c['member_name'], $c['tracking_id'], $kind);
    } catch (Throwable $e) {
        require_once __DIR__ . '/../includes/app_log.php';
        app_log('Ticket email failed for ticket ' . $ticketId . ': ' . $e->getMessage());
    }
}
