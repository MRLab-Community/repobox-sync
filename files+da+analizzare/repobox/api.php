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
    case 'rename':
  $newName = $_POST['new_name'] ?? '';
  if (!$newName) exit('Nuovo nome richiesto');
  $newPath = dirname($fullPath) . '/' . $newName;
  if (rename($fullPath, $newPath)) {
    echo 'Rinominato';
  } else {
    http_response_code(500);
    echo 'Errore nel rinominare';
  }
  break;

case 'move':
  $target = $_POST['target'] ?? '';
  if (!$target) exit('Destinazione richiesta');
  $targetPath = $baseDir . '/' . ltrim($target, '/');
  if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
  $newPath = $targetPath . '/' . basename($fullPath);
  if (rename($fullPath, $newPath)) {
    echo 'Spostato';
  } else {
    http_response_code(500);
    echo 'Errore nello spostare';
  }
  break;     

    default:
        http_response_code(400);
        exit('Azione non supportata');
}