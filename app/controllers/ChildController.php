<?php

class ChildController extends Controller {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (method_exists($this, 'requireRole')) {
            $this->requireRole('child');
        } else {
            $role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
            if ($role !== 'child') {
                header('Location: ' . BASE_URL . 'auth/login');
                exit();
            }
        }
    }

    public function index() {
        $this->dashboard();
    }

   public function dashboard() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();
        $user = [];

        try {
            $stmtUser = $db->prepare("
                SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, u.role, 
                       e.classe, e.identifiant_connexion, e.parent_id
                FROM utilisateurs u
                LEFT JOIN enfants e ON e.utilisateur_id = u.id
                WHERE u.id = ?
            ");
            $stmtUser->execute([$childId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($user)) {
                $_SESSION['user'] = $user;
                $_SESSION['user_photo'] = $user['photo_profil'] ?? '';
                $_SESSION['user_prenom'] = $user['prenom'] ?? '';
                $_SESSION['user_nom'] = $user['nom'] ?? '';
            }
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? [];
        }

        $childClasse = $user['classe'] ?? '';
        $childClasseId = null;
        if (!empty($childClasse)) {
            try {
                $stmtCls = $db->prepare("SELECT id FROM classes WHERE id = ? OR LOWER(nom) = LOWER(?) LIMIT 1");
                $stmtCls->execute([$childClasse, $childClasse]);
                $childClasseId = $stmtCls->fetchColumn() ?: null;
            } catch (PDOException $e) {}
        }

        $tutors = [];
        $repetiteurAttitre = null;
        try {
            $stmtTutors = $db->prepare("
                SELECT u.id AS utilisateur_id, u.id AS repetiteur_id, u.nom, u.prenom, u.photo_profil, 
                       COALESCE(r.note_moyenne, 5.0) AS note_moyenne, r.telephone, r.whatsapp
                FROM tutorats_actifs ta
                JOIN utilisateurs u ON ta.repetiteur_id = u.id
                LEFT JOIN repetiteurs r ON u.id = r.utilisateur_id
                WHERE ta.enfant_id = ? AND ta.statut = 'en_cours'
            ");
            $stmtTutors->execute([$childId]);
            $tutors = $stmtTutors->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $repetiteurAttitre = $tutors[0] ?? null;
        } catch (PDOException $e) {
            $tutors = [];
            $repetiteurAttitre = null;
        }

        // Récupération de la progression globale depuis le modèle Elearning
        $elearningModel = method_exists($this, 'model') ? $this->model('Elearning') : new Elearning();
        $globalProgress = [
            'progressionPourcentage' => 0,
            'leconsValideesCount'     => 0
        ];
        if (method_exists($elearningModel, 'getGlobalChildProgress')) {
            $globalProgress = $elearningModel->getGlobalChildProgress($childId);
        }

        // Récupération du dernier quiz et de la liste des chapitres via le modèle Lesson
        $lessonModel = method_exists($this, 'model') ? $this->model('Lesson') : new Lesson();
        $dernierQuiz = null;
        $chapitresList = [];

        try {
            $dernierQuiz = $lessonModel->getDernierQuizParClasse($childClasseId ?? $childClasse, $childId);
            $chapitresList = $lessonModel->getChapitresByClasse($childClasseId ?? $childClasse);
        } catch (Exception $e) {
            $dernierQuiz = null;
            $chapitresList = [];
        }

        $kpis = [
            'lecons_validees' => $globalProgress['leconsValideesCount'] ?? 0,
            'moyenne_quiz'    => 0,
            'sos_envoyes'     => 0,
            'deneat_cdt'      => 0
        ];

        try {
            $stmtProg = $db->prepare("
                SELECT AVG(score_obtenu) AS avg_score
                FROM progression_enfants
                WHERE enfant_id = ?
            ");
            $stmtProg->execute([$childId]);
            $progRes = $stmtProg->fetch(PDO::FETCH_ASSOC);
            $kpis['moyenne_quiz'] = round((float)($progRes['avg_score'] ?? 0), 1);

            $stmtSos = $db->prepare("SELECT COUNT(*) FROM messages WHERE expediteur_id = ? AND type_message = 'sos'");
            $stmtSos->execute([$childId]);
            $kpis['sos_envoyes'] = (int)$stmtSos->fetchColumn();

            $stmtCdt = $db->prepare("SELECT COUNT(*) FROM cahier_de_texte WHERE enfant_id = ?");
            $stmtCdt->execute([$childId]);
            $kpis['deneat_cdt'] = (int)$stmtCdt->fetchColumn();
        } catch (PDOException $e) {}

        $recentReports = [];
        try {
            $stmtReports = $db->prepare("
                SELECT cdt.*, u.nom AS tutor_nom, u.prenom AS tutor_prenom, u.photo_profil AS tutor_photo
                FROM cahier_de_texte cdt
                JOIN utilisateurs u ON cdt.repetiteur_id = u.id
                WHERE cdt.enfant_id = ?
                ORDER BY cdt.date_seance DESC, cdt.id DESC
                LIMIT 5
            ");
            $stmtReports->execute([$childId]);
            $recentReports = $stmtReports->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $recentReports = [];
        }

        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$childId]);
            $unreadMessages = (int)$stmtMsg->fetchColumn();
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $ressourcesCount = 0;
        try {
            if ($childClasseId) {
                $stmtCount = $db->prepare("SELECT COUNT(*) FROM ressources WHERE classe_id = ? OR classe_id IS NULL");
                $stmtCount->execute([$childClasseId]);
            } else {
                $stmtCount = $db->query("SELECT COUNT(*) FROM ressources");
            }
            $ressourcesCount = (int)$stmtCount->fetchColumn();
        } catch (PDOException $e) {
            $ressourcesCount = 0;
        }

        $ressourcesRecent = [];
        try {
            if ($childClasseId) {
                $stmtRes = $db->prepare("
                    SELECT r.*, m.nom AS matiere_nom
                    FROM ressources r
                    LEFT JOIN matieres m ON r.matiere_id = m.id
                    WHERE r.classe_id = ? OR r.classe_id IS NULL
                    ORDER BY r.date_publication DESC, r.id DESC
                    LIMIT 4
                ");
                $stmtRes->execute([$childClasseId]);
            } else {
                $stmtRes = $db->prepare("
                    SELECT r.*, m.nom AS matiere_nom
                    FROM ressources r
                    LEFT JOIN matieres m ON r.matiere_id = m.id
                    ORDER BY r.date_publication DESC, r.id DESC
                    LIMIT 4
                ");
                $stmtRes->execute();
            }
            $ressourcesRecent = $stmtRes->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $ressourcesRecent = [];
        }

        $userPhoto = !empty($user['photo_profil']) ? $user['photo_profil'] : ($_SESSION['user_photo'] ?? 'default.png');

        $data = [
            'titre'                  => 'Espace Élève - EDUVORA',
            'user'                   => $user,
            'userPhoto'              => $userPhoto,
            'tutors'                 => $tutors,
            'repetiteurAttitre'      => $repetiteurAttitre,
            'kpis'                   => $kpis,
            'progressionPourcentage' => $globalProgress['progressionPourcentage'] ?? 0,
            'leconsValideesCount'    => $globalProgress['leconsValideesCount'] ?? 0,
            'dernierQuiz'            => $dernierQuiz,
            'chapitresList'          => $chapitresList,
            'recentReports'          => $recentReports,
            'unreadMessages'         => $unreadMessages,
            'ressourcesRecent'       => $ressourcesRecent,
            'ressourcesCount'        => $ressourcesCount
        ];

        $this->view('child/dashboard', $data);
    }

    public function tracking() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();
        $user = [];
        try {
            $stmtUser = $db->prepare("
                SELECT u.*, e.classe, e.identifiant_connexion, e.parent_id
                FROM utilisateurs u 
                LEFT JOIN enfants e ON e.utilisateur_id = u.id 
                WHERE u.id = ?
            ");
            $stmtUser->execute([$childId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? [];
        }

        $userPhoto = !empty($user['photo_profil']) ? $user['photo_profil'] : ($_SESSION['user_photo'] ?? 'default.png');

        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$childId]);
            $unreadMessages = (int)$stmtMsg->fetchColumn();
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $elearningModel = method_exists($this, 'model') ? $this->model('Elearning') : new Elearning();

        // Récupération des cours attribués ou via repli
        $courses = [];
        if (method_exists($elearningModel, 'getAssignedChaptersWithProgress')) {
            $courses = $elearningModel->getAssignedChaptersWithProgress($childId);
        }

        // Récupération unifiée des statistiques globales
        $progressionPourcentage = 0;
        $leconsValideesCount    = 0;
        $totalTentatives        = 0;

        if (method_exists($elearningModel, 'getGlobalChildProgress')) {
            $globalStats = $elearningModel->getGlobalChildProgress($childId);
            $progressionPourcentage = $globalStats['progressionPourcentage'] ?? 0;
            $leconsValideesCount    = $globalStats['leconsValideesCount'] ?? 0;
            $totalTentatives        = $globalStats['totalTentatives'] ?? 0;
        }

        $data = [
            'titre'                  => 'Mon Suivi E-Learning - EDUVORA',
            'user'                   => $user,
            'userPhoto'              => $userPhoto,
            'courses'                => $courses,
            'progressionPourcentage' => $progressionPourcentage,
            'leconsValideesCount'    => $leconsValideesCount,
            'totalTentatives'        => $totalTentatives,
            'unreadMessages'         => $unreadMessages
        ];

        $this->view('child/tracking', $data);
    }

    public function classroom() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();
        $user = [];
        try {
            $stmtUser = $db->prepare("
                SELECT u.*, e.classe, e.identifiant_connexion, e.parent_id
                FROM utilisateurs u 
                LEFT JOIN enfants e ON e.utilisateur_id = u.id 
                WHERE u.id = ?
            ");
            $stmtUser->execute([$childId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? [];
        }

        $userPhoto = !empty($user['photo_profil']) ? $user['photo_profil'] : ($_SESSION['user_photo'] ?? 'default.png');
        $childClasse = $user['classe'] ?? '';
        $chapterId = (int)($_GET['chapter_id'] ?? 0);
        $selectedLessonId = (int)($_GET['lecon_id'] ?? 0);

        $lessonModel = method_exists($this, 'model') ? $this->model('Lesson') : new Lesson();
        $elearningModel = method_exists($this, 'model') ? $this->model('Elearning') : new Elearning();

        if ($chapterId > 0 && method_exists($elearningModel, 'getChapterLessonsForChild')) {
            $chapterData = $elearningModel->getChapterLessonsForChild($chapterId, $childId);
            $chapters = $chapterData['chapter'] ? [$chapterData['chapter']] : [];
            if (!empty($chapters) && !empty($chapterData['lessons'])) {
                $chapters[0]['lecons'] = array_map(function($l) {
                    $l['lecon_id'] = $l['id'];
                    return $l;
                }, $chapterData['lessons']);
            }
            if ($selectedLessonId <= 0 && isset($chapterData['currentLesson']['id'])) {
                $selectedLessonId = (int)$chapterData['currentLesson']['id'];
            }
        } else {
            $chapters = $lessonModel->getChaptersWithLessons($childId, $childClasse);
            if ($selectedLessonId <= 0 && !empty($chapters[0]['lecons'][0]['lecon_id'])) {
                $selectedLessonId = (int)$chapters[0]['lecons'][0]['lecon_id'];
            }
        }

        $activeLesson = null;
        if ($selectedLessonId > 0) {
            $activeLesson = $lessonModel->getLessonDetails($selectedLessonId, $childId);
        }

        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$childId]);
            $unreadMessages = (int)$stmtMsg->fetchColumn();
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $data = [
            'titre'             => 'Classe Virtuelle & E-Learning - EDUVORA',
            'user'              => $user,
            'userPhoto'         => $userPhoto,
            'chapters'          => $chapters,
            'activeLesson'      => $activeLesson,
            'unreadMessages'    => $unreadMessages,
            'selectedChapterId' => $chapterId
        ];

        $this->view('child/classroom', $data);
    }

    public function submit_quiz() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'child/classroom');
            exit();
        }

        $leconId = (int)($_POST['lecon_id'] ?? 0);
        $userAnswers = $_POST['answers'] ?? [];

        if ($leconId <= 0) {
            $_SESSION['flash_error'] = "Évaluation introuvable.";
            header('Location: ' . BASE_URL . 'child/classroom');
            exit();
        }

        try {
            $lessonModel = method_exists($this, 'model') ? $this->model('Lesson') : new Lesson();
            $lesson = $lessonModel->getLessonDetails($leconId, $childId);

            if (!$lesson || empty($lesson['quiz_data'])) {
                $_SESSION['flash_error'] = "Aucune évaluation n'est disponible pour cette leçon.";
                header('Location: ' . BASE_URL . 'child/classroom?lecon_id=' . $leconId);
                exit();
            }

            $quizData = $lesson['quiz_data'];
            $totalQuestions = count($quizData);
            $correctCount = 0;

            foreach ($quizData as $qIndex => $q) {
                if (isset($userAnswers[$qIndex])) {
                    $userAnsIndex = (int)$userAnswers[$qIndex];
                    $targetAns = $q['reponse'] ?? $q['correct'] ?? $q['reponse_correcte'] ?? $q['bonne_reponse'] ?? $q['answer'] ?? null;

                    if ($targetAns !== null) {
                        if ((string)$userAnsIndex === (string)$targetAns) {
                            $correctCount++;
                        } elseif (($userAnsIndex + 1) === (int)$targetAns) {
                            $correctCount++;
                        } elseif (isset($q['options'][$userAnsIndex]) && trim((string)$q['options'][$userAnsIndex]) === trim((string)$targetAns)) {
                            $correctCount++;
                        }
                    }
                }
            }

            $scoreObtenu = $totalQuestions > 0 ? (int)round(($correctCount / $totalQuestions) * 100) : 0;
            $scoreMin = (int)($lesson['score_minimum'] ?? 50);
            $estValide = ($scoreObtenu >= $scoreMin);

            $lessonModel->saveProgression($childId, $leconId, $scoreObtenu, $estValide);

            if ($estValide) {
                $_SESSION['flash_success'] = "Bravo ! Vous avez obtenu un score de {$scoreObtenu}% (minimum requis : {$scoreMin}%). Leçon validée !";
            } else {
                $_SESSION['flash_error'] = "Score obtenu : {$scoreObtenu}%. Le score minimum requis est de {$scoreMin}%. Réessayez !";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors du traitement du questionnaire.";
        }

        header('Location: ' . BASE_URL . 'child/classroom?lecon_id=' . $leconId);
        exit();
    }

    public function courses() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();
        $user = [];
        try {
            $stmtUser = $db->prepare("
                SELECT u.*, e.classe 
                FROM utilisateurs u 
                LEFT JOIN enfants e ON e.utilisateur_id = u.id 
                WHERE u.id = ?
            ");
            $stmtUser->execute([$childId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? [];
        }

        $userPhoto = !empty($user['photo_profil']) ? $user['photo_profil'] : ($_SESSION['user_photo'] ?? 'default.png');
        $childClasseName = $user['classe'] ?? '';
        $defaultClasseId = 0;

        if (!empty($childClasseName)) {
            try {
                $stmtCls = $db->prepare("SELECT id FROM classes WHERE id = ? OR LOWER(nom) = LOWER(?) LIMIT 1");
                $stmtCls->execute([$childClasseName, $childClasseName]);
                $defaultClasseId = (int)($stmtCls->fetchColumn() ?: 0);
            } catch (PDOException $e) {}
        }

        $searchQuery   = trim($_GET['query'] ?? '');
        $typeFilter    = trim($_GET['type'] ?? '');
        $formatFilter  = trim($_GET['format'] ?? '');
        $sectionFilter = (int)($_GET['section_id'] ?? 0);
        $matiereFilter = (int)($_GET['matiere_id'] ?? 0);
        $isClasseSubmitted = isset($_GET['classe_id']);
        $classeFilter  = $isClasseSubmitted ? (int)$_GET['classe_id'] : $defaultClasseId;

        $sql = "
            SELECT r.*, u.nom AS auteur_nom, u.prenom AS auteur_prenom, u.photo_profil AS auteur_photo,
                   s.nom AS section_nom, m.nom AS matiere_nom, c.nom AS classe_nom
            FROM ressources r
            JOIN utilisateurs u ON r.auteur_id = u.id
            LEFT JOIN sections s ON r.section_id = s.id
            LEFT JOIN matieres m ON r.matiere_id = m.id
            LEFT JOIN classes c ON r.classe_id = c.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($typeFilter)) {
            $sql .= " AND r.type = ?";
            $params[] = $typeFilter;
        }
        if (!empty($formatFilter)) {
            $sql .= " AND r.format_fichier = ?";
            $params[] = $formatFilter;
        }
        if ($sectionFilter > 0) {
            $sql .= " AND r.section_id = ?";
            $params[] = $sectionFilter;
        }
        if ($matiereFilter > 0) {
            $sql .= " AND r.matiere_id = ?";
            $params[] = $matiereFilter;
        }
        if ($classeFilter > 0) {
            $sql .= " AND (r.classe_id = ? OR r.classe_id IS NULL)";
            $params[] = $classeFilter;
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (r.titre LIKE ? OR m.nom LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
            $searchTerm = '%' . $searchQuery . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY r.date_publication DESC, r.id DESC";
        $ressources = [];
        try {
            $stmtRessources = $db->prepare($sql);
            $stmtRessources->execute($params);
            $ressources = $stmtRessources->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $sections = [];
        $matieres = [];
        $classes  = [];
        try {
            $sections = $db->query("SELECT id, nom FROM sections ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $matieres = $db->query("SELECT id, nom FROM matieres ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $classes  = $db->query("SELECT id, nom FROM classes ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$childId]);
            $unreadMessages = (int)$stmtMsg->fetchColumn();
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $data = [
            'titre'          => 'Cours et Épreuves - EDUVORA',
            'user'           => $user,
            'userPhoto'      => $userPhoto,
            'ressources'     => $ressources,
            'sections'       => $sections,
            'matieres'       => $matieres,
            'classes'        => $classes,
            'filters'        => [
                'query'      => $searchQuery,
                'type'       => $typeFilter,
                'format'     => $formatFilter,
                'section_id' => $sectionFilter,
                'matiere_id' => $matiereFilter,
                'classe_id'  => $classeFilter
            ],
            'unreadMessages' => $unreadMessages
        ];

        $this->view('child/courses', $data);
    }

   public function help_sos() {
        $childId = $this->getCurrentUserId();
        if ($childId <= 0) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }

        $db = Database::getConnection();

        // Traitement de l'envoi de la demande SOS (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $contenu = trim($_POST['contenu'] ?? '');
            $file = $_FILES['fichier_sos'] ?? $_FILES['piece_jointe'] ?? null;

            if (empty($contenu) && (!$file || $file['error'] !== UPLOAD_ERR_OK)) {
                $_SESSION['flash_error'] = "Veuillez saisir une explication ou joindre un fichier (Image ou PDF).";
            } else {
                try {
                    // 1. Récupération de la classe de l'enfant
                    $stmtClasse = $db->prepare("SELECT classe FROM enfants WHERE utilisateur_id = ?");
                    $stmtClasse->execute([$childId]);
                    $childClasse = $stmtClasse->fetchColumn() ?: '';

                    // 2. Traitement de la pièce jointe (PDF ou Image)
                    $attachmentHtml = '';
                    if ($file && $file['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                        if (in_array($ext, $allowed)) {
                            $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
                            $targetDir = rtrim($rootDir, '/\\') . '/public/uploads/sos/';

                            if (!is_dir($targetDir)) {
                                @mkdir($targetDir, 0777, true);
                            }

                            $fileName = 'sos_' . $childId . '_' . time() . '.' . $ext;
                            $targetPath = $targetDir . $fileName;

                            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                                @chmod($targetPath, 0666);
                                $fileUrl = BASE_URL . 'public/uploads/sos/' . $fileName;

                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                                    $attachmentHtml = '<br><div class="sos-attachment mt-2"><a href="' . $fileUrl . '" target="_blank"><img src="' . $fileUrl . '" alt="Pièce jointe SOS" style="max-width: 100%; max-height: 250px; border-radius: 8px;"></a></div>';
                                } else {
                                    $attachmentHtml = '<br><div class="sos-attachment mt-2"><a href="' . $fileUrl . '" target="_blank" class="btn-pdf-link"><i class="fas fa-file-pdf"></i> Consulter le document PDF joint</a></div>';
                                }
                            }
                        }
                    }

                    $finalContenu = trim($contenu) . $attachmentHtml;

                    // 3. Récupération des destinataires : Tous les répétiteurs
                    $stmtTutors = $db->query("SELECT id FROM utilisateurs WHERE role = 'tutor'");
                    $tutorIds = $stmtTutors->fetchAll(PDO::FETCH_COLUMN) ?: [];

                    // 4. Récupération des destinataires : Élèves de la même classe (hors expéditeur)
                    $childIds = [];
                    if (!empty($childClasse)) {
                        $stmtChildren = $db->prepare("
                            SELECT u.id 
                            FROM utilisateurs u 
                            JOIN enfants e ON u.id = e.utilisateur_id 
                            WHERE u.role = 'child' AND e.classe = ? AND u.id != ?
                        ");
                        $stmtChildren->execute([$childClasse, $childId]);
                        $childIds = $stmtChildren->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    }

                    // Dédoublonnage de la liste des destinataires
                    $recipients = array_unique(array_merge($tutorIds, $childIds));

                    if (!empty($recipients)) {
                        $db->beginTransaction();

                        $stmtIns = $db->prepare("
                            INSERT INTO messages (expediteur_id, destinataire_id, contenu, type_message, est_lu, date_envoi)
                            VALUES (?, ?, ?, 'sos', 0, NOW())
                        ");

                        foreach ($recipients as $destId) {
                            $stmtIns->execute([$childId, $destId, $finalContenu]);
                        }

                        $db->commit();
                        $_SESSION['flash_success'] = "Votre SOS a été envoyé à tous les répétiteurs et vos camarades de classe !";
                    } else {
                        $_SESSION['flash_error'] = "Aucun destinataire trouvé dans votre classe ou parmi les tuteurs.";
                    }

                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['flash_error'] = "Erreur lors de l'envoi de la demande SOS.";
                }

                $this->redirectBack('child/help_sos');
                exit();
            }
        }

        // Chargement des données de l'élève
        $user = [];
        try {
            $stmtUser = $db->prepare("
                SELECT u.*, e.classe 
                FROM utilisateurs u 
                LEFT JOIN enfants e ON e.utilisateur_id = u.id 
                WHERE u.id = ?
            ");
            $stmtUser->execute([$childId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $user = $_SESSION['user'] ?? [];
        }

        $userPhoto = !empty($user['photo_profil']) ? $user['photo_profil'] : ($_SESSION['user_photo'] ?? 'default.png');

        // Récupération de l'historique des SOS envoyés par cet enfant
        $sosHistory = [];
        try {
            $stmtSos = $db->prepare("
                SELECT m.contenu, m.date_envoi, MIN(m.id) AS id
                FROM messages m
                WHERE m.expediteur_id = ? AND m.type_message = 'sos'
                GROUP BY m.contenu, m.date_envoi
                ORDER BY m.date_envoi DESC
            ");
            $stmtSos->execute([$childId]);
            $sosHistory = $stmtSos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $sosHistory = [];
        }

        // Nombre de messages non lus
        $unreadMessages = 0;
        try {
            $stmtMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND est_lu = 0");
            $stmtMsg->execute([$childId]);
            $unreadMessages = (int)$stmtMsg->fetchColumn();
        } catch (PDOException $e) {
            $unreadMessages = 0;
        }

        $data = [
            'titre'          => 'Aide SOS Exercices - EDUVORA',
            'user'           => $user,
            'userPhoto'      => $userPhoto,
            'sosHistory'     => $sosHistory,
            'unreadMessages' => $unreadMessages
        ];

        $this->view('child/help_sos', $data);
    }

    public function submit_review() {
    $childId = $this->getCurrentUserId();
    if ($childId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirectBack('child/dashboard');
        exit();
    }

    $repetiteurId = (int)($_POST['repetiteur_id'] ?? 0);
    $note         = (int)($_POST['note'] ?? 5);
    $commentaire  = trim($_POST['commentaire'] ?? '');

    if ($repetiteurId <= 0 || $note < 1 || $note > 5) {
        $_SESSION['flash_error'] = "Données d'évaluation invalides.";
        $this->redirectBack('child/dashboard');
        exit();
    }

    try {
        // Utilisation du modèle Tutor pour gérer l'enregistrement et la moyenne
        $tutorModel = $this->model('Tutor');
        $success = $tutorModel->addAvis($repetiteurId, $childId, $note, $commentaire);

        if ($success) {
            $_SESSION['flash_success'] = "Ton avis a été enregistré avec succès !";
        } else {
            $_SESSION['flash_error'] = "Erreur lors de l'enregistrement de l'avis.";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Une erreur est survenue lors de la soumission.";
    }

    $this->redirectBack('child/dashboard');
}

 /**
     * Traite la soumission de la photo de profil
     */
    public function updateProfilePhoto() {
        $userId = $this->getCurrentUserId();

        if ($userId <= 0) {
            $_SESSION['flash_error'] = "Erreur : Utilisateur non identifié en session.";
            $this->redirectBack('child/dashboard');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_profil'])) {
            $file = $_FILES['photo_profil'];
            
            if ($file['error'] === UPLOAD_ERR_OK) {
                $this->processUploadPhoto($userId, $file);
            } else {
                $_SESSION['flash_error'] = "Erreur lors de l'envoi du fichier (Code: " . $file['error'] . ").";
            }
        }

        $this->redirectBack('child/dashboard');
    }

    /**
     * Méthode privée gérant la validation, le déplacement et l'enregistrement de l'image
     */
    private function processUploadPhoto(int $userId, array $file): bool {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $_SESSION['flash_error'] = "Format non valide. Formats autorisés : JPG, PNG, WEBP.";
            return false;
        }

        $newName = 'child_' . $userId . '_' . time() . '.' . $ext;
        $rootDir = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
        $targetDir = $rootDir . '/public/uploads/profiles/';

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $targetPath = $targetDir . $newName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            @chmod($targetPath, 0666);
            $db = Database::getConnection();

            try {
                // Suppression de l'ancienne photo du serveur si différente de default.png
                $stmtOld = $db->prepare("SELECT photo_profil FROM utilisateurs WHERE id = ?");
                $stmtOld->execute([$userId]);
                $oldPhoto = $stmtOld->fetchColumn();

                if (!empty($oldPhoto) && $oldPhoto !== 'default.png' && file_exists($targetDir . $oldPhoto)) {
                    @unlink($targetDir . $oldPhoto);
                }

                // Mise à jour BDD
                $stmt = $db->prepare("UPDATE utilisateurs SET photo_profil = ? WHERE id = ?");
                $stmt->execute([$newName, $userId]);

                // Synchronisation des variables de session
                $_SESSION['user_photo'] = $newName;
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['photo_profil'] = $newName;
                } else {
                    $_SESSION['user'] = ['photo_profil' => $newName];
                }

                $_SESSION['flash_success'] = "Photo de profil mise à jour avec succès.";
                return true;
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Erreur lors de la mise à jour en base de données.";
                return false;
            }
        } else {
            $_SESSION['flash_error'] = "Erreur lors du transfert du fichier vers le serveur.";
            return false;
        }
    }

    /**
     * Récupère l'ID utilisateur courant depuis la session
     */
    private function getCurrentUserId(): int {
        return (int) ($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['id'] ?? 0);
    }

    /**
     * Redirige vers la page précédente ou vers la route par défaut
     */
    private function redirectBack(string $fallbackRoute): void {
        $referer = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . $fallbackRoute);
        header('Location: ' . $referer);
        exit();
    }
}