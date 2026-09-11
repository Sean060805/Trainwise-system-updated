<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// HR-only (2026-08-31) - "bumping" a Confirmed participant back out. Built
// specifically because there was previously no way back once someone was
// Confirmed: a real capacity correction (15 confirmed, HR needs to bring
// it down to 10) had no path forward without this. Mirrors
// confirm_training_participant.php's shape.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

ensureTrainingRecommendationsTable($con);
ensureTrainingDemandTable($con);

if (!isset($_POST['recommendation_id']) || !is_numeric($_POST['recommendation_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant']);
    exit();
}
$recId = intval($_POST['recommendation_id']);

// Only reversible from Confirmed - Completed already has proof attached
// and a training_history_log entry; unwinding that is a different,
// riskier operation this action deliberately doesn't attempt.
$stmt = $con->prepare("SELECT id, user_id, title, demand_id FROM training_recommendations WHERE id = ? AND status = 'Confirmed'");
$stmt->bind_param("i", $recId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'This participant is not currently confirmed - they may have already been changed.']);
    exit();
}

$updateStmt = $con->prepare("UPDATE training_recommendations SET status = 'Not Selected' WHERE id = ?");
$updateStmt->bind_param("i", $recId);
$updateStmt->execute();
$updateStmt->close();

$message = 'HR adjusted the confirmed list for "' . $row['title'] . '" - you are no longer confirmed to attend. You may be reconsidered if a spot opens up.';
$notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_recommendation', 0, NOW())");
$notifStmt->bind_param("isi", $row['user_id'], $message, $recId);
$notifStmt->execute();
$notifStmt->close();

// If that was the last Confirmed (and nobody's Completed yet either), the
// demand itself shouldn't still read "Confirmed".
maybeRevertTrainingDemandToApproved($con, $row['demand_id']);

// A bump frees up a seat the same way raising capacity does (2026-08-31,
// per the user's own question: should a bumped person become
// reconsiderable again? Yes - see reopenNotSelectedIfRoomAvailable()'s
// comment). This includes the just-bumped person themselves - they're
// mechanically just another Not Selected row now, and reopening only
// makes them eligible again, not automatically re-confirmed.
$reopenedCount = reopenNotSelectedIfRoomAvailable($con, $row['demand_id']);

echo json_encode(['success' => true, 'reopened_count' => $reopenedCount]);
