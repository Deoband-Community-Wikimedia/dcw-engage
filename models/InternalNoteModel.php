<?php
/**
 * DCW Engage - Internal notes
 *
 * Staff-only notes on a request (reimbursement or internet support). They are
 * NEVER selected by any applicant-facing query, never put in an email and never
 * printed on a receipt. Notes are append-only: nobody edits or deletes them.
 */
class InternalNoteModel {
    public const MAX_LENGTH = 2000;
    private const QUEUES = ['reimbursement', 'internet'];

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /**
     * @throws \InvalidArgumentException if the queue is unknown or the note is empty or too long
     */
    public function add($queue, $requestId, $authorEmail, $body) {
        if (!in_array($queue, self::QUEUES, true) || (int) $requestId <= 0) {
            throw new \InvalidArgumentException('Unknown request.');
        }
        $body = trim((string) $body);
        if ($body === '') {
            throw new \InvalidArgumentException('Write something in the note first.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Note is too long (' . self::MAX_LENGTH . ' characters max).');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO internal_notes (queue, request_id, author_email, body)
             VALUES (:queue, :rid, :author, :body)"
        );
        return $stmt->execute([
            'queue'  => $queue,
            'rid'    => (int) $requestId,
            'author' => (string) $authorEmail,
            'body'   => $body,
        ]);
    }

    /** All notes for one request, oldest first. */
    public function forRequest($queue, $requestId) {
        $stmt = $this->db->prepare(
            "SELECT author_email, body, created_at
             FROM internal_notes
             WHERE queue = :queue AND request_id = :rid
             ORDER BY id ASC"
        );
        $stmt->execute(['queue' => $queue, 'rid' => (int) $requestId]);
        return $stmt->fetchAll();
    }
}
