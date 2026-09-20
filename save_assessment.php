<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

// Disable error display for production, enable logging instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$response = ['success' => false, 'message' => ''];

try {
    // 1. Verify session and CSRF token
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Session expired. Please login again.");
    }

    if (!isset($_POST['csrf_token']) || empty($_POST['csrf_token'])) {
        throw new Exception("Security token missing.");
    }
    
    if ($_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        throw new Exception("Security verification failed. Please refresh the page.");
    }

    $user_id = (int)$_SESSION['user_id'];

    // 2. Check for required form data
    if (!isset($_POST['print_training_data'])) {
        throw new Exception("No training data submitted.");
    }

    require_once 'config.php';
    
    // Check database connection
    if (!$con || $con->connect_error) {
        throw new Exception("Database connection error.");
    }
    
    // Get current active deadline
    $deadline_query = $con->query("SELECT id, submission_deadline, allow_submissions FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
    if (!$deadline_query) {
        throw new Exception("Database query error: " . $con->error);
    }
    
    if ($deadline_query->num_rows === 0) {
        throw new Exception("No active assessment period found.");
    }
    
    $deadline_data = $deadline_query->fetch_assoc();
    $deadline_id = $deadline_data['id'];
    $allow_submissions = (bool)$deadline_data['allow_submissions'];
    
    // Check if submissions are allowed
    if (!$allow_submissions) {
        throw new Exception("Submissions are currently closed for this assessment period.");
    }

    // 3. Process training data from correct field
    $training_json = $_POST['print_training_data'] ?? '';
    
    if (empty($training_json)) {
        throw new Exception("Training data is empty.");
    }
    
    // Try to decode JSON
    $training_data = json_decode($training_json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid training data format: " . json_last_error_msg());
    }
    
    if (!is_array($training_data)) {
        throw new Exception("Training data must be an array.");
    }

    // Get other form data
    $desired_skills = $_POST['desired_skills'] ?? '';
    $comments = $_POST['comments'] ?? '';
    
    // Clean the data
    $desired_skills = trim($desired_skills);
    $comments = trim($comments);

    // ISO 25010 audit (2026-09-06): these had no length limit at all -
    // unlike submit_custom_training_request.php's free-text field, which
    // already caps at 500 chars for exactly this reason. A huge paste
    // here is still valid TEXT-column data (won't error), but renders as
    // an unreadable wall of text for whoever reviews it later
    // (get_assessment_details.php's modal) and needlessly bloats what
    // gets sent to the ML service for matching. Matches assessment_form_partial.php's
    // new maxlength="2000" - generous enough for a real multi-sentence
    // answer, not so open-ended it invites abuse.
    if (mb_strlen($desired_skills) > 2000) {
        throw new Exception('Desired training/skills is too long - please keep it to 2000 characters or fewer.');
    }
    if (mb_strlen($comments) > 2000) {
        throw new Exception('Comments/suggestions is too long - please keep it to 2000 characters or fewer.');
    }

    // 4. Validate training entries
    $has_valid_training = false;
    $cleaned_training_data = [];
    
    foreach ($training_data as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        
        $date = trim($entry['date'] ?? '');
        // 2026-09-03 - real TAM feedback: trainings aren't always
        // one-day. Optional - blank means single-day, same convention
        // as the "Report Training Found" dean modal's date range.
        $end_date = trim($entry['end_date'] ?? '');
        $training = trim($entry['training'] ?? '');
        $start_time = trim($entry['start_time'] ?? '');
        $end_time = trim($entry['end_time'] ?? '');
        $venue = trim($entry['venue'] ?? '');
        // 2026-09-17 addition - real feedback from the first ~20
        // respondents: Venue used to be required unconditionally, so
        // anyone reporting a genuinely online past training had no way
        // to say so and typed workarounds like "Online" into a field
        // labeled Venue. Training Type matches the same vocabulary
        // already used in the training-demand pipeline
        // (create_dean_sourced_training.php/report_training_demand.php).
        $training_type = trim($entry['training_type'] ?? '');
        $training_type_other = trim($entry['training_type_other'] ?? '');
        $modality = trim($entry['modality'] ?? '');
        if (!in_array($modality, ['Face-to-Face', 'Online'], true)) {
            $modality = 'Face-to-Face';
        }

        // Only validate if we have at least a training title
        if (!empty($training)) {
            // Validate date if provided
            if (!empty($date)) {
                if (!DateTime::createFromFormat('Y-m-d', $date)) {
                    throw new Exception("Invalid date format in training entry #" . ($index + 1) . ": " . $date);
                }

                // Validate date is not in future
                $training_date = new DateTime($date);
                $today = new DateTime();
                $today->setTime(0, 0, 0);

                if ($training_date > $today) {
                    throw new Exception("Training date cannot be in the future in entry #" . ($index + 1));
                }
            }

            // Validate end_date if provided - must be a real date, not
            // before the start date, and not in the future.
            if (!empty($end_date)) {
                if (!DateTime::createFromFormat('Y-m-d', $end_date)) {
                    throw new Exception("Invalid end date format in training entry #" . ($index + 1) . ": " . $end_date);
                }

                $training_end_date = new DateTime($end_date);
                $today = new DateTime();
                $today->setTime(0, 0, 0);

                if ($training_end_date > $today) {
                    throw new Exception("Training end date cannot be in the future in entry #" . ($index + 1));
                }

                if (!empty($date)) {
                    $training_date = new DateTime($date);
                    if ($training_end_date < $training_date) {
                        throw new Exception("Training end date cannot be before the start date in entry #" . ($index + 1));
                    }
                }
            }

            // Validate required fields for complete entries. Venue is only
            // required when the training was Face-to-Face - an Online
            // entry has no venue to give. "Specify Training Type" is only
            // required when Training Type is "Other".
            $venueOk = $modality === 'Online' || !empty($venue);
            $typeOk = !empty($training_type) && ($training_type !== 'Other' || !empty($training_type_other));
            if (!empty($date) && (empty($start_time) || empty($end_time) || !$venueOk || !$typeOk)) {
                throw new Exception("Please complete all fields for training entry #" . ($index + 1));
            }

            // Clean and store the entry
            $cleaned_entry = [
                'date' => $date,
                'end_date' => $end_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration' => trim($entry['duration'] ?? ''),
                'training' => $training,
                'venue' => $modality === 'Online' ? '' : $venue,
                'training_type' => $training_type,
                'training_type_other' => $training_type === 'Other' ? $training_type_other : '',
                'modality' => $modality
            ];

            $cleaned_training_data[] = $cleaned_entry;
            $has_valid_training = true;
        }
    }

    // Check if we have any content to save
    if (!$has_valid_training && empty($desired_skills) && empty($comments)) {
        throw new Exception('Please provide at least one complete training entry or desired skills/comments.');
    }

    // 5. Check for existing submission for this deadline
    $check_sql = "SELECT id FROM assessments WHERE user_id = ? AND deadline_id = ? LIMIT 1";
    $check_stmt = $con->prepare($check_sql);
    
    if (!$check_stmt) {
        throw new Exception("Database error: " . $con->error);
    }

    $check_stmt->bind_param("ii", $user_id, $deadline_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        throw new Exception("You have already submitted an assessment for this period.");
    }
    $check_stmt->close();

    // 6. Determine submission status (on-time or late)
    $now = new DateTime();
    $deadline = new DateTime($deadline_data['submission_deadline']);
    $status = ($now <= $deadline) ? 'submitted' : 'late';

    // 7. Insert new assessment
    $training_json_encoded = json_encode($cleaned_training_data, JSON_UNESCAPED_UNICODE);
    
    // Use deadline_id from form if provided, otherwise use active deadline
    $form_deadline_id = isset($_POST['deadline_id']) ? (int)$_POST['deadline_id'] : $deadline_id;
    
    $insert_sql = "INSERT INTO assessments 
                  (user_id, deadline_id, training_history, desired_skills, comments, created_at, submission_date, status) 
                  VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)";
    
    $insert_stmt = $con->prepare($insert_sql);
    
    if (!$insert_stmt) {
        throw new Exception("Database error: " . $con->error);
    }

    $insert_stmt->bind_param("iissss", $user_id, $form_deadline_id, $training_json_encoded, $desired_skills, $comments, $status);

    if (!$insert_stmt->execute()) {
        throw new Exception("Failed to save assessment: " . $insert_stmt->error);
    }

    $assessment_id = $con->insert_id;
    $insert_stmt->close();

    // 8. Create admin notification (optional)
    try {
        $notif_message = "New assessment submitted by user ID: " . $user_id;
        $notif_sql = "INSERT INTO notifications 
                     (user_id, message, related_id, related_type, is_read, created_at) 
                     VALUES (?, ?, ?, 'assessment', 0, NOW())";
        
        $notif_stmt = $con->prepare($notif_sql);
        
        if ($notif_stmt) {
            $admin_user_id = 1; // Default admin ID or get from config
            $notif_stmt->bind_param("isi", $admin_user_id, $notif_message, $assessment_id);
            $notif_stmt->execute();
            $notif_stmt->close();
        }
    } catch (Exception $e) {
        // Don't fail if notification fails
        error_log("Notification error: " . $e->getMessage());
    }

    // 9. Mark all assessment notifications as read for this user
    try {
        $mark_read_sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND related_type = 'deadline'";
        $mark_read_stmt = $con->prepare($mark_read_sql);
        if ($mark_read_stmt) {
            $mark_read_stmt->bind_param("i", $user_id);
            $mark_read_stmt->execute();
            $mark_read_stmt->close();
        }
    } catch (Exception $e) {
        // Don't fail if mark read fails
        error_log("Mark read error: " . $e->getMessage());
    }

    // 10. Update session with submission info
    $_SESSION['last_assessment_id'] = $assessment_id;
    $_SESSION['last_submission_time'] = time();
    $_SESSION['assessment_submitted'] = true;

    // 11. Success response
    $response = [
        'success' => true,
        'message' => 'Assessment submitted successfully!',
        'assessment_id' => $assessment_id,
        'status' => $status,
        'redirect' => 'user_page.php?submitted=1'
    ];

} catch (Exception $e) {
    http_response_code(400);
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ];
    
    // Log error
    error_log("[" . date('Y-m-d H:i:s') . "] Assessment Submission Error: " . $e->getMessage() . 
              " | User ID: " . ($_SESSION['user_id'] ?? 'unknown') . 
              " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

// Don't close connection here - config.php handles it
// Return JSON response
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>