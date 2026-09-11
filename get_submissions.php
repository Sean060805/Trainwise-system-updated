<?php
require 'config.php';

// Check if deadline ID is provided
if (!isset($_GET['deadline_id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Deadline ID is required']);
    exit();
}

$deadlineId = intval($_GET['deadline_id']);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 10;
$offset = ($page - 1) * $perPage;

// Get submissions with user info
$stmt = $con->prepare("
    SELECT 
        u.name, 
        u.department,
        CASE 
            WHEN a.submission_date <= s.submission_deadline THEN 'On Time'
            ELSE 'Late'
        END AS status,
        a.submission_date
    FROM 
        assessments a
    JOIN 
        users u ON a.user_id = u.id
    JOIN 
        settings s ON a.deadline_id = s.id
    WHERE 
        a.deadline_id = ?
    ORDER BY 
        a.submission_date DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $deadlineId, $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();

$submissions = [];
while ($row = $result->fetch_assoc()) {
    $submissions[] = [
        'name' => htmlspecialchars($row['name']),
        'department' => htmlspecialchars($row['department']),
        'status' => $row['status'],
        'formatted_date' => date('M j, Y g:i A', strtotime($row['submission_date']))
    ];
}

header('Content-Type: application/json');
echo json_encode(['submissions' => $submissions]);
?>