<?php
session_start();
require 'config.php';

$error = "";
$success = "";

// CONFIGURAZIONE SICUREZZA
$max_registrations_per_hour = 3; // Livello 2: Max tentativi per IP all'ora
$min_form_time = 2; // Livello 4: Minimo secondi per compilare il form (i bot sono istantanei)

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- LIVELLO 4: CONTROLLO TEMPO DI COMPILAZIONE ---
    if (isset($_POST['form_start_time'])) {
        $time_diff = time() - intval($_POST['form_start_time']);
        if ($time_diff < $min_form_time) {
            $error = "Rilevata attività sospetta (compilazione troppo veloce). Accesso negato.";
            // Non eseguiamo nessun'altra operazione per risparmiare risorse
            $blocked_by_speed = true;
        } else {
            $blocked_by_speed = false;
        }
    } else {
        $blocked_by_speed = true; // Mancanza del timestamp = sospetto
        $error = "Errore di validazione del form.";
    }

    // --- LIVELLO 1: HONEYPOT (TRAPPOLA PER BOT) ---
    // Se il campo nascosto 'website_url' è stato compilato, è un bot.
    if (empty($error) && !empty($_POST['website_url'])) {
        $error = "Rilevato comportamento automatico. Registrazione bloccata.";
    }

    // --- LIVELLO 2: RATE LIMITING (CONTROLLO IP) ---
    if (empty($error)) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $now = time();
        $lockout_window = 3600; // 1 ora
        
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Pulizia vecchi record
            $pdo->exec("DELETE FROM registration_attempts WHERE attempt_time < " . ($now - $lockout_window));

            // Conteggio tentativi recenti
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM registration_attempts WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] >= $max_registrations_per_hour) {
                $error = "Troppi tentativi di registrazione da questo indirizzo IP. Riprova tra un'ora.";
            } else {
                // Registra questo tentativo nel DB (anche se fallirà dopo, conta come tentativo)
                $ins = $pdo->prepare("INSERT INTO registration_attempts (ip_address, attempt_time) VALUES (?, ?)");
                $ins->execute([$ip, $now]);
            }
        } catch (PDOException $e) {
            $error = "Errore di connessione al database.";
        }
    }

    // --- ELABORAZIONE DATI (Se i controlli precedenti sono passati) ---
    if (empty($error)) {
        $user = trim($_POST['username']);
        $pass = $_POST['password'];
        $pass_confirm = $_POST['password_confirm'];

        // --- LIVELLO 3: VALIDAZIONE STRICT PASSWORD & INPUT ---
        if (strlen($user) < 4) {
            $error = "Lo username deve avere almeno 4 caratteri.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
            $error = "Lo username può contenere solo lettere, numeri e underscore.";
        } elseif ($pass !== $pass_confirm) {
            $error = "Le password non coincidono.";
        } elseif (strlen($pass) < 8) {
            $error = "La password deve essere lunga almeno 8 caratteri.";
        } elseif (!preg_match('/[A-Z]/', $pass)) {
            $error = "La password deve contenere almeno una lettera maiuscola.";
        } elseif (!preg_match('/[0-9]/', $pass)) {
            $error = "La password deve contenere almeno un numero.";
        } elseif (!preg_match('/[^A-Za-z0-9]/', $pass)) {
            $error = "La password deve contenere almeno un carattere speciale (es. @, #, $, !).";
        }

        // Inserimento nel DB se tutto ok
        if (empty($error)) {
            try {
                // Controllo se utente esiste già
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$user]);
                if ($check->fetch()) {
                    $error = "Questo username è già in uso.";
                } else {
                    // Hash sicuro della password
                    $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
                    
                    // --- LIVELLO 5: SANITIZZAZIONE E INSERT SICURO ---
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$user, $pass_hash]);
                    
                    $success = "Registrazione avvenuta con successo! Verrai reindirizzato al login...";
                    header("Refresh: 3; url=login.php");
                }
            } catch (PDOException $e) {
                $error = "Errore durante la registrazione: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrati - SmokeFree</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
    .reg-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
    h2 { text-align: center; color: #333; margin-top: 0; }
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; font-size: 14px; }
    input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; transition: border 0.3s; }
    input:focus { border-color: #2196F3; outline: none; }
    button { width: 100%; padding: 14px; background: #2196F3; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
    button:hover { background: #1976D2; }
    .error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #ef9a9a; }
    .success { background: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #c8e6c9; }
    .footer { text-align: center; margin-top: 20px; font-size: 13px; color: #666; }
    .footer a { color: #007cba; text-decoration: none; }
    
    /* LIVELLO 1: HONEYPOT CSS */
    /* Nasconde il campo agli umani ma i bot lo compileranno */
    .hp-field { position: absolute; left: -9999px; opacity: 0; height: 0; overflow: hidden; }
    
    .requirements { font-size: 12px; color: #666; background: #f9f9f9; padding: 10px; border-radius: 4px; margin-top: 5px; }
</style>
<script>
    // Imposta il tempo di inizio compilazione al caricamento della pagina
    window.addEventListener('load', function() {
        var startTime = Math.floor(Date.now() / 1000);
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'form_start_time';
        input.value = startTime;
        document.querySelector('form').appendChild(input);
    });
</script>
</head>
<body>

<div class="reg-box">
    <h2>Crea Account</h2>
    
    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="success"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <!-- LIVELLO 1: HONEYPOT FIELD -->
        <!-- I bot compileranno questo campo pensando sia un URL sito web -->
        <input type="text" name="website_url" class="hp-field" tabindex="-1" autocomplete="off">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required placeholder="Solo lettere e numeri">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <div class="requirements">
                Min 8 caratteri, 1 Maiuscola, 1 Numero, 1 Simbolo (@#$!...).
            </div>
        </div>

        <div class="form-group">
            <label for="password_confirm">Conferma Password</label>
            <input type="password" id="password_confirm" name="password_confirm" required>
        </div>

        <button type="submit">Registrati</button>
    </form>

    <div class="footer">
        Hai già un account? <a href="login.php">Accedi qui</a>
    </div>
</div>

</body>
</html>