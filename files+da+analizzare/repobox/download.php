<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function isUserLoggedIn() { return isset($_SESSION['repobox_user_id']); }
if (!isUserLoggedIn()) { http_response_code(403); exit('Accesso negato.'); }

$project = $_GET['project'] ?? '';
if (!$project || !preg_match('/^[a-zA-Z0-9._-]+$/', $project)) {
    die('Progetto non valido.');
}

$projectPath = __DIR__ . '/progetti/' . $project;
if (!is_dir($projectPath)) {
    die('Progetto non trovato.');
}

$zipFile = tempnam(sys_get_temp_dir(), 'repobox_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isDir()) {
            $relativePath = substr($file->getPathname(), strlen($projectPath) + 1);
            $zip->addFile($file->getPathname(), $relativePath);
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $project . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    readfile($zipFile);
    unlink($zipFile);
    exit;
} else {
    die('Errore nella creazione dello ZIP.');
}