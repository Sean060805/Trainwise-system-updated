<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

ensureCustomTrainingRequestsTable($con);

// The raw, ungrouped list is always returned - HR should be able to see
// every individual submission even before running clustering, same
// transparency principle as the AI Shortlist showing excluded candidates
// instead of hiding them.
$raw = $con->query("
    SELECT ctr.id, ctr.request_text, ctr.status, ctr.cluster_label, ctr.created_at,
           u.name, u.department
    FROM custom_training_requests ctr
    JOIN users u ON u.id = ctr.user_id
    ORDER BY ctr.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$response = ['success' => true, 'raw' => $raw];

// Clustering only runs when explicitly asked for (?cluster=1) - it's a
// real ML call with real latency, not something to fire on every page
// load of the HR dashboard.
if (isset($_GET['cluster'])) {
    $clusters = clusterCustomTrainingRequests($con);
    if ($clusters === null) {
        $response['cluster_error'] = 'The AI service is unreachable right now - please try again in a moment.';
    } else {
        // Also check each cluster's own label against the catalog and
        // existing demands (2026-09-01) - a cluster of custom requests can
        // itself turn out to describe something that already exists, even
        // if no individual submission triggered the same check earlier
        // (e.g. it was force-submitted, or promoted from an older version
        // before this check existed). Surfaced to HR as a suggestion, not
        // auto-applied - HR still decides whether to keep the AI's label
        // or the suggested existing one when promoting.
        foreach ($clusters as &$cluster) {
            $cluster['existing_match'] = findExistingTrainingMatch($con, $cluster['label']);
        }
        unset($cluster);
        $response['clusters'] = $clusters;
    }
}

echo json_encode($response);
