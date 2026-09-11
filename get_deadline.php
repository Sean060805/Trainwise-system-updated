<?php
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Get the active deadline with all details
    $stmt = $con->prepare("SELECT id, title, submission_deadline, description, created_at 
                          FROM settings 
                          WHERE is_active = 1 
                          ORDER BY submission_deadline DESC 
                          LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $con->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Database query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Validate the deadline
        if (empty($row['submission_deadline'])) {
            throw new Exception("No deadline set in database");
        }
        
        $timestamp = strtotime($row['submission_deadline']);
        if ($timestamp === false) {
            throw new Exception("Invalid deadline format in database");
        }
        
        // Prepare response data
        $response = [
            'success' => true,
            'deadline' => [
                'id' => $row['id'],
                'title' => $row['title'],
                'datetime' => $row['submission_deadline'],
                'formatted' => date("F j, Y, g:i a", $timestamp),
                'description' => $row['description'],
                'created_at' => $row['created_at'],
                'is_active' => true,
                'is_valid' => (strtotime($row['submission_deadline']) > time())
            ]
        ];
    } else {
        $response = [
            'success' => true,
            'deadline' => null,
            'message' => 'No active deadline set'
        ];
    }
    
    $stmt->close();
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log the error securely
    error_log("Deadline retrieval error: " . $e->getMessage());
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'error' => 'Error retrieving deadline',
        'debug' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
    ]);
}

// Close connection
if (isset($con) && $con) {
    $con->close();
}