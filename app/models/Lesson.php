<?php

class Lesson {
    private $db;

    public function __construct() {
        $this->db = method_exists('Database', 'getConnection') 
            ? Database::getConnection() 
            : Database::getInstance();
    }

    // ==========================================
    // --- CHAPITRES ---
    // ==========================================

    public function createChapitre(int $repetiteurId, string $titre, ?int $classeId, int $ordre = 1): int {
        $sql = "INSERT INTO chapitres (repetiteur_id, titre, classe_id, ordre) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$repetiteurId, $titre, $classeId, $ordre]);
        return (int)$this->db->lastInsertId();
    }

    public function getChapitresByTutor(int $repetiteurId): array {
        $sql = "SELECT ch.*, c.nom AS classe_nom 
                FROM chapitres ch 
                LEFT JOIN classes c ON ch.classe_id = c.id 
                WHERE ch.repetiteur_id = ? 
                ORDER BY ch.ordre ASC, ch.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$repetiteurId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère la liste des chapitres d'une classe avec le nom du répétiteur et le total des leçons
     */
    public function getChapitresByClasse($classe): array {
        $sql = "SELECT 
                    c.id, 
                    c.titre, 
                    c.ordre, 
                    CONCAT(u.prenom, ' ', u.nom) AS repetiteur_nom,
                    COUNT(l.id) AS total_lecons
                FROM chapitres c
                LEFT JOIN classes cl ON c.classe_id = cl.id
                LEFT JOIN utilisateurs u ON c.repetiteur_id = u.id
                LEFT JOIN lecons l ON l.chapitre_id = c.id
                WHERE (c.classe_id = :c_val OR cl.nom = :c_nom OR c.classe_id IS NULL)
                GROUP BY c.id, c.titre, c.ordre, u.prenom, u.nom
                ORDER BY c.ordre ASC, c.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':c_val' => $classe,
            ':c_nom' => (string)$classe
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère le programme complet (Chapitres + Leçons + Progression) pour un élève
     */
    public function getChaptersWithLessons(int $enfantId, string $classeNom = ''): array {
        $params = [':enfant_id' => $enfantId];
        $whereClause = "";

        if (!empty($classeNom)) {
            $whereClause = "WHERE cl.nom = :c_nom1 OR c.classe_id = :c_nom2 OR c.classe_id IS NULL";
            $params[':c_nom1'] = $classeNom;
            $params[':c_nom2'] = $classeNom;
        }

        $sql = "SELECT 
                    c.id AS chapitre_id, c.titre AS chapitre_titre, c.ordre AS chap_ordre,
                    l.id AS lecon_id, l.titre AS lecon_titre, l.ordre AS lecon_ordre,
                    p.score_obtenu, p.est_valide
                FROM chapitres c
                LEFT JOIN classes cl ON c.classe_id = cl.id
                LEFT JOIN lecons l ON l.chapitre_id = c.id
                LEFT JOIN progression_enfants p ON p.lecon_id = l.id AND p.enfant_id = :enfant_id
                {$whereClause}
                ORDER BY c.ordre ASC, c.id ASC, l.ordre ASC, l.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $chapters = [];
        foreach ($rows as $row) {
            $cId = $row['chapitre_id'];
            if (!isset($chapters[$cId])) {
                $chapters[$cId] = [
                    'chapitre_id'    => $cId,
                    'chapitre_titre' => $row['chapitre_titre'],
                    'lecons'         => []
                ];
            }
            if (!empty($row['lecon_id'])) {
                $chapters[$cId]['lecons'][] = [
                    'lecon_id'     => $row['lecon_id'],
                    'lecon_titre'  => $row['lecon_titre'],
                    'score_obtenu' => $row['score_obtenu'],
                    'est_valide'   => $row['est_valide']
                ];
            }
        }
        return array_values($chapters);
    }

    // ==========================================
    // --- LEÇONS ---
    // ==========================================

    public function createLecon(
        int $chapitreId, 
        string $titre, 
        ?string $contenu = null, 
        int $ordre = 1, 
        string $formatLecon = 'texte', 
        ?string $fichierLecon = null, 
        ?string $youtubeUrl = null
    ): int {
        $sql = "INSERT INTO lecons (chapitre_id, titre, contenu, ordre, format_lecon, fichier_lecon, youtube_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $chapitreId, 
            $titre, 
            $contenu, 
            $ordre, 
            $formatLecon, 
            $fichierLecon, 
            $youtubeUrl
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getLeconsByChapitre(int $chapitreId): array {
        $sql = "SELECT l.*, e.id AS evaluation_id, e.score_minimum, e.contenu_quiz, e.type_evaluation, e.eval_qna_text, e.fichier_evaluation 
                FROM lecons l 
                LEFT JOIN evaluations e ON l.id = e.lecon_id 
                WHERE l.chapitre_id = ? 
                ORDER BY l.ordre ASC, l.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$chapitreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Récupère le détail d'une leçon ainsi que son évaluation et l'état de progression de l'élève
     */
    public function getLessonDetails(int $leconId, int $enfantId): ?array {
        $sql = "SELECT 
                    l.id AS lecon_id, l.chapitre_id, l.titre AS lecon_titre, l.contenu, 
                    l.format_lecon, l.fichier_lecon, l.youtube_url,
                    e.id AS evaluation_id, e.contenu_quiz, e.score_minimum, 
                    e.type_evaluation, e.eval_qna_text, e.fichier_evaluation,
                    p.score_obtenu, p.est_valide
                FROM lecons l
                LEFT JOIN evaluations e ON e.lecon_id = l.id
                LEFT JOIN progression_enfants p ON p.lecon_id = l.id AND p.enfant_id = :enfant_id
                WHERE l.id = :lecon_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'lecon_id'  => $leconId,
            'enfant_id' => $enfantId
        ]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lesson) {
            $lesson['quiz_data'] = !empty($lesson['contenu_quiz']) ? json_decode($lesson['contenu_quiz'], true) : [];
        }

        return $lesson ?: null;
    }

    // ==========================================
    // --- ÉVALUATIONS / QUIZ ---
    // ==========================================

    public function saveEvaluation(
        int $leconId, 
        ?array $quizArray = null, 
        int $scoreMinimum = 50, 
        ?string $typeEvaluation = 'quiz', 
        ?string $evalQnaText = null, 
        ?string $fichierEvaluation = null
    ): bool {
        $jsonQuiz = !empty($quizArray) ? json_encode($quizArray, JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO evaluations (lecon_id, contenu_quiz, score_minimum, type_evaluation, eval_qna_text, fichier_evaluation) 
                VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    contenu_quiz = VALUES(contenu_quiz), 
                    score_minimum = VALUES(score_minimum),
                    type_evaluation = VALUES(type_evaluation),
                    eval_qna_text = VALUES(eval_qna_text),
                    fichier_evaluation = VALUES(fichier_evaluation)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $leconId, 
            $jsonQuiz, 
            $scoreMinimum, 
            $typeEvaluation, 
            $evalQnaText, 
            $fichierEvaluation
        ]);
    }

    /**
     * Récupère le dernier quiz e-learning publié pour la classe de l'enfant
     */
    public function getDernierQuizParClasse($classe, int $enfantId): ?array {
        $sql = "SELECT 
                    e.id AS evaluation_id,
                    e.score_minimum,
                    e.type_evaluation,
                    l.id AS lecon_id,
                    l.titre AS lecon_titre,
                    c.id AS chapitre_id,
                    c.titre AS chapitre_titre,
                    u.nom AS repetiteur_nom,
                    u.prenom AS repetiteur_prenom,
                    p.score_obtenu,
                    p.est_valide
                FROM evaluations e
                INNER JOIN lecons l ON e.lecon_id = l.id
                INNER JOIN chapitres c ON l.chapitre_id = c.id
                LEFT JOIN classes cl ON c.classe_id = cl.id
                INNER JOIN utilisateurs u ON c.repetiteur_id = u.id
                LEFT JOIN progression_enfants p ON p.lecon_id = l.id AND p.enfant_id = :enfant_id
                WHERE (c.classe_id = :classe_val OR cl.nom = :classe_nom OR c.classe_id IS NULL)
                ORDER BY e.id DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':classe_val' => $classe,
            ':classe_nom' => (string)$classe,
            ':enfant_id'  => $enfantId
        ]);

        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        return $quiz ?: null;
    }

    // ==========================================
    // --- PROGRESSION ÉLÈVE ---
    // ==========================================

    public function saveProgression(int $enfantId, int $leconId, int $scoreObtenu, bool $isValide): bool {
        $sql = "INSERT INTO progression_enfants (enfant_id, lecon_id, score_obtenu, est_valide)
                VALUES (:enfant_id, :lecon_id, :score, :est_valide)
                ON DUPLICATE KEY UPDATE 
                    score_obtenu = GREATEST(score_obtenu, VALUES(score_obtenu)),
                    est_valide = IF(VALUES(est_valide) = 1, 1, est_valide),
                    date_validation = CURRENT_TIMESTAMP";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'enfant_id'  => $enfantId,
            'lecon_id'   => $leconId,
            'score'      => $scoreObtenu,
            'est_valide' => $isValide ? 1 : 0
        ]);
    }
}