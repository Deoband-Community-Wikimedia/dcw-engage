<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/MemberCardModel.php';

/**
 * DCW Engage - event participation cards (Train-a-Wikipedian and any future event).
 *
 * An event has participants; each participant has a role and a card. The card's QR code points at
 * /event-card/<token>, a random 32-character token that says nothing about the person (the same idea as
 * MemberCardModel). Tables: card_events and card_participants (event_cards.sql).
 *
 * Who may use this: organizer and owner (enforced in views/admin/event_cards.php).
 *
 * Roles are a plain list with a colour each, so adding "Speaker" later is one new line here. The colours
 * come from the DCW logo (the same blue, red and green as the membership card, with the green darkened so
 * white text stays readable on it).
 */
class EventCardModel
{
    /** Role => band colour on the card. Order here is the order people are listed and printed in. */
    public const ROLES = [
        'Organiser'   => '#8f1c10',   // DCW red
        'Facilitator' => '#2f7a4f',   // DCW green, darkened for white text
        'Mentee'      => '#2d6499',   // DCW blue
    ];

    /** Shown on the card when a person has no affiliation of their own. */
    public const DEFAULT_AFFILIATION = 'Deoband Community Wikimedia';

    public const MAX_CSV_ROWS = 500;

    /** Where logos live (png or svg), under the project root. Same folder as the chapter logos; the DCW logo is generic.svg. */
    private const LOGO_DIR = '/assets/chapters/';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DB::getInstance()->getConnection();
    }

    // ---- Events ----------------------------------------------------------------------------------

    /** All events, newest first, each with a head count. */
    public function events(): array
    {
        return $this->db->query(
            'SELECT e.*, (SELECT COUNT(*) FROM card_participants p WHERE p.event_id = e.id) AS people
             FROM card_events e ORDER BY e.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function event(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM card_events WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createEvent(string $name, string $edition, ?string $logoKey, string $by): int
    {
        $name    = $this->clean($name, 120);
        $edition = $this->clean($edition, 80);
        if ($name === '') {
            throw new InvalidArgumentException('Give the event a name.');
        }
        $logoKey = ($logoKey !== null && in_array($logoKey, self::availableLogos(), true)) ? $logoKey : null;

        $this->db->prepare('INSERT INTO card_events (name, edition, logo_key, created_by) VALUES (?, ?, ?, ?)')
                 ->execute([$name, $edition, $logoKey, $by]);
        $id = (int) $this->db->lastInsertId();
        $this->log('event.created', 'event:' . $id, $name . ' ' . $edition);
        return $id;
    }

    // ---- Logos -----------------------------------------------------------------------------------

    /** Logo keys (file names without extension) found in assets/chapters/, for the "Event logo" dropdown. 'generic' (the DCW logo) is left out because it is always on the card. */
    public static function availableLogos(): array
    {
        $keys = [];
        foreach (glob(dirname(__DIR__) . self::LOGO_DIR . '*.{png,svg}', GLOB_BRACE) ?: [] as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            if ($key !== 'generic' && preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    /** The event's logo as a data: URI (PNG preferred, SVG otherwise), or null. */
    public static function logoDataUri(?string $key): ?string
    {
        if ($key === null || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return null;
        }
        foreach (['png', 'svg'] as $ext) {
            $file = dirname(__DIR__) . self::LOGO_DIR . $key . '.' . $ext;
            if (is_file($file)) {
                $bytes = @file_get_contents($file);
                if ($bytes === false) {
                    return null;
                }
                return 'data:' . ($ext === 'svg' ? 'image/svg+xml' : 'image/png') . ';base64,' . base64_encode($bytes);
            }
        }
        return null;
    }

    // ---- Participants ----------------------------------------------------------------------------

    /** Everyone in an event: roles in the order of ROLES, then by name. */
    public function participants(int $eventId): array
    {
        $st = $this->db->prepare('SELECT * FROM card_participants WHERE event_id = ?');
        $st->execute([$eventId]);
        $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
        $order = array_flip(array_keys(self::ROLES));
        usort($rows, fn($a, $b) => [$order[$a['role']] ?? 99, strtolower($a['name'])]
                               <=> [$order[$b['role']] ?? 99, strtolower($b['name'])]);
        return $rows;
    }

    public function participant(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM card_participants WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Add one person (an organiser, a facilitator, a late addition). */
    public function addPerson(int $eventId, string $name, string $role, string $affiliation, string $by): int
    {
        $this->requireEvent($eventId);
        $name = $this->validName($name);
        $role = $this->validRole($role);
        $aff  = $this->clean($affiliation, 120);

        $this->db->prepare(
            'INSERT INTO card_participants (event_id, application_id, name, role, affiliation, token, added_by)
             VALUES (?, NULL, ?, ?, ?, ?, ?)'
        )->execute([$eventId, $name, $role, $aff, bin2hex(random_bytes(16)), $by]);
        $id = (int) $this->db->lastInsertId();
        $this->log('event.person_added', 'event:' . $eventId, $role);
        return $id;
    }

    public function updatePerson(int $id, string $name, string $role, string $affiliation, string $by): void
    {
        $p = $this->participant($id) ?? throw new InvalidArgumentException('That person is no longer on the list.');
        $this->db->prepare('UPDATE card_participants SET name = ?, role = ?, affiliation = ? WHERE id = ?')
                 ->execute([$this->validName($name), $this->validRole($role), $this->clean($affiliation, 120), $id]);
        $this->log('event.person_updated', 'event:' . $p['event_id'], 'participant ' . $id);
    }

    /** Give a person a new token. The old QR code stops working at once. */
    public function reissue(int $id, string $by): void
    {
        $p = $this->participant($id) ?? throw new InvalidArgumentException('That person is no longer on the list.');
        $this->db->prepare('UPDATE card_participants SET token = ? WHERE id = ?')
                 ->execute([bin2hex(random_bytes(16)), $id]);
        $this->log('event.card_reissued', 'event:' . $p['event_id'], 'participant ' . $id);
    }

    public function remove(int $id, string $by): void
    {
        $p = $this->participant($id);
        if ($p === null) {
            return;
        }
        $this->db->prepare('DELETE FROM card_participants WHERE id = ?')->execute([$id]);
        $this->log('event.person_removed', 'event:' . $p['event_id'], 'participant ' . $id);
    }

    // ---- Importing mentees from a scholarship form -----------------------------------------------

    /**
     * Applications that could become mentees: awardees who CONFIRMED their scholarship (the /confirm/<token>
     * step, award_confirmations.response = 'confirmed') on the chosen form, not yet in this event.
     * Only the three columns the screen needs are read; no email, answers or documents.
     */
    public function candidates(int $formId, int $eventId): array
    {
        $st = $this->db->prepare(
            "SELECT a.id, a.tracking_id, a.applicant_name
             FROM applications a
             JOIN award_confirmations ac ON ac.application_id = a.id AND ac.response = 'confirmed'
             WHERE a.form_id = ? AND a.status = 'Accepted'
               AND NOT EXISTS (SELECT 1 FROM card_participants p WHERE p.event_id = ? AND p.application_id = a.id)
             ORDER BY a.applicant_name"
        );
        $st->execute([$formId, $eventId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Import the chosen applications as Mentees. Returns ['added' => n, 'skipped' => n].
     * The name is copied from the application with anything outside English letters, spaces, dots, hyphens
     * and apostrophes removed; an organiser can edit it afterwards. Anything that is not Accepted AND confirmed,
     * or not on this form, is ignored even if its id was posted.
     */
    public function importApplications(int $eventId, int $formId, array $ids, string $by): array
    {
        $this->requireEvent($eventId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) {
            return ['added' => 0, 'skipped' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare(
            "SELECT a.id, a.applicant_name FROM applications a
             JOIN award_confirmations ac ON ac.application_id = a.id AND ac.response = 'confirmed'
             WHERE a.form_id = ? AND a.status = 'Accepted' AND a.id IN ($in)"
        );
        $st->execute(array_merge([$formId], $ids));

        $ins = $this->db->prepare(
            "INSERT IGNORE INTO card_participants (event_id, application_id, name, role, token, added_by)
             VALUES (?, ?, ?, 'Mentee', ?, ?)"
        );
        $added = 0;
        $skipped = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $this->clean(preg_replace("/[^\p{Latin} .'\-]/u", '', (string) $row['applicant_name']) ?? '', 120);
            if ($name === '') {
                $skipped++;
                continue;
            }
            $ins->execute([$eventId, (int) $row['id'], $name, bin2hex(random_bytes(16)), $by]);
            $ins->rowCount() > 0 ? $added++ : $skipped++;
        }
        $this->log('event.mentees_imported', 'event:' . $eventId, $added . ' added');
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Import organisers and facilitators from a CSV: columns name, role, affiliation (optional).
     * A header row is detected and skipped. Good rows are added; bad rows are reported by row number.
     * Returns ['added' => n, 'errors' => [string, ...]].
     */
    public function importCsv(int $eventId, string $path, string $by): array
    {
        $this->requireEvent($eventId);
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            throw new InvalidArgumentException('Could not read that file.');
        }
        $roles  = [];
        foreach (array_keys(self::ROLES) as $r) {
            $roles[strtolower($r)] = $r;
        }
        $added  = 0;
        $errors = [];
        $line   = 0;
        while (($row = fgetcsv($fh, 2000, ',', '"', '')) !== false) {
            $line++;
            if ($line === 1 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? $row[0];   // BOM from Excel
                if (in_array(strtolower(trim((string) $row[0])), ['name', 'full name'], true)) {
                    continue;
                }
            }
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;   // blank line
            }
            if ($line > self::MAX_CSV_ROWS + 1) {
                $errors[] = 'Stopped at ' . self::MAX_CSV_ROWS . ' rows. Upload the rest in a second file.';
                break;
            }
            try {
                $role = $roles[strtolower(trim((string) ($row[1] ?? '')))] ?? null;
                if ($role === null) {
                    throw new InvalidArgumentException('role must be one of ' . implode(', ', array_keys(self::ROLES)));
                }
                $this->addPerson($eventId, (string) ($row[0] ?? ''), $role, (string) ($row[2] ?? ''), $by);
                $added++;
            } catch (InvalidArgumentException $e) {
                $errors[] = 'Row ' . $line . ': ' . $e->getMessage();
            }
        }
        fclose($fh);
        return ['added' => $added, 'errors' => $errors];
    }

    // ---- The public check ------------------------------------------------------------------------

    /**
     * What /event-card/<token> shows, or null for an unknown token. Only the first name and last initial
     * leave this class (MemberCardModel::shortName), plus the role and the event.
     */
    public function lookup(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $this->db->prepare(
            'SELECT p.name, p.role, e.name AS event_name, e.edition
             FROM card_participants p JOIN card_events e ON e.id = p.event_id WHERE p.token = ?'
        );
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'name'       => MemberCardModel::shortName((string) $row['name']),
            'role'       => (string) $row['role'],
            'color'      => self::ROLES[$row['role']] ?? '#1b3f66',
            'event_name' => (string) $row['event_name'],
            'edition'    => (string) $row['edition'],
        ];
    }

    /** The full link a QR code should carry. */
    public static function url(string $token): string
    {
        $base = defined('ENGAGE_BASE_URL') ? rtrim((string) ENGAGE_BASE_URL, '/') : 'https://engage.dcwwiki.org';
        return $base . '/event-card/' . $token;
    }

    // ---- Internals -------------------------------------------------------------------------------

    private function requireEvent(int $id): void
    {
        if ($this->event($id) === null) {
            throw new InvalidArgumentException('That event does not exist.');
        }
    }

    private function validRole(string $role): string
    {
        $role = trim($role);
        if (!isset(self::ROLES[$role])) {
            throw new InvalidArgumentException('Choose a role: ' . implode(', ', array_keys(self::ROLES)) . '.');
        }
        return $role;
    }

    /** Names are English: Latin letters, spaces, dots, hyphens and apostrophes. */
    private function validName(string $name): string
    {
        $name = $this->clean($name, 80);
        if ($name === '') {
            throw new InvalidArgumentException('Name is missing.');
        }
        if (!preg_match("/^\p{Latin}[\p{Latin} .'\-]*$/u", $name)) {
            throw new InvalidArgumentException('Names can use English letters, spaces, dots, hyphens and apostrophes only.');
        }
        return $name;
    }

    private function clean(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    /** Audit entry; a logging failure must never stop the work. */
    private function log(string $action, string $target, string $detail): void
    {
        try {
            AuditLog::activity($action, $target, $detail);
        } catch (Throwable $e) {
            app_log('Event card audit failed: ' . $e->getMessage());
        }
    }
}
