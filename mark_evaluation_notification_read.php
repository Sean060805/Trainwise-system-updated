<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$evaluation_id = $input['evaluation_id'] ?? null;
$user_id = $_SESSION['user_id'];

if ($evaluation_id) {
    try {
        // Mark evaluation notification as read
        $update_sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND related_id = ? AND related_type = 'evaluation'";
        $stmt = $con->prepare($update_sql);
        $stmt->bind_param("ii", $user_id, $evaluation_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Error in mark_evaluation_notification_read.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No evaluation ID provided']);
}

if (isset($con) && $con) {
    $con->close();
}
?>