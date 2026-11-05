<?php

class AdminController extends Controller {

    public function __construct() {
        // Accès strictement réservé aux Administrateurs
        if (method_exists($this, 'requireRole')) {
            $this->requireRole('admin');
        }
    }

    /**
     * Point d'entrée par défaut (/admin)
     */
    public function index() {
        $this->dashboard();
    }

    /**
     * Tableau de bord principal (KPIs, Membres, Répétiteurs, Graphiques)
     */
    public function dashboard() {
        $db = Database::getConnection();

        // Synchronisation préventive des profils répétiteurs
        $this->syncTutorProfiles($db);

        $adminUserId = $this->getCurrentUserId();

        // 1. KPIs principaux
        $totalUsers     = $this->safeCount($db, "SELECT COUNT(*) FROM utilisateurs");
        $totalPremium   = $this->safeCount($db, "SELECT COUNT(*) FROM utilisateurs WHERE is_premium = TRUE AND role IN ('parent', 'tutor')");
        $activeTutorats = $this->safeCount($db, "SELECT COUNT(*) FROM tutorats_actifs WHERE statut = 'en_cours'");
        $totalResources = $this->safeCount($db, "SELECT COUNT(*) FROM ressources");
        
        // Comptage ciblé des messages reçus par l'administrateur connecté et non lus
        $unreadMessages = $this->safeCount($db, "SELECT COUNT(*) FROM messages WHERE destinataire_id = " . (int)$adminUserId . " AND est_lu = FALSE");

        // 2. Graphiques
        $premiumStats = $this->safeFetchAll($db, "
            SELECT role, 
                   SUM(CASE WHEN is_premium = TRUE THEN 1 ELSE 0 END) AS premium,
                   SUM(CASE WHEN is_premium = FALSE THEN 1 ELSE 0 END) AS standard
            FROM utilisateurs 
            WHERE role IN ('parent', 'tutor') 
            GROUP BY role
        ");

        $classDemandStats = $this->safeFetchAll($db, "
            SELECT classe, COUNT(*) AS total_enfants 
            FROM enfants 
            WHERE classe IS NOT NULL AND classe != '' 
            GROUP BY classe 
            ORDER BY total_enfants DESC 
            LIMIT 7
        ");

        $sectionStats = $this->safeFetchAll($db, "
            SELECT s.nom AS section, COUNT(r.id) AS total_ressources
            FROM sections s
            LEFT JOIN ressources r ON r.section_id = s.id
            GROUP BY s.id
        ");

        // 3. Gestion globale des utilisateurs / membres (Limité aux 5 derniers)
        $membersList = $this->safeFetchAll($db, "
            SELECT id, nom, prenom, email, role, is_premium, est_suspendu, photo_profil, date_creation 
            FROM utilisateurs 
            ORDER BY id DESC
            LIMIT 5
        ");

        // 4. Liste des Administrateurs (Limité aux 5 derniers)
        $adminsList = $this->safeFetchAll($db, "
            SELECT id, nom, prenom, email, photo_profil, date_creation 
            FROM utilisateurs 
            WHERE role = 'admin' 
            ORDER BY id DESC
            LIMIT 5
        ");

        // 5. Liste des Répétiteurs (Limité aux 5 meilleurs/derniers)
        $tutorsList = $this->safeFetchAll($db, "
            SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium, u.date_creation,
                   COALESCE(r.note_moyenne, 0.00) AS note_moyenne, 
                   COALESCE(r.recommande_admin, 0) AS recommande_admin, 
                   r.cv_url, r.portfolio_url,
                   (SELECT COUNT(*) FROM avis WHERE repetiteur_id = u.id) AS total_avis
            FROM utilisateurs u
            LEFT JOIN repetiteurs r ON r.utilisateur_id = u.id
            WHERE u.role = 'tutor'
            ORDER BY recommande_admin DESC, note_moyenne DESC, u.id DESC
            LIMIT 5
        ");

        $data = [
            'titre'            => 'Administration - Horizon',
            'user'             => $_SESSION,
            'totalUsers'       => $totalUsers,
            'totalPremium'     => $totalPremium,
            'activeTutorats'   => $activeTutorats,
            'totalResources'   => $totalResources,
            'sosMessages'      => $unreadMessages,
            'unreadMessages'   => $unreadMessages,
            'premiumStats'     => json_encode($premiumStats),
            'classDemandStats' => json_encode($classDemandStats),
            'sectionStats'     => json_encode($sectionStats),
            'membersList'      => $membersList,
            'adminsList'       => $adminsList,
            'tutorsList'       => $tutorsList
        ];

        $this->view('admin/dashboard', $data);
    }

    /**
     * Page de gestion de la Bibliothèque / Ressources (Cours & Épreuves) (/admin/courses ou /admin/library)
     */
    public function courses() {
        $db = Database::getConnection();

        $filterType    = trim($_GET['type'] ?? '');
        $filterSection = (int)($_GET['section_id'] ?? 0);
        $filterMatiere = (int)($_GET['matiere_id'] ?? 0);
        $filterClasse  = (int)($_GET['classe_id'] ?? 0);
        $search        = trim($_GET['search'] ?? '');

        $whereClauses = ["1=1"];
        $params       = [];

        if (!empty($filterType) && in_array($filterType, ['cours', 'epreuve'])) {
            $whereClauses[] = "r.type = ?";
            $params[]       = $filterType;
        }
        if ($filterSection > 0) {
            $whereClauses[] = "r.section_id = ?";
            $params[]       = $filterSection;
        }
        if ($filterMatiere > 0) {
            $whereClauses[] = "r.matiere_id = ?";
            $params[]       = $filterMatiere;
        }
        if ($filterClasse > 0) {
            $whereClauses[] = "r.classe_id = ?";
            $params[]       = $filterClasse;
        }
        if (!empty($search)) {
            $whereClauses[] = "(r.titre LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
            $searchTerm     = "%{$search}%";
            $params[]       = $searchTerm;
            $params[]       = $searchTerm;
            $params[]       = $searchTerm;
        }

        $whereSql = implode(" AND ", $whereClauses);

        $sql = "
            SELECT r.*, 
                   u.nom AS auteur_nom, u.prenom AS auteur_prenom, u.email AS auteur_email,
                   s.nom AS section_nom, 
                   m.nom AS matiere_nom, 
                   c.nom AS classe_nom
            FROM ressources r
            LEFT JOIN utilisateurs u ON r.auteur_id = u.id
            LEFT JOIN sections s ON r.section_id = s.id
            LEFT JOIN matieres m ON r.matiere_id = m.id
            LEFT JOIN classes c ON r.classe_id = c.id
            WHERE {$whereSql}
            ORDER BY r.id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $resourcesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Données pour alimenter les listes déroulantes des filtres
        $sections = $this->safeFetchAll($db, "SELECT * FROM sections ORDER BY nom ASC");
        $matieres = $this->safeFetchAll($db, "SELECT * FROM matieres ORDER BY nom ASC");
        $classes  = $this->safeFetchAll($db, "SELECT * FROM classes ORDER BY nom ASC");

        $data = [
            'titre'         => 'Bibliothèque - Gestion des Ressources - Horizon',
            'user'          => $_SESSION,
            'resourcesList' => $resourcesList,
            'sections'      => $sections,
            'matieres'      => $matieres,
            'classes'       => $classes,
            'filters'       => [
                'type'       => $filterType,
                'section_id' => $filterSection,
                'matiere_id' => $filterMatiere,
                'classe_id'  => $filterClasse,
                'search'     => $search
            ]
        ];

        $this->view('admin/courses', $data);
    }

    /**
     * Alias d'accès pour /admin/library
     */
    public function library() {
        $this->courses();
    }

    /**
     * Suppression d'une ressource (Fichier physique + Entrée en base)
     */
    public function deleteCourse($id) {
        $resourceId = (int)$id;

        if ($resourceId > 0) {
            $db = Database::getConnection();

            // Recherche de la ressource avant suppression pour nettoyer le fichier du disque
            $stmt = $db->prepare("SELECT lien_ressource, format_fichier, type FROM ressources WHERE id = ?");
            $stmt->execute([$resourceId]);
            $resource = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($resource) {
                // Suppression du fichier du serveur s'il ne s'agit pas d'une URL externe/YouTube
                if ($resource['format_fichier'] !== 'youtube' && !empty($resource['lien_ressource'])) {
                    $filename  = basename($resource['lien_ressource']);
                    $subFolder = ($resource['type'] === 'epreuve') ? 'epreuves' : 'courses';
                    $rootDir   = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $filePath  = $rootDir . '/public/uploads/' . $subFolder . '/' . $filename;

                    if (file_exists($filePath) && is_file($filePath)) {
                        @unlink($filePath);
                    }
                }

                // Suppression de l'enregistrement BDD
                $stmtDelete = $db->prepare("DELETE FROM ressources WHERE id = ?");
                $stmtDelete->execute([$resourceId]);

                $_SESSION['flash_success'] = "La ressource a été supprimée avec succès.";
            } else {
                $_SESSION['flash_error'] = "Ressource introuvable.";
            }
        } else {
            $_SESSION['flash_error'] = "Identifiant de ressource invalide.";
        }

        $this->redirectBack('admin/courses');
    }

    /**
     * Alias de suppression pour /admin/deleteResource/$id
     */
    public function deleteResource($id) {
        $this->deleteCourse($id);
    }

    /**
     * Page de gestion des membres / utilisateurs (/admin/users)
     */
    public function users() {
        $this->checkAdminAccess(); 

        $search = trim($_GET['search'] ?? '');
        $role = trim($_GET['role'] ?? '');

        if (method_exists($this, 'model')) {
            $userModel = $this->model('User');
            if ($userModel && method_exists($userModel, 'getAllUsers')) {
                $usersList = $userModel->getAllUsers($search, $role);
            } else {
                $db = Database::getConnection();
                $usersList = $this->safeFetchAll($db, "SELECT id, nom, prenom, email, role, is_premium, est_suspendu, photo_profil, date_creation FROM utilisateurs ORDER BY id DESC");
            }
        } else {
            $db = Database::getConnection();
            $usersList = $this->safeFetchAll($db, "SELECT id, nom, prenom, email, role, is_premium, est_suspendu, photo_profil, date_creation FROM utilisateurs ORDER BY id DESC");
        }

        $this->view('admin/users', [
            'titre' => 'Gestion des Membres',
            'usersList' => $usersList,
            'filters' => [
                'search' => $search,
                'role' => $role
            ]
        ]);
    }

    /**
     * Action pour suspendre ou réactiver un utilisateur
     */
    public function toggleUserStatus($id = null) {
        $this->checkAdminAccess();

        $userId = (int)$id;
        $currentUserId = $this->getCurrentUserId();

        if ($userId <= 0) {
            $_SESSION['flash_error'] = "Identifiant d'utilisateur invalide.";
            $this->redirectBack('admin/members');
        }

        // Empêcher l'admin de se suspendre lui-même
        if ($userId === $currentUserId) {
            $_SESSION['flash_error'] = "Vous ne pouvez pas suspendre votre propre compte administrateur.";
            $this->redirectBack('admin/members');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, prenom, nom, est_suspendu FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Inverse le statut est_suspendu (0 -> 1 ou 1 -> 0)
            $stmtUpdate = $db->prepare("UPDATE utilisateurs SET est_suspendu = NOT COALESCE(est_suspendu, 0) WHERE id = ?");
            $stmtUpdate->execute([$userId]);

            $action = !empty($user['est_suspendu']) ? "réactivé" : "suspendu";
            $_SESSION['flash_success'] = "Le compte de " . htmlspecialchars($user['prenom'] . ' ' . $user['nom']) . " a été $action avec succès.";
        } else {
            $_SESSION['flash_error'] = "Utilisateur introuvable.";
        }

        $this->redirectBack('admin/members');
    }

    /**
     * Page dédiée à la gestion des Avis & Répétiteurs (/admin/tutors)
     */
    public function tutors() {
        $db = Database::getConnection();

        // Synchronisation préventive des profils répétiteurs
        $this->syncTutorProfiles($db);

        // Récupération de la liste complète des répétiteurs
        $tutorsList = $this->safeFetchAll($db, "
            SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium, u.date_creation,
                   COALESCE(r.note_moyenne, 0.00) AS note_moyenne, 
                   COALESCE(r.recommande_admin, 0) AS recommande_admin, 
                   r.cv_url, r.portfolio_url,
                   (SELECT COUNT(*) FROM avis WHERE repetiteur_id = u.id) AS total_avis,
                   (SELECT COUNT(*) FROM avis WHERE repetiteur_id = u.id) AS nb_avis
            FROM utilisateurs u
            LEFT JOIN repetiteurs r ON r.utilisateur_id = u.id
            WHERE u.role = 'tutor'
            ORDER BY recommande_admin DESC, note_moyenne DESC, u.id DESC
        ");

        // Récupération de la liste des parents pour la modal de recommandation ciblée
        $parentsList = $this->safeFetchAll($db, "
            SELECT id, nom, prenom, email 
            FROM utilisateurs 
            WHERE role = 'parent' 
            ORDER BY nom ASC, prenom ASC
        ");

        $data = [
            'titre'       => 'Avis & Répétiteurs - Horizon',
            'user'        => $_SESSION,
            'tutorsList'  => $tutorsList,
            'tutors'      => $tutorsList,  // Alias de compatibilité
            'parentsList' => $parentsList,
            'parents'     => $parentsList   // Alias de compatibilité
        ];

        $this->view('admin/tutors', $data);
    }

    /**
     * Soumettre un avis et une note sur un répétiteur et recalculer sa moyenne
     */
    public function addTutorReview() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tutorId     = (int)($_POST['tutor_id'] ?? 0);
            $note        = (int)($_POST['note'] ?? 5);
            $commentaire = trim($_POST['commentaire'] ?? '');
            $userId      = $this->getCurrentUserId();

            if ($tutorId > 0 && $note >= 1 && $note <= 5 && $userId > 0) {
                $db = Database::getConnection();

                // Assure la présence de la ligne dans 'repetiteurs'
                $stmtInit = $db->prepare("
                    INSERT INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin) 
                    VALUES (?, 0.00, FALSE) 
                    ON DUPLICATE KEY UPDATE utilisateur_id = utilisateur_id
                ");
                $stmtInit->execute([$tutorId]);

                // Insertion de l'avis
                $stmt = $db->prepare("INSERT INTO avis (repetiteur_id, auteur_id, note, commentaire) VALUES (?, ?, ?, ?)");
                $stmt->execute([$tutorId, $userId, $note, $commentaire]);

                // Recalcul automatique de la moyenne des notes
                $stmtAvg = $db->prepare("SELECT AVG(note) FROM avis WHERE repetiteur_id = ?");
                $stmtAvg->execute([$tutorId]);
                $newAvg = round((float)$stmtAvg->fetchColumn(), 2);

                // Mise à jour de la note moyenne
                $stmtUpdate = $db->prepare("UPDATE repetiteurs SET note_moyenne = ? WHERE utilisateur_id = ?");
                $stmtUpdate->execute([$newAvg, $tutorId]);

                $_SESSION['flash_success'] = "L'avis et la note ont bien été enregistrés.";
            } else {
                $_SESSION['flash_error'] = "Données invalides ou note hors de la plage (1 à 5).";
            }
        }

        $this->redirectBack('admin/tutors');
    }

    /**
     * Recommander ou ne plus recommander un répétiteur sur toute la plateforme (Toggle Global)
     */
    public function toggleRecommendation($tutorId) {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin) 
            VALUES (?, 0.00, TRUE) 
            ON DUPLICATE KEY UPDATE recommande_admin = NOT recommande_admin
        ");
        $stmt->execute([(int)$tutorId]);

        $_SESSION['flash_success'] = "Statut de recommandation globale mis à jour.";
        $this->redirectBack('admin/tutors');
    }

    /**
     * Alias pour toggleRecommendation
     */
    public function toggleTutorRecommendation($tutorId) {
        $this->toggleRecommendation($tutorId);
    }

    /**
     * Recommander un répétiteur spécifiquement à un parent via un message interne
     */
    public function recommendTutorToParent() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tutorId   = (int)($_POST['tutor_id'] ?? 0);
            $parentId  = (int)($_POST['parent_id'] ?? 0);
            $noteAdmin = trim($_POST['note_admin'] ?? '');
            $adminId   = $this->getCurrentUserId();

            if ($tutorId > 0 && $parentId > 0 && $adminId > 0) {
                $db = Database::getConnection();

                $stmtTutor = $db->prepare("SELECT nom, prenom FROM utilisateurs WHERE id = ? AND role = 'tutor'");
                $stmtTutor->execute([$tutorId]);
                $tutor = $stmtTutor->fetch(PDO::FETCH_ASSOC);

                if ($tutor) {
                    $messageContent = "💡 **Recommandation de l'administration Horizon**\n\n";
                    $messageContent .= "Bonjour, nous vous recommandons vivement le répétiteur **" . $tutor['prenom'] . " " . $tutor['nom'] . "** pour accompagner votre enfant.\n\n";
                    if (!empty($noteAdmin)) {
                        $messageContent .= "💬 *Note de l'administrateur :* \"" . $noteAdmin . "\"\n\n";
                    }
                    $messageContent .= "Vous pouvez consulter son profil et prendre contact directement depuis la plateforme.";

                    $stmtMsg = $db->prepare("
                        INSERT INTO messages (expediteur_id, destinataire_id, contenu, type_message, est_lu, date_envoi) 
                        VALUES (?, ?, ?, 'standard', FALSE, NOW())
                    ");
                    $stmtMsg->execute([$adminId, $parentId, $messageContent]);

                    $_SESSION['flash_success'] = "La recommandation a été envoyée directement dans la messagerie du parent.";
                } else {
                    $_SESSION['flash_error'] = "Répétiteur introuvable.";
                }
            } else {
                $_SESSION['flash_error'] = "Veuillez sélectionner un parent valide.";
            }
        }

        $this->redirectBack('admin/tutors');
    }

    /**
     * Page dédiée à la Gestion des Membres (/admin/members)
     */
    public function members() {
        $db = Database::getConnection();

        $membersList = $this->safeFetchAll($db, "
            SELECT id, nom, prenom, email, role, is_premium, est_suspendu, photo_profil, date_creation 
            FROM utilisateurs 
            ORDER BY id DESC
        ");

        $data = [
            'titre'       => 'Gestion des Membres - Horizon',
            'user'        => $_SESSION,
            'membersList' => $membersList
        ];

        $this->view('admin/members', $data);
    }

    /**
     * Page complète de gestion des Administrateurs (Création & Consultation)
     */
    public function addAdmin() {
        $db = Database::getConnection();
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nom      = trim($_POST['nom'] ?? '');
            $prenom   = trim($_POST['prenom'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!empty($nom) && !empty($prenom) && !empty($email) && !empty($password)) {
                $stmtCheck = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                $stmtCheck->execute([$email]);

                if ($stmtCheck->fetch()) {
                    $error = "Cette adresse email est déjà utilisée.";
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                    $stmtInsert = $db->prepare("
                        INSERT INTO utilisateurs (nom, prenom, email, password, role, photo_profil, is_premium) 
                        VALUES (?, ?, ?, ?, 'admin', 'default.png', TRUE)
                    ");
                    $stmtInsert->execute([$nom, $prenom, $email, $hashedPassword]);
                    
                    $_SESSION['flash_success'] = "L'administrateur a été créé avec succès.";
                    header('Location: ' . BASE_URL . 'admin/addAdmin');
                    exit();
                }
            } else {
                $error = "Veuillez remplir tous les champs obligatoires.";
            }
        }

        $adminsList = $this->safeFetchAll($db, "
            SELECT id, nom, prenom, email, photo_profil, date_creation 
            FROM utilisateurs 
            WHERE role = 'admin' 
            ORDER BY id DESC
        ");

        $data = [
            'titre'      => 'Gestion des Administrateurs - Horizon',
            'adminsList' => $adminsList,
            'error'      => $error
        ];

        $this->view('admin/add_admin', $data);
    }

    /**
     * Modification d'un administrateur (UPDATE)
     */
    public function updateAdmin() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $db       = Database::getConnection();
            $id       = (int)($_POST['id'] ?? 0);
            $nom      = trim($_POST['nom'] ?? '');
            $prenom   = trim($_POST['prenom'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($id > 0 && !empty($nom) && !empty($prenom) && !empty($email)) {
                $stmtCheck = $db->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ?");
                $stmtCheck->execute([$email, $id]);

                if ($stmtCheck->fetch()) {
                    $_SESSION['flash_error'] = "Cet email est déjà attribué à un autre compte.";
                } else {
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("
                            UPDATE utilisateurs 
                            SET nom = ?, prenom = ?, email = ?, password = ? 
                            WHERE id = ? AND role = 'admin'
                        ");
                        $stmt->execute([$nom, $prenom, $email, $hashedPassword, $id]);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE utilisateurs 
                            SET nom = ?, prenom = ?, email = ? 
                            WHERE id = ? AND role = 'admin'
                        ");
                        $stmt->execute([$nom, $prenom, $email, $id]);
                    }

                    $_SESSION['flash_success'] = "L'administrateur a été mis à jour avec succès.";
                }
            } else {
                $_SESSION['flash_error'] = "Champs obligatoires manquants pour la modification.";
            }
        }

        header('Location: ' . BASE_URL . 'admin/addAdmin');
        exit();
    }

    /**
     * Suppression d'un administrateur (DELETE)
     */
    public function deleteAdmin($id) {
        $adminId = (int)$id;
        $currentUserId = $this->getCurrentUserId();

        if ($adminId === $currentUserId) {
            $_SESSION['flash_error'] = "Action impossible : vous ne pouvez pas supprimer votre propre compte.";
        } else {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id = ? AND role = 'admin'");
            $stmt->execute([$adminId]);
            $_SESSION['flash_success'] = "L'administrateur a été supprimé.";
        }

        header('Location: ' . BASE_URL . 'admin/addAdmin');
        exit();
    }

    /**
     * Mise à jour de la photo de profil de l'administrateur connecté
     */
    public function updateProfilePhoto() {
        $userId = $this->getCurrentUserId();

        if ($userId <= 0) {
            $_SESSION['flash_error'] = "Session invalide ou expirée.";
            header('Location: ' . BASE_URL . 'admin/dashboard');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_profil'])) {
            $file = $_FILES['photo_profil'];

            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (in_array($ext, $allowed)) {
                    $newName = 'admin_' . $userId . '_' . time() . '.' . $ext;
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $targetDir = $rootDir . '/public/uploads/profiles/';

                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0777, true);
                    }

                    $targetPath = $targetDir . $newName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        @chmod($targetPath, 0666);

                        $db = Database::getConnection();
                        $stmt = $db->prepare("UPDATE utilisateurs SET photo_profil = ? WHERE id = ?");
                        $stmt->execute([$newName, $userId]);

                        $_SESSION['user_photo'] = $newName;
                        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                            $_SESSION['user']['photo_profil'] = $newName;
                        }

                        $_SESSION['flash_success'] = "Photo de profil mise à jour avec succès.";
                    } else {
                        $_SESSION['flash_error'] = "Erreur de transfert : Vérifiez les permissions du dossier /public/uploads/profiles/";
                    }
                } else {
                    $_SESSION['flash_error'] = "Format d'image non valide (seuls JPG, PNG et WEBP sont acceptés).";
                }
            } else {
                $_SESSION['flash_error'] = "Erreur lors de l'envoi du fichier (Code : " . $file['error'] . ").";
            }
        }

        $this->redirectBack('admin/dashboard');
    }

    /**
     * Activer/Désactiver le statut Premium d'un membre
     */
    public function togglePremiumUser($userId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE utilisateurs SET is_premium = NOT is_premium WHERE id = ?");
        $stmt->execute([(int)$userId]);

        $_SESSION['flash_success'] = "Statut Premium mis à jour pour cet utilisateur.";
        $this->redirectBack('admin/members');
    }

    /**
     * Supprimer un membre générique de la plateforme
     */
    public function deleteUser($id) {
        $userId = (int)$id;
        $currentUserId = $this->getCurrentUserId();

        if ($userId !== $currentUserId) {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id = ?");
            $stmt->execute([$userId]);
            $_SESSION['flash_success'] = "L'utilisateur a été supprimé.";
        }

        $this->redirectBack('admin/members');
    }

    /**
     * Page de gestion des codes Premium (/admin/premiumManager)
     */
    public function premiumManager() {
        $db = Database::getConnection();

        $codes = $this->safeFetchAll($db, "
            SELECT 
                cp.id, cp.code, cp.statut, cp.date_creation, cp.date_utilisation,
                admin.nom AS admin_nom, admin.prenom AS admin_prenom,
                user.nom AS user_nom, user.prenom AS user_prenom, user.email AS user_email, user.role AS user_role
            FROM codes_premium cp
            JOIN utilisateurs admin ON cp.genere_par = admin.id
            LEFT JOIN utilisateurs user ON cp.utilise_par = user.id
            ORDER BY cp.id DESC
        ");

        $data = [
            'titre' => 'Gestion des codes Premium - Horizon',
            'codes' => $codes
        ];

        $this->view('admin/premium_manager', $data);
    }

    /**
     * Alias de compatibilité pour premiumManager (/admin/premium_manager)
     */
    public function premium_manager() {
        $this->premiumManager();
    }

    /**
     * Action POST : Générer un nouveau code Premium à usage unique
     */
    public function generatePremiumCode() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $db = Database::getConnection();
            $adminId = $this->getCurrentUserId();

            if ($adminId > 0) {
                $randomPart1 = strtoupper(bin2hex(random_bytes(2)));
                $randomPart2 = strtoupper(bin2hex(random_bytes(2)));
                $code = "HORIZON-{$randomPart1}-{$randomPart2}";

                $stmt = $db->prepare("INSERT INTO codes_premium (code, genere_par, statut) VALUES (?, ?, 'actif')");
                if ($stmt->execute([$code, $adminId])) {
                    $_SESSION['flash_success'] = "Nouveau code Premium généré : <strong>{$code}</strong>";
                } else {
                    $_SESSION['flash_error'] = "Erreur lors de la génération du code Premium.";
                }
            } else {
                $_SESSION['flash_error'] = "Session administrateur invalide.";
            }
        }

        header('Location: ' . BASE_URL . 'admin/premiumManager');
        exit();
    }

    /**
     * Vérification de sécurité des privilèges administrateur
     */
    private function checkAdminAccess(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $this->getCurrentUserId();
        $role   = $_SESSION['user_role'] ?? $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';

        if ($userId <= 0 || ($role !== 'admin' && ($_SESSION['role'] ?? '') !== 'admin')) {
            $_SESSION['flash_error'] = "Accès refusé. Vous devez être administrateur pour accéder à cette page.";
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }
    }

    /**
     * Synchronisation préventive des profils tuteurs manquants dans 'repetiteurs'
     */
    private function syncTutorProfiles(PDO $db): void {
        try {
            $db->exec("
                INSERT IGNORE INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin)
                SELECT id, 0.00, FALSE FROM utilisateurs WHERE role = 'tutor'
            ");
        } catch (Throwable $e) {
            // Ignorer silencieusement si la synchronisation échoue
        }
    }

    /**
     * Récupère l'ID de l'utilisateur connecté
     */
    private function getCurrentUserId(): int {
        return (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['id'] ?? 0);
    }

    /**
     * Utilitaire de redirection vers la page précédente
     */
    private function redirectBack(string $fallbackRoute) {
        $referer = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . $fallbackRoute);
        header('Location: ' . $referer);
        exit();
    }

    /**
     * Compte sécurisé sans risque de Fatal Error
     */
    private function safeCount(PDO $db, string $query): int {
        try {
            $stmt = $db->query($query);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * FetchAll sécurisé sans risque de Fatal Error
     */
    private function safeFetchAll(PDO $db, string $query): array {
        try {
            $stmt = $db->query($query);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}