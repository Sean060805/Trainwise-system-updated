<?php
session_start();

// Enhanced security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Database connection with error handling
try {
    $mysqli = new mysqli("localhost", "root", "", "user_db");
    
    if ($mysqli->connect_error) {
        throw new Exception('Database connection error');
    }
    
    // Validate session and user_id
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Authentication required');
    }

    $user_id = (int)$_SESSION['user_id'];
    if ($user_id <= 0) {
        throw new Exception('Invalid user session');
    }

    // Get the active deadline (only those that are active/not expired)
    $deadlineRes = $mysqli->query("
        SELECT deadline 
        FROM submission_deadline 
        WHERE is_active = TRUE 
        ORDER BY deadline DESC 
        LIMIT 1
    ");
    
    if (!$deadlineRes) {
        throw new Exception('Failed to fetch deadline');
    }
    
    $deadlineData = $deadlineRes->fetch_assoc();
    $latestDeadline = $deadlineData['deadline'] ?? null;
    $hasSubmitted = false;

    if ($latestDeadline) {
        // Check if user has a submission BEFORE the deadline
        $stmt = $mysqli->prepare("
            SELECT 1 
            FROM assessments 
            WHERE user_id = ? 
            AND created_at <= ?
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare submission check');
        }
        
        $stmt->bind_param("is", $user_id, $latestDeadline);
        $stmt->execute();
        $hasSubmitted = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // Return response
    echo json_encode([
        'success' => true,
        'hasSubmitted' => $hasSubmitted,
        'latestDeadline' => $latestDeadline,
        'currentTime' => date('Y-m-d H:i:s') // For debugging
    ]);

} catch (Exception $e) {
    // Log error for admin (in production, use proper logging)
    error_log('Submission Check Error: ' . $e->getMessage());
    
    // Return safe error message
    echo json_encode([
        'success' => false,
        'error' => 'Unable to check submission status'
    ]);
} finally {
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>