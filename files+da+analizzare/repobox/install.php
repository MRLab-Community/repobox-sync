<?php
/**
 * install.php - Procedura guidata di installazione per Repobox
 */
session_start();
$error = '';
$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$successMsg = '';

// Se esiste già il file di installazione, blocca l'accesso (opzionale, rimuovi se vuoi reinstallare)
if (file_exists(__DIR__ . '/installed.json')) {
   die('❌ Repobox è già installato. Se vuoi reinstallare, elimina il file <code>installed.json</code>.');
}

$dbConfigFile = __DIR__ . '/config_db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // Passo 1: Configurazione Database
        $dbHost = trim($_POST['db_host']);
        $dbName = trim($_POST['db_name']);
        $dbUser = trim($_POST['db_user']);
        $dbPass = $_POST['db_pass']; // Su Altervista spesso è vuota

        try {
            // Costruzione DSN
            $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
            
            // Tentativo di connessione PDO
            // Nota: Su Altervista a volte serve impostare alcune opzioni specifiche se il server è vecchio, 
            // ma generalmente queste sono standard.
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Aggiungiamo questo per compatibilità massima con hosting condivisi
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);

            // Se arriviamo qui, la connessione è riuscita!
            
            // 1. Crea le tabelle
            $sqlUsers = "CREATE TABLE IF NOT EXISTS repo_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $sqlLogs = "CREATE TABLE IF NOT EXISTS repo_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action VARCHAR(100),
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $sqlSettings = "CREATE TABLE IF NOT EXISTS repo_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $pdo->exec($sqlUsers);
            $pdo->exec($sqlLogs);
            $pdo->exec($sqlSettings);

            // 2. Salva config DB in un file sicuro
            // Usiamo var_export per sicurezza nei caratteri speciali
            $configContent = "<?php\n";
            $configContent .= "// Configurazione Database Generata da Installer\n";
            $configContent .= "define('REPO_DB_HOST', '" . addslashes($dbHost) . "');\n";
            $configContent .= "define('REPO_DB_NAME', '" . addslashes($dbName) . "');\n";
            $configContent .= "define('REPO_DB_USER', '" . addslashes($dbUser) . "');\n";
            $configContent .= "define('REPO_DB_PASS', '" . addslashes($dbPass) . "');\n";
            
            if (file_put_contents($dbConfigFile, $configContent)) {
                $step = 2; // Passa al passo successivo
            } else {
                $error = "Impossibile scrivere il file config_db.php. Controlla i permessi della cartella.";
            }

        } catch (PDOException $e) {
            // Cattura l'errore specifico
            $errorCode = $e->getCode();
            $errorMsg = $e->getMessage();
            
            $error = "Errore DB: $errorMsg";
            
            // Suggerimenti specifici per Altervista
            if (strpos($errorMsg, 'Access denied') !== false) {
                $error .= "<br><strong>Suggerimento:</strong> Su Altervista la password del database è spesso <strong>VUOTA</strong>. Prova a lasciare il campo password vuoto.";
            } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                $error .= "<br><strong>Suggerimento:</strong> Assicurati di aver creato il database '$dbName' dal pannello di controllo Altervista (Sezione MySQL).";
            } elseif (strpos($errorMsg, 'PDO') !== false || strpos($errorMsg, 'constant') !== false) {
                 $error .= "<br><strong>Suggerimento:</strong> Sembra un problema con l'estensione PDO. Anche se dovrebbe essere attiva, prova a cambiare la versione PHP nel pannello (es. da 8.4 a 8.2 o 7.4).";
            }
        }
    } 
    // ... (Il resto del codice per step 2 rimane uguale) ...
    elseif ($step === 2) {
         // Passo 2: Admin e Impostazioni (Codice invariato dal precedente tentativo)
         require_once $dbConfigFile; // Carica il file appena creato
         try {
            $pdo = new PDO("mysql:host=" . REPO_DB_HOST . ";dbname=" . REPO_DB_NAME . ";charset=utf8mb4", REPO_DB_USER, REPO_DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $adminUser = trim($_POST['admin_user']);
            $adminPass = password_hash($_POST['admin_pass'], PASSWORD_DEFAULT);
            
            // Inserisci Admin (con IGNORE per evitare errori se già esiste)
            $stmt = $pdo->prepare("INSERT IGNORE INTO repo_users (username, password_hash, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$adminUser, $adminPass]);

            // Salva Impostazioni (Colori)
            $headerColor = $_POST['header_color'];
            $sidebarColor = $_POST['sidebar_color'];
            
            $stmt = $pdo->prepare("INSERT INTO repo_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->execute(['header_color', $headerColor, $headerColor]);
            $stmt->execute(['sidebar_color', $sidebarColor, $sidebarColor]);

            // Crea file di blocco installazione
            $installData = [
                'installed_at' => date('Y-m-d H:i:s'),
                'version' => '1.0',
                'admin_user' => $adminUser
            ];
            file_put_contents(__DIR__ . '/installed.json', json_encode($installData, JSON_PRETTY_PRINT));

            $step = 3;
        } catch (PDOException $e) {
            $error = "Errore creazione admin: " . $e->getMessage();
        }
    }
}
?>
<!-- IL RESTO DELL'HTML RIMANE UGUALE -->
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>🚀 Installazione Repobox</title>
    <!-- ... (stili CSS invariati) ... -->
    <style>
        body { font-family: -apple-system, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h1 { color: #333; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        input[type="text"], input[type="password"], input[type="color"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #007cba; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #005a87; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 4px; text-align: center; }
        .step-indicator { text-align: center; margin-bottom: 20px; color: #888; font-size: 14px; }
        small { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📦 Installazione Repobox</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="step-indicator">Passo 1 di 2: Configurazione Database</div>
            <form method="POST">
                <input type="hidden" name="step" value="1">
                <div class="form-group">
                    <label>Host Database</label>
                    <input type="text" name="db_host" value="localhost" required>
                    <small>Di solito è <code>localhost</code> su Altervista.</small>
                </div>
                <div class="form-group">
                    <label>Nome Database</label>
                    <input type="text" name="db_name" placeholder="es. my_nomeutente" required>
                    <small>Inserisci il nome esatto del DB creato dal pannello Altervista.</small>
                </div>
                <div class="form-group">
                    <label>Utente Database</label>
                    <input type="text" name="db_user" placeholder="es. nomeutente" required>
                    <small>Il tuo username Altervista (senza 'my_').</small>
                </div>
                <div class="form-group">
                    <label>Password Database</label>
                    <input type="password" name="db_pass" placeholder="Lascia vuoto se non ne hai impostata una">
                    <small><strong>Importante:</strong> Su Altervista la password è spesso <strong>VUOTA</strong>.</small>
                </div>
                <button type="submit">Connetti e Continua ➡️</button>
            </form>

        <?php elseif ($step === 2): ?>
            <div class="step-indicator">Passo 2 di 2: Account Admin & Aspetto</div>
            <form method="POST">
                <input type="hidden" name="step" value="2">
                <div class="form-group">
                    <label>Username Admin</label>
                    <input type="text" name="admin_user" required>
                </div>
                <div class="form-group">
                    <label>Password Admin</label>
                    <input type="password" name="admin_pass" required>
                </div>
                <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">
                <div class="form-group">
                    <label>Colore Header</label>
                    <input type="color" name="header_color" value="#3f8e9b">
                </div>
                <div class="form-group">
                    <label>Colore Sidebar</label>
                    <input type="color" name="sidebar_color" value="#ffffff">
                </div>
                <button type="submit">Completa Installazione 🚀</button>
            </form>

        <?php elseif ($step === 3): ?>
            <div class="success">
                <h2>✅ Installazione Completata!</h2>
                <p>Repobox è pronto all'uso.</p>
                <p style="margin-top:15px; font-size:14px; color:#555;">
                    <strong>Nota di sicurezza:</strong> Per proteggere il sito, ti consigliamo di eliminare il file <code>install.php</code> dal server tramite FTP o File Manager.
                </p>
                <a href="repo.php" style="display:inline-block; margin-top:20px; padding:12px 25px; background:#007cba; color:white; text-decoration:none; border-radius:4px; font-weight:bold;">Vai a Repobox</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>