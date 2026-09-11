<?php
session_start();
header('Content-Type: application/json');

// DB connection
$conn = new mysqli("localhost", "root", "", "user_db");
if ($conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Database error"]);
    exit;
}

// Check session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "error" => "Session expired. Please login again."]);
    $conn->close();
    exit;
}

$user_id = $_SESSION['user_id'];
$response = [];

try {
    // Query user profile data
    $stmt = $conn->prepare("SELECT name, educationalAttainment, specialization, designation, department, yearsInLSPU, teaching_status FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database error");
    }
    
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Database error");
    }
    
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        echo json_encode(["success" => false, "error" => "User data not found"]);
        $stmt->close();
        $conn->close();
        exit;
    }

    $stmt->bind_result($name, $educ, $spec, $desig, $dept, $years, $teach);
    $stmt->fetch();
    
    // Check if profile exists (at least name and education)
    $hasProfile = (!empty(trim($name ?? ''))) && (!empty(trim($educ ?? '')));
    
    $response = [
        'success' => true,
        'hasProfile' => $hasProfile,
        'profileData' => [
            'name' => htmlspecialchars($name ?? ''),
            'educationalAttainment' => htmlspecialchars($educ ?? ''),
            'specialization' => htmlspecialchars($spec ?? ''),
            'designation' => htmlspecialchars($desig ?? ''),
            'department' => htmlspecialchars($dept ?? ''),
            'yearsInLSPU' => htmlspecialchars($years ?? ''),
            'teaching_status' => htmlspecialchars($teach ?? '')
        ]
    ];

} catch (Exception $e) {
    $response = ["success" => false, "error" => $e->getMessage()];
} finally {
    if (isset($stmt) && $stmt) {
        $stmt->close();
    }
    $conn->close();
    echo json_encode($response);
    exit;
}
?>