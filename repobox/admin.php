<?php
/**
 * admin.php - Pannello di Amministrazione
 */
session_start();

// Controllo sicurezza: deve esistere il file di installazione e l'utente deve essere loggato come admin
if (!file_exists(__DIR__ . '/installed.json')) {
    header('Location: install.php');
    exit;
}
if (!isset($_SESSION['repobox_user_id']) || $_SESSION['repobox_role'] !== 'admin') {
    // Reindirizza al login se non admin (assumendo che tu abbia un login.php, altrimenti adattalo)
    // Per ora, se non c'è sessione, mandiamo al login standard o mostriamo errore
    if (!isset($_SESSION['repobox_user_id'])) {
        die('Accesso negato. Effettua il login come amministratore.');
    }
    die('Accesso negato. Solo gli amministratori possono accedere a questa pagina.');
}

require_once __DIR__ . '/config_db.php'; // Il file creato dall'installer

$pdo = new PDO("mysql:host=" . REPO_DB_HOST . ";dbname=" . REPO_DB_NAME, REPO_DB_USER, REPO_DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ATTR_ERRMODE_EXCEPTION);

$message = '';

// Gestione Azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_settings') {
            $stmt = $pdo->prepare("INSERT INTO repo_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->execute(['header_color', $_POST['header_color'], $_POST['header_color']]);
            $stmt->execute(['sidebar_color', $_POST['sidebar_color'], $_POST['sidebar_color']]);
            $message = '✅ Impostazioni salvate!';
            
            // Log attività
            $logStmt = $pdo->prepare("INSERT INTO repo_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$_SESSION['repobox_user_id'], 'UPDATE_SETTINGS', 'Aggiornati colori tema', $_SERVER['REMOTE_ADDR']]);
        }
        
        if ($_POST['action'] === 'clear_logs') {
            $pdo->exec("TRUNCATE TABLE repo_logs");
            $message = '🗑️ Log svuotati.';
             $logStmt = $pdo->prepare("INSERT INTO repo_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$_SESSION['repobox_user_id'], 'CLEAR_LOGS', 'Svuotati log attività', $_SERVER['REMOTE_ADDR']]);
        }
    }
}

// Recupera Impostazioni
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM repo_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$headerColor = $settings['header_color'] ?? '#3f8e9b';
$sidebarColor = $settings['sidebar_color'] ?? '#ffffff';

// Recupera Log
$logs = $pdo->query("SELECT l.*, u.username FROM repo_logs l LEFT JOIN repo_users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>⚙️ Admin Panel - Repobox</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f6f8fa; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        input[type="color"] { width: 100px; height: 40px; border: none; cursor: pointer; }
        button { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-save { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .alert { padding: 15px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #555; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; background: #eee; }
        .back-link { display: inline-block; margin-bottom: 20px; text-decoration: none; color: #007cba; }
    </style>
</head>
<body>
    <div class="container">
        <a href="repo.php" class="back-link">← Torna a Repobox</a>
        <h1>⚙️ Pannello di Amministrazione</h1>
        
        <?php if ($message): ?>
            <div class="alert"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Sezione Temi -->
        <h2>🎨 Aspetto e Temi</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_settings">
            <div class="form-group">
                <label>Colore Header</label>
                <input type="color" name="header_color" value="<?= htmlspecialchars($headerColor) ?>">
            </div>
            <div class="form-group">
                <label>Colore Sidebar</label>
                <input type="color" name="sidebar_color" value="<?= htmlspecialchars($sidebarColor) ?>">
            </div>
            <button type="submit" class="btn-save">Salva Modifiche</button>
        </form>

        <!-- Sezione Log -->
        <h2>📜 Log delle Attività</h2>
        <div style="text-align: right; margin-bottom: 10px;">
            <form method="POST" style="display:inline;" onsubmit="return confirm('Sei sicuro di voler cancellare tutti i log?');">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="btn-danger">Svuota Log</button>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Data/Ora</th>
                    <th>Utente</th>
                    <th>Azione</th>
                    <th>Dettagli</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= $log['username'] ? htmlspecialchars($log['username']) : '<em>Sistema</em>' ?></td>
                    <td><span class="badge"><?= htmlspecialchars($log['action']) ?></span></td>
                    <td><?= htmlspecialchars($log['details']) ?></td>
                    <td><?= htmlspecialchars($log['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" style="text-align:center; color:#999;">Nessun log presente.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>