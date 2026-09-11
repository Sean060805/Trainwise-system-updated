<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

// HR-only audit log viewer (2026-09-02, per the adviser). Simple
// offset-based pagination, matching get_submissions.php's own convention
// in this codebase - no need for cursor-based paging at this scale.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

ensureAuditLogTable($con);

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

$search = trim($_GET['search'] ?? '');
$where = '';
$params = [];
$types = '';
if ($search !== '') {
    $where = "WHERE description LIKE ? OR actor_name LIKE ? OR action LIKE ?";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

$countStmt = $con->prepare("SELECT COUNT(*) c FROM audit_log $where");
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$stmt = $con->prepare("
    SELECT actor_name, actor_role, action, entity_type, entity_id, description, created_at
    FROM audit_log
    $where
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'entries' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / $perPage)),
]);
