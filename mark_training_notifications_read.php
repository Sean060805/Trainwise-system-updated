<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

require_once 'config.php';

$userId = $_SESSION['user_id'];
$stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND related_type = 'training_recommendation'");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>
