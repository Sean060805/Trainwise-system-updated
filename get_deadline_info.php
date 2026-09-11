<?php
require 'config.php';

// Check if deadline ID is provided
if (!isset($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Deadline ID is required']);
    exit();
}

$deadlineId = intval($_GET['id']);

// Get deadline info
$stmt = $con->prepare("SELECT title, submission_deadline FROM settings WHERE id = ?");
$stmt->bind_param("i", $deadlineId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("HTTP/1.1 404 Not Found");
    echo json_encode(['error' => 'Deadline not found']);
    exit();
}

$deadline = $result->fetch_assoc();

// Get total submissions count
$countStmt = $con->prepare("SELECT COUNT(*) AS total FROM assessments WHERE deadline_id = ?");
$countStmt->bind_param("i", $deadlineId);
$countStmt->execute();
$countResult = $countStmt->get_result();
$countData = $countResult->fetch_assoc();

// Format the response
$response = [
    'title' => $deadline['title'],
    'formatted_date' => date('F j, Y g:i A', strtotime($deadline['submission_deadline'])),
    'total_submissions' => $countData['total']
];

header('Content-Type: application/json');
echo json_encode($response);
?>