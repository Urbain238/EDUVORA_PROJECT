<?php

class Elearning {
    private $db;

    public function __construct() {
        $database = Database::getInstance();
        $this->db = method_exists($database, 'getConnection') ? $database->getConnection() : $database;
    }

    // Récupérer les élèves actifs suivis par ce répétiteur
    public function getActiveStudentsByTutor($tutorId) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.nom, u.prenom, u.photo_profil, e.classe 
            FROM tutorats_actifs ta
            JOIN utilisateurs u ON ta.enfant_id = u.id
            JOIN enfants e ON u.id = e.utilisateur_id
            WHERE ta.repetiteur_id = :tutor_id AND ta.statut = 'en_cours'
            ORDER BY u.nom ASC
        ");
        $stmt->execute([':tutor_id' => $tutorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Récupérer les classes distinctes de l'ensemble des élèves du répétiteur
    public function getActiveClassesByTutor($tutorId) {
        $stmt = $this->db->prepare("
            SELECT DISTINCT e.classe, COUNT(u.id) AS nb_eleves
            FROM tutorats_actifs ta
            JOIN utilisateurs u ON ta.enfant_id = u.id
            JOIN enfants e ON u.id = e.utilisateur_id
            WHERE ta.repetiteur_id = :tutor_id AND ta.statut = 'en_cours' AND e.classe IS NOT NULL AND e.classe != ''
            GROUP BY e.classe
            ORDER BY e.classe ASC
        ");
        $stmt->execute([':tutor_id' => $tutorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Récupérer tous les chapitres créés par ce répétiteur
    public function getChaptersByTutor($tutorId) {
        $stmt = $this->db->prepare("
            SELECT c.*, cl.nom AS classe_nom, COUNT(l.id) AS nb_lecons
            FROM chapitres c
            LEFT JOIN classes cl ON c.classe_id = cl.id
            LEFT JOIN lecons l ON c.id = l.chapitre_id
            WHERE c.repetiteur_id = :tutor_id
            GROUP BY c.id, cl.nom
            ORDER BY c.ordre ASC
        ");
        $stmt->execute([':tutor_id' => $tutorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Attribuer un chapitre à un élève unique avec date limite
    public function assignChapterToStudent($tutorId, $studentId, $chapterId, $dateLimite = null) {
        $stmt = $this->db->prepare("
            INSERT INTO elearning_attributions (repetiteur_id, enfant_id, chapitre_id, date_limite) 
            VALUES (:tutor_id, :student_id, :chapter_id, :date_limite)
            ON DUPLICATE KEY UPDATE date_limite = VALUES(date_limite)
        ");
        return $stmt->execute([
            ':tutor_id'    => $tutorId,
            ':student_id'  => $studentId,
            ':chapter_id'  => $chapterId,
            ':date_limite' => !empty($dateLimite) ? $dateLimite : null
        ]);
    }

    // Attribuer un chapitre à tous les élèves d'une même classe avec date limite
    public function assignChapterToClass($tutorId, $className, $chapterId, $dateLimite = null) {
        $stmt = $this->db->prepare("
            SELECT u.id 
            FROM tutorats_actifs ta
            JOIN utilisateurs u ON ta.enfant_id = u.id
            JOIN enfants e ON u.id = e.utilisateur_id
            WHERE ta.repetiteur_id = :tutor_id AND ta.statut = 'en_cours' AND e.classe = :classe
        ");
        $stmt->execute([
            ':tutor_id' => $tutorId,
            ':classe'   => $className
        ]);
        $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($students as $studentId) {
            $this->assignChapterToStudent($tutorId, $studentId, $chapterId, $dateLimite);
        }
        return true;
    }

    // Retirer un chapitre attribué à un élève
    public function removeAssignment($tutorId, $studentId, $chapterId) {
        $stmt = $this->db->prepare("
            DELETE FROM elearning_attributions 
            WHERE repetiteur_id = :tutor_id AND enfant_id = :student_id AND chapitre_id = :chapter_id
        ");
        return $stmt->execute([
            ':tutor_id'   => $tutorId,
            ':student_id' => $studentId,
            ':chapter_id' => $chapterId
        ]);
    }

    // Récupérer tous les cours attribués à un élève avec calcul de progression
    public function getAssignedChaptersWithProgress($childId) {
        $stmt = $this->db->prepare("
            SELECT 
                c.id AS chapitre_id,
                c.titre AS chapitre_titre,
                ea.date_limite,
                ea.date_attribution,
                u.nom AS repetiteur_nom,
                u.prenom AS repetiteur_prenom,
                u.photo_profil AS repetiteur_photo,
                COUNT(DISTINCT l.id) AS total_lecons,
                COUNT(DISTINCT CASE WHEN pe.est_valide = 1 THEN l.id END) AS lecons_validees
            FROM elearning_attributions ea
            JOIN chapitres c ON ea.chapitre_id = c.id
            JOIN utilisateurs u ON ea.repetiteur_id = u.id
            LEFT JOIN lecons l ON c.id = l.chapitre_id
            LEFT JOIN progression_enfants pe ON l.id = pe.lecon_id AND pe.enfant_id = ea.enfant_id
            WHERE ea.enfant_id = :child_id
            GROUP BY c.id, c.titre, ea.date_limite, ea.date_attribution, u.nom, u.prenom, u.photo_profil
            ORDER BY ea.date_attribution DESC
        ");
        $stmt->execute([':child_id' => $childId]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Repli (Fallback) : Si aucune attribution spécifique n'existe, charger via les tutorats actifs
        if (empty($courses)) {
            $stmtFallback = $this->db->prepare("
                SELECT 
                    c.id AS chapitre_id,
                    c.titre AS chapitre_titre,
                    NULL AS date_limite,
                    CURRENT_TIMESTAMP AS date_attribution,
                    u.nom AS repetiteur_nom,
                    u.prenom AS repetiteur_prenom,
                    u.photo_profil AS repetiteur_photo,
                    COUNT(DISTINCT l.id) AS total_lecons,
                    COUNT(DISTINCT CASE WHEN pe.est_valide = 1 THEN l.id END) AS lecons_validees
                FROM tutorats_actifs ta
                JOIN chapitres c ON ta.repetiteur_id = c.repetiteur_id
                JOIN utilisateurs u ON ta.repetiteur_id = u.id
                LEFT JOIN lecons l ON c.id = l.chapitre_id
                LEFT JOIN progression_enfants pe ON l.id = pe.lecon_id AND pe.enfant_id = ta.enfant_id
                WHERE ta.enfant_id = :child_id AND ta.statut = 'en_cours'
                GROUP BY c.id, c.titre, u.nom, u.prenom, u.photo_profil
                ORDER BY c.ordre ASC
            ");
            $stmtFallback->execute([':child_id' => $childId]);
            $courses = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
        }

        return $courses;
    }

    // Récupérer les statistiques globales de progression (Calcul direct + Repli)
    public function getGlobalChildProgress($childId) {
        // 1. Nombre réel de leçons validées
        $stmtVal = $this->db->prepare("
            SELECT COUNT(DISTINCT lecon_id) 
            FROM progression_enfants 
            WHERE enfant_id = :child_id AND est_valide = 1
        ");
        $stmtVal->execute([':child_id' => $childId]);
        $validees = (int)$stmtVal->fetchColumn();

        // 2. Nombre total de tentatives de l'élève
        $stmtTentatives = $this->db->prepare("
            SELECT COUNT(*) 
            FROM progression_enfants 
            WHERE enfant_id = :child_id
        ");
        $stmtTentatives->execute([':child_id' => $childId]);
        $totalTentatives = (int)$stmtTentatives->fetchColumn();

        // 3. Total des leçons attribuées à l'élève
        $stmtTotalAttr = $this->db->prepare("
            SELECT COUNT(DISTINCT l.id)
            FROM elearning_attributions ea
            JOIN chapitres c ON ea.chapitre_id = c.id
            JOIN lecons l ON c.id = l.chapitre_id
            WHERE ea.enfant_id = :child_id
        ");
        $stmtTotalAttr->execute([':child_id' => $childId]);
        $totalLecons = (int)$stmtTotalAttr->fetchColumn();

        // Repli 1 : Compter via les tutorats actifs
        if ($totalLecons === 0) {
            $stmtTuteur = $this->db->prepare("
                SELECT COUNT(DISTINCT l.id)
                FROM tutorats_actifs ta
                JOIN chapitres c ON ta.repetiteur_id = c.repetiteur_id
                JOIN lecons l ON c.id = l.chapitre_id
                WHERE ta.enfant_id = :child_id AND ta.statut = 'en_cours'
            ");
            $stmtTuteur->execute([':child_id' => $childId]);
            $totalLecons = (int)$stmtTuteur->fetchColumn();
        }

        // Repli 2 : Compter le total général des leçons existantes
        if ($totalLecons === 0) {
            $stmtAll = $this->db->query("SELECT COUNT(id) FROM lecons");
            $totalLecons = (int)$stmtAll->fetchColumn();
        }

        $pourcentage = ($totalLecons > 0) ? (int)round(($validees / $totalLecons) * 100) : 0;
        if ($pourcentage > 100) $pourcentage = 100;

        return [
            'total_lecons'            => $totalLecons,
            'leconsValideesCount'     => $validees,
            'totalTentatives'        => $totalTentatives,
            'progressionPourcentage' => $pourcentage
        ];
    }

    // Récupérer un chapitre spécifique attribué à un élève avec l'état de ses leçons
    public function getChapterLessonsForChild($chapterId, $childId) {
        $stmtChap = $this->db->prepare("
            SELECT c.*, c.titre AS chapitre_titre 
            FROM chapitres c 
            WHERE c.id = :chapter_id
        ");
        $stmtChap->execute([':chapter_id' => $chapterId]);
        $chapter = $stmtChap->fetch(PDO::FETCH_ASSOC);

        if (!$chapter) {
            return ['chapter' => null, 'lessons' => [], 'currentLesson' => null];
        }

        $stmtLessons = $this->db->prepare("
            SELECT 
                l.*,
                COALESCE(pe.est_valide, 0) AS est_valide,
                pe.score_obtenu
            FROM lecons l
            LEFT JOIN progression_enfants pe ON l.id = pe.lecon_id AND pe.enfant_id = :child_id
            WHERE l.chapitre_id = :chapter_id
            ORDER BY l.ordre ASC
        ");
        $stmtLessons->execute([
            ':chapter_id' => $chapterId,
            ':child_id'   => $childId
        ]);
        $lessons = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);

        $currentLesson = null;
        foreach ($lessons as $lesson) {
            if ((int)$lesson['est_valide'] === 0) {
                $currentLesson = $lesson;
                break;
            }
        }
        if (!$currentLesson && !empty($lessons)) {
            $currentLesson = $lessons[count($lessons) - 1];
        }

        return [
            'chapter'       => $chapter,
            'lessons'       => $lessons,
            'currentLesson' => $currentLesson
        ];
    }

    // Récupérer un tableau associatif [chapitre_id => date_limite] pour un élève
    public function getAssignedChaptersDetailsByStudent($studentId) {
        $stmt = $this->db->prepare("
            SELECT chapitre_id, date_limite 
            FROM elearning_attributions 
            WHERE enfant_id = :student_id
        ");
        $stmt->execute([':student_id' => $studentId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assigned = [];
        foreach ($results as $row) {
            $assigned[$row['chapitre_id']] = $row['date_limite'];
        }
        return $assigned;
    }

    // Méthode de secours pour la liste simple des ID de chapitres
    public function getAssignedChapterIdsByStudent($studentId) {
        return array_keys($this->getAssignedChaptersDetailsByStudent($studentId));
    }
}