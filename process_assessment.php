<?php
include 'config.php';

header('Content-Type: application/json');

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// If JSON decoding fails, try regular POST
if ($data === null) {
    $data = $_POST;
}

// Validate action
$action = $data['action'] ?? '';
if (empty($action)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

try {
    if ($action === 'remove_assessment') {
        // Validate assessment ID
        $assessment_id = intval($data['assessment_id'] ?? 0);
        if ($assessment_id <= 0) {
            throw new Exception('Invalid assessment ID');
        }

        // Use soft delete (update deleted flag) instead of hard delete
        $stmt = $con->prepare("UPDATE assessments SET deleted = 1 WHERE id = ?");
        $stmt->bind_param("i", $assessment_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Assessment removed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'No assessment found with that ID']);
            }
        } else {
            throw new Exception('Database error: ' . $stmt->error);
        }
    } 
    elseif ($action === 'submit') {
        // Validate required fields
        $required_fields = [
            'name', 'department', 'training_title', 'date_conducted',
            'objectives', 'rated_by', 'assessment_date', 'signature_data'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            throw new Exception('Missing required fields: ' . implode(', ', $missing_fields));
        }
        
        // Process ratings (assuming they're in $data['rating'] array)
        $ratings = $data['rating'] ?? [];
        $remarks = $data['remark'] ?? [];
        
        // Prepare the data for insertion
        $assessment_id = intval($data['assessment_id'] ?? 0);
        $user_id = intval($data['user_id'] ?? 0);
        $name = $con->real_escape_string($data['name']);
        $department = $con->real_escape_string($data['department']);
        $training_title = $con->real_escape_string($data['training_title']);
        $date_conducted = $con->real_escape_string($data['date_conducted']);
        $objectives = $con->real_escape_string($data['objectives']);
        $comments = $con->real_escape_string($data['comments'] ?? '');
        $future_training = $con->real_escape_string($data['future_training'] ?? '');
        $rated_by = $con->real_escape_string($data['rated_by']);
        $assessment_date = $con->real_escape_string($data['assessment_date']);
        $signature_data = $con->real_escape_string($data['signature_data']);
        
        // Convert ratings and remarks to JSON
        $ratings_json = json_encode($ratings);
        $remarks_json = json_encode($remarks);
        
        if ($assessment_id > 0) {
            // Update existing assessment
            $stmt = $con->prepare("UPDATE assessments SET 
                training_title = ?, date_conducted = ?, objectives = ?, 
                ratings = ?, remarks = ?, comments = ?, future_training = ?,
                rated_by = ?, assessment_date = ?, signature_data = ?, 
                submission_date = NOW()
                WHERE id = ?");
            $stmt->bind_param("ssssssssssi", 
                $training_title, $date_conducted, $objectives,
                $ratings_json, $remarks_json, $comments, $future_training,
                $rated_by, $assessment_date, $signature_data,
                $assessment_id);
        } else {
            // Create new assessment
            $stmt = $con->prepare("INSERT INTO assessments (
                user_id, name, department, training_title, date_conducted,
                objectives, ratings, remarks, comments, future_training,
                rated_by, assessment_date, signature_data, submission_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issssssssssss", 
                $user_id, $name, $department, $training_title, $date_conducted,
                $objectives, $ratings_json, $remarks_json, $comments, $future_training,
                $rated_by, $assessment_date, $signature_data);
        }
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'Assessment submitted successfully',
                'assessment_id' => $assessment_id > 0 ? $assessment_id : $con->insert_id
            ]);
        } else {
            throw new Exception('Database error: ' . $stmt->error);
        }
    }
    else {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>