<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_GET['id']);

// 2026-09-06 - HR asked to see WHO actually found/posted the training, not
// just a generic "the dean" - joins to the real name (and role, so the
// label can correctly say "Dean" vs "HR" instead of guessing from has_dean,
// which only reflects whether a dean COULD be involved, not who actually did it).
$demandStmt = $con->prepare("SELECT d.id, d.title, d.found_training_title, d.description, d.training_type, d.pipeline_status, d.budget_hint,
    d.found_training_details, d.found_provider, d.found_cost, d.is_free, d.found_dates, d.found_start_time, d.found_end_time,
    d.found_capacity, d.found_venue, d.actual_modality, d.found_notes, d.found_updated_at,
    d.cancellation_reason, d.cancelled_at,
    fb.name AS found_by_name, fb.role AS found_by_role, fb.department AS found_by_department,
    cb.name AS cancelled_by_name, cb.role AS cancelled_by_role
    FROM training_demand d
    LEFT JOIN users fb ON fb.id = d.found_by_user_id
    LEFT JOIN users cb ON cb.id = d.cancelled_by_user_id
    WHERE d.id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}

$badgeClasses = [
    'Pending HR Review' => 'badge-pending',
    'Forwarded to Dean' => 'badge-info',
    'Training Found'    => 'badge-on-time',
    'No Training Found' => 'badge-declined',
    'HR Approved'       => 'badge-accepted',
    'Confirmed'         => 'badge-accepted',
    'Training Completed' => 'badge-on-time',
    'Closed'            => 'badge-no-sub',
];
$demand['status_badge_class'] = $badgeClasses[$demand['pipeline_status']] ?? 'badge-info';

$requestersStmt = $con->prepare("
    SELECT tr.id, tr.user_id, u.name, u.department, tr.status,
           tr.proof_certificate_path, tr.proof_approval_letter_path, tr.proof_program_path, tr.proof_hours
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.demand_id = ? AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed', 'Not Selected', 'Cancelled')
    ORDER BY tr.updated_at DESC
");
$requestersStmt->bind_param("i", $demandId);
$requestersStmt->execute();
$result = $requestersStmt->get_result();

// Split out "Not Selected" people into their own list rather than mixing
// them into the main table (2026-08-28 finding: sitting them next to
// still-active requesters, with the same "Proof of Completion" column
// showing a dash, reads as "still waiting on something" when they're
// actually a closed-out case). Not removed entirely though - HR still
// has a legitimate reason to see who wasn't picked, same reasoning as
// keeping "Excluded" people visible in the AI Shortlist instead of
// hiding them.
$requesters = [];
$notSelected = [];
$cancelled = [];
$rawDepartments = [];
while ($row = $result->fetch_assoc()) {
    $rawDepartments[] = $row['department'];
    $entry = [
        'recommendation_id' => (int)$row['id'],
        'name' => htmlspecialchars($row['name']),
        'department' => htmlspecialchars($row['department'] ?? 'N/A'),
        'status' => htmlspecialchars($row['status']),
        // Only meaningful for Confirmed/Completed rows - null on
        // Accepted/Training Available, which the UI treats as "no proof yet".
        'proof_certificate_path' => $row['proof_certificate_path'],
        'proof_approval_letter_path' => $row['proof_approval_letter_path'],
        'proof_program_path' => $row['proof_program_path'],
        'proof_hours' => $row['proof_hours'],
    ];
    if ($row['status'] === 'Not Selected') {
        $notSelected[] = $entry;
    } elseif ($row['status'] === 'Cancelled') {
        // 2026-09-15 - same "don't mix into the active table" reasoning as
        // Not Selected above, but a distinct bucket: these people didn't
        // lose out on a spot, the whole demand was closed as
        // 'No Training Found' (see reject_training_demand.php) - a
        // different enough story that folding them into Not Selected's
        // list would misrepresent why they're no longer active.
        $cancelled[] = $entry;
    } else {
        $requesters[] = $entry;
    }
}
$requestersStmt->close();

// Whether any requester's college actually has a dean account to forward
// to (2026-08-29) - a demand made up entirely of "ADMIN" (central non-
// teaching office) requesters has no admin_admin role, so forwarding
// would silently reach nobody. admin_page.php uses this to show "Report
// Directly" instead of "Forward to Dean(s)" for exactly that case - see
// report_training_demand.php's HR self-report path for the enforcement.
$collegeCodes = array_values(array_unique(array_filter(array_map('canonicalTnaCollegeCode', $rawDepartments))));
$hasDean = false;
if (!empty($collegeCodes)) {
    $roles = array_map(fn($c) => 'admin_' . strtolower($c), $collegeCodes);
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $deanCheckStmt = $con->prepare("SELECT id FROM users WHERE role IN ($placeholders) LIMIT 1");
    $deanCheckStmt->bind_param(str_repeat('s', count($roles)), ...$roles);
    $deanCheckStmt->execute();
    $hasDean = $deanCheckStmt->get_result()->num_rows > 0;
    $deanCheckStmt->close();
}

echo json_encode(['success' => true, 'demand' => $demand, 'requesters' => $requesters, 'not_selected' => $notSelected, 'cancelled' => $cancelled, 'has_dean' => $hasDean]);
