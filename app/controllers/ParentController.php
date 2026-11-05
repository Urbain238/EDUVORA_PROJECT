<?php

class ParentController extends Controller {

    public function __construct() {
        $this->requireRole('parent');
    }

    /**
     * Point d'entrée par défaut
     */
    public function index() {
        $this->dashboard();
    }

    /**
     * Tableau de bord principal du parent
     */
    public function dashboard() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        $user = null;
        $children = [];
        $activeTutorsCount = 0;
        $avgScore = 0;
        $unreadMessages = 0;
        $recentReports = [];
        $recommendedTutors = [];

        // 1. Informations du parent
        try {
            $stmtUser = $db->prepare("SELECT id, nom, prenom, email, photo_profil, is_premium, role FROM utilisateurs WHERE id = ? AND role = 'parent'");
            $stmtUser->execute([$parentId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION['user'] = $user;
            }
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? null;
        }

        // 2. Liste des enfants rattachés avec progression moyenne
        try {
            $stmtChildren = $db->prepare("
                SELECT u.id AS utilisateur_id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion,
                       COALESCE(AVG(p.score_obtenu), 0) AS progression_pct
                FROM enfants e
                JOIN utilisateurs u ON e.utilisateur_id = u.id
                LEFT JOIN progression_enfants p ON p.enfant_id = u.id
                WHERE e.parent_id = ?
                GROUP BY u.id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion
                LIMIT 2
            ");
            $stmtChildren->execute([$parentId]);
            $children = $stmtChildren->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $children = [];
        }

        // 3. Tutorats actifs
        try {
            $stmtTutors = $db->prepare("SELECT COUNT(*) AS total FROM tutorats_actifs WHERE parent_id = ? AND statut = 'en_cours'");
            $stmtTutors->execute([$parentId]);
            $activeTutorsCount = (int) ($stmtTutors->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        } catch (PDOException $e) {
            $activeTutorsCount = 0;
        }

        // 4. Score moyen global
        try {
            $stmtAvg = $db->prepare("
                SELECT COALESCE(AVG(p.score_obtenu), 0) AS avg_score
                FROM progression_enfants p
                JOIN enfants e ON p.enfant_id = e.utilisateur_id
                WHERE e.parent_id = ?
            ");
            $stmtAvg->execute([$parentId]);
            $avgScore = $stmtAvg->fetch(PDO::FETCH_ASSOC)['avg_score'] ?? 0;
        } catch (PDOException $e) {
            $avgScore = 0;
        }

        // 5. Messages non lus
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) AS unread FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$parentId]);
            $unreadMessages = (int) ($stmtMsg->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        // 6. Derniers rapports de séance
        try {
            $stmtReports = $db->prepare("
                SELECT c.*, u.nom AS repetiteur_nom, u.prenom AS repetiteur_prenom
                FROM cahier_de_texte c
                JOIN utilisateurs u ON c.repetiteur_id = u.id
                JOIN enfants e ON c.enfant_id = e.utilisateur_id
                WHERE e.parent_id = ?
                ORDER BY c.date_seance DESC LIMIT 1
            ");
            $stmtReports->execute([$parentId]);
            $recentReports = $stmtReports->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $recentReports = [];
        }

        // 7. Répétiteurs recommandés
        try {
            $stmtRecTutors = $db->prepare("
                SELECT u.id AS utilisateur_id, u.nom, u.prenom, u.photo_profil, u.is_premium,
                       r.telephone, r.whatsapp, r.note_moyenne, r.recommande_admin
                FROM repetiteurs r
                JOIN utilisateurs u ON r.utilisateur_id = u.id
                WHERE u.role = 'tutor'
                ORDER BY r.recommande_admin DESC, u.is_premium DESC, r.note_moyenne DESC LIMIT 4
            ");
            $stmtRecTutors->execute();
            $recommendedTutors = $stmtRecTutors->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $recommendedTutors = [];
        }

        $data = [
            'titre'               => 'Espace Parent - EDUVORA',
            'user'                => $user,
            'children'            => $children,
            'active_tutors_count' => $activeTutorsCount,
            'avg_score'           => $avgScore,
            'unreadMessages'      => $unreadMessages,
            'recent_reports'      => $recentReports,
            'recommended_tutors'  => $recommendedTutors
        ];

        $this->view('parent/dashboard', $data);
    }

    /**
     * Page de recherche et d'affichage des répétiteurs
     * Route : /parent/find_tutor
     */
    public function find_tutor() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        $stmtUser = $db->prepare("SELECT id, nom, prenom, email, photo_profil, is_premium, role FROM utilisateurs WHERE id = ?");
        $stmtUser->execute([$parentId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $isParentPremium = (bool)($user['is_premium'] ?? false);

        $queryStr  = trim($_GET['query'] ?? '');
        $sectionId = (int)($_GET['section_id'] ?? 0);
        $matiereId = (int)($_GET['matiere_id'] ?? 0);
        $classeId  = (int)($_GET['classe_id'] ?? 0);

        $sql = "SELECT 
                    u.id AS utilisateur_id,
                    u.nom,
                    u.prenom,
                    u.email,
                    u.photo_profil,
                    u.is_premium,
                    r.cv_url,
                    r.portfolio_url,
                    r.telephone,
                    r.whatsapp,
                    r.facebook,
                    r.linkedin,
                    r.instagram,
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

        if (!empty($queryStr)) {
            $sql .= " AND (u.nom LIKE ? OR u.prenom LIKE ?)";
            $params[] = '%' . $queryStr . '%';
            $params[] = '%' . $queryStr . '%';
        }
        if ($sectionId > 0) {
            $sql .= " AND rs.section_id = ?";
            $params[] = $sectionId;
        }
        if ($matiereId > 0) {
            $sql .= " AND rm.matiere_id = ?";
            $params[] = $matiereId;
        }
        if ($classeId > 0) {
            $sql .= " AND rc.classe_id = ?";
            $params[] = $classeId;
        }

        $sql .= " GROUP BY u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium, 
                           r.utilisateur_id, r.cv_url, r.portfolio_url, r.telephone, r.whatsapp, 
                           r.facebook, r.linkedin, r.instagram, r.note_moyenne, r.recommande_admin";

        if ($isParentPremium) {
            $sql .= " ORDER BY r.recommande_admin DESC, u.is_premium DESC, r.note_moyenne DESC, total_avis DESC";
        } else {
            $sql .= " ORDER BY r.note_moyenne DESC, total_avis DESC, u.is_premium DESC";
        }

        $stmtTutors = $db->prepare($sql);
        $stmtTutors->execute($params);
        $tutors = $stmtTutors->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtChildren = $db->prepare("
            SELECT u.id AS utilisateur_id, u.nom, u.prenom, e.classe
            FROM enfants e
            JOIN utilisateurs u ON e.utilisateur_id = u.id
            WHERE e.parent_id = ?
        ");
        $stmtChildren->execute([$parentId]);
        $children = $stmtChildren->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sections = $db->query("SELECT id, nom FROM sections ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $matieres = $db->query("SELECT id, nom FROM matieres ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $classes  = $db->query("SELECT id, nom FROM classes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtMsg = $db->prepare("SELECT COUNT(*) AS unread FROM messages WHERE destinataire_id = ? AND est_lu = 0");
        $stmtMsg->execute([$parentId]);
        $unreadMessages = (int) ($stmtMsg->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);

        $data = [
            'titre'          => 'Trouver un Répétiteur - EDUVORA',
            'user'           => $user,
            'tutors'         => $tutors,
            'children'       => $children,
            'sections'       => $sections,
            'matieres'       => $matieres,
            'classes'        => $classes,
            'unreadMessages' => $unreadMessages
        ];

        $this->view('parent/find_tutor', $data);
    }

    /**
     * Page d'évaluation, recommandation entre parents et classement hiérarchisé
     * Route : /parent/tutors
     */
    public function tutors() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        // 1. Soumission d'un avis
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
            $repetiteurId = (int)($_POST['repetiteur_id'] ?? 0);
            $note         = (int)($_POST['note'] ?? 5);
            $commentaire  = trim($_POST['commentaire'] ?? '');

            if ($repetiteurId > 0 && $note >= 1 && $note <= 5) {
                try {
                    $stmtAvis = $db->prepare("INSERT INTO avis (repetiteur_id, auteur_id, note, commentaire, date_creation) VALUES (?, ?, ?, ?, NOW())");
                    $stmtAvis->execute([$repetiteurId, $parentId, $note, $commentaire]);

                    $stmtAvg = $db->prepare("SELECT AVG(note) AS avg_note FROM avis WHERE repetiteur_id = ?");
                    $stmtAvg->execute([$repetiteurId]);
                    $res = $stmtAvg->fetch(PDO::FETCH_ASSOC);
                    $newAvg = $res['avg_note'] ? round((float)$res['avg_note'], 2) : $note;

                    $stmtUpdate = $db->prepare("UPDATE repetiteurs SET note_moyenne = ? WHERE utilisateur_id = ?");
                    $stmtUpdate->execute([$newAvg, $repetiteurId]);

                    $_SESSION['flash_success'] = "Votre avis a été enregistré avec succès !";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de votre avis.";
                }

                header('Location: ' . BASE_URL . 'parent/tutors');
                exit();
            } else {
                $_SESSION['flash_error'] = "Veuillez fournir une note valide.";
            }
        }

        // 2. Recommandation à un autre parent
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_recommendation'])) {
            $repetiteurId   = (int)($_POST['repetiteur_id'] ?? 0);
            $targetParentId = (int)($_POST['parent_id'] ?? 0);
            $noteParent     = trim($_POST['note_parent'] ?? '');

            if ($repetiteurId > 0 && $targetParentId > 0 && $targetParentId !== $parentId) {
                try {
                    try {
                        $stmtRec = $db->prepare("INSERT INTO recommandations_parents (repetiteur_id, parent_expediteur_id, parent_destinataire_id, note, date_creation) VALUES (?, ?, ?, ?, NOW())");
                        $stmtRec->execute([$repetiteurId, $parentId, $targetParentId, $noteParent]);
                    } catch (PDOException $ex) {
                        $stmtMsg = $db->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi, est_lu) VALUES (?, ?, ?, NOW(), 0)");
                        $contenuMessage = "Recommandation de répétiteur : Un parent vous recommande un répétiteur. " . ($noteParent ? "Note : " . $noteParent : "");
                        $stmtMsg->execute([$parentId, $targetParentId, $contenuMessage]);
                    }

                    $_SESSION['flash_success'] = "Votre recommandation a bien été transmise au parent !";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Erreur lors de l'envoi de la recommandation.";
                }

                header('Location: ' . BASE_URL . 'parent/tutors');
                exit();
            } else {
                $_SESSION['flash_error'] = "Veuillez sélectionner un parent destinataire valide.";
            }
        }

        // Profil du parent
        $user = null;
        try {
            $stmtUser = $db->prepare("SELECT id, nom, prenom, email, photo_profil, is_premium, role FROM utilisateurs WHERE id = ?");
            $stmtUser->execute([$parentId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? null;
        }

        // Liste des autres parents
        $otherParents = [];
        try {
            $stmtParents = $db->prepare("SELECT id, nom, prenom, email FROM utilisateurs WHERE role = 'parent' AND id != ? ORDER BY prenom ASC, nom ASC");
            $stmtParents->execute([$parentId]);
            $otherParents = $stmtParents->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $otherParents = [];
        }

        // Classement : Assignés -> Premium & Recommandé Admin -> Premium -> Recommandé Admin -> Autres (triés par note d'étoiles)
        $tutors = [];
        try {
            $sql = "
                SELECT 
                    u.id AS utilisateur_id, 
                    u.nom, 
                    u.prenom, 
                    u.photo_profil, 
                    u.is_premium,
                    r.note_moyenne, 
                    r.recommande_admin,
                    COUNT(DISTINCT ta.id) AS est_assigne,
                    (SELECT COUNT(*) FROM avis WHERE repetiteur_id = u.id) AS total_avis
                FROM utilisateurs u
                INNER JOIN repetiteurs r ON u.id = r.utilisateur_id
                LEFT JOIN tutorats_actifs ta ON ta.repetiteur_id = r.utilisateur_id 
                    AND ta.parent_id = :parentId 
                    AND ta.statut = 'en_cours'
                WHERE u.role = 'tutor'
                GROUP BY u.id, u.nom, u.prenom, u.photo_profil, u.is_premium, r.note_moyenne, r.recommande_admin
                ORDER BY 
                    est_assigne DESC,
                    (u.is_premium = 1 AND r.recommande_admin = 1) DESC,
                    u.is_premium DESC,
                    r.recommande_admin DESC,
                    r.note_moyenne DESC,
                    u.nom ASC
            ";
            $stmtTutors = $db->prepare($sql);
            $stmtTutors->execute([':parentId' => $parentId]);
            $tutors = $stmtTutors->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $tutors = [];
        }

        // Messages non lus
        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) AS unread FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$parentId]);
            $unreadMessages = (int) ($stmtMsg->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $data = [
            'titre'          => 'Évaluer & Recommander un Répétiteur - EDUVORA',
            'user'           => $user,
            'tutors'         => $tutors,
            'parents'        => $otherParents,
            'unreadMessages' => $unreadMessages
        ];

        $this->view('parent/tutors', $data);
    }

    /**
     * Traitement de l'assignation d'un tuteur à un enfant
     */
    public function assign_tutor() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'parent/find_tutor');
            exit();
        }

        $repetiteurId = (int)($_POST['repetiteur_id'] ?? 0);
        $enfantId     = (int)($_POST['enfant_id'] ?? 0);

        if ($repetiteurId <= 0 || $enfantId <= 0) {
            $_SESSION['flash_error'] = "Données d'assignation invalides.";
            header('Location: ' . BASE_URL . 'parent/find_tutor');
            exit();
        }

        $db = Database::getConnection();

        try {
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) FROM tutorats_actifs 
                WHERE repetiteur_id = ? AND enfant_id = ? AND parent_id = ? AND statut = 'en_cours'
            ");
            $stmtCheck->execute([$repetiteurId, $enfantId, $parentId]);

            if ($stmtCheck->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "Ce répétiteur suit déjà cet enfant actuellement.";
            } else {
                $stmtInsert = $db->prepare("
                    INSERT INTO tutorats_actifs (repetiteur_id, enfant_id, parent_id, statut) 
                    VALUES (?, ?, ?, 'en_cours')
                ");
                $stmtInsert->execute([$repetiteurId, $enfantId, $parentId]);
                $_SESSION['flash_success'] = "Le répétiteur a été assigné à votre enfant avec succès !";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Erreur lors de l'assignation du répétiteur.";
        }

        header('Location: ' . BASE_URL . 'parent/find_tutor');
        exit();
    }

    /**
     * Page de gestion des enfants
     */
    public function add_child() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nom         = trim($_POST['nom'] ?? '');
            $prenom      = trim($_POST['prenom'] ?? '');
            $classe      = trim($_POST['classe'] ?? '');
            $identifiant = trim($_POST['identifiant_connexion'] ?? '');
            $password    = $_POST['password'] ?? '';

            if (empty($nom) || empty($prenom) || empty($classe) || empty($identifiant) || empty($password)) {
                $_SESSION['flash_error'] = "Tous les champs obligatoires doivent être remplis.";
            } else {
                try {
                    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM enfants WHERE identifiant_connexion = ?");
                    $stmtCheck->execute([$identifiant]);
                    
                    if ($stmtCheck->fetchColumn() > 0) {
                        $_SESSION['flash_error'] = "L'identifiant '$identifiant' est déjà utilisé par un autre élève.";
                    } else {
                        $db->beginTransaction();

                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmtUser = $db->prepare("
                            INSERT INTO utilisateurs (nom, prenom, email, password, role, photo_profil) 
                            VALUES (?, ?, NULL, ?, 'child', 'default.png')
                        ");
                        $stmtUser->execute([$nom, $prenom, $hashedPassword]);
                        $childId = $db->lastInsertId();

                        $stmtChild = $db->prepare("
                            INSERT INTO enfants (utilisateur_id, parent_id, classe, identifiant_connexion) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmtChild->execute([$childId, $parentId, $classe, $identifiant]);

                        $db->commit();
                        $_SESSION['flash_success'] = "L'enfant $prenom $nom a été ajouté avec succès !";

                        header('Location: ' . BASE_URL . 'parent/add_child');
                        exit();
                    }
                } catch (PDOException $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de l'enfant dans la base de données.";
                }
            }
        }

        $children = [];
        try {
            $stmtChildren = $db->prepare("
                SELECT u.id AS utilisateur_id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion,
                       COALESCE(AVG(p.score_obtenu), 0) AS progression_pct
                FROM enfants e
                JOIN utilisateurs u ON e.utilisateur_id = u.id
                LEFT JOIN progression_enfants p ON p.enfant_id = u.id
                WHERE e.parent_id = ?
                GROUP BY u.id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion
                ORDER BY u.id DESC
            ");
            $stmtChildren->execute([$parentId]);
            $children = $stmtChildren->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $children = [];
        }

        $stmtUser = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
        $stmtUser->execute([$parentId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $data = [
            'titre'    => 'Gestion des Enfants - EDUVORA',
            'user'     => $user,
            'children' => $children
        ];

        $this->view('parent/add_child', $data);
    }

    /**
     * Modification d'un enfant
     */
    public function edit_child() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'parent/add_child');
            exit();
        }

        $childId     = (int) ($_POST['child_id'] ?? 0);
        $nom         = trim($_POST['nom'] ?? '');
        $prenom      = trim($_POST['prenom'] ?? '');
        $classe      = trim($_POST['classe'] ?? '');
        $identifiant = trim($_POST['identifiant_connexion'] ?? '');
        $newPassword = $_POST['password'] ?? '';

        if ($childId <= 0 || empty($nom) || empty($prenom) || empty($classe) || empty($identifiant)) {
            $_SESSION['flash_error'] = "Veuillez remplir tous les champs obligatoires.";
            header('Location: ' . BASE_URL . 'parent/add_child');
            exit();
        }

        $db = Database::getConnection();

        try {
            $stmtCheckOwner = $db->prepare("SELECT COUNT(*) FROM enfants WHERE utilisateur_id = ? AND parent_id = ?");
            $stmtCheckOwner->execute([$childId, $parentId]);
            if ($stmtCheckOwner->fetchColumn() == 0) {
                $_SESSION['flash_error'] = "Action non autorisée.";
                header('Location: ' . BASE_URL . 'parent/add_child');
                exit();
            }

            $stmtCheckUniq = $db->prepare("SELECT COUNT(*) FROM enfants WHERE identifiant_connexion = ? AND utilisateur_id != ?");
            $stmtCheckUniq->execute([$identifiant, $childId]);
            if ($stmtCheckUniq->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "L'identifiant '$identifiant' est déjà utilisé par un autre élève.";
                header('Location: ' . BASE_URL . 'parent/add_child');
                exit();
            }

            $db->beginTransaction();

            if (!empty($newPassword)) {
                $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmtUser = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, password = ? WHERE id = ?");
                $stmtUser->execute([$nom, $prenom, $hashed, $childId]);
            } else {
                $stmtUser = $db->prepare("UPDATE utilisateurs SET nom = ?, prenom = ? WHERE id = ?");
                $stmtUser->execute([$nom, $prenom, $childId]);
            }

            $stmtChild = $db->prepare("UPDATE enfants SET classe = ?, identifiant_connexion = ? WHERE utilisateur_id = ? AND parent_id = ?");
            $stmtChild->execute([$classe, $identifiant, $childId, $parentId]);

            $db->commit();
            $_SESSION['flash_success'] = "Informations de l'enfant mises à jour.";

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = "Erreur lors de la modification de l'enfant.";
        }

        header('Location: ' . BASE_URL . 'parent/add_child');
        exit();
    }

    /**
     * Suppression d'un enfant
     */
    public function delete_child() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $childId = (int) ($_POST['child_id'] ?? $_GET['id'] ?? 0);

        if ($childId > 0) {
            $db = Database::getConnection();

            try {
                $stmtCheck = $db->prepare("SELECT COUNT(*) FROM enfants WHERE utilisateur_id = ? AND parent_id = ?");
                $stmtCheck->execute([$childId, $parentId]);

                if ($stmtCheck->fetchColumn() > 0) {
                    $stmtDelete = $db->prepare("DELETE FROM utilisateurs WHERE id = ? AND role = 'child'");
                    $stmtDelete->execute([$childId]);

                    $_SESSION['flash_success'] = "Le compte enfant a été supprimé définitivement.";
                } else {
                    $_SESSION['flash_error'] = "Vous n'avez pas l'autorisation de supprimer cet enfant.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Erreur lors de la suppression de l'enfant.";
            }
        } else {
            $_SESSION['flash_error'] = "Identifiant d'enfant invalide.";
        }

        header('Location: ' . BASE_URL . 'parent/add_child');
        exit();
    }

    /**
     * Page : Consultation du Cahier de Texte & Suivi de la Progression
     * Route : /parent/progress_tracking
     */
    public function progress_tracking() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $parentId = $this->getCurrentUserId();

        if ($parentId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        $user = null;
        try {
            $stmtUser = $db->prepare("SELECT id, nom, prenom, email, photo_profil, is_premium, role FROM utilisateurs WHERE id = ?");
            $stmtUser->execute([$parentId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? null;
        }

        $myChildren = [];
        try {
            $stmtChild = $db->prepare("
                SELECT e.utilisateur_id AS enfant_id, u.nom, u.prenom, u.photo_profil, e.classe, e.identifiant_connexion
                FROM enfants e
                JOIN utilisateurs u ON e.utilisateur_id = u.id
                WHERE e.parent_id = ?
                ORDER BY u.prenom ASC
            ");
            $stmtChild->execute([$parentId]);
            $myChildren = $stmtChild->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $myChildren = [];
        }

        $selectedChildId = (int)($_GET['enfant_id'] ?? ($myChildren[0]['enfant_id'] ?? 0));
        $selectedChild = null;

        foreach ($myChildren as $child) {
            if ((int)$child['enfant_id'] === $selectedChildId) {
                $selectedChild = $child;
                break;
            }
        }

        if (!$selectedChild && !empty($myChildren)) {
            $selectedChild = $myChildren[0];
            $selectedChildId = (int)$selectedChild['enfant_id'];
        }

        $cahierDeTexte = [];
        $progressionElearning = [];
        $kpis = [
            'total_seances'   => 0,
            'lecons_validees' => 0,
            'moyenne_quiz'    => 0
        ];

        if ($selectedChildId > 0) {
            try {
                $stmtCdt = $db->prepare("
                    SELECT cdt.id, cdt.titre_seance, cdt.rapport, cdt.date_seance, cdt.date_soumission,
                           t_u.nom AS tutor_nom, t_u.prenom AS tutor_prenom, t_u.photo_profil AS tutor_photo
                    FROM cahier_de_texte cdt
                    JOIN utilisateurs t_u ON cdt.repetiteur_id = t_u.id
                    WHERE cdt.enfant_id = ?
                    ORDER BY cdt.date_seance DESC, cdt.id DESC
                ");
                $stmtCdt->execute([$selectedChildId]);
                $cahierDeTexte = $stmtCdt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                $cahierDeTexte = [];
            }

            try {
                $stmtProg = $db->prepare("
                    SELECT pe.id, pe.score_obtenu, pe.est_valide, pe.date_validation,
                           l.titre AS lecon_titre, c.titre AS chapitre_titre
                    FROM progression_enfants pe
                    JOIN lecons l ON pe.lecon_id = l.id
                    JOIN chapitres c ON l.chapitre_id = c.id
                    WHERE pe.enfant_id = ?
                    ORDER BY pe.date_validation DESC, pe.id DESC
                ");
                $stmtProg->execute([$selectedChildId]);
                $progressionElearning = $stmtProg->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                $progressionElearning = [];
            }

            $kpis['total_seances'] = count($cahierDeTexte);
            
            $totalScore = 0;
            $countEvaluated = 0;
            $validees = 0;

            foreach ($progressionElearning as $prog) {
                if (!empty($prog['est_valide'])) {
                    $validees++;
                }
                if ($prog['score_obtenu'] !== null) {
                    $totalScore += (float)$prog['score_obtenu'];
                    $countEvaluated++;
                }
            }

            $kpis['lecons_validees'] = $validees;
            $kpis['moyenne_quiz']    = $countEvaluated > 0 ? round($totalScore / $countEvaluated, 1) : 0;
        }

        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) AS unread FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$parentId]);
            $unreadMessages = (int) ($stmtMsg->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $data = [
            'titre'                => 'Suivi & Rapport des Enfants - EDUVORA',
            'user'                 => $user,
            'myChildren'           => $myChildren,
            'selectedChild'        => $selectedChild,
            'cahierDeTexte'        => $cahierDeTexte,
            'progressionElearning' => $progressionElearning,
            'kpis'                 => $kpis,
            'unreadMessages'       => $unreadMessages
        ];

        $this->view('parent/progress_tracking', $data);
    }

    /**
     * Mise à jour de la photo de profil du parent
     */
    public function update_photo() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $this->getCurrentUserId();

        if ($userId <= 0) {
            $_SESSION['flash_error'] = "Session invalide ou expirée.";
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_profil'])) {
            $file = $_FILES['photo_profil'];

            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

                if (in_array($ext, $allowed) && in_array($mimeType, $allowedMimes)) {
                    $newName = 'parent_' . $userId . '_' . time() . '.' . $ext;
                    
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $targetDir = $rootDir . '/public/uploads/profiles/';

                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }

                    $targetPath = $targetDir . $newName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        @chmod($targetPath, 0644);

                        $db = Database::getConnection();
                        $stmt = $db->prepare("UPDATE utilisateurs SET photo_profil = ? WHERE id = ?");
                        $stmt->execute([$newName, $userId]);

                        $_SESSION['user_photo'] = $newName;
                        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                            $_SESSION['user']['photo_profil'] = $newName;
                        }

                        $_SESSION['flash_success'] = "Photo de profil mise à jour avec succès.";
                    } else {
                        $_SESSION['flash_error'] = "Erreur lors du transfert du fichier.";
                    }
                } else {
                    $_SESSION['flash_error'] = "Format d'image non valide (JPG, PNG, WEBP acceptés).";
                }
            } else {
                $_SESSION['flash_error'] = "Erreur d'upload : " . $file['error'];
            }
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . 'parent/dashboard');
        header('Location: ' . $referer);
        exit();
    }

    /** Aliases de compatibilité camelCase */
    public function addChild() { $this->add_child(); }
    public function editChild() { $this->edit_child(); }
    public function deleteChild() { $this->delete_child(); }
    public function updatePhoto() { $this->update_photo(); }
    public function updateProfilePhoto() { $this->update_photo(); }
    public function findTutor() { $this->find_tutor(); }
    public function assignTutor() { $this->assign_tutor(); }
    public function progressTracking() { $this->progress_tracking(); }

    /** Routes secondaires */
    public function children() { $this->add_child(); }
    public function reports() { $this->progress_tracking(); }
    public function progress() { $this->progress_tracking(); }

    /**
     * Helper privé pour récupérer l'ID utilisateur
     */
    private function getCurrentUserId(): int {
        return (int) ($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['id'] ?? 0);
    }
}