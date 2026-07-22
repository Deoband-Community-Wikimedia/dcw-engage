<?php
/**
 * DCW Engage - Notes Model
 * 
 * Handles Internal Organizer Notes on applications.
 * Notes are append-only (never edited or deleted individually) to
 * preserve a full audit trail for the organizing team.
 */
class NotesModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /**
     * Add a new note to an application.
     * Snapshots the admin's email at write-time so the note stays
     * attributable even if the admin account is later removed.
     */
    public function addNote($applicationId, $adminId, $adminEmail, $noteText) {
        $stmt = $this->db->prepare(
            "INSERT INTO application_notes (application_id, admin_id, admin_email_snapshot, note_text) 
             VALUES (:app_id, :admin_id, :admin_email, :text)"
        );
        $stmt->execute([
            'app_id' => $applicationId,
            'admin_id' => $adminId,
            'admin_email' => $adminEmail,
            'text' => $noteText
        ]);
        return $this->db->lastInsertId();
    }

    /**
     * Fetch all notes for a given application, newest first.
     */
    public function getNotesByApplication($applicationId) {
        $stmt = $this->db->prepare(
            "SELECT * FROM application_notes 
             WHERE application_id = :app_id 
             ORDER BY created_at DESC"
        );
        $stmt->execute(['app_id' => $applicationId]);
        return $stmt->fetchAll();
    }
}