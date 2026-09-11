<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get image via POST or session
if (isset($_POST['user_id'])) {
    $user_id = $_POST['user_id'];
}

$stmt = $con->prepare("SELECT image_data FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($image_data);
$stmt->fetch();

if (!empty($image_data)) {
    // Output as image
    header("Content-Type: image/jpeg");
    echo $image_data;
} else {
    // Output default image
    $default_image = 'images/noprofile.jpg';
    if (file_exists($default_image)) {
        header("Content-Type: image/jpeg");
        readfile($default_image);
    }
}

$stmt->close();
$con->close();
?>