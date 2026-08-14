<?php
/**
 * DCW Engage - Audit Log
 *
 * An append-only record of sensitive workspace actions — who invited or
 * removed whom, who accepted an invitation, who reset a password.
 *
 * Writing is deliberately best-effort. A logging failure must never break the
 * action being logged (removing an organizer has to succeed even if the audit
 * insert somehow fails), so record() swallows its own errors to the PHP error
 * log rather than throwing. The trade-off is accepted on purpose: the action
 * matters more than its footnote.
 */
class AuditLog {
    /**
     * Record one action. $actorId may be null for a self-serve event (a
     * password reset, an accepted invitation) where the actor is the subject
     * rather than a signed-in owner. $actorEmail is always stored so the row
     * still names someone after their account is gone.
     */
    public static function record($action, $actorId, $actorEmail, $target = null, $detail = null) {
        try {
            $db = DB::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO audit_log (actor_id, actor_email, action, target, detail, ip_address)
                 VALUES (:actor_id, :actor_email, :action, :target, :detail, :ip)"
            );
            $stmt->execute([
                'actor_id'    => $actorId ? (int) $actorId : null,
                'actor_email' => (string) $actorEmail,
                'action'      => (string) $action,
                'target'      => $target !== null ? (string) $target : null,
                'detail'      => $detail !== null ? (string) $detail : null,
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log("Audit log write failed for action '$action': " . $e->getMessage());
        }
    }

    /**
     * Most recent entries first, for the audit view. The limit is clamped and
     * cast to an int so it can be inlined safely — LIMIT does not bind
     * reliably with emulated prepares turned off.
     */
    public static function recent($limit = 100) {
        try {
            $db = DB::getInstance()->getConnection();
            $limit = max(1, min(500, (int) $limit));
            return $db->query(
                "SELECT actor_email, action, target, detail, ip_address, created_at
                 FROM audit_log
                 ORDER BY created_at DESC, id DESC
                 LIMIT $limit"
            )->fetchAll();
        } catch (Throwable $e) {
            error_log("Audit log read failed: " . $e->getMessage());
            return [];
        }
    }
}
