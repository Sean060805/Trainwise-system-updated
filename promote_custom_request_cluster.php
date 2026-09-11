<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// Turns an AI-identified cluster of employee free-text requests into a
// real training_demand - reusing the EXACT same pipeline every catalog-
// based Accept already goes through (Forward to Dean, Report Found,
// Approve, Shortlist, Confirm, Proof of Completion), rather than building
// a second parallel system. See clusterCustomTrainingRequests() in
// ml_recommendations.php for how the cluster itself gets identified.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

$label = trim($_POST['label'] ?? '');
$idsRaw = $_POST['request_ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $idsRaw)));
// 2026-09-06 - reverted the 2026-09-01 fix that asked HR to pick a
// training_type right here, at promotion. Real gap the user caught: at
// this point this is still just an IDEA/topic ("Forensic Science and
// Criminal Investigation Techniques") - nobody, not HR and not the AI,
// actually knows yet whether the real session that eventually gets found
// will be a half-day seminar or a 2-day workshop. Guessing it here was
// locking in a fictional detail before it could possibly be known.
// training_type now gets set for real in report_training_demand.php,
// at the point a dean/HR actually reports a real training they found -
// the first moment that's genuinely knowable. Left NULL here on purpose;
// the demand table renders that as "To be determined", not a blank "—".
$trainingType = null;

if ($label === '') {
    echo json_encode(['success' => false, 'message' => 'A title is required.']);
    exit();
}
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No requests selected.']);
    exit();
}

ensureCustomTrainingRequestsTable($con);
ensureTrainingRecommendationsTable($con); // also ensures training_demand exists

// Only act on rows that are STILL Unclustered (2026-09-01) - defends
// against the case where HR views a clustering result, another HR user
// (or this same one, on another tab) promotes something touching the
// same requests in the meantime, and this request would otherwise
// silently double-count someone. No transaction wraps this - matches
// this codebase's existing convention (see save_dean_note.php) of a
// plain check-then-act rather than DB-level locking, judged proportionate
// for this system's actual concurrent-use scale.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $con->prepare("
    SELECT ctr.id, ctr.user_id, ctr.request_text
    FROM custom_training_requests ctr
    WHERE ctr.id IN ($placeholders) AND ctr.status = 'Unclustered'
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($requests)) {
    echo json_encode(['success' => false, 'message' => 'These requests have already been promoted by someone else - please refresh.']);
    exit();
}

$description = 'Requested directly by employees - not from the standard training catalog.';
$demandId = findOrCreateTrainingDemand($con, $label, $description, $trainingType);
if ($demandId === null) {
    echo json_encode(['success' => false, 'message' => 'Could not create the training demand - please try again.']);
    exit();
}

$recStmt = $con->prepare("
    INSERT INTO training_recommendations (user_id, title, description, reason, status, demand_id)
    VALUES (?, ?, ?, ?, 'Accepted', ?)
");
$updateReqStmt = $con->prepare("
    UPDATE custom_training_requests SET status = 'Clustered', cluster_label = ?, demand_id = ? WHERE id = ?
");
$notifStmt = $con->prepare("
    INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at)
    VALUES (?, ?, ?, 'training_recommendation', 0, NOW())
");

$promotedCount = 0;
foreach ($requests as $r) {
    // Each person's own row keeps their original wording as its reason -
    // useful context for whoever ends up sourcing this - even though the
    // title matches the cluster's shared label like any other pooled demand.
    $reason = 'You specifically requested: "' . $r['request_text'] . '"';
    $recStmt->bind_param("isssi", $r['user_id'], $label, $description, $reason, $demandId);
    $recStmt->execute();
    $recId = $recStmt->insert_id;

    $updateReqStmt->bind_param("sii", $label, $demandId, $r['id']);
    $updateReqStmt->execute();

    $message = 'Your request for "' . $r['request_text'] . '" has been grouped with ' . (count($requests) - 1) . ' other colleague(s) and sent to HR as "' . $label . '".';
    $notifStmt->bind_param("isi", $r['user_id'], $message, $recId);
    $notifStmt->execute();

    $promotedCount++;
}
$recStmt->close();
$updateReqStmt->close();
$notifStmt->close();

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $_SESSION['user_role'] ?? 'admin',
    'Promoted Employee Request Cluster', 'training_demand', $demandId,
    'Promoted a cluster of ' . $promotedCount . ' employee request(s) into "' . $label . '".');

echo json_encode(['success' => true, 'demand_id' => $demandId, 'promoted_count' => $promotedCount]);
