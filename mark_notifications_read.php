<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Companion endpoint to get_notifications.php (2026-09-02 fix - found via
// the same error-log sweep: markAllAsRead() in CCS/CCJE/CFND/CAS/CBAA's
// dashboards has been fetch()-ing this file since the bell dropdown was
// built, but, like get_notifications.php, it was never actually created -
// every click silently 404'd and the console-logged catch() swallowed it,
// so the bell-dot/badge never cleared. Marks this dean's own persisted
// `notifications` rows as read; harmless best-effort since the page-load
// badge count doesn't currently filter on is_read either.
if (!isset($_SESSION['user_id']) || strpos($_SESSION['user_role'] ?? '', 'admin_') !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit();
}

$userId = $_SESSION['user_id'];

$stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
