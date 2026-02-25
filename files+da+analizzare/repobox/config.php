<?php
/**
 * config.php - Configurazione centrale con database
 */

// Impostazioni Repobox
define('REPOBOX_PROJECTS_DIR', __DIR__ . '/progetti');
define('REPOBOX_TITLE', '📦 Repobox – Repository Personale');

// Crea cartella progetti se non esiste
if (!is_dir(REPOBOX_PROJECTS_DIR)) {
    mkdir(REPOBOX_PROJECTS_DIR, 0755, true);
}

// 🔐 Configurazione Database (MODIFICA CON I TUOI DATI)
define('DB_HOST', 'localhost'); // Altervista usa 'localhost'
define('DB_NAME', 'my_myprogect'); // Nome del tuo DB
define('DB_USER', 'myprogect'); // Utente DB
define('DB_PASS', 'Gold..79463572'); // Password DB

// Connessione al database
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            die('Errore connessione database: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}