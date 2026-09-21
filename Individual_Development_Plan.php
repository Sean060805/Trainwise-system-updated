<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$form_data = null;
$is_edit = false;

// Set upload directory path
$upload_dir = 'uploads/profile_images/';

// Create uploads directory if not exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Signature directories
$signature_dir = 'uploads/signatures/';
$signature_library_dir = $signature_dir . 'library/';

if (!file_exists($signature_dir)) {
    mkdir($signature_dir, 0777, true);
}
if (!file_exists($signature_library_dir)) {
    mkdir($signature_library_dir, 0777, true);
}

// Saved reusable signature path for current user
$saved_employee_signature_path = $signature_library_dir . 'employee_user_' . $user_id . '.png';
$has_saved_employee_signature = file_exists($saved_employee_signature_path);

// Get user information from database - INCLUDING PROFILE IMAGE
// 2026-09-03 - educationalAttainment/specialization/teaching_status
// added to this SELECT so $hasProfile below can be computed without a
// second query - needed for the "Take Assessment" sidebar link, which
// this page was entirely missing (see that block further down).
$user_info = [];
$stmt = $con->prepare("SELECT name, yearsInLSPU, profile_image, designation, department, educationalAttainment, specialization, teaching_status FROM users WHERE id = ?");
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
// user_page.php/training_recommendations.php/now profile.php whenever
// the profile is complete, there's an open deadline, and nothing's
// submitted yet for it) was simply missing from this page's sidebar
// entirely, along with the computation it depends on - this page never
// had any of that logic. Mirrors training_recommendations.php's exact
// computation (same required-fields list, same "latest active
// deadline" query, same submission check).
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
    error_log("Individual_Development_Plan.php: assessment-button status check failed: " . $e->getMessage());
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

/**
 * Create transparent signature image with black lines only.
 * White/light background becomes transparent.
 * Dark pixels become solid black.
 */
function createBlackLineSignaturePng($sourceImageString, $outputPath) {
    $src = @imagecreatefromstring($sourceImageString);
    if (!$src) {
        return false;
    }

    $width = imagesx($src);
    $height = imagesy($src);

    $dst = imagecreatetruecolor($width, $height);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);

    $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
    imagefill($dst, 0, 0, $transparent);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            // Brightness formula
            $brightness = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);

            // Keep darker pixels only, remove white/light background
            if ($brightness < 210) {
                // Stronger/darker lines become more opaque
                $alpha = (int) max(0, min(127, ($brightness / 210) * 80));
                $black = imagecolorallocatealpha($dst, 0, 0, 0, $alpha);
                imagesetpixel($dst, $x, $y, $black);
            } else {
                imagesetpixel($dst, $x, $y, $transparent);
            }
        }
    }

    $saved = imagepng($dst, $outputPath);

    imagedestroy($src);
    imagedestroy($dst);

    return $saved;
}

/**
 * Save base64 signature image as transparent PNG with black lines only.
 */
function saveSignatureImage($base64Data, $type, $form_id, $user_id, $saveToLibrary = true) {
    if (empty($base64Data) || strpos($base64Data, 'data:image') === false) {
        return '';
    }

    $upload_dir = 'uploads/signatures/';
    $library_dir = $upload_dir . 'library/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    if (!is_dir($library_dir)) {
        mkdir($library_dir, 0777, true);
    }

    // Detect mime and decode base64
    if (!preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
        return '';
    }

    $base64String = substr($base64Data, strpos($base64Data, ',') + 1);
    $base64String = str_replace(' ', '+', $base64String);
    $imageData = base64_decode($base64String);

    if ($imageData === false) {
        return '';
    }

    // Save per form
    $filename = $type . '_' . $form_id . '_' . time() . '.png';
    $filepath = $upload_dir . $filename;

    $saved = createBlackLineSignaturePng($imageData, $filepath);
    if (!$saved) {
        return '';
    }

    // Save reusable library signature for employee
    if ($saveToLibrary && $type === 'employee') {
        $libraryPath = $library_dir . 'employee_user_' . $user_id . '.png';
        @copy($filepath, $libraryPath);
    }

    return $filepath;
}

// Check if we're editing an existing form
if ($form_id > 0) {
    // Get form data from idp_forms table
    $stmt = $con->prepare("SELECT * FROM idp_forms WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $form_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $form_data = $result->fetch_assoc();
    $stmt->close();

    if ($form_data) {
        $is_edit = true;
        $form_data['form_data'] = json_decode($form_data['form_data'], true);

        // Get additional data from idp_personal_info table
        $stmt = $con->prepare("SELECT * FROM idp_personal_info WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $personal_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($personal_info) {
            $form_data['form_data']['personal_info'] = array_merge(
                $form_data['form_data']['personal_info'] ?? [],
                $personal_info
            );
        }

        // Get purpose data
        $stmt = $con->prepare("SELECT * FROM idp_purpose WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $purpose = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($purpose) {
            $form_data['form_data']['purpose'] = $purpose;
        }

        // Get certification data
        $stmt = $con->prepare("SELECT * FROM idp_certification WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $certification = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($certification) {
            $form_data['form_data']['certification'] = $certification;
        }

        // Get long term goals
        $stmt = $con->prepare("SELECT * FROM idp_long_term_goals WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $long_term_goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $form_data['form_data']['long_term_goals'] = $long_term_goals;

        // Get short term goals
        $stmt = $con->prepare("SELECT * FROM idp_short_term_goals WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $short_term_goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $form_data['form_data']['short_term_goals'] = $short_term_goals;
    } else {
        $form_id = 0;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Begin transaction
    $con->begin_transaction();

    try {
        // Process uploaded employee signature only
        $employee_signature_path = '';

        // Auto-save reusable employee signature to library
        if (!empty($_POST['employee_signature_data'])) {
            $targetFormId = $form_id > 0 ? $form_id : time();
            $employee_signature_path = saveSignatureImage(
                $_POST['employee_signature_data'],
                'employee',
                $targetFormId,
                $user_id,
                true
            );
        } elseif (!empty($_POST['use_saved_employee_signature']) && file_exists($saved_employee_signature_path)) {
            $employee_signature_path = $saved_employee_signature_path;
        }

        // Prepare form data for storage
        $form_input = [
            'personal_info' => [
                'name' => $_POST['name'] ?? ($user_info['name'] ?? ''),
                'position' => $_POST['position'] ?? '',
                'salary_grade' => $_POST['salary_grade'] ?? '',
                'years_position' => $_POST['years_position'] ?? '',
                'years_lspu' => $_POST['years_lspu'] ?? ($user_info['yearsInLSPU'] ?? ''),
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
                'employee_date' => !empty($_POST['employee_date']) ? $_POST['employee_date'] : null,
                'employee_signature' => $employee_signature_path ?: ($_POST['employee_signature'] ?? ''),
                'supervisor_name' => $_POST['supervisor_name'] ?? '',
                'supervisor_date' => !empty($_POST['supervisor_date']) ? $_POST['supervisor_date'] : null,
                'supervisor_signature' => '',
                'director_name' => $_POST['director_name'] ?? '',
                'director_date' => !empty($_POST['director_date']) ? $_POST['director_date'] : null,
                'director_signature' => ''
            ]
        ];

        // Process long term goals
        if (isset($_POST['long_term_area']) && is_array($_POST['long_term_area'])) {
            foreach ($_POST['long_term_area'] as $index => $area) {
                if (!empty($area) || !empty($_POST['long_term_activity'][$index])) {
                    $form_input['long_term_goals'][] = [
                        'area' => $area,
                        'activity' => $_POST['long_term_activity'][$index] ?? '',
                        'target_date' => !empty($_POST['long_term_date'][$index]) ? $_POST['long_term_date'][$index] : null,
                        'stage' => $_POST['long_term_stage'][$index] ?? ''
                    ];
                }
            }
        }

        // Process short term goals
        if (isset($_POST['short_term_area']) && is_array($_POST['short_term_area'])) {
            foreach ($_POST['short_term_area'] as $index => $area) {
                $form_input['short_term_goals'][] = [
                    'area' => $area,
                    'priority' => $_POST['short_term_priority'][$index] ?? '',
                    'activity' => $_POST['short_term_activity'][$index] ?? '',
                    'target_date' => !empty($_POST['short_term_date'][$index]) ? $_POST['short_term_date'][$index] : null,
                    'responsible' => $_POST['short_term_responsible'][$index] ?? '',
                    'stage' => $_POST['short_term_stage'][$index] ?? ''
                ];
            }
        }

        $json_data = json_encode($form_input);
        $status = isset($_POST['submit_form']) ? 'submitted' : 'draft';
        $submitted_at = $status === 'submitted' ? date('Y-m-d H:i:s') : null;

        // Save to idp_forms table
        if ($is_edit) {
            $stmt = $con->prepare("UPDATE idp_forms SET form_data = ?, status = ?, submitted_at = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $json_data, $status, $submitted_at, $form_id);
        } else {
            $stmt = $con->prepare("INSERT INTO idp_forms (user_id, form_data, status, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->bind_param("isss", $user_id, $json_data, $status, $submitted_at);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to save form data: " . $stmt->error);
        }

        if (!$is_edit) {
            $form_id = $con->insert_id;

            // If new form and employee signature came from upload, rename/copy cleanly to form-specific file
            if (!empty($_POST['employee_signature_data'])) {
                $newEmployeeSignaturePath = saveSignatureImage(
                    $_POST['employee_signature_data'],
                    'employee',
                    $form_id,
                    $user_id,
                    true
                );

                if (!empty($newEmployeeSignaturePath)) {
                    $form_input['certification']['employee_signature'] = $newEmployeeSignaturePath;
                    $json_data = json_encode($form_input);

                    $stmt2 = $con->prepare("UPDATE idp_forms SET form_data = ? WHERE id = ?");
                    $stmt2->bind_param("si", $json_data, $form_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
        }
        $stmt->close();

        // Save to idp_personal_info table
        $stmt = $con->prepare("REPLACE INTO idp_personal_info (form_id, name, position, salary_grade, years_position, years_lspu, years_other, division, office, address, supervisor) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "issssssssss",
            $form_id,
            $form_input['personal_info']['name'],
            $form_input['personal_info']['position'],
            $form_input['personal_info']['salary_grade'],
            $form_input['personal_info']['years_position'],
            $form_input['personal_info']['years_lspu'],
            $form_input['personal_info']['years_other'],
            $form_input['personal_info']['division'],
            $form_input['personal_info']['office'],
            $form_input['personal_info']['address'],
            $form_input['personal_info']['supervisor']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save personal info: " . $stmt->error);
        }
        $stmt->close();

        // Save to idp_purpose table
        $stmt = $con->prepare("REPLACE INTO idp_purpose (form_id, purpose1, purpose2, purpose3, purpose4, purpose5, purpose_other) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "iiiiiis",
            $form_id,
            $form_input['purpose']['purpose1'],
            $form_input['purpose']['purpose2'],
            $form_input['purpose']['purpose3'],
            $form_input['purpose']['purpose4'],
            $form_input['purpose']['purpose5'],
            $form_input['purpose']['purpose_other']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save purpose data: " . $stmt->error);
        }
        $stmt->close();

        // Save to idp_certification table
        $stmt = $con->prepare("REPLACE INTO idp_certification (form_id, employee_name, employee_date, employee_signature, supervisor_name, supervisor_date, supervisor_signature, director_name, director_date, director_signature) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $employee_date = !empty($form_input['certification']['employee_date']) ? $form_input['certification']['employee_date'] : null;
        $supervisor_date = !empty($form_input['certification']['supervisor_date']) ? $form_input['certification']['supervisor_date'] : null;
        $director_date = !empty($form_input['certification']['director_date']) ? $form_input['certification']['director_date'] : null;

        $empty_supervisor_signature = '';
        $empty_director_signature = '';

        $stmt->bind_param(
            "isssssssss",
            $form_id,
            $form_input['certification']['employee_name'],
            $employee_date,
            $form_input['certification']['employee_signature'],
            $form_input['certification']['supervisor_name'],
            $supervisor_date,
            $empty_supervisor_signature,
            $form_input['certification']['director_name'],
            $director_date,
            $empty_director_signature
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save certification data: " . $stmt->error);
        }
        $stmt->close();

        // Save long term goals
        $stmt = $con->prepare("DELETE FROM idp_long_term_goals WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $stmt->close();

        foreach ($form_input['long_term_goals'] as $goal) {
            $stmt = $con->prepare("INSERT INTO idp_long_term_goals (form_id, area, activity, target_date, stage) VALUES (?, ?, ?, ?, ?)");
            $target_date = !empty($goal['target_date']) ? $goal['target_date'] : null;
            $stmt->bind_param("issss", $form_id, $goal['area'], $goal['activity'], $target_date, $goal['stage']);
            $stmt->execute();
            $stmt->close();
        }

        // Save short term goals
        $stmt = $con->prepare("DELETE FROM idp_short_term_goals WHERE form_id = ?");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $stmt->close();

        foreach ($form_input['short_term_goals'] as $goal) {
            $stmt = $con->prepare("INSERT INTO idp_short_term_goals (form_id, area, priority, activity, target_date, responsible, stage) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $target_date = !empty($goal['target_date']) ? $goal['target_date'] : null;
            $stmt->bind_param("issssss", $form_id, $goal['area'], $goal['priority'], $goal['activity'], $target_date, $goal['responsible'], $goal['stage']);
            $stmt->execute();
            $stmt->close();
        }

        // Final sync of latest json_data if new form had newly generated employee signature
        if (!$is_edit && !empty($form_input['certification']['employee_signature'])) {
            $final_json_data = json_encode($form_input);
            $stmt = $con->prepare("UPDATE idp_forms SET form_data = ? WHERE id = ?");
            $stmt->bind_param("si", $final_json_data, $form_id);
            $stmt->execute();
            $stmt->close();
        }

        // Commit transaction
        $con->commit();

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => $status === 'submitted' ? 'IDP form submitted successfully!' : 'IDP form saved as draft.'
        ];

        if ($status === 'submitted') {
            // ================================================================
            // UPDATED: Send notification to Department Admin, NOT System Admin
            // ================================================================
            
            // Get the user's department from session
            $user_department = $_SESSION['user_department'] ?? $user_info['department'] ?? '';
            
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
            
            // Get the corresponding department admin role
            $dept_admin_role = $departmentAdminRoles[$user_department] ?? '';
            
            if (!empty($dept_admin_role)) {
                // Create notification for Department Admin only
                $message = "New IDP form submitted by " . ($form_input['personal_info']['name'] ?? 'an employee') . " from " . $user_department . " department";
                
                $stmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, type) 
                                      SELECT id, ?, ?, 'idp_form' FROM users WHERE role = ?");
                $stmt->bind_param("sis", $message, $form_id, $dept_admin_role);
                $stmt->execute();
                $stmt->close();
            } else {
                // Fallback: if no department admin found, notify system admin
                $message = "New IDP form submitted by " . ($form_input['personal_info']['name'] ?? 'an employee');
                $stmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, type) 
                                      SELECT id, ?, ?, 'idp_form' FROM users WHERE role = 'admin'");
                $stmt->bind_param("si", $message, $form_id);
                $stmt->execute();
                $stmt->close();
            }

            // Redirect to My Submitted Forms page
            header("Location: save_idp_forms.php");
            exit();
        } else {
            header("Location: Individual_Development_Plan.php?id=" . $form_id);
            exit();
        }
    } catch (Exception $e) {
        $con->rollback();
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Error saving form: ' . $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Individual Development Plan</title>
<link rel="stylesheet" href="assets/css/tw-47.css">
<!-- 2026-09-10 fix - real bug found via a screenshot: every sidebar
     icon (<i class="ri-*">) on this page rendered invisible because
     this file never actually loaded the RemixIcon font/stylesheet -
     every other page in this app pulls it from this same CDN
     (training_recommendations.php:219, etc.); this one was just missing
     the tag itself, not a CSS override or a JS conflict. -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
<!-- 2026-09-17 fix - same class of bug as the RemixIcon one above: this
     page's <style> block sets `* { font-family: 'Poppins' }` and also
     references 'Fraunces'/'Space Grotesk' for headings, but never
     actually loaded Google Fonts - it was silently rendering in the
     browser's fallback sans-serif this whole time. save_idp_forms.php
     (the sibling IDP page) already loads this exact font set. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --cream: #FDF8F0;
  --cream-dim: #F5EDDF;
  --ink: #16233A;
  --ink-soft: #52627B;
  --royal: #1A4B8C;
  --royal-2: #0F3460;
  --forest: #0D6B4D;
  --forest-2: #084A34;
  --gold: #D4A843;
  --gold-light: #E8C96E;
  --gold-soft: #F5E6C8;
}

* {
  font-family: 'Poppins', sans-serif;
}

body {
  background: linear-gradient(135deg, #FDF8F0 0%, #F5EDDF 100%);
  margin: 0;
  padding: 0;
}

.fixed-sidebar {
  position: fixed;
  left: 0;
  top: 0;
  height: 100vh;
  /* 2026-09-03 fix - see the matching comment in user_page.php's
     .sidebar-fixed: 100vh overshoots the real visible area on mobile
     once the browser's own chrome is accounted for. This file uses a
     differently-named class (.fixed-sidebar, not .sidebar-fixed), so
     it was missed by that earlier pass - same bug, same fix. */
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

.main-content-wrapper {
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
  .fixed-sidebar {
    transform: translateX(-100%);
    transition: transform 0.28s ease;
  }
  .fixed-sidebar.mobile-open {
    transform: translateX(0);
  }
  .main-content-wrapper {
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

.form-gradient {
  background: linear-gradient(145deg, #ffffff 0%, #FDF8F0 100%);
}

.hover-lift {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.hover-lift:hover {
  transform: translateY(-5px);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.floating-label {
  position: relative;
  margin-bottom: 1.5rem;
}

.floating-label input,
.floating-label textarea {
  width: 100%;
  padding: 1rem 1rem 0.5rem 1rem;
  border: 2px solid #e5e7eb;
  border-radius: 0.75rem;
  font-size: 1rem;
  transition: all 0.3s ease;
  background: white;
}

.floating-label input:focus,
.floating-label textarea:focus {
  border-color: #1A4B8C;
  box-shadow: 0 0 0 3px rgba(26, 75, 140, 0.1);
  outline: none;
}

.floating-label label {
  position: absolute;
  top: 50%;
  left: 1rem;
  transform: translateY(-50%);
  background: white;
  padding: 0 0.5rem;
  color: #6b7280;
  font-size: 0.875rem;
  transition: all 0.3s ease;
  pointer-events: none;
}

.floating-label input:focus + label,
.floating-label input:not(:placeholder-shown) + label,
.floating-label textarea:focus + label,
.floating-label textarea:not(:placeholder-shown) + label {
  top: 0.25rem;
  transform: translateY(0);
  font-size: 0.75rem;
  color: #1A4B8C;
}

.floating-label textarea {
  min-height: 100px;
  resize: vertical;
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

.development-table {
  border-collapse: separate;
  border-spacing: 0;
  border-radius: 0.75rem;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  width: 100%;
  table-layout: fixed;
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
}

.development-table td {
  padding: 0.5rem;
  border: 1px solid #e5e7eb;
  background: white;
  vertical-align: top;
  word-wrap: break-word;
}

.development-table tr:hover td {
  background-color: #FDF8F0;
}

.development-table textarea {
  width: 100%;
  min-height: 80px;
  resize: none;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 0.5rem;
  font-family: 'Poppins', sans-serif;
  font-size: 0.875rem;
  transition: all 0.3s ease;
}

.development-table textarea:focus {
  outline: none;
  border-color: #1A4B8C;
  box-shadow: 0 0 0 2px rgba(26, 75, 140, 0.1);
}

.development-table textarea.auto-expand {
  overflow: hidden;
  min-height: 80px;
  max-height: 300px;
}

.signature-line {
  height: 2px;
  background: #6b7280;
  margin: 0.5rem auto;
  width: 80%;
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

.group.open .ri-arrow-down-s-line {
  transform: rotate(180deg);
}

@media print {
  .no-print {
    display: none !important;
  }

  .print-only {
    display: block !important;
  }

  .fixed-sidebar {
    display: none !important;
  }

  .main-content-wrapper {
    margin-left: 0 !important;
  }

  body {
    background: white !important;
  }

  .form-container {
    padding: 0 !important;
    box-shadow: none !important;
  }

  table {
    page-break-inside: avoid;
  }

  .signature-section {
    page-break-inside: avoid;
  }
}

.print-only {
  display: none;
}

.section-highlight {
  background: linear-gradient(135deg, rgba(26, 75, 140, 0.1) 0%, rgba(15, 52, 96, 0.05) 100%);
  border-left: 4px solid #D4A843;
  padding: 1.5rem;
  border-radius: 0 0.75rem 0.75rem 0;
}

.btn-gradient-primary {
  background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(26, 75, 140, 0.4);
}

.btn-gradient-primary:hover {
  background: linear-gradient(135deg, #0F3460 0%, #0A2345 100%);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(26, 75, 140, 0.5);
}

.btn-gradient-success {
  background: linear-gradient(135deg, #0D6B4D 0%, #084A34 100%);
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(13, 107, 77, 0.4);
}

.btn-gradient-success:hover {
  background: linear-gradient(135deg, #084A34 0%, #063A28 100%);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(13, 107, 77, 0.5);
}

.required-field::after {
  content: '*';
  color: #DC3545;
  margin-left: 4px;
}

.help-text {
  font-size: 0.75rem;
  color: #6b7280;
  margin-top: 0.25rem;
}

.form-section {
  animation: fadeIn 0.5s ease-out;
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

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
  margin-right: 6px;
}

.dot-draft {
  background-color: #D4A843;
}

.dot-submitted {
  background-color: #0D6B4D;
}

.signature-upload-container {
  border: 2px dashed #e5e7eb;
  border-radius: 0.75rem;
  background: white;
  padding: 1rem;
  margin-bottom: 1rem;
  text-align: center;
  transition: all 0.3s ease;
  min-height: 220px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.signature-upload-container:hover {
  border-color: #1A4B8C;
  background-color: #FDF8F0;
}

.signature-upload-container.dragover {
  border-color: #1A4B8C;
  background-color: #F5EDDF;
}

.signature-upload-btn {
  background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
  color: white;
  padding: 0.75rem 1.5rem;
  border-radius: 0.5rem;
  border: none;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.3s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.signature-upload-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(26, 75, 140, 0.3);
}

.signature-preview {
  margin-top: 1rem;
  max-width: 100%;
  min-height: 110px;
  max-height: 150px;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 0.5rem;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.signature-preview img {
  max-width: 100%;
  max-height: 100px;
  display: block;
  margin: 0 auto;
}

.saved-signature-card {
  background: #ffffff;
  border: 1px solid #F5E6C8;
  border-radius: 1rem;
  padding: 1rem;
  box-shadow: 0 4px 14px rgba(0,0,0,0.05);
  height: 100%;
}

.saved-signature-preview {
  min-height: 150px;
  border: 1px dashed #cbd5e1;
  border-radius: 0.75rem;
  background: linear-gradient(180deg, #ffffff 0%, #FDF8F0 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.saved-signature-preview img {
  max-width: 100%;
  max-height: 100px;
}

.area-col { width: 25%; }
.priority-col { width: 15%; }
.activity-col { width: 25%; }
.date-col { width: 12%; }
.responsible-col { width: 12%; }
.stage-col { width: 11%; }
.long-term-area-col { width: 25%; }
.long-term-activity-col { width: 35%; }
.long-term-date-col { width: 15%; }
.long-term-stage-col { width: 25%; }

.table-cell-content {
  min-height: 60px;
  padding: 0.5rem;
  cursor: text;
  outline: none;
  transition: all 0.3s ease;
  position: relative;
}

.table-cell-content:focus {
  background-color: #FDF8F0;
  border-radius: 0.25rem;
}

.table-cell-content.placeholder {
  color: #9ca3af;
  font-style: italic;
}

.table-cell-content.default-text {
  color: #6b7280;
  font-style: italic;
}

.hidden-input {
  display: none;
}

.signature-note {
  font-size: 0.8rem;
  color: #64748b;
  margin-top: 0.5rem;
}

.muted-box {
  background: #FDF8F0;
  border: 1px dashed #cbd5e1;
  border-radius: 0.75rem;
  padding: 1rem;
  text-align: center;
}

.sidebar-logo-ring {
  box-shadow: 0 0 0 3px rgba(212, 168, 67, 0.5);
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
</style>
</head>
<body class="min-h-screen font-poppins">
<button id="mobileMenuBtn" class="mobile-menu-btn no-print" aria-label="Open menu"><i class="ri-menu-line"></i></button>
<div id="mobileBackdrop" class="mobile-backdrop"></div>

<div id="sidebarFixed" class="fixed-sidebar text-white">
  <div class="h-full flex flex-col">
    <div class="p-6 flex items-center border-b border-white/10">
      <div class="relative mr-3">
        <div class="w-14 h-14 rounded-full bg-white/95 flex items-center justify-center p-1.5 shadow-lg sidebar-logo-ring">
          <img src="images/lspu-logo.png" alt="LSPU Logo" class="w-full h-full object-contain" onerror="this.style.display='none'" />
        </div>
      </div>
      <div>
        <a href="user_page.php" class="text-lg font-semibold text-white tracking-tight leading-tight block" style="font-family:'Fraunces',serif;">Training Tracker</a>
        <p class="eyebrow" style="color:#E8C96E;"><span style="width:10px;"></span>TNA System</p>
      </div>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1">
      <a href="user_page.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-dashboard-line text-lg"></i>
        </div>
        Dashboard
        <i class="ri-arrow-right-s-line ml-auto"></i>
      </a>

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
          <a href="Individual_Development_Plan.php" class="nav-item active flex items-center px-4 py-2.5 text-sm rounded-lg bg-blue-700/30 transition-all">
            <div class="w-5 h-5 flex items-center justify-center mr-3">
              <i class="ri-file-add-line"></i>
            </div>
            Create New
          </a>
          <a href="save_idp_forms.php" class="nav-item flex items-center px-4 py-2.5 text-sm rounded-lg hover:bg-blue-700/30 transition-all">
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
         training_recommendations.php, missing here (and on profile.php/
         save_idp_forms.php), which read as inconsistent. Ported from
         training_recommendations.php's version. -->
    <div class="p-4 border-t border-white/10">
      <div class="bg-white/5 backdrop-blur-sm rounded-xl p-4 border border-white/10 mb-4">
        <h3 class="eyebrow mb-3" style="color:var(--gold-light);">Quick Status</h3>

        <div class="space-y-3">
          <!-- Profile Completion -->
          <?php if ($profileCompletionPercentage < 100): /* 2026-09-21 tester feedback: a permanent "100%" bar is noise - show profile progress only while it is incomplete */ ?>
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
          <?php endif; ?>

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

    <div class="p-4 border-t border-blue-800/30 mt-auto">
      <div class="flex items-center justify-between">
        <div class="flex items-center">
          <?php
            $defaultImage = 'images/noprofile.jpg';
            $imageSrc = $defaultImage;

            if (!empty($profile_image)) {
                $full_path = $upload_dir . $profile_image;
                if (file_exists($full_path)) {
                    $imageSrc = $full_path;
                }
            }
          ?>
          <img class="w-10 h-10 rounded-full border-2 border-blue-500/30 mr-3 object-cover"
               src="<?= htmlspecialchars($imageSrc); ?>"
               alt="Profile Picture"
               onerror="this.onerror=null;this.src='<?= $defaultImage; ?>';">
          <div>
            <p class="text-sm font-medium text-white truncate max-w-[120px]">
              <?= htmlspecialchars($first_name . ' ' . $last_name) ?>
            </p>
            <p class="text-xs text-blue-300 truncate max-w-[120px]" style="color:#E8C96E;">
              <?= htmlspecialchars($designation) ?>
            </p>
          </div>
        </div>

        <a href="?logout=true" class="p-2 rounded-lg hover:bg-red-600/20 text-red-100 border border-red-500/20 transition-all flex items-center justify-center">
          <i class="ri-logout-box-line"></i>
        </a>
      </div>
    </div>
  </div>
</div>

<div class="main-content-wrapper">
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

    <div class="print-only">
      <div class="text-center mb-8">
        <div class="text-sm">Republic of the Philippines</div>
        <div class="text-xl font-bold">Laguna State Polytechnic University</div>
        <div class="text-sm">Province of Laguna</div>
        <div class="text-2xl font-bold mt-4">INDIVIDUAL DEVELOPMENT PLAN</div>
      </div>
    </div>

    <form method="POST" action="" class="form-gradient rounded-2xl shadow-custom overflow-hidden hover:shadow-custom-hover transition-all duration-300 hover-lift" id="idp-form" enctype="multipart/form-data">
      <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
      <input type="hidden" id="employee_signature_data" name="employee_signature_data" value="">
      <input type="hidden" id="use_saved_employee_signature" name="use_saved_employee_signature" value="0">

      <div class="p-8 md:p-12 form-container">
        <div class="text-center mb-12 no-print">
          <div class="flex flex-col items-center mb-6">
            <div class="w-20 h-20 rounded-full bg-gradient-to-r from-royal to-royal-2 flex items-center justify-center mb-4 shadow-lg" style="background:linear-gradient(135deg,#1A4B8C,#0F3460);">
              <i class="ri-file-text-line text-white text-3xl"></i>
            </div>
            <div>
              <h1 class="text-4xl font-bold" style="background:linear-gradient(135deg,#1A4B8C,#0F3460); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;">INDIVIDUAL DEVELOPMENT PLAN</h1>
              <p class="text-gray-600 mt-2">Employee Growth and Competency Roadmap</p>
            </div>
          </div>
          <div class="w-32 h-1.5 bg-gradient-to-r from-gold via-gold-light to-gold mx-auto rounded-full"></div>
        </div>

        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4 no-print">
          <div class="flex items-center">
            <div class="bg-gradient-to-r from-gold-soft to-cream rounded-xl p-3 mr-4">
              <i class="ri-file-list-3-line text-royal text-2xl"></i>
            </div>
            <div>
              <span class="text-sm font-medium text-gray-600">Form Status:</span>
              <span class="ml-2 badge <?php echo ($is_edit && $form_data['status'] === 'submitted') ? 'badge-submitted' : 'badge-draft'; ?>">
                <span class="status-dot <?php echo ($is_edit && $form_data['status'] === 'submitted') ? 'dot-submitted' : 'dot-draft'; ?>"></span>
                <?php echo ($is_edit && $form_data['status'] === 'submitted') ? 'Submitted' : ($is_edit ? 'Draft' : 'New Form'); ?>
              </span>
              <?php if ($is_edit): ?>
                <p class="text-xs text-gray-500 mt-1">Last updated: <?php echo date('M j, Y g:i A', strtotime($form_data['updated_at'])); ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="flex space-x-4">
            <button type="button" id="print-btn" class="btn-gradient-primary text-white px-6 py-3 rounded-xl font-medium flex items-center shadow-lg hover:shadow-xl">
              <i class="ri-printer-line mr-3"></i> Generate PDF
            </button>
            <button type="button" id="help-btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-medium flex items-center">
              <i class="ri-question-line mr-3"></i> Help
            </button>
          </div>
        </div>

        <div class="mb-12 form-section">
          <div class="section-highlight mb-6 no-print">
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
              <i class="ri-user-settings-line mr-3 text-royal"></i> Personal Information
            </h3>
            <p class="text-gray-600 mt-2">Complete your personal and employment details</p>
          </div>

          <div class="print-only">
            <h3 class="text-xl font-bold mb-4">PERSONAL INFORMATION</h3>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 no-print">
            <div class="space-y-6">
              <div class="floating-label">
                <input type="text" id="name" name="name" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['name']) : (isset($user_info['name']) ? htmlspecialchars($user_info['name']) : ''); ?>"
                       <?php echo isset($user_info['name']) ? 'readonly' : ''; ?>>
                <label for="name" class="required-field">Name</label>
                <span class="help-text">Your full name as registered in the system</span>
              </div>

              <div class="floating-label">
                <input type="text" id="position" name="position" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['position']) : ''; ?>">
                <label for="position" class="required-field">Current Position</label>
                <span class="help-text">Your current job title</span>
              </div>

              <div class="floating-label">
                <input type="text" id="salary-grade" name="salary_grade" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['salary_grade']) : ''; ?>">
                <label for="salary-grade" class="required-field">Salary Grade</label>
              </div>

              <div class="floating-label">
                <input type="text" id="years-position" name="years_position" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_position']) : ''; ?>">
                <label for="years-position" class="required-field">Years in this Position</label>
              </div>

              <div class="floating-label">
                <input type="text" id="years-lspu" name="years_lspu" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_lspu']) : (isset($user_info['yearsInLSPU']) ? htmlspecialchars($user_info['yearsInLSPU']) : ''); ?>"
                       <?php echo isset($user_info['yearsInLSPU']) ? 'readonly' : ''; ?>>
                <label for="years-lspu" class="required-field">Years in LSPU</label>
                <span class="help-text">Automatically filled from your profile</span>
              </div>
            </div>

            <div class="space-y-6">
              <div class="floating-label">
                <input type="text" id="years-other" name="years_other" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_other']) : ''; ?>">
                <label for="years-other">Years in Other Office/Agency</label>
                <span class="help-text">If applicable</span>
              </div>

              <div class="floating-label">
                <input type="text" id="division" name="division" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['division']) : ''; ?>">
                <label for="division" class="required-field">Division</label>
              </div>

              <div class="floating-label">
                <input type="text" id="office" name="office" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['office']) : ''; ?>">
                <label for="office" class="required-field">Office/Unit</label>
              </div>

              <div class="floating-label">
                <textarea id="address" name="address" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl" rows="2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['address']) : ''; ?></textarea>
                <label for="address" class="required-field">Office Address</label>
              </div>

              <div class="floating-label">
                <input type="text" id="supervisor" name="supervisor" placeholder=" " class="border-2 border-gray-200 focus:border-royal rounded-xl"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['supervisor']) : ''; ?>">
                <label for="supervisor" class="required-field">Supervisor's Name</label>
              </div>
            </div>
          </div>

          <div class="print-only">
            <table class="w-full border-collapse border border-gray-300 mb-6">
              <tbody>
                <tr>
                  <td class="border border-gray-300 p-2 font-medium">1. Name</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['name']) : ''; ?></td>
                  <td class="border border-gray-300 p-2 font-medium">6. Years in other office/agency if any</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_other']) : ''; ?></td>
                </tr>
                <tr>
                  <td class="border border-gray-300 p-2 font-medium">2. Current Position</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['position']) : ''; ?></td>
                  <td class="border border-gray-300 p-2 font-medium">7. Division</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['division']) : ''; ?></td>
                </tr>
                <tr>
                  <td class="border border-gray-300 p-2 font-medium">3. Salary Grade</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['salary_grade']) : ''; ?></td>
                  <td class="border border-gray-300 p-2 font-medium">8. Office</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['office']) : ''; ?></td>
                </tr>
                <tr>
                  <td class="border border-gray-300 p-2 font-medium">4. Years in the Position</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_position']) : ''; ?></td>
                  <td class="border border-gray-300 p-2 font-medium">9. No more development is desired or required for</td>
                  <td class="border border-gray-300 p-2"></td>
                </tr>
                <tr>
                  <td class="border border-gray-300 p-2 font-medium">5. Years in LSPU</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['years_lspu']) : ''; ?></td>
                  <td class="border border-gray-300 p-2 font-medium">10. Supervisor's Name</td>
                  <td class="border border-gray-300 p-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['personal_info']['supervisor']) : ''; ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="mb-12 form-section">
          <div class="section-highlight mb-6 no-print">
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
              <i class="ri-target-line mr-3 text-royal"></i> Purpose of Development Plan
            </h3>
            <p class="text-gray-600 mt-2">Select the purpose(s) for creating this development plan</p>
          </div>

          <div class="print-only">
            <h3 class="text-xl font-bold mb-4">PURPOSE:</h3>
          </div>

          <div class="bg-gradient-to-r from-cream to-gold-soft p-8 rounded-2xl border border-gold-soft no-print">
            <div class="space-y-4">
              <div class="flex items-start p-4 rounded-lg hover:bg-white/50 transition-colors">
                <div class="flex items-center h-6 mr-4">
                  <input type="checkbox" id="purpose1" name="purpose1" class="checkbox-custom w-5 h-5"
                         <?php echo ($is_edit && $form_data['form_data']['purpose']['purpose1']) ? 'checked' : ''; ?>>
                </div>
                <label for="purpose1" class="flex-1 cursor-pointer">
                  <span class="text-gray-800 font-medium">To meet the competencies in the current positions</span>
                  <p class="text-sm text-gray-600 mt-1">Develop skills required for your current role</p>
                </label>
              </div>

              <div class="flex items-start p-4 rounded-lg hover:bg-white/50 transition-colors">
                <div class="flex items-center h-6 mr-4">
                  <input type="checkbox" id="purpose2" name="purpose2" class="checkbox-custom w-5 h-5"
                         <?php echo ($is_edit && $form_data['form_data']['purpose']['purpose2']) ? 'checked' : ''; ?>>
                </div>
                <label for="purpose2" class="flex-1 cursor-pointer">
                  <span class="text-gray-800 font-medium">To increase the level of competencies of current positions</span>
                  <p class="text-sm text-gray-600 mt-1">Enhance existing skills for better performance</p>
                </label>
              </div>

              <div class="flex items-start p-4 rounded-lg hover:bg-white/50 transition-colors">
                <div class="flex items-center h-6 mr-4">
                  <input type="checkbox" id="purpose3" name="purpose3" class="checkbox-custom w-5 h-5"
                         <?php echo ($is_edit && $form_data['form_data']['purpose']['purpose3']) ? 'checked' : ''; ?>>
                </div>
                <label for="purpose3" class="flex-1 cursor-pointer">
                  <span class="text-gray-800 font-medium">To meet the competencies in the next higher position</span>
                  <p class="text-sm text-gray-600 mt-1">Prepare for career advancement</p>
                </label>
              </div>

              <div class="flex items-start p-4 rounded-lg hover:bg-white/50 transition-colors">
                <div class="flex items-center h-6 mr-4">
                  <input type="checkbox" id="purpose4" name="purpose4" class="checkbox-custom w-5 h-5"
                         <?php echo ($is_edit && $form_data['form_data']['purpose']['purpose4']) ? 'checked' : ''; ?>>
                </div>
                <label for="purpose4" class="flex-1 cursor-pointer">
                  <span class="text-gray-800 font-medium">To acquire new competencies across different functions/position</span>
                  <p class="text-sm text-gray-600 mt-1">Develop cross-functional skills</p>
                </label>
              </div>

              <div class="flex items-start p-4 rounded-lg hover:bg-white/50 transition-colors">
                <div class="flex items-center h-6 mr-4">
                  <input type="checkbox" id="purpose5" name="purpose5" class="checkbox-custom w-5 h-5"
                         <?php echo ($is_edit && $form_data['form_data']['purpose']['purpose5']) ? 'checked' : ''; ?>>
                </div>
                <div class="flex-1">
                  <label for="purpose5" class="text-gray-800 font-medium cursor-pointer">Others, please specify:</label>
                  <div class="mt-3">
                    <input type="text" id="purpose-other" name="purpose_other"
                           class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-royal focus:outline-none transition-colors"
                           placeholder="Specify other purposes here"
                           value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['purpose']['purpose_other']) : ''; ?>">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="print-only">
            <div class="mb-4">
              <div>(<?php echo ($is_edit && $form_data['form_data']['purpose']['purpose1']) ? '✓' : ' '; ?>) To meet the competencies in the current positions</div>
              <div>(<?php echo ($is_edit && $form_data['form_data']['purpose']['purpose2']) ? '✓' : ' '; ?>) To increase the level of competencies of current positions</div>
              <div>(<?php echo ($is_edit && $form_data['form_data']['purpose']['purpose3']) ? '✓' : ' '; ?>) To meet the competencies in the next higher position</div>
              <div>(<?php echo ($is_edit && $form_data['form_data']['purpose']['purpose4']) ? '✓' : ' '; ?>) To acquire new competencies across different functions/position</div>
              <div>(<?php echo ($is_edit && $form_data['form_data']['purpose']['purpose5']) ? '✓' : ' '; ?>) Others, please specify: <?php echo $is_edit ? htmlspecialchars($form_data['form_data']['purpose']['purpose_other']) : ''; ?></div>
            </div>
          </div>
        </div>

        <div class="mb-12 form-section">
          <div class="section-highlight mb-8 no-print">
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
              <i class="ri-line-chart-line mr-3 text-royal"></i> Career Development Plan
            </h3>
            <p class="text-gray-600 mt-2">Outline your short-term and long-term development goals</p>
          </div>

          <div class="mb-10">
            <div class="mb-6 no-print">
              <h4 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                <i class="ri-calendar-2-line mr-3 text-gold"></i>
                Training/Development Interventions for Long Term Goals (Next Five Years)
              </h4>
              <p class="text-gray-600">Plan your development activities for the next five years</p>
            </div>

            <div class="print-only">
              <h4 class="font-bold mb-2">Training/Development Interventions for Long Term Goals (Next Five Years)</h4>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-gray-200">
              <table class="w-full development-table">
                <thead>
                  <tr>
                    <th class="p-4 font-semibold text-left long-term-area-col">Area of Development</th>
                    <th class="p-4 font-semibold text-left long-term-activity-col">Development Activity</th>
                    <th class="p-4 font-semibold text-left long-term-date-col">Target Completion Date</th>
                    <th class="p-4 font-semibold text-left long-term-stage-col">Completion Stage</th>
                  </tr>
                </thead>
                <tbody id="long-term-goals-body">
                  <?php if ($is_edit && !empty($form_data['form_data']['long_term_goals'])): ?>
                    <?php foreach ($form_data['form_data']['long_term_goals'] as $goal): ?>
                      <tr>
                        <td class="p-2">
                          <div contenteditable="true" data-name="long_term_area[]"
                               class="table-cell-content min-h-[60px] outline-none <?php echo (empty($goal['area']) || $goal['area'] === 'Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development') ? 'default-text' : ''; ?>"
                               data-default="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
                            <?php echo !empty($goal['area']) ? htmlspecialchars($goal['area']) : 'Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development'; ?>
                          </div>
                          <input type="hidden" name="long_term_area[]" value="<?php echo htmlspecialchars($goal['area']); ?>">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="long_term_activity[]"
                               class="table-cell-content min-h-[60px] outline-none <?php echo (empty($goal['activity']) || $goal['activity'] === 'Pursuance of Academic Degrees for advancement, conduct of trainings/seminars') ? 'default-text' : ''; ?>"
                               data-default="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
                            <?php echo !empty($goal['activity']) ? htmlspecialchars($goal['activity']) : 'Pursuance of Academic Degrees for advancement, conduct of trainings/seminars'; ?>
                          </div>
                          <input type="hidden" name="long_term_activity[]" value="<?php echo htmlspecialchars($goal['activity']); ?>">
                        </td>
                        <td class="p-2">
                          <input type="date" name="long_term_date[]"
                                 class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print"
                                 value="<?php echo htmlspecialchars($goal['target_date']); ?>">
                          <span class="print-only"><?php echo htmlspecialchars($goal['target_date']); ?></span>
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="long_term_stage[]"
                               class="table-cell-content min-h-[60px] outline-none <?php echo empty($goal['stage']) ? 'placeholder' : ''; ?>"
                               data-placeholder="Enter completion stage...">
                            <?php echo !empty($goal['stage']) ? htmlspecialchars($goal['stage']) : ''; ?>
                          </div>
                          <input type="hidden" name="long_term_stage[]" value="<?php echo htmlspecialchars($goal['stage']); ?>">
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td class="p-2">
                        <div contenteditable="true" data-name="long_term_area[]"
                             class="table-cell-content min-h-[60px] outline-none default-text"
                             data-default="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
                          Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development
                        </div>
                        <input type="hidden" name="long_term_area[]" value="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
                      </td>
                      <td class="p-2">
                        <div contenteditable="true" data-name="long_term_activity[]"
                             class="table-cell-content min-h-[60px] outline-none default-text"
                             data-default="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
                          Pursuance of Academic Degrees for advancement, conduct of trainings/seminars
                        </div>
                        <input type="hidden" name="long_term_activity[]" value="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
                      </td>
                      <td class="p-2">
                        <input type="date" name="long_term_date[]"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print">
                        <span class="print-only"></span>
                      </td>
                      <td class="p-2">
                        <div contenteditable="true" data-name="long_term_stage[]"
                             class="table-cell-content min-h-[60px] outline-none placeholder"
                             data-placeholder="Enter completion stage..."></div>
                        <input type="hidden" name="long_term_stage[]" value="">
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="mt-4 text-right no-print">
              <button type="button" onclick="addLongTermGoal()" class="text-royal hover:text-royal-2 flex items-center text-sm">
                <i class="ri-add-circle-line mr-2"></i> Add Another Long Term Goal
              </button>
            </div>
          </div>

          <div class="mb-10">
            <div class="mb-6 no-print">
              <h4 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                <i class="ri-calendar-line mr-3 text-gold"></i>
                Short Term Development Goals (Next Year)
              </h4>
              <p class="text-gray-600">Plan your immediate development activities for the coming year</p>
            </div>

            <div class="print-only">
              <h4 class="font-bold mb-2">Short Term Development Goals Next Year</h4>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-gray-200">
              <table class="w-full development-table">
                <thead>
                  <tr>
                    <th class="p-4 font-semibold text-left area-col">Area of Development</th>
                    <th class="p-4 font-semibold text-left priority-col">Priority for Learning and Development Program (LDP)</th>
                    <th class="p-4 font-semibold text-left activity-col">Development Activity</th>
                    <th class="p-4 font-semibold text-left date-col">Target Completion Date</th>
                    <th class="p-4 font-semibold text-left responsible-col">Who is Responsible</th>
                    <th class="p-4 font-semibold text-left stage-col">Completion Stage</th>
                  </tr>
                </thead>
                <tbody id="short-term-goals-body">
                  <?php
                  $default_short_term_areas = [
                    "1. Behavioral Training such as: Value Re-orientation, Team Building, Oral Communication, Written Communication, Customer Relations, People Development, Improving Planning & Delivery, Solving Problems and making decisions, Basic Communication Training Program, etc",
                    "2. Technical Skills Training such as: Basic Occupational Safety & Health, University Safety procedures, Preventive Maintenance Activities, etc.",
                    "3. Quality Management Training such as: Customer Requirements, Time Management, Continuous Improvement for Quality & Productivity, etc",
                    "4. Others: Formal Classroom Training, on-the-job training, Self-development, developmental activities/interventions, etc."
                  ];

                  if ($is_edit && !empty($form_data['form_data']['short_term_goals'])) {
                    foreach ($form_data['form_data']['short_term_goals'] as $index => $goal) {
                      $area = !empty($goal['area']) ? $goal['area'] : ($default_short_term_areas[$index] ?? '');
                      $defaultActivity = ($index === 0) ? 'Conduct of training/seminar' : (($index === 3) ? 'Coaching on the Job-knowledge sharing and learning session' : '');
                      $activity = !empty($goal['activity']) ? $goal['activity'] : $defaultActivity;
                      ?>
                      <tr>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_area[]"
                               class="table-cell-content min-h-[60px] outline-none">
                            <?php echo htmlspecialchars($area); ?>
                          </div>
                          <input type="hidden" name="short_term_area[]" value="<?php echo htmlspecialchars($area); ?>">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_priority[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter priority...">
                            <?php echo htmlspecialchars($goal['priority']); ?>
                          </div>
                          <input type="hidden" name="short_term_priority[]" value="<?php echo htmlspecialchars($goal['priority']); ?>">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_activity[]"
                               class="table-cell-content min-h-[60px] outline-none <?php echo (empty($goal['activity']) && !empty($defaultActivity)) ? 'default-text' : ''; ?>"
                               data-default="<?php echo htmlspecialchars($defaultActivity); ?>">
                            <?php echo htmlspecialchars($activity); ?>
                          </div>
                          <input type="hidden" name="short_term_activity[]" value="<?php echo htmlspecialchars($activity); ?>">
                        </td>
                        <td class="p-2">
                          <input type="date" name="short_term_date[]"
                                 class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print"
                                 value="<?php echo htmlspecialchars($goal['target_date']); ?>">
                          <div class="print-only"><?php echo htmlspecialchars($goal['target_date']); ?></div>
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_responsible[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter responsible person...">
                            <?php echo htmlspecialchars($goal['responsible']); ?>
                          </div>
                          <input type="hidden" name="short_term_responsible[]" value="<?php echo htmlspecialchars($goal['responsible']); ?>">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_stage[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter completion stage...">
                            <?php echo htmlspecialchars($goal['stage']); ?>
                          </div>
                          <input type="hidden" name="short_term_stage[]" value="<?php echo htmlspecialchars($goal['stage']); ?>">
                        </td>
                      </tr>
                      <?php
                    }
                  } else {
                    foreach ($default_short_term_areas as $index => $area) {
                      $defaultActivity = ($index === 0) ? 'Conduct of training/seminar' : (($index === 3) ? 'Coaching on the Job-knowledge sharing and learning session' : '');
                      ?>
                      <tr>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_area[]"
                               class="table-cell-content min-h-[60px] outline-none">
                            <?php echo htmlspecialchars($area); ?>
                          </div>
                          <input type="hidden" name="short_term_area[]" value="<?php echo htmlspecialchars($area); ?>">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_priority[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter priority..."></div>
                          <input type="hidden" name="short_term_priority[]" value="">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_activity[]"
                               class="table-cell-content min-h-[60px] outline-none <?php echo !empty($defaultActivity) ? 'default-text' : ''; ?>"
                               data-default="<?php echo htmlspecialchars($defaultActivity); ?>">
                            <?php echo htmlspecialchars($defaultActivity); ?>
                          </div>
                          <input type="hidden" name="short_term_activity[]" value="<?php echo htmlspecialchars($defaultActivity); ?>">
                        </td>
                        <td class="p-2">
                          <input type="date" name="short_term_date[]"
                                 class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print">
                          <div class="print-only"></div>
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_responsible[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter responsible person..."></div>
                          <input type="hidden" name="short_term_responsible[]" value="">
                        </td>
                        <td class="p-2">
                          <div contenteditable="true" data-name="short_term_stage[]"
                               class="table-cell-content min-h-[60px] outline-none placeholder"
                               data-placeholder="Enter completion stage..."></div>
                          <input type="hidden" name="short_term_stage[]" value="">
                        </td>
                      </tr>
                      <?php
                    }
                  }
                  ?>
                </tbody>
              </table>
            </div>

            <div class="mt-4 text-right no-print">
              <button type="button" onclick="addShortTermGoal()" class="text-royal hover:text-royal-2 flex items-center text-sm">
                <i class="ri-add-circle-line mr-2"></i> Add Another Short Term Goal
              </button>
            </div>
          </div>
        </div>

        <!-- Certification and Commitment -->
        <div class="form-section">
          <div class="section-highlight mb-6 no-print">
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
              <i class="ri-file-certificate-line mr-3 text-royal"></i> Certification and Commitment
            </h3>
            <p class="text-gray-600 mt-2">Employee signature only, with reusable saved signature on the side</p>
          </div>

          <div class="print-only">
            <h3 class="text-xl font-bold mb-4">CERTIFICATION AND COMMITMENT</h3>
          </div>

          <div class="bg-gradient-to-r from-cream to-gold-soft p-8 rounded-2xl border border-gold-soft mb-8 no-print">
            <div class="text-center mb-8">
              <div class="inline-flex items-center justify-center p-3 rounded-full bg-royal/10 mb-4">
                <i class="ri-shield-check-line text-royal text-2xl"></i>
              </div>
              <p class="text-gray-700 italic">
                This is to certify that this Individual Development Plan has been discussed with me by my immediate superior. I further commit that I will exert time and effort to ensure that this will be achieved according to agreed time frames.
              </p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
              <!-- Employee Signature -->
              <div class="xl:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="text-center mb-6">
                  <div class="signature-line mb-4"></div>
                  <p class="text-sm font-medium text-gray-700">Signature of Employee</p>
                </div>

                <div class="signature-upload-container mb-4" id="employeeSignatureUpload">
                  <input type="file" id="employeeSignatureFile" accept="image/png,image/jpeg,image/jpg" class="hidden-input" onchange="handleSignatureUpload(this, 'employee')">
                  <i class="ri-upload-cloud-2-line text-4xl text-royal mb-3"></i>
                  <p class="text-gray-700 mb-2 font-medium">Upload Employee Signature</p>
                  <p class="text-sm text-gray-500 mb-4">Automatic black-lines-only cleanup with transparent background</p>

                  <button type="button" onclick="document.getElementById('employeeSignatureFile').click()" class="signature-upload-btn">
                    <i class="ri-upload-line"></i> Upload Signature
                  </button>

                  <p class="text-xs text-gray-500 mt-2">Supported: PNG, JPG, JPEG | Max: 2MB</p>

                  <div class="signature-preview mt-3" id="employeeSignaturePreview">
                    <?php if ($is_edit && !empty($form_data['form_data']['certification']['employee_signature'])): ?>
                      <img src="<?php echo htmlspecialchars($form_data['form_data']['certification']['employee_signature']); ?>" alt="Employee Signature">
                    <?php elseif ($has_saved_employee_signature): ?>
                      <img src="<?php echo htmlspecialchars($saved_employee_signature_path); ?>" alt="Saved Employee Signature">
                    <?php else: ?>
                      <span class="text-sm text-gray-400">No signature selected yet</span>
                    <?php endif; ?>
                  </div>

                  <p class="signature-note">Tip: white background will be removed and only dark signature strokes will be kept.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <input type="text" name="employee_name"
                         class="w-full px-3 py-3 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         placeholder="Printed Name"
                         value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['employee_name']) : ''; ?>">

                  <input type="date" name="employee_date"
                         class="w-full px-3 py-3 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         value="<?php echo $is_edit && !empty($form_data['form_data']['certification']['employee_date']) ? htmlspecialchars($form_data['form_data']['certification']['employee_date']) : ''; ?>">
                </div>

                <input type="hidden" id="employeeSignature" name="employee_signature"
                       value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['employee_signature'] ?? '') : ($has_saved_employee_signature ? htmlspecialchars($saved_employee_signature_path) : ''); ?>">
              </div>

              <!-- Saved Signature Side Panel -->
              <div class="saved-signature-card">
                <div class="flex items-center mb-4">
                  <div class="w-10 h-10 rounded-full bg-gold-soft flex items-center justify-center mr-3">
                    <i class="ri-folder-shared-line text-royal text-xl"></i>
                  </div>
                  <div>
                    <h4 class="font-bold text-gray-800">Saved Signature</h4>
                    <p class="text-xs text-gray-500">Reusable employee signature</p>
                  </div>
                </div>

                <div class="saved-signature-preview mb-4" id="savedEmployeeSignatureBox">
                  <?php if ($has_saved_employee_signature): ?>
                    <img src="<?php echo htmlspecialchars($saved_employee_signature_path); ?>" alt="Saved Employee Signature" id="savedEmployeeSignatureImage">
                  <?php else: ?>
                    <div class="text-center text-gray-400" id="savedEmployeeSignatureEmpty">
                      <i class="ri-image-line text-3xl block mb-2"></i>
                      <span class="text-sm">No saved signature yet</span>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="space-y-3">
                  <button type="button" class="w-full bg-royal hover:bg-royal-2 text-white py-3 rounded-xl font-medium transition-all" onclick="useSavedEmployeeSignature()">
                    <i class="ri-check-line mr-2"></i> Use Saved Signature
                  </button>

                  <button type="button" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 py-3 rounded-xl font-medium transition-all" onclick="clearEmployeeSignature()">
                    <i class="ri-close-line mr-2"></i> Clear Current Signature
                  </button>
                </div>

                <p class="text-xs text-gray-500 mt-4 leading-relaxed">
                  Kapag nag-upload ang employee ng bagong signature, automatic itong nase-save dito sa gilid para magamit ulit sa susunod.
                </p>
              </div>
            </div>

            <!-- Supervisor and Director: no signature -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-8">
              <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 text-center">
                <div class="signature-line mb-4"></div>
                <p class="text-sm font-medium text-gray-700 mb-4">Immediate Supervisor</p>

                <div class="muted-box mb-4">
                  <i class="ri-information-line text-royal mr-1"></i>
                  <span class="text-sm text-gray-600">No signature required</span>
                </div>

                <div class="space-y-4">
                  <input type="text" name="supervisor_name"
                         class="w-full px-3 py-2 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         placeholder="Printed Name"
                         value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['supervisor_name']) : ''; ?>">
                  <input type="date" name="supervisor_date"
                         class="w-full px-3 py-2 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         value="<?php echo $is_edit && !empty($form_data['form_data']['certification']['supervisor_date']) ? htmlspecialchars($form_data['form_data']['certification']['supervisor_date']) : ''; ?>">
                </div>
              </div>

              <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 text-center">
                <div class="signature-line mb-4"></div>
                <p class="text-sm font-medium text-gray-700 mb-4">Campus Director</p>

                <div class="muted-box mb-4">
                  <i class="ri-information-line text-royal mr-1"></i>
                  <span class="text-sm text-gray-600">No signature required</span>
                </div>

                <div class="space-y-4">
                  <input type="text" name="director_name"
                         class="w-full px-3 py-2 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         placeholder="Printed Name"
                         value="<?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['director_name']) : ''; ?>">
                  <input type="date" name="director_date"
                         class="w-full px-3 py-2 border-b-2 border-gray-300 bg-transparent text-center focus:border-royal focus:outline-none"
                         value="<?php echo $is_edit && !empty($form_data['form_data']['certification']['director_date']) ? htmlspecialchars($form_data['form_data']['certification']['director_date']) : ''; ?>">
                </div>
              </div>
            </div>

            <div class="mt-8 text-center">
              <div class="inline-flex items-center justify-center p-3 rounded-full bg-forest/10 mb-4">
                <i class="ri-hand-heart-line text-forest text-2xl"></i>
              </div>
              <p class="text-gray-700 italic">
                I commit to support and ensure that this agreed Individual Development Plan is achieved to the agreed time frames
              </p>
            </div>
          </div>

          <!-- Print version -->
          <div class="print-only">
            <div class="mb-6">
              <p class="mb-4">
                This is to certify that this Individual Development Plan has been discussed with me by my immediate superior. I further commit that I will exert time and effort to ensure that this will be achieved according to agreed time frames.
              </p>

              <div class="grid grid-cols-1 gap-6 mt-8">
                <div class="text-center">
                  <div class="signature-line mb-2"></div>
                  <div class="text-sm font-medium">Signature of Employee</div>
                  <div class="mt-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['employee_name']) : ''; ?></div>
                  <div class="text-sm"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['employee_date']) : ''; ?></div>
                  <?php if ($is_edit && !empty($form_data['form_data']['certification']['employee_signature'])): ?>
                    <div class="mt-2">
                      <img src="<?php echo htmlspecialchars($form_data['form_data']['certification']['employee_signature']); ?>" alt="Employee Signature" style="max-height: 60px;">
                    </div>
                  <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-6 mt-4">
                  <div class="text-center">
                    <div class="signature-line mb-2"></div>
                    <div class="text-sm font-medium">Immediate Supervisor</div>
                    <div class="mt-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['supervisor_name']) : ''; ?></div>
                    <div class="text-sm"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['supervisor_date']) : ''; ?></div>
                  </div>

                  <div class="text-center">
                    <div class="signature-line mb-2"></div>
                    <div class="text-sm font-medium">Campus Director</div>
                    <div class="mt-2"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['director_name']) : ''; ?></div>
                    <div class="text-sm"><?php echo $is_edit ? htmlspecialchars($form_data['form_data']['certification']['director_date']) : ''; ?></div>
                  </div>
                </div>
              </div>

              <div class="mt-8 text-center italic">
                I commit to support and ensure that this agreed Individual Development Plan is achieved to the agreed time frames
              </div>

              <div class="mt-8 text-center text-sm">
                LSPU-HRO-SF-027 Rev. 1 2 April 2018
              </div>
            </div>
          </div>

          <div class="flex flex-col lg:flex-row justify-between items-center pt-8 border-t border-gray-200 mt-8 no-print">
            <div class="flex space-x-4 mb-6 lg:mb-0">
              <button type="submit" name="save_draft" class="bg-gray-600 hover:bg-gray-700 text-white px-8 py-3 rounded-xl font-medium flex items-center shadow-lg hover:shadow-xl transition-all">
                <i class="ri-save-line mr-3"></i> Save as Draft
              </button>
              <button type="submit" name="submit_form" class="btn-gradient-success text-white px-8 py-3 rounded-xl font-medium flex items-center shadow-lg hover:shadow-xl transition-all">
                <i class="ri-send-plane-fill mr-3"></i> Submit to HR
              </button>
            </div>
            <div class="text-sm text-gray-600 flex flex-col lg:flex-row lg:space-x-8 text-center lg:text-left">
              <span class="font-medium">LSPU-HRD-SF-027</span>
              <span>Revision 1</span>
              <span>Effective: 2 April 2018</span>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const dropdownContainer = document.getElementById('idp-dropdown');
  if (dropdownContainer) {
    dropdownContainer.classList.add('open');
  }

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

  initializeEditableCells();

  document.querySelectorAll('.auto-expand').forEach(textarea => {
    textarea.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = (this.scrollHeight) + 'px';
    });
    textarea.dispatchEvent(new Event('input'));
  });

  document.getElementById('print-btn')?.addEventListener('click', function() {
    // Individual_Development_Plan_pdf.php reads a saved record from the
    // database by ?form_id=... (idp_personal_info/idp_purpose/etc, in
    // the nested shape those tables produce) - it was never built to
    // accept the flat, unsaved live-form JSON this button used to POST
    // to it under a completely different field name, which is why every
    // click landed on "Invalid form ID". Use the real saved id instead.
    const formIdInput = document.querySelector('input[name="form_id"]');
    const formId = formIdInput ? parseInt(formIdInput.value, 10) : 0;

    if (!formId) {
      Swal.fire({
        title: 'Save first',
        text: 'Please save this plan (Save as Draft or Submit to HR) before generating a PDF.',
        icon: 'info',
        confirmButtonColor: '#1A4B8C',
        customClass: { popup: 'rounded-2xl' }
      });
      return;
    }

    window.open(`Individual_Development_Plan_pdf.php?form_id=${formId}`, '_blank');
  });

  const form = document.getElementById('idp-form');
  if (form) {
    form.addEventListener('submit', function(e) {
      updateHiddenInputs();

      const requiredFields = form.querySelectorAll('[class*="required-field"]');
      let isValid = true;
      let firstInvalidField = null;

      requiredFields.forEach(label => {
        const fieldId = label.getAttribute('for');
        const field = document.getElementById(fieldId);
        if (field && !field.value.trim()) {
          isValid = false;
          if (!firstInvalidField) firstInvalidField = field;
          field.classList.add('border-red-500');
        }
      });

      if (!isValid) {
        e.preventDefault();
        firstInvalidField?.focus();
        Swal.fire({
          title: 'Missing Information',
          text: 'Please fill in all required fields marked with *',
          icon: 'warning',
          confirmButtonColor: '#DC3545'
        });
      }
    });
  }

  document.querySelectorAll('input, textarea').forEach(field => {
    field.addEventListener('input', function() {
      this.classList.remove('border-red-500');
    });
  });

  document.getElementById('help-btn')?.addEventListener('click', function() {
    Swal.fire({
      title: 'IDP Form Help',
      html: `
        <div class="text-left space-y-4">
          <div>
            <h4 class="font-bold text-royal">Personal Information</h4>
            <p class="text-sm text-gray-600">Fill in your current employment details. Some fields are automatically populated from your profile.</p>
          </div>
          <div>
            <h4 class="font-bold text-royal">Purpose</h4>
            <p class="text-sm text-gray-600">Select the main reason(s) for creating this development plan. You can select multiple options.</p>
          </div>
          <div>
            <h4 class="font-bold text-royal">Career Development Goals</h4>
            <p class="text-sm text-gray-600">Plan both long-term (5 years) and short-term (1 year) development activities. You can add more rows as needed.</p>
          </div>
          <div>
            <h4 class="font-bold text-royal">Certification</h4>
            <p class="text-sm text-gray-600">Employee only ang may signature. Ang uploaded signature ay nililinis para black lines lang at nase-save din for reuse.</p>
          </div>
        </div>
      `,
      icon: 'info',
      confirmButtonColor: '#1A4B8C',
      confirmButtonText: 'Got it!',
      width: '600px'
    });
  });
});

function initializeEditableCells() {
  document.querySelectorAll('.table-cell-content.default-text').forEach(cell => {
    if (cell.dataset.initialized === '1') return;
    cell.dataset.initialized = '1';

    const defaultValue = cell.getAttribute('data-default');
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
      if (hiddenInput) {
        hiddenInput.value = this.textContent.trim();
      }
    });

    cell.addEventListener('input', function() {
      if (hiddenInput) {
        hiddenInput.value = this.textContent.trim();
      }
    });
  });

  document.querySelectorAll('.table-cell-content.placeholder').forEach(cell => {
    if (cell.dataset.initialized === '1') return;
    cell.dataset.initialized = '1';

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
      if (hiddenInput) {
        hiddenInput.value = this.classList.contains('placeholder') ? '' : this.textContent.trim();
      }
    });

    cell.addEventListener('input', function() {
      if (hiddenInput) {
        hiddenInput.value = this.textContent.trim();
      }
    });
  });

  document.querySelectorAll('.table-cell-content:not(.default-text):not(.placeholder)').forEach(cell => {
    if (cell.dataset.initialized === '1') return;
    cell.dataset.initialized = '1';

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

/**
 * Convert uploaded signature image to black lines only with transparent background.
 */
function processSignatureImage(file, callback) {
  const reader = new FileReader();

  reader.onload = function(e) {
    const img = new Image();

    img.onload = function() {
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d', { willReadFrequently: true });

      canvas.width = img.width;
      canvas.height = img.height;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0);

      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const data = imageData.data;

      for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const a = data[i + 3];

        const brightness = (0.299 * r) + (0.587 * g) + (0.114 * b);

        if (brightness > 210 || a < 20) {
          data[i + 3] = 0;
        } else {
          data[i] = 0;
          data[i + 1] = 0;
          data[i + 2] = 0;

          const opacity = Math.max(80, 255 - Math.floor(brightness));
          data[i + 3] = opacity;
        }
      }

      ctx.putImageData(imageData, 0, 0);
      callback(canvas.toDataURL('image/png'));
    };

    img.src = e.target.result;
  };

  reader.readAsDataURL(file);
}

function handleSignatureUpload(input, type) {
  const file = input.files[0];
  if (!file) return;

  if (!file.type.match('image.*')) {
    Swal.fire({
      icon: 'error',
      title: 'Invalid File',
      text: 'Please upload an image file (PNG, JPG, JPEG)',
      confirmButtonColor: '#DC3545'
    });
    return;
  }

  if (file.size > 2 * 1024 * 1024) {
    Swal.fire({
      icon: 'error',
      title: 'File Too Large',
      text: 'Please upload an image smaller than 2MB',
      confirmButtonColor: '#DC3545'
    });
    return;
  }

  processSignatureImage(file, function(cleanBase64) {
    const preview = document.getElementById(type + 'SignaturePreview');
    const signatureInput = document.getElementById(type + 'Signature');
    const signatureDataInput = document.getElementById(type + '_signature_data');
    const useSavedInput = document.getElementById('use_saved_employee_signature');

    if (preview) {
      preview.innerHTML = `<img src="${cleanBase64}" alt="${type} Signature">`;
    }

    if (signatureInput) {
      signatureInput.value = cleanBase64;
    }

    if (signatureDataInput) {
      signatureDataInput.value = cleanBase64;
    }

    if (useSavedInput) {
      useSavedInput.value = '0';
    }

    Swal.fire({
      icon: 'success',
      title: 'Signature Uploaded!',
      text: 'Employee signature has been cleaned and prepared as black lines only.',
      confirmButtonColor: '#0D6B4D',
      timer: 2200,
      timerProgressBar: true
    });
  });
}

const employeeUploadContainer = document.getElementById('employeeSignatureUpload');
if (employeeUploadContainer) {
  employeeUploadContainer.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
  });

  employeeUploadContainer.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
  });

  employeeUploadContainer.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');

    const files = e.dataTransfer.files;
    if (files.length > 0) {
      const fileInput = document.getElementById('employeeSignatureFile');
      if (fileInput) {
        fileInput.files = files;
        handleSignatureUpload(fileInput, 'employee');
      }
    }
  });
}

function useSavedEmployeeSignature() {
  const savedImage = document.getElementById('savedEmployeeSignatureImage');
  const preview = document.getElementById('employeeSignaturePreview');
  const signatureInput = document.getElementById('employeeSignature');
  const signatureDataInput = document.getElementById('employee_signature_data');
  const useSavedInput = document.getElementById('use_saved_employee_signature');

  if (!savedImage) {
    Swal.fire({
      icon: 'info',
      title: 'No Saved Signature',
      text: 'Wala pang saved signature para magamit.',
      confirmButtonColor: '#1A4B8C'
    });
    return;
  }

  preview.innerHTML = `<img src="${savedImage.src}" alt="Employee Signature">`;
  signatureInput.value = savedImage.src;
  signatureDataInput.value = '';
  useSavedInput.value = '1';

  Swal.fire({
    icon: 'success',
    title: 'Saved Signature Selected',
    text: 'Gagamitin ang saved employee signature sa pag-save o submit.',
    confirmButtonColor: '#0D6B4D',
    timer: 1800,
    timerProgressBar: true
  });
}

function clearEmployeeSignature() {
  const preview = document.getElementById('employeeSignaturePreview');
  const signatureInput = document.getElementById('employeeSignature');
  const signatureDataInput = document.getElementById('employee_signature_data');
  const useSavedInput = document.getElementById('use_saved_employee_signature');
  const fileInput = document.getElementById('employeeSignatureFile');

  if (preview) {
    preview.innerHTML = `<span class="text-sm text-gray-400">No signature selected yet</span>`;
  }
  if (signatureInput) signatureInput.value = '';
  if (signatureDataInput) signatureDataInput.value = '';
  if (useSavedInput) useSavedInput.value = '0';
  if (fileInput) fileInput.value = '';

  Swal.fire({
    icon: 'success',
    title: 'Cleared',
    text: 'Current employee signature has been cleared.',
    confirmButtonColor: '#0D6B4D',
    timer: 1500,
    timerProgressBar: true
  });
}

function collectFormData() {
  updateHiddenInputs();

  return {
    name: document.getElementById('name')?.value || '',
    position: document.getElementById('position')?.value || '',
    salary_grade: document.getElementById('salary-grade')?.value || '',
    years_position: document.getElementById('years-position')?.value || '',
    years_lspu: document.getElementById('years-lspu')?.value || '',
    years_other: document.getElementById('years-other')?.value || '',
    division: document.getElementById('division')?.value || '',
    office: document.getElementById('office')?.value || '',
    address: document.getElementById('address')?.value || '',
    supervisor: document.getElementById('supervisor')?.value || '',

    purpose1: document.getElementById('purpose1')?.checked || false,
    purpose2: document.getElementById('purpose2')?.checked || false,
    purpose3: document.getElementById('purpose3')?.checked || false,
    purpose4: document.getElementById('purpose4')?.checked || false,
    purpose5: document.getElementById('purpose5')?.checked || false,
    purpose_other: document.getElementById('purpose-other')?.value || '',

    long_term_area: Array.from(document.querySelectorAll('input[name="long_term_area[]"]')).map(el => el.value),
    long_term_activity: Array.from(document.querySelectorAll('input[name="long_term_activity[]"]')).map(el => el.value),
    long_term_date: Array.from(document.querySelectorAll('input[name="long_term_date[]"]')).map(el => el.value),
    long_term_stage: Array.from(document.querySelectorAll('input[name="long_term_stage[]"]')).map(el => el.value),

    short_term_area: Array.from(document.querySelectorAll('input[name="short_term_area[]"]')).map(el => el.value),
    short_term_priority: Array.from(document.querySelectorAll('input[name="short_term_priority[]"]')).map(el => el.value),
    short_term_activity: Array.from(document.querySelectorAll('input[name="short_term_activity[]"]')).map(el => el.value),
    short_term_date: Array.from(document.querySelectorAll('input[name="short_term_date[]"]')).map(el => el.value),
    short_term_responsible: Array.from(document.querySelectorAll('input[name="short_term_responsible[]"]')).map(el => el.value),
    short_term_stage: Array.from(document.querySelectorAll('input[name="short_term_stage[]"]')).map(el => el.value),

    employee_name: document.querySelector('input[name="employee_name"]')?.value || '',
    employee_date: document.querySelector('input[name="employee_date"]')?.value || '',
    employee_signature: document.getElementById('employeeSignature')?.value || '',
    supervisor_name: document.querySelector('input[name="supervisor_name"]')?.value || '',
    supervisor_date: document.querySelector('input[name="supervisor_date"]')?.value || '',
    director_name: document.querySelector('input[name="director_name"]')?.value || '',
    director_date: document.querySelector('input[name="director_date"]')?.value || ''
  };
}

function updateHiddenInputs() {
  document.querySelectorAll('.table-cell-content').forEach(cell => {
    const hiddenInput = cell.parentElement.querySelector('input[type="hidden"]');
    if (hiddenInput) {
      if (cell.classList.contains('placeholder')) {
        hiddenInput.value = '';
      } else {
        hiddenInput.value = cell.textContent.trim();
      }
    }
  });
}

function addLongTermGoal() {
  const tableBody = document.getElementById('long-term-goals-body');
  const newRow = document.createElement('tr');
  newRow.innerHTML = `
    <td class="p-2">
      <div contenteditable="true" data-name="long_term_area[]"
           class="table-cell-content min-h-[60px] outline-none default-text"
           data-default="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
        Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development
      </div>
      <input type="hidden" name="long_term_area[]" value="Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development">
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="long_term_activity[]"
           class="table-cell-content min-h-[60px] outline-none default-text"
           data-default="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
        Pursuance of Academic Degrees for advancement, conduct of trainings/seminars
      </div>
      <input type="hidden" name="long_term_activity[]" value="Pursuance of Academic Degrees for advancement, conduct of trainings/seminars">
    </td>
    <td class="p-2">
      <input type="date" name="long_term_date[]"
             class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print">
      <span class="print-only"></span>
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="long_term_stage[]"
           class="table-cell-content min-h-[60px] outline-none placeholder"
           data-placeholder="Enter completion stage..."></div>
      <input type="hidden" name="long_term_stage[]" value="">
    </td>
  `;
  tableBody.appendChild(newRow);
  initializeEditableCells();
}

function addShortTermGoal() {
  const tableBody = document.getElementById('short-term-goals-body');
  const newRow = document.createElement('tr');
  newRow.innerHTML = `
    <td class="p-2">
      <div contenteditable="true" data-name="short_term_area[]"
           class="table-cell-content min-h-[60px] outline-none"></div>
      <input type="hidden" name="short_term_area[]" value="">
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="short_term_priority[]"
           class="table-cell-content min-h-[60px] outline-none placeholder"
           data-placeholder="Enter priority..."></div>
      <input type="hidden" name="short_term_priority[]" value="">
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="short_term_activity[]"
           class="table-cell-content min-h-[60px] outline-none"></div>
      <input type="hidden" name="short_term_activity[]" value="">
    </td>
    <td class="p-2">
      <input type="date" name="short_term_date[]"
             class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-royal focus:outline-none no-print">
      <div class="print-only"></div>
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="short_term_responsible[]"
           class="table-cell-content min-h-[60px] outline-none placeholder"
           data-placeholder="Enter responsible person..."></div>
      <input type="hidden" name="short_term_responsible[]" value="">
    </td>
    <td class="p-2">
      <div contenteditable="true" data-name="short_term_stage[]"
           class="table-cell-content min-h-[60px] outline-none placeholder"
           data-placeholder="Enter completion stage..."></div>
      <input type="hidden" name="short_term_stage[]" value="">
    </td>
  `;
  tableBody.appendChild(newRow);
  initializeEditableCells();
}
</script>
</body>
</html>