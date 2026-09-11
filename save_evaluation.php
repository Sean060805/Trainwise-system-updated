<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    // Prepare evaluation data
    $data = [
        'user_id' => $_POST['user_id'],
        'evaluator_id' => $_POST['evaluator_id'],
        'training_title' => $_POST['training_title'],
        'date_conducted' => $_POST['date_conducted'],
        'objectives' => $_POST['objectives'],
        'comments' => $_POST['comments'] ?? null,
        'future_training_needs' => $_POST['future_training_needs'] ?? null,
        'rated_by' => $_POST['rated_by'],
        'signature_data' => $_POST['signature_data'] ?? null,
        'assessment_date' => $_POST['assessment_date'],
        'status' => $_POST['status'],
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Add all 8 ratings and remarks
    for ($i = 1; $i <= 8; $i++) {
        $data["rating_$i"] = $_POST["rating_$i"] ?? 0;
        $data["remark_$i"] = $_POST["remark_$i"] ?? null;
    }

    // Check if updating existing evaluation
    $evaluationId = $_POST['id'] ?? null;
    
    if ($evaluationId) {
        // Update existing evaluation
        $sql = "UPDATE evaluations SET ";
        $updates = [];
        foreach ($data as $field => $value) {
            $updates[] = "$field = :$field";
        }
        $sql .= implode(', ', $updates) . " WHERE id = :id";
        $data['id'] = $evaluationId;
    } else {
        // Create new evaluation
        $sql = "INSERT INTO evaluations (";
        $sql .= implode(', ', array_keys($data)) . ") VALUES (";
        $sql .= ':' . implode(', :', array_keys($data)) . ")";
    }

    $stmt = $con->prepare($sql);
    $stmt->execute($data);

    if (!$evaluationId) {
        $evaluationId = $con->lastInsertId();
    }

    // Create notification
    $notificationMsg = "New evaluation " . ($_POST['status'] === 'draft' ? 'draft saved' : 'submitted');
    $stmt = $con->prepare("INSERT INTO notifications 
        (user_id, message, related_id, related_type, type) 
        VALUES (?, ?, ?, 'evaluation', 'evaluation')");
    $stmt->execute([
        $data['user_id'],
        $notificationMsg,
        $evaluationId
    ]);

    // Update user's notification flag
    $con->prepare("UPDATE users SET has_notification = 1 WHERE id = ?")
       ->execute([$data['user_id']]);

    echo json_encode([
        'success' => true,
        'evaluation_id' => $evaluationId,
        'message' => 'Evaluation saved successfully'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>