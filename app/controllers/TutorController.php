<?php
class TutorController extends Controller {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (method_exists($this, 'requireRole')) {
            $this->requireRole('tutor');
        } else {
            $role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
            if ($role !== 'tutor') {
                header('Location: ' . BASE_URL . 'auth/login');
                exit();
            }
        }
    }

    public function index() {
        $this->dashboard();
    }

    public function dashboard() {
        $db = Database::getConnection();
        $tutorUserId = $this->getCurrentUserId();
        $this->ensureTutorProfileExists($db, $tutorUserId);

        $tutorProfile = [];
        try {
            $stmtUser = $db->prepare("
                SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium, u.date_creation,
                       r.cv_url, r.portfolio_url, r.telephone, r.whatsapp, r.facebook, r.linkedin, r.instagram,
                       r.note_moyenne, r.recommande_admin
                FROM utilisateurs u
                LEFT JOIN repetiteurs r ON r.utilisateur_id = u.id
                WHERE u.id = ?");
            $stmtUser->execute([$tutorUserId]);
            $tutorProfile = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));

        $activeStudentsCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM tutorats_actifs WHERE repetiteur_id IN ($inPlaceholders) AND statut = 'en_cours'");
            $stmt->execute($tutorIds);
            $activeStudentsCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        $publishedResourcesCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM ressources WHERE auteur_id = ?");
            $stmt->execute([$tutorUserId]);
            $publishedResourcesCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        $chaptersCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM chapitres WHERE repetiteur_id IN ($inPlaceholders)");
            $stmt->execute($tutorIds);
            $chaptersCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        $unreadSosCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND type_message = 'sos' AND est_lu = FALSE");
            $stmt->execute([$tutorUserId]);
            $unreadSosCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        $unreadStandardCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND type_message = 'standard' AND est_lu = FALSE");
            $stmt->execute([$tutorUserId]);
            $unreadStandardCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        // Somme globale des messages non lus (SOS + Standard)
        $unreadMessages = $unreadSosCount + $unreadStandardCount;

        $totalAvisCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM avis WHERE repetiteur_id IN ($inPlaceholders)");
            $stmt->execute($tutorIds);
            $totalAvisCount = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        $myStudents = [];
        try {
            $stmtStudents = $db->prepare("
                SELECT ta.id AS tutorat_id,
                       e_u.id AS enfant_id,
                       CONCAT(e_u.prenom, ' ', e_u.nom) AS student_name,
                       e_u.photo_profil AS student_photo,
                       e.classe AS class_name,
                       p_u.id AS parent_id,
                       CONCAT(p_u.prenom, ' ', p_u.nom) AS parent_name,
                       p_u.email AS parent_email
                FROM tutorats_actifs ta
                JOIN utilisateurs e_u ON ta.enfant_id = e_u.id
                LEFT JOIN enfants e ON e.utilisateur_id = e_u.id
                JOIN utilisateurs p_u ON ta.parent_id = p_u.id
                WHERE ta.repetiteur_id IN ($inPlaceholders) AND ta.statut = 'en_cours'
                ORDER BY ta.id DESC");
            $stmtStudents->execute($tutorIds);
            $myStudents = $stmtStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $dernierCompteRendu = null;
        try {
            $stmtCR = $db->prepare("
                SELECT cdt.id, cdt.titre_seance, cdt.rapport, cdt.date_seance,
                       e_u.photo_profil AS student_photo,
                       CONCAT(e_u.prenom, ' ', e_u.nom) AS student_name
                FROM cahier_de_texte cdt
                JOIN utilisateurs e_u ON cdt.enfant_id = e_u.id
                WHERE cdt.repetiteur_id IN ($inPlaceholders)
                ORDER BY cdt.date_seance DESC, cdt.id DESC
                LIMIT 1");
            $stmtCR->execute($tutorIds);
            $dernierCompteRendu = $stmtCR->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {}

        $recentReports = [];
        try {
            $stmtReports = $db->prepare("
                SELECT cdt.id, cdt.titre_seance, cdt.rapport, cdt.date_seance, cdt.date_soumission,
                       e_u.nom AS enfant_nom, e_u.prenom AS enfant_prenom
                FROM cahier_de_texte cdt
                JOIN utilisateurs e_u ON cdt.enfant_id = e_u.id
                WHERE cdt.repetiteur_id IN ($inPlaceholders)
                ORDER BY cdt.date_seance DESC, cdt.id DESC
                LIMIT 5");
            $stmtReports->execute($tutorIds);
            $recentReports = $stmtReports->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $recentResources = [];
        try {
            $stmtRecentRes = $db->prepare("
                SELECT r.id, r.titre, r.type, r.format_fichier, r.lien_ressource, r.date_publication,
                       m.nom AS matiere_nom, c.nom AS classe_nom, s.nom AS section_nom
                FROM ressources r
                LEFT JOIN matieres m ON r.matiere_id = m.id
                LEFT JOIN classes c ON r.classe_id = c.id
                LEFT JOIN sections s ON r.section_id = s.id
                WHERE r.auteur_id = ?
                ORDER BY r.id DESC
                LIMIT 5");
            $stmtRecentRes->execute([$tutorUserId]);
            $recentResources = $stmtRecentRes->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        // --- 1. CHARGEMENT DE TOUTES LES PUBLICATIONS VIA LE MODÈLE COURSE ---
        $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
        require_once $rootDir . '/app/models/Course.php';
        $courseModel = new Course();
        $mesPublications = $courseModel->getByAuteur($tutorUserId);

        // Nombre total d'éléments (calculé sur le tableau complet)
        $totalPublications = !empty($mesPublications) ? count($mesPublications) : $publishedResourcesCount;

        // Limiter la liste affichée dans le tableau du tableau de bord aux 5 derniers éléments
        $mesPublicationsAffichees = array_slice($mesPublications, 0, 5);

        // --- 2. RÉCUPÉRATION DES AVIS ET DU DERNIER AVIS REÇU ---
        $recentAvis = [];
        $dernierAvis = null;
        try {
            $stmtRecentAvis = $db->prepare("
                SELECT a.id, a.note, a.commentaire, 
                       a.date_creation AS date_avis,
                       CONCAT(u.prenom, ' ', u.nom) AS author_name,
                       u.nom AS auteur_nom, u.prenom AS auteur_prenom, 
                       u.role AS auteur_role, 
                       u.photo_profil AS author_photo,
                       u.photo_profil AS auteur_photo
                FROM avis a
                JOIN utilisateurs u ON a.auteur_id = u.id
                WHERE a.repetiteur_id IN ($inPlaceholders)
                ORDER BY a.id DESC
                LIMIT 5");
            $stmtRecentAvis->execute($tutorIds);
            $recentAvis = $stmtRecentAvis->fetchAll(PDO::FETCH_ASSOC) ?: [];
            
            // Extraction du plus récent si la liste n'est pas vide
            if (!empty($recentAvis)) {
                $dernierAvis = $recentAvis[0];
            }
        } catch (PDOException $e) {}

        $data = [
            'titre'                   => 'Espace Répétiteur - EDUVORA',
            'user'                    => $tutorProfile,
            'activeStudentsCount'     => $activeStudentsCount,
            'totalElevesSuivis'       => $activeStudentsCount,
            'publishedResourcesCount' => $publishedResourcesCount,
            'totalPublications'       => $totalPublications,
            'chaptersCount'           => $chaptersCount,
            'unreadSosCount'          => $unreadSosCount,
            'unreadStandardCount'     => $unreadStandardCount,
            'unreadMessages'          => $unreadMessages,
            'totalAvisCount'          => $totalAvisCount,
            'myStudents'              => $myStudents,
            'elevesSuivisList'        => $myStudents,
            'dernierCompteRendu'      => $dernierCompteRendu,
            'recentReports'           => $recentReports,
            'recentResources'         => $recentResources,
            'mesPublications'         => $mesPublicationsAffichees,
            'recentAvis'              => $recentAvis,
            'dernierAvis'             => $dernierAvis
        ];

        $this->view('tutor/dashboard', $data);
    }

    public function profile_edit() {
        $db = Database::getConnection();
        $tutorUserId = $this->getCurrentUserId();
        $this->ensureTutorProfileExists($db, $tutorUserId);

        $stmtUser = $db->prepare("
            SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.is_premium,
                   r.cv_url, r.portfolio_url, r.telephone, r.whatsapp, r.facebook, r.linkedin, r.instagram,
                   r.note_moyenne, r.recommande_admin
            FROM utilisateurs u
            LEFT JOIN repetiteurs r ON r.utilisateur_id = u.id
            WHERE u.id = ?");
        $stmtUser->execute([$tutorUserId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

        $allSections = $db->query("SELECT * FROM sections ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allMatieres = $db->query("SELECT * FROM matieres ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allClasses  = $db->query("SELECT * FROM classes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));

        $stmtSec = $db->prepare("SELECT section_id FROM repetiteur_sections WHERE repetiteur_id IN ($inPlaceholders)");
        $stmtSec->execute($tutorIds);
        $selectedSections = $stmtSec->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmtMat = $db->prepare("SELECT matiere_id FROM repetiteur_matieres WHERE repetiteur_id IN ($inPlaceholders)");
        $stmtMat->execute($tutorIds);
        $selectedMatieres = $stmtMat->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmtCls = $db->prepare("SELECT classe_id FROM repetiteur_classes WHERE repetiteur_id IN ($inPlaceholders)");
        $stmtCls->execute($tutorIds);
        $selectedClasses = $stmtCls->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmtGal = $db->prepare("SELECT * FROM galeries WHERE utilisateur_id = ? ORDER BY id DESC");
        $stmtGal->execute([$tutorUserId]);
        $gallery = $stmtGal->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $data = [
            'titre'            => 'Éditer mon Profil & Galerie - EDUVORA',
            'user'             => $user,
            'allSections'      => $allSections,
            'allMatieres'      => $allMatieres,
            'allClasses'       => $allClasses,
            'selectedSections' => $selectedSections,
            'selectedMatieres' => $selectedMatieres,
            'selectedClasses'  => $selectedClasses,
            'gallery'          => $gallery
        ];

        $this->view('tutor/profile_edit', $data);
    }

    public function updateProfile() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/profile_edit');
        }

        $db = Database::getConnection();
        $this->ensureTutorProfileExists($db, $tutorUserId);

        $stmtCurrent = $db->prepare("SELECT cv_url FROM repetiteurs WHERE utilisateur_id = ?");
        $stmtCurrent->execute([$tutorUserId]);
        $currentCv = $stmtCurrent->fetchColumn() ?: null;

        $telephone    = trim($_POST['telephone'] ?? '');
        $whatsapp     = trim($_POST['whatsapp'] ?? '');
        $portfolioUrl = filter_var(trim($_POST['portfolio_url'] ?? ''), FILTER_SANITIZE_URL);
        $facebook     = filter_var(trim($_POST['facebook'] ?? ''), FILTER_SANITIZE_URL);
        $linkedin     = filter_var(trim($_POST['linkedin'] ?? ''), FILTER_SANITIZE_URL);
        $instagram    = filter_var(trim($_POST['instagram'] ?? ''), FILTER_SANITIZE_URL);
        $cvFilename   = $currentCv;

        if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['cv_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $newName = 'cv_tutor_' . $tutorUserId . '_' . time() . '.pdf';
                $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                $targetDir = $rootDir . '/public/uploads/cvs/';
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                $targetPath = $targetDir . $newName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    @chmod($targetPath, 0666);
                    if (!empty($currentCv) && file_exists($targetDir . $currentCv)) {
                        @unlink($targetDir . $currentCv);
                    }
                    $cvFilename = $newName;
                } else {
                    $_SESSION['flash_error'] = "Erreur lors du transfert du fichier CV.";
                }
            } else {
                $_SESSION['flash_error'] = "Le CV doit être exclusivement au format PDF.";
            }
        }

        $stmtUpdate = $db->prepare("
            UPDATE repetiteurs 
            SET cv_url = ?, portfolio_url = ?, telephone = ?, whatsapp = ?, facebook = ?, linkedin = ?, instagram = ? 
            WHERE utilisateur_id = ?");
        $stmtUpdate->execute([$cvFilename, $portfolioUrl, $telephone, $whatsapp, $facebook, $linkedin, $instagram, $tutorUserId]);

        $sections = $_POST['sections'] ?? [];
        $matieres = $_POST['matieres'] ?? [];
        $classes  = $_POST['classes'] ?? [];

        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $targetRepetiteurId = $tutorIds[1] ?? $tutorUserId;

        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM repetiteur_sections WHERE repetiteur_id = ?")->execute([$targetRepetiteurId]);
            if (!empty($sections)) {
                $insSec = $db->prepare("INSERT INTO repetiteur_sections (repetiteur_id, section_id) VALUES (?, ?)");
                foreach ($sections as $secId) { $insSec->execute([$targetRepetiteurId, (int)$secId]); }
            }

            $db->prepare("DELETE FROM repetiteur_matieres WHERE repetiteur_id = ?")->execute([$targetRepetiteurId]);
            if (!empty($matieres)) {
                $insMat = $db->prepare("INSERT INTO repetiteur_matieres (repetiteur_id, matiere_id) VALUES (?, ?)");
                foreach ($matieres as $matId) { $insMat->execute([$targetRepetiteurId, (int)$matId]); }
            }

            $db->prepare("DELETE FROM repetiteur_classes WHERE repetiteur_id = ?")->execute([$targetRepetiteurId]);
            if (!empty($classes)) {
                $insCls = $db->prepare("INSERT INTO repetiteur_classes (repetiteur_id, classe_id) VALUES (?, ?)");
                foreach ($classes as $clsId) { $insCls->execute([$targetRepetiteurId, (int)$clsId]); }
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['flash_error'] = "Une erreur est survenue lors de l'enregistrement de vos options.";
        }

        if (!isset($_SESSION['flash_error'])) {
            $_SESSION['flash_success'] = "Profil et compétences mis à jour avec succès.";
        }
        $this->redirectBack('tutor/profile_edit');
    }

    public function uploadGallery() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/profile_edit');
        }

        if (isset($_FILES['gallery_photo']) && $_FILES['gallery_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['gallery_photo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed)) {
                $newName = 'gallery_' . $tutorUserId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                $targetDir = $rootDir . '/public/uploads/galleries/';
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                $targetPath = $targetDir . $newName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    @chmod($targetPath, 0666);
                    $db = Database::getConnection();
                    $stmt = $db->prepare("INSERT INTO galeries (utilisateur_id, image_url) VALUES (?, ?)");
                    $stmt->execute([$tutorUserId, $newName]);
                    $_SESSION['flash_success'] = "Photo ajoutée à la galerie avec succès.";
                } else {
                    $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de la photo sur le serveur.";
                }
            } else {
                $_SESSION['flash_error'] = "Format non supporté (JPG, PNG, WEBP autorisés).";
            }
        } else {
            $_SESSION['flash_error'] = "Veuillez sélectionner un fichier image valide.";
        }
        $this->redirectBack('tutor/profile_edit');
    }

    public function deleteGalleryPhoto() {
        $tutorUserId = $this->getCurrentUserId();
        $photoId = (int)($_GET['id'] ?? 0);

        if ($tutorUserId > 0 && $photoId > 0) {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT image_url FROM galeries WHERE id = ? AND utilisateur_id = ?");
            $stmt->execute([$photoId, $tutorUserId]);
            $photo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($photo) {
                $stmtDel = $db->prepare("DELETE FROM galeries WHERE id = ? AND utilisateur_id = ?");
                if ($stmtDel->execute([$photoId, $tutorUserId])) {
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $filePath = $rootDir . '/public/uploads/galleries/' . $photo['image_url'];
                    if (file_exists($filePath)) { @unlink($filePath); }
                    $_SESSION['flash_success'] = "Photo supprimée de la galerie.";
                }
            } else {
                $_SESSION['flash_error'] = "Impossible de trouver ou supprimer cette photo.";
            }
        }
        $this->redirectBack('tutor/profile_edit');
    }

    public function updateProfilePhoto() {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            $_SESSION['flash_error'] = "Session invalide ou expirée.";
            $this->redirectBack('tutor/dashboard');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_profil'])) {
            $file = $_FILES['photo_profil'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($ext, $allowed)) {
                    $newName = 'tutor_' . $userId . '_' . time() . '.' . $ext;
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $targetDir = $rootDir . '/public/uploads/profiles/';
                    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
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
                        $_SESSION['flash_error'] = "Erreur lors du transfert du fichier vers le serveur.";
                    }
                } else {
                    $_SESSION['flash_error'] = "Format non valide. Formats autorisés : JPG, PNG, WEBP.";
                }
            } else {
                $_SESSION['flash_error'] = "Erreur lors de l'envoi du fichier.";
            }
        }
        $this->redirectBack('tutor/dashboard');
    }

    public function student_report() {
    $tutorUserId = $this->getCurrentUserId();
    $db = Database::getConnection();
    $assignedStudents = [];

    $tutorIds = $this->getTutorIds($db, $tutorUserId);
    $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));

    try {
        $stmt = $db->prepare("
            SELECT 
                ta.enfant_id, 
                e_u.nom AS enfant_nom, 
                e_u.prenom AS enfant_prenom, 
                e_u.photo_profil, 
                e.classe,
                p_u.nom AS parent_nom,
                p_u.prenom AS parent_prenom
            FROM tutorats_actifs ta
            JOIN utilisateurs e_u ON ta.enfant_id = e_u.id
            LEFT JOIN enfants e ON e.utilisateur_id = e_u.id
            LEFT JOIN utilisateurs p_u ON ta.parent_id = p_u.id
            WHERE ta.repetiteur_id IN ($inPlaceholders) AND ta.statut = 'en_cours'
        ");
        $stmt->execute($tutorIds);
        $assignedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {}

    $selectedChildId = (int)($_GET['enfant_id'] ?? ($assignedStudents[0]['enfant_id'] ?? 0));
    $reports = [];
    $selectedStudentInfo = null;

    if ($selectedChildId > 0) {
        foreach ($assignedStudents as $student) {
            if ((int)$student['enfant_id'] === $selectedChildId) {
                $selectedStudentInfo = $student;
                break;
            }
        }
        try {
            $params = array_merge($tutorIds, [$selectedChildId]);
            $stmtR = $db->prepare("
                SELECT * FROM cahier_de_texte 
                WHERE repetiteur_id IN ($inPlaceholders) AND enfant_id = ? 
                ORDER BY date_seance DESC, id DESC");
            $stmtR->execute($params);
            $reports = $stmtR->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    $data = [
        'titre'               => 'Cahier de Texte - EDUVORA',
        'assignedStudents'    => $assignedStudents,
        'selectedChildId'     => $selectedChildId,
        'selectedStudentInfo' => $selectedStudentInfo,
        'reports'             => $reports
    ];
    $this->view('tutor/student_report', $data);
}

    public function saveReport() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/student_report');
        }

        $childId     = (int)($_POST['enfant_id'] ?? 0);
        $titreSeance = trim($_POST['titre_seance'] ?? '');
        $rapport     = trim($_POST['rapport'] ?? '');
        $dateSeance  = trim($_POST['date_seance'] ?? '');

        if ($childId <= 0 || empty($titreSeance) || empty($rapport) || empty($dateSeance)) {
            $_SESSION['flash_error'] = "Tous les champs du formulaire sont obligatoires.";
            header('Location: ' . BASE_URL . 'tutor/student_report?enfant_id=' . $childId);
            exit();
        }

        $db = Database::getConnection();
        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $targetRepId = $tutorIds[1] ?? $tutorUserId;

        try {
            $stmt = $db->prepare("
                INSERT INTO cahier_de_texte (repetiteur_id, enfant_id, titre_seance, rapport, date_seance, date_soumission) 
                VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$targetRepId, $childId, $titreSeance, $rapport, $dateSeance]);
            $_SESSION['flash_success'] = "Séance enregistrée avec succès dans le cahier de texte.";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de la séance.";
        }

        header('Location: ' . BASE_URL . 'tutor/student_report?enfant_id=' . $childId);
        exit();
    }

    public function deleteReport() {
        $tutorUserId = $this->getCurrentUserId();
        $reportId    = (int)($_GET['id'] ?? 0);
        $childId     = (int)($_GET['enfant_id'] ?? 0);

        if ($tutorUserId > 0 && $reportId > 0) {
            $db = Database::getConnection();
            $tutorIds = $this->getTutorIds($db, $tutorUserId);
            $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));
            $params = array_merge([$reportId], $tutorIds);

            try {
                $stmt = $db->prepare("DELETE FROM cahier_de_texte WHERE id = ? AND repetiteur_id IN ($inPlaceholders)");
                $stmt->execute($params);
                $_SESSION['flash_success'] = "Compte-rendu supprimé.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Impossible de supprimer ce compte-rendu.";
            }
        }
        header('Location: ' . BASE_URL . 'tutor/student_report?enfant_id=' . $childId);
        exit();
    }

    // =========================================================================
    // SECTION E-LEARNING & RESSOURCES
    // =========================================================================

    public function course_builder() {
        $tutorUserId = $this->getCurrentUserId();
        $db = Database::getConnection();

        $allSections = $db->query("SELECT * FROM sections ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allMatieres = $db->query("SELECT * FROM matieres ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allClasses  = $db->query("SELECT * FROM classes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ressources = [];
        try {
            $stmtR = $db->prepare("
                SELECT r.*, s.nom AS section_nom, m.nom AS matiere_nom, c.nom AS classe_nom
                FROM ressources r
                LEFT JOIN sections s ON r.section_id = s.id
                LEFT JOIN matieres m ON r.matiere_id = m.id
                LEFT JOIN classes c ON r.classe_id = c.id
                WHERE r.auteur_id = ?
                ORDER BY r.date_publication DESC, r.id DESC");
            $stmtR->execute([$tutorUserId]);
            $ressources = $stmtR->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));

        $chapitres = [];
        try {
            $stmtChap = $db->prepare("
                SELECT ch.*, c.nom AS classe_nom
                FROM chapitres ch
                LEFT JOIN classes c ON ch.classe_id = c.id
                WHERE ch.repetiteur_id IN ($inPlaceholders)
                ORDER BY ch.ordre ASC, ch.id ASC");
            $stmtChap->execute($tutorIds);
            $rawChapitres = $stmtChap->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawChapitres as $chap) {
                $lecons = [];
                try {
                    $stmtLec = $db->prepare("
                        SELECT l.*, e.id AS evaluation_id, e.type_evaluation, e.eval_qna_text, e.fichier_evaluation, e.score_minimum, e.contenu_quiz
                        FROM lecons l
                        LEFT JOIN evaluations e ON l.id = e.lecon_id
                        WHERE l.chapitre_id = ?
                        ORDER BY l.ordre ASC, l.id ASC");
                    $stmtLec->execute([$chap['id']]);
                    $lecons = $stmtLec->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    foreach ($lecons as &$lecon) {
                        $lecon['quiz_data'] = !empty($lecon['contenu_quiz']) ? json_decode($lecon['contenu_quiz'], true) : null;
                    }
                } catch (PDOException $e) {
                    try {
                        $stmtLecSimple = $db->prepare("SELECT * FROM lecons WHERE chapitre_id = ? ORDER BY ordre ASC, id ASC");
                        $stmtLecSimple->execute([$chap['id']]);
                        $lecons = $stmtLecSimple->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (PDOException $ex) {}
                }

                $chap['lecons'] = $lecons;
                $chapitres[] = $chap;
            }
        } catch (PDOException $e) {}

        $data = [
            'titre'      => 'Gestion des Cours & Épreuves - EDUVORA',
            'allSections'=> $allSections,
            'allMatieres'=> $allMatieres,
            'allClasses' => $allClasses,
            'ressources' => $ressources,
            'chapitres'  => $chapitres
        ];
        $this->view('tutor/course_builder', $data);
    }

    public function store_resource() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/course_builder');
        }

        $titre         = trim($_POST['titre'] ?? '');
        $type          = $_POST['type'] ?? 'cours';  
        $formatFichier = $_POST['format_fichier'] ?? 'pdf';
        $sectionId     = (int)($_POST['section_id'] ?? 0);
        $matiereId     = (int)($_POST['matiere_id'] ?? 0);
        $classeId      = (int)($_POST['classe_id'] ?? 0);

        if (empty($titre)) {
            $_SESSION['flash_error'] = "Le titre de la ressource est obligatoire.";
            $this->redirectBack('tutor/course_builder');
        }

        $lienRessource = '';
        if ($formatFichier === 'youtube') {
            $lienRessource = trim($_POST['youtube_url'] ?? '');
            if (empty($lienRessource)) {
                $_SESSION['flash_error'] = "Veuillez fournir un lien vidéo YouTube valide.";
                $this->redirectBack('tutor/course_builder');
            }
        } else {
            $fileKey = isset($_FILES['file_upload']) ? 'file_upload' : (isset($_FILES['fichier']) ? 'fichier' : null);
            if ($fileKey && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$fileKey];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ($formatFichier === 'pdf') ? ['pdf'] : ['mp4', 'webm', 'ogg', 'mkv'];

                if (!in_array($ext, $allowed)) {
                    $_SESSION['flash_error'] = "Format de fichier non valide pour le type sélectionné.";
                    $this->redirectBack('tutor/course_builder');
                }

                $subFolder = ($type === 'epreuve') ? 'epreuves' : 'courses';
                $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                $targetDir = $rootDir . '/public/uploads/' . $subFolder . '/';

                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                $lienRessource = $type . '_' . $tutorUserId . '_' . time() . '.' . $ext;
                $targetPath = $targetDir . $lienRessource;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    @chmod($targetPath, 0666);
                } else {
                    $_SESSION['flash_error'] = "Erreur lors du transfert du fichier sur le serveur.";
                    $this->redirectBack('tutor/course_builder');
                }
            } else {
                $_SESSION['flash_error'] = "Veuillez joindre un fichier valide.";
                $this->redirectBack('tutor/course_builder');
            }
        }

        $db = Database::getConnection();
        try {
            $stmt = $db->prepare("
                INSERT INTO ressources (auteur_id, titre, type, format_fichier, lien_ressource, section_id, matiere_id, classe_id, date_publication)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $tutorUserId, $titre, $type, $formatFichier, $lienRessource,
                $sectionId > 0 ? $sectionId : null,
                $matiereId > 0 ? $matiereId : null,
                $classeId > 0 ? $classeId : null
            ]);
            $_SESSION['flash_success'] = "Ressource publiée avec succès !";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Erreur lors de la publication de la ressource.";
        }
        $this->redirectBack('tutor/course_builder');
    }

    public function delete_resource() {
        $tutorUserId = $this->getCurrentUserId();
        $id = (int)($_GET['id'] ?? 0);

        if ($tutorUserId > 0 && $id > 0) {
            $db = Database::getConnection();
            try {
                $stmt = $db->prepare("SELECT format_fichier, lien_ressource, type FROM ressources WHERE id = ? AND auteur_id = ?");
                $stmt->execute([$id, $tutorUserId]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($res) {
                    if (in_array($res['format_fichier'], ['pdf', 'video'])) {
                        $subFolder = ($res['type'] === 'epreuve') ? 'epreuves' : 'courses';
                        $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                        $filePath = $rootDir . '/public/uploads/' . $subFolder . '/' . $res['lien_ressource'];
                        if (file_exists($filePath)) { @unlink($filePath); }
                    }
                    $stmtDel = $db->prepare("DELETE FROM ressources WHERE id = ? AND auteur_id = ?");
                    $stmtDel->execute([$id, $tutorUserId]);
                    $_SESSION['flash_success'] = "Ressource supprimée avec succès.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Impossible de supprimer la ressource.";
            }
        }
        $this->redirectBack('tutor/course_builder');
    }

    // --- CHAPITRES ---

    public function store_chapter() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/course_builder');
        }

        $titre    = trim($_POST['titre'] ?? '');
        $classeId = (int)($_POST['classe_id'] ?? 0);
        $ordre    = (int)($_POST['ordre'] ?? 1);

        if (!empty($titre)) {
            $db = Database::getConnection();
            $tutorIds = $this->getTutorIds($db, $tutorUserId);
            $targetRepId = $tutorIds[0];

            try {
                $stmt = $db->prepare("INSERT INTO chapitres (repetiteur_id, titre, classe_id, ordre) VALUES (?, ?, ?, ?)");
                $stmt->execute([$targetRepId, $titre, $classeId > 0 ? $classeId : null, $ordre]);
                $_SESSION['flash_success'] = "Nouveau chapitre créé avec succès !";
            } catch (PDOException $e) {
                try {
                    $altRepId = $tutorIds[1] ?? $tutorUserId;
                    $stmt = $db->prepare("INSERT INTO chapitres (repetiteur_id, titre, classe_id, ordre) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$altRepId, $titre, $classeId > 0 ? $classeId : null, $ordre]);
                    $_SESSION['flash_success'] = "Nouveau chapitre créé avec succès !";
                } catch (PDOException $ex) {
                    $_SESSION['flash_error'] = "Erreur SQL lors de la création : " . $ex->getMessage();
                }
            }
        } else {
            $_SESSION['flash_error'] = "Le titre du chapitre est obligatoire.";
        }
        $this->redirectBack('tutor/course_builder');
    }

    public function delete_chapter() {
        $tutorUserId = $this->getCurrentUserId();
        $id = (int)($_GET['id'] ?? 0);

        if ($tutorUserId > 0 && $id > 0) {
            $db = Database::getConnection();
            $tutorIds = $this->getTutorIds($db, $tutorUserId);
            $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));
            $params = array_merge([$id], $tutorIds);

            try {
                $stmt = $db->prepare("SELECT id FROM chapitres WHERE id = ? AND repetiteur_id IN ($inPlaceholders)");
                $stmt->execute($params);

                if ($stmt->fetch()) {
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                    $stmtL = $db->prepare("SELECT id, fichier_lecon FROM lecons WHERE chapitre_id = ?");
                    $stmtL->execute([$id]);
                    $lecons = $stmtL->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($lecons as $lec) {
                        if (!empty($lec['fichier_lecon'])) {
                            @unlink($rootDir . '/public/uploads/lecons/' . $lec['fichier_lecon']);
                        }
                        $stmtE = $db->prepare("SELECT fichier_evaluation FROM evaluations WHERE lecon_id = ?");
                        $stmtE->execute([$lec['id']]);
                        $eval = $stmtE->fetch(PDO::FETCH_ASSOC);
                        if ($eval && !empty($eval['fichier_evaluation'])) {
                            @unlink($rootDir . '/public/uploads/evaluations/' . $eval['fichier_evaluation']);
                        }
                        $db->prepare("DELETE FROM evaluations WHERE lecon_id = ?")->execute([$lec['id']]);
                    }

                    $db->prepare("DELETE FROM lecons WHERE chapitre_id = ?")->execute([$id]);
                    $db->prepare("DELETE FROM chapitres WHERE id = ? AND repetiteur_id IN ($inPlaceholders)")->execute($params);

                    $_SESSION['flash_success'] = "Chapitre et ses leçons supprimés avec succès.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Erreur lors de la suppression du chapitre.";
            }
        }
        $this->redirectBack('tutor/course_builder');
    }

    // --- LEÇONS & ÉVALUATIONS ---

    public function store_lesson() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/course_builder');
        }

        $chapitreId  = (int)($_POST['chapitre_id'] ?? 0);
        $titre       = trim($_POST['titre'] ?? '');
        $formatLecon = $_POST['format_lecon'] ?? 'texte';
        $contenu     = trim($_POST['contenu'] ?? '');
        $youtubeUrl  = trim($_POST['lecon_youtube_url'] ?? '');
        $ordre       = (int)($_POST['ordre'] ?? 1);

        $scoreMin = (int)($_POST['score_minimum'] ?? 50);
        $typeEval = $_POST['type_evaluation'] ?? 'quiz';
        $evalQna  = trim($_POST['eval_qna_text'] ?? '');

        if ($chapitreId <= 0 || empty($titre)) {
            $_SESSION['flash_error'] = "Veuillez remplir les informations obligatoires de la leçon.";
            $this->redirectBack('tutor/course_builder');
        }

        $db = Database::getConnection();
        try {
            $fichierLecon = '';
            if (in_array($formatLecon, ['pdf', 'video']) && isset($_FILES['lecon_file']) && $_FILES['lecon_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['lecon_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'lesson_' . $tutorUserId . '_' . time() . '.' . $ext;
                $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                $targetDir = $rootDir . '/public/uploads/lecons/';

                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                if (move_uploaded_file($file['tmp_name'], $targetDir . $newName)) {
                    @chmod($targetDir . $newName, 0666);
                    $fichierLecon = $newName;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO lecons (chapitre_id, titre, format_lecon, contenu, fichier_lecon, youtube_url, ordre) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $chapitreId, $titre, $formatLecon, $contenu, $fichierLecon,
                ($formatLecon === 'youtube' ? $youtubeUrl : null), $ordre
            ]);
            $leconId = (int)$db->lastInsertId();

            $fichierEval = '';
            if ($typeEval === 'pdf' && isset($_FILES['eval_pdf_file']) && $_FILES['eval_pdf_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['eval_pdf_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'eval_' . $leconId . '_' . time() . '.' . $ext;
                $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                $targetDir = $rootDir . '/public/uploads/evaluations/';

                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                if (move_uploaded_file($file['tmp_name'], $targetDir . $newName)) {
                    @chmod($targetDir . $newName, 0666);
                    $fichierEval = $newName;
                }
            }

            $jsonQuiz = null;
            if ($typeEval === 'quiz') {
                $questions = $_POST['quiz_questions'] ?? [];
                $options   = $_POST['quiz_options'] ?? [];
                $answers   = $_POST['quiz_answers'] ?? [];
                $quizData  = [];

                if (is_array($questions)) {
                    foreach ($questions as $idx => $qText) {
                        if (!empty(trim($qText))) {
                            $quizData[] = [
                                'question' => trim($qText),
                                'options'  => $options[$idx] ?? [],
                                'answer'   => (int)($answers[$idx] ?? 0)
                            ];
                        }
                    }
                }
                if (!empty($quizData)) {
                    $jsonQuiz = json_encode($quizData, JSON_UNESCAPED_UNICODE);
                }
            }

            $stmtEval = $db->prepare("
                INSERT INTO evaluations (lecon_id, type_evaluation, contenu_quiz, eval_qna_text, fichier_evaluation, score_minimum) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmtEval->execute([
                $leconId, $typeEval, $jsonQuiz,
                ($typeEval === 'qna' ? $evalQna : null),
                $fichierEval, $scoreMin
            ]);

            $_SESSION['flash_success'] = "Leçon et évaluation enregistrées avec succès !";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de la leçon.";
        }

        $this->redirectBack('tutor/course_builder');
    }
    // ==========================================
    // GESTION DU PARCOURS E-LEARNING
    // ==========================================

    // 1. Affichage de la vue e-learning (par élève ou par classe)
    public function elearning_manager() {
        $elearningModel = $this->model('Elearning');
        $tutorId = $_SESSION['user_id'];

        $students = $elearningModel->getActiveStudentsByTutor($tutorId);
        $classes  = $elearningModel->getActiveClassesByTutor($tutorId);
        $chapters = $elearningModel->getChaptersByTutor($tutorId);

        $mode = $_GET['mode'] ?? 'student'; // 'student' ou 'class'
        $selectedStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : ($students[0]['id'] ?? 0);
        $selectedClass = $_GET['class_name'] ?? ($classes[0]['classe'] ?? '');

        $assignedChapters = [];
        if ($mode === 'student' && $selectedStudentId > 0) {
            $assignedChapters = $elearningModel->getAssignedChaptersDetailsByStudent($selectedStudentId);
        }

        $data = [
            'titre'             => 'Gestion E-Learning Élèves',
            'students'          => $students,
            'classes'           => $classes,
            'chapters'          => $chapters,
            'mode'              => $mode,
            'selectedStudentId' => $selectedStudentId,
            'selectedClass'     => $selectedClass,
            'assignedChapters'  => $assignedChapters
        ];

        $this->view('tutor/elearning_manager', $data);
    }

    // 2. Traitement des attributions / retraits avec date limite
    public function toggle_elearning() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $elearningModel = $this->model('Elearning');
            $tutorId    = $_SESSION['user_id'];
            $action     = $_POST['action'] ?? '';
            $chapterId  = (int)($_POST['chapter_id'] ?? 0);
            $dateLimite = !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;

            if ($action === 'assign_student') {
                $studentId = (int)($_POST['student_id'] ?? 0);
                if ($studentId > 0 && $chapterId > 0) {
                    $elearningModel->assignChapterToStudent($tutorId, $studentId, $chapterId, $dateLimite);
                    $_SESSION['flash_success'] = "Module attribué à l'élève avec succès.";
                }
                header('Location: ' . BASE_URL . 'tutor/elearning_manager?mode=student&student_id=' . $studentId);
                exit;

            } elseif ($action === 'assign_class') {
                $className = trim($_POST['class_name'] ?? '');
                if (!empty($className) && $chapterId > 0) {
                    $elearningModel->assignChapterToClass($tutorId, $className, $chapterId, $dateLimite);
                    $_SESSION['flash_success'] = "Module attribué à la classe " . htmlspecialchars($className) . ".";
                }
                header('Location: ' . BASE_URL . 'tutor/elearning_manager?mode=class&class_name=' . urlencode($className));
                exit;

            } elseif ($action === 'remove') {
                $studentId = (int)($_POST['student_id'] ?? 0);
                if ($studentId > 0 && $chapterId > 0) {
                    $elearningModel->removeAssignment($tutorId, $studentId, $chapterId);
                    $_SESSION['flash_success'] = "Module retiré de l'élève.";
                }
                header('Location: ' . BASE_URL . 'tutor/elearning_manager?mode=student&student_id=' . $studentId);
                exit;
            }
        }
    }

    // 3. Création instantanée d'un cours dédié à un élève spécifique
    public function create_custom_chapter() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tutorId    = $_SESSION['user_id'];
            $studentId  = (int)($_POST['student_id'] ?? 0);
            $titre      = trim($_POST['titre'] ?? '');
            $dateLimite = !empty($_POST['date_limite']) ? $_POST['date_limite'] : null;

            if ($studentId > 0 && !empty($titre)) {
                $db = Database::getInstance();
                $pdo = method_exists($db, 'getConnection') ? $db->getConnection() : $db;

                // Calcul du rang/ordre du chapitre
                $stmtOrdre = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM chapitres WHERE repetiteur_id = :tutor_id");
                $stmtOrdre->execute([':tutor_id' => $tutorId]);
                $nextOrdre = $stmtOrdre->fetchColumn();

                // Création du chapitre
                $stmtChap = $pdo->prepare("INSERT INTO chapitres (repetiteur_id, titre, ordre) VALUES (:tutor_id, :titre, :ordre)");
                $stmtChap->execute([
                    ':tutor_id' => $tutorId,
                    ':titre'    => $titre,
                    ':ordre'    => $nextOrdre
                ]);
                $chapterId = $pdo->lastInsertId();

                // Attribution exclusive à l'élève avec la date limite
                $elearningModel = $this->model('Elearning');
                $elearningModel->assignChapterToStudent($tutorId, $studentId, $chapterId, $dateLimite);

                $_SESSION['flash_success'] = "Cours créé et attribué ! Ajoutez-y maintenant des leçons et des évaluations.";

                header('Location: ' . BASE_URL . 'tutor/course_builder?chapter_id=' . $chapterId);
                exit;
            }
        }
        header('Location: ' . BASE_URL . 'tutor/elearning_manager');
        exit;
    }
    public function update_lesson() {
        $tutorUserId = $this->getCurrentUserId();
        if ($tutorUserId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('tutor/course_builder');
        }

        $lessonId    = (int)($_POST['lesson_id'] ?? 0);
        $chapitreId  = (int)($_POST['chapitre_id'] ?? 0);
        $titre       = trim($_POST['titre'] ?? '');
        $formatLecon = $_POST['format_lecon'] ?? 'texte';
        $contenu     = trim($_POST['contenu'] ?? '');
        $youtubeUrl  = trim($_POST['lecon_youtube_url'] ?? '');
        $ordre       = (int)($_POST['ordre'] ?? 1);

        $scoreMin = (int)($_POST['score_minimum'] ?? 50);
        $typeEval = $_POST['type_evaluation'] ?? 'quiz';
        $evalQna  = trim($_POST['eval_qna_text'] ?? '');

        if ($lessonId <= 0 || $chapitreId <= 0 || empty($titre)) {
            $_SESSION['flash_error'] = "Informations de modification incomplètes.";
            $this->redirectBack('tutor/course_builder');
        }

        $db = Database::getConnection();
        $tutorIds = $this->getTutorIds($db, $tutorUserId);
        $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));
        $params = array_merge([$lessonId], $tutorIds);

        try {
            $stmtCheck = $db->prepare("
                SELECT l.id, l.fichier_lecon, e.fichier_evaluation 
                FROM lecons l
                JOIN chapitres c ON l.chapitre_id = c.id
                LEFT JOIN evaluations e ON l.id = e.lecon_id
                WHERE l.id = ? AND c.repetiteur_id IN ($inPlaceholders)");
            $stmtCheck->execute($params);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $_SESSION['flash_error'] = "Leçon introuvable ou vous n'avez pas l'autorisation de la modifier.";
                $this->redirectBack('tutor/course_builder');
            }

            $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
            $fichierLecon = $existing['fichier_lecon'];

            if (in_array($formatLecon, ['pdf', 'video']) && isset($_FILES['lecon_file']) && $_FILES['lecon_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['lecon_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'lesson_' . $tutorUserId . '_' . time() . '.' . $ext;
                $targetDir = $rootDir . '/public/uploads/lecons/';

                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                if (move_uploaded_file($file['tmp_name'], $targetDir . $newName)) {
                    @chmod($targetDir . $newName, 0666);
                    if (!empty($existing['fichier_lecon']) && file_exists($targetDir . $existing['fichier_lecon'])) {
                        @unlink($targetDir . $existing['fichier_lecon']);
                    }
                    $fichierLecon = $newName;
                }
            }

            $stmtUp = $db->prepare("
                UPDATE lecons 
                SET chapitre_id = ?, titre = ?, format_lecon = ?, contenu = ?, fichier_lecon = ?, youtube_url = ?, ordre = ?
                WHERE id = ?");
            $stmtUp->execute([
                $chapitreId, $titre, $formatLecon, $contenu, $fichierLecon,
                ($formatLecon === 'youtube' ? $youtubeUrl : null), $ordre, $lessonId
            ]);

            $fichierEval = $existing['fichier_evaluation'];
            if ($typeEval === 'pdf' && isset($_FILES['eval_pdf_file']) && $_FILES['eval_pdf_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['eval_pdf_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'eval_' . $lessonId . '_' . time() . '.' . $ext;
                $targetDir = $rootDir . '/public/uploads/evaluations/';

                if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                if (move_uploaded_file($file['tmp_name'], $targetDir . $newName)) {
                    @chmod($targetDir . $newName, 0666);
                    if (!empty($existing['fichier_evaluation']) && file_exists($targetDir . $existing['fichier_evaluation'])) {
                        @unlink($targetDir . $existing['fichier_evaluation']);
                    }
                    $fichierEval = $newName;
                }
            }

            $jsonQuiz = null;
            if ($typeEval === 'quiz') {
                $questions = $_POST['quiz_questions'] ?? [];
                $options   = $_POST['quiz_options'] ?? [];
                $answers   = $_POST['quiz_answers'] ?? [];
                $quizData  = [];

                if (is_array($questions)) {
                    foreach ($questions as $idx => $qText) {
                        if (!empty(trim($qText))) {
                            $quizData[] = [
                                'question' => trim($qText),
                                'options'  => $options[$idx] ?? [],
                                'answer'   => (int)($answers[$idx] ?? 0)
                            ];
                        }
                    }
                }
                if (!empty($quizData)) {
                    $jsonQuiz = json_encode($quizData, JSON_UNESCAPED_UNICODE);
                }
            }

            $stmtEval = $db->prepare("
                INSERT INTO evaluations (lecon_id, type_evaluation, contenu_quiz, eval_qna_text, fichier_evaluation, score_minimum) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    type_evaluation = VALUES(type_evaluation),
                    contenu_quiz = VALUES(contenu_quiz),
                    eval_qna_text = VALUES(eval_qna_text),
                    fichier_evaluation = COALESCE(VALUES(fichier_evaluation), fichier_evaluation),
                    score_minimum = VALUES(score_minimum)");
            $stmtEval->execute([
                $lessonId, $typeEval, $jsonQuiz,
                ($typeEval === 'qna' ? $evalQna : null),
                $fichierEval, $scoreMin
            ]);

            $_SESSION['flash_success'] = "Leçon et évaluation modifiées avec succès !";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Erreur lors de la modification de la leçon.";
        }

        $this->redirectBack('tutor/course_builder');
    }

    public function delete_lesson() {
        $tutorUserId = $this->getCurrentUserId();
        $id = (int)($_GET['id'] ?? 0);

        if ($tutorUserId > 0 && $id > 0) {
            $db = Database::getConnection();
            $tutorIds = $this->getTutorIds($db, $tutorUserId);
            $inPlaceholders = implode(',', array_fill(0, count($tutorIds), '?'));
            $params = array_merge([$id], $tutorIds);

            try {
                // 1. Vérification de la propriété de la leçon
                $stmtCheck = $db->prepare("
                    SELECT l.id, l.fichier_lecon 
                    FROM lecons l
                    JOIN chapitres c ON l.chapitre_id = c.id
                    WHERE l.id = ? AND c.repetiteur_id IN ($inPlaceholders)");
                $stmtCheck->execute($params);
                $lec = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($lec) {
                    $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);

                    // Supprimer le fichier vidéo/PDF de la leçon
                    if (!empty($lec['fichier_lecon'])) {
                        $path = $rootDir . '/public/uploads/lecons/' . $lec['fichier_lecon'];
                        if (file_exists($path)) { @unlink($path); }
                    }

                    // 2. Nettoyage isolé du fichier d'évaluation
                    try {
                        $stmtE = $db->prepare("SELECT fichier_evaluation FROM evaluations WHERE lecon_id = ?");
                        $stmtE->execute([$id]);
                        $eval = $stmtE->fetch(PDO::FETCH_ASSOC);
                        if ($eval && !empty($eval['fichier_evaluation'])) {
                            $pathEval = $rootDir . '/public/uploads/evaluations/' . $eval['fichier_evaluation'];
                            if (file_exists($pathEval)) { @unlink($pathEval); }
                        }
                    } catch (PDOException $ex) {}

                    // 3. Suppression préalable des enregistrements enfants liés aux clés étrangères
                    $dependentTables = [
                        'evaluations',
                        'progression_lecons',
                        'progression_eleves',
                        'historique_lectures',
                        'reponses_quiz',
                        'soumissions_evaluations'
                    ];

                    foreach ($dependentTables as $table) {
                        try {
                            $db->prepare("DELETE FROM {$table} WHERE lecon_id = ?")->execute([$id]);
                        } catch (PDOException $ex) {}
                    }

                    // 4. Suppression définitive de la leçon
                    $stmtDel = $db->prepare("DELETE FROM lecons WHERE id = ?");
                    $stmtDel->execute([$id]);

                    $_SESSION['flash_success'] = "Leçon supprimée avec succès.";
                } else {
                    $_SESSION['flash_error'] = "Leçon introuvable ou vous n'avez pas l'autorisation de la supprimer.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Erreur SQL : " . $e->getMessage();
            }
        }
        $this->redirectBack('tutor/course_builder');
    }

    // --- HELPER METHODS ---

    private function getTutorIds(PDO $db, int $userId): array {
        $ids = [$userId];
        try {
            $stmt = $db->prepare("SELECT id FROM repetiteurs WHERE utilisateur_id = ?");
            $stmt->execute([$userId]);
            $repId = $stmt->fetchColumn();
            if ($repId) {
                $ids[] = (int)$repId;
            }
        } catch (PDOException $e) {}
        return array_values(array_unique($ids));
    }

    private function ensureTutorProfileExists(PDO $db, int $tutorUserId): void {
        if ($tutorUserId <= 0) return;
        try {
            $stmt = $db->prepare("
                INSERT INTO repetiteurs (utilisateur_id, note_moyenne, recommande_admin)
                VALUES (?, 0.00, FALSE)
                ON DUPLICATE KEY UPDATE utilisateur_id = utilisateur_id");
            $stmt->execute([$tutorUserId]);
        } catch (PDOException $e) {}
    }

    private function getCurrentUserId(): int {
        return (int) ($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['id'] ?? 0);
    }

    private function redirectBack(string $fallbackRoute): void {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (!empty($referer) && strpos($referer, $_SERVER['HTTP_HOST'] ?? '') !== false) {
            header('Location: ' . $referer);
        } else {
            header('Location: ' . BASE_URL . $fallbackRoute);
        }
        exit();
    }
}