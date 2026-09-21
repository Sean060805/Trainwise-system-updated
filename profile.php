<?php
session_start();
require_once 'config.php';

// 2026-09-03 fix - Sign Out (href="?logout=1" below) only ever cleared
// browser localStorage/sessionStorage via JS and redirected - it never
// actually destroyed the PHP session server-side, so the account
// stayed logged in underneath. Matches the pattern already used by
// user_page.php/the college dashboards (a self-contained
// $_GET['logout'] check on the same page).
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Set upload directory path
$upload_dir = 'uploads/profile_images/';

// Create uploads directory if not exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Initialize variables
$update_success = false;
$update_error = '';
$first_name = '';
$last_name = '';
$educ = '';
$spec = '';
$desig = '';
$dept = '';
$years = '';
$teach = '';
$profile_image = '';

// Handle profile update form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureUserNamePartsColumns($con);
    $first_name = trim($_POST['first_name'] ?? '');
    // 2026-09-09 - was missing entirely; real LSPU names follow a "First
    // MI. Last" convention (see config.php's buildFullName() comment).
    $middle_initial = trim($_POST['middle_initial'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $educ = trim($_POST['educationalAttainment'] ?? '');
    $spec = trim($_POST['specialization'] ?? '');
    $desig = trim($_POST['designation'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $years = trim($_POST['yearsInLSPU'] ?? '');
    $teach = trim($_POST['teaching_status'] ?? '');
    $full_name = buildFullName($first_name, $middle_initial, $last_name);
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($educ) || empty($spec) || empty($desig) || empty($dept) || empty($years) || empty($teach)) {
        $update_error = "All fields are required.";
    } else {
        // Get current profile image
        $current_image = '';
        $stmt = $con->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($current_image);
        $stmt->fetch();
        $stmt->close();
        
        // Handle image upload
        $profile_image = $current_image; // Keep current image by default
        // 2026-09-17 fix - ISO 25010 audit found the old image used to be
        // deleted immediately after move_uploaded_file() succeeded, before
        // the UPDATE below was even attempted. If that UPDATE then failed,
        // the DB row was left pointing at a now-deleted file. Matches
        // upload_training_proof.php's safer order now: hold the old path
        // and only delete it after the DB write actually succeeds.
        $oldImageToDelete = null;

        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            $file_type = mime_content_type($_FILES['profile_image']['tmp_name']);
            $file_ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));

            if (!in_array($file_type, $allowed_types)) {
                $update_error = "Only JPG, PNG, or GIF images are allowed.";
            } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                $update_error = "Image size must be less than 2MB.";
            } else {
                // Generate unique filename
                $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;

                // Move uploaded file
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                    if (!empty($current_image) && $current_image != 'noprofile.jpg' && file_exists($upload_dir . $current_image)) {
                        $oldImageToDelete = $upload_dir . $current_image;
                    }
                    $profile_image = $new_filename;
                } else {
                    $update_error = "Failed to upload image.";
                }
            }
        }

        if (empty($update_error)) {
            // Update user profile
            $stmt = $con->prepare("UPDATE users SET name=?, first_name=?, middle_initial=?, last_name=?, educationalAttainment=?, specialization=?, designation=?, department=?, yearsInLSPU=?, teaching_status=?, profile_image=? WHERE id=?");
            $stmt->bind_param("sssssssssssi", $full_name, $first_name, $middle_initial, $last_name, $educ, $spec, $desig, $dept, $years, $teach, $profile_image, $user_id);

            if ($stmt->execute()) {
                $update_success = true;

                // Only remove the old file now that the DB write it depends
                // on has actually succeeded.
                if ($oldImageToDelete !== null) {
                    @unlink($oldImageToDelete);
                }

                // Update session data
                $_SESSION['profile_name'] = $full_name;
                $_SESSION['profile_first_name'] = $first_name;
                $_SESSION['profile_last_name'] = $last_name;
                $_SESSION['profile_educationalAttainment'] = $educ;
                $_SESSION['profile_specialization'] = $spec;
                $_SESSION['profile_designation'] = $desig;
                $_SESSION['profile_department'] = $dept;
                $_SESSION['profile_yearsInLSPU'] = $years;
                $_SESSION['profile_teaching_status'] = $teach;
                $_SESSION['profile_image'] = $profile_image;

                // Set tracking for years increment
                $_SESSION['profile_yearInLSPU_set'] = date('Y');

                // 2026-09-16: used to redirect back to profile.php?success=1
                // and render its own toast - now lands the user straight on
                // the dashboard instead, per the requested flow. user_page.php
                // already has a ?profile_updated=1 handler for this (was
                // dead/unused until now - nothing else ever set this param).
                header("Location: user_page.php?profile_updated=1");
                exit;
            } else {
                $update_error = "Error updating profile: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Load user data from database
ensureUserNamePartsColumns($con);
$stmt = $con->prepare("SELECT name, first_name, middle_initial, last_name, educationalAttainment, specialization, designation, department, yearsInLSPU, teaching_status, profile_image FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $name = $row['name'] ?? '';
    $educ = $row['educationalAttainment'] ?? '';
    $spec = $row['specialization'] ?? '';
    $desig = $row['designation'] ?? '';
    $dept = $row['department'] ?? '';
    $years = $row['yearsInLSPU'] ?? '';
    $teach = $row['teaching_status'] ?? '';
    $profile_image = $row['profile_image'];

    // 2026-09-09 - read the real columns directly now instead of
    // exploding `name` on the first space, which mis-split any real
    // "First MI. Last" name (e.g. "Dexter C. Cosme" -> first="Dexter",
    // last="C. Cosme"). Fallback to the old explode() only for a legacy
    // row somehow missing these (shouldn't happen post-backfill, but
    // cheap insurance rather than showing a blank field).
    if (!empty($row['first_name']) || !empty($row['last_name'])) {
        $first_name = $row['first_name'] ?? '';
        $middle_initial = $row['middle_initial'] ?? '';
        $last_name = $row['last_name'] ?? '';
    } else {
        $name_parts = explode(' ', $name, 2);
        $first_name = $name_parts[0] ?? '';
        $middle_initial = '';
        $last_name = $name_parts[1] ?? '';
    }

    // Update session data
    $_SESSION['profile_name'] = $name;
    $_SESSION['profile_first_name'] = $first_name;
    $_SESSION['profile_last_name'] = $last_name;
    $_SESSION['profile_educationalAttainment'] = $educ;
    $_SESSION['profile_specialization'] = $spec;
    $_SESSION['profile_designation'] = $desig;
    $_SESSION['profile_department'] = $dept;
    $_SESSION['profile_yearsInLSPU'] = $years;
    $_SESSION['profile_teaching_status'] = $teach;
    $_SESSION['profile_image'] = $profile_image;
    
    // Set tracking for years increment if not set
    if (!isset($_SESSION['profile_yearInLSPU_set']) && is_numeric($years)) {
        $_SESSION['profile_yearInLSPU_set'] = date('Y');
    }
} else {
    // Default values if no data found
    $first_name = '';
    $middle_initial = '';
    $last_name = '';
    $educ = '';
    $spec = '';
    $desig = '';
    $dept = '';
    $years = '';
    $teach = '';
    $profile_image = '';
}
$stmt->close();

// Compute auto-increased Years in LSPU
$current_year = date('Y');
$year_set = $_SESSION['profile_yearInLSPU_set'] ?? $current_year;
$computed_years = is_numeric($years) ? ((int)$years + ($current_year - (int)$year_set)) : '';

// 2026-09-03 fix - the sidebar's "Take Assessment" link (which every
// other page - user_page.php, training_recommendations.php - shows
// whenever the profile is complete, there's an open deadline, and
// nothing's submitted yet for it) was simply missing from this page's
// sidebar entirely, along with the $hasProfile/$hasDeadline/
// $hasSubmitted computation it depends on - this page never had any of
// that logic. Mirrors training_recommendations.php's exact computation
// (same required-fields list, same "latest active deadline" query,
// same submission check) so the link shows/hides consistently no
// matter which page the employee is currently on.
$hasProfile = !empty(trim($name ?? '')) && !empty(trim($educ)) && !empty(trim($spec))
    && !empty(trim($desig)) && !empty(trim($dept)) && !empty(trim((string)$years)) && !empty(trim($teach));

// 2026-09-03 - percentage version of the same 7-field check above, for
// the Quick Status card's progress bar (matches training_recommendations.php).
$requiredProfileFields = [$name ?? '', $educ, $spec, $desig, $dept, (string)$years, $teach];
$completedProfileFields = count(array_filter($requiredProfileFields, fn($v) => !empty(trim($v))));
$profileCompletionPercentage = round(($completedProfileFields / count($requiredProfileFields)) * 100);

// 2026-09-15 - per-field emptiness, computed once from the values as
// loaded from the DB (not re-evaluated on every keystroke), so the
// server can (a) pre-select which fields start with the red "needs
// input" styling, and (b) decide the one-time layout split below -
// still-empty required fields render in their own group after the
// filled ones, instead of interleaved, so a respondent can see at a
// glance what's left. JS keeps the live version of this in sync as
// they type; this is only the initial snapshot.
$fieldIsEmpty = [
    'first_name' => empty(trim($first_name ?? '')),
    'last_name' => empty(trim($last_name ?? '')),
    'educationalAttainment' => empty(trim($educ ?? '')),
    'specialization' => empty(trim($spec ?? '')),
    'designation' => empty(trim($desig ?? '')),
    'department' => empty(trim($dept ?? '')),
    'yearsInLSPU' => empty(trim((string)$years)),
    'teaching_status' => empty(trim($teach ?? '')),
];

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
    error_log("profile.php: assessment-button status check failed: " . $e->getMessage());
}
$showAssessmentButton = $hasProfile && $hasDeadline && !$hasSubmitted;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Profile | Training Needs Assessment</title>
  <!-- cache-busted with the bundle's own mtime, see user_page.php's
       tw-46.css link for why this matters. -->
  <link rel="stylesheet" href="assets/css/tw-44.css?v=<?= @filemtime(__DIR__ . '/assets/css/tw-44.css') ?: time() ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
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

    /* 2026-09-16: switched from Poppins to Inter to match user_page.php
       and index.php - a user visibly saw the font change moving between
       the profile-gate flow and the dashboard in the same session. */
    * {
      font-family: 'Inter', sans-serif;
    }

    body {
      background: linear-gradient(135deg, #FDF8F0 0%, #F5EDDF 100%);
      min-height: 100vh;
    }

    .sidebar-gradient {
      background:
        radial-gradient(circle at 85% 0%, rgba(212,168,67,0.22), transparent 55%),
        linear-gradient(180deg, #1A4B8C 0%, #0F3460 100%);
    }

    .sidebar-fixed {
      position: fixed;
      left: 0;
      top: 0;
      height: 100vh;
      /* 2026-09-03 fix - see the matching comment in user_page.php:
         100vh overshoots the real visible area on mobile once the
         browser's own chrome is accounted for, pushing Sign Out below
         the fold. 100dvh tracks the real viewport. */
      height: 100dvh;
      width: 16rem;
      overflow-y: auto;
      overflow-x: hidden;
      -webkit-overflow-scrolling: touch;
      z-index: 50;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
    }

    .main-content {
      margin-left: 16rem;
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      position: relative;
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
      .sidebar-fixed {
        transform: translateX(-100%);
        transition: transform 0.28s ease;
      }
      .sidebar-fixed.mobile-open {
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

    .card-gradient {
      background: linear-gradient(145deg, #ffffff 0%, #FDF8F0 100%);
      border: 1px solid #E8DDD0;
    }

    .profile-gradient {
      background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
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

    html {
      scroll-behavior: smooth;
    }
    
    ::-webkit-scrollbar {
      width: 8px;
    }
    
    ::-webkit-scrollbar-track {
      background: #F5EDDF;
      border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%);
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

    .btn-royal {
      background: linear-gradient(135deg, #1A4B8C 0%, #0F3460 100%);
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(26, 75, 140, 0.4);
    }

    .btn-royal:hover {
      background: linear-gradient(135deg, #0F3460 0%, #0A2345 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(26, 75, 140, 0.5);
    }

    .btn-forest {
      background: linear-gradient(135deg, #0D6B4D 0%, #084A34 100%);
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(13, 107, 77, 0.4);
    }

    .btn-forest:hover {
      background: linear-gradient(135deg, #084A34 0%, #063A28 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(13, 107, 77, 0.5);
    }

    .btn-gold {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(212, 168, 67, 0.4);
    }

    .btn-gold:hover {
      background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(212, 168, 67, 0.5);
    }

    .form-input {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1px solid #e5e7eb;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      transition: all 0.3s ease;
      background: #FDF8F0;
    }

    .form-input:focus {
      border-color: #1A4B8C;
      /* 2026-09-16: widened to match index.php's soft-glow focus
         convention (4px / 0.12) exactly, was a slightly weaker 3px/0.1. */
      box-shadow: 0 0 0 4px rgba(26, 75, 140, 0.12);
      outline: none;
      background: #ffffff;
    }

    /* 2026-09-16: Save/Cancel bar stays pinned to the bottom of
       .main-content (which is its own scroll container - height:100vh;
       overflow-y:auto; above) so it never requires scrolling a long form
       to reach, instead of sitting in normal flow at the very end. */
    #actionButtons {
      position: sticky;
      bottom: 0;
      background: #FDF8F0;
      padding-top: 1.25rem;
      padding-bottom: 1.25rem;
      z-index: 10;
      box-shadow: 0 -4px 16px -8px rgba(15, 24, 48, 0.15);
    }

    .form-input:disabled,
    .form-input[readonly] {
      background: #FDF8F0;
      cursor: default;
    }

    .form-input:disabled:hover,
    .form-input[readonly]:hover {
      background: #FDF8F0;
    }

    /* 2026-09-15 - real TAM testers (20 respondents) reported that once
       editing is on, an empty required field looks identical to a filled
       one - nothing told them which boxes still needed input. Placed
       after the disabled/readonly rules above so it wins the cascade
       tie even on a field that's still technically disabled (e.g. during
       the brief pre-auto-edit render). Red (not the earlier gold) per
       follow-up feedback - matches this page's existing validation-error
       color (border-red-500 in validateForm()) instead of introducing a
       second "something's wrong" color. */
    .form-input.field-empty,
    .form-input.field-empty:disabled,
    .form-input.field-empty[readonly] {
      background: #FEF2F2;
      border-color: #EF4444;
      box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.4);
    }

    .form-input.field-empty::placeholder {
      color: #DC2626;
    }
  </style>
</head>

<body class="bg-gray-50">
<button id="mobileMenuBtn" class="mobile-menu-btn" aria-label="Open menu"><i class="ri-menu-line"></i></button>
<div id="mobileBackdrop" class="mobile-backdrop"></div>
<!-- Sidebar -->
<aside id="sidebarFixed" class="sidebar-fixed sidebar-gradient text-white shadow-xl flex flex-col justify-between">
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

    <!-- Navigation -->
    <nav class="flex-1 px-4 py-6 space-y-1">
      <a href="user_page.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-dashboard-line text-lg"></i>
        </div>
        Dashboard
      </a>
      
      <!-- IDP Forms -->
      <div class="group">
        <button id="idp-dropdown-btn" class="nav-item flex items-center justify-between w-full px-4 py-3 text-sm font-medium rounded-lg hover:bg-blue-700/50 transition-all">
          <div class="flex items-center">
            <div class="w-6 h-6 flex items-center justify-center mr-3">
              <i class="ri-file-text-line text-lg"></i>
            </div>
            IDP Forms
          </div>
          <i class="ri-arrow-down-s-line transition-transform duration-300 group-[.open]:rotate-180"></i>
        </button>
        
        <div id="idp-dropdown-menu" class="hidden pl-10 mt-1 space-y-1 group-[.open]:block">
          <a href="Individual_Development_Plan.php" class="nav-item flex items-center px-4 py-2.5 text-sm rounded-lg hover:bg-blue-700/30 transition-all">
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

      <a href="profile.php" class="nav-item active flex items-center px-4 py-3 text-sm font-medium rounded-lg bg-blue-700/50 hover:bg-blue-700/70 transition-all">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-user-line text-lg"></i>
        </div>
        Profile
        <i class="ri-arrow-right-s-line ml-auto"></i>
      </a>
    </nav>

    <!-- Quick Status - 2026-09-03 fix: was only on user_page.php/
         training_recommendations.php, missing here and on both IDP
         pages, which read as inconsistent (it would show, then vanish,
         depending which page you were on). Ported from
         training_recommendations.php's version (the simpler one - no
         notification-count row, which this page has no data for). -->
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

    <!-- User Info & Logout -->
    <div class="p-4 border-t border-blue-800/30">
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
            <p class="text-sm font-medium text-white">
              <?= htmlspecialchars($first_name . ' ' . $last_name) ?>
            </p>
            <p class="text-xs text-blue-300" style="color:#E8C96E;">
              <?= htmlspecialchars($desig ?: 'Staff') ?>
            </p>
          </div>
        </div>
        <a href="?logout=1" class="p-2 rounded-lg hover:bg-red-600/20 text-red-100 border border-red-500/20 transition-all">
          <i class="ri-logout-box-line"></i>
        </a>
      </div>
    </div>
  </div>
</aside>

<!-- Main Content -->
<main class="main-content">
  <div class="p-6 md:p-8">
    <!-- Header -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
        <div>
          <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2" style="background:linear-gradient(135deg,#1A4B8C,#0F3460); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;">
            My Profile
          </h1>
          <p class="text-gray-600 flex items-center">
            <i class="ri-user-line mr-2" style="color:#B8922A;"></i>
            Manage your personal information
          </p>
        </div>
      </div>
    </div>

    <!-- Profile Form -->
    <div class="card-gradient rounded-2xl shadow-custom p-6 md:p-8">
      <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
        <div>
          <h2 class="text-xl md:text-2xl font-bold text-gray-800 mb-2 flex items-center">
            <i class="ri-user-settings-line text-royal mr-3 text-xl"></i>
            Personal Information
          </h2>
          <p class="text-gray-600">Update your profile details below</p>
        </div>
        
        <button
          id="editButton"
          type="button"
          class="px-6 py-2.5 btn-royal rounded-xl font-medium flex items-center gap-2"
        >
          <i class="ri-edit-line"></i> Edit Profile
        </button>
      </div>

      <form id="profileForm" class="space-y-6" method="POST" action="" enctype="multipart/form-data">
        <!-- Profile Image and Form Fields -->
        <div class="flex flex-col lg:flex-row gap-8">
          <!-- Profile Picture -->
          <div class="w-full lg:w-1/3">
            <div class="relative group">
              <div class="relative overflow-hidden rounded-2xl border-2 border-gold-soft w-full aspect-square">
                <?php
                  $mainImageSrc = 'images/noprofile.jpg';
                  if (!empty($profile_image)) {
                      $full_path = $upload_dir . $profile_image;
                      if (file_exists($full_path)) {
                          $mainImageSrc = $full_path;
                      }
                  }
                ?>
                <img 
                  id="profileImage" 
                  src="<?= htmlspecialchars($mainImageSrc) ?>" 
                  alt="Profile Picture" 
                  class="w-full h-full object-cover"
                  onerror="this.onerror=null;this.src='images/noprofile.jpg';"
                />
                <div id="changePhotoOverlay" class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center cursor-pointer">
                  <span class="text-white font-medium">Change Photo</span>
                </div>
              </div>
              <input id="imageInput" type="file" name="profile_image" accept="image/*" class="hidden" />
              <button 
                id="uploadButton" 
                type="button" 
                class="mt-4 w-full px-4 py-2 btn-gold rounded-lg font-medium flex items-center justify-center gap-2 hidden"
              >
                <i class="ri-upload-line"></i> Upload New Photo
              </button>
              <p class="text-xs text-gray-500 mt-2 text-center">JPG, PNG or GIF. Max size 2MB</p>
            </div>
          </div>

          <!-- Form Fields -->
          <div class="flex-1">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- First Name -->
              <div data-field="first_name">
                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">First Name <span class="req-ast text-red-500" id="ast-first_name" style="display:<?= $fieldIsEmpty['first_name'] ? 'inline' : 'none' ?>">*</span></label>
                <input
                  id="first_name"
                  name="first_name" 
                  type="text" 
                  value="<?= htmlspecialchars($first_name) ?>" 
                  readonly 
                  class="form-input"
                  required
                />
              </div>
              
              <!-- Middle Initial -->
              <div>
                <label for="middle_initial" class="block text-sm font-medium text-gray-700 mb-2">Middle Initial</label>
                <input
                  id="middle_initial"
                  name="middle_initial"
                  type="text"
                  value="<?= htmlspecialchars($middle_initial) ?>"
                  readonly
                  class="form-input"
                  maxlength="5"
                />
              </div>

              <!-- Last Name -->
              <div data-field="last_name">
                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">Last Name <span class="req-ast text-red-500" id="ast-last_name" style="display:<?= $fieldIsEmpty['last_name'] ? 'inline' : 'none' ?>">*</span></label>
                <input
                  id="last_name"
                  name="last_name"
                  type="text"
                  value="<?= htmlspecialchars($last_name) ?>"
                  readonly
                  class="form-input"
                  required
                />
              </div>

              <!-- Educational Attainment -->
              <div data-field="educationalAttainment">
                <label for="educationalAttainment" class="block text-sm font-medium text-gray-700 mb-2">Educational Attainment <span class="req-ast text-red-500" id="ast-educationalAttainment" style="display:<?= $fieldIsEmpty['educationalAttainment'] ? 'inline' : 'none' ?>">*</span></label>
                <select 
                  id="educationalAttainment" 
                  name="educationalAttainment" 
                  disabled
                  class="form-input"
                  required
                >
                  <option value="">Select Educational Attainment</option>
                  <?php
                    $educOptions = [
                      "Doctorate Degree (Completed)",
                      "Doctorate Degree – With Complete Academic Requirements (CAR)",
                      "Doctorate Degree – With Units Earned",
                      "Master's Degree (Completed)",
                      "Master's Degree – With Complete Academic Requirements (CAR)",
                      "Master's Degree – With Units Earned",
                      "Bachelor's Degree",
                      "Bachelor's Degree – With Units Earned",
                      "Associate Degree",
                      "Senior High School Graduate",
                      "High School Graduate",
                      "Vocational/Technical Graduate",
                      "Currently Enrolled in Graduate Studies"
                    ];
                    foreach ($educOptions as $option) {
                      $selected = ($educ ?? '') === $option ? 'selected' : '';
                      echo "<option value=\"" . htmlspecialchars($option) . "\" $selected>" . htmlspecialchars($option) . "</option>";
                    }
                  ?>
                </select>
              </div>
              
              <!-- Specialization -->
              <div data-field="specialization">
                <label for="specialization" class="block text-sm font-medium text-gray-700 mb-2">Specialization <span class="req-ast text-red-500" id="ast-specialization" style="display:<?= $fieldIsEmpty['specialization'] ? 'inline' : 'none' ?>">*</span></label>
                <input 
                  id="specialization" 
                  name="specialization" 
                  type="text" 
                  value="<?= htmlspecialchars($spec) ?>" 
                  readonly 
                  class="form-input"
                  required
                />
              </div>
              
              <!-- Designation / Position -->
              <div data-field="designation">
                <label for="designationSelect" class="block text-sm font-medium text-gray-700 mb-2">Designation / Position <span class="req-ast text-red-500" id="ast-designation" style="display:<?= $fieldIsEmpty['designation'] ? 'inline' : 'none' ?>">*</span></label>
                <?php
                  // 2026-09-03 - was free text; converted to a dropdown of
                  // the real CHED/SUC academic rank ladder (see
                  // index.php's $facultyRanks for the source and the real
                  // TAM feedback behind it).
                  //
                  // 2026-09-06 - a real non-teaching employee (Accounting/
                  // Budget Office) hit this dropdown and found every single
                  // option was a faculty rank (Instructor -> Professor VI)
                  // with nothing applicable to her - the only way through
                  // was the buried "Other" free-text fallback, off-screen
                  // below Professor VI. Added a second optgroup with LSPU's
                  // actual non-teaching position list (given directly by
                  // the adviser, sourced from LSPU LB) - same "Colleges /
                  // Offices" optgroup pattern already used for Department
                  // just below. "Other" kept as the fallback for both.
                  // 2026-09-17 - "Part-Time Instructor" added below the
                  // full-time Instructor ranks. Real feedback from the
                  // first ~20 testers: most of them are part-time and had
                  // no matching option here, so they were falling through
                  // to the buried "Other" free-text fallback for what is
                  // actually a common, real designation at LSPU.
                  $facultyRanks = [
                    'Instructor I', 'Instructor II', 'Instructor III',
                    'Part-Time Instructor',
                    'Assistant Professor I', 'Assistant Professor II', 'Assistant Professor III', 'Assistant Professor IV',
                    'Associate Professor I', 'Associate Professor II', 'Associate Professor III', 'Associate Professor IV', 'Associate Professor V',
                    'Professor I', 'Professor II', 'Professor III', 'Professor IV', 'Professor V', 'Professor VI',
                  ];
                  $nonTeachingPositions = [
                    'Head',
                    'Administrative Officer I', 'Administrative Officer II', 'Administrative Officer III', 'Administrative Officer IV', 'Administrative Officer V',
                    'Administrative Aide I', 'Administrative Aide II', 'Administrative Aide III', 'Administrative Aide IV', 'Administrative Aide V', 'Administrative Aide VI',
                    'Staff',
                  ];
                  $isCustomDesig = ($desig !== '' && !in_array($desig, $facultyRanks, true) && !in_array($desig, $nonTeachingPositions, true));
                ?>
                <select
                  id="designationSelect"
                  disabled
                  class="form-input"
                  required
                >
                  <option value="">Select Designation / Position</option>
                  <optgroup label="Teaching Staff">
                    <?php foreach ($facultyRanks as $rank): ?>
                      <option value="<?= htmlspecialchars($rank) ?>" <?= $desig === $rank ? 'selected' : '' ?>><?= htmlspecialchars($rank) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                  <optgroup label="Non-Teaching Staff">
                    <?php foreach ($nonTeachingPositions as $pos): ?>
                      <option value="<?= htmlspecialchars($pos) ?>" <?= $desig === $pos ? 'selected' : '' ?>><?= htmlspecialchars($pos) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                  <option value="OTHER" <?= $isCustomDesig ? 'selected' : '' ?>>Other (not listed above)</option>
                </select>
                <input
                  type="text"
                  id="designationOtherInput"
                  placeholder="Type your designation/position"
                  value="<?= $isCustomDesig ? htmlspecialchars($desig) : '' ?>"
                  disabled
                  class="form-input mt-2 <?= $isCustomDesig ? '' : 'hidden' ?>"
                />
                <input type="hidden" id="designation" name="designation" value="<?= htmlspecialchars($desig) ?>" />
              </div>

              <!-- Department -->
              <div data-field="department">
                <label for="departmentSelect" class="block text-sm font-medium text-gray-700 mb-2">Department <span class="req-ast text-red-500" id="ast-department" style="display:<?= $fieldIsEmpty['department'] ? 'inline' : 'none' ?>">*</span></label>
                <?php
                  $deptOptions = [
                    "CA" => "College of Agriculture (CA)",
                    "CAS" => "College of Arts and Sciences (CAS)",
                    "CBAA" => "College of Business, Administration and Accountancy (CBAA)",
                    "CCS" => "College of Computer Studies (CCS)",
                    "CCJE" => "College of Criminal Justice Education (CCJE)",
                    "COE" => "College of Engineering (COE)",
                    "CIT" => "College of Industrial Technology (CIT)",
                    "CFND" => "College of Food, Nutrition and Dietetics (CFND)",
                    "COF" => "College of Fisheries (COF)",
                    // 2026-09-17 fix - this dropdown used to offer "CIHTM"
                    // and "CHMT" as two separate colleges with two
                    // different full names, for what is really the same
                    // one college. The dean account for this college was
                    // already set up as department='CHMT' (role
                    // admin_chmt) before this was noticed, and that role
                    // string is derived from this exact code in several
                    // files (reject/forward/report_training_demand.php,
                    // training_pipeline.php), so the code itself is left
                    // as 'CHMT' rather than risk breaking that dean's
                    // access - only the label shown to users is corrected
                    // to the college's real name and acronym.
                    "CHMT" => "College of International Hospitality and Tourism Management (CIHTM)",
                    "CTE" => "College of Teacher Education (CTE)",
                    "CONAH" => "College of Nursing and Allied Health (CONAH)",
                    "COL" => "College of Law (COL)"
                  ];
                  // 2026-09-02 - non-teaching staff used to be forced into a
                  // free-text "Other" box for their office, which was error
                  // prone (typos meant they'd never match anything downstream).
                  // Now offered a real dropdown too, same UX as the college
                  // list above. Keep this in sync with $nonTeachingOfficeAliases
                  // in admin_page.php and TNA_COLLEGE_CODE_MAP in
                  // ml_recommendations.php - all three must agree on the exact
                  // literal strings.
                  // 2026-09-07 - replaced the original 5-office placeholder
                  // list with the real 13 non-teaching offices, given
                  // directly by HR (Dr. Imee Prescilla P. Sanchez, HRMO).
                  // The old 5 are NOT removed from the classification arrays
                  // in admin_page.php/ml_recommendations.php/features.py -
                  // only from this dropdown - so any existing account still
                  // holding one of the old placeholder values keeps
                  // resolving correctly instead of silently breaking.
                  $officeOptions = [
                    "Office of the Campus Director" => "Office of the Campus Director",
                    "Guidance Counselor" => "Guidance Counselor",
                    "Disbursing and Cashiering" => "Disbursing and Cashiering",
                    "Records Office" => "Records Office",
                    "General Services Unit" => "General Services Unit",
                    "Library Services" => "Library Services",
                    "Supply" => "Supply",
                    "Admission and Registrarship" => "Admission and Registrarship",
                    "Accounting Office" => "Accounting Office",
                    "Budget and Finance" => "Budget and Finance",
                    "Human Resource Management" => "Human Resource Management",
                    "Medical and Dental Services" => "Medical and Dental Services",
                    "Procurement" => "Procurement",
                  ];
                  // 2026-09-03 fix - users.department is inconsistently
                  // populated across this whole codebase (documented
                  // elsewhere as a known issue, e.g. canonicalTnaCollegeCode()
                  // in ml_recommendations.php exists specifically to handle
                  // it) - some accounts have the short code ("CCS"), others
                  // have the full college name ("College of Computer
                  // Studies"). This dropdown used to only ever match the
                  // short code, so a real employee with the full name
                  // stored got dumped into "Other" with their college name
                  // sitting in the free-text box, even though it's a
                  // perfectly real, listed college. Build a normalized
                  // (lowercased) full-name -> code lookup straight from
                  // $deptOptions's own labels and resolve $dept through it
                  // before deciding what to pre-select - purely a display/
                  // pre-selection fix, doesn't touch what's actually stored
                  // unless the employee re-saves (at which point it
                  // naturally normalizes to the short code, since that's
                  // what the now-correctly-selected option's value is).
                  $deptFullNameToCode = [];
                  foreach ($deptOptions as $code => $label) {
                    $fullName = trim(preg_replace('/\s*\(' . preg_quote($code, '/') . '\)\s*$/', '', $label));
                    $deptFullNameToCode[strtolower($fullName)] = $code;
                  }
                  // 2026-09-17 - the removed "CIHTM" option above (see the
                  // note there) means any account already saved with the
                  // bare raw value 'CIHTM' would otherwise no longer match
                  // anything here and get dumped into the free-text Other
                  // box on their own profile. Resolves it to the same
                  // 'CHMT' option (now correctly labeled) instead - a
                  // display/pre-selection fix only, same as every other
                  // alias in this block.
                  $deptFullNameToCode['cihtm'] = 'CHMT';
                  $deptForSelect = $deptFullNameToCode[strtolower(trim($dept))] ?? $dept;

                  // 2026-09-08 fix - found while reviewing a real non-teaching
                  // account's profile page for a screenshot: the 13-office
                  // dropdown above was built from HR's definitive office
                  // list, but the actual seeded employee roster (40 real
                  // non-teaching accounts) predates that list and still
                  // stores the older 5-office names HR used originally
                  // ("Registrar's Office", "Human Resource Management Office
                  // (HRMO)", "Supply/Property Office", "Library",
                  // "Accounting/Budget Office") - none of which string-match
                  // any of the 13 new option keys. admin_page.php already
                  // has this exact aliasing (see $nonTeachingOfficeAliases
                  // there) for its own department filter; this dropdown
                  // never got the same treatment, so every one of those real
                  // accounts silently fell into "Other" here even though
                  // their office is a real, listed one. Same non-destructive
                  // approach as $deptFullNameToCode above: only affects
                  // which option is pre-selected, not what's stored, unless
                  // the employee re-saves.
                  $legacyOfficeAliases = [
                    "registrar's office" => "Admission and Registrarship",
                    "human resource management office (hrmo)" => "Human Resource Management",
                    "supply/property office" => "Supply",
                    "library" => "Library Services",
                    "accounting/budget office" => "Accounting Office",
                  ];
                  $deptForSelect = $legacyOfficeAliases[strtolower(trim($dept))] ?? $deptForSelect;

                  // Anyone whose saved department matches neither list (e.g.
                  // an older free-typed office not in the curated 5) still
                  // falls back to "Other" with their actual text preserved.
                  $isCustomDept = ($dept !== '' && !in_array($deptForSelect, array_keys($deptOptions), true) && !in_array($deptForSelect, array_keys($officeOptions), true));
                ?>
                <select
                  id="departmentSelect"
                  disabled
                  class="form-input"
                  required
                >
                  <option value="">Select Department</option>
                  <optgroup label="Colleges (Teaching Staff)">
                    <?php
                      foreach ($deptOptions as $val => $label) {
                        $selected = $deptForSelect === $val ? 'selected' : '';
                        echo "<option value=\"" . htmlspecialchars($val) . "\" $selected>" . htmlspecialchars($label) . "</option>";
                      }
                    ?>
                  </optgroup>
                  <optgroup label="Offices (Non-Teaching Staff)">
                    <?php
                      foreach ($officeOptions as $val => $label) {
                        $selected = $deptForSelect === $val ? 'selected' : '';
                        echo "<option value=\"" . htmlspecialchars($val) . "\" $selected>" . htmlspecialchars($label) . "</option>";
                      }
                    ?>
                  </optgroup>
                  <option value="OTHER" <?= $isCustomDept ? 'selected' : '' ?>>Other (not listed above)</option>
                </select>
                <input
                  type="text"
                  id="departmentOtherInput"
                  placeholder="Type your office or department"
                  value="<?= $isCustomDept ? htmlspecialchars($dept) : '' ?>"
                  disabled
                  class="form-input mt-2 <?= $isCustomDept ? '' : 'hidden' ?>"
                />
                <input type="hidden" id="department" name="department" value="<?= htmlspecialchars($dept) ?>" />
              </div>
              
              <!-- Years in LSPU -->
              <div data-field="yearsInLSPU">
                <label for="yearsInLSPU" class="block text-sm font-medium text-gray-700 mb-2">Years in LSPU <span class="req-ast text-red-500" id="ast-yearsInLSPU" style="display:<?= $fieldIsEmpty['yearsInLSPU'] ? 'inline' : 'none' ?>">*</span></label>
                <input 
                  id="yearsInLSPU" 
                  name="yearsInLSPU" 
                  type="number" 
                  min="0" 
                  max="50"
                  value="<?= htmlspecialchars($years) ?>"
                  readonly
                  class="form-input"
                  required
                />
                <?php if ($computed_years !== ''): ?>
                  <p class="text-xs text-gray-500 mt-2">
                    Started in <strong style="color:#1A4B8C;"><?= $current_year - $computed_years ?></strong>,
                    now <strong style="color:#0D6B4D;"><?= $computed_years ?></strong> year<?= $computed_years > 1 ? 's' : '' ?> (as of <?= $current_year ?>)
                  </p>
                <?php endif; ?>
              </div>
              
              <!-- Employment Type -->
              <div data-field="teaching_status">
                <label for="teaching_status" class="block text-sm font-medium text-gray-700 mb-2">Type of Employment <span class="req-ast text-red-500" id="ast-teaching_status" style="display:<?= $fieldIsEmpty['teaching_status'] ? 'inline' : 'none' ?>">*</span></label>
                <select 
                  id="teaching_status" 
                  name="teaching_status" 
                  disabled
                  class="form-input"
                  required
                >
                  <option value="">Select</option>
                  <option value="Teaching" <?= ($teach ?? '') === "Teaching" ? "selected" : "" ?>>Teaching</option>
                  <?php
                    // 2026-09-02 fix - this used to submit "Non Teaching"
                    // (a space), but the DB convention everywhere else
                    // (admin_page.php's stats queries) is "Non-teaching" (a
                    // hyphen) - the two can never SQL-match, which is what
                    // caused Non-Teaching Staff to read 0 on the dashboard
                    // for so long. Also accept the old space-variant here so
                    // an existing saved profile still shows pre-selected
                    // instead of reverting to blank.
                    $isNonTeaching = in_array($teach ?? '', ['Non-teaching', 'Non Teaching', 'Non-Teaching'], true);
                  ?>
                  <option value="Non-teaching" <?= $isNonTeaching ? "selected" : "" ?>>Non-teaching</option>
                </select>
              </div>
            </div>

            <!-- 2026-09-15 - real TAM testers said empty required fields
                 got lost among the already-filled ones. JS moves each
                 still-empty required field's wrapper div here, once, on
                 page load (see groupEmptyFieldsBelow() below) - grouping
                 them together instead of leaving them interleaved. Not
                 re-run while the user is actively editing, so fields
                 don't jump around mid-type. -->
            <div id="needsInputHeading" class="hidden mt-8 pt-6 border-t-2 border-red-200 flex items-center gap-2">
              <i class="ri-error-warning-fill text-red-500 text-lg"></i>
              <p class="text-sm font-semibold text-red-600">Still needed - please fill in these required fields</p>
            </div>
            <div id="needsInputGrid" class="hidden grid grid-cols-1 md:grid-cols-2 gap-6 mt-4"></div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div id="actionButtons" class="pt-6 mt-6 border-t border-gold-soft hidden">
          <div class="flex flex-col sm:flex-row justify-end gap-3">
            <button 
              type="button" 
              id="cancelButton"
              class="px-6 py-3 border border-gray-300 rounded-xl hover:bg-gray-50 font-medium text-gray-700 transition-all duration-200 flex items-center justify-center gap-2"
            >
              <i class="ri-close-line"></i> Cancel
            </button>
            <button 
              type="submit" 
              name="update_profile" 
              class="px-6 py-3 btn-forest rounded-xl font-medium flex items-center justify-center gap-2"
            >
              <i class="ri-save-line"></i> Save Changes
            </button>
          </div>
          <p class="text-xs text-gray-500 mt-4">* Required fields</p>
        </div>
      </form>
    </div>
  </div>
</main>

<!-- Error Notification -->
<?php if (!empty($update_error)): ?>
<div id="errorNotification" class="fixed top-4 right-4 bg-gradient-to-r from-red-500 to-red-600 text-white p-4 rounded-xl shadow-lg font-medium flex items-center gap-3 z-50 animate-fade-in">
  <div class="w-6 h-6 flex items-center justify-center bg-white/20 text-white rounded-full">
    <i class="ri-close-line"></i>
  </div>
  <span><?= htmlspecialchars($update_error) ?></span>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
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
});

document.addEventListener('DOMContentLoaded', () => {
  const editButton = document.getElementById('editButton');
  const cancelButton = document.getElementById('cancelButton');
  const actionButtons = document.getElementById('actionButtons');
  const imageInput = document.getElementById('imageInput');
  const uploadButton = document.getElementById('uploadButton');
  const profileImage = document.getElementById('profileImage');
  const formElements = document.querySelectorAll('#profileForm input, #profileForm select');
  const profileForm = document.getElementById('profileForm');

  // 2026-09-15 - real TAM testers found that clicking "Complete Profile
  // Now" on the dashboard dropped them on a read-only form that still
  // required a second click on "Edit Profile" before they could type
  // anything - confusing enough that the person testing with them had to
  // walk every respondent through it by hand. If the profile isn't
  // complete yet, skip straight to edit mode; a profile that's already
  // complete still opens read-only (protects against accidental edits).
  const profileIsComplete = <?= $hasProfile ? 'true' : 'false' ?>;

  // Enable editing mode
  function enableEditing() {
    formElements.forEach(el => {
      el.removeAttribute('readonly');
      el.removeAttribute('disabled');
      el.classList.remove('form-input');
      el.classList.add('form-input');
      el.style.background = '#ffffff';
    });
    
    uploadButton.classList.remove('hidden');
    editButton.classList.add('hidden');
    actionButtons.classList.remove('hidden');
  }

  // Department "Other" - non-teaching staff belong to an office, not one
  // of the fixed colleges, so they need a free-text fallback. The hidden
  // #department field (the one actually named "department") always
  // mirrors whichever of the select/text input is currently active.
  const deptSelect = document.getElementById('departmentSelect');
  const deptOtherInput = document.getElementById('departmentOtherInput');
  const deptHidden = document.getElementById('department');

  function syncDepartment() {
    if (!deptSelect || !deptOtherInput || !deptHidden) return;
    if (deptSelect.value === 'OTHER') {
      deptOtherInput.classList.remove('hidden');
      deptHidden.value = deptOtherInput.value.trim();
    } else {
      deptOtherInput.classList.add('hidden');
      deptHidden.value = deptSelect.value;
    }
  }

  if (deptSelect && deptOtherInput) {
    deptSelect.addEventListener('change', syncDepartment);
    deptOtherInput.addEventListener('input', syncDepartment);
    syncDepartment();
  }

  // Designation/Position "Other" - same pattern as Department above
  // (2026-09-03).
  const desigSelect = document.getElementById('designationSelect');
  const desigOtherInput = document.getElementById('designationOtherInput');
  const desigHidden = document.getElementById('designation');

  function syncDesignation() {
    if (!desigSelect || !desigOtherInput || !desigHidden) return;
    if (desigSelect.value === 'OTHER') {
      desigOtherInput.classList.remove('hidden');
      desigHidden.value = desigOtherInput.value.trim();
    } else {
      desigOtherInput.classList.add('hidden');
      desigHidden.value = desigSelect.value;
    }
  }

  if (desigSelect && desigOtherInput) {
    desigSelect.addEventListener('change', syncDesignation);
    desigOtherInput.addEventListener('input', syncDesignation);
    syncDesignation();
  }

  // 2026-09-15 - highlight which required fields are still empty, live,
  // so a respondent filling this in alone (no one walking them through
  // it) can see at a glance what's left instead of guessing. Also drops
  // the "*" from a field's label once it's filled (per follow-up
  // feedback) - the asterisk only means anything while the field still
  // needs input.
  const plainRequiredFields = ['first_name', 'last_name', 'educationalAttainment', 'specialization', 'yearsInLSPU', 'teaching_status'];

  function toggleAsterisk(fieldKey, isEmpty) {
    const ast = document.getElementById('ast-' + fieldKey);
    if (ast) ast.style.display = isEmpty ? 'inline' : 'none';
  }

  function setFieldState(fieldKey, el) {
    if (!el) return;
    const isEmpty = !el.value || !el.value.trim();
    el.classList.toggle('field-empty', isEmpty);
    toggleAsterisk(fieldKey, isEmpty);
  }

  function updateEmptyFieldHighlights() {
    plainRequiredFields.forEach(id => setFieldState(id, document.getElementById(id)));

    // Department/Designation each have two possible controls (a select,
    // or the "Other" free-text fallback) - only the one actually visible
    // should ever show as empty, but the asterisk is shared between them
    // (it sits on the label, above both).
    if (deptSelect) {
      if (deptSelect.value === 'OTHER') {
        deptSelect.classList.remove('field-empty');
        setFieldState('department', deptOtherInput);
      } else {
        setFieldState('department', deptSelect);
        if (deptOtherInput) deptOtherInput.classList.remove('field-empty');
      }
    }
    if (desigSelect) {
      if (desigSelect.value === 'OTHER') {
        desigSelect.classList.remove('field-empty');
        setFieldState('designation', desigOtherInput);
      } else {
        setFieldState('designation', desigSelect);
        if (desigOtherInput) desigOtherInput.classList.remove('field-empty');
      }
    }
  }

  formElements.forEach(el => {
    el.addEventListener('input', updateEmptyFieldHighlights);
    el.addEventListener('change', updateEmptyFieldHighlights);
  });

  // 2026-09-15 - move whichever required fields started out empty (the
  // server-computed style="display:none/inline" on each "*" already
  // reflects this) into their own group below the filled ones, so
  // they're not lost interleaved among 8 other fields. This runs exactly
  // once, at load - re-running it while someone is actively typing would
  // make fields jump around under their cursor, so it's deliberately
  // NOT wired into updateEmptyFieldHighlights()/enableEditing().
  function groupEmptyFieldsBelow() {
    const needsGrid = document.getElementById('needsInputGrid');
    const needsHeading = document.getElementById('needsInputHeading');
    if (!needsGrid || !needsHeading) return;

    const fieldKeys = ['first_name', 'last_name', 'educationalAttainment', 'specialization', 'designation', 'department', 'yearsInLSPU', 'teaching_status'];
    let anyMoved = false;

    fieldKeys.forEach(key => {
      const wrapper = document.querySelector(`#profileForm [data-field="${key}"]`);
      const ast = document.getElementById('ast-' + key);
      if (!wrapper || !ast) return;
      if (ast.style.display !== 'none') {
        needsGrid.appendChild(wrapper);
        anyMoved = true;
      }
    });

    if (anyMoved) {
      needsGrid.classList.remove('hidden');
      needsHeading.classList.remove('hidden');
    }
  }

  // Disable editing mode
  function disableEditing() {
    formElements.forEach(el => {
      el.setAttribute('readonly', 'readonly');
      el.setAttribute('disabled', 'disabled');
      el.style.background = '#FDF8F0';
    });
    
    uploadButton.classList.add('hidden');
    editButton.classList.remove('hidden');
    actionButtons.classList.add('hidden');

    // Reset form values
    profileForm.reset();
    syncDepartment();
  }

  if (profileForm) {
    profileForm.addEventListener('submit', syncDepartment);
  }
  
  // Handle image upload
  function handleImageChange(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate file size
    if (file.size > 2 * 1024 * 1024) {
      Swal.fire({
        title: 'File Too Large',
        text: 'File size should not exceed 2MB',
        icon: 'warning',
        confirmButtonColor: '#1A4B8C',
        customClass: {
          popup: 'rounded-2xl'
        }
      });
      event.target.value = '';
      return;
    }
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!validTypes.includes(file.type.toLowerCase())) {
      Swal.fire({
        title: 'Invalid File Type',
        text: 'Only JPG, PNG, or GIF images are allowed',
        icon: 'warning',
        confirmButtonColor: '#1A4B8C',
        customClass: {
          popup: 'rounded-2xl'
        }
      });
      event.target.value = '';
      return;
    }
    
    // Preview image
    const reader = new FileReader();
    reader.onload = function(e) {
      profileImage.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }
  
  // Validate form
  function validateForm() {
    const requiredFields = [
      'first_name', 
      'last_name', 
      'educationalAttainment', 
      'specialization', 
      'designation', 
      'department', 
      'yearsInLSPU', 
      'teaching_status'
    ];
    
    let isValid = true;
    let firstEmptyField = null;
    
    requiredFields.forEach(fieldId => {
      const field = document.getElementById(fieldId);
      if (field && !field.value.trim()) {
        field.classList.add('border-red-500');
        isValid = false;
        if (!firstEmptyField) firstEmptyField = field;
      } else if (field) {
        field.classList.remove('border-red-500');
      }
    });
    
    if (!isValid) {
      if (firstEmptyField) {
        firstEmptyField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstEmptyField.focus();
      }
      
      Swal.fire({
        title: 'Validation Error',
        text: 'Please fill in all required fields',
        icon: 'error',
        confirmButtonColor: '#DC3545',
        customClass: {
          popup: 'rounded-2xl'
        }
      });
      return false;
    }
    
    return true;
  }
  
  // Event Listeners
  editButton.addEventListener('click', enableEditing);
  cancelButton.addEventListener('click', disableEditing);
  
  uploadButton.addEventListener('click', () => {
    imageInput.click();
  });

  // The "Change Photo" hover overlay on the picture itself was purely
  // decorative CSS with no click handler - clicking it did nothing. Wire
  // it to the same upload flow as the "Upload New Photo" button: enter
  // edit mode first if not already editing (so the rest of the form
  // becomes editable too, matching how saving a new photo actually
  // works), then open the file picker.
  const changePhotoOverlay = document.getElementById('changePhotoOverlay');
  if (changePhotoOverlay) {
    changePhotoOverlay.addEventListener('click', () => {
      if (uploadButton.classList.contains('hidden')) {
        enableEditing();
      }
      imageInput.click();
    });
  }

  imageInput.addEventListener('change', handleImageChange);
  
  profileForm.addEventListener('submit', function(e) {
    if (!validateForm()) {
      e.preventDefault();
      return false;
    }
  });
  
  // Auto-hide notifications
  setTimeout(() => {
    const successNotif = document.getElementById('successNotification');
    const errorNotif = document.getElementById('errorNotification');
    
    if (successNotif) {
      successNotif.style.opacity = '0';
      successNotif.style.transition = 'opacity 0.3s ease';
      setTimeout(() => successNotif.remove(), 300);
    }
    
    if (errorNotif) {
      errorNotif.style.opacity = '0';
      errorNotif.style.transition = 'opacity 0.3s ease';
      setTimeout(() => errorNotif.remove(), 300);
    }
  }, 5000);
  
  // IDP dropdown
  const dropdownBtn = document.getElementById('idp-dropdown-btn');
  const dropdownMenu = document.getElementById('idp-dropdown-menu');
  
  if (dropdownBtn && dropdownMenu) {
    dropdownBtn.addEventListener('click', function() {
      dropdownBtn.parentElement.classList.toggle('open');
      dropdownMenu.classList.toggle('hidden');
    });
    
    document.addEventListener('click', function(event) {
      if (!dropdownBtn.contains(event.target) && !dropdownMenu.contains(event.target)) {
        dropdownBtn.parentElement.classList.remove('open');
        dropdownMenu.classList.add('hidden');
      }
    });
  }
  
  // Initialize: skip straight to edit mode if the profile still needs
  // fields filled in (see profileIsComplete above); otherwise open
  // read-only as before.
  groupEmptyFieldsBelow();

  // 2026-09-16: "Still Needed" rows in the dashboard's profile-gate modal
  // now link here as profile.php?focus=<field>, so this needs to force
  // edit mode (even on an otherwise-complete profile - the whole point is
  // to make the target field immediately typeable) and land the user
  // directly on it instead of making them hunt for it themselves.
  const focusParams = new URLSearchParams(window.location.search);
  const focusField = focusParams.get('focus');

  if (focusField) {
    enableEditing();
  } else if (profileIsComplete) {
    disableEditing();
  } else {
    enableEditing();
  }
  updateEmptyFieldHighlights();

  const focusFieldIdMap = {
    name: 'first_name',
    educationalAttainment: 'educationalAttainment',
    specialization: 'specialization',
    designation: 'designationSelect',
    department: 'departmentSelect',
    yearsInLSPU: 'yearsInLSPU',
    teaching_status: 'teaching_status'
  };

  // 2026-09-21 - tester feedback: clicking "Complete Profile" on a brand-new
  // account still dropped the user at the top of this page to scroll down
  // for the empty fields. The per-field ?focus= links above already did the
  // right thing; a plain visit to an INCOMPLETE profile now behaves the same
  // way and lands on the first empty required field (in the same order as
  // focusFieldIdMap). That covers every entry point - the dashboard button,
  // the recommendations page, the sidebar - without editing each link. An
  // explicit ?focus= still wins.
  let focusTargetId = focusField ? focusFieldIdMap[focusField] : null;
  if (!focusTargetId && !profileIsComplete) {
    focusTargetId = Object.values(focusFieldIdMap).find(id => {
      const el = document.getElementById(id);
      return el && !String(el.value || '').trim();
    }) || null;
  }

  const targetEl = focusTargetId ? document.getElementById(focusTargetId) : null;
  if (targetEl) {
    // groupEmptyFieldsBelow() may have already relocated this field's
    // wrapper into #needsInputGrid - querying by id still finds it
    // wherever it now lives, so ordering here doesn't matter. Small
    // delay lets that reflow (and the edit-mode toggle above) settle
    // before scrolling, so the target position is stable.
    setTimeout(() => {
      targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      targetEl.focus({ preventScroll: true });
    }, 50);
  }
});

// Handle logout
if (window.location.search.includes('logout=1')) {
  localStorage.clear();
  sessionStorage.clear();
  window.location.href = 'index.php';
}
</script>

</body>
</html>