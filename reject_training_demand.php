<?php
/**
 * "No Training Found" - the counterpart to report_training_demand.php.
 * 2026-09-15, per real-testing feedback from Mr. Mike Philip Ramos: a
 * dean handling a forwarded demand only ever had "Report to HR" - there
 * was no way to say "I looked, there's genuinely nothing available for
 * this" without either leaving it stuck at 'Forwarded to Dean' forever,
 * or filling in the Report Found form dishonestly. Same two authorization
 * paths as report_training_demand.php (dean at 'Forwarded to Dean', HR
 * self-report at 'Pending HR Review' when no dean exists for this
 * demand's colleges) - see that file for the full reasoning behind each
 * check, mirrored here.
 */
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';
require_once 'notification_email.php';

header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
$isDean = strpos($role, 'admin_') === 0;
$isHr = $role === 'admin';
if (!isset($_SESSION['user_id']) || (!$isDean && !$isHr)) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_POST['demand_id']) || !is_numeric($_POST['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_POST['demand_id']);
$actorId = $_SESSION['user_id'];
$reason = trim($_POST['reason'] ?? '');
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please explain why no training could be found.']);
    exit();
}

$demandStmt = $con->prepare("SELECT pipeline_status, title FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demandRow = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();
if (!$demandRow) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}

// Same candidate-department resolution as report_training_demand.php -
// see that file for why canonicalTnaCollegeCode() is used instead of a
// raw string match.
$candStmt = $con->prepare("
    SELECT DISTINCT u.department
    FROM training_demand d
    JOIN training_recommendations tr ON tr.demand_id = d.id
    JOIN users u ON u.id = tr.user_id
    WHERE d.id = ? AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
");
$candStmt->bind_param("i", $demandId);
$candStmt->execute();
$candidateDepartments = array_column($candStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'department');
$candStmt->close();
$collegeCodes = array_values(array_unique(array_filter(array_map('canonicalTnaCollegeCode', $candidateDepartments))));

if ($isDean) {
    $deptCode = strtoupper(substr($role, strlen('admin_')));
    if (!in_array($deptCode, $collegeCodes, true)) {
        echo json_encode(['success' => false, 'message' => 'Demand not found for your department']);
        exit();
    }
    if ($demandRow['pipeline_status'] !== 'Forwarded to Dean') {
        echo json_encode(['success' => false, 'message' => 'This demand is not awaiting your response']);
        exit();
    }
} else {
    // Same HR-self-report gate as report_training_demand.php: only when
    // no dean exists to review it (e.g. every requester is in the
    // non-teaching ADMIN bucket).
    $roles = array_map(fn($c) => 'admin_' . strtolower($c), $collegeCodes);
    $deanExists = false;
    if (!empty($roles)) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $deanCheckStmt = $con->prepare("SELECT id FROM users WHERE role IN ($placeholders) LIMIT 1");
        $deanCheckStmt->bind_param(str_repeat('s', count($roles)), ...$roles);
        $deanCheckStmt->execute();
        $deanExists = $deanCheckStmt->get_result()->num_rows > 0;
        $deanCheckStmt->close();
    }
    if ($deanExists) {
        echo json_encode(['success' => false, 'message' => 'This demand has a dean to review it - forward it instead of rejecting it directly.']);
        exit();
    }
    if ($demandRow['pipeline_status'] !== 'Pending HR Review') {
        echo json_encode(['success' => false, 'message' => 'This demand is not awaiting a report']);
        exit();
    }
}

// Captured before the transaction below, specifically so the notification
// loop afterward notifies exactly the rows this action itself is about to
// flip - not just "whatever is Cancelled for this demand_id now," which
// could in principle also match rows cancelled by some earlier, unrelated
// event.
$recipientsStmt = $con->prepare("SELECT id, user_id FROM training_recommendations WHERE demand_id = ? AND status = 'Accepted'");
$recipientsStmt->bind_param("i", $demandId);
$recipientsStmt->execute();
$recipients = $recipientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recipientsStmt->close();

// 2026-09-17 fix - ISO 25010 audit found these two writes (close the
// demand, cancel its accepted recommendations) were not transactional:
// if the first succeeded and the second failed, a demand could end up
// marked 'No Training Found' while the employees who accepted it stayed
// stuck at 'Accepted' forever, never notified. Matches the same
// begin_transaction()/commit()/rollback() pattern already used by the
// IDP signature-upload flow. Notifications/audit log stay outside the
// transaction - those are best-effort side effects, not data that needs
// to roll back together with the state change itself.
$con->begin_transaction();
try {
    $updateStmt = $con->prepare("UPDATE training_demand
        SET pipeline_status = 'No Training Found', cancellation_reason = ?, cancelled_by_user_id = ?, cancelled_at = NOW()
        WHERE id = ?");
    $updateStmt->bind_param("sii", $reason, $actorId, $demandId);
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to reject: ' . $con->error);
    }
    $updateStmt->close();

    $flipStmt = $con->prepare("UPDATE training_recommendations SET status = 'Cancelled' WHERE demand_id = ? AND status = 'Accepted'");
    $flipStmt->bind_param("i", $demandId);
    if (!$flipStmt->execute()) {
        throw new Exception('Failed to update recommendation records: ' . $con->error);
    }
    $flipStmt->close();

    $con->commit();
} catch (Exception $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}

$employeeMessage = 'Your request for "' . $demandRow['title'] . '" has been cancelled - no training provider could be found. Reason: ' . $reason;
foreach ($recipients as $r) {
    notifyUser($con, $r['user_id'], $employeeMessage, $r['id'], 'training_recommendation', "Training unavailable: {$demandRow['title']}");
}

// Dean rejecting is news to HR (they forwarded it expecting an answer);
// HR rejecting its own self-reported demand is not news to itself.
if ($isDean) {
    $hrStmt = $con->query("SELECT id FROM users WHERE role = 'admin'");
    $hrIds = $hrStmt ? array_column($hrStmt->fetch_all(MYSQLI_ASSOC), 'id') : [];
    $actorName = $_SESSION['user_name'] ?? 'The dean';
    $hrMessage = $actorName . ' could not find a training for "' . $demandRow['title'] . '". Reason: ' . $reason;
    foreach ($hrIds as $hrId) {
        notifyUser($con, $hrId, $hrMessage, $demandId, 'training_demand', "No training found: {$demandRow['title']}");
    }
}

logAuditEvent($con, $actorId, $_SESSION['user_name'] ?? ($isDean ? 'Dean' : 'HR'), $role,
    'Reported No Training Found', 'training_demand', $demandId,
    'No training available for "' . $demandRow['title'] . '" - reason: ' . $reason . '. Notified ' . count($recipients) . ' employee(s).');

echo json_encode(['success' => true]);
