<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user = $_POST['username'];
    $pass = $_POST['password'];

    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch();

        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Credenziali errate.";
        }
    } catch (PDOException $e) {
        $error = "Errore DB: " . $e->getMessage();
    }
}
?>
<!-- HTML Form Login -->
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login SmokeFree</title>
<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;background:#f0f2f5;} form{background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);width:300px;} input{width:100%;padding:10px;margin:10px 0;box-sizing:border-box;} button{width:100%;padding:10px;background:#4CAF50;color:white;border:none;border-radius:4px;cursor:pointer;}</style>
</head>
<body>
<form method="post">
    <h2 style="text-align:center">Accedi</h2>
    <?php if(isset($error)) echo "<p style='color:red;text-align:center'>$error</p>"; ?>
    <input type="text" name="username" placeholder="Username" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit">Entra</button>
    <p style="text-align:center;font-size:12px;"><a href="register.php">Primo accesso? Registrati</a></p>
</form>
</body>
</html> 