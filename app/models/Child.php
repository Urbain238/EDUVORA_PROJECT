<?php

class Child {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Vérifie si un identifiant de connexion est déjà utilisé
     */
    public function isIdentifiantExists(string $identifiant): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM enfants WHERE identifiant_connexion = ?");
        $stmt->execute([$identifiant]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Crée un compte enfant (utilisateurs + enfants) sous transaction
     */
    public function createChild(int $parentId, string $nom, string $prenom, string $classe, string $identifiant, string $password): bool {
        try {
            $this->db->beginTransaction();

            // 1. Insertion dans la table utilisateurs (email = NULL car optionnel pour les enfants)
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $this->db->prepare("
                INSERT INTO utilisateurs (nom, prenom, email, password, role, photo_profil) 
                VALUES (?, ?, NULL, ?, 'child', 'default.png')
            ");
            $stmtUser->execute([$nom, $prenom, $hashedPassword]);
            $childUserId = $this->db->lastInsertId();

            // 2. Insertion dans la table enfants
            $stmtChild = $this->db->prepare("
                INSERT INTO enfants (utilisateur_id, parent_id, classe, identifiant_connexion) 
                VALUES (?, ?, ?, ?)
            ");
            $stmtChild->execute([$childUserId, $parentId, $classe, $identifiant]);

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * Récupère la liste des enfants rattachés à un parent
     */
    public function getChildrenByParent(int $parentId): array {
        $stmt = $this->db->prepare("
            SELECT u.id AS utilisateur_id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion,
                   COALESCE(AVG(p.score_obtenu), 0) AS progression_pct
            FROM enfants e
            JOIN utilisateurs u ON e.utilisateur_id = u.id
            LEFT JOIN progression_enfants p ON p.enfant_id = u.id
            WHERE e.parent_id = ?
            GROUP BY u.id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion
        ");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère le répétiteur attitré actif d'un enfant pour la vue du tableau de bord
     */
    public function getRepetiteurAttitre(int $childId): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                u.id AS utilisateur_id,
                u.nom,
                u.prenom,
                u.photo_profil,
                COALESCE(r.note_moyenne, 5.00) AS note_moyenne
            FROM tutorats_actifs ta
            JOIN utilisateurs u ON ta.repetiteur_id = u.id
            LEFT JOIN repetiteurs r ON u.id = r.utilisateur_id
            WHERE ta.enfant_id = ? AND ta.statut = 'en_cours'
            LIMIT 1
        ");
        $stmt->execute([$childId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }
}