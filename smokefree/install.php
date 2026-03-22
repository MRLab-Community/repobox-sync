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
    $admin_pass = password_hash($_POST['admin_pass'], PASSWORD_DEFAULT);

    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Crea il database se non esiste (potrebbe fallire su hosting shared se non permessi, ma proviamo)
        // Su Altervista spesso si usa il DB già creato dal pannello, quindi usiamo quello selezionato
        $pdo->exec("USE `$name`");

        // Crea tabella utenti
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);

        // Crea tabella dati smokefree
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

        // Inserisci admin
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$admin_user, $admin_pass]);

        // Scrivi config.php
        $config_content = "<?php\ndefine('DB_HOST', '$host');\ndefine('DB_USER', '$user');\ndefine('DB_PASS', '$pass');\ndefine('DB_NAME', '$name');\n";
        file_put_contents('config.php', $config_content);

        $msg = "<div style='color:green'>Installazione completata! File config.php creato. <a href='login.php'>Vai al Login</a></div>";
        $step = 2;
        
        // Sicurezza: rinomina o elimina install.php dopo successo (consigliato farlo manualmente)
    } catch (PDOException $e) {
        $msg = "<div style='color:red'>Errore: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Installazione SmokeFree</title>
<style>body{font-family:sans-serif;max-width:600px;margin:50px auto;padding:20px;border:1px solid #ddd;} input{width:100%;margin:10px 0;padding:10px;}</style>
</head>
<body>
<h1>Installazione SmokeFree</h1>
<p>Benvenuto nell'installer simile a WordPress.</p>
<?php echo $msg; ?>
<?php if($step == 1): ?>
<form method="post">
    <h3>Dati Database (Recuperabili dal pannello Altervista)</h3>
    <input type="text" name="db_host" placeholder="Host (es. sql123.altervista.org)" required>
    <input type="text" name="db_user" placeholder="Username DB" required>
    <input type="password" name="db_pass" placeholder="Password DB" required>
    <input type="text" name="db_name" placeholder="Nome Database" required>
    
    <h3>Crea Account Amministratore</h3>
    <input type="text" name="admin_user" placeholder="Username Admin" required>
    <input type="password" name="admin_pass" placeholder="Password Admin" required>
    
    <button type="submit" style="background:#007cba;color:white;border:none;padding:15px;width:100%;cursor:pointer;">Installa</button>
</form>
<p><small>Dopo l'installazione, cancella il file install.php per sicurezza.</small></p>
<?php endif; ?>
</body>
</html>