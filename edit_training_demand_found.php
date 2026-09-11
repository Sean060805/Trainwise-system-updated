<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// HR-only (2026-08-31) - editing a training's found details after the
// fact was explicitly asked to be an HR power, not a dean one: the dean's
// involvement is the one-time "here's what I found" report, reviewed once
// by HR (see report_training_demand.php); any later date/time/venue
// adjustment goes through HR so there's one place responsible for keeping
// requesters correctly informed.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

ensureTrainingDemandTable($con); // also ensures found_start_time/found_end_time/found_updated_at exist

if (!isset($_POST['demand_id']) || !is_numeric($_POST['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}
$demandId = intval($_POST['demand_id']);
$editedBy = $_SESSION['user_id'];

$foundProvider = trim($_POST['found_provider'] ?? '');
$foundCost = trim($_POST['found_cost'] ?? '');
$foundDates = trim($_POST['found_dates'] ?? '');
$foundCapacity = isset($_POST['found_capacity']) && $_POST['found_capacity'] !== '' ? intval($_POST['found_capacity']) : null;
$foundVenue = trim($_POST['found_venue'] ?? '');
$actualModality = trim($_POST['actual_modality'] ?? '');
if (!in_array($actualModality, ['Face-to-Face', 'Online'], true)) {
    echo json_encode(['success' => false, 'message' => 'Please select the actual modality this training will run as.']);
    exit();
}
$foundNotes = trim($_POST['found_notes'] ?? '');
$foundStartTime = trim($_POST['found_start_time'] ?? '');
$foundEndTime = trim($_POST['found_end_time'] ?? '');
// 2026-09-06 - added alongside report_training_demand.php's same fields.
// Unlike that file, training_type isn't a hard-fail here if blank - this
// endpoint only ever runs on a demand that's already past the initial
// report (see the pipeline_status check below), so a value already
// exists; the Edit form always pre-fills this dropdown from it, but an
// old row edited before this column existed could still have one blank.
$trainingType = trim($_POST['training_type'] ?? '');
$isFree = isset($_POST['is_free']) && $_POST['is_free'] === '1';
if ($isFree) {
    $foundCost = 'Free';
}
$foundTrainingTitle = trim($_POST['found_training_title'] ?? '');

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

$demandStmt = $con->prepare("SELECT title, pipeline_status, found_capacity FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();
if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}
// Can only edit a training that's actually been found already - nothing
// to adjust before that.
if (!in_array($demand['pipeline_status'], ['Training Found', 'HR Approved', 'Confirmed', 'Training Completed'], true)) {
    echo json_encode(['success' => false, 'message' => 'This demand has no reported training to edit yet.']);
    exit();
}

// Can't shrink capacity below people already locked in (2026-08-31, real
// scenario the user caught: 15 confirmed, HR edits capacity down to 10) -
// who gets bumped from an already-Confirmed spot is a real HR decision,
// not something this save should make silently. Completed is included
// too (someone who already attended still "used" a slot). Training
// Available (not yet picked) doesn't count here - shrinking the pool of
// candidates HR can still choose from is fine, that's what capacity is
// for; only actual commitments are protected.
$lockedInStmt = $con->prepare("SELECT COUNT(*) c FROM training_recommendations WHERE demand_id = ? AND status IN ('Confirmed', 'Completed')");
$lockedInStmt->bind_param("i", $demandId);
$lockedInStmt->execute();
$lockedInCount = (int)$lockedInStmt->get_result()->fetch_assoc()['c'];
$lockedInStmt->close();

if ($foundCapacity < $lockedInCount) {
    echo json_encode(['success' => false, 'message' => "Capacity can't be lower than $lockedInCount - that many people are already confirmed or completed for this training. Remove someone from the confirmed list first if you need to reduce it."]);
    exit();
}

$updateStmt = $con->prepare("UPDATE training_demand
    SET found_provider = ?, found_cost = ?, is_free = ?, found_dates = ?, found_start_time = ?, found_end_time = ?,
        found_capacity = ?, found_venue = ?, actual_modality = ?, found_training_title = NULLIF(?, ''), found_notes = ?,
        training_type = CASE WHEN ? = '' THEN training_type ELSE ? END,
        found_updated_at = NOW()
    WHERE id = ?");
$updateStmt->bind_param(
    "ssisssissssssi",
    $foundProvider, $foundCost, $isFree, $foundDates, $foundStartTimeParam, $foundEndTimeParam, $foundCapacity, $foundVenue, $actualModality, $foundTrainingTitle, $foundNotes, $trainingType, $trainingType, $demandId
);
if (!$updateStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to save changes: ' . $con->error]);
    $updateStmt->close();
    exit();
}
$updateStmt->close();

// Notify everyone still actively tied to this demand - they need to know
// the date/time/venue/etc. they were relying on just changed. Completed
// participants are left alone (the training already happened for them).
$recipientsStmt = $con->prepare("SELECT id, user_id FROM training_recommendations WHERE demand_id = ? AND status IN ('Training Available', 'Confirmed')");
$recipientsStmt->bind_param("i", $demandId);
$recipientsStmt->execute();
$recipients = $recipientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recipientsStmt->close();

$message = 'HR updated the training details for "' . $demand['title'] . '". Please review the latest date, time, and venue.';
$notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_recommendation', 0, NOW())");
foreach ($recipients as $r) {
    $notifStmt->bind_param("isi", $r['user_id'], $message, $r['id']);
    $notifStmt->execute();
}
$notifStmt->close();

// If HR raised capacity enough to clear more room than is currently used,
// reopen the Not Selected pool - see reopenNotSelectedIfRoomAvailable()'s
// own comment for why this is a shared, generalized check (also used by
// unconfirm_training_participant.php) rather than a one-off "did capacity
// increase" diff.
$reopenedCount = reopenNotSelectedIfRoomAvailable($con, $demandId);

logAuditEvent($con, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'HR', $_SESSION['user_role'] ?? 'admin',
    'Edited Found Training Details', 'training_demand', $demandId,
    'Edited the found-training details for "' . $demand['title'] . '" - notified ' . count($recipients) . ' employee(s).');

echo json_encode(['success' => true, 'notified_count' => count($recipients), 'reopened_count' => $reopenedCount]);
