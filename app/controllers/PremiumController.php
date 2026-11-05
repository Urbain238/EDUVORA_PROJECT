<?php

class PremiumController extends Controller {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Vérification universelle de la session utilisateur
        $userId = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0;

        if ($userId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }
    }

    private function getUserId(): int {
        return (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
    }

    public function index() {
        $userId = $this->getUserId();
        $db = Database::getConnection();

        // Vérification en temps réel du statut Premium en base de données
        $stmt = $db->prepare("SELECT id, nom, prenom, role, is_premium FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $isPremium = !empty($user['is_premium']);

        $data = [
            'titre'      => 'Activer mon accès Premium - Horizon',
            'is_premium' => $isPremium,
            'user'       => $user
        ];

        $this->view('shared/upgrade_premium', $data);
    }

    public function activate() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'premium');
            exit();
        }

        $userId = $this->getUserId();
        // Récupère la valeur du formulaire (gère le nom 'code' ou 'code_premium')
        $code = trim($_POST['code'] ?? $_POST['code_premium'] ?? '');

        if (empty($code)) {
            $_SESSION['flash_error'] = "Veuillez saisir un code Premium.";
            header('Location: ' . BASE_URL . 'premium');
            exit();
        }

        // Inclusion sécurisée du modèle avec chemin absolu
        $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
        require_once $rootDir . '/app/models/PremiumCode.php';
        $premiumModel = new PremiumCode();

        $result = $premiumModel->redeem($code, $userId);

        if (!empty($result['success'])) {
            // Mise à jour de toutes les variables de session actives
            $_SESSION['is_premium'] = 1;
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['is_premium'] = 1;
            }

            $_SESSION['flash_success'] = $result['message'] ?? "Votre compte a été activé avec succès !";
        } else {
            $_SESSION['flash_error'] = $result['message'] ?? "Code invalide ou déjà utilisé.";
        }

        header('Location: ' . BASE_URL . 'premium');
        exit();
    }
}