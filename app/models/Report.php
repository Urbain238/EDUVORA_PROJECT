<?php
// app/models/Report.php

class Report {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Récupère la liste des élèves actifs actuellement suivis par le répétiteur
     */
    public function getAssignedStudents($tutorId) {
        $sql = "SELECT 
                    ta.enfant_id, 
                    e_u.nom AS enfant_nom, 
                    e_u.prenom AS enfant_prenom, 
                    e_u.photo_profil AS enfant_photo,
                    e.classe AS enfant_classe,
                    p_u.nom AS parent_nom,
                    p_u.prenom AS parent_prenom
                FROM tutorats_actifs ta
                JOIN utilisateurs e_u ON ta.enfant_id = e_u.id
                LEFT JOIN enfants e ON e.utilisateur_id = e_u.id
                JOIN utilisateurs p_u ON ta.parent_id = p_u.id
                WHERE ta.repetiteur_id = :tutor_id AND ta.statut = 'en_cours'
                ORDER BY e_u.nom ASC, e_u.prenom ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifie si un enfant est activement assigné à ce répétiteur
     */
    public function isChildAssignedToTutor($tutorId, $childId) {
        $sql = "SELECT COUNT(*) FROM tutorats_actifs 
                WHERE repetiteur_id = :tutor_id AND enfant_id = :child_id AND statut = 'en_cours'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId, ':child_id' => $childId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Ajoute une nouvelle séance dans le cahier de texte
     */
    public function addReport($tutorId, $childId, $titreSeance, $rapport, $dateSeance) {
        $sql = "INSERT INTO cahier_de_texte (repetiteur_id, enfant_id, titre_seance, rapport, date_seance) 
                VALUES (:repetiteur_id, :enfant_id, :titre_seance, :rapport, :date_seance)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':repetiteur_id' => $tutorId,
            ':enfant_id'     => $childId,
            ':titre_seance'  => $titreSeance,
            ':rapport'       => $rapport,
            ':date_seance'   => $dateSeance
        ]);
    }

    /**
     * Récupère toutes les séances saisies pour un enfant spécifique
     */
    public function getReportsByChild($tutorId, $childId) {
        $sql = "SELECT cdt.*, e_u.nom AS enfant_nom, e_u.prenom AS enfant_prenom
                FROM cahier_de_texte cdt
                JOIN utilisateurs e_u ON cdt.enfant_id = e_u.id
                WHERE cdt.repetiteur_id = :tutor_id AND cdt.enfant_id = :child_id
                ORDER BY cdt.date_seance DESC, cdt.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId, ':child_id' => $childId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Supprime une entrée du cahier de texte
     */
    public function deleteReport($reportId, $tutorId) {
        $sql = "DELETE FROM cahier_de_texte WHERE id = :report_id AND repetiteur_id = :tutor_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':report_id' => $reportId, ':tutor_id' => $tutorId]);
    }
}