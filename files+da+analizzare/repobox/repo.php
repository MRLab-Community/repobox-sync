<?php
// ✅ DEBUG MODE FORZATO - Mostra errori a schermo
error_reporting(E_ALL);
ini_set('display_errors', 1); // CAMBIATO DA 0 A 1

if (session_status() === PHP_SESSION_NONE) session_start();

// Includi config se esiste (per Admin e Colori)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback se config non c'è ancora
    define('REPOBOX_PROJECTS_DIR', __DIR__ . '/progetti');
}

function debugLog($message, $data = null) {
    // Scrivi sia a schermo che nel file
    echo "<div style='background:red; color:white; padding:5px; font-family:monospace;'>DEBUG: $message</div>";
    $logFile = __DIR__ . '/debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    if ($data !== null) $logEntry .= "DATA: " . print_r($data, true) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

$loggedIn = isset($_SESSION['repobox_user_id']);

// 2. Usa la costante definita in config.php invece di scriverla a mano
$baseDir = REPOBOX_PROJECTS_DIR; 

// 3. Recupera i colori dal DB se l'installazione è avvenuta
$headerColor = '#3f8e9b'; // Colore default
$sidebarColor = '#ffffff'; // Colore default

if (file_exists(__DIR__ . '/installed.json')) {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM repo_settings WHERE setting_key IN ('header_color', 'sidebar_color')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!empty($settings['header_color'])) $headerColor = $settings['header_color'];
        if (!empty($settings['sidebar_color'])) $sidebarColor = $settings['sidebar_color'];
    } catch (Exception $e) {
        // Se c'è errore (es. tabelle non create), usa i default
        debugLog("Errore lettura impostazioni tema: " . $e->getMessage());
    }
}

// Funzione validazione
function isValidPath($relPath, $baseDir) {
    $relPath = preg_replace('#\.\./|\\\\|\0#', '', $relPath);
    if (strpos($relPath, '/') === 0 || strpos($relPath, ':') !== false) return false;
    if (preg_match('/[<>|"\'\x00-\x1F]/', $relPath)) return false;
    $fullPath = $baseDir . '/' . ltrim($relPath, '/');
    if (file_exists($fullPath)) {
        $baseReal = str_replace('\\', '/', realpath($baseDir) . '/');
        $fullReal = str_replace('\\', '/', realpath($fullPath));
        return strpos($fullReal, $baseReal) === 0;
    }
    return strpos(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $baseDir)) === 0;
}

// Funzione ricorsiva per contare contenuti
function countContents($dir) {
    $count = ['files' => 0, 'folders' => 0];
    if (!is_dir($dir)) return $count;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $count['folders']++;
            $sub = countContents($path);
            $count['files'] += $sub['files'];
            $count['folders'] += $sub['folders'];
        } else {
            $count['files']++;
        }
    }
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debugLog("Metodo: POST");
    if (!$loggedIn) {
        http_response_code(403);
        exit('Accesso negato.');
    }

    $rawInput = file_get_contents('php://input');
    $postData = $_POST;
    if (empty($postData) && !empty($rawInput)) {
        parse_str($rawInput, $parsedData);
        $postData = array_merge($postData, $parsedData);
    }

    $action = $postData['action'] ?? '';
    debugLog("Azione: $action");

    switch ($action) {
        case 'create_folder':
            $name = $postData['name'] ?? '';
            $parent = $postData['parent'] ?? '';
            if (!$name) { http_response_code(400); echo 'Nome richiesto'; exit; }
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
            if (!$name) { http_response_code(400); echo 'Nome non valido'; exit; }
            $targetDir = $baseDir . '/' . ltrim($parent, '/');
            if ($parent !== '' && (!is_dir($targetDir) || !isValidPath($parent, $baseDir))) {
                http_response_code(400); echo 'Cartella padre non valida'; exit;
            }
            $newPath = $targetDir . '/' . $name;
            if (file_exists($newPath)) { http_response_code(400); echo 'Esiste già'; exit; }
            if (mkdir($newPath, 0755)) { echo 'Creato'; }
            else { http_response_code(500); echo 'Errore creazione'; }
            exit;

        case 'create_file':
            $name = $postData['name'] ?? '';
            $ext = $postData['ext'] ?? 'txt';
            $parent = $postData['parent'] ?? '';
            if (!$name) { http_response_code(400); echo 'Nome richiesto'; exit; }
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
            if (!$name) { http_response_code(400); echo 'Nome non valido'; exit; }
            $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            $fileName = $name . '.' . $ext;
            $targetDir = $baseDir . '/' . ltrim($parent, '/');
            if ($parent !== '' && (!is_dir($targetDir) || !isValidPath($parent, $baseDir))) {
                http_response_code(400); echo 'Cartella padre non valida'; exit;
            }
            $newPath = $targetDir . '/' . $fileName;
            if (file_exists($newPath)) { http_response_code(400); echo 'Esiste già'; exit; }
            if (touch($newPath)) { echo 'Creato'; }
            else { http_response_code(500); echo 'Errore creazione'; }
            exit;

        case 'delete_recursive':
            $file = $postData['file'] ?? '';
            $fullPath = $baseDir . '/' . $file;
            if (!isValidPath($file, $baseDir) || !file_exists($fullPath)) {
                http_response_code(400); echo 'Percorso non valido'; exit;
            }
            if (!is_dir($fullPath)) {
                if (unlink($fullPath)) echo 'Eliminato';
                else { http_response_code(500); echo 'Errore eliminazione'; }
                exit;
            }
            function deleteDir($path) {
                if (!is_dir($path)) return false;
                $files = scandir($path);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $full = $path . '/' . $file;
                    if (is_dir($full)) { deleteDir($full); } else { unlink($full); }
                }
                return rmdir($path);
            }
            if (deleteDir($fullPath)) { echo 'Eliminato'; }
            else { http_response_code(500); echo 'Errore durante l\'eliminazione ricorsiva'; }
            exit;

        case 'save_file':
            $file = $postData['file'] ?? '';
            $content = $postData['content'] ?? '';
            $fullPath = $baseDir . '/' . $file;
            if (isValidPath($file, $baseDir) && file_exists($fullPath) && is_file($fullPath)) {
                if (file_put_contents($fullPath, $content) !== false) echo 'Salvato';
                else { http_response_code(500); echo 'Errore scrittura'; }
            } else { http_response_code(400); echo 'File non valido'; }
            exit;

        case 'delete':
            // Eliminazione con Cestino
            $file = $postData['file'] ?? '';
            $fullPath = $baseDir . '/' . $file;
            $trashDir = $baseDir . '/.trash';
            
            if (!is_dir($trashDir)) { mkdir($trashDir, 0755, true); }
            if (!isValidPath($file, $baseDir) || !file_exists($fullPath)) {
                http_response_code(400); echo 'Percorso non valido'; exit;
            }

            $timestamp = date('Y-m-d_H-i-s_');
            $uniqueId = uniqid();
            $originalName = basename($fullPath);
            $trashName = $timestamp . $uniqueId . '_' . $originalName;
            $trashPath = $trashDir . '/' . $trashName;

            $metaData = [
                'original_name' => $originalName,
                'original_path' => $file,
                'deleted_at' => time(),
                'trash_name' => $trashName
            ];

            if (is_dir($fullPath)) {
                $destTrash = $trashDir . '/' . $timestamp . $uniqueId . '_' . str_replace('/', '_', $file);
                if (rename($fullPath, $destTrash)) {
                    $metaData['trash_name'] = basename($destTrash);
                    file_put_contents($trashDir . '/' . $metaData['trash_name'] . '.json', json_encode($metaData));
                    echo 'Eliminato (nel cestino)';
                } else {
                    http_response_code(500); echo 'Errore spostamento nel cestino';
                }
            } else {
                if (rename($fullPath, $trashPath)) {
                    file_put_contents($trashPath . '.json', json_encode($metaData));
                    echo 'Eliminato (nel cestino)';
                } else {
                    http_response_code(500); echo 'Errore spostamento nel cestino';
                }
            }
            exit;

        case 'list_trash':
            $trashDir = $baseDir . '/.trash';
            if (!is_dir($trashDir)) {
                header('Content-Type: application/json');
                echo json_encode([]);
                exit;
            }
            $items = scandir($trashDir);
            $trashFiles = [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if (substr($item, -5) === '.json') continue;
                
                $path = $trashDir . '/' . $item;
                $jsonPath = $path . '.json';
                $meta = null;
                
                if (file_exists($jsonPath)) {
                    $meta = json_decode(file_get_contents($jsonPath), true);
                }
                
                $trashFiles[] = [
                    'name' => $item,
                    'trash_name' => $item,
                    'original_name' => $meta ? $meta['original_name'] : preg_replace('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]+_/', '', $item),
                    'original_path' => $meta ? $meta['original_path'] : '',
                    'size' => is_file($path) ? filesize($path) : 0,
                    'date' => filemtime($path),
                    'is_dir' => is_dir($path)
                ];
            }
            header('Content-Type: application/json');
            echo json_encode($trashFiles);
            exit;

        case 'restore_trash':
            $filename = $postData['filename'] ?? '';
            $trashDir = $baseDir . '/.trash';
            $jsonFile = $trashDir . '/' . $filename . '.json';
            
            if (!file_exists($jsonFile)) {
                $cleanName = str_replace('.json', '', $filename);
                $jsonFile = $trashDir . '/' . $cleanName . '.json';
                $filename = $cleanName;
            }
            
            if (!file_exists($jsonFile)) {
                http_response_code(404); echo 'Metadati non trovati.'; exit;
            }

            $metaData = json_decode(file_get_contents($jsonFile), true);
            if (!$metaData) {
                http_response_code(500); echo 'Errore lettura metadati'; exit;
            }

            $trashPath = $trashDir . '/' . $metaData['trash_name'];
            if (!file_exists($trashPath)) {
                unlink($jsonFile);
                http_response_code(404); echo 'File nel cestino non trovato'; exit;
            }

            $restorePath = $baseDir . '/' . $metaData['original_path'];
            $restoreDir = dirname($restorePath);
            
            if (!is_dir($restoreDir)) {
                mkdir($restoreDir, 0755, true);
            }
            
            if (file_exists($restorePath)) {
                $ext = pathinfo($metaData['original_name'], PATHINFO_EXTENSION);
                $nameWithoutExt = pathinfo($metaData['original_name'], PATHINFO_FILENAME);
                $restorePath = $restoreDir . '/' . $nameWithoutExt . '_restored_' . time() . ($ext ? '.' . $ext : '');
            }

            if (rename($trashPath, $restorePath)) {
                unlink($jsonFile);
                echo 'Ripristinato';
            } else {
                http_response_code(500); echo 'Errore nel ripristino';
            }
            exit;

        case 'empty_trash':
            $trashDir = $baseDir . '/.trash';
            if (is_dir($trashDir)) {
                function deleteDir($path) {
                    if (!is_dir($path)) return false;
                    $files = scandir($path);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') continue;
                        $full = $path . '/' . $file;
                        if (is_dir($full)) { deleteDir($full); } else { unlink($full); }
                    }
                    return rmdir($path);
                }
                deleteDir($trashDir);
                mkdir($trashDir, 0755, true);
                echo 'Cestino svuotato';
            } else {
                echo 'Cestino già vuoto';
            }
            exit;

        case 'check_folder':
            $file = $postData['file'] ?? '';
            $fullPath = $baseDir . '/' . $file;
            if (!isValidPath($file, $baseDir) || !is_dir($fullPath)) {
                http_response_code(400); echo 'Cartella non valida'; exit;
            }
            $count = countContents($fullPath);
            header('Content-Type: application/json');
            echo json_encode([
                'isEmpty' => ($count['files'] === 0 && $count['folders'] === 0),
                'files' => $count['files'],
                'folders' => $count['folders']
            ]);
            exit;

        case 'move_batch':
            $filesJson = $postData['files'] ?? '[]';
            $target = $postData['target'] ?? '';
            $filesToMove = json_decode($filesJson, true);
            if (!is_array($filesToMove) || empty($target)) { http_response_code(400); echo 'Dati non validi'; exit; }
            $targetPath = $baseDir . '/' . ltrim($target, '/');
            if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
            $movedCount = 0;
            foreach ($filesToMove as $f) {
                $src = $baseDir . '/' . $f;
                if (isValidPath($f, $baseDir) && file_exists($src) && is_file($src)) {
                    $dest = $targetPath . '/' . basename($f);
                    if (rename($src, $dest)) $movedCount++;
                }
            }
            echo "Spostati $movedCount file";
            exit;

        case 'rename':
            $file = $postData['file'] ?? '';
            $newName = $postData['new_name'] ?? '';
            if (!$newName) { http_response_code(400); echo 'Nome richiesto'; exit; }
            $fullPath = $baseDir . '/' . $file;
            $newPath = dirname($fullPath) . '/' . $newName;
            if (isValidPath($file, $baseDir) && file_exists($fullPath)) {
                if (rename($fullPath, $newPath)) echo 'Rinominato';
                else { http_response_code(500); echo 'Errore rename'; }
            } else { http_response_code(400); echo 'Percorso non valido'; }
            exit;

        case 'move':
        case 'copy':
            $file = $postData['file'] ?? '';
            $target = $postData['target'] ?? '';
            if (!$target) { http_response_code(400); echo 'Destinazione richiesta'; exit; }
            $fullPath = $baseDir . '/' . $file;
            $targetPath = $baseDir . '/' . ltrim($target, '/');
            if (!isValidPath($file, $baseDir) || !file_exists($fullPath)) { http_response_code(400); echo 'Origine non valida'; exit; }
            if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
            if (!isValidPath($target, $baseDir)) { http_response_code(400); echo 'Destinazione non valida'; exit; }
            $newPath = $targetPath . '/' . basename($fullPath);
            if ($action === 'move') {
                echo rename($fullPath, $newPath) ? 'Spostato' : 'Errore spostamento';
            } else {
                echo copy($fullPath, $newPath) ? 'Copiato' : 'Errore copia';
            }
            exit;
            
        default:
            http_response_code(400);
            echo 'Azione non riconosciuta';
            exit;
    }
}
// --- FINE GESTIONE POST ---

function isUserLoggedIn() { return isset($_SESSION['repobox_user_id']); }
$loggedIn = isUserLoggedIn();
$baseDir = __DIR__ . '/progetti';

function listFolderFiles($dir) {
    $arr = [];
    if (!is_dir($dir)) return $arr;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || strpos($item, '.') === 0) continue;
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

// ✅ AGGIUNGI QUESTO BLOCCO PER IL CESTINO
if (isset($_GET['action']) && $_GET['action'] === 'list_trash') {
    header('Content-Type: application/json');
    $trashDir = $baseDir . '/.trash';
    
    // Se la cartella non esiste, ritorna array vuoto
    if (!is_dir($trashDir)) {
        echo json_encode([]);
        exit;
    }

    $items = scandir($trashDir);
    $trashFiles = [];
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Ignora i file .json dei metadati nella lista visiva
        if (substr($item, -5) === '.json') continue;
        
        $path = $trashDir . '/' . $item;
        $jsonPath = $path . '.json';
        $meta = null;
        
        // Tenta di leggere i metadati se esistono
        if (file_exists($jsonPath)) {
            $meta = json_decode(file_get_contents($jsonPath), true);
        }
        
        $trashFiles[] = [
            'name' => $item, // Nome tecnico (es. 2026-03-12_..._file.php)
            'trash_name' => $item,
            // Usa il nome originale dai metadati, altrimenti pulisci il prefisso data
            'original_name' => $meta ? $meta['original_name'] : preg_replace('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]+_/', '', $item),
            'original_path' => $meta ? $meta['original_path'] : '',
            'size' => is_file($path) ? filesize($path) : 0,
            'date' => filemtime($path),
            'is_dir' => is_dir($path)
        ];
    }
    echo json_encode($trashFiles);
    exit;
}

// ... (continua con 'list-folders' se c'è) ...

if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    $files = listFolderFiles($baseDir);
    echo json_encode(empty($files) ? ['__empty__' => ['type' => 'empty']] : $files, JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'file') {
    $file = $_GET['file'] ?? '';
    $fullPath = $baseDir . '/' . $file;
    $realBase = realpath($baseDir);
    $realFile = realpath($fullPath);
    if ($realFile && strpos($realFile, $realBase) === 0 && is_file($realFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($realFile);
    } else { 
        http_response_code(404); 
        echo "File non trovato."; 
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'list-folders') {
    header('Content-Type: application/json');
    function getAllFolders($dir, $basePath = '') {
        $folders = [];
        if (!is_dir($dir)) return $folders;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..' || strpos($item, '.') === 0) continue;
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
.sidebar { width: 100%; background: <?php echo htmlspecialchars($sidebarColor); ?>; border-bottom: 1px solid #e1e4e8; padding: 16px; }
@media (min-width: 768px) { .sidebar { width: 300px; height: 100vh; border-right: 1px solid #e1e4e8; overflow-y: auto; position: sticky; top: 0; } }
.content { flex: 1; padding: 16px; background: white; min-width: 0; }
h3 { font-size: 14px; font-weight: 600; color: #586069; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; }
.file-tree { list-style: none; font-size: 14px; }
.file-tree li { margin: 4px 0; }
.folder, .file { display: flex; align-items: center; padding: 4px 8px; border-radius: 6px; cursor: pointer; color: #24292f; position: relative; }
.folder:hover, .file:hover { background: #f1f3f5; }
.folder.selected, .file.selected { background: #e1f0ff; border: 1px solid #0366d6; }
.folder::before { content: "📁"; margin-right: 6px; display: inline-block; width: 16px; text-align: center; }
.file::before { content: "📄"; margin-right: 6px; opacity: 0.8; }
.file-tree ul { margin-left: 18px; display: none; }
.menu-toggle { display: none; position: fixed; top: 10px; left: 10px; z-index: 100; padding: 8px 12px; color: darkslategrey; border: none; border-radius: 4px; font-size: 18px; cursor: pointer; }
@media (max-width: 767px) { .menu-toggle { display: block; } .sidebar { position: fixed; left: -300px; top: 0; bottom: 0; width: 280px; z-index: 99; box-shadow: 2px 0 10px rgba(0,0,0,0.2); transition: left 0.3s ease; overflow-y: auto; padding: 16px; box-sizing: border-box; } .sidebar.active { left: 0; } .content { padding-top: 60px; } }
.sidebar-header { display: flex; justify-content: space-between; align-items: center; padding-right: 10px; }
.close-sidebar { display: none; background: none; border: none; color: #d32f2f; font-size: 24px; cursor: pointer; width: 30px; height: 30px; }
.sidebar.active .close-sidebar { display: block; }
.code-with-linenumbers { display: flex; background: #f8f8f8; border: 1px solid #e1e4e8; border-radius: 6px; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; margin: 0; }
.code-linenumbers { text-align: right; padding: 16px 8px; background: #f0f0f0; color: #999; user-select: none; border-right: 1px solid #e1e4e8; white-space: normal; flex-shrink: 0; width: 50px; }
.code-content { padding: 16px; white-space: pre; outline: none; flex-grow: 1; margin: 0; }
.empty { color: #6a737d; font-style: italic; }
.btn { display: inline-block; background: #1a73e8; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; margin-top: 8px; cursor: pointer; border: none; }
.btn:hover { background: #1557b0; }
.btn-sm { padding: 6px 12px; font-size: 13px; margin-right: 5px; }
.btn-success { background: #28a745; }
.btn-success:hover { background: #218838; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.header h1 { font-size: 18px; }
.file-actions { display: none; position: absolute; right: -12px; top: -13px; background: white; border: 1px solid #ddd; border-radius: 4px; padding: 2px; font-size: 12px; z-index: 10; }
.folder-container:hover .file-actions, .file:hover .file-actions { display: block; }
.file-actions button { background: none; border: none; cursor: pointer; padding: 2px 4px; margin: 0 1px; border-radius: 3px; font-size: 12px; }
.file-actions button:hover { background: #f0f0f0; }
.editor-toolbar { margin-bottom: 10px; }
.editor-toolbar button { margin-right: 8px; padding: 6px 12px; }
.line-number, .code-line { min-height: 1.6em; white-space: pre; padding: 0; margin: 0; display: block; }
.site-footer { background-color: #2e808d; color: white; text-align: center; padding: 0.3em 0; width: 100%; }
.site-header { background-color: <?php echo htmlspecialchars($headerColor); ?>; color: white; text-align: left; padding: 0.8em 0; width: 100%; }
.site-logo { padding-left: 20px; font-size: 25px; font-weight: bold; }
#myBtn { display: none; position: fixed; bottom: 30px; right: 30px; z-index: 99; border: none; outline: none; cursor: pointer; padding: 15px; font-size: 20px; background: none; opacity: 0.5; }
#myBtn:hover { opacity: 0.8; }
@media (max-width: 767px) { #myBtn { bottom: 60px; right: -5px; } }
html { scrollbar-width: thin; scrollbar-color: transparent transparent; }
html:hover { scrollbar-color: #a0a0a0 #f0f0f0; }
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background-color: transparent; border-radius: 4px; }
html:hover ::-webkit-scrollbar-thumb { background-color: #a0a0a0; }
html:hover ::-webkit-scrollbar-track { background: #f0f0f0; }
/* Modal Styles */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center; }
.modal-box { background: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
.modal-box h3 { margin-top: 0; color: #24292f; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px; }
.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
.modal-actions { text-align: right; margin-top: 20px; }
</style>
<!-- Highlight.js (già presente) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<!-- AGGIUNGI QUESTE DUE RIGHE PER ACE EDITOR -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ext-language_tools.js"></script>
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
            <div style="margin-bottom: 10px; display:flex; gap:5px; flex-wrap:wrap;">
                <button class="btn btn-sm btn-success" onclick="promptCreateFolder()">➕ Cartella</button>
                <button class="btn btn-sm" onclick="promptCreateFile()">📄 File</button>
            </div>
            <a href="upload.php" class="btn" style="width:100%; text-align:center; margin-bottom:5px;">📤 Carica Plugin</a>
            <a href="sync-to-github.php" class="btn" style="background:#28a745; width:100%; text-align:center; margin-top:0;">🔄 Sync GitHub</a>
            <!-- Nella sidebar, dopo Sync GitHub -->
<?php if (isset($_SESSION['repobox_role']) && $_SESSION['repobox_role'] === 'admin'): ?>
    <a href="admin.php" class="btn" style="background:#6c757d; width:100%; text-align:center; margin-top:5px;">⚙️ Pannello Admin</a>
<?php endif; ?>
            <!-- Pulsante Cestino -->
            <button onclick="openTrashModal()" class="btn" style="background:#ff751a; width:100%; text-align:center; margin-top:5px;">🗑️ Cestino</button>
            
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
let currentParentPath = '';

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// --- FUNZIONI DI CREAZIONE ---
function selectFolder(path, element) {
    currentParentPath = path;
    document.querySelectorAll('.folder.selected, .file.selected').forEach(el => el.classList.remove('selected'));
    if(element) element.classList.add('selected');
}

function promptCreateFolder() {
    const parent = currentParentPath || '';
    const parentName = parent ? parent.split('/').pop() : 'Radice';
    const name = prompt(`Creare una nuova cartella in "${parentName}"?\nInserisci il nome:`);
    if (!name) return;
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=create_folder&name=${encodeURIComponent(name)}&parent=${encodeURIComponent(parent)}`
    })
    .then(r => r.text())
    .then(msg => {
        if (msg === 'Creato') { refreshTree(); alert('✅ Cartella creata!'); }
        else { alert('❌ Errore: ' + msg); }
    });
}

function promptCreateFile() {
    const parent = currentParentPath || '';
    const parentName = parent ? parent.split('/').pop() : 'Radice';
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-box">
            <h3>📄 Nuovo File in "${parentName}"</h3>
            <div class="form-group">
                <label>Nome file:</label>
                <input type="text" id="newFileName" placeholder="es. index" autofocus>
            </div>
            <div class="form-group">
                <label>Estensione:</label>
                <select id="newFileExt">
                    <option value="php">php</option>
                    <option value="html">html</option>
                    <option value="css">css</option>
                    <option value="js">js</option>
                    <option value="json">json</option>
                    <option value="txt">txt</option>
                    <option value="sql">sql</option>
                    <option value="md">md</option>
                    <option value="xml">xml</option>
                    <option value="py">py</option>
                    <option value="sh">sh</option>
                </select>
            </div>
            <div class="modal-actions">
                <button class="btn" style="background:#ccc; color:#333;" onclick="this.closest('.modal-overlay').remove()">Annulla</button>
                <button class="btn btn-success" onclick="confirmCreateFile('${parent}')">Crea</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    const input = document.getElementById('newFileName');
    input.focus();
    input.addEventListener('keypress', function (e) { if (e.key === 'Enter') confirmCreateFile(parent); });
}

function confirmCreateFile(parent) {
    const name = document.getElementById('newFileName').value.trim();
    const ext = document.getElementById('newFileExt').value;
    if (!name) { alert('Inserisci un nome!'); return; }
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=create_file&name=${encodeURIComponent(name)}&ext=${encodeURIComponent(ext)}&parent=${encodeURIComponent(parent)}`
    })
    .then(r => r.text())
    .then(msg => {
        document.querySelectorAll('.modal-overlay').forEach(m => m.remove());
        if (msg === 'Creato') { refreshTree(); alert('✅ File creato!'); }
        else { alert('❌ Errore: ' + msg); }
    });
}

// --- CARICAMENTO FILE ---
function loadFile(path) {
    currentFilePath = path;
    currentParentPath = path.substring(0, path.lastIndexOf('/'));
    document.querySelectorAll('.folder.selected, .file.selected').forEach(el => el.classList.remove('selected'));
    
    const contentDiv = document.getElementById('fileContent');
    contentDiv.innerHTML = '<p>🔄 Caricamento...</p>';
    
    fetch(`?action=file&file=${encodeURIComponent(path)}`)
        .then(r => {
            if (!r.ok) throw new Error('File non trovato');
            return r.text();
        })
        .then(code => {
            const lang = getLanguageFromPath(path);
            const lines = code.split('\n');
            const isLoggedIn = <?php echo $loggedIn ? 'true' : 'false'; ?>;

            const tempCode = document.createElement('code');
            tempCode.className = `language-${lang}`;
            tempCode.textContent = code;
            hljs.highlightElement(tempCode);
            const highlightedHtml = tempCode.innerHTML;

            const lineNumbersHtml = lines.map((_, i) => `<div class="line-number">${i + 1}</div>`).join('');
            const codeLinesHtml = highlightedHtml.split('\n').map(line => line ? `<div class="code-line">${line}</div>` : '<div class="code-line">&nbsp;</div>').join('');

            if (isLoggedIn) {
    // Genera l'HTML del contenitore per Ace
    contentDiv.innerHTML = `
        <h3>${escapeHtml(path)}
            <div style="float:right; font-size:12px;">
                <button onclick="copyCode()" title="Copia codice">📋</button>
                <button onclick="downloadFile('${escapeHtml(path)}')" title="Download file">⬇️</button>
            </div>
        </h3>
        <div class="editor-toolbar">
            <button onclick="saveFile()">✅ Salva</button>
            <button onclick="loadFile('${escapeHtml(path)}')">↺ Ricarica</button>
        </div>
        <!-- Contenitore per Ace Editor -->
        <div id="ace-editor-container" style="position: relative; width: 100%; height: 600px; border: 1px solid #e1e4e8; border-radius: 6px;"></div>
        <div class="editor-toolbar" style="margin-top:16px;">
            <button onclick="saveFile()">✅ Salva</button>
            <button onclick="loadFile('${escapeHtml(path)}')">↺ Ricarica</button>
        </div>
    `;

    // Inizializzazione Ace Editor
    // Attendiamo un attimo che il DOM sia pronto
    setTimeout(() => {
        const editorElement = document.querySelector('#ace-editor-container');
        if (!editorElement) return;

        // Crea l'istanza di Ace
        window.repoEditor = ace.edit("ace-editor-container");
        
        // Configura tema e modalità
        window.repoEditor.setTheme("ace/theme/github"); // Tema chiaro simile al tuo
        window.repoEditor.session.setMode("ace/mode/" + getLanguageFromPath(path));
        
        // Imposta il contenuto
        window.repoEditor.setValue(code, -1); // -1 sposta il cursore all'inizio
        
        // Opzioni avanzate
        window.repoEditor.setOptions({
            fontSize: "13px",
            showPrintMargin: false,
            wrap: false,
            enableBasicAutocompletion: true,
            enableLiveAutocompletion: true,
            highlightActiveLine: true,
            highlightGutterLine: true,
            tabSize: 4
        });
        
        // Rimuovi il focus iniziale se vuoi
        // window.repoEditor.blur(); 
    }, 50);
} 
else {
    // Modalità lettura (invariata, usa highlight.js statico)
    const tempCode = document.createElement('code');
    tempCode.className = `language-${lang}`;
    tempCode.textContent = code;
    hljs.highlightElement(tempCode);
    const highlightedHtml = tempCode.innerHTML;
    const lineNumbersHtml = lines.map((_, i) => `<div class="line-number">${i + 1}</div>`).join('');
    const codeLinesHtml = highlightedHtml.split('\n').map(line => line ? `<div class="code-line">${line}</div>` : '<div class="code-line">&nbsp;</div>').join('');

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

// --- OPERAZIONI FILE ---
// --- OPERAZIONI FILE AGGIORNATE PER ACE ---

function saveFile() {
    if (!window.repoEditor) {
        alert('Nessun editor attivo!');
        return;
    }
    // Ace usa getValue() per ottenere il testo
    const content = window.repoEditor.getValue();
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=save_file&file=${encodeURIComponent(currentFilePath)}&content=${encodeURIComponent(content)}`
    })
    .then(r => r.text())
    .then(msg => {
        if (msg === 'Salvato') alert('✅ File salvato!');
        else alert('❌ Errore: ' + msg);
    })
    .catch(err => alert('❌ Errore di connessione'));
}

function copyCode() {
    if (!window.repoEditor) return;
    const content = window.repoEditor.getValue();
    navigator.clipboard.writeText(content).then(() => alert('✅ Codice copiato!'));
}

function downloadFile(filePath) {
    if (!window.repoEditor) return;
    const content = window.repoEditor.getValue();
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filePath.split('/').pop();
    a.click();
    URL.revokeObjectURL(url);
}

function deleteFile(path) {
    if (confirm('Spostare "' + path + '" nel cestino?')) {
        fetch('', { method: 'POST', body: `action=delete&file=${encodeURIComponent(path)}` })
        .then(r => r.text())
        .then(msg => {
            if(msg.includes('Eliminato')) {
                removeNodeFromTree(path);
                // Se il file eliminato era aperto, pulisci l'editor
                if(currentFilePath === path) {
                    document.getElementById('fileContent').innerHTML = '<p class="empty">File eliminato. Seleziona un altro file.</p>';
                    currentFilePath = '';
                }
                alert('✅ File spostato nel cestino.');
            } else {
                alert('❌ Errore: ' + msg);
            }
        })
        .catch(err => alert('Errore di connessione'));
    }
}

function renameFile(path) {
    const newName = prompt('Nuovo nome:', path.split('/').pop());
    if (newName) {
        fetch('', { method: 'POST', body: `action=rename&file=${encodeURIComponent(path)}&new_name=${encodeURIComponent(newName)}` })
        .then(r => r.text())
        .then(msg => {
            if(msg === 'Rinominato') { refreshTree(); alert('✅ Rinominato'); } 
            else { alert('❌ Errore: ' + msg); }
        });
    }
}

function moveFile(path) { showMoveModal(path, 'file'); }
function copyFile(path) { showCopyModal(path); }
function renameFolder(path) {
    const newName = prompt('Nuovo nome cartella:', path.split('/').pop());
    if (newName) {
        fetch('', { method: 'POST', body: `action=rename&file=${encodeURIComponent(path)}&new_name=${encodeURIComponent(newName)}` })
        .then(r => r.text())
        .then(msg => {
            if(msg === 'Rinominato') { refreshTree(); alert('✅ Rinominata'); } 
            else { alert('❌ Errore: ' + msg); }
        });
    }
}

// --- ELIMINAZIONE INTELLIGENTE CARTELLE ---
function deleteFolderSmart(path) {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=check_folder&file=${encodeURIComponent(path)}` })
    .then(r => { if (!r.ok) throw new Error('Server error'); return r.json(); })
    .then(data => {
        if (data.isEmpty) {
            if(confirm(`Eliminare la cartella vuota "${path.split('/').pop()}"?`)) performDelete(path);
        } else { showSmartDeleteModal(path, data); }
    })
    .catch(err => alert('Errore verifica: ' + err.message));
}

function performDelete(path) {
    fetch('', { method: 'POST', body: `action=delete&file=${encodeURIComponent(path)}` })
    .then(r => r.text())
    .then(msg => {
        if(msg.includes('Eliminato')) {
            removeNodeFromTree(path);
            // Se la cartella eliminata era aperta o genitore, pulisci
            if(currentFilePath.startsWith(path + '/')) {
                document.getElementById('fileContent').innerHTML = '<p class="empty">Contenuto eliminato.</p>';
                currentFilePath = '';
            }
            alert('✅ Eliminato');
        } else {
            alert('❌ Errore: ' + msg);
        }
    });
}

// --- GESTIONE CESTINO (FUNZIONI MANCANTI AGGIUNTE) ---
// --- FUNZIONI CESTINO (AGGIUNGI QUESTE) ---

function openTrashModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-box" style="max-width:600px;">
            <h3>🗑️ Cestino</h3>
            <p style="font-size:13px; color:#666; margin-bottom:15px;">I file eliminati vengono spostati qui. Svuota il cestino per eliminarli definitivamente.</p>
            <div id="trashList" style="max-height:300px; overflow-y:auto; border:1px solid #eee; margin-bottom:15px; min-height:100px;"></div>
            <div class="modal-actions">
                <button class="btn" style="background:#d32f2f;" onclick="emptyTrash()">🗑️ Svuota Cestino</button>
                <button class="btn" style="background:#ccc; color:#333;" onclick="this.closest('.modal-overlay').remove()">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    loadTrashContent();
}

function loadTrashContent() {
    const list = document.getElementById('trashList');
    list.innerHTML = '<p style="text-align:center;">Caricamento...</p>';
    
    fetch('?action=list_trash')
    .then(r => {
        if (!r.ok) throw new Error('Errore server');
        return r.json();
    })
    .then(files => {
        list.innerHTML = '';
        if (files.length === 0) {
            list.innerHTML = '<p style="text-align:center; color:#999; padding:20px;">Il cestino è vuoto.</p>';
            return;
        }
        files.forEach(f => {
            const displayName = f.original_name || f.name.replace(/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]+_/, '');
            const dateStr = new Date(f.date * 1000).toLocaleString();
            const div = document.createElement('div');
            div.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:8px; border-bottom:1px solid #f0f0f0;';
            div.innerHTML = `
                <span>${f.is_dir ? '📁' : '📄'} ${escapeHtml(displayName)} <small style="color:#999">(${dateStr})</small></span>
                <button class="btn-sm" style="background:#28a745; color:white;" onclick="restoreTrashItem('${f.trash_name}')">↺ Ripristina</button>
            `;
            list.appendChild(div);
        });
    })
    .catch(err => {
        console.error(err);
        list.innerHTML = '<p style="color:red; text-align:center;">Errore nel caricamento del cestino.</p>';
    });
}

function restoreTrashItem(trashName) {
    if(!confirm('Ripristinare questo file nella sua posizione originale?')) return;
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=restore_trash&filename=${encodeURIComponent(trashName)}`
    })
    .then(r => r.text())
    .then(msg => {
        if(msg === 'Ripristinato') {
            alert('✅ File ripristinato!');
            loadTrashContent(); // Ricarica la lista
            refreshTree();      // Aggiorna l'albero principale
        } else {
            alert('❌ Errore: ' + msg);
        }
    });
}

function emptyTrash() {
    if(!confirm('⚠️ ATTENZIONE: Sei sicuro di voler eliminare DEFINITIVAMENTE tutto il contenuto del cestino? Questa azione è irreversibile.')) return;
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=empty_trash`
    })
    .then(r => r.text())
    .then(msg => {
        alert(msg);
        loadTrashContent();
    });
}

function showSmartDeleteModal(path, info) {
    const folderName = path.split('/').pop();
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:2000;';
    modal.innerHTML = `
        <div style="background:white;padding:25px;border-radius:8px;max-width:500px;width:90%;">
            <h3 style="color:#d32f2f;">⚠️ Cartella Non Vuota</h3>
            <p>Contiene: ${info.files} file, ${info.folders} cartelle.</p>
            <div style="display:flex; flex-direction:column; gap:10px; margin-top:20px;">
                <button id="btn-delete-all" style="background:#d32f2f;color:white;border:none;padding:12px;border-radius:4px;">🗑️ Elimina TUTTO</button>
                <button id="btn-move-then-delete" style="background:#f57c00;color:white;border:none;padding:12px;border-radius:4px;">📦 Sposta contenuto ed elimina</button>
                <button id="btn-cancel-delete" style="background:#eee;border:1px solid #ccc;padding:10px;border-radius:4px;">Annulla</button>
            </div>
        </div>`;
    document.body.appendChild(modal);
    document.getElementById('btn-cancel-delete').onclick = () => document.body.removeChild(modal);
    document.getElementById('btn-move-then-delete').onclick = () => { document.body.removeChild(modal); showMoveContentModal(path); };
    document.getElementById('btn-delete-all').onclick = () => {
        if(confirm('⚠️ ATTENZIONE: Azione IRREVERSIBILE. Procedere?')) {
            document.body.removeChild(modal);
            fetch('', { method: 'POST', body: `action=delete_recursive&file=${encodeURIComponent(path)}` })
            .then(r => r.text())
            .then(msg => { if(msg === 'Eliminato') { removeNodeFromTree(path); alert('✅ Eliminato'); } else { alert('❌ Errore: ' + msg); } });
        }
    };
}

function showMoveContentModal(folderPath) {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:2000;';
    modal.innerHTML = `
        <div style="background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;">
            <h3>Sposta contenuto di "${folderPath.split('/').pop()}"</h3>
            <select id="destFolderSelect" style="width:100%;padding:8px;margin:10px 0;"></select>
            <div style="text-align:right;">
                <button id="confirmMoveBtn" style="background:#1a73e8;color:white;border:none;padding:8px 16px;border-radius:4px;">Sposta ed Elimina</button>
                <button id="cancelMoveBtn" style="background:#eee;border:1px solid #ccc;padding:8px 16px;border-radius:4px;">Annulla</button>
            </div>
        </div>`;
    document.body.appendChild(modal);
    fetch('?action=list-folders').then(r=>r.json()).then(folders => {
        const sel = document.getElementById('destFolderSelect');
        folders.forEach(f => { if(f !== folderPath && !f.startsWith(folderPath + '/')) { const opt = document.createElement('option'); opt.value = f; opt.textContent = f; sel.appendChild(opt); }});
    });
    document.getElementById('cancelMoveBtn').onclick = () => document.body.removeChild(modal);
    document.getElementById('confirmMoveBtn').onclick = () => {
        const target = document.getElementById('destFolderSelect').value;
        if(!target) return alert('Seleziona destinazione');
        fetch('?action=list').then(r=>r.json()).then(data => {
            const filesToMove = extractFilesFromTree(data, folderPath);
            if(filesToMove.length === 0) { performDelete(folderPath); document.body.removeChild(modal); return; }
            fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=move_batch&files=${encodeURIComponent(JSON.stringify(filesToMove))}&target=${encodeURIComponent(target)}` })
            .then(r => r.text()).then(msg => { if(msg.includes('Spostati')) performDelete(folderPath); else alert('Errore: '+msg); document.body.removeChild(modal); });
        });
    };
}

function extractFilesFromTree(tree, basePath) {
    let files = [];
    const collect = (node, currentPath) => {
        for(const name in node) {
            const item = node[name];
            const fullPath = currentPath ? `${currentPath}/${name}` : name;
            if(fullPath.startsWith(basePath + '/') && item.type === 'file') files.push(fullPath);
            else if(item.type === 'dir') collect(item.children, fullPath);
        }
    };
    collect(tree, '');
    return files;
}

function removeNodeFromTree(path) { refreshTree(); }

function refreshTree() {
    // 1. SALVA LO STATO: Leggi i dataset.path di tutti i LI che hanno un UL visibile dentro
    const openFolders = [];
    document.querySelectorAll('#fileTree li').forEach(li => {
        const ul = li.querySelector('ul');
        if (ul && ul.style.display === 'block' && li.dataset.path) {
            openFolders.push(li.dataset.path);
        }
    });

    // 2. RICARICA I DATI
    fetch('?action=list').then(r => r.json()).then(data => {
        const ul = document.getElementById('fileTree');
        ul.innerHTML = '';
        
        // 3. RICOSTRUISCI PASSANDO LA LISTA
        buildTree(data, ul, '', openFolders);
    });
}

function showMoveModal(path, type) {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
    modal.innerHTML = `<div style="background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;"><h3>Sposta ${type}</h3><p>${escapeHtml(path)}</p><select id="moveFolderSelect" style="width:100%;padding:6px;margin:8px 0;"></select><button id="moveConfirmBtn" style="background:#1a73e8;color:white;border:none;padding:8px 16px;border-radius:4px;">Sposta</button> <button id="moveCancelBtn" style="background:#eee;border:1px solid #ccc;padding:8px 16px;border-radius:4px;">Annulla</button></div>`;
    document.body.appendChild(modal);
    fetch('?action=list-folders').then(r => r.json()).then(folders => {
        const select = document.getElementById('moveFolderSelect');
        folders.forEach(folder => { if (folder !== path && !folder.startsWith(path + '/')) { const opt = document.createElement('option'); opt.value = folder; opt.textContent = folder; select.appendChild(opt); }});
    });
    document.getElementById('moveCancelBtn').onclick = () => document.body.removeChild(modal);
    document.getElementById('moveConfirmBtn').onclick = () => {
        const target = document.getElementById('moveFolderSelect').value;
        if (target) { document.body.removeChild(modal); fetch('', { method: 'POST', body: `action=move&file=${encodeURIComponent(path)}&target=${encodeURIComponent(target)}` }).then(r => r.text()).then(msg => { if(msg.includes('Spostato')) { refreshTree(); alert('✅ Spostato'); } else alert('Errore: ' + msg); }); }
    };
}

function showCopyModal(path) {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
    modal.innerHTML = `<div style="background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;"><h3>Copia file</h3><p>${escapeHtml(path)}</p><select id="copyFolderSelect" style="width:100%;padding:6px;margin:8px 0;"></select><button id="copyConfirmBtn" style="background:#1a73e8;color:white;border:none;padding:8px 16px;border-radius:4px;">Copia</button> <button id="copyCancelBtn" style="background:#eee;border:1px solid #ccc;padding:8px 16px;border-radius:4px;">Annulla</button></div>`;
    document.body.appendChild(modal);
    fetch('?action=list-folders').then(r => r.json()).then(folders => {
        const select = document.getElementById('copyFolderSelect');
        folders.forEach(folder => { const opt = document.createElement('option'); opt.value = folder; opt.textContent = folder; select.appendChild(opt); });
    });
    document.getElementById('copyCancelBtn').onclick = () => document.body.removeChild(modal);
    document.getElementById('copyConfirmBtn').onclick = () => {
        const target = document.getElementById('copyFolderSelect').value;
        if (target) { document.body.removeChild(modal); fetch('', { method: 'POST', body: `action=copy&file=${encodeURIComponent(path)}&target=${encodeURIComponent(target)}` }).then(r => r.text()).then(msg => { if (msg === 'Copiato') { alert('✅ Copiato!'); refreshTree(); } else alert('❌ Errore: ' + msg); }); }
    };
}

// Costruzione albero
fetch('?action=list').then(r => r.json()).then(data => {
    const ul = document.getElementById('fileTree');
    if (data.__empty__) { ul.innerHTML = '<li style="color:#666;">Nessun progetto.</li>'; }
    else { ul.innerHTML = ''; buildTree(data, ul); }
});

function buildTree(data, parent, currentPath = '', openFolders = []) {
    for (const name in data) {
        const item = data[name];
        const li = document.createElement('li');
        const fullPath = currentPath ? `${currentPath}/${name}` : name;
        
        // Salva il percorso nell'elemento DOM
        li.dataset.path = fullPath;

        if (item.type === 'dir') {
            // --- RAMO CARTELLA ---
            const folderDiv = document.createElement('div');
            folderDiv.style.display = 'flex';
            folderDiv.style.alignItems = 'center';
            folderDiv.style.position = 'relative';
            
            const toggleSpan = document.createElement('span');
            toggleSpan.textContent = '+ ';
            toggleSpan.style.cursor = 'pointer';
            toggleSpan.style.marginRight = '6px';
            toggleSpan.style.fontWeight = 'bold';
            toggleSpan.style.color = '#586069';
            
            const nameContainer = document.createElement('div');
            nameContainer.className = 'folder-container';
            nameContainer.style.display = 'flex';
            nameContainer.style.alignItems = 'center';
            nameContainer.style.flexGrow = '1';
            
            const span = document.createElement('span');
            span.className = 'folder';
            span.textContent = name;
            
            // Click sulla cartella
            span.onclick = (e) => {
                e.stopPropagation();
                selectFolder(fullPath, span);
                const ul = li.querySelector('ul');
                if(ul) {
                    const isOpen = ul.style.display === 'block';
                    ul.style.display = isOpen ? 'none' : 'block';
                    toggleSpan.textContent = isOpen ? '+ ' : '- ';
                }
            };
            nameContainer.appendChild(span);

            // --- BLOCCO PULSANTI AZIONE (PHP) ---
            <?php if ($loggedIn): ?>
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'file-actions';
            const folderButtons = [
                { label: '🔄', action: 'rename-folder', title: 'Rinomina' },
                { label: '📁', action: 'move-folder', title: 'Sposta' },
                { label: '🗑️', action: 'delete-folder', title: 'Elimina' }
            ];
            folderButtons.forEach(btn => {
                const button = document.createElement('button');
                button.textContent = btn.label;
                button.title = btn.title;
                button.dataset.action = btn.action;
                button.dataset.path = fullPath;
                actionsDiv.appendChild(button);
            });
            nameContainer.appendChild(actionsDiv);
            <?php endif; ?>
            // --- FINE BLOCCO PULSANTI ---

            folderDiv.appendChild(toggleSpan);
            folderDiv.appendChild(nameContainer);
            li.appendChild(folderDiv);
            
            const nestedUl = document.createElement('ul');
            buildTree(item.children, nestedUl, fullPath, openFolders);
            li.appendChild(nestedUl);
            
            // Ripristino stato aperto/chiuso
            if (openFolders.includes(fullPath)) {
                nestedUl.style.display = 'block';
                toggleSpan.textContent = '- ';
            }

        } else {
            // --- RAMO FILE (QUESTO È L'ELSE CHE DAVA ERRORE PRIMA) ---
            const span = document.createElement('span');
            span.className = 'file';
            span.textContent = name;
            
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'file-actions';
            
            <?php if ($loggedIn): ?>
            const buttons = [
                { label: '✏️', action: 'edit', title: 'MODIFICA' },
                { label: '📋', action: 'copy', title: 'COPIA' },
                { label: '🗑️', action: 'delete', title: 'ELIMINA' },
                { label: '🔄', action: 'rename', title: 'RINOMINA' },
                { label: '📁', action: 'move', title: 'SPOSTA' }
            ];
            buttons.forEach(btn => {
                const button = document.createElement('button');
                button.textContent = btn.label;
                button.title = btn.title;
                button.dataset.action = btn.action;
                button.dataset.path = item.path;
                actionsDiv.appendChild(button);
            });
            <?php endif; ?>
            
            span.onclick = () => {
                document.querySelectorAll('.folder.selected, .file.selected').forEach(el => el.classList.remove('selected'));
                loadFile(item.path);
            };
            span.appendChild(actionsDiv);
            li.appendChild(span);
        }
        
        parent.appendChild(li);
    }
}

document.addEventListener('click', function(e) {
    if (e.target.matches('.file-actions button')) {
        const action = e.target.dataset.action; 
        let path = e.target.dataset.path;
        if (!path && (action.includes('folder'))) { alert("Errore: Percorso non rilevato."); return; }
        switch(action) {
            case 'edit': loadFile(path); break;
            case 'copy': copyFile(path); break;
            case 'delete': deleteFile(path); break;
            case 'rename': renameFile(path); break;
            case 'move': moveFile(path); break;
            case 'rename-folder': renameFolder(path); break;
            case 'move-folder': moveFolder(path); break;
            case 'delete-folder': deleteFolderSmart(path); break;
        }
    }
});

// Ricerca
document.getElementById('searchBox')?.addEventListener('input', async function() {
    const query = this.value.trim(); const resultsDiv = document.getElementById('searchResults');
    if (query.length < 2) { resultsDiv.style.display = 'none'; return; }
    resultsDiv.style.display = 'block'; resultsDiv.innerHTML = '<p>🔍 Ricerca...</p>';
    try {
        const data = await (await fetch('?action=list')).json(); const files = [];
        const collect = (obj, base = '') => { for (const name in obj) { const item = obj[name]; const path = base ? `${base}/${name}` : name; if (item.type === 'file') files.push(path); else if (item.type === 'dir') collect(item.children, path); }};
        collect(data); const results = [];
        await Promise.all(files.map(async file => { try { const content = await (await fetch(`?action=file&file=${encodeURIComponent(file)}`)).text(); content.split('\n').forEach((line, i) => { if (line.toLowerCase().includes(query.toLowerCase())) results.push({ file, line: i + 1, content: line.trim() }); }); } catch (e) {} }));
        if (results.length === 0) resultsDiv.innerHTML = '<p>Nessun risultato.</p>';
        else {
            results.sort((a, b) => a.file.localeCompare(b.file)); let html = '<div style="font-size:13px;">';
            results.slice(0, 50).forEach(r => { const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); const highlighted = r.content.replace(new RegExp(`(${escapedQuery})`, 'gi'), '<mark style="background:#fff38d;">$1</mark>'); html += `<div style="margin-bottom:12px; cursor:pointer;" onclick="loadFileAndHighlight('${r.file}', ${r.line}, '${query.replace(/'/g, "\\'")}');"><strong>${r.file}</strong> (Riga ${r.line})<br><code>${highlighted}</code></div>`; });
            html += '</div>'; resultsDiv.innerHTML = html; if (results.length > 0) loadFileAndHighlight(results[0].file, results[0].line, query);
        }
    } catch (error) { resultsDiv.innerHTML = '<p style="color:red;">Errore.</p>'; }
});

function loadFileAndHighlight(filePath, lineNum, query) { loadFile(filePath); setTimeout(() => { const container = document.querySelector('.code-with-linenumbers'); if (container) { const lineHeight = 20; container.scrollTop = (lineNum - 1) * lineHeight - container.clientHeight / 2 + lineHeight / 2; } }, 500); }

// Mobile menu
const menuToggle = document.getElementById('menuToggle'); const closeSidebar = document.getElementById('closeSidebar'); const sidebar = document.querySelector('.sidebar');
if (menuToggle) { menuToggle.addEventListener('click', () => { sidebar.classList.add('active'); menuToggle.style.display = 'none'; }); }
if (closeSidebar) { closeSidebar.addEventListener('click', () => { sidebar.classList.remove('active'); if (window.innerWidth <= 767) menuToggle.style.display = 'block'; }); }
window.addEventListener('resize', () => { if (window.innerWidth <= 767 && !sidebar.classList.contains('active')) { menuToggle.style.display = 'block'; } else if (window.innerWidth > 767) { menuToggle.style.display = 'none'; } });

function getLanguageFromPath(path) { const ext = path.split('.').pop().toLowerCase(); const map = { 'js':'javascript', 'ts':'typescript', 'jsx':'javascript', 'tsx':'typescript', 'php':'php', 'html':'html', 'htm':'html', 'xml':'xml', 'css':'css', 'scss':'scss', 'sass':'sass', 'json':'json', 'yml':'yaml', 'yaml':'yaml', 'sql':'sql', 'py':'python', 'sh':'bash', 'md':'markdown', 'txt':'plaintext' }; return map[ext] || 'plaintext'; }

let mybutton = document.getElementById("myBtn"); window.onscroll = () => { mybutton.style.display = (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) ? "block" : "none"; };
function topFunction() { document.body.scrollTop = 0; document.documentElement.scrollTop = 0; }
</script>
</body>
</html>