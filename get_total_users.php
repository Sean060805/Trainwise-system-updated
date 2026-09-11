<?php
require_once 'config.php';

$result = $con->query("SELECT COUNT(*) AS total FROM users");
$row = $result->fetch_assoc();

echo $row['total'];
?>

