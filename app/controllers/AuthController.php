<?php

class AuthController extends Controller {

    public function __construct() {
        // Garantit qu'une session est toujours active avant tout traitement
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Connexion des utilisateurs (Admin, Parent, Répétiteur, Enfant)
     */
    public function login() {
        // Redirige l'utilisateur s'il est déjà connecté
        if (isset($_SESSION['user_id'])) {
            $this->redirectByRole($_SESSION['user_role']);
        }

        $data = [
            'titre'  => 'Connexion - EDUVORA',
            'erreur' => ''
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $identifier = trim($_POST['identifiant'] ?? '');
            $password   = $_POST['password'] ?? '';

            if (!empty($identifier) && !empty($password)) {
                $userModel = $this->model('User');
                $user = $userModel->findByIdentifier($identifier);

                // Vérification du mot de passe haché
                if ($user && password_verify($password, $user['password'])) {

                    // VÉRIFICATION DE LA SUSPENSION DU COMPTE
                    if (!empty($user['est_suspendu']) && (int)$user['est_suspendu'] === 1) {
                        // S'assure qu'aucune session incomplète ne persiste
                        unset($_SESSION['user_id']);
                        
                        // Redirection vers la page dédiée au compte suspendu
                        header('Location: ' . BASE_URL . 'auth/suspended');
                        exit();
                    }
                    
                    // Enregistrement des informations principales en Session
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['user_nom']    = $user['nom'];
                    $_SESSION['user_prenom'] = $user['prenom'];
                    $_SESSION['user_role']   = $user['role']; // admin, parent, tutor, child
                    $_SESSION['user_photo']  = $user['photo_profil'];
                    $_SESSION['is_premium']  = (bool)$user['is_premium'];

                    // Redirection vers le tableau de bord correspondant
                    $this->redirectByRole($user['role']);
                } else {
                    $data['erreur'] = "Identifiant ou mot de passe incorrect.";
                }
            } else {
                $data['erreur'] = "Veuillez remplir tous les champs.";
            }
        }

        $this->view('auth/login', $data);
    }

    /**
     * Page d'affichage pour les comptes suspendus
     */
    public function suspended() {
        $data = [
            'titre' => 'Compte Suspendu - EDUVORA'
        ];

        $this->view('auth/suspended', $data);
    }

    /**
     * Inscription des nouveaux membres (Parents et Répétiteurs)
     */
    public function register() {
        // Redirige si déjà connecté
        if (isset($_SESSION['user_id'])) {
            $this->redirectByRole($_SESSION['user_role']);
        }

        $data = [
            'titre'  => 'Inscription - EDUVORA',
            'erreur' => '',
            'nom'    => '',
            'prenom' => '',
            'email'  => '',
            'role'   => 'parent'
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nom      = trim($_POST['nom'] ?? '');
            $prenom   = trim($_POST['prenom'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';
            $role     = $_POST['role'] ?? 'parent';
            $photo    = $_FILES['photo_profil'] ?? null;

            // Maintien des saisies en cas d'erreur de formulaire
            $data['nom']    = $nom;
            $data['prenom'] = $prenom;
            $data['email']  = $email;
            $data['role']   = $role;

            // Contrôles de validation des champs textes
            if (empty($nom) || empty($prenom) || empty($email) || empty($password) || empty($confirm)) {
                $data['erreur'] = "Veuillez remplir tous les champs obligatoires.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $data['erreur'] = "Veuillez fournir une adresse email valide.";
            } elseif ($password !== $confirm) {
                $data['erreur'] = "Les mots de passe ne correspondent pas.";
            } elseif (strlen($password) < 6) {
                $data['erreur'] = "Le mot de passe doit contenir au moins 6 caractères.";
            } elseif (!in_array($role, ['parent', 'tutor'])) {
                $data['erreur'] = "Le rôle sélectionné n'est pas autorisé.";
            } elseif (!$photo || $photo['error'] !== UPLOAD_ERR_OK) {
                $data['erreur'] = "Veuillez sélectionner une photo de profil.";
            } else {
                // Validation spécifique du fichier image
                $allowedExts  = ['jpg', 'jpeg', 'png', 'webp'];
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                $maxSize      = 2 * 1024 * 1024; // 2 Mo
                $fileExt      = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

                if ($photo['size'] > $maxSize) {
                    $data['erreur'] = "La photo de profil ne doit pas dépasser 2 Mo.";
                } elseif (!in_array($fileExt, $allowedExts) || !in_array($photo['type'], $allowedTypes)) {
                    $data['erreur'] = "Format d'image non supporté (seuls JPG, PNG et WEBP sont acceptés).";
                } else {
                    $userModel = $this->model('User');

                    // Vérification de l'unicité de l'email
                    if ($userModel->findByIdentifier($email)) {
                        $data['erreur'] = "Cet email est déjà associé à un compte EDUVORA.";
                    } else {
                        // Utilisation du chemin absolu sur le serveur
                        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/profiles/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $newFileName = uniqid('avatar_', true) . '.' . $fileExt;
                        $destination = $uploadDir . $newFileName;

                        if (move_uploaded_file($photo['tmp_name'], $destination)) {
                            // Création effective dans la BDD (table utilisateurs)
                            $userId = $userModel->create([
                                'nom'          => $nom,
                                'prenom'       => $prenom,
                                'email'        => $email,
                                'password'     => $password,
                                'role'         => $role,
                                'photo_profil' => $newFileName
                            ]);

                            if ($userId) {
                                // Si l'utilisateur est un répétiteur, création automatique de son profil dans 'repetiteurs'
                                if ($role === 'tutor') {
                                    $db = Database::getConnection();
                                    $stmtRep = $db->prepare("
                                        INSERT INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin) 
                                        VALUES (?, 0.00, FALSE)
                                        ON DUPLICATE KEY UPDATE utilisateur_id = utilisateur_id
                                    ");
                                    $stmtRep->execute([$userId]);
                                }

                                // Inscription réussie : connexion automatique et redirection
                                $_SESSION['user_id']     = $userId;
                                $_SESSION['user_nom']    = $nom;
                                $_SESSION['user_prenom'] = $prenom;
                                $_SESSION['user_role']   = $role;
                                $_SESSION['user_photo']  = $newFileName;
                                $_SESSION['is_premium']  = false;

                                $this->redirectByRole($role);
                            } else {
                                $data['erreur'] = "Une erreur est survenue lors de la création de votre compte.";
                            }
                        } else {
                            $data['erreur'] = "Une erreur est survenue lors de l'enregistrement de l'image.";
                        }
                    }
                }
            }
        }

        $this->view('auth/register', $data);
    }

    /**
     * Redirection vers le bon tableau de bord selon le profil
     */
    private function redirectByRole(string $role): void {
        switch ($role) {
            case 'admin':
                header('Location: ' . BASE_URL . 'admin/dashboard');
                break;
            case 'tutor':
                header('Location: ' . BASE_URL . 'tutor/dashboard');
                break;
            case 'parent':
                header('Location: ' . BASE_URL . 'parent/dashboard');
                break;
            case 'child':
                header('Location: ' . BASE_URL . 'child/dashboard');
                break;
            default:
                header('Location: ' . BASE_URL . 'auth/login');
        }
        exit();
    }

    /**
     * Déconnexion globale de la session
     */
    public function logout() {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . 'auth/login');
        exit();
    }
}