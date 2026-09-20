<?php
/**
 * Closes a training_demand that HR has determined is a dead end - every
 * remaining requester has already been hard-excluded from the AI
 * Shortlist (already completed a near-duplicate training), so there is
 * no one left HR could ever Confirm. Wires up the 'Closed' pipeline_status
 * value that already existed in the ENUM but had no action setting it.
 *
 * Deliberately NOT automatic - HR clicks this explicitly (per this app's
 * "HR always makes the final call, never the AI" principle). Re-verifies
 * server-side that eligibility is genuinely zero rather than trusting the
 * client's last-loaded shortlist, in case something changed between page
 * load and the click (e.g. a new Accept came in).
 */
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

$demandStmt = $con->prepare("SELECT title, description, pipeline_status FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}
if ($demand['pipeline_status'] !== 'HR Approved') {
    echo json_encode(['success' => false, 'message' => 'This demand is not at the shortlist stage']);
    exit();
}

$pendingStmt = $con->prepare("SELECT tr.id, tr.user_id, tr.title, tr.description FROM training_recommendations tr WHERE tr.demand_id = ? AND tr.status = 'Training Available'");
$pendingStmt->bind_param("i", $demandId);
$pendingStmt->execute();
$pending = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingStmt->close();

if (empty($pending)) {
    echo json_encode(['success' => false, 'message' => 'No one is currently pending on this demand - nothing to close']);
    exit();
}

// Re-derive eligibility server-side (same query text + same batched call
// get_demand_shortlist.php uses) rather than trusting the client's last
// snapshot - confirm every pending requester is genuinely hard-excluded.
$queryText = trim($demand['title'] . ' ' . ($demand['description'] ?? ''));
$userIds = array_unique(array_column($pending, 'user_id'));
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$types = str_repeat('i', count($userIds));
$priorStmt = $con->prepare("SELECT id, user_id, title, description FROM training_recommendations WHERE user_id IN ($placeholders) AND status IN ('Confirmed', 'Completed')");
$priorStmt->bind_param($types, ...$userIds);
$priorStmt->execute();
$priorRows = $priorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$priorStmt->close();

$priorTrainingTextsById = [];
$priorTrainingToUserId = [];
foreach ($priorRows as $p) {
    $priorTrainingTextsById[$p['id']] = trim($p['title'] . ' ' . ($p['description'] ?? ''));
    $priorTrainingToUserId[$p['id']] = (int)$p['user_id'];
}
$exclusions = getTopicalExclusions($queryText, $priorTrainingTextsById);
$hardExcludedUserIds = array_unique(array_map(
    fn($priorRecId) => $priorTrainingToUserId[$priorRecId],
    array_keys($exclusions['hard'])
));

$stillEligible = array_diff($userIds, $hardExcludedUserIds);
if (!empty($stillEligible)) {
    echo json_encode(['success' => false, 'message' => 'Not everyone is excluded yet - ' . count($stillEligible) . ' requester(s) are still eligible. Refresh the shortlist.']);
    exit();
}

$closeStmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'Closed' WHERE id = ?");
$closeStmt->bind_param("i", $demandId);
$closeStmt->execute();
$closeStmt->close();

$flipStmt = $con->prepare("UPDATE training_recommendations SET status = 'Not Selected' WHERE demand_id = ? AND status = 'Training Available'");
$flipStmt->bind_param("i", $demandId);
$flipStmt->execute();
$flipStmt->close();

$message = 'After review, HR found that everyone who requested "' . $demand['title'] . '" already has equivalent training on file. This request will not be pursued further this round.';
foreach ($pending as $p) {
    notifyUser($con, $p['user_id'], $message, $p['id'], 'training_recommendation', "Update on your request: {$demand['title']}");
}

echo json_encode(['success' => true, 'closed_count' => count($pending)]);
