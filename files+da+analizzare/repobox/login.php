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
  <title>📦 Repobox – Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #f6f8fa;
      color: #24292f;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .header {
      background: #3a90a5;
      color: white;
      padding: 12px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .header img {
      height: 24px;
    }
    .content {
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }
    .card {
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      padding: 32px;
      width: 100%;
      max-width: 400px;
    }
    input {
      width: 93%;
      padding: 12px;
      margin: 8px 0;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }
    button {
      width: 100%;
      padding: 12px;
      background: #1a73e8;
      color: white;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
    }
    button:hover {
      background: #1557b0;
    }
    .footer {
      background: #2c7a85;
      color: white;
      text-align: center;
      padding: 12px;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="header">
    <h1>📦 Repobox</h1>
  </div>

  <div class="content">
    <div class="card">
      <h2>🔑 Repobox Login 🔑</h2>
      <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Accedi</button>
      </form>
      <?php if (isset($_GET['error'])): ?>
        <p style="color:#d32f2f; margin-top:12px;">❌ Credenziali errate</p>
      <?php endif; ?>
      <p style="font-size:12px; color:#666; margin-top:16px;">
        ⚠️ Dopo 3 tentativi falliti, l’accesso sarà bloccato per 15 minuti.
      </p>
    </div>
  </div>

  <div class="footer">
    © 2026 Repobox - MRLab Community • Tutti i diritti riservati
  </div>
</body>
</html>