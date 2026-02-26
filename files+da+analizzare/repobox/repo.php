<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function isUserLoggedIn() {
    return isset($_SESSION['repobox_user_id']);
}
$loggedIn = isUserLoggedIn();
$baseDir = __DIR__ . '/progetti';
function listFolderFiles($dir) {
    $arr = [];
    if (!is_dir($dir)) return $arr;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            $arr[$item] = ['type' => 'dir', 'children' => listFolderFiles($fullPath)];
        } else {
            $relativePath = ltrim(str_replace(__DIR__ . '/progetti/', '', $fullPath), '/');
            $arr[$item] = [
                'type' => 'file',
                'path' => $relativePath,
                'size' => filesize($fullPath),
                'modified' => filemtime($fullPath)
            ];
        }
    }
    return $arr;
}
// API: lista file
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    $files = listFolderFiles($baseDir);
    echo json_encode(empty($files) ? ['__empty__' => ['type' => 'empty']] : $files, JSON_UNESCAPED_SLASHES);
    exit;
}
// API: leggi file
if (isset($_GET['action']) && $_GET['action'] === 'file') {
    $file = $_GET['file'] ?? '';
    $fullPath = $baseDir . '/' . $file;
    if (file_exists($fullPath) && is_file($fullPath) && strpos(realpath($fullPath), realpath($baseDir)) === 0) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($fullPath);
    } else {
        http_response_code(404);
        echo "File non trovato.";
    }
    exit;
}
// API: salva file
if (isset($_POST['action']) && $_POST['action'] === 'save_file' && $loggedIn) {
    $file = $_POST['file'] ?? '';
    $content = $_POST['content'] ?? '';
    $fullPath = $baseDir . '/' . $file;
    if (file_exists($fullPath) && is_file($fullPath) && strpos(realpath($fullPath), realpath($baseDir)) === 0) {
        file_put_contents($fullPath, $content);
        echo 'Salvato';
    } else {
        http_response_code(400);
        echo 'File non valido';
    }
    exit;
}
// API: elenca TUTTE le cartelle ricorsivamente in /progetti/
if (isset($_GET['action']) && $_GET['action'] === 'list-folders') {
    header('Content-Type: application/json');
    
    function getAllFolders($dir, $basePath = '') {
        $folders = [];
        if (!is_dir($dir)) return $folders;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $relativePath = $basePath ? "$basePath/$item" : $item;
                $folders[] = $relativePath;
                $folders = array_merge($folders, getAllFolders($path, $relativePath));
            }
        }
        return $folders;
    }
    echo json_encode(getAllFolders($baseDir));
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>📦 Repobox – Repository Personale</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f6f8fa; color: #24292f; line-height: 1.6; min-height: 100vh; display: flex; flex-direction: column; }
    .container { display: flex; flex: 1; }
    @media (max-width: 767px) { .container { flex-direction: column; } }
    .sidebar { width: 100%; background: white; border-bottom: 1px solid #e1e4e8; padding: 16px; }
    @media (min-width: 768px) {
      .sidebar { width: 300px; height: 100vh; border-right: 1px solid #e1e4e8; overflow-y: auto; position: sticky; top: 0; }
    }
    .content {
      flex: 1;
      padding: 16px;
      background: white;
      min-width: 0;
    }
    h3 { font-size: 14px; font-weight: 600; color: #586069; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; }
    .file-tree { list-style: none; font-size: 14px; }
    .file-tree li { margin: 4px 0; }
    .folder, .file { display: flex; align-items: center; padding: 4px 8px; border-radius: 6px; cursor: pointer; color: #24292f; position: relative; }
    .folder:hover, .file:hover { background: #f1f3f5; }
    .folder::before { content: "📁"; margin-right: 6px; display: inline-block; width: 16px; text-align: center; }
    .file::before { content: "📄"; margin-right: 6px; opacity: 0.8; }
    .file-tree ul { margin-left: 18px; display: none; }
    /* Hamburger menu su mobile */
    .menu-toggle {
      display: none;
      position: fixed;
      top: 10px;
      left: 10px;
      z-index: 100;
      padding: 8px 12px;
      color: darkslategrey;
      border: none;
      border-radius: 4px;
      font-size: 18px;
      cursor: pointer;
    }
    @media (max-width: 767px) {
      .menu-toggle {
        display: block;
      }
      .sidebar {
        position: fixed;
        left: -300px;
        top: 0;
        bottom: 0;
        width: 280px;
        z-index: 99;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        transition: left 0.3s ease;
        overflow-y: auto;
        overflow-x: scroll;
        padding: 16px;
        box-sizing: border-box;
      }
      .sidebar.active {
        left: 0;
      }
      .content {
        padding-top: 60px;
      }
    }
    .sidebar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-right: 10px;
    }
    .close-sidebar {
      display: none;
      background: none;
      border: none;
      color: #d32f2f;
      font-size: 24px;
      cursor: pointer;
      width: 30px;
      height: 30px;
    }
    .sidebar.active .close-sidebar {
      display: block;
    }
    /* Contenitore del codice con numeri */
    .code-with-linenumbers {
      display: flex;
      background: #f8f8f8;
      border: 1px solid #e1e4e8;
      border-radius: 6px;
      font-family: Consolas, Monaco, monospace;
      font-size: 13px;
      line-height: 1.6;
      overflow-x: auto;
      margin: 0;
    }
    /* Colonna dei numeri */
    .code-linenumbers {
      text-align: right;
      padding: 16px 8px;
      background: #f0f0f0;
      color: #999;
      user-select: none;
      border-right: 1px solid #e1e4e8;
      white-space: normal;
      flex-shrink: 0;
      width: 50px;
    }
    /* Area del codice */
    .code-content {
      padding: 16px;
      white-space: pre;
      outline: none;
      flex-grow: 1;
      margin: 0;
    }
    .code-editor {
      width: 100%;
      font-family: Consolas, Monaco, monospace;
      font-size: 13px;
      line-height: 1.6;
      padding: 16px;
      border: 1px solid #e1e4e8;
      border-radius: 6px;
      background: #f8f8f8;
      outline: none;
      white-space: pre;
      overflow-wrap: normal;
      overflow-x: auto;
    }
    .empty { color: #6a737d; font-style: italic; }
    .btn { display: inline-block; background: #1a73e8; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; margin-top: 8px; }
    .btn:hover { background: #1557b0; }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .header h1 { font-size: 18px; }
    .file-actions {
      display: none;
      position: absolute;
      right: 8px;
      background: white;
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 2px;
      font-size: 12px;
      z-index: 10;
    }
    .file:hover .file-actions,
    .folder:hover .file-actions {
      display: block;
    }
    .file-actions button {
      background: none;
      border: none;
      cursor: pointer;
      padding: 2px 4px;
      margin: 0 1px;
      border-radius: 3px;
      font-size: 12px;
    }
    .file-actions button:hover { background: #f0f0f0; }
    .editor-toolbar { margin-bottom: 10px; }
    .editor-toolbar button { margin-right: 8px; padding: 6px 12px; }
    .file-tree li {
      list-style-type: none;
    }
    /* Elementi per allineamento riga per riga */
    .line-number,
    .code-line {
      min-height: 1.6em;
      white-space: pre;
      padding: 0;
      margin: 0;
      display: block;
    }
    .site-footer {
      background-color: #2e808d;
      color: white;
      text-align: center;
      padding: 0.3em 0;
      width: 100%;
    }
    .site-header {
      background-color: #3f8e9b;
      color: white;
      text-align: left;
      padding: 0.8em 0;
      width: 100%;
    }
    .site-logo{
      padding-left: 20px;
      font-size: 25px;
      font-weight: bold;
    }
    #myBtn {
      display: none;
      position: fixed;
      bottom: 30px;
      right: 30px;
      z-index: 99;
      border: none;
      outline: none;
      cursor: pointer;
      padding: 15px;
      font-size: 20px;
      background: none;
      opacity: 0.5;
    }
    #myBtn:hover {
      opacity: 0.8;
    }
    @media (max-width: 767px) {
      #myBtn {
        bottom: 60px;
        right: -5px;
      }
    }
    /* Scrollbar nascosta */
    html { scrollbar-width: thin; scrollbar-color: transparent transparent; }
    html:hover { scrollbar-color: #a0a0a0 #f0f0f0; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background-color: transparent; border-radius: 4px; }
    html:hover ::-webkit-scrollbar-thumb { background-color: #a0a0a0; }
    html:hover ::-webkit-scrollbar-track { background: #f0f0f0; }
  </style>
  <!-- Highlight.js -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
</head>
<body>
<header class="site-header">
  <button id="menuToggle" class="menu-toggle" aria-label="Toggle navigation">☰</button>
  <div class="site-logo">📦 Repobox</div>
</header>
<button onclick="topFunction()" id="myBtn" title="Vai Su">⤴️</button>

<div class="container">
  <div class="sidebar">
    <div class="header">
      <h1>Progetti</h1>
      <button id="closeSidebar" class="close-sidebar" aria-label="Chiudi menu">✕</button>
      <?php if ($loggedIn): ?>
        <a href="logout.php" title="Esci" style="font-size:20px; text-decoration:none;">🚪</a>
      <?php endif; ?>
    </div>
    <?php if ($loggedIn): ?>
      <a href="upload.php" class="btn">📤 Carica Plugin</a>
      <a href="sync-to-github.php" class="btn" style="background:#28a745; margin-top:8px;">🔄 Sincronizza con GitHub</a>
      <div style="margin:15px 0;">
        <input type="text" id="searchBox" placeholder="🔍 Cerca nei file..." style="width:100%; padding:6px; font-size:13px; border:1px solid #ddd; border-radius:4px;">
        <div id="searchResults" style="margin-top:8px; max-height:200px; overflow-y:auto; display:none;"></div>
      </div>
    <?php else: ?>
      <?php
      $hasProjects = is_dir(__DIR__ . '/progetti') && count(array_diff(scandir(__DIR__ . '/progetti'), ['.', '..'])) > 0;
      if ($hasProjects): ?>
        <a href="login.php" class="btn" style="display:block; text-align:center; margin:10px 0;">🔒 Accedi</a>
      <?php endif; ?>
    <?php endif; ?>
    <ul id="fileTree" class="file-tree">
      <li class="loading">Caricamento...</li>
    </ul>
  </div>

  <div class="content">
    <h3>Codice Sorgente</h3>
    <div id="fileContent">
      <p class="empty">Seleziona un file a sinistra per visualizzarne il contenuto.</p>
    </div>
  </div>
</div>

<footer class="site-footer">
  <p>&copy; <script>document.write(new Date().getFullYear());</script> <a href="https://mrlab.altervista.org/" target="_blank" style="text-decoration: none; color: #fff; font-weight: bold;">MRLab Community</a> Tutti i diritti riservati</p>   
</footer>

<script>
let currentFilePath = '';
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
function loadFile(path) {
  currentFilePath = path;
  const contentDiv = document.getElementById('fileContent');
  contentDiv.innerHTML = '<p>🔄 Caricamento...</p>';
  
  fetch(`?action=file&file=${encodeURIComponent(path)}`)
    .then(r => r.ok ? r.text() : Promise.reject('File non trovato'))
    .then(code => {
      const lang = getLanguageFromPath(path);
      const lines = code.split('\n');
      let highlightedCode = code;
      
      // Evidenziazione solo in lettura
      if (!<?php echo $loggedIn ? 'true' : 'false'; ?>) {
        const tempCode = document.createElement('code');
        tempCode.className = `language-${lang}`;
        tempCode.textContent = code;
        hljs.highlightElement(tempCode);
        highlightedCode = tempCode.innerHTML;
      }

      const lineNumbersHtml = lines.map((_, i) => `<div class="line-number">${i + 1}</div>`).join('');
      const codeLinesHtml = highlightedCode.split('\n').map(line => `<div class="code-line">${line}</div>`).join('');

      if (<?php echo $loggedIn ? 'true' : 'false'; ?>) {
        // Modalità modifica
        const tempCode = document.createElement('code');
        tempCode.className = `language-${lang}`;
        tempCode.textContent = code;
        hljs.highlightElement(tempCode);
        const highlightedHtml = tempCode.innerHTML;
        const codeLinesHtml = highlightedHtml.split('\n').map(line => `<div class="code-line">${line}</div>`).join('');

        contentDiv.innerHTML = `
          <h3>${escapeHtml(path)}
            <?php if ($loggedIn): ?>
            <div style="float:right; font-size:12px;">
              <button onclick="copyCode('${escapeHtml(path)}')" title="Copia codice">📋</button>
              <button onclick="downloadFile('${escapeHtml(path)}')" title="Download file">⬇️</button>
            </div>
            <?php endif; ?>
          </h3>
          <div class="editor-toolbar">
            <button onclick="saveFile()">✅ Salva</button>
            <button onclick="loadFile('${escapeHtml(path)}')">↺ Ricarica</button>
          </div>
          <div class="code-with-linenumbers">
            <div class="code-linenumbers">${lineNumbersHtml}</div>
            <div class="code-content" id="codeEditor" contenteditable="true" spellcheck="false">${codeLinesHtml}</div>
          </div>
          <div class="editor-toolbar" style="margin-top:16px;">
            <button onclick="saveFile()">✅ Salva</button>
            <button onclick="loadFile('${escapeHtml(path)}')">↺ Ricarica</button>
          </div>
        `;
      } else {
        // Modalità lettura
        contentDiv.innerHTML = `
          <h3>${escapeHtml(path)}</h3>
          <div class="code-with-linenumbers">
            <div class="code-linenumbers">${lineNumbersHtml}</div>
            <pre class="code-content">${codeLinesHtml}</pre>
          </div>
        `;
      }
    })
    .catch(() => {
      contentDiv.innerHTML = '<p style="color:red">❌ Impossibile caricare il file.</p>';
    });
}

// Funzioni di utilità
function saveFile() {
  const content = document.getElementById('codeEditor').innerText;
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=save_file&file=${encodeURIComponent(currentFilePath)}&content=${encodeURIComponent(content)}`
  }).then(r => r.text()).then(msg => {
    alert(msg === 'Salvato' ? '✅ File salvato!' : '❌ Errore.');
  });
}

function copyCode(filePath) {
  const content = document.getElementById('codeEditor')?.innerText || document.querySelector('.code-content').textContent;
  navigator.clipboard.writeText(content).then(() => alert('✅ Codice copiato!'));
}

function downloadFile(filePath) {
  const content = document.getElementById('codeEditor')?.innerText || document.querySelector('.code-content').textContent;
  const blob = new Blob([content], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filePath.split('/').pop();
  a.click();
  URL.revokeObjectURL(url);
}

// Funzioni per file
function deleteFile(path) {
  if (confirm('Eliminare ' + path + '?')) {
    fetch('api.php', { method: 'POST', body: `action=delete&file=${encodeURIComponent(path)}` }).then(() => location.reload());
  }
}
function renameFile(path) {
  const newName = prompt('Nuovo nome:', path.split('/').pop());
  if (newName) {
    fetch('api.php', { method: 'POST', body: `action=rename&file=${encodeURIComponent(path)}&new_name=${encodeURIComponent(newName)}` }).then(() => location.reload());
  }
}
function moveFile(path) { showMoveModal(path, 'file'); }
function copyFile(path) { showCopyModal(path); }

// Funzioni per cartelle
function renameFolder(path) {
  const newName = prompt('Nuovo nome cartella:', path.split('/').pop());
  if (newName) {
    fetch('api.php', { method: 'POST', body: `action=rename&file=${encodeURIComponent(path)}&new_name=${encodeURIComponent(newName)}` }).then(() => location.reload());
  }
}
function moveFolder(path) { showMoveModal(path, 'folder'); }

// Modali riutilizzabili
function showMoveModal(path, type) {
  const modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
  const content = document.createElement('div');
  content.style.cssText = 'background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;';
  content.innerHTML = `
    <h3>📁 Sposta ${type}</h3>
    <p>${type === 'file' ? 'File' : 'Cartella'}: <strong>${escapeHtml(path)}</strong></p>
    <label>Destinazione:</label>
    <select id="moveFolderSelect" style="width:100%;padding:6px;margin:8px 0;border:1px solid #ddd;border-radius:4px;">
      <option value="">-- Scegli --</option>
    </select>
    <div style="margin-top:15px;">
      <button id="moveConfirmBtn" style="background:#1a73e8;color:white;border:none;padding:8px 16px;border-radius:4px;margin-right:8px;">Sposta</button>
      <button id="moveCancelBtn" style="background:#f1f3f5;border:1px solid #ddd;padding:8px 16px;border-radius:4px;">Annulla</button>
    </div>
  `;
  modal.appendChild(content);
  document.body.appendChild(modal);

  fetch('?action=list-folders').then(r => r.json()).then(folders => {
    const select = document.getElementById('moveFolderSelect');
    folders.forEach(folder => {
      if (folder !== path && !folder.startsWith(path + '/')) {
        const opt = document.createElement('option');
        opt.value = folder;
        opt.textContent = folder;
        select.appendChild(opt);
      }
    });
  });

  document.getElementById('moveCancelBtn').onclick = () => document.body.removeChild(modal);
  document.getElementById('moveConfirmBtn').onclick = () => {
    const target = document.getElementById('moveFolderSelect').value;
    if (target) {
      document.body.removeChild(modal);
      fetch('api.php', { method: 'POST', body: `action=move&file=${encodeURIComponent(path)}&target=${encodeURIComponent(target)}` }).then(() => location.reload());
    } else alert('Seleziona una destinazione.');
  };
}

function showCopyModal(path) {
  const modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
  const content = document.createElement('div');
  content.style.cssText = 'background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;';
  content.innerHTML = `
    <h3>📋 Copia file</h3>
    <p>File: <strong>${escapeHtml(path)}</strong></p>
    <label>Destinazione:</label>
    <select id="copyFolderSelect" style="width:100%;padding:6px;margin:8px 0;border:1px solid #ddd;border-radius:4px;">
      <option value="">-- Scegli --</option>
    </select>
    <div style="margin-top:15px;">
      <button id="copyConfirmBtn" style="background:#1a73e8;color:white;border:none;padding:8px 16px;border-radius:4px;margin-right:8px;">Copia</button>
      <button id="copyCancelBtn" style="background:#f1f3f5;border:1px solid #ddd;padding:8px 16px;border-radius:4px;">Annulla</button>
    </div>
  `;
  modal.appendChild(content);
  document.body.appendChild(modal);

  fetch('?action=list-folders').then(r => r.json()).then(folders => {
    const select = document.getElementById('copyFolderSelect');
    folders.forEach(folder => {
      const opt = document.createElement('option');
      opt.value = folder;
      opt.textContent = folder;
      select.appendChild(opt);
    });
  });

  document.getElementById('copyCancelBtn').onclick = () => document.body.removeChild(modal);
  document.getElementById('copyConfirmBtn').onclick = () => {
    const target = document.getElementById('copyFolderSelect').value;
    if (target) {
      document.body.removeChild(modal);
      fetch('api.php', { method: 'POST', body: `action=copy&file=${encodeURIComponent(path)}&target=${encodeURIComponent(target)}` })
        .then(r => r.text())
        .then(msg => {
          if (msg === 'Copiato') alert('✅ Copiato!');
          else alert('❌ Errore: ' + msg);
          location.reload();
        });
    } else alert('Seleziona una destinazione.');
  };
}

// Costruzione albero
fetch('?action=list').then(r => r.json()).then(data => {
  const ul = document.getElementById('fileTree');
  if (data.__empty__) {
    ul.innerHTML = '<li style="color:#666; font-style:italic;">Nessun progetto presente.</li>';
  } else {
    ul.innerHTML = '';
    buildTree(data, ul);
  }
});

function buildTree(data, parent) {
  for (const name in data) {
    const item = data[name];
    const li = document.createElement('li');

    if (item.type === 'dir') {
      const folderDiv = document.createElement('div');
      folderDiv.style.display = 'flex';
      folderDiv.style.alignItems = 'center';

      const toggleSpan = document.createElement('span');
      toggleSpan.textContent = '+ ';
      toggleSpan.style.cursor = 'pointer';
      toggleSpan.style.marginRight = '6px';
      toggleSpan.style.fontWeight = 'bold';
      toggleSpan.style.color = '#586069';

      const span = document.createElement('span');
      span.className = 'folder';
      span.textContent = name;

      let isOpen = false;
      toggleSpan.onclick = () => {
        const ul = li.querySelector('ul');
        isOpen = !isOpen;
        ul.style.display = isOpen ? 'block' : 'none';
        toggleSpan.textContent = isOpen ? '- ' : '+ ';
      };

      folderDiv.appendChild(toggleSpan);
      folderDiv.appendChild(span);

      // Aggiungi pulsanti per cartelle (solo se loggato)
      <?php if ($loggedIn): ?>
      const actionsDiv = document.createElement('div');
      actionsDiv.className = 'file-actions';
      actionsDiv.innerHTML = `
        <button onclick="renameFolder('${item.path}')" title="Rinomina">🔄</button>
        <button onclick="moveFolder('${item.path}')" title="Sposta">📁</button>
      `;
      folderDiv.appendChild(actionsDiv);
      <?php endif; ?>

      li.appendChild(folderDiv);

      const nestedUl = document.createElement('ul');
      buildTree(item.children, nestedUl);
      li.appendChild(nestedUl);
    } else {
      const span = document.createElement('span');
      span.className = 'file';
      span.textContent = name;

      <?php if ($loggedIn): ?>
      span.innerHTML = `
        ${name}
        <div class="file-actions">
          <button onclick="editFile('${item.path}')" title="MODIFICA">✏️</button>
          <button onclick="copyFile('${item.path}')" title="COPIA">📋</button>
          <button onclick="deleteFile('${item.path}')" title="ELIMINA">🗑️</button>
          <button onclick="renameFile('${item.path}')" title="RINOMINA">🔄</button>
          <button onclick="moveFile('${item.path}')" title="SPOSTA">📁</button>
        </div>
      `;
      <?php endif; ?>

      span.onclick = () => loadFile(item.path);
      li.appendChild(span);
    }
    parent.appendChild(li);
  }
}

function editFile(path) { loadFile(path); }

// Ricerca
document.getElementById('searchBox')?.addEventListener('input', async function() {
  const query = this.value.trim();
  const resultsDiv = document.getElementById('searchResults');
  if (query.length < 2) {
    resultsDiv.style.display = 'none';
    return;
  }
  resultsDiv.style.display = 'block';
  resultsDiv.innerHTML = '<p>🔍 Ricerca in corso...</p>';

  try {
    const data = await (await fetch('?action=list')).json();
    const files = [];
    const collect = (obj, base = '') => {
      for (const name in obj) {
        const item = obj[name];
        const path = base ? `${base}/${name}` : name;
        if (item.type === 'file') files.push(path);
        else if (item.type === 'dir') collect(item.children, path);
      }
    };
    collect(data);

    const results = [];
    await Promise.all(files.map(async file => {
      try {
        const content = await (await fetch(`?action=file&file=${encodeURIComponent(file)}`)).text();
        content.split('\n').forEach((line, i) => {
          if (line.toLowerCase().includes(query.toLowerCase())) {
            results.push({ file, line: i + 1, content: line.trim() });
          }
        });
      } catch (e) {}
    }));

    if (results.length === 0) {
      resultsDiv.innerHTML = '<p>Nessun risultato.</p>';
    } else {
      results.sort((a, b) => a.file.localeCompare(b.file));
      let html = '<div style="font-size:13px;">';
      results.slice(0, 50).forEach(r => {
        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const highlighted = r.content.replace(new RegExp(`(${escapedQuery})`, 'gi'), '<mark style="background:#fff38d;">$1</mark>');
        html += `
          <div style="margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #eee; cursor:pointer;" 
               onclick="loadFileAndHighlight('${r.file}', ${r.line}, '${query.replace(/'/g, "\\'")}');">
            <div><strong>${r.file}</strong></div>
            <div style="color:#555; margin-top:4px;">Riga ${r.line}</div>
            <div style="margin-top:6px;"><code>${highlighted}</code></div>
          </div>
        `;
      });
      html += '</div>';
      resultsDiv.innerHTML = html;
      if (results.length > 0) loadFileAndHighlight(results[0].file, results[0].line, query);
    }
  } catch (error) {
    resultsDiv.innerHTML = '<p style="color:red;">Errore nella ricerca.</p>';
  }
});

function loadFileAndHighlight(filePath, lineNum, query) {
  loadFile(filePath);
  setTimeout(() => {
    const container = document.querySelector('.code-with-linenumbers');
    if (container) {
      const lineHeight = parseFloat(getComputedStyle(container.querySelector('.code-content')).lineHeight) || 20;
      container.scrollTop = (lineNum - 1) * lineHeight - container.clientHeight / 2 + lineHeight / 2;
    }
  }, 500);
}

// Mobile menu
const menuToggle = document.getElementById('menuToggle');
const closeSidebar = document.getElementById('closeSidebar');
const sidebar = document.querySelector('.sidebar');
if (menuToggle) {
  menuToggle.addEventListener('click', () => {
    sidebar.classList.add('active');
    menuToggle.style.display = 'none';
  });
}
if (closeSidebar) {
  closeSidebar.addEventListener('click', () => {
    sidebar.classList.remove('active');
    if (window.innerWidth <= 767) menuToggle.style.display = 'block';
  });
}
window.addEventListener('resize', () => {
  if (window.innerWidth <= 767 && !sidebar.classList.contains('active')) {
    menuToggle.style.display = 'block';
  } else if (window.innerWidth > 767) {
    menuToggle.style.display = 'none';
  }
});

function getLanguageFromPath(path) {
  const ext = path.split('.').pop().toLowerCase();
  const map = { 'js':'javascript', 'ts':'typescript', 'jsx':'javascript', 'tsx':'typescript', 'php':'php', 'html':'html', 'htm':'html', 'xml':'xml', 'css':'css', 'scss':'scss', 'sass':'sass', 'json':'json', 'yml':'yaml', 'yaml':'yaml', 'sql':'sql', 'py':'python', 'sh':'bash', 'md':'markdown', 'txt':'plaintext' };
  return map[ext] || 'plaintext';
}

// Scroll to top
let mybutton = document.getElementById("myBtn");
window.onscroll = () => {
  mybutton.style.display = (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) ? "block" : "none";
};
function topFunction() {
  document.body.scrollTop = 0;
  document.documentElement.scrollTop = 0;
}
</script>
</body>
</html>