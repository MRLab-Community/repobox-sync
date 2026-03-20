<?php
/**
 * sync-to-github.php - Sincronizzazione SELETTIVA con GitHub
 */

session_start();
if (!isset($_SESSION['repobox_user_id'])) {
    http_response_code(403);
    exit('Accesso negato.');
}

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('❌ File .env non trovato. Configura prima le credenziali GitHub.');
}

$env = parse_ini_file($envFile);
$token = $env['GITHUB_TOKEN'] ?? '';
$owner = $env['GITHUB_OWNER'] ?? '';
$repo = $env['GITHUB_REPO'] ?? '';

if (!$token || !$owner || !$repo) {
    die('❌ Configurazione GitHub incompleta nel file .env (Token, Owner o Repo mancanti).');
}

$baseDir = __DIR__ . '/progetti';

// --- FUNZIONI PHP ---

// Recupera struttura file per l'albero (con path relativo)
function getTreeStructure($dir, $base) {
    $tree = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        $relative = ltrim(str_replace($base . '/', '', $path), '/');
        
        if (is_dir($path)) {
            $tree[$item] = [
                'type' => 'dir',
                'children' => getTreeStructure($path, $base),
                'path' => $relative
            ];
        } else {
            $tree[$item] = [
                'type' => 'file',
                'path' => $relative,
                'size' => filesize($path)
            ];
        }
    }
    return $tree;
}

// Ottiene SHA da GitHub
function getGitHubFileSha($owner, $repo, $path, $token) {
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . urlencode($path);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $token,
        'User-Agent: Repobox-Selective-Sync',
        'Accept: application/vnd.github.v3+json'
    ]);
    // Disabilita verifica SSL se necessario in locale, ma in produzione tenerla attiva
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['sha'] ?? null;
    }
    return null;
}

// Carica file su GitHub
function uploadFileToGitHub($owner, $repo, $path, $content, $sha, $token) {
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . urlencode($path);
    $payload = [
        'message' => 'Sync from Repobox (Selective) - ' . date('Y-m-d H:i:s'),
        'content' => base64_encode($content),
        'branch' => 'main' // O 'master' a seconda del tuo repo
    ];
    if ($sha) {
        $payload['sha'] = $sha;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $token,
        'User-Agent: Repobox-Selective-Sync',
        'Content-Type: application/json',
        'Accept: application/vnd.github.v3+json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['success' => ($httpCode === 200 || $httpCode === 201), 'code' => $httpCode];
}

// --- GESTIONE AJAX ---

// 1. AJAX: Esegue il sync di UN file specifico dalla lista selezionata
if (isset($_POST['ajax_sync'])) {
    header('Content-Type: application/json');
    
    $filesToSync = json_decode($_POST['files'], true); // Array di path
    $index = intval($_POST['index']);
    
    if (!isset($filesToSync[$index])) {
        echo json_encode(['done' => true, 'message' => 'Completato']);
        exit;
    }

    $file = $filesToSync[$index];
    $localPath = $baseDir . '/' . $file;
    
    if (!file_exists($localPath)) {
        echo json_encode(['success' => false, 'file' => $file, 'error' => 'File locale non trovato']);
        exit;
    }

    $content = file_get_contents($localPath);
    $sha = getGitHubFileSha($owner, $repo, $file, $token);
    $result = uploadFileToGitHub($owner, $repo, $file, $content, $sha, $token);
    
    echo json_encode([
        'success' => $result['success'],
        'file' => $file,
        'done' => false,
        'next_index' => $index + 1
    ]);
    exit;
}

// --- INTERFACCIA UTENTE ---
$tree = getTreeStructure($baseDir, $baseDir);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>🔄 Sync Selettivo GitHub</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 20px; background: #f6f8fa; color: #24292f; }
    .container { max-width: 900px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    h2 { margin-top: 0; color: #0366d6; display: flex; align-items: center; gap: 10px; }
    
    /* Toolbar Selezione */
    .toolbar { background: #f1f8ff; padding: 10px; border-radius: 6px; margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border: 1px solid #c8e1ff; }
    .btn-sm { padding: 6px 12px; font-size: 13px; border: 1px solid #d1d5da; background: #fafbfc; border-radius: 4px; cursor: pointer; color: #24292f; }
    .btn-sm:hover { background: #f3f4f6; border-color: #c6cbd1; }
    .btn-primary { background: #0366d6; color: white; border: none; }
    .btn-primary:hover { background: #0256c7; }
    .btn-secondary { background: #6c757d; color: white; border: none; }
    .btn-secondary:hover { background: #5a6268; }
    .counter { margin-left: auto; font-weight: bold; font-size: 14px; color: #586069; }

    /* Albero File */
    .file-tree { list-style: none; padding: 0; margin: 0; border: 1px solid #e1e4e8; border-radius: 6px; max-height: 400px; overflow-y: auto; }
    .file-tree li { margin: 0; }
    .tree-item { display: flex; align-items: center; padding: 8px 12px; border-bottom: 1px solid #f6f8fa; transition: background 0.2s; }
    .tree-item:hover { background: #f6f8fa; }
    .tree-item:last-child { border-bottom: none; }
    .tree-indent { width: 20px; display: inline-block; }
    .icon { margin-right: 8px; width: 16px; text-align: center; display: inline-block; }
    .filename { flex-grow: 1; font-size: 14px; }
    .filesize { font-size: 12px; color: #959da5; margin-left: 10px; }
    
    input[type="checkbox"] { transform: scale(1.2); cursor: pointer; }

    /* Area Progresso (Nascosta all'inizio) */
    #syncArea { display: none; margin-top: 20px; border-top: 2px solid #e1e4e8; pt-4; }
    .progress-bar { width: 100%; background: #e1e4e8; border-radius: 4px; height: 24px; margin: 10px 0; overflow: hidden; }
    .progress-fill { height: 100%; background: #28a745; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold; }
    .log { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-family: 'Consolas', monospace; font-size: 13px; max-height: 300px; overflow-y: auto; margin-top: 10px; }
    .log div { margin-bottom: 4px; }
    .log .success { color: #85e89d; }
    .log .error { color: #f97583; }
    
    .actions { margin-top: 20px; text-align: right; }
    .btn-back { text-decoration: none; color: #24292f; font-weight: 600; }
    .btn-back:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <h2>🔄 Sincronizzazione Selettiva GitHub</h2>
    <p style="margin-bottom: 20px; color: #586069;">
      Repository: <a href="https://github.com/<?= htmlspecialchars($owner) ?>/<?= htmlspecialchars($repo) ?>" target="_blank" style="color:#0366d6;"><?= htmlspecialchars($owner) ?>/<?= htmlspecialchars($repo) ?></a>
    </p>

    <!-- FASE 1: SELEZIONE -->
    <div id="selectionPhase">
      <div class="toolbar">
        <button class="btn-sm btn-primary" onclick="selectAll()">✅ Seleziona Tutti</button>
        <button class="btn-sm" onclick="deselectAll()">❌ Deseleziona Tutti</button>
        <button class="btn-sm" onclick="invertSelection()">🔄 Inverti</button>
        <span class="counter" id="countDisplay">0 file selezionati</span>
      </div>

      <ul class="file-tree" id="fileTree">
        <!-- Generato via JS -->
      </ul>

      <div class="actions">
      <a href="repo.php" class="btn-sm btn-secondary" style="padding: 10px 20px; font-size: 16px; margin-right: 10px; text-decoration: none; display: inline-block;">
        ❌ Annulla
    </a>
        <button class="btn-sm btn-primary" style="padding: 10px 20px; font-size: 16px;" onclick="startSync()">🚀 Avvia Sincronizzazione</button>
      </div>
    </div>

    <!-- FASE 2: PROGRESSO -->
    <div id="syncArea">
      <h3>⏳ Sincronizzazione in corso...</h3>
      <div class="progress-bar">
        <div class="progress-fill" id="progressFill">0%</div>
      </div>
      <div style="text-align: right; font-size: 13px; margin-bottom: 5px;">
        File <span id="currentNum">0</span> di <span id="totalNum">0</span>
      </div>
      <div class="log" id="log"></div>
      
      <div class="actions">
        <a href="repo.php" class="btn-back">← Torna a Repobox</a>
      </div>
    </div>
  </div>

  <script>
    // Dati dall'albero PHP
    const treeData = <?= json_encode($tree) ?>;
    let selectedFiles = new Set();

    // Renderizza l'albero ricorsivamente
    function renderTree(node, container, indent = 0) {
        for (const name in node) {
            const item = node[name];
            const li = document.createElement('li');
            
            const div = document.createElement('div');
            div.className = 'tree-item';
            div.style.paddingLeft = (12 + (indent * 20)) + 'px';

            // Checkbox (solo per file)
            if (item.type === 'file') {
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = item.path;
                cb.onchange = updateCount;
                // Seleziona di default? No, lasciamo scegliere all'utente
                div.appendChild(cb);
            } else {
                const spacer = document.createElement('span');
                spacer.style.width = '20px';
                spacer.style.display = 'inline-block';
                div.appendChild(spacer);
            }

            // Icona
            const icon = document.createElement('span');
            icon.className = 'icon';
            icon.textContent = item.type === 'dir' ? '📁' : '📄';
            div.appendChild(icon);

            // Nome
            const span = document.createElement('span');
            span.className = 'filename';
            span.textContent = name;
            div.appendChild(span);

            // Dimensione (solo file)
            if (item.type === 'file') {
                const size = document.createElement('span');
                size.className = 'filesize';
                size.textContent = (item.size / 1024).toFixed(1) + ' KB';
                div.appendChild(size);
            }

            li.appendChild(div);
            container.appendChild(li);

            // Ricorsione per cartelle
            if (item.type === 'dir' && item.children) {
                renderTree(item.children, container, indent + 1);
            }
        }
    }

    // Inizializza
    const treeContainer = document.getElementById('fileTree');
    renderTree(treeData, treeContainer);

    // Funzioni Toolbar
    function updateCount() {
        const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
        selectedFiles.clear();
        checkboxes.forEach(cb => selectedFiles.add(cb.value));
        document.getElementById('countDisplay').textContent = selectedFiles.size + ' file selezionati';
    }

    function selectAll() {
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = true);
        updateCount();
    }

    function deselectAll() {
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        updateCount();
    }

    function invertSelection() {
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = !cb.checked);
        updateCount();
    }

    // Logica Sync
    let syncQueue = [];
    let currentIndex = 0;

    function startSync() {
        if (selectedFiles.size === 0) {
            alert('⚠️ Seleziona almeno un file da sincronizzare!');
            return;
        }

        if (!confirm(`Sei sicuro di voler sincronizzare ${selectedFiles.size} file su GitHub?`)) return;

        // Prepara coda
        syncQueue = Array.from(selectedFiles);
        currentIndex = 0;

        // UI Switch
        document.getElementById('selectionPhase').style.display = 'none';
        document.getElementById('syncArea').style.display = 'block';
        document.getElementById('totalNum').textContent = syncQueue.length;
        document.getElementById('log').innerHTML = '';
        
        processNext();
    }

    function processNext() {
        if (currentIndex >= syncQueue.length) {
            finishSync();
            return;
        }

        const file = syncQueue[currentIndex];
        updateProgress();

        fetch('sync-to-github.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_sync=1&files=${encodeURIComponent(JSON.stringify(syncQueue))}&index=${currentIndex}`
        })
        .then(r => r.json())
        .then(data => {
            const logDiv = document.getElementById('log');
            const entry = document.createElement('div');
            
            if (data.success) {
                entry.innerHTML = `<span class="success">✅ ${file}</span>`;
            } else {
                entry.innerHTML = `<span class="error">❌ ${file} (${data.error || 'Errore sconosciuto'})</span>`;
            }
            logDiv.appendChild(entry);
            logDiv.scrollTop = logDiv.scrollHeight;

            currentIndex++;
            setTimeout(processNext, 200); // Pausa breve tra le richieste
        })
        .catch(err => {
            const logDiv = document.getElementById('log');
            logDiv.innerHTML += `<div class="error">❌ ${file} (Errore di rete)</div>`;
            currentIndex++;
            setTimeout(processNext, 200);
        });
    }

    function updateProgress() {
        const percent = Math.floor((currentIndex / syncQueue.length) * 100);
        const bar = document.getElementById('progressFill');
        bar.style.width = percent + '%';
        bar.textContent = percent + '%';
        document.getElementById('currentNum').textContent = currentIndex;
    }

    function finishSync() {
        document.getElementById('progressFill').style.width = '100%';
        document.getElementById('progressFill').textContent = '100%';
        document.getElementById('currentNum').textContent = syncQueue.length;
        
        const logDiv = document.getElementById('log');
        const finalMsg = document.createElement('div');
        finalMsg.style.marginTop = '15px';
        finalMsg.style.fontWeight = 'bold';
        finalMsg.style.color = '#85e89d';
        finalMsg.textContent = '✅ Sincronizzazione completata!';
        logDiv.appendChild(finalMsg);
        logDiv.scrollTop = logDiv.scrollHeight;
    }
  </script>
</body>
</html>