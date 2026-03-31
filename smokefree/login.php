<?php
session_start();
require 'config.php';

// Configurazione Sicurezza
$max_attempts = 5;
$lockout_time = 900; // 15 minuti

// Gestione Logout
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'];
$now = time();
$error = "";
$blocked = false;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Controllo blocco IP (Compatibile struttura Repobox)
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ? AND last_attempt > (? - ?)");
    $stmt->execute([$ip, $now, $lockout_time]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['attempts'] >= $max_attempts) {
        $wait_time = ceil(($result['last_attempt'] + $lockout_time - $now) / 60);
        $error = "Troppi tentativi falliti. Riprova tra $wait_time minuti.";
        $blocked = true;
    }
} catch (PDOException $e) {
    $error = "Errore DB: " . $e->getMessage();
    $blocked = true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$blocked) {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($pass, $row['password'])) {
            // LOGIN SUCCESSOSO
            
            // Pulisce i tentativi per questo IP
            $del = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $del->execute([$ip]);

            // Rigenera ID sessione per sicurezza
            session_regenerate_id(true);

            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];

            header("Location: index.php");
            exit;
        } else {
            // LOGIN FALLITO
            if (!$blocked) {
                $check = $pdo->prepare("SELECT id FROM login_attempts WHERE ip_address = ?");
                $check->execute([$ip]);
                if ($check->fetch()) {
                    $upd = $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = ? WHERE ip_address = ?");
                    $upd->execute([$now, $ip]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, ?)");
                    $ins->execute([$ip, $now]);
                }
            }
            $error = "Username o password errati.";
        }
    } catch (PDOException $e) {
        $error = "Errore durante il login: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - SmokeFree</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
.login-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.1); width: 100%; max-width: 350px; text-align: center; }
.logo-img { margin-bottom: 15px; }
h2 { text-align: center; color: #333; margin-top: 0; margin-bottom: 25px; }
.form-group { margin-bottom: 20px; text-align: left; }
label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; font-size: 14px; }
input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; transition: border 0.3s; }
input:focus { border-color: #4CAF50; outline: none; }
button { width: 100%; padding: 14px; background: #4CAF50; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
button:hover { background: #45a049; }
button:disabled { background: #ccc; cursor: not-allowed; }
.error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #ef9a9a; }
.footer { text-align: center; margin-top: 25px; font-size: 13px; color: #666; }
.footer a { color: #007cba; text-decoration: none; }
</style>
</head>
<body>
<div class="login-box">
    <!-- LOGO RICHIESTO -->
    <img src="icons/icon-192.png" class="logo-img" style="width: 64px; height: auto;" alt="SmokeFree Logo" />
    
    <h2>Accedi</h2>
    
    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="on">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autocomplete="username" <?php echo $blocked ? 'disabled' : ''; ?>>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password" <?php echo $blocked ? 'disabled' : ''; ?>>
        </div>
        <button type="submit" <?php echo $blocked ? 'disabled' : ''; ?>>Entra</button>
    </form>
    
    <div class="footer">
        <a href="register.php">Crea account</a>
    </div>
</div>
</body>
</html>