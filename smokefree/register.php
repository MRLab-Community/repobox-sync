<?php
session_start();
require 'config.php';
// In produzione, potresti voler disabilitare la registrazione pubblica dopo il primo utente
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user = $_POST['username'];
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$user, $pass]);
        header("Location: login.php");
    } catch (PDOException $e) {
        $error = "Utente esistente o errore DB.";
    }
}
?>
<!-- Simile a login.php ma con form di registrazione -->
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registrati</title>
<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;background:#f0f2f5;} form{background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);width:300px;} input{width:100%;padding:10px;margin:10px 0;box-sizing:border-box;} button{width:100%;padding:10px;background:#2196F3;color:white;border:none;border-radius:4px;cursor:pointer;}</style>
</head>
<body>
<form method="post">
    <h2 style="text-align:center">Nuovo Utente</h2>
    <?php if(isset($error)) echo "<p style='color:red;text-align:center'>$error</p>"; ?>
    <input type="text" name="username" placeholder="Scegli Username" required>
    <input type="password" name="password" placeholder="Scegli Password" required>
    <button type="submit">Registrati</button>
    <p style="text-align:center;font-size:12px;"><a href="login.php">Torna al Login</a></p>
</form>
</body>
</html>