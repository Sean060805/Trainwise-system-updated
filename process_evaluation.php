<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $assessment_id = $_POST['assessment_id'] ?? 0;

    try {
        switch ($action) {
            case 'submit_evaluation':
                // Process dean's evaluation submission
                $evaluation_data = json_encode([
                    'ratings' => $_POST['ratings'],
                    'comments' => $_POST['comments'],
                    'signature' => $_POST['signature_data']
                ]);
                
                $stmt = $con->prepare("UPDATE assessments SET dean_evaluation = ? WHERE id = ?");
                $stmt->bind_param("si", $evaluation_data, $assessment_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Evaluation submitted for HR validation']);
                break;

            case 'approve_evaluation':
                // HR approves evaluation
                $stmt = $con->prepare("UPDATE assessments SET hr_validated = 1, visible_to_user = 1 WHERE id = ?");
                $stmt->bind_param("i", $assessment_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Evaluation approved and published']);
                break;

            case 'reject_evaluation':
                // HR rejects evaluation
                $stmt = $con->prepare("UPDATE assessments SET dean_evaluation = NULL WHERE id = ?");
                $stmt->bind_param("i", $assessment_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Evaluation returned for revision']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log("Evaluation processing error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Processing error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>