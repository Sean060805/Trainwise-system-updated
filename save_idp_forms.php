<?php
session_start();
require_once 'config.php';

$user_id = $_SESSION['user_id'];

// Set upload directory path
$upload_dir = 'uploads/profile_images/';

// Create uploads directory if not exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get user information from database - INCLUDING PROFILE IMAGE AND DEPARTMENT
// 2026-09-03 - educationalAttainment/specialization/yearsInLSPU/
// teaching_status added so $hasProfile below can be computed without a
// second query - needed for the "Take Assessment" sidebar link, which
// this page was entirely missing (see that block further down).
$user_info = [];
$stmt = $con->prepare("SELECT name, profile_image, designation, department, educationalAttainment, specialization, yearsInLSPU, teaching_status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user_info = $result->fetch_assoc();
}
$stmt->close();

// Get user department
$user_department = $user_info['department'] ?? $_SESSION['user_department'] ?? '';

// 2026-09-03 fix - the sidebar's "Take Assessment" link (shown on
// user_page.php/training_recommendations.php/profile.php/the IDP form
// whenever the profile is complete, there's an open deadline, and
// nothing's submitted yet for it) was simply missing from this page's
// sidebar entirely, along with the computation it depends on. Mirrors
// training_recommendations.php's exact computation.
$hasProfile = !empty(trim($user_info['name'] ?? '')) && !empty(trim($user_info['educationalAttainment'] ?? ''))
    && !empty(trim($user_info['specialization'] ?? '')) && !empty(trim($user_info['designation'] ?? ''))
    && !empty(trim($user_info['department'] ?? '')) && !empty(trim((string)($user_info['yearsInLSPU'] ?? '')))
    && !empty(trim($user_info['teaching_status'] ?? ''));

// 2026-09-03 - percentage version of the same 7-field check above, for
// the Quick Status card's progress bar (matches training_recommendations.php).
$requiredProfileFields = [
    $user_info['name'] ?? '', $user_info['educationalAttainment'] ?? '', $user_info['specialization'] ?? '',
    $user_info['designation'] ?? '', $user_info['department'] ?? '', (string)($user_info['yearsInLSPU'] ?? ''),
    $user_info['teaching_status'] ?? '',
];
$completedProfileFields = count(array_filter($requiredProfileFields, fn($v) => !empty(trim($v))));
$profileCompletionPercentage = round(($completedProfileFields / count($requiredProfileFields)) * 100);

$hasDeadline = false;
$hasSubmitted = false;
$currentDeadlineId = null;
$rawDeadline = null;
$currentDeadlinePassed = false;
try {
    $deadlineQuery = $con->prepare("SELECT id, submission_deadline FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
    if ($deadlineQuery) {
        $deadlineQuery->execute();
        $deadlineResult = $deadlineQuery->get_result();
        if ($deadlineResult && $deadlineRow = $deadlineResult->fetch_assoc()) {
            $currentDeadlineId = $deadlineRow['id'] ?? null;
            $rawDeadline = $deadlineRow['submission_deadline'] ?? null;
            $hasDeadline = !empty($rawDeadline);
            if ($hasDeadline) {
                $currentDeadlinePassed = (new DateTime() > new DateTime($rawDeadline));
            }
        }
        $deadlineQuery->close();
    }

    if ($hasProfile && $hasDeadline && $currentDeadlineId) {
        $submissionStmt = $con->prepare("SELECT id FROM assessments WHERE user_id = ? AND deadline_id = ? LIMIT 1");
        if ($submissionStmt) {
            $submissionStmt->bind_param("ii", $user_id, $currentDeadlineId);
            $submissionStmt->execute();
            $hasSubmitted = $submissionStmt->get_result()->num_rows > 0;
            $submissionStmt->close();
        }
    }
} catch (Exception $e) {
    error_log("save_idp_forms.php: assessment-button status check failed: " . $e->getMessage());
}
$showAssessmentButton = $hasProfile && $hasDeadline && !$hasSubmitted;

// Assign user info for sidebar
$profile_name = $user_info['name'] ?? '';
$profile_image = $user_info['profile_image'] ?? '';
$designation = $user_info['designation'] ?? 'Staff';

// Split name for display
$name_parts = explode(' ', $profile_name, 2);
$first_name = $name_parts[0] ?? '';
$last_name = $name_parts[1] ?? '';

// Get profile image path
$defaultImage = 'images/noprofile.jpg';
$imageSrc = $defaultImage;

if (!empty($profile_image)) {
    $full_path = $upload_dir . $profile_image;
    if (file_exists($full_path)) {
        $imageSrc = $full_path;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Department admin role mapping
$departmentAdminRoles = [
    'CA' => 'admin_ca',
    'CAS' => 'admin_cas',
    'CBAA' => 'admin_cbaa',
    'CCS' => 'admin_ccs',
    'CCJE' => 'admin_ccje',
    'COE' => 'admin_coe',
    'CIT' => 'admin_cit',
    'CFND' => 'admin_cfnd',
    'COF' => 'admin_cof',
    'CIHTM' => 'admin_cihtm',
    'CTE' => 'admin_cte',
    'CONAH' => 'admin_conah',
    'COL' => 'admin_col'
];

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_id'])) {
        // Redirect to edit form
        $form_id = intval($_POST['edit_id']);
        header("Location: Individual_Development_Plan.php?id=" . $form_id);
        exit();
    } elseif (isset($_POST['save_id'])) {
        // Save form updates - only if form is still a draft
        $form_id = intval($_POST['save_id']);
        
        // Check if form is still a draft
        $stmt = $con->prepare("SELECT status FROM idp_forms WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $form_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $form = $result->fetch_assoc();
        $stmt->close();
        
        if ($form['status'] !== 'draft') {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Cannot edit a submitted form.'
            ];
            header("Location: save_idp_forms.php");
            exit();
        }
        
        // Collect all form data
        $form_input = [
            'personal_info' => [
                'name' => $_POST['name'] ?? '',
                'position' => $_POST['position'] ?? '',
                'salary_grade' => $_POST['salary_grade'] ?? '',
                'years_position' => $_POST['years_position'] ?? '',
                'years_lspu' => $_POST['years_lspu'] ?? '',
                'years_other' => $_POST['years_other'] ?? '',
                'division' => $_POST['division'] ?? '',
                'office' => $_POST['office'] ?? '',
                'address' => $_POST['address'] ?? '',
                'supervisor' => $_POST['supervisor'] ?? ''
            ],
            'purpose' => [
                'purpose1' => isset($_POST['purpose1']) ? 1 : 0,
                'purpose2' => isset($_POST['purpose2']) ? 1 : 0,
                'purpose3' => isset($_POST['purpose3']) ? 1 : 0,
                'purpose4' => isset($_POST['purpose4']) ? 1 : 0,
                'purpose5' => isset($_POST['purpose5']) ? 1 : 0,
                'purpose_other' => $_POST['purpose_other'] ?? ''
            ],
            'long_term_goals' => [],
            'short_term_goals' => [],
            'certification' => [
                'employee_name' => $_POST['employee_name'] ?? '',
                'employee_date' => $_POST['employee_date'] ?? '',
                'employee_signature' => $_POST['employee_signature'] ?? '',
                'supervisor_name' => $_POST['supervisor_name'] ?? '',
                'supervisor_date' => $_POST['supervisor_date'] ?? '',
                'director_name' => $_POST['director_name'] ?? '',
                'director_date' => $_POST['director_date'] ?? ''
            ]
        ];

        // Process long term goals
        if (isset($_POST['long_term_area']) && is_array($_POST['long_term_area'])) {
            foreach ($_POST['long_term_area'] as $index => $area) {
                if (!empty($area) || !empty($_POST['long_term_activity'][$index])) {
                    $form_input['long_term_goals'][] = [
                        'area' => $area,
                        'activity' => $_POST['long_term_activity'][$index] ?? '',
                        'target_date' => $_POST['long_term_date'][$index] ?? '',
                        'stage' => $_POST['long_term_stage'][$index] ?? ''
                    ];
                }
            }
        }

        // Process short term goals
        if (isset($_POST['short_term_area']) && is_array($_POST['short_term_area'])) {
            foreach ($_POST['short_term_area'] as $index => $area) {
                if (!empty($area) || !empty($_POST['short_term_priority'][$index])) {
                    $form_input['short_term_goals'][] = [
                        'area' => $area,
                        'priority' => $_POST['short_term_priority'][$index] ?? '',
                        'activity' => $_POST['short_term_activity'][$index] ?? '',
                        'target_date' => $_POST['short_term_date'][$index] ?? '',
                        'responsible' => $_POST['short_term_responsible'][$index] ?? '',
                        'stage' => $_POST['short_term_stage'][$index] ?? ''
                    ];
                }
            }
        }

        $json_data = json_encode($form_input);
        
        $stmt = $con->prepare("UPDATE idp_forms SET form_data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sii", $json_data, $form_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'IDP form saved successfully!'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Error saving form: ' . $con->error
            ];
        }
        $stmt->close();
        header("Location: save_idp_forms.php");
        exit();
    } elseif (isset($_POST['submit_id'])) {
        // Submit to Department Admin (not System Admin)
        $form_id = intval($_POST['submit_id']);
        
        // First get the form data to include in notification
        $stmt = $con->prepare("SELECT form_data FROM idp_forms WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $form_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $form = $result->fetch_assoc();
        $stmt->close();
        
        $form_data = json_decode($form['form_data'], true);
        $employee_name = $form_data['personal_info']['name'] ?? 'an employee';
        
        // Update status to submitted
        $stmt = $con->prepare("UPDATE idp_forms SET status = 'submitted', submitted_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $form_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'IDP form submitted to HR successfully!'
            ];
            
            // ================================================================
            // UPDATED: Send notification to Department Admin, NOT System Admin
            // ================================================================
            
            // Get the corresponding department admin role
            $dept_admin_role = $departmentAdminRoles[$user_department] ?? '';
            
            if (!empty($dept_admin_role)) {
                // Create notification for Department Admin only
                $message = "New IDP form submitted by " . $employee_name . " from " . $user_department . " department";
                
                $stmt2 = $con->prepare("INSERT INTO notifications (user_id, message, related_id, type) 
                                      SELECT id, ?, ?, 'idp_form' FROM users WHERE role = ?");
                $stmt2->bind_param("sis", $message, $form_id, $dept_admin_role);
                $stmt2->execute();
                $stmt2->close();
            } else {
                // Fallback: if no department admin found, notify system admin
                $message = "New IDP form submitted by " . $employee_name;
                $stmt2 = $con->prepare("INSERT INTO notifications (user_id, message, related_id, type) 
                                      SELECT id, ?, ?, 'idp_form' FROM users WHERE role = 'admin'");
                $stmt2->bind_param("si", $message, $form_id);
                $stmt2->execute();
                $stmt2->close();
            }
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Error submitting form: ' . $con->error
            ];
        }
        $stmt->close();
        header("Location: save_idp_forms.php");
        exit();
    }
}

// Get all IDP forms for this user
$stmt = $con->prepare("SELECT id, form_data, status, created_at, submitted_at, updated_at FROM idp_forms WHERE user_id = ? ORDER BY status ASC, created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$forms = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count drafts and submitted forms
$draft_count = 0;
$submitted_count = 0;
$submitted_forms = [];
foreach ($forms as $form) {
    if ($form['status'] === 'draft') {
        $draft_count++;
    } else {
        $submitted_count++;
        $submitted_forms[] = $form;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My IDP Forms</title>
    <link rel="stylesheet" href="assets/css/tw-0.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --royal: #1A4B8C;
            --royal-2: #0F3460;
            --forest: #0D6B4D;
            --forest-2: #084A34;
            --gold: #D4A843;
            --gold-light: #E8C96E;
            --gold-soft: #F5E6C8;
            --cream: #FDF8F0;
            --cream-dim: #F5EDDF;
            --ink: #16233A;
            --ink-soft: #52627B;
        }

        * {
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #FDF8F0 0%, #F5EDDF 100%);
            min-height: 100vh;
        }

        /* Fixed Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            /* 2026-09-03 fix - see the matching comment in user_page.php's
               .sidebar-fixed: 100vh overshoots the real visible area on
               mobile once the browser's own chrome is accounted for.
               This file uses a differently-named class (.sidebar, not
               .sidebar-fixed), so it was missed by that earlier pass -
               same bug, same fix. */
            height: 100dvh;
            width: 16rem;
            background:
                radial-gradient(circle at 85% 0%, rgba(212,168,67,0.22), transparent 55%),
                linear-gradient(180deg, #1A4B8C 0%, #0F3460 100%);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            z-index: 50;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .main-content {
            margin-left: 16rem;
            min-height: 100vh;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 60;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            background: #1A4B8C;
            color: #fff;
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 14px rgba(0,0,0,0.25);
            font-size: 1.25rem;
        }

        .mobile-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 24, 48, 0.5);
            z-index: 49;
        }

        @media (max-width: 860px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.28s ease;
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding-top: 56px;
            }
            .mobile-menu-btn {
                display: flex;
            }
            .mobile-backdrop.active {
                display: block;
            }
        }

        .eyebrow {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.65rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .eyebrow::before {
            content: '';
            width: 14px;
            height: 1px;
            display: inline-block;
        }

        .nav-item {
            transition: all 0.3s ease;
            border-radius: 0.75rem;
            margin: 0.25rem 0;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            position: relative;
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: #D4A843;
            border-radius: 0 2px 2px 0;
        }

        .form-card {
            transition: all 0.3s ease;
            background: white;
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid #E8DDD0;
        }

        .form-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0, 1, 0, 1);
        }

        .accordion-content.active {
            max-height: 5000px;
            transition: max-height 1s ease-in-out;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }

        .tab-content.active {
            display: block;
        }

        .tab-button {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            cursor: pointer;
            background-color: #e2e8f0;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .tab-button.active {
            background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(26, 75, 140, 0.4);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-draft {
            background-color: #F5E6C8;
            color: #B8922A;
        }

        .badge-submitted {
            background-color: #D1FAE5;
            color: #065F46;
        }

        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal-backdrop.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            border-radius: 1rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            border: 1px solid #E8DDD0;
        }

        .readonly-field {
            background-color: #FDF8F0;
            border: 1px solid #e5e7eb;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            color: #374151;
        }

        .checkbox-custom:disabled {
            background-color: #e5e7eb;
            border-color: #d1d5db;
        }

        .signature-preview {
            max-width: 200px;
            max-height: 80px;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem;
            background: white;
        }

        .signature-preview img {
            max-width: 100%;
            max-height: 60px;
            display: block;
            margin: 0 auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus {
            border-color: #1A4B8C;
            box-shadow: 0 0 0 3px rgba(26, 75, 140, 0.1);
            outline: none;
        }

        .table-input {
            width: 100%;
            border: none;
            padding: 0.5rem;
            font-size: 0.875rem;
            background: transparent;
        }

        .table-input:focus {
            outline: none;
            background-color: #FDF8F0;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 75, 140, 0.5);
        }

        .btn-success {
            background: linear-gradient(135deg, #0D6B4D 0%, #084A34 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 107, 77, 0.5);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-gold {
            background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 168, 67, 0.5);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #E8DDD0;
        }

        .empty-state i {
            font-size: 3rem;
            color: #9ca3af;
            margin-bottom: 1rem;
        }

        /* Table styles */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
        }

        .development-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .development-table th {
            background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
            color: white;
            padding: 1rem;
            font-weight: 600;
            text-align: left;
            vertical-align: top;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }

        .development-table td {
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            background: white;
            vertical-align: top;
            word-wrap: break-word;
        }

        .development-table tr:hover td {
            background-color: #FDF8F0;
        }

        .development-table .editable-cell {
            min-height: 60px;
            padding: 0.5rem;
            cursor: text;
            outline: none;
            transition: all 0.3s ease;
            width: 100%;
            display: block;
        }

        .development-table .editable-cell:focus {
            background-color: #FDF8F0;
            border-radius: 0.25rem;
        }

        .development-table .editable-cell.placeholder {
            color: #9ca3af;
            font-style: italic;
        }

        .development-table .editable-cell.default-text {
            color: #6b7280;
            font-style: italic;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #E8DDD0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            .print-only {
                display: block !important;
            }
            
            .sidebar {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            body {
                background: white !important;
            }
        }

        /* Column Widths for Development Tables */
        .area-col {
            width: 25%;
        }

        .priority-col {
            width: 15%;
        }

        .activity-col {
            width: 25%;
        }

        .date-col {
            width: 12%;
        }

        .responsible-col {
            width: 12%;
        }

        .stage-col {
            width: 11%;
        }

        .long-term-area-col {
            width: 25%;
        }

        .long-term-activity-col {
            width: 35%;
        }

        .long-term-date-col {
            width: 15%;
        }

        .long-term-stage-col {
            width: 25%;
        }

        .checkbox-custom {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid #d1d5db;
            border-radius: 0.375rem;
            appearance: none;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            flex-shrink: 0;
        }

        .checkbox-custom:checked {
            background-color: #1A4B8C;
            border-color: #1A4B8C;
        }

        .checkbox-custom:checked::after {
            content: '✓';
            position: absolute;
            color: white;
            font-size: 0.875rem;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .checkbox-custom:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .checkbox-custom:disabled:checked {
            background-color: #0D6B4D;
            border-color: #0D6B4D;
        }
    </style>
</head>
<body class="min-h-screen">
    <button id="mobileMenuBtn" class="mobile-menu-btn" aria-label="Open menu"><i class="ri-menu-line"></i></button>
    <div id="mobileBackdrop" class="mobile-backdrop"></div>
    <!-- Fixed Sidebar -->
    <div id="sidebarFixed" class="sidebar text-white">
        <div class="h-full flex flex-col">
            <!-- Logo & Title -->
            <div class="p-6 flex items-center border-b border-white/10">
                <div class="relative mr-3">
                    <div class="w-14 h-14 rounded-full bg-white/95 flex items-center justify-center p-1.5 shadow-lg" style="box-shadow:0 0 0 3px rgba(212,168,67,0.5);">
                        <img src="images/lspu-logo.png" alt="LSPU Logo" class="w-full h-full object-contain" onerror="this.style.display='none'" />
                    </div>
                </div>
                <div>
                    <a href="user_page.php" class="text-lg font-semibold text-white tracking-tight leading-tight block" style="font-family:'Fraunces',serif;">Training Tracker</a>
                    <p class="eyebrow" style="color:#E8C96E;"><span style="width:10px;"></span>TNA System</p>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="user_page.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
                    <div class="w-6 h-6 flex items-center justify-center mr-3">
                        <i class="ri-dashboard-line text-lg"></i>
                    </div>
                    Dashboard
                    <i class="ri-arrow-right-s-line ml-auto"></i>
                </a>
                
                <!-- IDP Forms Dropdown (Always Open) -->
                <div class="group open" id="idp-dropdown">
                    <button id="idp-dropdown-btn" class="nav-item active flex items-center justify-between w-full px-4 py-3 text-sm font-medium rounded-lg bg-blue-700/50 transition-all">
                        <div class="flex items-center">
                            <div class="w-6 h-6 flex items-center justify-center mr-3">
                                <i class="ri-file-text-line text-lg"></i>
                            </div>
                            IDP Forms
                        </div>
                        <i class="ri-arrow-down-s-line transition-transform duration-300 rotate-180"></i>
                    </button>
                    
                    <div id="idp-dropdown-menu" class="pl-10 mt-1 space-y-1 block">
                        <a href="Individual_Development_Plan.php" class="nav-item flex items-center px-4 py-2.5 text-sm rounded-lg hover:bg-blue-700/30 transition-all">
                            <div class="w-5 h-5 flex items-center justify-center mr-3">
                                <i class="ri-file-add-line"></i>
                            </div>
                            Create New
                        </a>
                        <a href="save_idp_forms.php" class="nav-item active flex items-center px-4 py-2.5 text-sm rounded-lg bg-blue-700/30 transition-all">
                            <div class="w-5 h-5 flex items-center justify-center mr-3">
                                <i class="ri-file-list-line"></i>
                            </div>
                            My Submitted Forms
                        </a>
                    </div>
                </div>
                
                <a href="training_recommendations.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
                    <div class="w-6 h-6 flex items-center justify-center mr-3">
                        <i class="ri-lightbulb-flash-line text-lg"></i>
                    </div>
                    Training Recommendations
                </a>

                <!-- Assessment Link - mirrors training_recommendations.php: this
                     page has no assessment modal of its own, so it links to the
                     one on user_page.php. -->
                <?php if ($showAssessmentButton): ?>
                <a href="user_page.php#assessmentFormWrapper" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg mt-4" style="background:linear-gradient(135deg, rgba(212,168,67,0.35), rgba(212,168,67,0.15)); border:1px solid rgba(212,168,67,0.5);">
                    <div class="w-6 h-6 flex items-center justify-center mr-3">
                        <i class="ri-file-edit-line text-lg"></i>
                    </div>
                    Take Assessment
                    <span class="ml-auto animate-pulse">
                        <i class="ri-arrow-right-up-line"></i>
                    </span>
                </a>
                <?php endif; ?>

                <a href="profile.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
                    <div class="w-6 h-6 flex items-center justify-center mr-3">
                        <i class="ri-user-line text-lg"></i>
                    </div>
                    Profile
                </a>
            </nav>

            <!-- Quick Status - 2026-09-03 fix: was only on user_page.php/
                 training_recommendations.php, missing here (and on
                 profile.php/Individual_Development_Plan.php), which read
                 as inconsistent. Ported from training_recommendations.php's
                 version. -->
            <div class="p-4 border-t border-white/10">
                <div class="bg-white/5 backdrop-blur-sm rounded-xl p-4 border border-white/10 mb-4">
                    <h3 class="eyebrow mb-3" style="color:var(--gold-light);">Quick Status</h3>

                    <div class="space-y-3">
                        <!-- Profile Completion -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center mr-3">
                                    <i class="ri-user-line text-sm" style="color:var(--gold-light);"></i>
                                </div>
                                <span class="text-sm text-white/80">Profile</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-sm font-semibold text-white mr-2"><?= $profileCompletionPercentage ?>%</span>
                                <div class="w-16 bg-white/10 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full" style="width: <?= $profileCompletionPercentage ?>%; background:linear-gradient(90deg,#D4A843,#B8922A);"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Assessment Status -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full <?= $hasSubmitted ? 'bg-emerald-500/20' : ($hasDeadline ? 'bg-amber-500/20' : 'bg-white/10') ?> flex items-center justify-center mr-3">
                                    <i class="ri-file-text-line <?= $hasSubmitted ? 'text-emerald-300' : ($hasDeadline ? 'text-amber-300' : 'text-white/50') ?> text-sm"></i>
                                </div>
                                <span class="text-sm text-white/80">TNA Status</span>
                            </div>
                            <span class="text-xs font-medium px-2 py-1 rounded-full <?= $hasSubmitted ? 'bg-emerald-500/20 text-emerald-300' : ($hasDeadline ? 'bg-amber-500/20 text-amber-300' : 'bg-white/10 text-white/50') ?>">
                                <?= $hasSubmitted ? 'Submitted' : ($hasDeadline ? 'Pending' : 'None') ?>
                            </span>
                        </div>

                        <!-- Deadline -->
                        <?php if ($hasDeadline): ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 rounded-full <?= $currentDeadlinePassed ? 'bg-red-500/20' : 'bg-white/10' ?> flex items-center justify-center mr-3">
                                        <i class="ri-time-line <?= $currentDeadlinePassed ? 'text-red-300' : 'text-white/70' ?> text-sm"></i>
                                    </div>
                                    <span class="text-sm text-white/80">Deadline</span>
                                </div>
                                <span class="text-xs font-medium <?= $currentDeadlinePassed ? 'text-red-300' : 'text-white/70' ?>">
                                    <?= date('M j', strtotime($rawDeadline)) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Info & Logout -->
            <div class="p-4 border-t border-blue-800/30 mt-auto">
                <div class="flex items-center justify-between">
                    <!-- User Info -->
                    <div class="flex items-center">
                        <img class="w-10 h-10 rounded-full border-2 border-blue-500/30 mr-3 object-cover" 
                             src="<?= htmlspecialchars($imageSrc); ?>"
                             alt="Profile Picture"
                             onerror="this.onerror=null;this.src='<?= htmlspecialchars($defaultImage); ?>';">
                        <div>
                            <p class="text-sm font-medium text-white truncate max-w-[120px]">
                                <?= htmlspecialchars($first_name . ' ' . $last_name) ?>
                            </p>
                            <p class="text-xs text-blue-300 truncate max-w-[120px]" style="color:#E8C96E;">
                                <?= htmlspecialchars($designation ?: 'Staff') ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Logout Button -->
                    <a href="?logout=1" class="p-2 rounded-lg hover:bg-red-600/20 text-red-100 border border-red-500/20 transition-all flex items-center justify-center">
                        <i class="ri-logout-box-line"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container mx-auto px-8 py-8">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="mb-6 p-4 rounded-xl animate-fade-in no-print <?php echo $_SESSION['message']['type'] === 'success' ? 'bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800' : 'bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 text-red-800'; ?>">
                    <div class="flex items-center">
                        <i class="ri-<?php echo $_SESSION['message']['type'] === 'success' ? 'checkbox-circle-fill' : 'error-warning-fill'; ?> mr-3 text-xl"></i>
                        <div>
                            <p class="font-medium"><?php echo $_SESSION['message']['text']; ?></p>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
                <div class="flex items-center">
                    <div class="bg-gradient-to-r from-gold-soft to-cream rounded-xl p-3 mr-4">
                        <i class="ri-file-list-3-line text-royal text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">My IDP Forms</h1>
                        <p class="text-gray-600 mt-1">View and manage your Individual Development Plans</p>
                    </div>
                </div>
                
                <a href="Individual_Development_Plan.php" class="btn-primary flex items-center">
                    <i class="ri-file-add-line mr-2"></i> Create New IDP
                </a>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Draft Forms</h3>
                            <p class="text-3xl font-bold text-royal mt-2"><?php echo $draft_count; ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-gold-soft flex items-center justify-center">
                            <i class="ri-draft-line text-royal text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mt-3">Forms that are still in progress</p>
                </div>
                
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Submitted Forms</h3>
                            <p class="text-3xl font-bold text-forest mt-2"><?php echo $submitted_count; ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="ri-send-plane-line text-forest text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mt-3">Forms submitted to HR</p>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="mb-6 flex flex-wrap gap-2">
                <button class="tab-button active" data-tab="drafts">
                    <i class="ri-draft-line mr-2"></i>
                    Drafts (<?php echo $draft_count; ?>)
                </button>
                <button class="tab-button" data-tab="submitted">
                    <i class="ri-send-plane-line mr-2"></i>
                    Submitted (<?php echo $submitted_count; ?>)
                </button>
            </div>
            
            <!-- Drafts Tab -->
            <div class="tab-content active" id="drafts-tab">
                <?php if ($draft_count === 0): ?>
                    <div class="empty-state">
                        <i class="ri-file-text-line"></i>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Draft Forms</h3>
                        <p class="text-gray-600 mb-4">You don't have any draft IDP forms yet.</p>
                        <a href="Individual_Development_Plan.php" class="btn-primary inline-flex items-center">
                            <i class="ri-file-add-line mr-2"></i> Create Your First IDP
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php 
                        $draft_index = 0;
                        foreach ($forms as $form): 
                            if ($form['status'] !== 'draft') continue;
                            $draft_index++;
                            $form_data = json_decode($form['form_data'], true);
                            $created_date = date('M d, Y', strtotime($form['created_at']));
                            $updated_date = date('M d, Y', strtotime($form['updated_at']));
                        ?>
                            <div class="form-card">
                                <button class="accordion-toggle w-full text-left p-6 focus:outline-none hover:bg-cream transition-all" data-id="<?php echo $form['id']; ?>">
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center space-x-4">
                                            <div class="w-12 h-12 rounded-xl bg-gradient-to-r from-royal to-royal-2 flex items-center justify-center">
                                                <i class="ri-file-text-line text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-lg text-gray-800">IDP Form Draft #<?php echo $draft_index; ?></h3>
                                                <p class="text-gray-600 text-sm mt-1">
                                                    Created: <?php echo $created_date; ?> • 
                                                    Last updated: <?php echo $updated_date; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <span class="badge badge-draft">
                                                <i class="ri-draft-line mr-1"></i> Draft
                                            </span>
                                            <i class="ri-arrow-down-s-line transition-transform duration-300 text-gray-500 text-xl"></i>
                                        </div>
                                    </div>
                                </button>
                                
                                <div class="accordion-content px-6" id="content-<?php echo $form['id']; ?>">
                                    <div class="border-t border-gray-200 pt-6 pb-8">
                                        <form method="POST" action="save_idp_forms.php" id="form-<?php echo $form['id']; ?>">
                                            <input type="hidden" name="save_id" value="<?php echo $form['id']; ?>">
                                            
                                            <div class="mb-8">
                                                <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                    <i class="ri-user-settings-line mr-3 text-royal"></i>
                                                    Personal Information
                                                </h4>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                    <div class="space-y-4">
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Name</label>
                                                            <input type="text" name="name" value="<?php echo htmlspecialchars($form_data['personal_info']['name']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Position</label>
                                                            <input type="text" name="position" value="<?php echo htmlspecialchars($form_data['personal_info']['position']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Salary Grade</label>
                                                            <input type="text" name="salary_grade" value="<?php echo htmlspecialchars($form_data['personal_info']['salary_grade']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Years in Position</label>
                                                            <input type="text" name="years_position" value="<?php echo htmlspecialchars($form_data['personal_info']['years_position']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Years in LSPU</label>
                                                            <input type="text" name="years_lspu" value="<?php echo htmlspecialchars($form_data['personal_info']['years_lspu']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="space-y-4">
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Years in Other Office/Agency</label>
                                                            <input type="text" name="years_other" value="<?php echo htmlspecialchars($form_data['personal_info']['years_other']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Division</label>
                                                            <input type="text" name="division" value="<?php echo htmlspecialchars($form_data['personal_info']['division']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Office</label>
                                                            <input type="text" name="office" value="<?php echo htmlspecialchars($form_data['personal_info']['office']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Office Address</label>
                                                            <textarea name="address" class="form-input" rows="3"><?php echo htmlspecialchars($form_data['personal_info']['address']); ?></textarea>
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Supervisor's Name</label>
                                                            <input type="text" name="supervisor" value="<?php echo htmlspecialchars($form_data['personal_info']['supervisor']); ?>" 
                                                                   class="form-input">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-8">
                                                <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                    <i class="ri-target-line mr-3 text-royal"></i>
                                                    Purpose of Development Plan
                                                </h4>
                                                <div class="bg-gradient-to-r from-cream to-gold-soft p-6 rounded-xl border border-gold-soft">
                                                    <div class="space-y-4">
                                                        <div class="flex items-start">
                                                            <input type="checkbox" id="purpose1-<?php echo $form['id']; ?>" name="purpose1" class="checkbox-custom mt-1 mr-3" 
                                                                   <?php echo ($form_data['purpose']['purpose1'] ?? 0) ? 'checked' : ''; ?>>
                                                            <label for="purpose1-<?php echo $form['id']; ?>" class="flex-1">
                                                                <span class="text-gray-800 font-medium">To meet the competencies in the current positions</span>
                                                            </label>
                                                        </div>
                                                        <div class="flex items-start">
                                                            <input type="checkbox" id="purpose2-<?php echo $form['id']; ?>" name="purpose2" class="checkbox-custom mt-1 mr-3"
                                                                   <?php echo ($form_data['purpose']['purpose2'] ?? 0) ? 'checked' : ''; ?>>
                                                            <label for="purpose2-<?php echo $form['id']; ?>" class="flex-1">
                                                                <span class="text-gray-800 font-medium">To increase the level of competencies of current positions</span>
                                                            </label>
                                                        </div>
                                                        <div class="flex items-start">
                                                            <input type="checkbox" id="purpose3-<?php echo $form['id']; ?>" name="purpose3" class="checkbox-custom mt-1 mr-3"
                                                                   <?php echo ($form_data['purpose']['purpose3'] ?? 0) ? 'checked' : ''; ?>>
                                                            <label for="purpose3-<?php echo $form['id']; ?>" class="flex-1">
                                                                <span class="text-gray-800 font-medium">To meet the competencies in the next higher position</span>
                                                            </label>
                                                        </div>
                                                        <div class="flex items-start">
                                                            <input type="checkbox" id="purpose4-<?php echo $form['id']; ?>" name="purpose4" class="checkbox-custom mt-1 mr-3"
                                                                   <?php echo ($form_data['purpose']['purpose4'] ?? 0) ? 'checked' : ''; ?>>
                                                            <label for="purpose4-<?php echo $form['id']; ?>" class="flex-1">
                                                                <span class="text-gray-800 font-medium">To acquire new competencies across different functions/position</span>
                                                            </label>
                                                        </div>
                                                        <div class="flex items-start">
                                                            <input type="checkbox" id="purpose5-<?php echo $form['id']; ?>" name="purpose5" class="checkbox-custom mt-1 mr-3"
                                                                   <?php echo ($form_data['purpose']['purpose5'] ?? 0) ? 'checked' : ''; ?>>
                                                            <div class="flex-1">
                                                                <label for="purpose5-<?php echo $form['id']; ?>" class="text-gray-800 font-medium block mb-2">Others, please specify:</label>
                                                                <input type="text" name="purpose_other" value="<?php echo htmlspecialchars($form_data['purpose']['purpose_other'] ?? ''); ?>" 
                                                                       class="form-input w-full">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Long Term Goals -->
                                            <div class="mb-8">
                                                <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                    <i class="ri-calendar-2-line mr-3 text-gold"></i>
                                                    Training/Development Interventions for Long Term Goals (Next Five Years)
                                                </h4>
                                                <div class="table-wrapper">
                                                    <table class="development-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="long-term-area-col">Area of Development</th>
                                                                <th class="long-term-activity-col">Development Activity</th>
                                                                <th class="long-term-date-col">Target Completion Date</th>
                                                                <th class="long-term-stage-col">Completion Stage</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="long-term-goals-<?php echo $form['id']; ?>">
                                                            <?php if (!empty($form_data['long_term_goals'])): ?>
                                                                <?php foreach ($form_data['long_term_goals'] as $index => $goal): ?>
                                                                <tr>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_area[]" 
                                                                             class="editable-cell min-h-[60px] <?php echo (empty($goal['area']) || $goal['area'] === 'Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development') ? 'default-text' : ''; ?>">
                                                                            <?php echo !empty($goal['area']) ? htmlspecialchars($goal['area']) : 'Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development'; ?>
                                                                        </div>
                                                                        <input type="hidden" name="long_term_area[]" value="<?php echo htmlspecialchars($goal['area']); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_activity[]" 
                                                                             class="editable-cell min-h-[60px] <?php echo (empty($goal['activity']) || $goal['activity'] === 'Pursuance of Academic Degrees for advancement, conduct of trainings/seminars') ? 'default-text' : ''; ?>">
                                                                            <?php echo !empty($goal['activity']) ? htmlspecialchars($goal['activity']) : 'Pursuance of Academic Degrees for advancement, conduct of trainings/seminars'; ?>
                                                                        </div>
                                                                        <input type="hidden" name="long_term_activity[]" value="<?php echo htmlspecialchars($goal['activity']); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <input type="date" name="long_term_date[]" value="<?php echo htmlspecialchars($goal['target_date']); ?>" 
                                                                               class="table-input">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_stage[]" 
                                                                             class="editable-cell min-h-[60px] <?php echo empty($goal['stage']) ? 'placeholder' : ''; ?>"
                                                                             data-placeholder="Enter completion stage...">
                                                                            <?php echo !empty($goal['stage']) ? htmlspecialchars($goal['stage']) : ''; ?>
                                                                        </div>
                                                                        <input type="hidden" name="long_term_stage[]" value="<?php echo htmlspecialchars($goal['stage']); ?>">
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <tr>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_area[]" 
                                                                             class="editable-cell min-h-[60px] default-text">
                                                                            Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development
                                                                        </div>
                                                                        <input type="hidden" name="long_term_area[]" value="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_activity[]" 
                                                                             class="editable-cell min-h-[60px] default-text">
                                                                            Pursuance of Academic Degrees for advancement, conduct of trainings/seminars
                                                                        </div>
                                                                        <input type="hidden" name="long_term_activity[]" value="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
                                                                    </td>
                                                                    <td>
                                                                        <input type="date" name="long_term_date[]" class="table-input">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="long_term_stage[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter completion stage..."></div>
                                                                        <input type="hidden" name="long_term_stage[]" value="">
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <button type="button" onclick="addLongTermGoal(<?php echo $form['id']; ?>)" class="mt-3 text-royal hover:text-royal-2 font-medium flex items-center text-sm">
                                                    <i class="ri-add-circle-line mr-2"></i> Add Another Long Term Goal
                                                </button>
                                            </div>
                                            
                                            <!-- Short Term Goals -->
                                            <div class="mb-8">
                                                <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                    <i class="ri-calendar-line mr-3 text-forest"></i>
                                                    Short Term Development Goals (Next Year)
                                                </h4>
                                                <div class="table-wrapper">
                                                    <table class="development-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="area-col">Area of Development</th>
                                                                <th class="priority-col">Priority for LDP</th>
                                                                <th class="activity-col">Development Activity</th>
                                                                <th class="date-col">Target Completion Date</th>
                                                                <th class="responsible-col">Who is Responsible</th>
                                                                <th class="stage-col">Completion Stage</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="short-term-goals-<?php echo $form['id']; ?>">
                                                            <?php if (!empty($form_data['short_term_goals'])): ?>
                                                                <?php 
                                                                $default_short_term_areas = [
                                                                    "1. Behavioral Training such as: Value Re-orientation, Team Building, Oral Communication, Written Communication, Customer Relations, People Development, Improving Planning & Delivery, Solving Problems and making decisions, Basic Communication Training Program, etc",
                                                                    "2. Technical Skills Training such as: Basic Occupational Safety & Health, University Safety procedures, Preventive Maintenance Activities, etc.",
                                                                    "3. Quality Management Training such as: Customer Requirements, Time Management, Continuous Improvement for Quality & Productivity, etc",
                                                                    "4. Others: Formal Classroom Training, on-the-job training, Self-development, developmental activities/interventions, etc."
                                                                ];
                                                                
                                                                foreach ($form_data['short_term_goals'] as $index => $goal): 
                                                                    $defaultActivity = ($index === 0) ? 'Conduct of training/seminar' : (($index === 3) ? 'Coaching on the Job-knowledge sharing and learning session' : '');
                                                                    $area = !empty($goal['area']) ? $goal['area'] : ($default_short_term_areas[$index] ?? '');
                                                                    $activity = !empty($goal['activity']) ? $goal['activity'] : $defaultActivity;
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_area[]" 
                                                                             class="editable-cell min-h-[60px]">
                                                                            <?php echo htmlspecialchars($area); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_area[]" value="<?php echo htmlspecialchars($area); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_priority[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter priority...">
                                                                            <?php echo htmlspecialchars($goal['priority']); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_priority[]" value="<?php echo htmlspecialchars($goal['priority']); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_activity[]" 
                                                                             class="editable-cell min-h-[60px] <?php echo (empty($goal['activity']) && !empty($defaultActivity)) ? 'default-text' : ''; ?>"
                                                                             data-default="<?php echo $defaultActivity; ?>">
                                                                            <?php echo htmlspecialchars($activity); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_activity[]" value="<?php echo htmlspecialchars($activity); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <input type="date" name="short_term_date[]" value="<?php echo htmlspecialchars($goal['target_date']); ?>" 
                                                                               class="table-input">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_responsible[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter responsible person...">
                                                                            <?php echo htmlspecialchars($goal['responsible']); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_responsible[]" value="<?php echo htmlspecialchars($goal['responsible']); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_stage[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter completion stage...">
                                                                            <?php echo htmlspecialchars($goal['stage']); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_stage[]" value="<?php echo htmlspecialchars($goal['stage']); ?>">
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <?php 
                                                                $default_short_term_areas = [
                                                                    "1. Behavioral Training such as: Value Re-orientation, Team Building, Oral Communication, Written Communication, Customer Relations, People Development, Improving Planning & Delivery, Solving Problems and making decisions, Basic Communication Training Program, etc",
                                                                    "2. Technical Skills Training such as: Basic Occupational Safety & Health, University Safety procedures, Preventive Maintenance Activities, etc.",
                                                                    "3. Quality Management Training such as: Customer Requirements, Time Management, Continuous Improvement for Quality & Productivity, etc",
                                                                    "4. Others: Formal Classroom Training, on-the-job training, Self-development, developmental activities/interventions, etc."
                                                                ];
                                                                
                                                                foreach ($default_short_term_areas as $index => $area): 
                                                                    $defaultActivity = ($index === 0) ? 'Conduct of training/seminar' : (($index === 3) ? 'Coaching on the Job-knowledge sharing and learning session' : '');
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_area[]" 
                                                                             class="editable-cell min-h-[60px]">
                                                                            <?php echo htmlspecialchars($area); ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_area[]" value="<?php echo htmlspecialchars($area); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_priority[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter priority..."></div>
                                                                        <input type="hidden" name="short_term_priority[]" value="">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_activity[]" 
                                                                             class="editable-cell min-h-[60px] <?php echo !empty($defaultActivity) ? 'default-text' : ''; ?>"
                                                                             data-default="<?php echo $defaultActivity; ?>">
                                                                            <?php echo $defaultActivity; ?>
                                                                        </div>
                                                                        <input type="hidden" name="short_term_activity[]" value="<?php echo $defaultActivity; ?>">
                                                                    </td>
                                                                    <td>
                                                                        <input type="date" name="short_term_date[]" class="table-input">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_responsible[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter responsible person..."></div>
                                                                        <input type="hidden" name="short_term_responsible[]" value="">
                                                                    </td>
                                                                    <td>
                                                                        <div contenteditable="true" data-name="short_term_stage[]" 
                                                                             class="editable-cell min-h-[60px] placeholder"
                                                                             data-placeholder="Enter completion stage..."></div>
                                                                        <input type="hidden" name="short_term_stage[]" value="">
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <button type="button" onclick="addShortTermGoal(<?php echo $form['id']; ?>)" class="mt-3 text-royal hover:text-royal-2 font-medium flex items-center text-sm">
                                                    <i class="ri-add-circle-line mr-2"></i> Add Another Short Term Goal
                                                </button>
                                            </div>
                                            
                                            <!-- Certification -->
                                            <div class="mb-8">
                                                <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                    <i class="ri-file-certificate-line mr-3 text-royal"></i>
                                                    Certification and Commitment
                                                </h4>
                                                <div class="bg-gradient-to-r from-cream to-gold-soft p-6 rounded-xl border border-gold-soft">
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                                        <!-- Employee -->
                                                        <div class="space-y-4">
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Employee Name</label>
                                                                <input type="text" name="employee_name"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['employee_name'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Employee Date</label>
                                                                <input type="date" name="employee_date"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['employee_date'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Employee Signature</label>
                                                                <?php if (!empty($form_data['certification']['employee_signature'] ?? '')): ?>
                                                                    <div class="signature-preview mb-2">
                                                                        <img src="<?php echo htmlspecialchars($form_data['certification']['employee_signature']); ?>" alt="Employee Signature">
                                                                    </div>
                                                                <?php endif; ?>
                                                                <input type="text"
                                                                       name="employee_signature"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['employee_signature'] ?? ''); ?>"
                                                                       class="form-input"
                                                                       placeholder="Signature image path">
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Supervisor -->
                                                        <div class="space-y-4">
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Supervisor Name</label>
                                                                <input type="text" name="supervisor_name"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['supervisor_name'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Supervisor Date</label>
                                                                <input type="date" name="supervisor_date"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['supervisor_date'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div class="text-sm text-gray-500 italic">
                                                                Signature will be added by HR/Admin
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Director -->
                                                        <div class="space-y-4">
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Director Name</label>
                                                                <input type="text" name="director_name"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['director_name'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div>
                                                                <label class="block text-gray-600 mb-2 font-medium">Director Date</label>
                                                                <input type="date" name="director_date"
                                                                       value="<?php echo htmlspecialchars($form_data['certification']['director_date'] ?? ''); ?>"
                                                                       class="form-input">
                                                            </div>
                                                            <div class="text-sm text-gray-500 italic">
                                                                Signature will be added by HR/Admin
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="flex flex-col lg:flex-row justify-end space-y-4 lg:space-y-0 lg:space-x-4 mt-8 pt-6 border-t border-gray-200">
                                                <button type="submit" name="save" class="btn-primary flex items-center justify-center">
                                                    <i class="ri-save-line mr-2"></i> Save Changes
                                                </button>
                                                <button type="button" onclick="showSubmitModal(<?php echo $form['id']; ?>)" class="btn-success flex items-center justify-center">
                                                    <i class="ri-send-plane-line mr-2"></i> Submit to HR
                                                </button>
                                                <a href="Individual_Development_Plan.php?id=<?php echo $form['id']; ?>" class="btn-gold flex items-center justify-center">
                                                    <i class="ri-edit-line mr-2"></i> Edit in Full Form
                                                </a>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Submitted Tab -->
            <div class="tab-content" id="submitted-tab">
                <?php if ($submitted_count === 0): ?>
                    <div class="empty-state">
                        <i class="ri-send-plane-line"></i>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Submitted Forms</h3>
                        <p class="text-gray-600 mb-4">You haven't submitted any IDP forms yet.</p>
                        <p class="text-gray-500 text-sm">Submit your draft forms to appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php 
                        $submitted_index = 0;
                        foreach ($submitted_forms as $form): 
                            $submitted_index++;
                            $form_data = json_decode($form['form_data'], true);
                            $created_date = date('M d, Y', strtotime($form['created_at']));
                            $submitted_date = date('M d, Y', strtotime($form['submitted_at']));
                        ?>
                            <div class="form-card">
                                <button class="accordion-toggle w-full text-left p-6 focus:outline-none hover:bg-cream transition-all" data-id="<?php echo $form['id']; ?>">
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center space-x-4">
                                            <div class="w-12 h-12 rounded-xl bg-gradient-to-r from-forest to-forest-2 flex items-center justify-center">
                                                <i class="ri-file-text-line text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-lg text-gray-800">IDP Form #<?php echo $submitted_index; ?></h3>
                                                <p class="text-gray-600 text-sm mt-1">
                                                    Submitted: <?php echo $submitted_date; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <span class="badge badge-submitted">
                                                <i class="ri-send-plane-line mr-1"></i> Submitted
                                            </span>
                                            <i class="ri-arrow-down-s-line transition-transform duration-300 text-gray-500 text-xl"></i>
                                        </div>
                                    </div>
                                </button>
                                
                                <div class="accordion-content px-6" id="content-<?php echo $form['id']; ?>">
                                    <div class="border-t border-gray-200 pt-6 pb-8">
                                        <!-- Personal Information -->
                                        <div class="mb-8">
                                            <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                <i class="ri-user-settings-line mr-3 text-royal"></i>
                                                Personal Information
                                            </h4>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div class="space-y-4">
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Name</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['name'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Position</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['position'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Salary Grade</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['salary_grade'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Years in Position</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['years_position'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Years in LSPU</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['years_lspu'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                                
                                                <div class="space-y-4">
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Years in Other Office/Agency</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['years_other'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Division</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['division'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Office</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['office'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Office Address</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['address'] ?? ''); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-gray-600 mb-2 font-medium">Supervisor's Name</label>
                                                        <p class="readonly-field"><?php echo htmlspecialchars($form_data['personal_info']['supervisor'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Purpose -->
                                        <div class="mb-8">
                                            <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                <i class="ri-target-line mr-3 text-royal"></i>
                                                Purpose of Development Plan
                                            </h4>
                                            <div class="bg-gradient-to-r from-cream to-gold-soft p-6 rounded-xl border border-gold-soft">
                                                <div class="space-y-3">
                                                    <div class="flex items-center">
                                                        <input type="checkbox" class="checkbox-custom mr-3" <?php echo ($form_data['purpose']['purpose1'] ?? 0) ? 'checked' : ''; ?> disabled>
                                                        <span class="text-gray-800">To meet the competencies in the current positions</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input type="checkbox" class="checkbox-custom mr-3" <?php echo ($form_data['purpose']['purpose2'] ?? 0) ? 'checked' : ''; ?> disabled>
                                                        <span class="text-gray-800">To increase the level of competencies of current positions</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input type="checkbox" class="checkbox-custom mr-3" <?php echo ($form_data['purpose']['purpose3'] ?? 0) ? 'checked' : ''; ?> disabled>
                                                        <span class="text-gray-800">To meet the competencies in the next higher position</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input type="checkbox" class="checkbox-custom mr-3" <?php echo ($form_data['purpose']['purpose4'] ?? 0) ? 'checked' : ''; ?> disabled>
                                                        <span class="text-gray-800">To acquire new competencies across different functions/position</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input type="checkbox" class="checkbox-custom mr-3" <?php echo ($form_data['purpose']['purpose5'] ?? 0) ? 'checked' : ''; ?> disabled>
                                                        <span class="text-gray-800">Others, please specify: <?php echo htmlspecialchars($form_data['purpose']['purpose_other'] ?? ''); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Long Term Goals -->
                                        <div class="mb-8">
                                            <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                <i class="ri-calendar-2-line mr-3 text-gold"></i>
                                                Training/Development Interventions for Long Term Goals (Next Five Years)
                                            </h4>
                                            <div class="table-wrapper">
                                                <table class="development-table">
                                                    <thead>
                                                        <tr>
                                                            <th class="long-term-area-col">Area of Development</th>
                                                            <th class="long-term-activity-col">Development Activity</th>
                                                            <th class="long-term-date-col">Target Completion Date</th>
                                                            <th class="long-term-stage-col">Completion Stage</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (!empty($form_data['long_term_goals'])): ?>
                                                            <?php foreach ($form_data['long_term_goals'] as $goal): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($goal['area']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['activity']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['target_date']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['stage']); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <!-- Short Term Goals -->
                                        <div class="mb-8">
                                            <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                <i class="ri-calendar-line mr-3 text-forest"></i>
                                                Short Term Development Goals (Next Year)
                                            </h4>
                                            <div class="table-wrapper">
                                                <table class="development-table">
                                                    <thead>
                                                        <tr>
                                                            <th class="area-col">Area of Development</th>
                                                            <th class="priority-col">Priority for LDP</th>
                                                            <th class="activity-col">Development Activity</th>
                                                            <th class="date-col">Target Completion Date</th>
                                                            <th class="responsible-col">Who is Responsible</th>
                                                            <th class="stage-col">Completion Stage</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (!empty($form_data['short_term_goals'])): ?>
                                                            <?php foreach ($form_data['short_term_goals'] as $goal): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($goal['area']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['priority']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['activity']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['target_date']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['responsible']); ?></td>
                                                                <td><?php echo htmlspecialchars($goal['stage']); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <!-- Certification -->
                                        <div class="mb-8">
                                            <h4 class="font-bold text-xl text-gray-800 mb-4 flex items-center">
                                                <i class="ri-file-certificate-line mr-3 text-royal"></i>
                                                Certification and Commitment
                                            </h4>
                                            <div class="bg-gradient-to-r from-cream to-gold-soft p-6 rounded-xl border border-gold-soft">
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                                    <!-- Employee -->
                                                    <div class="space-y-4">
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Employee Name</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['employee_name'] ?? ''); ?></p>
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Employee Date</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['employee_date'] ?? ''); ?></p>
                                                        </div>
                                                        <?php if (!empty($form_data['certification']['employee_signature'] ?? '')): ?>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Employee Signature</label>
                                                            <div class="signature-preview">
                                                                <img src="<?php echo htmlspecialchars($form_data['certification']['employee_signature']); ?>" alt="Employee Signature">
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <!-- Supervisor -->
                                                    <div class="space-y-4">
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Supervisor Name</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['supervisor_name'] ?? ''); ?></p>
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Supervisor Date</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['supervisor_date'] ?? ''); ?></p>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Director -->
                                                    <div class="space-y-4">
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Director Name</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['director_name'] ?? ''); ?></p>
                                                        </div>
                                                        <div>
                                                            <label class="block text-gray-600 mb-2 font-medium">Director Date</label>
                                                            <p class="readonly-field"><?php echo htmlspecialchars($form_data['certification']['director_date'] ?? ''); ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="pt-6 border-t border-gray-200">
                                            <p class="text-gray-600 text-sm italic">
                                                <i class="ri-information-line mr-2"></i>
                                                This form was submitted on <?php echo $submitted_date; ?> and cannot be edited.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Submit Confirmation Modal -->
    <div class="modal-backdrop" id="submit-modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mr-4">
                        <i class="ri-send-plane-line text-forest text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">Confirm Submission</h3>
                        <p class="text-gray-600">Submit IDP Form to HR</p>
                    </div>
                </div>
                
                <p class="text-gray-600 mb-6">Are you sure you want to submit this IDP form to HR? Once submitted, you won't be able to make further changes. Please ensure all information is correct.</p>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideSubmitModal()" class="btn-secondary">
                        Cancel
                    </button>
                    <form method="POST" action="save_idp_forms.php" id="submit-form">
                        <input type="hidden" name="submit_id" id="submit-id-input">
                        <button type="submit" class="btn-success">
                            <i class="ri-send-plane-line mr-2"></i> Confirm Submit
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('sidebarFixed');
            const backdrop = document.getElementById('mobileBackdrop');

            function closeMobileSidebar() {
                sidebar.classList.remove('mobile-open');
                backdrop.classList.remove('active');
            }

            if (menuBtn && sidebar && backdrop) {
                menuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                    backdrop.classList.toggle('active');
                });
                backdrop.addEventListener('click', closeMobileSidebar);
                sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMobileSidebar));
            }

            // Accordion functionality
            const accordionToggles = document.querySelectorAll('.accordion-toggle');
            
            accordionToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const formId = this.getAttribute('data-id');
                    const content = document.getElementById(`content-${formId}`);
                    const arrow = this.querySelector('i:last-child');
                    
                    if (content.classList.contains('active')) {
                        content.classList.remove('active');
                        arrow.classList.remove('rotate-180');
                    } else {
                        content.classList.add('active');
                        arrow.classList.add('rotate-180');
                    }
                });
            });
            
            // Tab functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update active tab button
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding content
                    tabContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });

            // Initialize editable cells for draft forms
            initializeEditableCells();
            
            // Initialize dropdown (already open)
            const dropdownContainer = document.getElementById('idp-dropdown');
            if (dropdownContainer) {
                dropdownContainer.classList.add('open');
            }
        });
        
        // Modal functions
        function showSubmitModal(formId) {
            const submitIdInput = document.getElementById('submit-id-input');
            const modal = document.getElementById('submit-modal');
            
            if (submitIdInput && modal) {
                submitIdInput.value = formId;
                modal.classList.add('active');
            }
        }
        
        function hideSubmitModal() {
            const modal = document.getElementById('submit-modal');
            if (modal) {
                modal.classList.remove('active');
            }
        }
        
        // Editable cells functionality
        function initializeEditableCells() {
            // Handle editable cells with default text
            document.querySelectorAll('.editable-cell.default-text').forEach(cell => {
                const defaultValue = cell.textContent.trim();
                const hiddenInput = cell.parentElement.querySelector('input[type="hidden"]');
                
                cell.addEventListener('focus', function() {
                    if (this.textContent.trim() === defaultValue) {
                        this.textContent = '';
                        this.classList.remove('default-text');
                    }
                });
                
                cell.addEventListener('blur', function() {
                    if (this.textContent.trim() === '') {
                        this.textContent = defaultValue;
                        this.classList.add('default-text');
                    }
                    // Update hidden input value
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
                
                // Handle input to update hidden field
                cell.addEventListener('input', function() {
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
            });
            
            // Handle editable cells with placeholder
            document.querySelectorAll('.editable-cell.placeholder').forEach(cell => {
                const placeholder = cell.getAttribute('data-placeholder');
                const hiddenInput = cell.parentElement.querySelector('input[type="hidden"]');
                
                if (cell.textContent.trim() === '') {
                    cell.textContent = placeholder;
                    cell.classList.add('placeholder');
                }
                
                cell.addEventListener('focus', function() {
                    if (this.classList.contains('placeholder')) {
                        this.textContent = '';
                        this.classList.remove('placeholder');
                    }
                });
                
                cell.addEventListener('blur', function() {
                    if (this.textContent.trim() === '') {
                        this.textContent = placeholder;
                        this.classList.add('placeholder');
                    }
                    // Update hidden input value
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
                
                // Handle input to update hidden field
                cell.addEventListener('input', function() {
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
            });
            
            // Handle regular editable cells
            document.querySelectorAll('.editable-cell:not(.default-text):not(.placeholder)').forEach(cell => {
                const hiddenInput = cell.parentElement.querySelector('input[type="hidden"]');
                
                cell.addEventListener('input', function() {
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
                
                cell.addEventListener('blur', function() {
                    if (hiddenInput) {
                        hiddenInput.value = this.textContent.trim();
                    }
                });
            });
        }
        
        function addLongTermGoal(formId) {
            const tbody = document.getElementById(`long-term-goals-${formId}`);
            if (tbody) {
                const newRow = document.createElement('tr');
                newRow.innerHTML = `
                    <td>
                        <div contenteditable="true" data-name="long_term_area[]" 
                             class="editable-cell min-h-[60px] default-text">
                            Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development
                        </div>
                        <input type="hidden" name="long_term_area[]" value="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="long_term_activity[]" 
                             class="editable-cell min-h-[60px] default-text">
                            Pursuance of Academic Degrees for advancement, conduct of trainings/seminars
                        </div>
                        <input type="hidden" name="long_term_activity[]" value="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
                    </td>
                    <td>
                        <input type="date" name="long_term_date[]" class="table-input">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="long_term_stage[]" 
                             class="editable-cell min-h-[60px] placeholder"
                             data-placeholder="Enter completion stage..."></div>
                        <input type="hidden" name="long_term_stage[]" value="">
                    </td>
                `;
                tbody.appendChild(newRow);
                
                // Initialize the new editable cells
                initializeEditableCells();
            }
        }
        
        function addShortTermGoal(formId) {
            const tbody = document.getElementById(`short-term-goals-${formId}`);
            if (tbody) {
                const newRow = document.createElement('tr');
                newRow.innerHTML = `
                    <td>
                        <div contenteditable="true" data-name="short_term_area[]" 
                             class="editable-cell min-h-[60px]"
                             placeholder="Enter development area..."></div>
                        <input type="hidden" name="short_term_area[]" value="">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="short_term_priority[]" 
                             class="editable-cell min-h-[60px] placeholder"
                             data-placeholder="Enter priority..."></div>
                        <input type="hidden" name="short_term_priority[]" value="">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="short_term_activity[]" 
                             class="editable-cell min-h-[60px]"></div>
                        <input type="hidden" name="short_term_activity[]" value="">
                    </td>
                    <td>
                        <input type="date" name="short_term_date[]" class="table-input">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="short_term_responsible[]" 
                             class="editable-cell min-h-[60px] placeholder"
                             data-placeholder="Enter responsible person..."></div>
                        <input type="hidden" name="short_term_responsible[]" value="">
                    </td>
                    <td>
                        <div contenteditable="true" data-name="short_term_stage[]" 
                             class="editable-cell min-h-[60px] placeholder"
                             data-placeholder="Enter completion stage..."></div>
                        <input type="hidden" name="short_term_stage[]" value="">
                    </td>
                `;
                tbody.appendChild(newRow);
                
                // Initialize the new editable cells
                initializeEditableCells();
            }
        }
    </script>
</body>
</html>