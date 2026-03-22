<?php
session_start();
require 'config.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);

// Gestione salvataggio dati
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $date = $_POST['start_date'];
    $cost = $_POST['pack_cost'];
    $count = $_POST['cigs_per_day'];
    
    // Controlla se esiste già un record
    $stmt = $pdo->prepare("SELECT id FROM smoke_data WHERE user_id = ?");
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        $upd = $pdo->prepare("UPDATE smoke_data SET last_cig_date=?, pack_cost=?, cigs_per_day=? WHERE user_id=?");
        $upd->execute([$date, $cost, $count, $user_id]);
    } else {
        $ins = $pdo->prepare("INSERT INTO smoke_data (user_id, last_cig_date, pack_cost, cigs_per_day) VALUES (?, ?, ?, ?)");
        $ins->execute([$user_id, $date, $cost, $count]);
    }
    header("Location: index.php"); exit;
}

// Recupera dati
$stmt = $pdo->prepare("SELECT * FROM smoke_data WHERE user_id = ?");
$stmt->execute([$user_id]);
$data = $stmt->fetch();

$stats = [];
if ($data) {
    $start = new DateTime($data['last_cig_date']);
    $now = new DateTime();
    $diff = $now->diff($start);
    
    $total_days = ($diff->y * 365) + ($diff->m * 30) + $diff->d;
    $total_hours = ($total_days * 24) + $diff->h;
    $saved_money = $total_days * ($data['pack_cost'] * ($data['cigs_per_day'] / 20)); // Stima pacchetti
    
    $stats = [
        'days' => $total_days,
        'hours' => $total_hours,
        'money' => number_format($saved_money, 2),
        'cigs_avoided' => $total_days * $data['cigs_per_day']
    ];
}

// Benefici Medici (Fonte: AISM, OMS, CDC)
$benefits = [
    ["time" => 0.33, "text" => "Dopo 20 minuti: Battito cardiaco e pressione tornano normali."],
    ["time" => 8, "text" => "Dopo 8 ore: Livelli di ossigeno nel sangue normali, monossido di carbonio dimezzato."],
    ["time" => 24, "text" => "Dopo 24 ore: Il monossido di carbonio è eliminato. I polmoni iniziano a liberarsi del muco."],
    ["time" => 48, "text" => "Dopo 48 ore: La nicotina è eliminata. Gusto e olfatto migliorano."],
    ["time" => 72, "text" => "Dopo 3 giorni: Respirare diventa più facile. I bronchi si rilassano."],
    ["time" => 14, "text" => "Dopo 2 settimane: La circolazione migliora sensibilmente."],
    ["time" => 30, "text" => "Dopo 1 mese: Funzione polmonare aumentata fino al 30%."],
    ["time" => 180, "text" => "Dopo 6 mesi: Tosse e affanno diminuiscono drasticamente."],
    ["time" => 365, "text" => "Dopo 1 anno: Rischio di malattie coronariche dimezzato rispetto a un fumatore."],
    ["time" => 1825, "text" => "Dopo 5 anni: Rischio di ictus pari a quello di un non fumatore."]
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
<title>SmokeFree Tracker</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#4CAF50">
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f4f9; margin: 0; padding: 20px; color: #333; }
    .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center; }
    .stat-number { font-size: 2.5em; font-weight: bold; color: #4CAF50; margin: 10px 0; }
    .stat-label { font-size: 0.9em; color: #666; text-transform: uppercase; letter-spacing: 1px; }
    .timeline { text-align: left; }
    .milestone { padding: 15px; border-left: 4px solid #ddd; margin-bottom: 10px; background: #fafafa; transition: all 0.3s; }
    .milestone.active { border-left-color: #4CAF50; background: #e8f5e9; font-weight: bold; }
    .milestone.locked { opacity: 0.6; }
    form { display: flex; flex-direction: column; gap: 10px; }
    input, button { padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; }
    button { background: #4CAF50; color: white; border: none; cursor: pointer; font-weight: bold; }
    .logout { position: absolute; top: 10px; right: 10px; font-size: 12px; color: #666; text-decoration: none; }
</style>
</head>
<body>
<a href="login.php?logout=1" class="logout">Esci</a>

<h1 style="text-align:center; color:#4CAF50;">SmokeFree</h1>

<?php if (!$data): ?>
<div class="card">
    <h3>Inizia il tuo viaggio</h3>
    <form method="post">
        <label>Data ultima sigaretta:</label>
        <input type="datetime-local" name="start_date" required>
        <label>Costo medio pacchetto (€):</label>
        <input type="number" step="0.01" name="pack_cost" required>
        <label>Sigarette al giorno:</label>
        <input type="number" name="cigs_per_day" required>
        <button type="submit">Salva e Inizia</button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <div class="stat-number"><?php echo $stats['days']; ?></div>
    <div class="stat-label">Giorni senza fumo</div>
    <div style="margin-top:15px; font-size:1.2em;">💰 Risparmiati: <strong>€ <?php echo $stats['money']; ?></strong></div>
    <div style="font-size:0.9em; color:#666;">Sigarette evitate: <?php echo $stats['cigs_avoided']; ?></div>
</div>

<div class="card timeline">
    <h3>I tuoi progressi di salute</h3>
    <?php 
    $current_hours = $stats['hours'];
    foreach($benefits as $b): 
        $is_active = ($current_hours >= $b['time']);
        $class = $is_active ? 'active' : 'locked';
    ?>
    <div class="milestone <?php echo $class; ?>">
        <?php echo $b['text']; ?>
        <?php if(!$is_active) echo " <small>(Mancano circa " . round($b['time'] - $current_hours, 1) . " ore)</small>"; ?>
    </div>
    <?php endforeach; ?>
</div>

<button onclick="resetData()" style="background:#f44336; margin-top:20px; width:100%;">Reimposta Dati</button>
<?php endif; ?>

<script>
    // Registrazione Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').then(() => console.log('SW registrato'));
    }
    function resetData() {
        if(confirm("Sei sicuro? Perderai lo storico.")) {
            // Logica per resettare (richiederebbe un'altra action PHP o JS che chiama API)
            // Per semplicità qui si può reindirizzare o svuotare il DB via AJAX
            alert("Funzione da implementare con chiamata AJAX a un file reset.php");
        }
    }
</script>
</body>
</html>