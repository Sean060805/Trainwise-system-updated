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

ensureTrainingRecommendationsTable($con); // also ensures 'Not Selected' status exists
ensureTrainingDemandTable($con); // also ensures the 'Confirmed' pipeline_status exists

$ids = $_POST['recommendation_ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No participants selected']);
    exit();
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid participants selected']);
    exit();
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

// Only rows actually sitting at Training Available can be confirmed -
// mirrors the forward-only transition guard in update_training_recommendation.php.
$selectStmt = $con->prepare("SELECT id, user_id, title, demand_id FROM training_recommendations WHERE status = 'Training Available' AND id IN ($placeholders)");
$selectStmt->bind_param($types, ...$ids);
$selectStmt->execute();
$rows = $selectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$selectStmt->close();

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'None of the selected participants are eligible to confirm']);
    exit();
}

$validIds = array_column($rows, 'id');
$updatePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
$updateTypes = str_repeat('i', count($validIds));
$updateStmt = $con->prepare("UPDATE training_recommendations SET status = 'Confirmed' WHERE id IN ($updatePlaceholders)");
$updateStmt->bind_param($updateTypes, ...$validIds);
$updateStmt->execute();
$updateStmt->close();

// The demand itself should now read "Confirmed", not still "HR Approved" -
// HR asked for this directly after noticing the demand-level badge never
// changed once they'd actually picked final attendees (2026-08-31).
foreach (array_unique(array_filter(array_column($rows, 'demand_id'))) as $demandId) {
    markTrainingDemandConfirmed($con, $demandId);
}

foreach ($rows as $row) {
    $message = 'HR confirmed you for "' . $row['title'] . '". Upload the proof of completion to mark it complete.';
    notifyUser($con, $row['user_id'], $message, $row['id'], 'training_recommendation', "You're confirmed: {$row['title']}");
}

// Everyone else still sitting at 'Training Available' for the SAME
// demand(s) just confirmed against didn't make the cut this round - give
// them real closure instead of leaving them stuck on "awaiting HR's
// final list" indefinitely (a real gap found during manual testing,
// 2026-08-28: with e.g. 15 requesters but only 10 slots, the other 5
// previously just sat there forever with no status change and no
// notification).
$demandIds = array_values(array_unique(array_filter(array_column($rows, 'demand_id'))));
$notSelectedCount = 0;
if (!empty($demandIds)) {
    $demandPlaceholders = implode(',', array_fill(0, count($demandIds), '?'));
    $excludePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    $sql = "SELECT id, user_id, title FROM training_recommendations
            WHERE status = 'Training Available' AND demand_id IN ($demandPlaceholders) AND id NOT IN ($excludePlaceholders)";
    $remainderStmt = $con->prepare($sql);
    $remainderTypes = str_repeat('i', count($demandIds)) . str_repeat('i', count($validIds));
    $remainderStmt->bind_param($remainderTypes, ...array_merge($demandIds, $validIds));
    $remainderStmt->execute();
    $remainderRows = $remainderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $remainderStmt->close();

    if (!empty($remainderRows)) {
        $remainderIds = array_column($remainderRows, 'id');
        $remainderUpdatePlaceholders = implode(',', array_fill(0, count($remainderIds), '?'));
        $remainderUpdateTypes = str_repeat('i', count($remainderIds));
        $remainderUpdateStmt = $con->prepare("UPDATE training_recommendations SET status = 'Not Selected' WHERE id IN ($remainderUpdatePlaceholders)");
        $remainderUpdateStmt->bind_param($remainderUpdateTypes, ...$remainderIds);
        $remainderUpdateStmt->execute();
        $remainderUpdateStmt->close();

        foreach ($remainderRows as $row) {
            $message = 'HR has finalized attendees for "' . $row['title'] . '" and you weren\'t selected this round. You may be prioritized if this training is offered again.';
            notifyUser($con, $row['user_id'], $message, $row['id'], 'training_recommendation', "Update on your request: {$row['title']}");
        }
        $notSelectedCount = count($remainderRows);
    }
}

$logDemandId = count($demandIds) === 1 ? $demandIds[0] : null;
$titles = implode(', ', array_unique(array_column($rows, 'title')));
logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $_SESSION['user_role'] ?? 'admin',
    'Confirmed Participants', 'training_demand', $logDemandId,
    'Confirmed ' . count($rows) . ' participant(s) for "' . $titles . '"' . ($notSelectedCount > 0 ? '; ' . $notSelectedCount . ' not selected this round' : '') . '.');

echo json_encode(['success' => true, 'confirmed_count' => count($rows), 'not_selected_count' => $notSelectedCount]);
