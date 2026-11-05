<?php

class PremiumCode {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Génère un code unique à usage unique
     */
    public function generate(int $adminId): string|false {
        $part1 = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $part2 = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $code = "HORIZON-{$part1}-{$part2}";

        $stmt = $this->db->prepare("
            INSERT INTO codes_premium (code, genere_par, statut) 
            VALUES (:code, :genere_par, 'actif')
        ");

        if ($stmt->execute([':code' => $code, ':genere_par' => $adminId])) {
            return $code;
        }

        return false;
    }

    /**
     * Récupère tous les codes générés avec les infos Admin & Utilisateur
     */
    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT cp.*, 
                   u_gen.prenom AS admin_prenom, u_gen.nom AS admin_nom,
                   u_use.prenom AS user_prenom, u_use.nom AS user_nom, u_use.email AS user_email
            FROM codes_premium cp
            JOIN utilisateurs u_gen ON cp.genere_par = u_gen.id
            LEFT JOIN utilisateurs u_use ON cp.utilise_par = u_use.id
            ORDER BY cp.date_creation DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Consomme un code et passe le compte en Premium
     */
    public function redeem(string $code, int $userId): array {
        $codeClean = strtoupper(trim($code));

        // Vérification du code
        $stmt = $this->db->prepare("SELECT * FROM codes_premium WHERE code = :code AND statut = 'actif'");
        $stmt->execute([':code' => $codeClean]);
        $codeData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeData) {
            return ['success' => false, 'message' => 'Code invalide ou déjà utilisé.'];
        }

        try {
            $this->db->beginTransaction();

            // 1. Marquer le code comme utilisé
            $stmtUpdateCode = $this->db->prepare("
                UPDATE codes_premium 
                SET utilise_par = :user_id, date_utilisation = NOW(), statut = 'utilise' 
                WHERE id = :id
            ");
            $stmtUpdateCode->execute([
                ':user_id' => $userId,
                ':id'      => $codeData['id']
            ]);

            // 2. Mettre à jour l'utilisateur en Premium
            $stmtUpdateUser = $this->db->prepare("
                UPDATE utilisateurs 
                SET is_premium = 1 
                WHERE id = :user_id
            ");
            $stmtUpdateUser->execute([':user_id' => $userId]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Félicitations ! Votre compte est désormais Premium.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Une erreur système est survenue. Veuillez réessayer.'];
        }
    }
}