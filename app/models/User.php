<?php

class User {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Recherche un utilisateur par Email (Admin, Parent, Tuteur)
     * OU par Identifiant de connexion (Enfant)
     */
    public function findByIdentifier(string $identifier) {
        $sql = "SELECT u.*, e.identifiant_connexion, e.parent_id, e.classe 
                FROM utilisateurs u 
                LEFT JOIN enfants e ON u.id = e.utilisateur_id 
                WHERE u.email = :email OR e.identifiant_connexion = :identifiant 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email'       => $identifier,
            ':identifiant' => $identifier
        ]);
        
        return $stmt->fetch();
    }

    /**
     * Recherche un utilisateur par son ID
     */
    public function findById(int $id) {
        $sql = "SELECT u.*, 
                       COALESCE(r.note_moyenne, 0.00) AS note_moyenne,
                       COALESCE(r.recommande_admin, FALSE) AS recommande_admin,
                       r.cv_url, r.portfolio_url,
                       e.parent_id, e.classe, e.identifiant_connexion
                FROM utilisateurs u 
                LEFT JOIN repetiteurs r ON u.id = r.utilisateur_id
                LEFT JOIN enfants e ON u.id = e.utilisateur_id
                WHERE u.id = :id 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Récupère la liste de tous les utilisateurs avec filtres (Admin)
     */
    public function findAll(string $search = '', string $role = ''): array {
        $sql = "SELECT u.*, 
                       e.identifiant_connexion
                FROM utilisateurs u 
                LEFT JOIN enfants e ON u.id = e.utilisateur_id 
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search OR e.identifiant_connexion LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($role)) {
            $sql .= " AND u.role = :role";
            $params[':role'] = $role;
        }

        $sql .= " ORDER BY u.date_creation DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Inscription d'un nouvel utilisateur (Parent ou Répétiteur)
     */
    public function create(array $data): int|false {
        $sql = "INSERT INTO utilisateurs (nom, prenom, email, password, role, photo_profil) 
                VALUES (:nom, :prenom, :email, :password, :role, :photo_profil)";
        
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            ':nom'          => $data['nom'],
            ':prenom'       => $data['prenom'],
            ':email'        => $data['email'],
            ':password'     => password_hash($data['password'], PASSWORD_BCRYPT),
            ':role'         => $data['role'],
            ':photo_profil' => $data['photo_profil'] ?? 'default.png'
        ]);

        if ($success) {
            $userId = (int)$this->db->lastInsertId();

            // Si c'est un tuteur/répétiteur, création sécurisée de son profil lié
            if ($data['role'] === 'tutor') {
                $stmtTutor = $this->db->prepare("
                    INSERT INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin) 
                    VALUES (:id, 0.00, FALSE)
                    ON DUPLICATE KEY UPDATE utilisateur_id = utilisateur_id
                ");
                $stmtTutor->execute([':id' => $userId]);
            }

            return $userId;
        }

        return false;
    }

    /**
     * Mise à jour de la photo de profil
     */
    public function updatePhoto(int $userId, string $filename): bool {
        $sql = "UPDATE utilisateurs SET photo_profil = :photo WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':photo' => $filename,
            ':id'    => $userId
        ]);
    }

    /**
     * Passage du compte au statut Premium
     */
    public function setPremium(int $userId): bool {
        $sql = "UPDATE utilisateurs SET is_premium = TRUE WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $userId]);
    }

    /**
     * Suspend ou Réactive le compte d'un utilisateur (Alterne 0 et 1)
     */
    public function toggleSuspension(int $userId): bool {
        $sql = "UPDATE utilisateurs SET est_suspendu = NOT est_suspendu WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $userId]);
    }
}