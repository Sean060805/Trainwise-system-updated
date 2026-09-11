<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// The employee-side half of "Post a Training Opportunity" - see
// create_dean_sourced_training.php for the full design rationale. This
// mirrors the Accept action on any AI-recommended catalog title
// (update_training_recommendation.php), just against a demand the dean
// posted directly instead of one the AI suggested.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_POST['demand_id']) || !is_numeric($_POST['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid training']);
    exit();
}
$demandId = intval($_POST['demand_id']);
$userId = $_SESSION['user_id'];

$demandStmt = $con->prepare("SELECT id, title, found_training_title, description, training_type, pipeline_status, sourced_college FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Training not found']);
    exit();
}
if (empty($demand['sourced_college'])) {
    echo json_encode(['success' => false, 'message' => 'This training was not posted as an open opportunity']);
    exit();
}
if ($demand['pipeline_status'] !== 'Training Found') {
    // Signup window closes once HR reviews it (moves it past 'Training
    // Found') - past that point the requester list is what it is, same
    // as every other demand once HR starts shortlisting/confirming.
    echo json_encode(['success' => false, 'message' => 'This opportunity is no longer open for new sign-ups']);
    exit();
}

$userStmt = $con->prepare("SELECT department FROM users WHERE id = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userDept = $userStmt->get_result()->fetch_assoc()['department'] ?? null;
$userStmt->close();
if (canonicalTnaCollegeCode($userDept) !== $demand['sourced_college']) {
    echo json_encode(['success' => false, 'message' => 'This opportunity is not open to your college']);
    exit();
}

$dupStmt = $con->prepare("SELECT id FROM training_recommendations WHERE user_id = ? AND demand_id = ?");
$dupStmt->bind_param("ii", $userId, $demandId);
$dupStmt->execute();
if ($dupStmt->get_result()->num_rows > 0) {
    $dupStmt->close();
    echo json_encode(['success' => false, 'message' => 'You already expressed interest in this training']);
    exit();
}
$dupStmt->close();

$displayTitle = demandDisplayTitle($demand);
$reason = 'You expressed interest in a training your dean posted directly.';
$insertStmt = $con->prepare("
    INSERT INTO training_recommendations (user_id, title, description, reason, training_type, status, demand_id)
    VALUES (?, ?, ?, ?, ?, 'Accepted', ?)
");
$insertStmt->bind_param("issssi", $userId, $displayTitle, $demand['description'], $reason, $demand['training_type'], $demandId);
if (!$insertStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Could not save your interest - please try again.']);
    $insertStmt->close();
    exit();
}
$insertStmt->close();

echo json_encode(['success' => true, 'message' => 'You\'re on the list for "' . $displayTitle . '". HR will follow up once it\'s finalized.']);
