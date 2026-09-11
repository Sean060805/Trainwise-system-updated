<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// Lets an employee describe a specific training by name when none of the
// 5 AI-suggested catalog titles fit what they actually need (2026-09-01,
// per the adviser's direction during the pipeline demo). Deliberately a
// separate table from training_recommendations, not a 6th recommendation
// row - these are free text, not scored/ranked catalog matches, and get
// grouped later by clusterCustomTrainingRequests() rather than tied to
// one exact title the way a catalog Accept is.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$requestText = trim($_POST['request_text'] ?? '');

if ($requestText === '') {
    echo json_encode(['success' => false, 'message' => 'Please describe the training you need.']);
    exit();
}
if (mb_strlen($requestText) < 6) {
    echo json_encode(['success' => false, 'message' => 'That looks too short to be useful - please describe it a bit more.']);
    exit();
}
if (mb_strlen($requestText) > 500) {
    echo json_encode(['success' => false, 'message' => 'Please keep this to 500 characters or fewer.']);
    exit();
}
// Word limit (2026-09-02, per the adviser) - mirrors the client-side
// counter in training_recommendations.php; enforced here too since the
// client-side check alone can be bypassed. A token longer than 30
// characters counts as multiple "words" worth of budget, same as the
// client-side logic - otherwise one long unbroken run of characters
// with no spaces would count as just 1 word (a real gap found via
// testing).
$words = preg_split('/\s+/', $requestText, -1, PREG_SPLIT_NO_EMPTY);
$weightedWordCount = 0;
foreach ($words as $w) {
    $weightedWordCount += max(1, (int)ceil(mb_strlen($w) / 30));
}
if ($weightedWordCount > 60) {
    echo json_encode(['success' => false, 'message' => 'Please keep it to 60 words or fewer.']);
    exit();
}

ensureCustomTrainingRequestsTable($con);

// Check against the catalog and every existing demand before treating
// this as a brand-new idea (2026-09-01, a real gap the adviser caught):
// without this, a request describing something already in the catalog
// (or an already-promoted custom cluster) would silently create a
// duplicate, separate demand instead of pooling into what already
// exists. Not a hard block though - $force lets the employee submit
// anyway once they've seen the suggestion, since a wording overlap can
// genuinely be a coincidence for something they actually mean differently.
$force = isset($_POST['force']) && $_POST['force'] === '1';
if (!$force) {
    $match = findExistingTrainingMatch($con, $requestText);
    if ($match !== null) {
        echo json_encode([
            'success' => false,
            'existing_match' => $match,
            'message' => 'This looks similar to "' . $match['title'] . '".',
        ]);
        exit();
    }
}

$stmt = $con->prepare("INSERT INTO custom_training_requests (user_id, request_text) VALUES (?, ?)");
$stmt->bind_param("is", $userId, $requestText);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Could not save your request - please try again.']);
    $stmt->close();
    exit();
}
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Sent to HR. If enough colleagues ask for something similar, it gets pooled into a real training request.']);
