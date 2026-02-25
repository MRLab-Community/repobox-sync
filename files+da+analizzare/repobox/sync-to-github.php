<?php
/**
 * sync-to-github.php - Sincronizza repobox con GitHub
 * Con indicatore di avanzamento e pulsante di ritorno
 */

session_start();
if (!isset($_SESSION['repobox_user_id'])) {
    http_response_code(403);
    exit('Accesso negato.');
}

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('❌ File .env non trovato.');
}

$env = parse_ini_file($envFile);
$token = $env['GITHUB_TOKEN'] ?? '';
$owner = $env['GITHUB_OWNER'] ?? '';
$repo = $env['GITHUB_REPO'] ?? '';

if (!$token || !$owner || !$repo) {
    die('❌ Configurazione GitHub incompleta in .env');
}

$baseDir = __DIR__ . '/progetti';

// Funzione per leggere ricorsivamente i file
function getAllFiles($dir, $base) {
    $files = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        $relative = ltrim(str_replace($base, '', $path), '/');
        if (is_dir($path)) {
            $files = array_merge($files, getAllFiles($path, $base));
        } else {
            $files[] = $relative;
        }
    }
    return $files;
}

// Funzione per ottenere SHA di un file su GitHub
function getGitHubFileSha($owner, $repo, $path, $token) {
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . urlencode($path);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $token,
        'User-Agent: Repobox-Sync',
        'Accept: application/vnd.github.v3+json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['sha'] ?? null;
    }
    return null;
}

// Funzione per caricare/aggiornare un file su GitHub
function uploadFileToGitHub($owner, $repo, $path, $content, $sha, $token) {
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . urlencode($path);
    $payload = [
        'message' => 'Sync from Repobox - ' . date('Y-m-d H:i:s'),
        'content' => base64_encode($content),
        'branch' => 'main'
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
        'User-Agent: Repobox-Sync',
        'Content-Type: application/json',
        'Accept: application/vnd.github.v3+json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200 || $httpCode === 201;
}

// Modalità AJAX per avanzamento
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $file = $_GET['file'] ?? '';
    $localPath = $baseDir . '/' . $file;
    $content = file_get_contents($localPath);
    $sha = getGitHubFileSha($owner, $repo, $file, $token);
    $success = uploadFileToGitHub($owner, $repo, $file, $content, $sha, $token);
    echo json_encode(['file' => $file, 'success' => $success]);
    exit;
}

// Modalità normale: mostra pagina con JS per avanzamento
$files = getAllFiles($baseDir, $baseDir);
$total = count($files);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>🔄 Sincronizzazione con GitHub</title>
  <style>
    body { font-family: sans-serif; padding: 20px; background: #f6f8fa; }
    .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
    h2 { margin-bottom: 20px; color: #24292f; }
    .progress-bar { width: 100%; background: #e1e4e8; border-radius: 4px; margin: 10px 0; }
    .progress-fill { height: 20px; background: #28a745; border-radius: 4px; width: 0%; transition: width 0.3s; }
    .log { background: #f6f8fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 13px; max-height: 300px; overflow-y: auto; }
    .btn { display: inline-block; margin-top: 15px; padding: 8px 16px; background: #1a73e8; color: white; text-decoration: none; border-radius: 4px; }
    .btn:hover { background: #1557b0; }
  </style>
</head>
<body>
  <div class="container">
    <h2>🔄 Sincronizzazione con GitHub</h2>
    <p>Repo: <a href="https://github.com/<?= htmlspecialchars($owner) ?>/<?= htmlspecialchars($repo) ?>" target="_blank">https://github.com/<?= htmlspecialchars($owner) ?>/<?= htmlspecialchars($repo) ?></a></p>
    
    <div>File totali: <strong><?= $total ?></strong></div>
    <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
    <div id="progressText">0 / <?= $total ?></div>
    
    <div class="log" id="log"></div>
    
    <a href="repo.php" class="btn">← Torna a Repobox</a>
  </div>

  <script>
    const files = <?= json_encode($files) ?>;
    const total = files.length;
    let current = 0;
    const log = document.getElementById('log');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');

    function updateProgress() {
      const percent = Math.floor((current / total) * 100);
      progressFill.style.width = percent + '%';
      progressText.textContent = current + ' / ' + total;
    }

    function syncNext() {
      if (current >= total) {
        log.innerHTML += '<div style="color:green; margin-top:10px;">✅ Sincronizzazione completata!</div>';
        return;
      }

      const file = files[current];
      fetch(`sync-to-github.php?ajax=1&file=${encodeURIComponent(file)}`)
        .then(r => r.json())
        .then(data => {
          const msg = data.success ? `✅ ${data.file}` : `❌ ${data.file} (errore)`;
          log.innerHTML += '<div>' + msg + '</div>';
          log.scrollTop = log.scrollHeight;
          current++;
          updateProgress();
          setTimeout(syncNext, 100); // Breve pausa per non sovraccaricare
        })
        .catch(() => {
          log.innerHTML += '<div style="color:red;">❌ ' + file + ' (errore di rete)</div>';
          current++;
          updateProgress();
          setTimeout(syncNext, 100);
        });
    }

    // Avvia la sincronizzazione
    syncNext();
  </script>
</body>
</html>