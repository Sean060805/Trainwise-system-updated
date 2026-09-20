<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// Dean-facing "who requested this" view (2026-09-02, per the adviser) -
// the "Training Demand Forwarded to You" card only ever had a "Report to
// HR" action; a dean sourcing a paid provider had no way to see WHO
// they're actually sourcing for, just an aggregate count. Deliberately a
// separate, leaner endpoint from HR's get_demand_detail.php rather than
// reusing it with role branches - a dean has no legitimate reason to see
// proof-of-completion file paths or HR's budget_hint, and this codebase's
// convention is small inlined per-role queries over one shared endpoint
// with visibility flags bolted on.
if (!isset($_SESSION['user_id']) || strpos($_SESSION['user_role'] ?? '', 'admin_') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_GET['id']);
$deanCollegeCode = canonicalTnaCollegeCode(substr($_SESSION['user_role'], strlen('admin_')));

$demandStmt = $con->prepare("SELECT title FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}

$requestersStmt = $con->prepare("
    SELECT u.name, u.department, tr.status
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.demand_id = ? AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed', 'Not Selected', 'Cancelled')
    ORDER BY u.name ASC
");
$requestersStmt->bind_param("i", $demandId);
$requestersStmt->execute();
$result = $requestersStmt->get_result();

$requesters = [];
$sawOwnCollege = false;
while ($row = $result->fetch_assoc()) {
    $code = canonicalTnaCollegeCode($row['department']);
    if ($code !== null && $code === $deanCollegeCode) {
        $sawOwnCollege = true;
    }
    $requesters[] = [
        'name' => htmlspecialchars($row['name']),
        'department' => htmlspecialchars($row['department'] ?? 'N/A'),
        'status' => htmlspecialchars($row['status']),
    ];
}
$requestersStmt->close();

// Same access boundary as the card that links here - a dean can only see
// requester lists for demand their own college actually has a stake in,
// not any arbitrary demand ID typed into the URL.
if (!$sawOwnCollege) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

echo json_encode(['success' => true, 'title' => $demand['title'], 'requesters' => $requesters]);
