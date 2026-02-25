<?php
session_start();

define('MAX_ATTEMPTS', 3);
define('LOCKOUT_TIME', 900); // 15 minuti

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $host = 'localhost';
        $db = 'my_myprogect';      // <-- MODIFICA
        $user = 'myprogect';  // <-- MODIFICA
        $pass = 'Gold..79463572'; // <-- MODIFICA
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

function isIpLocked($ip) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row && $row['attempts'] >= MAX_ATTEMPTS) {
        $lockoutUntil = strtotime($row['last_attempt']) + LOCKOUT_TIME;
        return time() < $lockoutUntil;
    }
    return false;
}

function recordFailedAttempt($ip) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, attempts FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row) {
        $newAttempts = min($row['attempts'] + 1, MAX_ATTEMPTS);
        $pdo->prepare("UPDATE login_attempts SET attempts = ?, last_attempt = NOW() WHERE id = ?")
            ->execute([$newAttempts, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts) VALUES (?, 1)")
            ->execute([$ip]);
    }
}

function clearAttempts($ip) {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
}

$error = '';
$ip = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isIpLocked($ip)) {
        $error = 'Troppi tentativi. Riprova più tardi.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM repobox_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            clearAttempts($ip);
            $_SESSION['repobox_user_id'] = $user['id'];
            $_SESSION['repobox_username'] = $username;
            header('Location: repo.php');
            exit;
        } else {
            recordFailedAttempt($ip);
            $error = 'Credenziali non valide.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>🔒 Accesso a Repobox</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <header class="site-header">
   <div class="site-logo"> 📦 Repobox </div>
  </header>
  <style>
    body { font-family: sans-serif; background: #f6f8fa; }
    .container { max-width: 400px; margin: auto; background: white; padding: 10% 30px 30px 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h2 { margin-bottom: 20px; color: #24292f; }
    input { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #d1d5da; border-radius: 6px; }
    button { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
    button:hover { background: #1557b0; }
    .error { color: red; margin-bottom: 15px; }
    .info { font-size: 12px; color: #666; margin-top: 15px; }
    .site-footer {
    background-color: #2e808d;
    color: white;
    text-align: center;
    width: 100%;
    position: fixed;
    bottom: 0;
    line-height: 0.2;
}
.site-header {
background-color: #3f8e9b;
    color: white;
    text-align: left;
    padding: 1em 0;
    /* Aggiungi queste proprietà per fissare il footer in fondo alla pagina */
    /* Queste regole potrebbero variare a seconda della struttura HTML, ma sono un buon punto di partenza */
    width: 100%;
    top: 0;
}
.site-logo{
padding-left: 20px;
font-size: 25px;
font-weight: bold;
}
h1 {
    color: #1a73e8;
}
  </style>
</head>
<body>
  <div class="container">
    <h1>📦 Repobox Login</h1>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="text" name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <button type="submit">Accedi</button>
    </form>
    <p class="info">
      ⚠️ Dopo <?= MAX_ATTEMPTS ?> tentativi falliti, l'accesso sarà bloccato per <?= LOCKOUT_TIME / 60 ?> minuti.
    </p>
  </div>
  <footer class="site-footer">
        <p>&copy; <script>document.write(new Date().getFullYear());</script> Repobox - <a href="https://mrlab.altervista.org/" target="_blank" style="text-decoration: none; color: #fff; font-weight: bold;">MRLab Community</a> Tutti i diritti riservati</p>   
    </footer>
</body>
</html>