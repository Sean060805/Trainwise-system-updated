<?php
require_once 'config.php';
session_start();

// Verify admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        $newDeadline = $_POST['deadline'] ?? '';
        
        if (empty($newDeadline)) {
            throw new Exception('Deadline value is required');
        }

        // Validate date format (assuming YYYY-MM-DD HH:MM:SS format)
        $deadlineDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $newDeadline);
        if (!$deadlineDateTime) {
            throw new Exception('Invalid deadline format. Please use YYYY-MM-DD HH:MM:SS format');
        }

        // Check if date is in the future
        $currentDateTime = new DateTime();
        if ($deadlineDateTime <= $currentDateTime) {
            throw new Exception('Deadline must be in the future');
        }

        // Begin transaction
        $con->begin_transaction();

        // Save new deadline to settings table
        $stmt = $con->prepare("UPDATE settings SET submission_deadline = ? WHERE id = 1");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $con->error);
        }
        
        $stmt->bind_param("s", $newDeadline);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();

        // Set has_notification = 1 for all users (notify everyone)
        $updateResult = $con->query("UPDATE users SET has_notification = 1");
        if (!$updateResult) {
            throw new Exception("Notification update failed: " . $con->error);
        }

        // Create notification records for all users
        $message = "New assessment period announced. Deadline: " . date('F j, Y g:i a', strtotime($newDeadline));
        $users = $con->query("SELECT id FROM users");
        
        if ($users && $users->num_rows > 0) {
            $notificationStmt = $con->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
            if (!$notificationStmt) {
                throw new Exception("Notification prepare failed: " . $con->error);
            }
            
            while ($user = $users->fetch_assoc()) {
                $notificationStmt->bind_param("is", $user['id'], $message);
                if (!$notificationStmt->execute()) {
                    throw new Exception("Notification insert failed: " . $notificationStmt->error);
                }
            }
            $notificationStmt->close();
        }

        // Commit transaction
        $con->commit();

        // Log the action
        error_log("Admin {$_SESSION['user_id']} updated assessment deadline to $newDeadline");

        echo json_encode([
            'status' => 'success', 
            'message' => 'Deadline updated successfully',
            'formattedDeadline' => date('F j, Y g:i a', strtotime($newDeadline))
        ]);

    } catch (Exception $e) {
        // Rollback on error
        if (isset($con) && $con) {
            $con->rollback();
        }
        
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => $e->getMessage(),
            'debug' => $con->error ?? null
        ]);
        error_log("Deadline update error: " . $e->getMessage());
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}

// Close connection
if (isset($con) && $con) {
    $con->close();
}