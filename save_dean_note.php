<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once 'config.php';

if (!isset($con) || !$con) {
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit();
}

require_once 'ml_recommendations.php';
ensureTrainingRecommendationsTable($con);

// One comment + one link per individual recommended training, not per
// whole assessment - a reviewer (dean or HR Admin) may approve some
// recommendations and not others, and Start/Decline for the employee is
// gated on dean_link being set for that SPECIFIC recommendation (see
// training_recommendations.php).
if (!isset($_POST['recommendation_id']) || !is_numeric($_POST['recommendation_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid recommendation ID']);
    exit();
}

$recommendation_id = intval($_POST['recommendation_id']);
$dean_comment = trim($_POST['dean_comment'] ?? '');
$dean_link = trim($_POST['dean_link'] ?? '');

// Look up the prior state first, so we can tell whether this save is the
// specific moment Start/Decline goes from disabled to clickable (per
// adviser feedback: notify the employee exactly then, not on every edit).
$beforeStmt = $con->prepare("SELECT user_id, title, dean_link FROM training_recommendations WHERE id = ?");
if (!$beforeStmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $con->error]);
    exit();
}
$beforeStmt->bind_param("i", $recommendation_id);
$beforeStmt->execute();
$before = $beforeStmt->get_result()->fetch_assoc();
$beforeStmt->close();

if (!$before) {
    echo json_encode(['success' => false, 'message' => 'Recommendation not found']);
    exit();
}

$wasUnlockedBefore = !empty($before['dean_link']);

$stmt = $con->prepare("UPDATE training_recommendations SET dean_comment = ?, dean_link = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $con->error]);
    exit();
}
$stmt->bind_param("ssi", $dean_comment, $dean_link, $recommendation_id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $stmt->error]);
    $stmt->close();
    exit();
}
$stmt->close();

// Notify the employee only on the transition from "no link yet" to
// "link set" - editing an already-approved recommendation's comment
// later shouldn't send a second notification.
$justUnlocked = !$wasUnlockedBefore && $dean_link !== '';
if ($justUnlocked) {
    $message = 'Your dean approved a link for "' . $before['title'] . '" - you can now Start or Decline it.';
    $notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_recommendation', 0, NOW())");
    if ($notifStmt) {
        $notifStmt->bind_param("isi", $before['user_id'], $message, $recommendation_id);
        $notifStmt->execute();
        $notifStmt->close();
    }
}

echo json_encode(['success' => true]);
?>
