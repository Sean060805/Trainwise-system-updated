<?php
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
$reportedBy = $_SESSION['user_id'];

// Structured fields (2026-08-28) - replaces the old single free-text box
// so price/date/capacity are always in a consistent, usable shape rather
// than buried in a paragraph HR has to parse by eye.
$foundProvider = trim($_POST['found_provider'] ?? '');
$foundCost = trim($_POST['found_cost'] ?? '');
$foundDates = trim($_POST['found_dates'] ?? '');
$foundCapacity = isset($_POST['found_capacity']) && $_POST['found_capacity'] !== '' ? intval($_POST['found_capacity']) : null;
$foundVenue = trim($_POST['found_venue'] ?? '');
$foundNotes = trim($_POST['found_notes'] ?? '');
// 2026-09-06 - this system also covers free seminars/workshops, not just
// paid ones. When checked, $foundCost is normalized to the literal string
// "Free" (overriding whatever was in the cost box) so every place that
// already displays found_cost as plain text keeps working with no other
// changes, while is_free stays available as a real boolean for filtering.
$isFree = isset($_POST['is_free']) && $_POST['is_free'] === '1';
if ($isFree) {
    $foundCost = 'Free';
}
// The REAL training's actual name, once a dean/HR has genuinely found one
// - optional, since the idea's own working title (training_demand.title)
// is often already good enough. Never required: forcing this would push
// back toward "guessing a name" for the cases where the idea's title
// already IS the real training's name.
$foundTrainingTitle = trim($_POST['found_training_title'] ?? '');
// Actual delivery modality (2026-09-01) - what the training genuinely
// runs as, reported by whoever found it. Distinct from preferred_modality
// on training_recommendations, which only ever records what each
// employee asked for at Accept time - see ml_recommendations.php's
// ensureTrainingDemandActualModalityColumn() for the full reasoning.
$actualModality = trim($_POST['actual_modality'] ?? '');
if (!in_array($actualModality, ['Face-to-Face', 'Online'], true)) {
    echo json_encode(['success' => false, 'message' => 'Please select the actual modality this training will run as.']);
    exit();
}
// 2026-09-06 - training_type moved here from promote_custom_request_cluster.php
// (see that file's comment for the full reasoning): nobody actually knows
// whether "Forensic Science Update" will turn out to be a half-day
// seminar or a 2-day workshop until a real provider/session is found -
// guessing at promotion time was locking in a fictional detail. This is
// the first point in the pipeline where the real format is actually known.
$trainingType = trim($_POST['training_type'] ?? '');
if (!in_array($trainingType, ['Workshop', 'Seminar', 'Webinar', 'Conference'], true)) {
    echo json_encode(['success' => false, 'message' => 'Please select the training type.']);
    exit();
}
// Time-of-day (2026-08-31) - optional, same granularity as the employee's
// own past-training entries in assessment_form_partial.php (start_time/
// end_time). found_dates stays free-text since it's often a range, not a
// single calendar date.
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
// (actual_modality is already validated above, before the time fields)
if ($foundCapacity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Capacity must be at least 1.']);
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
    // The dean's own department must actually have a requester on this
    // demand - a dean shouldn't be able to report on a training their
    // college never asked for. users.department is messy (mixed short
    // codes/full names), so this normalizes every candidate row through
    // canonicalTnaCollegeCode() rather than an exact string match - see
    // forward_training_demand.php for the same fix and reasoning.
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
    // HR self-report path (2026-08-29): only allowed when NONE of this
    // demand's requester departments resolve to a real dean account -
    // e.g. every requester is in the central "ADMIN" (non-teaching)
    // bucket, which has no college and therefore no admin_* dean role to
    // forward to (forward_training_demand.php would look for a role
    // called "admin_admin", which does not exist in the users.role ENUM
    // - found while preparing a full-pipeline manual test). Colleges
    // that DO have a real dean must still go through the normal
    // Forwarded-to-Dean flow, not this shortcut - checked here, not just
    // left to the UI, so this can't be used to bypass dean review.
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
        echo json_encode(['success' => false, 'message' => 'This demand has a dean to review it - forward it instead of reporting directly.']);
        exit();
    }
    if ($demandRow['pipeline_status'] !== 'Pending HR Review') {
        echo json_encode(['success' => false, 'message' => 'This demand is not awaiting a report']);
        exit();
    }
}

// HR self-reporting (no dean involved) is the same person doing both the
// "found it" and "approve it" steps with no new information appearing in
// between - forcing a second manual click just re-confirms what HR already
// just said, so this path goes straight to HR Approved in one save, also
// flipping requesters to Training Available and notifying them exactly
// like approve_training_demand.php does. The dean path is a genuine
// independent-review handoff (dean reports, then HR separately reviews
// what the dean found) and must keep the two-step flow - see the $isDean
// branch above, left untouched.
if ($isHr) {
    $updateStmt = $con->prepare("UPDATE training_demand
        SET pipeline_status = 'HR Approved', training_type = ?,
            found_provider = ?, found_cost = ?, is_free = ?, found_dates = ?, found_start_time = ?, found_end_time = ?, found_capacity = ?, found_venue = ?, actual_modality = ?, found_training_title = NULLIF(?, ''), found_notes = ?,
            found_updated_at = NOW(), found_by_user_id = ?, hr_approved_by_user_id = ?
        WHERE id = ?");
    $updateStmt->bind_param(
        "sssisssissssiii",
        $trainingType, $foundProvider, $foundCost, $isFree, $foundDates, $foundStartTimeParam, $foundEndTimeParam, $foundCapacity, $foundVenue, $actualModality, $foundTrainingTitle, $foundNotes, $reportedBy, $reportedBy, $demandId
    );
} else {
    $updateStmt = $con->prepare("UPDATE training_demand
        SET pipeline_status = 'Training Found', training_type = ?,
            found_provider = ?, found_cost = ?, is_free = ?, found_dates = ?, found_start_time = ?, found_end_time = ?, found_capacity = ?, found_venue = ?, actual_modality = ?, found_training_title = NULLIF(?, ''), found_notes = ?,
            found_updated_at = NOW(), found_by_user_id = ?
        WHERE id = ?");
    $updateStmt->bind_param(
        "sssisssissssii",
        $trainingType, $foundProvider, $foundCost, $isFree, $foundDates, $foundStartTimeParam, $foundEndTimeParam, $foundCapacity, $foundVenue, $actualModality, $foundTrainingTitle, $foundNotes, $reportedBy, $demandId
    );
}

if (!$updateStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to report: ' . $con->error]);
    $updateStmt->close();
    exit();
}
$updateStmt->close();

if (!$isHr) {
    logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'Dean', $role,
        'Reported Training Found', 'training_demand', $demandId,
        'Reported a found training for "' . $demandRow['title'] . '" (' . $foundProvider . ', ' . $foundDates . ').');
    // Dean path stops here - HR still reviews and approves separately.
    echo json_encode(['success' => true]);
    exit();
}

// HR path continues straight into the approve_training_demand.php logic:
// flip every Accepted recommendation on this demand to Training Available
// and notify those employees, same as a normal HR approval would.
$titleStmt = $con->prepare("SELECT title FROM training_demand WHERE id = ?");
$titleStmt->bind_param("i", $demandId);
$titleStmt->execute();
$demandTitle = $titleStmt->get_result()->fetch_assoc()['title'] ?? '';
$titleStmt->close();

$recipientsStmt = $con->prepare("SELECT id, user_id FROM training_recommendations WHERE demand_id = ? AND status = 'Accepted'");
$recipientsStmt->bind_param("i", $demandId);
$recipientsStmt->execute();
$recipients = $recipientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recipientsStmt->close();

$flipStmt = $con->prepare("UPDATE training_recommendations SET status = 'Training Available' WHERE demand_id = ? AND status = 'Accepted'");
$flipStmt->bind_param("i", $demandId);
$flipStmt->execute();
$flipStmt->close();

$message = 'A training has been found and approved for "' . $demandTitle . '". You may be selected to attend.';
foreach ($recipients as $r) {
    notifyUser($con, $r['user_id'], $message, $r['id'], 'training_recommendation', "Training approved: {$demandTitle}");
}

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $role,
    'Reported & Approved (HR self-report)', 'training_demand', $demandId,
    'HR self-reported and approved a found training for "' . $demandTitle . '" (' . $foundProvider . ', ' . $foundDates . ') - notified ' . count($recipients) . ' employee(s).');

echo json_encode(['success' => true]);
