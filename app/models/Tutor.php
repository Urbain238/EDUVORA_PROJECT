<?php
// app/models/Tutor.php

class Tutor {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Compte le nombre d'élèves uniques actuellement suivis par le répétiteur (statut = 'en_cours')
     */
    public function getTotalElevesSuivis($repetiteurUserId) {
        $sql = "SELECT COUNT(DISTINCT enfant_id) AS total 
                FROM tutorats_actifs 
                WHERE repetiteur_id = :repetiteur_id 
                  AND statut = 'en_cours'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':repetiteur_id' => $repetiteurUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    /**
     * Récupère le dernier avis reçu par le répétiteur avec les informations de l'auteur
     */
    public function getDernierAvis($tutorId) {
        $sql = "SELECT 
                    a.*,
                    CONCAT(u.prenom, ' ', u.nom) AS author_name,
                    u.photo_profil AS author_photo
                FROM avis a
                INNER JOIN utilisateurs u ON a.auteur_id = u.id
                WHERE a.repetiteur_id = :tutor_id
                ORDER BY a.id DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Récupère la note moyenne globale enregistrée pour le répétiteur
     */
    public function getNoteMoyenne($tutorId) {
        $sql = "SELECT note_moyenne FROM repetiteurs WHERE utilisateur_id = :tutor_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (float)($row['note_moyenne'] ?? 0.00);
    }

    /**
     * Recherche et classe les répétiteurs selon les filtres et le statut du parent (Standard vs Premium)
     */
    public function searchTutors($filters = [], $isParentPremium = false) {
        $sql = "SELECT 
                    u.id AS utilisateur_id,
                    u.nom,
                    u.prenom,
                    u.email,
                    u.photo_profil,
                    u.is_premium,
                    r.cv_url,
                    r.portfolio_url,
                    r.note_moyenne,
                    r.recommande_admin,
                    GROUP_CONCAT(DISTINCT m.nom SEPARATOR ', ') AS matieres_noms,
                    GROUP_CONCAT(DISTINCT c.nom SEPARATOR ', ') AS classes_noms,
                    GROUP_CONCAT(DISTINCT s.nom SEPARATOR ', ') AS sections_noms,
                    GROUP_CONCAT(DISTINCT g.image_url SEPARATOR ',') AS galerie_images,
                    COUNT(DISTINCT a.id) AS total_avis
                FROM utilisateurs u
                INNER JOIN repetiteurs r ON u.id = r.utilisateur_id
                LEFT JOIN repetiteur_sections rs ON r.utilisateur_id = rs.repetiteur_id
                LEFT JOIN sections s ON rs.section_id = s.id
                LEFT JOIN repetiteur_matieres rm ON r.utilisateur_id = rm.repetiteur_id
                LEFT JOIN matieres m ON rm.matiere_id = m.id
                LEFT JOIN repetiteur_classes rc ON r.utilisateur_id = rc.repetiteur_id
                LEFT JOIN classes c ON rc.classe_id = c.id
                LEFT JOIN galeries g ON u.id = g.utilisateur_id
                LEFT JOIN avis a ON r.utilisateur_id = a.repetiteur_id
                WHERE u.role = 'tutor'";

        $params = [];

        // Recherche textuelle (Nom ou Prénom)
        if (!empty($filters['query'])) {
            $sql .= " AND (u.nom LIKE :query OR u.prenom LIKE :query)";
            $params[':query'] = '%' . trim($filters['query']) . '%';
        }

        // Filtre par Section
        if (!empty($filters['section_id'])) {
            $sql .= " AND rs.section_id = :section_id";
            $params[':section_id'] = (int)$filters['section_id'];
        }

        // Filtre par Matière
        if (!empty($filters['matiere_id'])) {
            $sql .= " AND rm.matiere_id = :matiere_id";
            $params[':matiere_id'] = (int)$filters['matiere_id'];
        }

        // Filtre par Classe
        if (!empty($filters['classe_id'])) {
            $sql .= " AND rc.classe_id = :classe_id";
            $params[':classe_id'] = (int)$filters['classe_id'];
        }

        $sql .= " GROUP BY u.id, r.utilisateur_id";

        // Algorithme de Recommandation & Tri selon statut du Parent
        if ($isParentPremium) {
            // Parent Premium : Priorité absolue aux recommandés par l'Admin, tuteurs Premium, puis notes
            $sql .= " ORDER BY r.recommande_admin DESC, u.is_premium DESC, r.note_moyenne DESC, total_avis DESC";
        } else {
            // Parent Standard : Classement basé sur les notes et avis
            $sql .= " ORDER BY r.note_moyenne DESC, total_avis DESC, u.is_premium DESC";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assigne un répétiteur à un enfant dans la table tutorats_actifs
     */
    public function assignToChild($repetiteurId, $enfantId, $parentId) {
        // Vérifier si une relation de tutorat est déjà active
        $checkSql = "SELECT id FROM tutorats_actifs 
                     WHERE repetiteur_id = :r_id AND enfant_id = :e_id AND parent_id = :p_id AND statut = 'en_cours'";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([
            ':r_id' => $repetiteurId,
            ':e_id' => $enfantId,
            ':p_id' => $parentId
        ]);

        if ($checkStmt->fetch()) {
            return false; // Relation déjà existante
        }

        $sql = "INSERT INTO tutorats_actifs (repetiteur_id, enfant_id, parent_id, statut) 
                VALUES (:r_id, :e_id, :p_id, 'en_cours')";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':r_id' => $repetiteurId,
            ':e_id' => $enfantId,
            ':p_id' => $parentId
        ]);
    }

    /**
     * Récupérer tous les répétiteurs avec leurs stats (Avis, recommandation Admin)
     */
    public function getAllTutorsWithStats() {
        $sql = "SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium, u.date_creation,
                       r.cv_url, r.portfolio_url, r.note_moyenne, r.recommande_admin,
                       COUNT(a.id) as nb_avis
                FROM utilisateurs u
                JOIN repetiteurs r ON u.id = r.utilisateur_id
                LEFT JOIN avis a ON r.utilisateur_id = a.repetiteur_id
                WHERE u.role = 'tutor'
                GROUP BY u.id
                ORDER BY r.recommande_admin DESC, r.note_moyenne DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Basculer la recommandation globale de l'Admin (Oui / Non)
     */
    public function toggleAdminRecommendation($tutorId) {
        $sql = "UPDATE repetiteurs SET recommande_admin = NOT recommande_admin WHERE utilisateur_id = :tutor_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':tutor_id' => $tutorId]);
    }

    /**
     * Ajouter ou mettre à jour un avis (Admin, Parent ou Enfant) et recalculer la moyenne
     */
    public function addAvis($tutorId, $authorId, $note, $commentaire) {
        // 1. Vérifier si l'auteur a déjà laissé un avis pour ce répétiteur
        $checkSql = "SELECT id FROM avis WHERE repetiteur_id = :tutor_id AND auteur_id = :author_id";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([
            ':tutor_id' => $tutorId,
            ':author_id' => $authorId
        ]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Mise à jour de l'avis existant
            $sql = "UPDATE avis SET note = :note, commentaire = :commentaire WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':note' => $note,
                ':commentaire' => $commentaire,
                ':id' => $existing['id']
            ]);
        } else {
            // Insertion d'un nouvel avis
            $sql = "INSERT INTO avis (repetiteur_id, auteur_id, note, commentaire) VALUES (:tutor_id, :author_id, :note, :commentaire)";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':tutor_id' => $tutorId,
                ':author_id' => $authorId,
                ':note' => $note,
                ':commentaire' => $commentaire
            ]);
        }

        // 2. Mettre à jour la note moyenne du répétiteur
        if ($success) {
            $this->updateAverageRating($tutorId);
        }

        return $success;
    }

    /**
     * Recalculer automatiquement la note moyenne du répétiteur
     */
    private function updateAverageRating($tutorId) {
        $sql = "SELECT AVG(note) as moyenne FROM avis WHERE repetiteur_id = :tutor_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tutor_id' => $tutorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $moyenne = $row['moyenne'] ? round($row['moyenne'], 2) : 0.00;

        $updateSql = "UPDATE repetiteurs SET note_moyenne = :moyenne WHERE utilisateur_id = :tutor_id";
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([':moyenne' => $moyenne, ':tutor_id' => $tutorId]);
    }

    /**
     * Récupérer la liste de tous les parents
     */
    public function getAllParents() {
        $sql = "SELECT id, nom, prenom, email FROM utilisateurs WHERE role = 'parent' ORDER BY nom ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}