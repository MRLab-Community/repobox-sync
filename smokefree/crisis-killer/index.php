<?php
// /smokefree/crisis-killer/index.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once __DIR__ . '/../config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$user_id = $_SESSION['user_id'];

$sql = "SELECT u.username, u.total_crises, u.crises_won,
               s.last_cig_date, s.pack_cost, s.cigs_per_day
        FROM users u
        LEFT JOIN smoke_data s ON u.id = s.user_id
        WHERE u.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Utente non trovato.");
}
$row = $result->fetch_assoc();

$name = htmlspecialchars($row['username']);
$crises_won = intval($row['crises_won']);
$days_free = 0;
$money_saved = 0.00;

if ($row['last_cig_date']) {
    $last_cig = new DateTime($row['last_cig_date']);
    $now = new DateTime();
    $diff = $now->diff($last_cig);
    $days_free = $diff->days;
    
    $cigs_per_day = floatval($row['cigs_per_day']);
    $pack_cost = floatval($row['pack_cost']);
    
    if ($cigs_per_day > 0 && $pack_cost > 0) {
        $money_saved = ($days_free * $cigs_per_day / 20) * $pack_cost;
    }
}

$hours_free = $days_free * 24;

$js_data = [
    'name' => $name,
    'days' => $days_free,
    'money' => round($money_saved, 2),
    'hours' => $hours_free,
    'crises_won' => $crises_won
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    if ($_POST['action'] === 'save_success') {
        $update_sql = "UPDATE users SET total_crises = total_crises + 1, crises_won = crises_won + 1 WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $user_id);
        
        if ($update_stmt->execute()) {
            $check_sql = "SELECT crises_won FROM users WHERE id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $new_val = $check_stmt->get_result()->fetch_assoc()['crises_won'];
            echo json_encode(['status' => 'success', 'new_won' => $new_val]);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Smokefree SOS - Zona Sicura</title>
<style>
    :root {
        --bg-color: #0f172a;
        --text-color: #f1f5f9;
        --accent-green: #10b981;
        --font-main: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    body, html {
        margin: 0; padding: 0; width: 100%; height: 100%;
        background-color: var(--bg-color);
        color: var(--text-color);
        font-family: var(--font-main);
        overflow: hidden;
        display: flex; justify-content: center; align-items: center;
        text-align: center;
    }

    #app-container {
        width: 100%; height: 100%;
        position: relative;
        display: flex; flex-direction: column;
        justify-content: center; align-items: center;
        padding: 20px; box-sizing: border-box;
    }

    .phase {
        display: none; width: 100%; max-width: 600px;
        flex-direction: column; align-items: center; justify-content: center;
        animation: fadeIn 1s ease;
        height: 100%;
    }
    .phase.active { display: flex; }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* --- TESTI DINAMICI CENTRALI --- */
    #dynamic-text-area, #breath-intro-text, #snake-intro-text {
        font-size: 1.8rem; font-weight: 700; line-height: 1.4;
        min-height: 120px; display: flex; align-items: center; justify-content: center;
        padding: 20px; 
        opacity: 1;
        transition: opacity 1s ease-in-out;
        color: white;
    }

    /* Pulsanti Bassi e Discreti */
    .bottom-controls {
        position: absolute; bottom: 40px;
        display: flex; gap: 40px;
        opacity: 0; pointer-events: none;
        transition: opacity 1.5s ease;
        z-index: 100;
    }
    .bottom-controls.visible { opacity: 1; pointer-events: all; }

    .discrete-btn {
        background: transparent; border: none;
        color: #94a3b8; cursor: pointer;
        display: flex; flex-direction: column; align-items: center;
        gap: 8px; transition: transform 0.2s, color 0.3s;
    }
    .discrete-btn:hover { color: white; transform: translateY(-3px); }
    .discrete-btn img { width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5)); }
    .discrete-btn span { font-size: 0.85rem; font-weight: 500; }

    /* --- FASE 2: RESPIRAZIONE --- */
    .breath-wrapper {
        position: relative;
        width: 220px;
        height: 220px;
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 20px auto;
        opacity: 0; 
        transition: opacity 1.5s ease;
        transform: scale(0.9);
    }
    .breath-wrapper.visible { opacity: 1; transform: scale(1); }

    .breath-circle {
        position: absolute;
        width: 100%; height: 100%;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(16,185,129,0.6) 0%, rgba(15,23,42,0.8) 70%);
        box-shadow: 0 0 40px rgba(16,185,129,0.2);
        z-index: 1;
        transition: transform 4s ease-in-out, box-shadow 4s ease-in-out;
        transform: scale(1);
    }

    .breath-text-container {
        position: relative; z-index: 2;
        width: 100%; height: 100%;
        display: flex; justify-content: center; align-items: center;
        pointer-events: none;
    }

    .breath-text {
        font-size: 1.4rem; color: white;
        opacity: 0; 
        transition: opacity 1s ease;
        text-shadow: 0 2px 4px rgba(0,0,0,0.8);
        padding: 20px; 
        font-weight: 600;
        position: absolute;
        width: 100%;
    }
    .breath-text.visible { opacity: 1; }

    /* --- FASE 3: SNAKE --- */
    #phase-snake {
        justify-content: flex-start; padding-top: 20px;
    }
    #phase-snake h3 {
        margin-top: 0; font-weight: 300; color: #94a3b8; flex-shrink: 0;
    }

    #game-container {
        position: relative; margin: 15px auto;
        border: 2px solid #334155; border-radius: 8px; overflow: hidden;
        background: #000; touch-action: none;
        flex-shrink: 0;
        opacity: 0; transition: opacity 1s ease;
    }
    #game-container.visible { opacity: 1; }
    
    canvas { display: block; }
    #snake-score { margin-top: 10px; font-size: 1.1rem; color: var(--accent-green); flex-shrink: 0; opacity: 0; transition: opacity 1s; }
    #snake-score.visible { opacity: 1; }

    /* Joystick */
    .snake-controls {
        display: grid;
        grid-template-columns: repeat(3, 60px);
        grid-template-rows: repeat(2, 60px);
        gap: 10px;
        justify-content: center;
        margin: 15px auto;
        user-select: none; -webkit-user-select: none;
        flex-shrink: 0;
        opacity: 0; transition: opacity 1s ease;
    }
    .snake-controls.visible { opacity: 1; }

    .ctrl-btn {
        background: rgba(255, 255, 255, 0.1);
        border: 2px solid #334155; border-radius: 12px;
        color: #94a3b8; font-size: 24px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all 0.1s;
        touch-action: manipulation;
    }
    .ctrl-btn:active, .ctrl-btn.active {
        background: var(--accent-green);
        border-color: var(--accent-green); color: white;
        transform: scale(0.95);
    }
    .btn-up { grid-column: 2; grid-row: 1; }
    .btn-left { grid-column: 1; grid-row: 2; }
    .btn-down { grid-column: 2; grid-row: 2; }
    .btn-right { grid-column: 3; grid-row: 2; }

    @media (min-width: 768px) {
        .snake-controls { display: none !important; }
    }

    .snake-final-btn-container {
        margin-top: auto; margin-bottom: 20px;
        width: 100%; display: flex; justify-content: center;
        flex-shrink: 0;
    }

    /* Success Overlay */
    #success-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(5px);
        z-index: 2000; display: none;
        flex-direction: column; justify-content: center; align-items: center;
    }
    .thumbs-up { font-size: 6rem; margin-bottom: 20px; animation: popUp 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    @keyframes popUp { 0% { transform: scale(0); } 80% { transform: scale(1.2); } 100% { transform: scale(1); } }
    
    .home-btn {
        margin-top: 30px; padding: 12px 30px;
        background: var(--accent-green); color: white;
        border: none; border-radius: 30px; font-size: 1rem; font-weight: bold;
        cursor: pointer; text-decoration: none; display: inline-block;
        box-shadow: 0 4px 15px rgba(16,185,129,0.4);
    }

    /* --- AUDIO CONTROLS FLOATING --- */
    .audio-controls {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 3000;
        background: rgba(30, 41, 59, 0.8);
        backdrop-filter: blur(5px);
        padding: 8px 12px;
        border-radius: 30px;
        border: 1px solid rgba(255,255,255,0.1);
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        cursor: default;
    }

    .audio-label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #cbd5e1;
        cursor: pointer;
        user-select: none;
    }
    .audio-label:hover { color: white; }

    .audio-icon-btn {
        background: none;
        border: none;
        color: #94a3b8;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 4px;
        transition: color 0.3s, transform 0.2s;
        display: flex; align-items: center; justify-content: center;
    }
    .audio-icon-btn:hover { color: white; transform: scale(1.1); }
    .audio-icon-btn.active { color: var(--accent-green); }

    .audio-options {
        position: absolute;
        top: 50px;
        right: 0;
        display: none;
        flex-direction: column;
        gap: 8px;
        width: 180px;
        background: rgba(15, 23, 42, 0.95);
        padding: 15px;
        border-radius: 12px;
        border: 1px solid rgba(255,255,255,0.1);
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        z-index: 3001;
    }
    .audio-options.show { display: flex; }

    .audio-option {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        color: #cbd5e1;
        padding: 10px;
        border-radius: 8px;
        font-size: 0.9rem;
        cursor: pointer;
        text-align: left;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.2s;
    }
    .audio-option:hover { background: rgba(255,255,255,0.1); }
    .audio-option.selected {
        background: rgba(16, 185, 129, 0.2);
        border-color: var(--accent-green);
        color: var(--accent-green);
        font-weight: 600;
    }

    .volume-slider {
        width: 100%;
        height: 4px;
        background: rgba(255,255,255,0.2);
        border-radius: 2px;
        outline: none;
        -webkit-appearance: none;
        margin-top: 5px;
    }
    .volume-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 14px;
        height: 14px;
        background: var(--accent-green);
        border-radius: 50%;
        cursor: pointer;
    }

    /* --- WELCOME MODAL (POPUP) --- */
    #welcome-modal {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.98);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-direction: column;
        padding: 20px;
        text-align: center;
        backdrop-filter: blur(10px);
    }
    
    .modal-content {
        background: rgba(30, 41, 59, 0.9);
        padding: 40px;
        border-radius: 20px;
        border: 1px solid rgba(16, 185, 129, 0.3);
        max-width: 500px;
        box-shadow: 0 0 50px rgba(16, 185, 129, 0.1);
        animation: modalPop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes modalPop {
        0% { transform: scale(0.8); opacity: 0; }
        100% { transform: scale(1); opacity: 1; }
    }

    .modal-title {
        font-size: 2rem;
        color: var(--accent-green);
        margin-bottom: 15px;
        font-weight: 700;
    }

    .modal-text {
        font-size: 1.1rem;
        color: #cbd5e1;
        margin-bottom: 25px;
        line-height: 1.6;
    }

    .sound-selection {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }

    .sound-btn {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.2);
        color: #cbd5e1;
        padding: 12px 20px;
        border-radius: 12px;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.3s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        min-width: 100px;
    }
    .sound-btn:hover {
        background: rgba(255,255,255,0.1);
        transform: translateY(-2px);
    }
    .sound-btn.selected {
        background: rgba(16, 185, 129, 0.2);
        border-color: var(--accent-green);
        color: var(--accent-green);
        font-weight: bold;
        box-shadow: 0 0 15px rgba(16, 185, 129, 0.3);
    }
    .sound-icon { font-size: 1.5rem; }
    .sound-playing-indicator {
        font-size: 0.7rem;
        color: var(--accent-green);
        font-weight: bold;
        display: none;
    }
    .sound-btn.selected .sound-playing-indicator { display: block; }

    .modal-enter-btn {
        background: var(--accent-green);
        color: white;
        border: none;
        padding: 15px 40px;
        font-size: 1.2rem;
        font-weight: bold;
        border-radius: 50px;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        width: 100%;
    }
    .modal-enter-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.6);
    }
    
    .modal-hint {
        margin-top: 15px;
        font-size: 0.85rem;
        color: #64748b;
        font-style: italic;
    }
</style>
</head>
<body>

<!-- WELCOME MODAL -->
<div id="welcome-modal">
    <div class="modal-content">
        <div class="modal-title">Benvenuto nella Zona Sicura</div>
        <p class="modal-text">
            Scegli un suono rilassante per iniziare. Clicca per ascoltare l'anteprima.<br>
            Potrai cambiare o spegnere l'audio in qualsiasi momento.
        </p>
        
        <div class="sound-selection">
            <div class="sound-btn" id="btn-rain" onclick="selectSound('rain', this)">
                <span class="sound-icon">🌧️</span>
                <span>Pioggia</span>
                <span class="sound-playing-indicator">● In riproduzione</span>
            </div>
            <div class="sound-btn" id="btn-ambient" onclick="selectSound('ambient', this)">
                <span class="sound-icon">🌿</span>
                <span>Ambient</span>
                <span class="sound-playing-indicator">● In riproduzione</span>
            </div>
            <div class="sound-btn" id="btn-drone" onclick="selectSound('drone', this)">
                <span class="sound-icon">🧘</span>
                <span>Meditazione</span>
                <span class="sound-playing-indicator">● In riproduzione</span>
            </div>
        </div>

        <button class="modal-enter-btn" onclick="enterSite()">Entra e Inizia</button>
        <div class="modal-hint">Consiglio: usa le cuffie per un'esperienza immersiva.</div>
    </div>
</div>

<div id="app-container">

    <!-- AUDIO CONTROLS -->
    <div class="audio-controls">
        <span class="audio-label" onclick="toggleAudioMenu()">Audio</span>
        <button class="audio-icon-btn" id="audioIconBtn" onclick="toggleMute()" title="Silenzia/Riattiva">
            🔊
        </button>
        
        <div class="audio-options" id="audioOptions">
            <div class="audio-option selected" id="opt-rain" onclick="changeAudio('rain', this)">
                <span>🌧️</span> Pioggia
            </div>
            <div class="audio-option" id="opt-ambient" onclick="changeAudio('ambient', this)">
                <span>🌿</span> Ambient
            </div>
            <div class="audio-option" id="opt-drone" onclick="changeAudio('drone', this)">
                <span>🧘</span> Meditazione
            </div>
            <input type="range" min="0" max="1" step="0.01" value="0.5" class="volume-slider" id="volumeSlider" oninput="setVolume(this.value)">
            <div style="font-size:0.7rem; color:#64748b; text-align:center; margin-top:2px;">Volume</div>
        </div>
    </div>

    <!-- ELEMENTO AUDIO HIDDEN -->
    <audio id="bg-audio" loop preload="auto"></audio>

    <!-- FASE 1: COACH -->
    <div id="phase-coach" class="phase active">
        <div id="dynamic-text-area">Calma <?php echo $name; ?>...</div>
        
        <div id="coach-actions" class="bottom-controls">
            <button class="discrete-btn" onclick="goToBreathingIntro()">
                <img src="../icons/lifebuoy.png" alt="Aiuto">
                <span>Non Passa</span>
            </button>
            <button class="discrete-btn" onclick="triggerSuccess(false)">
                <img src="../icons/win.png" alt="Vittoria">
                <span>E' Passata</span>
            </button>
        </div>
    </div>

    <!-- FASE 2: RESPIRAZIONE -->
    <div id="phase-breath" class="phase">
        <div id="breath-intro-text"></div>
        
        <div id="breath-wrapper" class="breath-wrapper">
            <div id="breath-circle" class="breath-circle"></div>
            <div class="breath-text-container">
                <div id="breath-instruction" class="breath-text"></div>
            </div>
        </div>
        
        <div id="breath-actions" class="bottom-controls" style="bottom: 30px;">
            <button class="discrete-btn" onclick="goToSnakeIntro()">
                <img src="../icons/lifebuoy.png" alt="Gioco">
                <span>Attiva gioco</span>
            </button>
            <button class="discrete-btn" onclick="triggerSuccess(false)">
                <img src="../icons/win.png" alt="Vittoria">
                <span>Ora sto bene</span>
            </button>
        </div>
    </div>

    <!-- FASE 3: SNAKE -->
    <div id="phase-snake" class="phase">
        <div id="snake-intro-text"></div>

        <h3 id="snake-title" style="opacity:0; transition:opacity 1s;">Distraiti. Respira. Muoviti.</h3>
        
        <div id="game-container">
            <canvas id="game-canvas" width="300" height="300"></canvas>
        </div>
        <div id="snake-score">Punti: 0</div>

        <div class="snake-controls" id="snake-joystick">
            <div class="ctrl-btn btn-up" id="btn-up">▲</div>
            <div class="ctrl-btn btn-left" id="btn-left">◀</div>
            <div class="ctrl-btn btn-down" id="btn-down">▼</div>
            <div class="ctrl-btn btn-right" id="btn-right">▶</div>
        </div>
        
        <div class="snake-final-btn-container">
            <button class="discrete-btn" onclick="triggerSuccess(false)">
                <img src="../icons/win.png" alt="Fine">
                <span>Ho vinto io</span>
            </button>
        </div>
    </div>

    <!-- SUCCESS OVERLAY -->
    <div id="success-overlay">
        <div class="thumbs-up">👍</div>
        <h2 id="success-msg" style="color: var(--accent-green); padding: 0 20px;">Sei un eroe!</h2>
        <p id="success-stats" style="color: #cbd5e1; margin-top: 10px; font-size: 1.1rem;"></p>
        <a href="../index.php" class="home-btn">Torna alla home</a>
    </div>

</div>

<script>
    const userData = <?php echo json_encode($js_data); ?>;
    let phraseTimer, actionTimeout;
    let snakeInterval = null;
    let currentPhraseIndex = 0;
    let dx = 0, dy = 0, changingDirection = false;
    const box = 20;

    // --- CONFIGURAZIONE AUDIO (LINK ALTERVISTA CORRETTI) ---
    const audioSources = {
        rain: 'https://myprogect.altervista.org/smokefree/sounds/light-rain-nature-sounds.mp3',
        ambient: 'https://myprogect.altervista.org/smokefree/sounds/ambient-minecraft.mp3',
        drone: 'https://myprogect.altervista.org/smokefree/sounds/Om-Meditation.mp3'
    };

    const bgAudio = document.getElementById('bg-audio');
    let isAudioPlaying = false;
    let currentTrack = null; // Null finché l'utente non ne sceglie uno
    let fadeInterval = null;
    let targetVolume = 0.5;

    function initAudio() {
        // Non impostiamo src qui, lo facciamo solo quando l'utente sceglie
        bgAudio.loop = true;
        bgAudio.volume = 0;
    }

    // Selezione dal Popup (con anteprima immediata)
    function selectSound(track, element) {
        // Aggiorna UI Popup
        document.querySelectorAll('.sound-btn').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        
        // Aggiorna anche il menu in alto se esiste
        updateMenuSelection(track);

        // Se è la stessa traccia, non fare nulla (già in play)
        if (currentTrack === track && isAudioPlaying) return;

        currentTrack = track;
        
        // Avvia audio immediatamente con fade-in VELOCE per anteprima (1s)
        playAudioWithFade(1000); 
    }

    function updateMenuSelection(track) {
        document.querySelectorAll('.audio-option').forEach(el => el.classList.remove('selected'));
        const opt = document.getElementById('opt-' + track);
        if(opt) opt.classList.add('selected');
    }

    // Funzione chiamata dal pulsante "Entra"
    function enterSite() {
        const modal = document.getElementById('welcome-modal');
        
        // Fade out del modale
        modal.style.transition = "opacity 0.8s ease";
        modal.style.opacity = 0;
        
        setTimeout(() => {
            modal.style.display = 'none';
            
            // Se l'utente non ha scelto nessun suono, partiamo col default (rain) ma muto finché non alza volume?
            // Meglio: se non ha scelto, non partiamo. Se ha scelto, continuiamo.
            if (!currentTrack) {
                // Nessuna scelta fatta: impostiamo rain ma non partiamo automaticamente per non spaventare
                currentTrack = 'rain';
                updateMenuSelection('rain');
                // L'utente dovrà attivare audio dal menu o alzare volume
            } else {
                // Già in riproduzione dal popup: assicuriamoci che il fade-in sia completato al volume target
                // Il play è già attivo, lasciamo fare.
            }
            
            // AVVIO AUTOMATICO DELLA SEQUENZA TESTI
            startCoachPhase();
            
        }, 800);
    }

    function playAudioWithFade(durationMs = 5000) {
        if (!currentTrack) return;

        // Forza ricaricamento sorgente se cambia
        const currentSrc = bgAudio.src;
        const newSrc = audioSources[currentTrack];
        
        if (currentSrc !== newSrc) {
            bgAudio.pause();
            bgAudio.src = newSrc;
            bgAudio.load(); // Importante per caricare il nuovo file
        }

        bgAudio.play().then(() => {
            isAudioPlaying = true;
            updateAudioIcon();
            
            // Fade In
            let vol = 0;
            bgAudio.volume = 0;
            const startTime = Date.now();
            
            if(fadeInterval) clearInterval(fadeInterval);
            
            fadeInterval = setInterval(() => {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(elapsed / durationMs, 1);
                
                // Curva ease-out per fade più naturale
                const easeProgress = 1 - Math.pow(1 - progress, 3);
                
                vol = targetVolume * easeProgress;
                bgAudio.volume = vol;
                
                if(document.getElementById('volumeSlider')) {
                    document.getElementById('volumeSlider').value = vol;
                }

                if (progress >= 1) {
                    clearInterval(fadeInterval);
                }
            }, 50);
            
        }).catch(error => {
            console.log("Autoplay bloccato o errore sorgente:", error);
            isAudioPlaying = false;
            updateAudioIcon();
            alert("Impossibile riprodurre l'audio. Controlla la connessione o i permessi del browser.");
        });
    }

    function changeAudioInternal(track) {
        if (currentTrack === track) return;
        currentTrack = track;
        
        // Crossfade: abbassa volume, cambia, rialza
        let originalVol = bgAudio.volume;
        targetVolume = originalVol; // Mantieni lo stesso volume target
        
        // Fade out veloce
        let fadeOut = setInterval(() => {
            if(bgAudio.volume > 0.05) {
                bgAudio.volume -= 0.05;
            } else {
                clearInterval(fadeOut);
                bgAudio.pause();
                bgAudio.src = audioSources[track];
                bgAudio.load();
                if(isAudioPlaying) {
                    bgAudio.play().then(() => {
                        // Fade in
                        let vol = 0;
                        let fadeIn = setInterval(() => {
                            if(vol < targetVolume) {
                                vol += 0.02;
                                if(vol > targetVolume) vol = targetVolume;
                                bgAudio.volume = vol;
                                if(document.getElementById('volumeSlider')) document.getElementById('volumeSlider').value = vol;
                            } else {
                                clearInterval(fadeIn);
                            }
                        }, 50);
                    });
                }
            }
        }, 50);
    }

    // Funzioni UI Menu
    function toggleAudioMenu() {
        const menu = document.getElementById('audioOptions');
        menu.classList.toggle('show');
    }

    function changeAudio(track, element) {
        // Aggiorna UI Menu
        document.querySelectorAll('.audio-option').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        
        // Aggiorna anche popup se visibile (opzionale)
        const popupBtn = document.getElementById('btn-' + track);
        if(popupBtn) {
            document.querySelectorAll('.sound-btn').forEach(el => el.classList.remove('selected'));
            popupBtn.classList.add('selected');
        }

        changeAudioInternal(track);
        
        // Chiudi menu dopo selezione
        setTimeout(() => {
            document.getElementById('audioOptions').classList.remove('show');
        }, 500);
    }

    function toggleMute() {
        if (isAudioPlaying && bgAudio.volume > 0.01) {
            // Mute: salva volume attuale e metti a 0
            targetVolume = bgAudio.volume;
            bgAudio.volume = 0;
            isAudioPlaying = false; // Tecnicamente è paused nel perceived sense
        } else {
            // Unmute: riprendi volume
            if (!isAudioPlaying && bgAudio.paused) {
                 bgAudio.play().catch(e => console.log(e));
            }
            bgAudio.volume = targetVolume > 0 ? targetVolume : 0.5;
            isAudioPlaying = true;
        }
        updateAudioIcon();
    }

    function setVolume(val) {
        targetVolume = parseFloat(val);
        bgAudio.volume = targetVolume;
        
        if (targetVolume > 0.01 && !isAudioPlaying && bgAudio.paused) {
            playAudioWithFade(500);
        }
        if (targetVolume <= 0.01) {
            isAudioPlaying = false;
        } else if (!bgAudio.paused) {
            isAudioPlaying = true;
        }
        updateAudioIcon();
    }

    function updateAudioIcon() {
        const btn = document.getElementById('audioIconBtn');
        if (isAudioPlaying && bgAudio.volume > 0.01) {
            btn.classList.add('active');
            btn.innerText = '🔊';
        } else {
            btn.classList.remove('active');
            btn.innerText = '🔇';
        }
    }

    // Frasi Coach
    const coachPhrases = [
        `Hai già resistito {days} giorni. Non cedere!`,
        `Hai risparmiato {money}€. Vuoi ricominciare a pagare casa al tabaccaio?`,
        `Sale il costo della benzina, dell'alcool, delle sigarette. Farsi un cancro diventa un lusso.`,
        `Ho smesso di fumare da 3 anni, 4 mesi, 12 giorni e 27 minuti, ma non ci penso affatto.`,
        `Fumare una sigaretta col filtro e' come leccare una figa con le mutande.`,
        `Quante sigarette fumi fra una scopata e l'altra?". "Una decina di stecche". (Dario Vergassola).`,
        `Sto cercando di smettere di fumare". "Hai provato con le caramelle?". "Si', ma non si accendono!`,
        `Grazie al cielo, ho smesso di nuovo di fumare…! Dio! Come mi sento in forma. Con istinti omicidi, ma in forma.`,
        `Mi dissero che fumava solo col bocchino. Cazzo, pensai, come diavolo fa?`,
        `Oh figlio mio, cos'è quell'accendino? ". "Mamma, voglio darmi fuoco." "Oh santo cielo, mi hai fatto prendere un colpo. Pensavo fumassi!`,
        `Oggi ho letto su un pacchetto di sigarette che il fumo danneggia gravemente chi ci sta intorno. E per la prima volta ho pensato che ci fosse un valido motivo per iniziare.`,
        `Mi dissero che fumava solo col bocchino. Cazzo, pensai, come diavolo fa?`, 
        `Ma stai fumando? No, mando un SMS a Toro Seduto.`,
        `Dapprima Dio creò l’uomo, poi la donna. Dopo l’uomo gli fece pena e gli diede una sigaretta.`,
        `Lo sapevate che il fumo provoca impotenza ma non importa perché tanto coi denti gialli e l’alito di tabacco non ve la dà nessuno?`,
        `Dapprima Dio creò l’uomo, poi la donna. Dopo l’uomo gli fece pena e gli diede una sigaretta.`,
        `E’ ormai provato, oltre ogni dubbio, che il fumo è una delle principali cause delle statistiche.`,
        `Preferirei baciare una mucca pazza sul muso che un fumatore sulla bocca.`,
        `Baciare una persona che fuma è come leccare un posacenere.`
    ];

    // Sequenze Intro
    const introCoach = ["Rifletti..."];
    const introBreath = ["Ok, non ti abbattere..", "Prova con la respirazione", "Respira profondamente", "Segui la sfera"];
    const introSnake = ["Non ti preoccupare", "Abbiamo ancora un asso nella manica!", "Hai bisogno di distrarti..", "Che ne dici di una partita a Snake?"];

    async function showSequence(elementId, sequence, callback) {
        const el = document.getElementById(elementId);
        for (let text of sequence) {
            el.style.opacity = 0;
            await new Promise(r => setTimeout(r, 1000));
            el.innerText = text;
            el.style.opacity = 1;
            await new Promise(r => setTimeout(r, 2500));
        }
        if (callback) callback();
    }

    function startCoachPhase() {
        document.getElementById('phase-coach').classList.add('active');
        
        showSequence('dynamic-text-area', introCoach, () => {
            rotatePhrases();
            actionTimeout = setTimeout(() => {
                document.getElementById('coach-actions').classList.add('visible');
            }, 15000);
        });
    }

    function rotatePhrases() {
        const el = document.getElementById('dynamic-text-area');
        let raw = coachPhrases[currentPhraseIndex];
        let txt = raw.replace(/{name}/g, userData.name)
                     .replace(/{days}/g, userData.days)
                     .replace(/{hours}/g, userData.hours)
                     .replace(/{money}/g, userData.money);

        el.style.opacity = 0;
        setTimeout(() => {
            el.innerHTML = txt;
            el.style.opacity = 1;
        }, 1000);

        currentPhraseIndex = (currentPhraseIndex + 1) % coachPhrases.length;
        phraseTimer = setTimeout(rotatePhrases, 8000);
    }

    function goToBreathingIntro() {
        clearTimeout(phraseTimer); clearTimeout(actionTimeout);
        document.querySelectorAll('.phase').forEach(p => p.classList.remove('active'));
        document.getElementById('phase-breath').classList.add('active');
        
        showSequence('breath-intro-text', introBreath, () => {
            document.getElementById('breath-intro-text').style.display = 'none';
            document.getElementById('breath-wrapper').classList.add('visible');
            startBreathingCycle();
        });
    }

    async function startBreathingCycle() {
        const circle = document.getElementById('breath-circle');
        const txtEl = document.getElementById('breath-instruction');
        const actions = document.getElementById('breath-actions');
        
        let count = 0;
        const maxCycles = 3;
        const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

        while (true) {
            if (count >= maxCycles) {
                actions.classList.add('visible');
            }

            txtEl.innerText = "Inspira...";
            txtEl.classList.add('visible');
            await wait(400);
            
            circle.style.transition = "transform 4s ease-in-out, box-shadow 4s ease-in-out";
            circle.style.transform = "scale(2.4)";
            circle.style.boxShadow = "0 0 80px rgba(16,185,129,0.5)";
            
            await wait(4000);

            txtEl.innerText = "Trattieni...";
            await wait(2000);

            txtEl.innerText = "Espira...";
            
            circle.style.transition = "transform 4s ease-in-out, box-shadow 4s ease-in-out";
            circle.style.transform = "scale(1)";
            circle.style.boxShadow = "0 0 40px rgba(16,185,129,0.2)";
            
            await wait(4000);

            count++;
        }
    }

    function goToSnakeIntro() {
        document.querySelectorAll('.phase').forEach(p => p.classList.remove('active'));
        document.getElementById('phase-snake').classList.add('active');
        
        showSequence('snake-intro-text', introSnake, () => {
            document.getElementById('snake-intro-text').style.display = 'none';
            document.getElementById('snake-title').style.opacity = 1;
            document.getElementById('game-container').classList.add('visible');
            document.getElementById('snake-score').classList.add('visible');
            document.getElementById('snake-joystick').classList.add('visible');
            initSnake();
            initJoystick();
        });
    }

    function initSnake() {
        const canvas = document.getElementById('game-canvas');
        const ctx = canvas.getContext('2d');
        const scoreEl = document.getElementById('snake-score');
        
        const size = Math.min(window.innerWidth - 40, 300);
        const validSize = Math.floor(size / 20) * 20;
        canvas.width = validSize; canvas.height = validSize;
        const tileCount = validSize / box;
        
        let snake = [{x: 10 * box, y: 10 * box}];
        let food = {x: 15 * box, y: 15 * box};
        let score = 0;
        dx = 0; dy = 0; 

        function placeFood() {
            let validPosition = false;
            while (!validPosition) {
                food.x = Math.floor(Math.random() * tileCount) * box;
                food.y = Math.floor(Math.random() * tileCount) * box;
                validPosition = true;
                for (let part of snake) {
                    if (part.x === food.x && part.y === food.y) { validPosition = false; break; }
                }
            }
        }

        function drawGame() {
            if (!snakeInterval) return;
            changingDirection = false;
            const head = {x: snake[0].x + dx, y: snake[0].y + dy};
            
            if (head.x < 0) head.x = (tileCount - 1) * box;
            if (head.x >= validSize) head.x = 0;
            if (head.y < 0) head.y = (tileCount - 1) * box;
            if (head.y >= validSize) head.y = 0;

            for (let i = 0; i < snake.length; i++) {
                if (head.x === snake[i].x && head.y === snake[i].y && snake.length > 1) {
                    snake = [{x: 10 * box, y: 10 * box}]; dx = 0; dy = 0; score = 0;
                    scoreEl.innerText = "Ricomincia! Punti: 0"; placeFood(); return;
                }
            }
            snake.unshift(head);
            if (head.x === food.x && head.y === food.y) { score++; scoreEl.innerText = "Punti: " + score; placeFood(); } 
            else { snake.pop(); }

            ctx.fillStyle = "#000000"; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = "#ff4757"; ctx.shadowBlur = 10; ctx.shadowColor = "#ff4757";
            ctx.fillRect(food.x + 2, food.y + 2, box - 4, box - 4); ctx.shadowBlur = 0;
            snake.forEach((part, index) => {
                ctx.fillStyle = (index === 0) ? "#2ecc71" : "#27ae60";
                ctx.fillRect(part.x + 1, part.y + 1, box - 2, box - 2);
            });
        }

        window.changeSnakeDirection = function(newDx, newDy) {
            if (changingDirection) return;
            const goingUp = dy === -box, goingDown = dy === box, goingRight = dx === box, goingLeft = dx === -box;
            if (newDx === -box && !goingRight) { dx = -box; dy = 0; changingDirection = true; }
            if (newDx === box && !goingLeft) { dx = box; dy = 0; changingDirection = true; }
            if (newDy === -box && !goingDown) { dx = 0; dy = -box; changingDirection = true; }
            if (newDy === box && !goingUp) { dx = 0; dy = box; changingDirection = true; }
        };

        document.addEventListener("keydown", function(e) {
            if (document.getElementById('phase-snake').classList.contains('active')) {
                if (e.keyCode == 37) window.changeSnakeDirection(-box, 0);
                if (e.keyCode == 38) window.changeSnakeDirection(0, -box);
                if (e.keyCode == 39) window.changeSnakeDirection(box, 0);
                if (e.keyCode == 40) window.changeSnakeDirection(0, box);
            }
        });

        if(snakeInterval) clearInterval(snakeInterval);
        snakeInterval = setInterval(drawGame, 350);
        placeFood();
    }

    function initJoystick() {
        const setupBtn = (id, dirX, dirY) => {
            const btn = document.getElementById(id);
            if(!btn) return;
            const handlePress = (e) => {
                if(e.cancelable) e.preventDefault();
                if(window.changeSnakeDirection) {
                    window.changeSnakeDirection(dirX, dirY);
                }
                btn.classList.add('active');
                setTimeout(() => btn.classList.remove('active'), 150);
            };
            btn.removeEventListener('pointerdown', handlePress);
            btn.removeEventListener('touchstart', handlePress);
            btn.addEventListener('pointerdown', handlePress);
            btn.addEventListener('touchstart', handlePress, {passive: false});
        };
        setupBtn('btn-up', 0, -box);
        setupBtn('btn-down', 0, box);
        setupBtn('btn-left', -box, 0);
        setupBtn('btn-right', box, 0);
    }

    function triggerSuccess(isGameOver) {
        if(snakeInterval) clearInterval(snakeInterval); snakeInterval = null;
        clearTimeout(phraseTimer); clearTimeout(actionTimeout);
        const overlay = document.getElementById('success-overlay');
        const statsTxt = document.getElementById('success-stats');
        const msgs = ["Grande! Sei l'eroe di te stesso!", "Una battaglia vinta.", "Il controllo è tuo."];
        document.getElementById('success-msg').innerText = msgs[Math.floor(Math.random()*msgs.length)];
        statsTxt.innerHTML = `Crisi superate totali: <strong>${userData.crises_won + 1}</strong>`;
        overlay.style.display = 'flex';

        fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Cache-Control': 'no-cache'},
            body: 'action=save_success'
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                statsTxt.innerHTML = `Crisi superate totali: <strong>${data.new_won}</strong>`;
                userData.crises_won = data.new_won;
            }
        })
        .catch(err => console.error("Errore salvataggio:", err));
    }

    // Init audio object
    initAudio();
</script>
</body>
</html>