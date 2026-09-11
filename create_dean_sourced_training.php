<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// "Post a Training Opportunity" (2026-09-06) - the other end of the
// pipeline from everything built so far. Every other path into
// training_demand starts with an EMPLOYEE'S need (assessment -> AI
// suggestion -> Accept, or a free-text custom request) and HR/the dean
// source something real *afterward*. This is the reverse: a dean who
// ALREADY found a real training on their own initiative (no prior
// employee request behind it at all) posts it directly - the exact
// "hey, I found a training, who wants to join?" scenario an ISO tester
// reported happening informally over Messenger, completely outside this
// system, meaning it never showed up in anyone's training history.
//
// Reuses the training_demand/training_recommendations schema end to end,
// deliberately NOT a parallel table: this just enters the SAME pipeline
// at the 'Training Found' stage instead of 'Pending HR Review', since
// the dean already knows the provider/cost/dates/etc. - nothing here
// needs "finding" anymore, only HR review/approval, same as any other
// demand that reaches 'Training Found'. Employees don't get a
// training_recommendations row created FOR them - they see this as an
// open opportunity (see get_training_opportunities() in this file /
// training_recommendations.php) and click "I'm Interested" themselves,
// same self-service Accept action as any AI-recommended catalog title -
// see express_interest_in_opportunity.php.
$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || strpos($role, 'admin_') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}
$deanCollege = strtoupper(substr($role, strlen('admin_')));

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
if ($title === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter the training\'s title.']);
    exit();
}

$foundProvider = trim($_POST['found_provider'] ?? '');
$foundCost = trim($_POST['found_cost'] ?? '');
$isFree = isset($_POST['is_free']) && $_POST['is_free'] === '1';
if ($isFree) {
    $foundCost = 'Free';
}
$foundDates = trim($_POST['found_dates'] ?? '');
$foundCapacity = isset($_POST['found_capacity']) && $_POST['found_capacity'] !== '' ? intval($_POST['found_capacity']) : null;
$foundVenue = trim($_POST['found_venue'] ?? '');
$foundNotes = trim($_POST['found_notes'] ?? '');
$actualModality = trim($_POST['actual_modality'] ?? '');
if (!in_array($actualModality, ['Face-to-Face', 'Online'], true)) {
    echo json_encode(['success' => false, 'message' => 'Please select the modality this training runs as.']);
    exit();
}
$trainingType = trim($_POST['training_type'] ?? '');
if (!in_array($trainingType, ['Workshop', 'Seminar', 'Webinar', 'Conference'], true)) {
    echo json_encode(['success' => false, 'message' => 'Please select the training type.']);
    exit();
}
$foundStartTime = trim($_POST['found_start_time'] ?? '');
$foundEndTime = trim($_POST['found_end_time'] ?? '');
$timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
if ($foundStartTime !== '' && !preg_match($timePattern, $foundStartTime)) {
    echo json_encode(['success' => false, 'message' => 'Invalid start time.']);
    exit();
}
if ($foundEndTime !== '' && !preg_match($timePattern, $foundEndTime)) {
    echo json_encode(['success' => false, 'message' => 'Invalid end time.']);
    exit();
}
if ($foundStartTime !== '' && $foundEndTime !== '' && $foundEndTime <= $foundStartTime) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
    exit();
}
$foundStartTimeParam = $foundStartTime !== '' ? $foundStartTime : null;
$foundEndTimeParam = $foundEndTime !== '' ? $foundEndTime : null;

if ($foundProvider === '' || (!$isFree && $foundCost === '') || $foundDates === '' || $foundCapacity === null) {
    echo json_encode(['success' => false, 'message' => 'Please fill in the training provider, cost (or mark it free), date(s), and capacity.']);
    exit();
}
if ($foundCapacity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Capacity must be at least 1.']);
    exit();
}

ensureTrainingRecommendationsTable($con); // also ensures training_demand + its self-heal columns

$demandId = findOrCreateTrainingDemand($con, $title, $description ?: 'Posted directly by the dean - not from an employee request.', $trainingType);
if ($demandId === null) {
    echo json_encode(['success' => false, 'message' => 'Could not create the training - please try again.']);
    exit();
}

// findOrCreateTrainingDemand() returns an EXISTING row's id if the title
// already matches something in the pipeline - guard against silently
// overwriting whatever state that row is already in (e.g. an idea HR is
// mid-review on, or a training someone already confirmed for).
$existingStmt = $con->prepare("SELECT pipeline_status, created_at FROM training_demand WHERE id = ?");
$existingStmt->bind_param("i", $demandId);
$existingStmt->execute();
$existingRow = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();
// A row findOrCreateTrainingDemand() just created is brand new (created
// within the last few seconds, still at the table's default status) -
// anything older than that came from a pre-existing row we shouldn't touch.
$isFreshlyCreated = $existingRow && $existingRow['pipeline_status'] === 'Pending HR Review'
    && strtotime($existingRow['created_at']) >= (time() - 10);
if (!$isFreshlyCreated) {
    echo json_encode(['success' => false, 'message' => 'A training titled "' . $title . '" already exists in the pipeline (status: ' . ($existingRow['pipeline_status'] ?? 'unknown') . '). Please use a more specific title, or find it in the Training Demand list instead.']);
    exit();
}

$updateStmt = $con->prepare("UPDATE training_demand
    SET pipeline_status = 'Training Found', sourced_college = ?,
        found_provider = ?, found_cost = ?, is_free = ?, found_dates = ?, found_start_time = ?, found_end_time = ?,
        found_capacity = ?, found_venue = ?, actual_modality = ?, found_notes = ?,
        found_updated_at = NOW(), found_by_user_id = ?
    WHERE id = ?");
$updateStmt->bind_param(
    "sssisssisssii",
    $deanCollege, $foundProvider, $foundCost, $isFree, $foundDates, $foundStartTimeParam, $foundEndTimeParam,
    $foundCapacity, $foundVenue, $actualModality, $foundNotes, $_SESSION['user_id'], $demandId
);
if (!$updateStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $con->error]);
    $updateStmt->close();
    exit();
}
$updateStmt->close();

// Broadcast, not a per-employee row - matches the real "hey, who wants to
// join?" open-call the ISO tester described, and lets each interested
// employee self-select via express_interest_in_opportunity.php, same as
// accepting any other recommendation.
$deptFullNames = [
    'CA' => 'College of Agriculture', 'CAS' => 'College of Arts and Sciences',
    'CBAA' => 'College of Business, Administration and Accountancy', 'CCJE' => 'College of Criminal Justice Education',
    'CCS' => 'College of Computer Studies', 'CFND' => 'College of Food, Nutrition and Dietetics',
    'CHMT' => 'College of International Hospitality and Tourism Management', 'CIT' => 'College of Industrial Technology',
    'COE' => 'College of Engineering', 'COF' => 'College of Fisheries', 'CONAH' => 'College of Nursing and Allied Health',
    'COL' => 'College of Law', 'CTE' => 'College of Teacher Education',
];
$notifyStmt = $con->prepare("SELECT id FROM users WHERE role = 'user' AND status = 'accepted' AND department = ?");
$notifyMessage = 'Your dean posted a new training opportunity: "' . $title . '". Check Training Recommendations to express interest.';
$notifiedCount = 0;
foreach (array_unique([$deanCollege, $deptFullNames[$deanCollege] ?? $deanCollege]) as $deptValue) {
    $notifyStmt->bind_param("s", $deptValue);
    $notifyStmt->execute();
    $employees = $notifyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $notifInsert = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_opportunity', 0, NOW())");
    foreach ($employees as $emp) {
        $notifInsert->bind_param("isi", $emp['id'], $notifyMessage, $demandId);
        $notifInsert->execute();
        $notifiedCount++;
    }
    $notifInsert->close();
}
$notifyStmt->close();

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'Dean', $role,
    'Posted Training Opportunity', 'training_demand', $demandId,
    'Posted "' . $title . '" directly (not from an employee request) - notified ' . $notifiedCount . ' ' . $deanCollege . ' employee(s).');

echo json_encode(['success' => true, 'demand_id' => $demandId, 'notified_count' => $notifiedCount]);
