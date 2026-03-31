<?php
// install.php
session_start();
$msg = "";
$step = 1;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $host = $_POST['db_host'];
    $user = $_POST['db_user'];
    $pass = $_POST['db_pass'];
    $name = $_POST['db_name'];
    $admin_user = $_POST['admin_user'];
    // Criptiamo subito la password
    $admin_pass = password_hash($_POST['admin_pass'], PASSWORD_DEFAULT);

    try {
        // Connessione al server (senza selezionare il DB ancora)
        $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Selezioniamo il database
        $pdo->exec("USE `$name`");

        // 1. Crea tabella utenti
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);

        // 2. Crea tabella dati smokefree
        $sql = "CREATE TABLE IF NOT EXISTS smoke_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            last_cig_date DATETIME NOT NULL,
            pack_cost DECIMAL(10,2) NOT NULL,
            cigs_per_day INT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $pdo->exec($sql);

        // 3. Inserisci admin
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$admin_user, $admin_pass]);

        // 4. Scrivi config.php
        $config_content = "<?php\n";
        $config_content .= "define('DB_HOST', '$host');\n";
        $config_content .= "define('DB_USER', '$user');\n";
        $config_content .= "define('DB_PASS', '$pass');\n";
        $config_content .= "define('DB_NAME', '$name');\n";
        
        if (file_put_contents('config.php', $config_content)) {
            $msg = "<div style='color:green; border:1px solid green; padding:10px; background:#eaffea;'>Installazione completata con successo!<br>File <strong>config.php</strong> creato.<br><br><a href='login.php' style='font-weight:bold;'>Clicca qui per andare al Login</a></div>";
            $step = 2;
        } else {
            throw new Exception("Impossibile scrivere il file config.php. Controlla i permessi della cartella.");
        }

    } catch (PDOException $e) {
        $msg = "<div style='color:red; border:1px solid red; padding:10px; background:#ffeaea;'>Errore Database: " . htmlspecialchars($e->getMessage()) . "</div>";
    } catch (Exception $e) {
        $msg = "<div style='color:red; border:1px solid red; padding:10px; background:#ffeaea;'>Errore Generico: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione SmokeFree</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background: white; max-width: 500px; width: 100%; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; margin-top: 0; }
        p { color: #666; line-height: 1.5; }
        label { display: block; margin-top: 15px; font-weight: bold; color: #444; }
        input[type="text"], input[type="password"], input[type="number"] { width: 100%; padding: 12px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 15px; margin-top: 25px; background: #007cba; color: white; border: none; border-radius: 4px; font-size: 18px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #005a87; }
        .note { font-size: 12px; color: #888; margin-top: 20px; text-align: center; border-top: 1px solid #eee; padding-top: 10px; }
        .error-box { background: #ffeaea; color: #d63030; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #ffcccc; }
        .success-box { background: #eaffea; color: #2e7d32; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c8e6c9; }
    </style>
</head>
<body>

<div class="container">
    <h1>Installazione SmokeFree</h1>
    <p>Benvenuto! Configura il database per avviare l'applicazione.</p>

    <?php echo $msg; ?>

    <?php if ($step == 1): ?>
    <form method="post">
        <label>Host Database (es. sqlXXX.altervista.org)</label>
        <input type="text" name="db_host" required placeholder="Inserisci l'host MySQL">

        <label>Username Database</label>
        <input type="text" name="db_user" required placeholder="Il tuo utente MySQL">

        <label>Password Database</label>
        <input type="password" name="db_pass" required placeholder="La tua password MySQL">

        <label>Nome Database</label>
        <input type="text" name="db_name" required placeholder="Il nome del DB creato su Altervista">

        <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">
        <h3 style="text-align:center; color:#007cba;">Account Amministratore</h3>

        <label>Username Admin</label>
        <input type="text" name="admin_user" required placeholder="Scegli un nome utente">

        <label>Password Admin</label>
        <input type="password" name="admin_pass" required placeholder="Scegli una password sicura">

        <button type="submit">Installa Ora</button>
    </form>
    
    <p class="note">
        <strong>Nota:</strong> Dopo l'installazione riuscita, cancella manualmente il file <code>install.php</code> dal server per sicurezza.
    </p>
    <?php endif; ?>
</div>

</body>
</html>