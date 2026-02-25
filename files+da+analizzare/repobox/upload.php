<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function isUserLoggedIn() { return isset($_SESSION['repobox_user_id']); }
if (!isUserLoggedIn()) { header('HTTP/1.1 403 Forbidden'); exit('Accesso negato.'); }

$projectsDir = __DIR__ . '/progetti';
$existingFolders = [];
if (is_dir($projectsDir)) {
    foreach (scandir($projectsDir) as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($projectsDir . '/' . $item)) {
            $existingFolders[] = $item;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_FILES['zip']['name'])) {
        $zipFile = $_FILES['zip']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === TRUE) {
            $projectName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($_FILES['zip']['name'], PATHINFO_FILENAME));
            $extractPath = $projectsDir . '/' . $projectName;
            if (!is_dir($extractPath)) mkdir($extractPath, 0755, true);
            $zip->extractTo($extractPath);
            $zip->close();
            echo "<p>✅ Plugin ZIP caricato in: <strong>" . htmlspecialchars($projectName) . "</strong></p>";
        } else {
            echo "<p>❌ Errore nello ZIP.</p>";
        }
    } elseif (!empty($_FILES['files']['name'][0])) {
        $targetFolder = $_POST['existing_folder'] ?? null;
        $uploadDir = $targetFolder && in_array($targetFolder, $existingFolders) 
            ? $projectsDir . '/' . $targetFolder 
            : $projectsDir;
        foreach ($_FILES['files']['name'] as $i => $name) {
            if ($_FILES['files']['error'][$i] == 0) {
                move_uploaded_file($_FILES['files']['tmp_name'][$i], $uploadDir . '/' . basename($name));
            }
        }
        echo "<p>✅ File caricati in: <strong>" . ($targetFolder ?: 'radice') . "</strong></p>";
    }
    echo '<br><a href="repo.php">← Torna al repository</a>';
    exit;
}
?>

<!DOCTYPE html>
<html>
<head><title>Carica Plugin</title></head>
<body style="font-family:sans-serif; padding:20px; max-width:600px; margin:auto;">
  <h2>📤 Carica Plugin</h2>
  
  <fieldset>
    <legend><strong>Opzione 1: Carica ZIP</strong></legend>
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="zip" accept=".zip" required>
      <br><br>
      <button type="submit">Carica ZIP</button>
    </form>
  </fieldset>

  <fieldset style="margin-top:30px;">
    <legend><strong>Opzione 2: Carica file singoli</strong></legend>
    <form method="post" enctype="multipart/form-data">
      <label>Seleziona cartella esistente (opzionale):</label><br>
      <select name="existing_folder">
        <option value="">📁 Radice (/progetti/)</option>
        <?php foreach ($existingFolders as $folder): ?>
          <option value="<?= htmlspecialchars($folder) ?>"><?= htmlspecialchars($folder) ?></option>
        <?php endforeach; ?>
      </select>
      <br><br>
      <input type="file" name="files[]" multiple required>
      <br><br>
      <button type="submit">Carica File</button>
    </form>
  </fieldset>

  <br><a href="repo.php">← Torna al repository</a>
</body>
</html>