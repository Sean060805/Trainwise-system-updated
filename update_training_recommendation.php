<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$userId = $_SESSION['user_id'];
$id = $_POST['id'] ?? 0;
$status = $_POST['status'] ?? '';

if (empty($id) || empty($status)) {
    echo json_encode(['success' => false]);
    exit();
}

// Employee-triggered transitions only. Accepted->Training Available and
// Training Available->Confirmed are system/HR-triggered elsewhere
// (approve_training_demand.php / confirm_training_participant.php) - an
// employee can never reach them through this endpoint.
$allowedFrom = [
    'Accepted'  => 'Recommended',
    'Declined'  => 'Recommended',
    'Completed' => 'Confirmed',
];

if (!array_key_exists($status, $allowedFrom)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status transition']);
    exit();
}

ensureTrainingRecommendationsTable($con);
ensureTrainingHistoryLogTable($con);

// 2026-09-10 fix - same bug as upload_training_proof.php's identical
// query (see its comment): joining training_demand here too so the
// Completed branch below can use the REAL found_training_title/
// training_type instead of the stale pre-dean-report catalog guess.
$currentStmt = $con->prepare("
    SELECT tr.status, tr.title, tr.description, tr.training_type, tr.demand_id, tr.proof_certificate_path, tr.proof_approval_letter_path, tr.proof_program_path, tr.proof_hours,
           td.found_training_title, td.training_type AS real_training_type
    FROM training_recommendations tr
    LEFT JOIN training_demand td ON td.id = tr.demand_id
    WHERE tr.id = ? AND tr.user_id = ?
");
$currentStmt->bind_param("ii", $id, $userId);
$currentStmt->execute();
$current = $currentStmt->get_result()->fetch_assoc();
$currentStmt->close();

if (!$current || $current['status'] !== $allowedFrom[$status]) {
    echo json_encode(['success' => false, 'message' => 'This recommendation cannot be moved to that status right now']);
    exit();
}

// Hard gate: certificate, HR approval letter, event program, and hours
// must ALL be on file before Completed is reachable - the disabled
// button in training_recommendations.php is just a UX nicety, this is
// the real enforcement (per the 2026-08-27 requirement).
if ($status === 'Completed') {
    $missingProof = empty($current['proof_certificate_path']) || empty($current['proof_approval_letter_path'])
        || empty($current['proof_program_path']) || $current['proof_hours'] === null;
    if ($missingProof) {
        echo json_encode(['success' => false, 'message' => 'Please upload your certificate, HR approval letter, and event program, and enter the number of hours, before marking this complete.']);
        exit();
    }
}

$completionDate = ($status === 'Completed') ? date('Y-m-d H:i:s') : null;
$demandId = null;

if ($status === 'Accepted') {
    // Modality is no longer captured here (removed 2026-09-01, per the
    // adviser's explicit direction during the pipeline demo): an employee
    // isn't in a position to decide Face-to-Face vs Online at Accept time
    // - that's determined once HR actually talks to a real training
    // provider (see training_demand.actual_modality, which is exactly
    // that - recorded by HR/the dean when reporting what they found, not
    // guessed by the employee beforehand). preferred_modality stays in
    // the schema, unused, rather than dropped - same convention already
    // used for other superseded columns in this codebase (e.g. link,
    // dean_link on this same table).
    // 2026-09-09 fix - same reasoning as actual_modality above, just found
    // later: this used to pass $current['training_type'], seeding the new
    // demand row with the catalog's original planning assumption (e.g.
    // "Workshop") as if a real provider had already confirmed it. That's
    // exactly the fiction promote_custom_request_cluster.php already
    // avoids by passing null here (see its own $trainingType = null) and
    // report_training_demand.php already REQUIRES the dean/HR to set the
    // real value once they've actually found a provider - this Accept-flow
    // path was the one place still skipping that and asserting a guess as
    // fact. training_pipeline.php's "To be determined" fallback (and the
    // employee-facing card's 2026-09-09 badge removal) already expect
    // training_type to be genuinely unknown until then.
    $demandId = findOrCreateTrainingDemand($con, $current['title'], $current['description'], null);
    $stmt = $con->prepare("UPDATE training_recommendations SET status = ?, demand_id = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("siii", $status, $demandId, $id, $userId);
} else {
    $stmt = $con->prepare("UPDATE training_recommendations SET status = ?, completion_date = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ssii", $status, $completionDate, $id, $userId);
}

if ($stmt->execute()) {
    if ($status === 'Completed') {
        // Write the permanent, proof-backed record - separate from
        // assessments.training_history (self-reported, different purpose).
        $historyTitle = $current['found_training_title'] ?: $current['title'];
        $historyTrainingType = $current['real_training_type'] ?: $current['training_type'];
        $insertHistory = $con->prepare("INSERT INTO training_history_log
            (user_id, recommendation_id, title, training_type, hours, completion_date, certificate_path, approval_letter_path, program_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertHistory->bind_param(
            "iissdssss",
            $userId, $id, $historyTitle, $historyTrainingType, $current['proof_hours'],
            $completionDate, $current['proof_certificate_path'], $current['proof_approval_letter_path'], $current['proof_program_path']
        );
        if (!$insertHistory->execute()) {
            error_log("update_training_recommendation: failed to write training_history_log for recommendation $id: " . $insertHistory->error);
        }
        $insertHistory->close();

        // If this was the last Confirmed participant still pending
        // completion, the demand itself is done - flip it automatically.
        maybeMarkTrainingDemandCompleted($con, $current['demand_id']);
    }
    $actionLabel = ['Accepted' => 'Accepted Training', 'Declined' => 'Declined Training', 'Completed' => 'Marked Training Complete'][$status] ?? $status;
    logAuditEvent($con, $userId, $_SESSION['user_name'] ?? 'Employee', $_SESSION['user_role'] ?? 'user',
        $actionLabel, 'training_recommendation', (int)$id,
        ($_SESSION['user_name'] ?? 'An employee') . ' marked "' . $current['title'] . '" as ' . $status . '.');
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}

$stmt->close();
?>
