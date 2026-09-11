<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// Shared notification-polling endpoint for dean dashboards (2026-09-02
// fix - a real, pre-existing bug found in Apache's error log: CCS, CBAA,
// CFND, CAS, and CCJE's dashboards have all been calling
// fetch('get_notifications.php') every 30 seconds since this bell-
// dropdown feature was first built, but the endpoint itself was never
// actually created - every single poll, on every one of those 5
// colleges' dashboards, for as long as this project has existed, has
// been silently 404ing in the background. This is the file that was
// missing.
//
// One shared endpoint rather than 5 near-duplicate ones, matching this
// session's established convention (get_demand_detail.php, etc.).
// Department resolved via canonicalTnaCollegeCode() from the dean's own
// role, not a raw string match against users.department - the exact
// department-mismatch bug (short codes vs. full college names) already
// found and fixed once elsewhere in this codebase (see
// forward_training_demand.php) would otherwise silently return zero
// notifications for any college whose real employees have full-name
// departments on file instead of short codes.
if (!isset($_SESSION['user_id']) || strpos($_SESSION['user_role'] ?? '', 'admin_') !== 0) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$deptCode = canonicalTnaCollegeCode(substr($_SESSION['user_role'], strlen('admin_')));
if ($deptCode === null) {
    echo json_encode([]);
    exit();
}

$notifications = [];

// Recent evaluations for this college - same shape/limit every affected
// college's own server-rendered version already used, normalized to
// match by canonical code instead of a literal department string so it
// actually finds real employees regardless of how their department is
// stored.
$evalStmt = $con->prepare("
    SELECT 'evaluation' as type,
           CONCAT('New evaluation submitted for ', u.name) as message,
           e.created_at as timestamp
    FROM evaluations e
    JOIN users u ON e.user_id = u.id
    WHERE u.department = ? OR u.department LIKE ?
    ORDER BY e.created_at DESC
    LIMIT 3
");
// Matches both a stored short code ('CCS') and anything containing it as
// a substring of a longer stored value - deliberately loose since
// users.department has no single consistent format across real accounts.
$likePattern = '%' . $deptCode . '%';
$evalStmt->bind_param("ss", $deptCode, $likePattern);
$evalStmt->execute();
$result = $evalStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$evalStmt->close();

// Pending-evaluations count, same as every affected college's own
// server-rendered version.
$pendingStmt = $con->prepare("
    SELECT COUNT(*) as count
    FROM users u
    WHERE (u.department = ? OR u.department LIKE ?)
      AND u.role = 'user'
      AND u.teaching_status IS NOT NULL
      AND u.teaching_status != ''
      AND NOT EXISTS (SELECT 1 FROM evaluations e WHERE e.user_id = u.id)
");
$pendingStmt->bind_param("ss", $deptCode, $likePattern);
$pendingStmt->execute();
$pendingRow = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();
if ($pendingRow && $pendingRow['count'] > 0) {
    $notifications[] = [
        'type' => 'pending',
        'message' => $pendingRow['count'] . ' faculty member(s) still need to be evaluated',
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

// Training demand HR forwarded to this dean - the one real, dismissible
// event type with a persisted read state. 2026-09-06 fix: this poll
// endpoint never included these at all, so every 30 seconds a dean's
// bell dropdown silently replaced its own server-rendered training_demand
// items with just eval+pending, making them disappear from view until
// the next full page reload (see CLAUDE.md). Unread first so the client
// can compute an accurate badge count from this same response.
$unreadCount = 0;
$demandStmt = $con->prepare("
    SELECT 'training_demand' as type, message, created_at as timestamp, is_read
    FROM notifications WHERE user_id = ? AND related_type = 'training_demand'
    ORDER BY is_read ASC, created_at DESC LIMIT 5
");
$demandStmt->bind_param("i", $_SESSION['user_id']);
$demandStmt->execute();
$demandResult = $demandStmt->get_result();
while ($row = $demandResult->fetch_assoc()) {
    $notifications[] = $row;
    if ((int)$row['is_read'] === 0) { $unreadCount++; }
}
$demandStmt->close();

echo json_encode(['notifications' => $notifications, 'unreadCount' => $unreadCount]);
