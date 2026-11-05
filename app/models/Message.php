<?php

class Message {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Traitement et enregistrement d'une pièce jointe (Image ou PDF)
     */
    public function uploadAttachment(?array $file, int $userId, string $subfolder = 'chat'): string {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return '';
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($ext, $allowed)) {
            return '';
        }

        $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
        $targetDir = rtrim($rootDir, '/\\') . '/public/uploads/' . $subfolder . '/';

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $fileName = $subfolder . '_' . $userId . '_' . time() . '.' . $ext;
        $targetPath = $targetDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            @chmod($targetPath, 0666);
            $fileUrl = BASE_URL . 'public/uploads/' . $subfolder . '/' . $fileName;

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                return '<br><div class="msg-attachment mt-2"><a href="' . $fileUrl . '" target="_blank"><img src="' . $fileUrl . '" alt="Pièce jointe" style="max-width: 100%; max-height: 250px; border-radius: 8px; border: 1px solid #e2e8f0;"></a></div>';
            } else {
                return '<br><div class="msg-attachment mt-2"><a href="' . $fileUrl . '" target="_blank" class="btn-pdf-link"><i class="fas fa-file-pdf"></i> Consulter le document PDF joint</a></div>';
            }
        }

        return '';
    }

    /**
     * Diffuse un SOS à tous les tuteurs et aux élèves de la même classe.
     */
    public function createSosBroadcast(int $expediteurId, string $texte, ?array $file = null): bool {
        $stmtClasse = $this->db->prepare("SELECT classe FROM enfants WHERE utilisateur_id = ?");
        $stmtClasse->execute([$expediteurId]);
        $childClasse = $stmtClasse->fetchColumn() ?: '';

        $stmtTutors = $this->db->query("SELECT id FROM utilisateurs WHERE role = 'tutor'");
        $tutorIds = $stmtTutors->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $childIds = [];
        if (!empty($childClasse)) {
            $stmtChildren = $this->db->prepare("
                SELECT u.id 
                FROM utilisateurs u 
                JOIN enfants e ON u.id = e.utilisateur_id 
                WHERE u.role = 'child' AND e.classe = ? AND u.id != ?
            ");
            $stmtChildren->execute([$childClasse, $expediteurId]);
            $childIds = $stmtChildren->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $recipients = array_unique(array_merge($tutorIds, $childIds));
        if (empty($recipients)) {
            return false;
        }

        $attachmentHtml = $this->uploadAttachment($file, $expediteurId, 'sos');
        $finalContenu = trim($texte) . $attachmentHtml;

        $this->db->beginTransaction();
        try {
            $stmtIns = $this->db->prepare("
                INSERT INTO messages (expediteur_id, destinataire_id, contenu, type_message, est_lu, date_envoi)
                VALUES (?, ?, ?, 'sos', 0, NOW())
            ");

            foreach ($recipients as $destId) {
                $stmtIns->execute([$expediteurId, $destId, $finalContenu]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Récupère les contacts ordonnés par activité récente avec le compteur de messages non lus.
     */
    public function getOrderedContacts(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.nom, u.prenom, u.role, u.photo_profil,
                MAX(m.date_envoi) AS last_message_date,
                SUM(CASE WHEN m.destinataire_id = ? AND m.expediteur_id = u.id AND m.est_lu = 0 THEN 1 ELSE 0 END) AS unread_count
            FROM utilisateurs u
            LEFT JOIN messages m 
                ON (m.expediteur_id = u.id AND m.destinataire_id = ?)
                OR (m.expediteur_id = ? AND m.destinataire_id = u.id)
            WHERE u.id != ?
            GROUP BY u.id, u.nom, u.prenom, u.role, u.photo_profil
            ORDER BY last_message_date DESC, u.nom ASC
        ");
        $stmt->execute([$userId, $userId, $userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère le fil privé et marque automatiquement les messages reçus comme lus.
     */
    public function getChatThread(int $user1, int $user2): array {
        $this->markAsRead($user1, $user2);

        $stmt = $this->db->prepare("
            SELECT m.*, 
                   u.nom AS exp_nom, 
                   u.prenom AS exp_prenom, 
                   u.photo_profil AS exp_photo
            FROM messages m
            JOIN utilisateurs u ON m.expediteur_id = u.id
            WHERE (m.expediteur_id = ? AND m.destinataire_id = ?)
               OR (m.expediteur_id = ? AND m.destinataire_id = ?)
            ORDER BY m.date_envoi ASC
        ");
        $stmt->execute([$user1, $user2, $user2, $user1]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère uniquement les SOS actifs NON LUS (est_lu = 0).
     * Un SOS marqué comme lu/résolu disparaît automatiquement.
     */
    public function getSosAlertsForUser(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT 
                m.*,
                u.nom AS expediteur_nom,
                u.prenom AS expediteur_prenom,
                u.photo_profil AS expediteur_photo,
                e.classe AS expediteur_classe
            FROM messages m
            JOIN utilisateurs u ON m.expediteur_id = u.id
            LEFT JOIN enfants e ON u.id = e.utilisateur_id
            WHERE m.destinataire_id = ? AND m.type_message = 'sos' AND m.est_lu = 0
            ORDER BY m.date_envoi DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère l'historique unique des demandes SOS émises par un enfant.
     */
    public function getSosHistoryForChild(int $childId): array {
        $stmt = $this->db->prepare("
            SELECT m.contenu, m.date_envoi, MIN(m.id) AS id
            FROM messages m
            WHERE m.expediteur_id = ? AND m.type_message = 'sos'
            GROUP BY m.contenu, m.date_envoi
            ORDER BY m.date_envoi DESC
        ");
        $stmt->execute([$childId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Marque les messages d'un expéditeur spécifique comme lus.
     */
    public function markAsRead(int $destinataireId, int $expediteurId): void {
        $stmt = $this->db->prepare("
            UPDATE messages 
            SET est_lu = 1 
            WHERE destinataire_id = ? AND expediteur_id = ? AND est_lu = 0
        ");
        $stmt->execute([$destinataireId, $expediteurId]);
    }

    /**
     * Résout un SOS spécifique en le marquant comme lu pour qu'il n'apparaisse plus.
     */
    public function resolveSosMessage(int $messageId, int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE messages 
            SET est_lu = 1 
            WHERE id = ? AND destinataire_id = ?
        ");
        $stmt->execute([$messageId, $userId]);
    }
}