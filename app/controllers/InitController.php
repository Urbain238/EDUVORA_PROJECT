<?php

class InitController extends Controller {

    public function index() {
        $db = Database::getConnection();
        $messages = [];

        try {
            // 1. Création de l'Administrateur par défaut
            $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => 'admin@horizon.cm']);
            
            if (!$stmt->fetch()) {
                $password = password_hash('admin123', PASSWORD_BCRYPT);
                $sqlAdmin = "INSERT INTO utilisateurs (nom, prenom, email, password, role, is_premium) 
                            VALUES ('Admin', 'Horizon', 'admin@horizon.cm', :pass, 'admin', TRUE)";
                $db->prepare($sqlAdmin)->execute([':pass' => $password]);
                $messages[] = "✅ Compte Administrateur créé (Email: admin@horizon.cm | MDP: admin123)";
            } else {
                $messages[] = "ℹ️ Le compte Administrateur existe déjà.";
            }

            // 2. Insertion des Sections (Francophone, Anglophone)
            $sections = ['Francophone', 'Anglophone'];
            foreach ($sections as $section) {
                $stmt = $db->prepare("SELECT id FROM sections WHERE nom = :nom");
                $stmt->execute([':nom' => $section]);
                if (!$stmt->fetch()) {
                    $db->prepare("INSERT INTO sections (nom) VALUES (:nom)")->execute([':nom' => $section]);
                    $messages[] = "✅ Section '$section' ajoutée.";
                }
            }

            // 3. Insertion des Classes de base
            $classes = ['6ème', '5ème', '4ème', '3ème', 'Seconde', 'Première', 'Terminale', 'Form 1', 'Form 5', 'Upper Sixth'];
            foreach ($classes as $classe) {
                $stmt = $db->prepare("SELECT id FROM classes WHERE nom = :nom");
                $stmt->execute([':nom' => $classe]);
                if (!$stmt->fetch()) {
                    $db->prepare("INSERT INTO classes (nom) VALUES (:nom)")->execute([':nom' => $classe]);
                    $messages[] = "✅ Classe '$classe' ajoutée.";
                }
            }

            // 4. Insertion des Matières fondamentales
            $matieres = ['Mathématiques', 'Physique-Chimie', 'SVT', 'Français', 'Anglais', 'Histoire-Géographie', 'Philosophie', 'Informatique'];
            foreach ($matieres as $matiere) {
                $stmt = $db->prepare("SELECT id FROM matieres WHERE nom = :nom");
                $stmt->execute([':nom' => $matiere]);
                if (!$stmt->fetch()) {
                    $db->prepare("INSERT INTO matieres (nom) VALUES (:nom)")->execute([':nom' => $matiere]);
                    $messages[] = "✅ Matière '$matiere' ajoutée.";
                }
            }

            $status = "success";

        } catch (PDOException $e) {
            $status = "danger";
            $messages[] = "❌ Erreur BDD : " . $e->getMessage();
        }

        // Affichage du résultat à l'écran
        echo "
        <!DOCTYPE html>
        <html lang='fr'>
        <head>
            <meta charset='UTF-8'>
            <title>Initialisation Horizon</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body class='bg-dark text-white p-5'>
            <div class='container max-w-600'>
                <h2 class='mb-4'>🚀 Initialisation de Horizon BDD</h2>
                <div class='card bg-secondary text-white p-4 mb-4'>
                    <ul class='mb-0'>";
                    foreach ($messages as $msg) {
                        echo "<li class='py-1'>$msg</li>";
                    }
        echo "      </ul>
                </div>
                <a href='" . BASE_URL . "auth/login' class='btn btn-primary btn-lg'>Aller à la page de Connexion</a>
            </div>
        </body>
        </html>";
    }
}