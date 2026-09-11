<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    
    try {
        // Mark all assessment notifications as read for this user
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'assessment'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Error marking notifications as read: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}