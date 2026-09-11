<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

// ---- Auth check -----------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// ISO 25010 Security audit (2026-09-06): same missing-role-check gap as
// the dashboard pages - any logged-in account could pull ANY employee's
// assessment by ID here, no department/role ownership check existed.
if (($_SESSION['user_role'] ?? '') !== 'admin_conah') {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// ---- Include config -------------------------------------------------
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    echo json_encode(['success' => false, 'message' => 'Config file not found']);
    exit();
}

// ---- Make sure DB connection exists ---------------------------------
if (!isset($con) || !$con) {
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit();
}

// ---- Validate assessment ID -----------------------------------------
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid assessment ID']);
    exit();
}
$assessment_id = intval($_GET['id']);

// ---- First, check if the assessment exists -------------------------
$check_sql = "SELECT a.id, a.user_id, u.department, u.name 
              FROM assessments a
              LEFT JOIN users u ON a.user_id = u.id
              WHERE a.id = ?";
$check_stmt = $con->prepare($check_sql);
if (!$check_stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $con->error]);
    exit();
}
$check_stmt->bind_param("i", $assessment_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Assessment ID ' . $assessment_id . ' not found in database']);
    $check_stmt->close();
    exit();
}

$assessment_info = $check_result->fetch_assoc();
$check_stmt->close();

// ---- Now get the full details ---------------------------------------
$sql = "SELECT 
            u.id as user_id,
            u.name,
            u.department,
            u.teaching_status,
            u.email,
            u.educationalAttainment,
            u.specialization,
            u.designation,
            u.yearsInLSPU,
            a.training_history,
            a.desired_skills,
            a.comments,
            a.submission_date,
            a.id as assessment_id
        FROM users u
        INNER JOIN assessments a ON u.id = a.user_id
        WHERE a.id = ?";

$stmt = $con->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error (prepare): ' . $con->error]);
    exit();
}

$stmt->bind_param("i", $assessment_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();

    // Parse training_history JSON safely
    $training_history = [];
    if (!empty($row['training_history'])) {
        $decoded = json_decode($row['training_history'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $training_history = $decoded;
        }
    }

    $response = [
        'success' => true,
        'assessment' => [
            'name'                  => $row['name'] ?? 'N/A',
            'department'            => $row['department'] ?? 'N/A',
            'teaching_status'       => $row['teaching_status'] ?? 'N/A',
            'email'                 => $row['email'] ?? 'N/A',
            'educationalAttainment' => $row['educationalAttainment'] ?? 'Not specified',
            'specialization'        => $row['specialization'] ?? 'Not specified',
            'designation'           => $row['designation'] ?? 'Not specified',
            'yearsInLSPU'           => $row['yearsInLSPU'] ?? 'Not specified',
            'training_history'      => $training_history,
            'desired_skills'        => $row['desired_skills'] ?? '',
            'comments'              => $row['comments'] ?? '',
            'submission_date'       => $row['submission_date'] ?? null
        ]
    ];

    echo json_encode($response);
} else {
    // This shouldn't happen since we already checked, but just in case
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve assessment details']);
}

$stmt->close();
?>