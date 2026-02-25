<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function isUserLoggedIn() { return isset($_SESSION['repobox_user_id']); }
if (!isUserLoggedIn()) { http_response_code(403); exit('Non autorizzato'); }

$baseDir = __DIR__ . '/progetti';
$action = $_POST['action'] ?? '';
$file = $_POST['file'] ?? '';
$newName = $_POST['new_name'] ?? '';
$target = $_POST['target'] ?? '';

function isPathAllowed($path, $base) {
    return strpos(realpath($path), realpath($base)) === 0;
}

if (!$file || !isPathAllowed($baseDir . '/' . $file, $baseDir)) {
    http_response_code(400);
    exit('Percorso non valido');
}

$fullPath = $baseDir . '/' . $file;

switch ($action) {
    case 'delete':
        if (is_file($fullPath)) {
            unlink($fullPath);
        } elseif (is_dir($fullPath)) {
            array_map('unlink', glob("$fullPath/*"));
            rmdir($fullPath);
        }
        break;

    case 'rename':
        if (!$newName) exit('Nome nuovo richiesto');
        $newPath = dirname($fullPath) . '/' . basename($newName);
        rename($fullPath, $newPath);
        break;

    case 'move':
        if (!$target) exit('Destinazione richiesta');
        $targetPath = $baseDir . '/' . ltrim($target, '/');
        if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
        $newPath = $targetPath . '/' . basename($fullPath);
        rename($fullPath, $newPath);
        break;
   case 'copy':
        if (!$target) exit('Destinazione richiesta');
        $targetPath = $baseDir . '/' . ltrim($target, '/');
        if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
        $newPath = $targetPath . '/' . basename($fullPath);
        if (copy($fullPath, $newPath)) {
        echo 'Copiato';
        } else {
        http_response_code(500);
        echo 'Errore nella copia';
        }
        break;     

    default:
        http_response_code(400);
        exit('Azione non supportata');
}