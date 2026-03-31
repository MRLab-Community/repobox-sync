<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php'; // Assicura che il path sia corretto

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Non loggato']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = $_SESSION['user_id'];

if (isset($data['thumbs_up_increment']) && isset($data['crisis_increment'])) {
    $stmt = $conn->prepare("UPDATE users SET thumbs_up = thumbs_up + ?, crisis_count = crisis_count + ? WHERE id = ?");
    $stmt->bind_param("iii", $data['thumbs_up_increment'], $data['crisis_increment'], $userId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'DB Error']);
    }
    $stmt->close();
}
?>