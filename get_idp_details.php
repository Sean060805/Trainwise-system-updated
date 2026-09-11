<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID required']);
    exit();
}

$id = intval($_GET['id']);

$query = "SELECT f.*, u.name as user_name, u.department, u.teaching_status 
          FROM idp_forms f 
          JOIN users u ON f.user_id = u.id 
          WHERE f.id = ?";
$stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $row['form_data'] = json_decode($row['form_data'], true);
    echo json_encode(['success' => true, 'idp' => $row]);
} else {
    echo json_encode(['success' => false, 'error' => 'IDP not found']);
}

mysqli_stmt_close($stmt);
mysqli_close($con);
?>