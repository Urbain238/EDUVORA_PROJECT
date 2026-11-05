<?php
class ElearningController extends Controller {
    private $elearningModel;

    public function __construct() {
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
            header('Location: ' . BASE_URL . 'auth/login');
            exit;
        }
        $this->elearningModel = $this->model('Elearning');
    }

    // Page principale de gestion de l'e-learning par élève
    public function manage() {
        $tutorId = $_SESSION['user_id'];
        $students = $this->elearningModel->getActiveStudentsByTutor($tutorId);
        $chapters = $this->elearningModel->getChaptersByTutor($tutorId);

        $selectedStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : ($students[0]['id'] ?? 0);
        $assignedChapterIds = [];

        if ($selectedStudentId > 0) {
            $assignedChapterIds = $this->elearningModel->getAssignedChapterIdsByStudent($selectedStudentId);
        }

        $data = [
            'titre' => 'Gestion E-Learning Élèves',
            'students' => $students,
            'chapters' => $chapters,
            'selectedStudentId' => $selectedStudentId,
            'assignedChapterIds' => $assignedChapterIds
        ];

        $this->view('tutor/elearning_manager', $data);
    }

    // Traitement de l'attribution / révocation des modules
    public function toggle_assignment() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tutorId = $_SESSION['user_id'];
            $studentId = (int)($_POST['student_id'] ?? 0);
            $chapterId = (int)($_POST['chapter_id'] ?? 0);
            $action = $_POST['action'] ?? '';

            if ($studentId > 0 && $chapterId > 0) {
                if ($action === 'assign') {
                    $this->elearningModel->assignChapter($tutorId, $studentId, $chapterId);
                    $_SESSION['flash_success'] = "Module e-learning attribué avec succès.";
                } elseif ($action === 'remove') {
                    $this->elearningModel->removeAssignment($tutorId, $studentId, $chapterId);
                    $_SESSION['flash_success'] = "Module e-learning retiré de l'élève.";
                }
            }
            header('Location: ' . BASE_URL . 'elearning/manage?student_id=' . $studentId);
            exit;
        }
    }
}