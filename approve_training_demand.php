<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';
require_once 'notification_email.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_POST['demand_id']) || !is_numeric($_POST['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_POST['demand_id']);
$approvedBy = $_SESSION['user_id'];

$demandStmt = $con->prepare("SELECT title, pipeline_status FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}

if ($demand['pipeline_status'] !== 'Training Found') {
    echo json_encode(['success' => false, 'message' => 'This demand has no reported training to approve yet']);
    exit();
}

$updateStmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'HR Approved', hr_approved_by_user_id = ? WHERE id = ?");
$updateStmt->bind_param("ii", $approvedBy, $demandId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to approve: ' . $con->error]);
    exit();
}
$updateStmt->close();

// Every employee who accepted this training moves from "sent to HR" to
// "a training is available" - the AI shortlist / HR's final Confirmed
// pick happens later (Phase 2), this just unblocks that step.
$recipientsStmt = $con->prepare("SELECT id, user_id FROM training_recommendations WHERE demand_id = ? AND status = 'Accepted'");
$recipientsStmt->bind_param("i", $demandId);
$recipientsStmt->execute();
$recipients = $recipientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recipientsStmt->close();

$flipStmt = $con->prepare("UPDATE training_recommendations SET status = 'Training Available' WHERE demand_id = ? AND status = 'Accepted'");
$flipStmt->bind_param("i", $demandId);
$flipStmt->execute();
$flipStmt->close();

$message = 'A training has been found and approved for "' . $demand['title'] . '". You may be selected to attend.';
foreach ($recipients as $r) {
    notifyUser($con, $r['user_id'], $message, $r['id'], 'training_recommendation', "Training approved: {$demand['title']}");
}

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $_SESSION['user_role'] ?? 'admin',
    'Approved Training', 'training_demand', $demandId,
    'Approved the found training for "' . $demand['title'] . '" - notified ' . count($recipients) . ' employee(s) it is now available.');

echo json_encode(['success' => true]);
