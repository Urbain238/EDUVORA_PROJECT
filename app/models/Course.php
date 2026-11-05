<?php

class Course {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Publie une ressource statique (PDF, Vidéo locale ou Lien YouTube)
     */
    public function createRessource(array $data): bool {
        $sql = "INSERT INTO ressources (auteur_id, titre, type, format_fichier, lien_ressource, section_id, matiere_id, classe_id, date_publication)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['auteur_id'],
            $data['titre'],
            $data['type'],            // 'cours' ou 'epreuve'
            $data['format_fichier'],  // 'pdf', 'video', 'youtube'
            $data['lien_ressource'],  // Nom de fichier uploadé ou URL YouTube
            !empty($data['section_id']) ? $data['section_id'] : null,
            !empty($data['matiere_id']) ? $data['matiere_id'] : null,
            !empty($data['classe_id']) ? $data['classe_id'] : null
        ]);
    }

    /**
     * Récupère toutes les ressources publiées par un répétiteur
     */
    public function getByAuteur(int $auteurId): array {
        $sql = "SELECT r.*, s.nom AS section_nom, m.nom AS matiere_nom, c.nom AS classe_nom
                FROM ressources r
                LEFT JOIN sections s ON r.section_id = s.id
                LEFT JOIN matieres m ON r.matiere_id = m.id
                LEFT JOIN classes c ON r.classe_id = c.id
                WHERE r.auteur_id = ?
                ORDER BY r.date_publication DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$auteurId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Suppression d'une ressource et de son fichier physique sur le serveur
     */
    public function deleteRessource(int $id, int $auteurId): bool {
        $stmtFile = $this->db->prepare("SELECT format_fichier, lien_ressource, type FROM ressources WHERE id = ? AND auteur_id = ?");
        $stmtFile->execute([$id, $auteurId]);
        $res = $stmtFile->fetch(PDO::FETCH_ASSOC);

        if ($res && in_array($res['format_fichier'], ['pdf', 'video'])) {
            // Correction du nom de dossier : 'epreuves' au lieu de 'epreuve' (au pluriel)
            $folder = ($res['type'] === 'epreuve') ? 'epreuves' : 'courses';
            $filePath = dirname(__DIR__, 2) . '/public/uploads/' . $folder . '/' . $res['lien_ressource'];
            
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $stmt = $this->db->prepare("DELETE FROM ressources WHERE id = ? AND auteur_id = ?");
        return $stmt->execute([$id, $auteurId]);
    }

    /**
     * Utilitaires pour récupérer les données des sélecteurs
     */
    public function getSections(): array {
        return $this->db->query("SELECT * FROM sections ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMatieres(): array {
        return $this->db->query("SELECT * FROM matieres ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getClasses(): array {
        return $this->db->query("SELECT * FROM classes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}