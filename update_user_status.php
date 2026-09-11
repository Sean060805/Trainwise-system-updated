<?php
session_start();
require 'config.php';

// Check admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $action = $_POST['action'];

    if (in_array($action, ['accept', 'decline'])) {
        $status = ($action === 'accept') ? 'accepted' : 'declined';

        $stmt = $con->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $user_id);
        $stmt->execute();
    }
}

header("Location: admin_page.php");
exit();
