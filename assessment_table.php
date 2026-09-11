<?php
session_start();
require 'config.php'; // Make sure this defines $con as the MySQLi connection

// Ensure user is logged in
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $_SESSION['flash_message'] = "User not logged in.";
    $_SESSION['flash_type'] = "error";
    header("Location: user_page.php");
    exit;
}

// Get current active deadline ID
$deadline_id = null;
$deadline_result = $con->query("SELECT id FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
if ($deadline_result && $deadline_result->num_rows > 0) {
    $deadline_row = $deadline_result->fetch_assoc();
    $deadline_id = $deadline_row['id'];
}

// Check if user has already submitted for this deadline
if ($deadline_id) {
    $check_stmt = $con->prepare("SELECT id FROM assessments WHERE user_id = ? AND deadline_id = ?");
    $check_stmt->bind_param("ii", $user_id, $deadline_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result && $check_result->num_rows > 0) {
        $_SESSION['flash_message'] = "You have already submitted an assessment for this period.";
        $_SESSION['flash_type'] = "error";
        header("Location: user_page.php");
        exit;
    }
    $check_stmt->close();
}

// Collect and sanitize POST data
$dates        = $_POST['date'] ?? [];
$start_times  = $_POST['start_time'] ?? [];
$end_times    = $_POST['end_time'] ?? [];
$durations    = $_POST['duration'] ?? [];
$trainings    = $_POST['training'] ?? [];
$venues       = $_POST['venue'] ?? [];
$desired_skills = trim($_POST['desired_skills'] ?? '');
$comments       = trim($_POST['comments'] ?? '');

// Validate row count consistency
$rowCount = count($dates);
if (
    $rowCount === 0 ||
    $rowCount !== count($trainings) ||
    $rowCount !== count($venues) ||
    $rowCount !== count($start_times) ||
    $rowCount !== count($end_times)
) {
    $_SESSION['flash_message'] = "Error: Incomplete or mismatched training data.";
    $_SESSION['flash_type'] = "error";
    header("Location: user_page.php");
    exit;
}

// Build training history array
$training_history = [];
for ($i = 0; $i < $rowCount; $i++) {
    $training_history[] = [
        'date'       => htmlspecialchars($dates[$i]),
        'start_time' => htmlspecialchars($start_times[$i] ?? ''),
        'end_time'   => htmlspecialchars($end_times[$i] ?? ''),
        'duration'   => htmlspecialchars($durations[$i] ?? ''),
        'training'   => htmlspecialchars($trainings[$i]),
        'venue'      => htmlspecialchars($venues[$i]),
    ];
}

// Convert to JSON
$training_history_json = json_encode($training_history, JSON_UNESCAPED_UNICODE);

// Determine submission status (on-time or late)
$status = 'submitted';
if ($deadline_id) {
    $deadline_query = $con->query("SELECT submission_deadline FROM settings WHERE id = $deadline_id");
    if ($deadline_query && $deadline_row = $deadline_query->fetch_assoc()) {
        $deadline = new DateTime($deadline_row['submission_deadline']);
        $now = new DateTime();
        if ($now > $deadline) {
            $status = 'late';
        }
    }
}

// Save to database
try {
    $stmt = $con->prepare("INSERT INTO assessments 
                          (user_id, deadline_id, training_history, desired_skills, comments, status, submission_date) 
                          VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $con->error);
    }

    $stmt->bind_param("iissss", $user_id, $deadline_id, $training_history_json, $desired_skills, $comments, $status);

    if ($stmt->execute()) {
        // Mark any deadline notifications as read
        $mark_read = $con->prepare("UPDATE notifications SET is_read = 1 
                                   WHERE user_id = ? AND related_type = 'deadline'");
        $mark_read->bind_param("i", $user_id);
        $mark_read->execute();
        $mark_read->close();
        
        $_SESSION['flash_message'] = "Training Needs Assessment submitted successfully.";
        $_SESSION['flash_type'] = "success";
        $_SESSION['form_submission_status'] = "success";
        $_SESSION['form_submission_message'] = "Your assessment has been recorded.";
    } else {
        throw new Exception("Database error: " . $stmt->error);
    }

    $stmt->close();
} catch (Exception $e) {
    $_SESSION['flash_message'] = "Submission failed: " . $e->getMessage();
    $_SESSION['flash_type'] = "error";
    $_SESSION['form_submission_status'] = "error";
    $_SESSION['form_submission_message'] = $e->getMessage();
}

// Close connection if needed
if ($con) {
    $con->close();
}

// Redirect back
header("Location: user_page.php");
exit;
?>