<?php
/**
 * Upload endpoint for the proof-of-completion documents (certificate, HR
 * approval letter, event program) + hours attended, staged on a
 * training_recommendations row.
 *
 * 2026-08-29: once all four pieces (cert, letter, program, hours) are on
 * file, this endpoint auto-transitions the row straight to 'Completed'
 * itself - there is no longer a separate "Mark Complete" click. Per the
 * user's own testing feedback: nobody but the employee uploading the
 * proof is involved in that click, so it added a confusing extra step
 * with no real checkpoint value. This mirrors the exact Completed-branch
 * logic in update_training_recommendation.php (history log insert +
 * maybeMarkTrainingDemandCompleted) so both entry points stay consistent.
 */
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}
$userId = $_SESSION['user_id'];
$recommendationId = intval($_POST['recommendation_id'] ?? 0);
if (empty($recommendationId)) {
    echo json_encode(['success' => false, 'message' => 'Missing recommendation ID']);
    exit();
}

ensureTrainingRecommendationsTable($con);
ensureTrainingHistoryLogTable($con);

// Ownership + status check - uploads only allowed while awaiting completion.
// 2026-09-10 fix - real, live bug found via a screenshot: the Training
// History record was saving tr.title/tr.training_type - the ORIGINAL
// catalog guess from before a dean ever found a real training - instead
// of the real found_training_title/training_type on training_demand.
// training_recommendations.php's own display already does exactly this
// same "found value overrides the catalog guess" join for the on-page
// card (see its found_training_title ?: title pattern) - this endpoint
// just wasn't pulling the same real values before writing the
// permanent history record.
$stmt = $con->prepare("
    SELECT tr.title, tr.training_type, tr.demand_id, tr.proof_certificate_path, tr.proof_approval_letter_path, tr.proof_program_path,
           td.found_training_title, td.training_type AS real_training_type
    FROM training_recommendations tr
    LEFT JOIN training_demand td ON td.id = tr.demand_id
    WHERE tr.id = ? AND tr.user_id = ? AND tr.status = 'Confirmed'
");
$stmt->bind_param("ii", $recommendationId, $userId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$current) {
    echo json_encode(['success' => false, 'message' => 'This training is not awaiting proof of completion.']);
    exit();
}

$uploadDir = 'uploads/training_proofs/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// profile.php's exact validation pattern (mime_content_type() on
// tmp_name, not the client-sent type), extended to allow PDF alongside
// images since these are official documents, not just photos.
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
$maxBytes = 5 * 1024 * 1024;

// fieldName in $_FILES => [DB column, docType used in the saved filename]
$docSlots = [
    'proof_certificate'     => ['proof_certificate_path', 'certificate'],
    'proof_approval_letter' => ['proof_approval_letter_path', 'approval_letter'],
    'proof_program'         => ['proof_program_path', 'program'],
];

$newPaths = [];
$oldPathsToDelete = [];
$error = '';

foreach ($docSlots as $fieldName => $slot) {
    [$column, $docType] = $slot;
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        continue; // this slot wasn't submitted this time - incremental upload is fine
    }

    $fileType = mime_content_type($_FILES[$fieldName]['tmp_name']);
    $fileExt = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));

    if (!in_array($fileType, $allowedTypes)) {
        $error = "Only PDF, JPG, PNG, or GIF files are allowed.";
        break;
    }
    if ($_FILES[$fieldName]['size'] > $maxBytes) {
        $error = "Each file must be less than 5MB.";
        break;
    }

    $newFilename = $recommendationId . '_' . $userId . '_' . $docType . '_' . time() . '.' . $fileExt;
    $uploadPath = $uploadDir . $newFilename;
    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $uploadPath)) {
        $newPaths[$column] = $newFilename;
        if (!empty($current[$column])) {
            $oldPathsToDelete[] = $uploadDir . $current[$column];
        }
    } else {
        $error = "Failed to upload one of the files.";
        break;
    }
}

if ($error !== '') {
    echo json_encode(['success' => false, 'message' => $error]);
    exit();
}

$hoursProvided = isset($_POST['hours']) && $_POST['hours'] !== '';
$hours = $hoursProvided ? (float)$_POST['hours'] : null;
if ($hoursProvided && ($hours <= 0 || $hours > 999)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid number of hours.']);
    exit();
}

if (empty($newPaths) && !$hoursProvided) {
    echo json_encode(['success' => false, 'message' => 'Nothing to upload.']);
    exit();
}

$setClauses = ['proof_uploaded_at = NOW()'];
$types = '';
$values = [];
foreach ($newPaths as $column => $filename) {
    $setClauses[] = "$column = ?";
    $types .= 's';
    $values[] = $filename;
}
if ($hoursProvided) {
    $setClauses[] = 'proof_hours = ?';
    $types .= 'd';
    $values[] = $hours;
}
$types .= 'ii';
$values[] = $recommendationId;
$values[] = $userId;

$sql = "UPDATE training_recommendations SET " . implode(', ', $setClauses) . " WHERE id = ? AND user_id = ?";
$updateStmt = $con->prepare($sql);
$updateStmt->bind_param($types, ...$values);

if ($updateStmt->execute()) {
    foreach ($oldPathsToDelete as $old) {
        if (file_exists($old)) {
            @unlink($old);
        }
    }
    $checkStmt = $con->prepare("SELECT proof_certificate_path, proof_approval_letter_path, proof_program_path, proof_hours FROM training_recommendations WHERE id = ?");
    $checkStmt->bind_param("i", $recommendationId);
    $checkStmt->execute();
    $row = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    $allComplete = !empty($row['proof_certificate_path']) && !empty($row['proof_approval_letter_path'])
        && !empty($row['proof_program_path']) && $row['proof_hours'] !== null;

    if ($allComplete) {
        // Same transition update_training_recommendation.php's Completed
        // branch performs - duplicated here (not called via HTTP) so this
        // stays one atomic request instead of two round trips.
        $completionDate = date('Y-m-d H:i:s');
        $completeStmt = $con->prepare("UPDATE training_recommendations SET status = 'Completed', completion_date = ? WHERE id = ? AND user_id = ?");
        $completeStmt->bind_param("sii", $completionDate, $recommendationId, $userId);
        $completeStmt->execute();
        $completeStmt->close();

        // Real found values win when a dean/HR actually reported them;
        // fall back to the original catalog guess only if this demand was
        // somehow never reported (shouldn't happen by the time someone
        // reaches Confirmed, but matches the same defensive pattern
        // training_recommendations.php's own display already uses).
        $historyTitle = $current['found_training_title'] ?: $current['title'];
        $historyTrainingType = $current['real_training_type'] ?: $current['training_type'];
        $insertHistory = $con->prepare("INSERT INTO training_history_log
            (user_id, recommendation_id, title, training_type, hours, completion_date, certificate_path, approval_letter_path, program_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertHistory->bind_param(
            "iissdssss",
            $userId, $recommendationId, $historyTitle, $historyTrainingType, $row['proof_hours'],
            $completionDate, $row['proof_certificate_path'], $row['proof_approval_letter_path'], $row['proof_program_path']
        );
        if (!$insertHistory->execute()) {
            error_log("upload_training_proof: failed to write training_history_log for recommendation $recommendationId: " . $insertHistory->error);
        }
        $insertHistory->close();

        maybeMarkTrainingDemandCompleted($con, $current['demand_id']);
    }

    echo json_encode(['success' => true, 'all_complete' => $allComplete]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save.']);
}
$updateStmt->close();
