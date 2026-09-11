<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

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
// Optional - HR often doesn't have a firm number yet (real pricing for a
// group booking usually isn't final until the dean actually talks to a
// provider), so this is guidance, not a requirement (2026-08-28 design
// discussion). Surfaced in the dean's notification message rather than a
// dedicated UI section across all 13 college dashboards.
$budgetHint = trim($_POST['budget_hint'] ?? '');

$demandStmt = $con->prepare("SELECT title, pipeline_status FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}

if ($demand['pipeline_status'] !== 'Pending HR Review') {
    echo json_encode(['success' => false, 'message' => 'This demand has already been forwarded']);
    exit();
}

// Every dean whose college has at least one requester for this training.
// users.department is inconsistently populated (some rows have short
// codes like "CCJE", most have full college names like "College of
// Criminal Justice Education") - a raw CONCAT('admin_', LOWER(department))
// match only works for the handful of colleges whose employees happen to
// have short-code departments. Normalize through canonicalTnaCollegeCode()
// (same mapping already used for the TNA-2025 boost) so this resolves
// correctly for every college, not just the ones tested so far.
$reqStmt = $con->prepare("
    SELECT DISTINCT u.department
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.demand_id = ? AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
");
$reqStmt->bind_param("i", $demandId);
$reqStmt->execute();
$rawDepartments = array_column($reqStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'department');
$reqStmt->close();

$collegeCodes = array_values(array_unique(array_filter(array_map('canonicalTnaCollegeCode', $rawDepartments))));

$deanIds = [];
if (!empty($collegeCodes)) {
    $roles = array_map(fn($c) => 'admin_' . strtolower($c), $collegeCodes);
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $deansStmt = $con->prepare("SELECT id FROM users WHERE role IN ($placeholders)");
    $deansStmt->bind_param(str_repeat('s', count($roles)), ...$roles);
    $deansStmt->execute();
    $deanIds = array_column($deansStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    $deansStmt->close();
}

// 2026-09-06 - hard server-side gate, not just a hidden button. The UI
// (training_pipeline.php's has_dean check) already only shows "Forward to
// Dean" when at least one requester's college actually has a dean account
// - e.g. a demand made entirely of non-teaching/ADMIN-office requesters has
// no admin_admin role and should be reported directly by HR instead. But
// this endpoint had no equivalent check of its own: a direct POST (stale
// page, replayed request, or simply a bug in a future UI change) would
// silently flip pipeline_status to 'Forwarded to Dean' with zero deans
// notified - a permanent dead end, since nothing would ever move it out of
// that status again. Refuse it instead, same "don't trust the client for a
// state transition's precondition" principle as update_training_recommendation.php's
// $allowedFrom map.
if (empty($deanIds)) {
    echo json_encode(['success' => false, 'message' => 'No requester in this training belongs to a college with a dean account - use "Report Directly" instead so HR sources this one.']);
    exit();
}

$updateStmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'Forwarded to Dean', budget_hint = ? WHERE id = ?");
$budgetHintOrNull = $budgetHint !== '' ? $budgetHint : null;
$updateStmt->bind_param("si", $budgetHintOrNull, $demandId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to forward: ' . $con->error]);
    exit();
}
$updateStmt->close();

$message = 'HR has forwarded a training request for "' . $demand['title'] . '". One or more of your faculty or staff requested this. Please check if a paid training provider is available.';
if ($budgetHint !== '') {
    $message .= ' HR\'s budget guidance: ' . $budgetHint . '.';
}
$notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_demand', 0, NOW())");
foreach ($deanIds as $deanId) {
    $notifStmt->bind_param("isi", $deanId, $message, $demandId);
    $notifStmt->execute();
}
$notifStmt->close();

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $_SESSION['user_role'] ?? 'admin',
    'Forwarded to Dean', 'training_demand', $demandId,
    'Forwarded "' . $demand['title'] . '" to ' . count($deanIds) . ' dean(s)' . ($budgetHint !== '' ? ' with budget guidance: ' . $budgetHint : '') . '.');

echo json_encode(['success' => true]);
