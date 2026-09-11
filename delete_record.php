<?php
// Database connection parameters
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "user_db";

// Create connection using mysqli
$con = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}

// Check if 'name' is provided in POST request
if (isset($_POST['name'])) {
    $name = $_POST['name'];

    // Prepare statement to find user by name
    $stmt = $con->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if user exists
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $userId = $user['id'];

        // Prepare statement to delete assessments for that user
        $delStmt = $con->prepare("DELETE FROM assessments WHERE user_id = ?");
        $delStmt->bind_param("i", $userId);

        if ($delStmt->execute()) {
            // Optional: delete the user itself if needed
            /*
            $delUserStmt = $con->prepare("DELETE FROM users WHERE id = ?");
            $delUserStmt->bind_param("i", $userId);
            $delUserStmt->execute();
            $delUserStmt->close();
            */

            echo 'success';
        } else {
            echo 'failed to delete assessments';
        }
        $delStmt->close();
    } else {
        echo 'user not found';
    }
    $stmt->close();
} else {
    echo 'no name provided';
}

// Close connection
$con->close();
?>
