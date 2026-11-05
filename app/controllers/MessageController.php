<?php

class MessageController extends Controller {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Sécuriser l'accès à la messagerie
        if (!isset($_SESSION['user_id']) && !isset($_SESSION['user']['id'])) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit();
        }
    }

    private function getUserId(): int {
        return (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
    }

    /**
     * Affichage de la boîte de réception et traitement des formulaires classiques (Fallback POST)
     */
    public function index() {
        $userId = $this->getUserId();
        $db = Database::getConnection();
        $messageModel = method_exists($this, 'model') ? $this->model('Message') : new Message();

        // Traitement de l'envoi classique en POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
            $destinataireId = (int)($_POST['destinataire_id'] ?? 0);
            $contenu = trim($_POST['contenu'] ?? '');
            $file = $_FILES['attachment'] ?? null;

            if ($destinataireId > 0 && (!empty($contenu) || ($file && $file['error'] === UPLOAD_ERR_OK))) {
                try {
                    $attachmentHtml = method_exists($messageModel, 'uploadAttachment') 
                        ? $messageModel->uploadAttachment($file, $userId, 'chat') 
                        : '';
                    $finalContenu = $contenu . $attachmentHtml;

                    $stmtIns = $db->prepare("
                        INSERT INTO messages (expediteur_id, destinataire_id, contenu, type_message, est_lu, date_envoi)
                        VALUES (?, ?, ?, 'standard', 0, NOW())
                    ");
                    $stmtIns->execute([$userId, $destinataireId, $finalContenu]);

                    $_SESSION['flash_success'] = "Message envoyé avec succès.";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Erreur lors de l'envoi du message.";
                }
            } else {
                $_SESSION['flash_error'] = "Veuillez sélectionner un destinataire et saisir un texte ou joindre un fichier.";
            }

            header('Location: ' . BASE_URL . 'message');
            exit();
        }

        // 1. Récupération des contacts ordonnés par activité récente + messages non lus
        $contacts = method_exists($messageModel, 'getOrderedContacts') 
            ? $messageModel->getOrderedContacts($userId) 
            : [];

        // 2. Récupération des alertes SOS actives (uniquement non lues/non résolues)
        $sosAlerts = method_exists($messageModel, 'getSosAlertsForUser') 
            ? $messageModel->getSosAlertsForUser($userId) 
            : [];

        $data = [
            'titre'         => 'Messagerie & SOS - Horizon',
            'sosAlerts'     => $sosAlerts,
            'contacts'      => $contacts,
            'currentUserId' => $userId
        ];

        $this->view('shared/inbox', $data);
    }

    /**
     * Endpoint AJAX : Récupération du fil de discussion privé
     */
    public function getThread() {
        header('Content-Type: application/json');
        $userId = $this->getUserId();
        $contactId = (int)($_GET['contact_id'] ?? 0);

        if ($userId <= 0 || $contactId <= 0) {
            echo json_encode(['status' => 'error', 'messages' => []]);
            exit();
        }

        $messageModel = method_exists($this, 'model') ? $this->model('Message') : new Message();
        
        // getChatThread s'occupe de marquer automatiquement les messages comme lus
        $messages = method_exists($messageModel, 'getChatThread') 
            ? $messageModel->getChatThread($userId, $contactId) 
            : [];

        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit();
    }

    /**
     * Endpoint AJAX : Envoi instantané d'un message (avec support de fichier)
     */
    public function sendAjax() {
        header('Content-Type: application/json');
        $userId = $this->getUserId();
        
        $destinataireId = (int)($_POST['destinataire_id'] ?? 0);
        $contenu = trim($_POST['contenu'] ?? '');
        $file = $_FILES['attachment'] ?? null;

        if ($userId <= 0 || $destinataireId <= 0 || (empty($contenu) && (!$file || $file['error'] !== UPLOAD_ERR_OK))) {
            echo json_encode(['status' => 'error', 'message' => 'Saisie texte ou fichier joint requis.']);
            exit();
        }

        try {
            $messageModel = method_exists($this, 'model') ? $this->model('Message') : new Message();
            $attachmentHtml = method_exists($messageModel, 'uploadAttachment') 
                ? $messageModel->uploadAttachment($file, $userId, 'chat') 
                : '';
            $finalContenu = $contenu . $attachmentHtml;

            $db = Database::getConnection();
            $stmtIns = $db->prepare("
                INSERT INTO messages (expediteur_id, destinataire_id, contenu, type_message, est_lu, date_envoi)
                VALUES (?, ?, ?, 'standard', 0, NOW())
            ");
            $success = $stmtIns->execute([$userId, $destinataireId, $finalContenu]);

            echo json_encode(['status' => $success ? 'success' : 'error']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => "Erreur lors de l'envoi."]);
        }
        exit();
    }

    /**
     * Endpoint AJAX : Marque un SOS spécifique comme résolu
     */
    public function resolveSos() {
        header('Content-Type: application/json');
        $userId = $this->getUserId();
        $messageId = (int)($_POST['message_id'] ?? 0);

        if ($userId > 0 && $messageId > 0) {
            $messageModel = method_exists($this, 'model') ? $this->model('Message') : new Message();
            if (method_exists($messageModel, 'resolveSosMessage')) {
                $messageModel->resolveSosMessage($messageId, $userId);
                echo json_encode(['status' => 'success']);
                exit();
            }
        }

        echo json_encode(['status' => 'error', 'message' => 'Impossible de résoudre ce SOS.']);
        exit();
    }
}