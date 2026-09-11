<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

ensureTrainingRecommendationsTable($con);

// Same aggregation as training_needs_summary_pdf.php - see that file for
// the reasoning on why this groups by training (not by college, unlike
// the old manually-compiled PDF) and why it's sorted by demand.
$candidatesStmt = $con->query("
    SELECT d.id, d.title, d.training_type, d.pipeline_status, u.department
    FROM training_demand d
    JOIN training_recommendations tr ON tr.demand_id = d.id
    JOIN users u ON u.id = tr.user_id
    WHERE tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
");
$candidates = $candidatesStmt ? $candidatesStmt->fetch_all(MYSQLI_ASSOC) : [];

$demandSummary = [];
foreach ($candidates as $row) {
    $id = $row['id'];
    if (!isset($demandSummary[$id])) {
        $demandSummary[$id] = [
            'title' => $row['title'],
            'training_type' => $row['training_type'],
            'pipeline_status' => $row['pipeline_status'],
            'college_counts' => [],
        ];
    }
    $code = canonicalTnaCollegeCode($row['department']) ?? 'Other';
    $demandSummary[$id]['college_counts'][$code] = ($demandSummary[$id]['college_counts'][$code] ?? 0) + 1;
}

$rows = [];
foreach ($demandSummary as $d) {
    $parts = [];
    foreach ($d['college_counts'] as $code => $count) {
        $parts[] = "$code ($count)";
    }
    $rows[] = [
        'title' => htmlspecialchars($d['title']),
        'training_type' => $d['training_type'] ? htmlspecialchars($d['training_type']) : 'To be determined',
        'total' => array_sum($d['college_counts']),
        'breakdown' => htmlspecialchars(implode(', ', $parts)),
        'pipeline_status' => htmlspecialchars($d['pipeline_status']),
    ];
}

usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);

echo json_encode(['success' => true, 'rows' => $rows]);
