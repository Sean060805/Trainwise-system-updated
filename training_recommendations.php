<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

// 2026-09-03 fix - Sign Out had no logout handling of any kind on this
// page (its link was a bare href="index.php", and index.php itself has
// no $_GET['logout'] handler either - so clicking it just navigated to
// the login screen with the session left fully intact underneath).
// Matches the pattern already used by user_page.php/the college
// dashboards (a self-contained $_GET['logout'] check on the same page).
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

$userId = $_SESSION['user_id'];
$trainingRecommendations = [];
$error = null;
$hasProfile = false;
$user = null;
$profileCompletionPercentage = 0;
$hasDeadline = false;
$hasSubmitted = false;
$currentDeadlinePassed = false;
$showAssessmentButton = false;
$currentDeadlineId = null;
$allowSubmissions = false;
$rawDeadline = null;

// Set upload directory path
$upload_dir = 'uploads/profile_images/';

try {
    // Get user profile
    $profileQuery = $con->prepare("
        SELECT id, name, email, profile_image, designation,
               educationalAttainment, specialization, department,
               yearsInLSPU, teaching_status,
               (CASE
                   WHEN name IS NOT NULL AND name != '' AND
                        educationalAttainment IS NOT NULL AND educationalAttainment != '' AND
                        specialization IS NOT NULL AND specialization != '' AND
                        designation IS NOT NULL AND designation != '' AND
                        department IS NOT NULL AND department != '' AND
                        yearsInLSPU IS NOT NULL AND yearsInLSPU != '' AND
                        teaching_status IS NOT NULL AND teaching_status != ''
                   THEN TRUE
                   ELSE FALSE
                END) as has_complete_profile
        FROM users
        WHERE id = ?
    ");

    if ($profileQuery) {
        $profileQuery->bind_param("i", $userId);
        $profileQuery->execute();
        $profileResult = $profileQuery->get_result();

        if ($profileResult && $profileResult->num_rows > 0) {
            $user = $profileResult->fetch_assoc();
            $hasProfile = (bool)$user['has_complete_profile'];

            $requiredFields = [
                'name' => 'Full Name',
                'educationalAttainment' => 'Educational Attainment',
                'specialization' => 'Specialization',
                'designation' => 'Designation',
                'department' => 'Department',
                'yearsInLSPU' => 'Years in LSPU',
                'teaching_status' => 'Employment Type'
            ];

            $completedFields = 0;
            foreach ($requiredFields as $field => $label) {
                if (!empty(trim($user[$field] ?? ''))) {
                    $completedFields++;
                }
            }
            $profileCompletionPercentage = round(($completedFields / count($requiredFields)) * 100);
        }
        $profileQuery->close();
    }

    // Get latest active deadline
    $deadlineQuery = $con->prepare("SELECT id, submission_deadline, allow_submissions FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
    if ($deadlineQuery) {
        $deadlineQuery->execute();
        $result = $deadlineQuery->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $rawDeadline = $row['submission_deadline'] ?? null;
            $currentDeadlineId = $row['id'] ?? null;
            $allowSubmissions = (bool)($row['allow_submissions'] ?? false);
            $hasDeadline = !empty($rawDeadline);

            if ($hasDeadline) {
                $now = new DateTime();
                $deadlineDT = new DateTime($rawDeadline);
                $currentDeadlinePassed = ($now > $deadlineDT);
            }
        }
        $deadlineQuery->close();
    }

    // Check assessment submission status
    if ($hasProfile && $hasDeadline && $currentDeadlineId) {
        $submissionStmt = $con->prepare("
            SELECT id FROM assessments
            WHERE user_id = ? AND deadline_id = ?
            LIMIT 1
        ");

        if ($submissionStmt) {
            $submissionStmt->bind_param("ii", $userId, $currentDeadlineId);
            $submissionStmt->execute();
            $submissionResult = $submissionStmt->get_result();
            $hasSubmitted = $submissionResult->num_rows > 0;
            $submissionStmt->close();
        }
    }

    $showAssessmentButton = $hasProfile && $hasDeadline && !$hasSubmitted;

    // Ensure the table exists, then refresh from the ML service if stale.
    ensureTrainingRecommendationsTable($con);

    if ($hasProfile && $hasSubmitted) {
        if (needsMLRefresh($con, $userId)) {
            refreshMLRecommendations($con, $userId);
        }
    }

    $trainingRecommendations = getTrainingRecommendations($con, $userId);
    // "Post a Training Opportunity" (2026-09-06) - open, dean-posted
    // trainings this employee's own college can still sign up for. See
    // create_dean_sourced_training.php for the full design.
    $openOpportunities = getOpenTrainingOpportunities($con, $userId, $user['department'] ?? null);

    // Mark all as read
    $markReadStmt = $con->prepare("UPDATE training_recommendations SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    if ($markReadStmt) {
        $markReadStmt->bind_param("i", $userId);
        $markReadStmt->execute();
        $markReadStmt->close();
    }

    // NOTE: training_recommendation notifications (e.g. HR confirming a
    // training) are intentionally NOT marked read just by visiting this
    // page - that cleared them before the employee ever saw the dashboard
    // bell (see mark_training_notifications_read.php, called when the
    // bell itself is opened, for the actual read trigger).

} catch (Exception $e) {
    $error = $e->getMessage();
}

function getPriorityColor($priority) {
    switch ($priority) {
        case 'High': return 'bg-red-50 text-red-700 border-red-200';
        case 'Medium': return 'bg-amber-50 text-amber-700 border-amber-200';
        case 'Low': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        default: return 'bg-gray-50 text-gray-700 border-gray-200';
    }
}

function getStatusColor($status) {
    switch ($status) {
        case 'Accepted': return 'bg-blue-50 text-blue-700';
        case 'Training Available': return 'bg-violet-50 text-violet-700';
        case 'Confirmed': return 'bg-indigo-50 text-indigo-700';
        case 'Completed': return 'bg-emerald-50 text-emerald-700';
        case 'Declined': return 'bg-red-50 text-red-700';
        case 'Not Selected': return 'bg-gray-100 text-gray-600';
        default: return 'bg-amber-50 text-amber-700';
    }
}

function getStatusLabel($status) {
    switch ($status) {
        case 'Accepted': return 'Forwarded to HR';
        case 'Training Available': return 'Training Found';
        case 'Confirmed': return 'Confirmed';
        case 'Not Selected': return 'Not Selected This Round';
        default: return $status;
    }
}

function getTypeIcon($type) {
    switch ($type) {
        case 'Workshop': return 'ri-tools-line';
        case 'Seminar': return 'ri-presentation-line';
        case 'Online Course': return 'ri-computer-line';
        case 'Webinar': return 'ri-vidicon-line';
        case 'Conference': return 'ri-group-line';
        default: return 'ri-book-open-line';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Training Recommendations | LSPU Training Tracker</title>
  <link rel="stylesheet" href="assets/css/tw-42.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
      --slate: #5B7288;
      --slate-strong: #37485C;
      --slate-soft: #CBD8E3;
      --border-soft: #E8DDD0;
      --danger: #DC3545;
    }

    * {
      font-family: 'Inter', sans-serif;
    }

    html {
      overflow: hidden;
      height: 100%;
    }

    body {
      overflow-y: auto;
      overflow-x: hidden;
      height: 100%;
      margin: 0;
      padding: 0;
      background:
        radial-gradient(circle at 8% 0%, rgba(212, 168, 67, 0.08), transparent 40%),
        linear-gradient(135deg, #FDF8F0 0%, #F5EDDF 100%);
      background-attachment: fixed;
      color: var(--ink);
      min-height: 100vh;
    }

    body::-webkit-scrollbar { width: 8px; }
    body::-webkit-scrollbar-track { background: rgba(245, 237, 223, 0.5); border-radius: 10px; }
    body::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%); border-radius: 10px; }
    body::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%); }

    .font-display { font-family: 'Fraunces', serif; }

    .section-title {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      color: var(--ink);
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

    /* Sidebar - Fixed Position */
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

    .sidebar-fixed::-webkit-scrollbar { width: 4px; }
    .sidebar-fixed::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
    .sidebar-fixed::-webkit-scrollbar-thumb { background: rgba(212, 168, 67, 0.5); border-radius: 10px; }

    .sidebar-gradient {
      background:
        radial-gradient(circle at 85% 0%, rgba(212,168,67,0.22), transparent 55%),
        linear-gradient(180deg, var(--royal) 0%, var(--royal-2) 100%);
    }

    .main-content {
      margin-left: 16rem;
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      position: relative;
    }

    .main-content::-webkit-scrollbar { width: 8px; }
    .main-content::-webkit-scrollbar-track { background: rgba(245, 237, 223, 0.3); border-radius: 10px; }
    .main-content::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%); border-radius: 10px; }
    .main-content::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%); }

    /* Mobile sidebar drawer */
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
      background: var(--royal);
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

    .card-gradient {
      background: linear-gradient(145deg, #ffffff 0%, var(--cream) 100%);
      border: 1px solid var(--border-soft);
    }

    .hover-lift {
      transition: transform 0.25s cubic-bezier(0.16,1,0.3,1), box-shadow 0.25s ease;
    }

    .hover-lift:hover {
      transform: translateY(-5px);
      box-shadow: 0 16px 34px -12px rgba(15, 24, 48, 0.22);
    }

    .nav-item {
      transition: all 0.25s ease;
      border-radius: 0.75rem;
      margin: 0.25rem 0;
      position: relative;
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, 0.08);
      transform: translateX(4px);
    }

    .nav-item.active {
      background: rgba(212, 168, 67, 0.18);
    }

    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 60%;
      background: var(--gold);
      border-radius: 0 3px 3px 0;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .fade-up { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }

    /* ===== Training recommendation cards ===== */
    .training-card {
      background: #fff;
      border: 1px solid var(--border-soft);
      border-radius: 16px;
      position: relative;
      overflow: hidden;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .training-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 4px; height: 100%;
    }

    .training-card.priority-high::before { background: var(--danger); }
    .training-card.priority-medium::before { background: var(--gold); }
    .training-card.priority-low::before { background: var(--forest); }

    .training-card:hover {
      transform: translateX(4px);
      box-shadow: 0 14px 28px -16px rgba(15, 24, 48, 0.35);
    }

    .btn-royal {
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      transition: all 0.25s ease;
      box-shadow: 0 8px 20px -8px rgba(26, 75, 140, 0.5);
    }
    .btn-royal:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -8px rgba(26, 75, 140, 0.55); }

    .btn-forest {
      background: linear-gradient(135deg, var(--forest) 0%, var(--forest-2) 100%);
      transition: all 0.25s ease;
      box-shadow: 0 8px 20px -8px rgba(13, 107, 77, 0.5);
    }
    .btn-forest:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -8px rgba(13, 107, 77, 0.55); }

    html { scroll-behavior: smooth; }

    /* Shared SweetAlert2 popup title styling used across this page's modals
       (Upload Proof, etc.) - name kept from when this was built for the
       Accept-Training modality picker (removed 2026-09-01, see below). */
    .swal-modality-popup .swal2-title { font-family: 'Fraunces', serif; font-weight: 600; color: var(--ink); font-size: 1.35rem; line-height: 1.3; }

    /* ===== Upload Proof modal file dropzones (2026-08-31) ===== */
    .proof-dropzone {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.7rem 0.85rem;
      border: 1.5px dashed var(--slate-soft, #CBD8E3);
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s ease;
      background: #fafbfc;
    }
    .proof-dropzone:hover { border-color: var(--royal); background: rgba(26, 75, 140, 0.04); }
    .proof-dropzone i { font-size: 1.2rem; color: var(--slate, #5B7288); flex-shrink: 0; }
    .proof-dropzone-text {
      font-size: 0.8rem;
      color: var(--slate, #5B7288);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .proof-dropzone-done { border-style: solid; border-color: var(--forest); background: rgba(13, 107, 77, 0.05); }
    .proof-dropzone-done i { color: var(--forest); }
    .proof-dropzone-done .proof-dropzone-text { color: var(--forest); font-weight: 600; }
  </style>
</head>

<body class="bg-gray-50 font-poppins">
<button id="mobileMenuBtn" class="mobile-menu-btn" aria-label="Open menu"><i class="ri-menu-line"></i></button>
<div id="mobileBackdrop" class="mobile-backdrop"></div>
<!-- Sidebar - Fixed -->
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
        <a href="user_page.php" class="font-display text-lg font-semibold text-white tracking-tight leading-tight block">Training Tracker</a>
        <p class="eyebrow" style="color:var(--gold-light);"><span style="width:10px;"></span>TNA System</p>
      </div>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 px-4 py-6 space-y-1">
      <a href="user_page.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-dashboard-line text-lg"></i>
        </div>
        Dashboard
      </a>

      <!-- IDP Forms Dropdown -->
      <div class="group">
        <button id="idp-dropdown-btn" class="nav-item flex items-center justify-between w-full px-4 py-3 text-sm font-medium rounded-lg">
          <div class="flex items-center">
            <div class="w-6 h-6 flex items-center justify-center mr-3">
              <i class="ri-file-text-line text-lg"></i>
            </div>
            IDP Forms
          </div>
          <i class="ri-arrow-down-s-line transition-transform duration-300 group-[.open]:rotate-180"></i>
        </button>

        <div id="idp-dropdown-menu" class="hidden pl-10 mt-1 space-y-1 group-[.open]:block">
          <a href="Individual_Development_Plan.php" class="nav-item flex items-center px-4 py-2.5 text-sm rounded-lg">
            <div class="w-5 h-5 flex items-center justify-center mr-3">
              <i class="ri-file-add-line"></i>
            </div>
            Create New
          </a>
          <a href="save_idp_forms.php" class="nav-item flex items-center px-4 py-2.5 text-sm rounded-lg">
            <div class="w-5 h-5 flex items-center justify-center mr-3">
              <i class="ri-file-list-line"></i>
            </div>
            My Submitted Forms
          </a>
        </div>
      </div>

      <!-- Training Recommendations - ACTIVE -->
      <a href="training_recommendations.php" class="nav-item active flex items-center px-4 py-3 text-sm font-medium rounded-lg">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-lightbulb-flash-line text-lg" style="color:var(--gold-light);"></i>
        </div>
        Training Recommendations
      </a>

      <!-- Assessment Link -->
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

      <!-- Profile -->
      <a href="profile.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-user-line text-lg"></i>
        </div>
        Profile
        <?php if (!$hasProfile && isset($user)): ?>
          <span class="ml-auto text-xs bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center">
            <i class="ri-alert-line text-xs"></i>
          </span>
        <?php endif; ?>
      </a>
    </nav>

    <!-- Quick Stats Section at Bottom -->
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

      <!-- User Info & Logout -->
      <div class="flex items-center justify-between">
        <div class="flex items-center">
          <?php
            $defaultImage = 'images/noprofile.jpg';
            $mainImageSrc = $defaultImage;

            if (isset($user) && !empty($user['profile_image'])) {
                $full_path = $upload_dir . $user['profile_image'];
                if (file_exists($full_path)) {
                    $mainImageSrc = $full_path;
                }
            }
          ?>
          <img class="w-10 h-10 rounded-full mr-3 object-cover" style="border:2px solid rgba(212,168,67,0.5);"
              src="<?= htmlspecialchars($mainImageSrc); ?>"
              alt="Profile Picture"
              onerror="this.onerror=null;this.src='<?= $defaultImage; ?>';">
          <div>
            <p class="text-sm font-medium text-white">
              <?= isset($user) ? htmlspecialchars($user['name'] ?? 'User') : 'User' ?>
            </p>
            <p class="text-xs" style="color:var(--gold-light);">
              <?= isset($user) ? htmlspecialchars($user['designation'] ?? 'Staff') : 'Staff' ?>
            </p>
          </div>
        </div>
        <a href="?logout=1" class="p-2 rounded-lg hover:bg-red-600/20 text-red-200 border border-red-400/20 transition-all">
          <i class="ri-logout-box-line"></i>
        </a>
      </div>
    </div>
  </div>
</aside>

<!-- Main Content -->
<main class="main-content">
  <div class="p-8">
    <!-- Header -->
    <div class="mb-8 fade-up">
      <span class="eyebrow" style="color:var(--gold-light,#B8922A);">
        <span style="background:var(--gold);width:14px;height:1px;display:inline-block;"></span>
        Powered by ML
      </span>
      <div class="flex items-center gap-3 mt-2">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-lg" style="background:linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);">
          <i class="ri-lightbulb-flash-line text-white text-2xl"></i>
        </div>
        <div>
          <h1 class="text-3xl font-bold section-title">Training Recommendations</h1>
          <p class="text-sm" style="color:var(--ink-soft);">Personalized suggestions based on your profile and assessment.</p>
        </div>
      </div>
    </div>

    <!-- Stats Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
      <?php
        $totalRecs = count($trainingRecommendations);
        $highPriority = count(array_filter($trainingRecommendations, fn($r) => $r['priority'] === 'High'));
        $inProgress = count(array_filter($trainingRecommendations, fn($r) => in_array($r['status'], ['Accepted', 'Training Available', 'Confirmed'], true)));
        $completed = count(array_filter($trainingRecommendations, fn($r) => $r['status'] === 'Completed'));
      ?>
      <div class="card-gradient rounded-2xl p-5 shadow-custom hover-lift fade-up">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm" style="color:var(--ink-soft);">Total Recommendations</p>
            <p class="text-2xl font-bold" style="color:var(--ink);"><?= $totalRecs ?></p>
          </div>
          <div class="w-12 h-12 rounded-full flex items-center justify-center" style="background:var(--gold-soft);">
            <i class="ri-lightbulb-line text-xl" style="color:var(--brass-strong,#B8922A);"></i>
          </div>
        </div>
      </div>

      <div class="card-gradient rounded-2xl p-5 shadow-custom hover-lift fade-up">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm" style="color:var(--ink-soft);">High Priority</p>
            <p class="text-2xl font-bold text-red-600"><?= $highPriority ?></p>
          </div>
          <div class="w-12 h-12 rounded-full bg-red-50 flex items-center justify-center">
            <i class="ri-alert-line text-red-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="card-gradient rounded-2xl p-5 shadow-custom hover-lift fade-up">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm" style="color:var(--ink-soft);">Accepted / In Pipeline</p>
            <p class="text-2xl font-bold" style="color:var(--royal);"><?= $inProgress ?></p>
          </div>
          <div class="w-12 h-12 rounded-full flex items-center justify-center" style="background:rgba(26,75,140,0.1);">
            <i class="ri-timer-line text-xl" style="color:var(--royal);"></i>
          </div>
        </div>
      </div>

      <div class="card-gradient rounded-2xl p-5 shadow-custom hover-lift fade-up">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm" style="color:var(--ink-soft);">Completed</p>
            <p class="text-2xl font-bold" style="color:var(--forest);"><?= $completed ?></p>
          </div>
          <div class="w-12 h-12 rounded-full flex items-center justify-center" style="background:rgba(13,107,77,0.1);">
            <i class="ri-check-double-line text-xl" style="color:var(--forest);"></i>
          </div>
        </div>
      </div>
    </div>

    <?php
      // Free-text "specific training" request (2026-09-01, per the
      // adviser's direction during the pipeline demo) - for when none
      // of the AI-suggested titles above fit what someone actually
      // needs. Gated on $hasSubmitted (2026-09-02 fix, a real gap a
      // real test caught): this box only makes sense once an
      // assessment has actually been submitted and $trainingRecommendations
      // has had a chance to populate - "don't see what you need?" is a
      // non-sequitur when nothing's been generated yet to not-see.
      // Shown for BOTH outcomes once that's true though - an empty
      // result (still generating) and a populated-but-nothing-fits
      // result both land here, same as before.
      // Moved above the recommendation list entirely (2026-09-02, per
      // the adviser) - it used to sit at the bottom, requiring a scroll
      // past every card just to reach it.
    ?>
    <?php if ($hasSubmitted): ?>
    <div class="card-gradient rounded-2xl shadow-custom p-8 mb-6 fade-up">
      <h3 class="text-lg font-semibold mb-1 section-title" style="display:flex;align-items:center;gap:0.5rem;">
        <i class="ri-edit-2-line" style="color:var(--brass-strong,#B8922A);"></i> Don't See What You Need?
      </h3>
      <p class="text-sm mb-4" style="color:var(--ink-soft);">
        Tell us specifically what training you're looking for. If enough colleagues describe something similar, it gets pooled into a real request HR can act on - same as the suggestions below.
      </p>
      <div style="display:flex;flex-direction:column;gap:0.4rem;">
        <textarea id="customRequestText" rows="2" maxlength="500" placeholder="e.g. Advanced Excel for budget forecasting"
                  oninput="updateCustomRequestWordCount(); this.style.height='auto'; this.style.height=this.scrollHeight+'px';"
                  style="width:100%;padding:0.7rem 0.9rem;border:1px solid var(--slate-soft,#CBD8E3);border-radius:10px;font-size:0.88rem;resize:none;overflow:hidden;box-sizing:border-box;"></textarea>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span id="customRequestWordCount" style="font-size:0.76rem;color:var(--slate,#5B7288);">0 / 60 words</span>
          <button onclick="submitCustomTrainingRequest()" class="btn-royal px-5 py-2 text-white text-sm rounded-lg flex items-center gap-1">
            <i class="ri-send-plane-line"></i> Send to HR
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Training Opportunities From Your Dean (2026-09-06) - a real training
         the dean already found and posted directly, not an AI idea -
         click "I'm Interested" to sign up, same as Accepting any other
         recommendation. See create_dean_sourced_training.php. -->
    <?php if (!empty($openOpportunities)): ?>
    <div class="card-gradient rounded-2xl shadow-custom p-6 mb-4 fade-up" style="border:1px solid var(--gold,#D4A843);">
      <h3 class="text-lg font-semibold section-title mb-1" style="display:flex;align-items:center;gap:0.5rem;">
        <i class="ri-megaphone-line" style="color:var(--brass-strong,#B8922A);"></i> Training Opportunities From Your Dean
      </h3>
      <p style="font-size:0.82rem;color:var(--slate,#5B7288);margin-bottom:1rem;">Your dean already found these - no need to wait for HR, just let them know you're interested.</p>
      <div class="space-y-3">
        <?php foreach ($openOpportunities as $opp): ?>
          <?php $oppTitle = !empty($opp['found_training_title']) ? $opp['found_training_title'] : $opp['title']; ?>
          <div style="background:var(--gold-soft,#F5E6C8); border-radius:12px; padding:1rem 1.2rem; display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
              <h4 style="font-weight:700;color:var(--ink,#16233A);margin:0 0 0.3rem;"><?= htmlspecialchars($oppTitle) ?></h4>
              <div style="display:flex;flex-wrap:wrap;gap:0.8rem;font-size:0.8rem;color:var(--slate,#5B7288);">
                <span><i class="ri-price-tag-3-line"></i> <?= $opp['training_type'] ? htmlspecialchars($opp['training_type']) : '<span style="font-style:italic;">To be determined</span>' ?></span>
                <span><i class="ri-building-line"></i> <?= htmlspecialchars($opp['found_provider']) ?></span>
                <span><i class="ri-wallet-3-line"></i> <?= $opp['is_free'] ? 'Free' : htmlspecialchars($opp['found_cost']) ?></span>
                <span><i class="ri-calendar-line"></i> <?= htmlspecialchars($opp['found_dates']) ?></span>
                <span><i class="ri-team-line"></i> <?= (int)$opp['found_capacity'] ?> slots</span>
              </div>
              <?php if (!empty($opp['description'])): ?>
                <p style="font-size:0.8rem;color:var(--slate,#5B7288);margin-top:0.5rem;"><?= htmlspecialchars($opp['description']) ?></p>
              <?php endif; ?>
            </div>
            <button type="button" class="btn-primary-sm" onclick="expressInterest(<?= (int)$opp['id'] ?>, this)" style="flex-shrink:0;padding:0.55rem 1.1rem;border-radius:8px;border:none;background:var(--royal,#1A4B8C);color:#fff;font-weight:600;font-size:0.82rem;cursor:pointer;">
              <i class="ri-hand-heart-line"></i> I'm Interested
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filter / Sort bar (2026-09-02) -->
    <?php if (!empty($trainingRecommendations)): ?>
    <div class="card-gradient rounded-2xl shadow-custom p-4 mb-4 fade-up" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;">
      <label style="font-size:0.8rem;font-weight:600;color:var(--ink-soft);display:flex;align-items:center;gap:0.4rem;">
        <i class="ri-filter-3-line"></i> Status:
        <select id="statusFilter" onchange="applyRecommendationFilters()" style="padding:0.4rem 0.6rem;border:1px solid var(--slate-soft,#CBD8E3);border-radius:8px;font-size:0.82rem;">
          <option value="all">All</option>
          <option value="Recommended">Recommended</option>
          <option value="Accepted">Forwarded to HR</option>
          <option value="Training Available">Training Found</option>
          <option value="Confirmed">Confirmed</option>
          <option value="Completed">Completed</option>
          <option value="Declined">Declined</option>
          <option value="Not Selected">Not Selected This Round</option>
        </select>
      </label>
      <span id="filterResultCount" style="font-size:0.78rem;color:var(--slate);margin-left:auto;"></span>
    </div>
    <?php endif; ?>

    <!-- Training Recommendations List -->
    <div class="space-y-4" id="recommendationsList">
      <?php if (!empty($trainingRecommendations)): ?>
        <?php foreach ($trainingRecommendations as $index => $rec): ?>
          <div class="training-card priority-<?= strtolower($rec['priority']) ?> p-6 fade-up"
               data-status="<?= htmlspecialchars($rec['status']) ?>"
               data-priority="<?= htmlspecialchars($rec['priority']) ?>"
               data-date="<?= htmlspecialchars($rec['recommended_date'] ?? $rec['created_at']) ?>"
               style="animation-delay: <?= $index * 0.08 ?>s">
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background:var(--gold-soft);">
                    <i class="<?= getTypeIcon($rec['training_type']) ?>" style="color:var(--brass-strong,#B8922A);"></i>
                  </div>
                  <div>
                    <h3 class="text-lg font-semibold section-title">
                      <?= htmlspecialchars($rec['found_training_title'] ?: $rec['title']) ?>
                    </h3>
                    <?php if (!empty($rec['found_training_title'])): ?>
                      <p style="font-size:0.76rem;color:var(--slate,#5B7288);margin:-0.4rem 0 0.4rem;">Originally suggested as: <?= htmlspecialchars($rec['title']) ?></p>
                    <?php endif; ?>
                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                      <span class="px-3 py-1 rounded-full text-sm font-medium <?= getPriorityColor($rec['priority']) ?> border">
                        <?= htmlspecialchars($rec['priority']) ?> Priority
                      </span>
                      <?php
                        // 2026-09-09 fix - this badge showed training_type
                        // as if it were a confirmed fact, but it's really
                        // just the catalog's original planning assumption
                        // from when the program was seeded (e.g. "Workshop"),
                        // never updated once a dean actually sources a real
                        // provider - report_training_demand.php's UPDATE
                        // never touches this column, only found_provider/
                        // found_dates/actual_modality/etc. The system's job
                        // at this stage is surfacing a training topic worth
                        // pursuing, not asserting a delivery format nobody
                        // has confirmed yet. The real, dean-confirmed details
                        // already show separately in the "What the Dean
                        // Found" panel once they exist - removed here rather
                        // than show a claim the system can't back up.
                      ?>
                      <span class="px-3 py-1 rounded-full text-sm font-medium <?= getStatusColor($rec['status']) ?>">
                        <?= htmlspecialchars(getStatusLabel($rec['status'])) ?>
                      </span>
                    </div>
                  </div>
                </div>

                <?php
                  // description and reason are stored separately (2026-08-29).
                  // Shown as two distinct pieces now, not joined with a dash -
                  // a plain paragraph reads more like something a person wrote,
                  // and a separately labeled line makes clear which part is the
                  // training's description and which part is why it was
                  // suggested to this specific employee (2026-08-29 wording pass).
                  // Old rows from before the description/reason split already
                  // have the reason baked into description with nothing in the
                  // reason column, so those just show as one paragraph, which is
                  // still fine.
                  $descriptionText = trim($rec['description'] ?? '');
                  $reasonText = trim($rec['reason'] ?? '');
                  // 2026-09-03, updated same day - exact-match against
                  // recommender.py's LOW_CONFIDENCE_REASON constant (keep
                  // both in sync if that string ever changes). Originally
                  // this just relabeled an irrelevant recommendation
                  // honestly; per direct user feedback after seeing that
                  // live on Dexter's real account, the ML side now
                  // EXCLUDES an irrelevant program outright instead of
                  // showing it with a caveat (see recommend()'s
                  // has_real_query in recommender.py) - so this string
                  // now only actually appears for the one deliberate
                  // exception: a genuinely blank submission (nothing
                  // typed anywhere), where every recommendation really is
                  // just a generic role-based guess with nothing personal
                  // to compare it to.
                  $isLowConfidence = ($reasonText === "No close match yet — shown as a general suggestion based on your role");
                  // 2026-09-06 - exact-match against the reason string
                  // express_interest_in_opportunity.php sets (keep both in
                  // sync if that string ever changes). This row was never
                  // an AI suggestion at all, so "Why we suggested this"
                  // reads wrong here - the employee found and chose it
                  // themselves off a dean's posting.
                  $isDeanPosted = ($reasonText === 'You expressed interest in a training your dean posted directly.');
                ?>
                <p class="text-base leading-relaxed ml-13" style="color:var(--ink-soft);">
                  <?= htmlspecialchars($descriptionText !== '' ? $descriptionText : 'No description available') ?>
                </p>
                <?php if ($isDeanPosted): ?>
                  <p class="text-sm leading-relaxed ml-13 mt-2 flex items-start gap-1.5" style="color:var(--slate);">
                    <i class="ri-hand-heart-line mt-0.5"></i>
                    <span>You took interest in this training after your dean posted it directly.</span>
                  </p>
                <?php elseif ($isLowConfidence): ?>
                  <p class="text-sm leading-relaxed ml-13 mt-2 flex items-start gap-1.5" style="color:var(--brass-strong,#B8922A);">
                    <i class="ri-search-eye-line mt-0.5"></i>
                    <span>General suggestion — you didn't specify a particular training need in your assessment, so this is based on what's typical for your role.</span>
                  </p>
                <?php elseif ($reasonText !== ''): ?>
                  <p class="text-sm leading-relaxed ml-13 mt-2 flex items-start gap-1.5" style="color:var(--slate);">
                    <i class="ri-lightbulb-flash-line mt-0.5"></i>
                    <span>Why we suggested this: <?= htmlspecialchars($reasonText) ?></span>
                  </p>
                <?php endif; ?>

                <div class="flex items-center gap-4 mt-4 ml-13 text-sm" style="color:var(--slate);">
                  <span class="flex items-center gap-1">
                    <i class="ri-calendar-line"></i>
                    <?= $isDeanPosted ? 'Accepted' : 'Recommended' ?> on <?= date('M j, Y', strtotime($rec['recommended_date'] ?? $rec['created_at'])) ?>
                  </span>
                  <?php if (!empty($rec['completion_date'])): ?>
                    <span class="flex items-center gap-1" style="color:var(--forest);">
                      <i class="ri-check-line"></i>
                      Completed on <?= date('M j, Y', strtotime($rec['completion_date'])) ?>
                    </span>
                  <?php endif; ?>
                </div>

                <?php if ($rec['status'] === 'Accepted'): ?>
                  <div class="mt-3 ml-13 text-sm flex items-start gap-1" style="color:var(--slate);">
                    <i class="ri-time-line mt-0.5"></i>
                    <span>This has been sent to HR as part of the pooled training request. You will be notified once a training is confirmed.</span>
                  </div>
                <?php elseif ($rec['status'] === 'Training Available'): ?>
                  <div class="mt-3 ml-13 text-sm flex items-start gap-1" style="color:var(--slate);">
                    <i class="ri-checkbox-circle-line mt-0.5"></i>
                    <span>HR has found a training for this and is finalizing the list of attendees.</span>
                  </div>
                <?php elseif ($rec['status'] === 'Confirmed'): ?>
                  <div class="mt-3 ml-13 text-sm flex items-start gap-1" style="color:var(--slate);">
                    <i class="ri-user-star-line mt-0.5"></i>
                    <span>HR has confirmed you for this training. Please upload the proof of completion to mark it complete.</span>
                  </div>
                <?php elseif ($rec['status'] === 'Not Selected'): ?>
                  <div class="mt-3 ml-13 text-sm flex items-start gap-1" style="color:var(--slate);">
                    <i class="ri-information-line mt-0.5"></i>
                    <span>A training was found for this, but the available slots were limited and you were not selected this time. You may be prioritized if it is offered again.</span>
                  </div>
                <?php endif; ?>

                <?php
                  // What HR actually found - provider/cost/date/time/venue
                  // (2026-08-31). Previously the employee never saw any of
                  // this, only a generic "HR is finalizing" status line -
                  // a real gap found during manual testing. Shown for any
                  // status where a training has genuinely been found for
                  // them; HR can edit these details afterward (see
                  // edit_training_demand_found.php), which is why a "last
                  // updated" note is included.
                  $foundStatuses = ['Training Available', 'Confirmed', 'Completed'];
                  if (in_array($rec['status'], $foundStatuses, true) && !empty($rec['found_provider'])):
                    $timeParts = [];
                    if (!empty($rec['found_start_time'])) $timeParts[] = date('g:i A', strtotime($rec['found_start_time']));
                    if (!empty($rec['found_end_time'])) $timeParts[] = date('g:i A', strtotime($rec['found_end_time']));
                    $timeRange = implode(' - ', $timeParts);
                    // Duration (2026-09-01) - shown as a separate line from Time,
                    // not merged into it: Time is a fact ("2:00 PM - 6:00 PM"),
                    // Duration is a derived convenience ("4 hrs") - keeping them
                    // visually distinct avoids cramming both ideas into one line.
                    $durationText = '';
                    if (!empty($rec['found_start_time']) && !empty($rec['found_end_time'])) {
                        $minutes = (strtotime($rec['found_end_time']) - strtotime($rec['found_start_time'])) / 60;
                        if ($minutes > 0) {
                            $hrs = intdiv($minutes, 60);
                            $mins = $minutes % 60;
                            $parts = [];
                            if ($hrs > 0) $parts[] = $hrs . ' hr' . ($hrs !== 1 ? 's' : '');
                            if ($mins > 0) $parts[] = $mins . ' min' . ($mins !== 1 ? 's' : '');
                            $durationText = implode(' ', $parts);
                        }
                    }
                    // Who actually found it (2026-09-01) - a dean's role is always
                    // 'admin_<college>'; HR's plain role is just 'admin'.
                    $foundByLabel = (!empty($rec['found_by_role']) && $rec['found_by_role'] !== 'admin')
                        ? 'What the Dean Found' : 'What HR Found';
                ?>
                  <div class="mt-3 ml-13 rounded-xl p-4" style="background:var(--gold-soft,#F5E6C8); border:1px solid rgba(212,168,67,0.35);">
                    <p class="text-xs font-semibold mb-2" style="color:var(--brass-strong,#8C6423);">
                      <i class="ri-map-pin-2-line"></i> <?= htmlspecialchars($foundByLabel) ?>
                    </p>
                    <dl class="text-sm grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1" style="color:var(--ink-soft);">
                      <?php // Cost intentionally left out (2026-08-31, per explicit
                            // decision) - the employee isn't the one paying for it,
                            // so an institutional/negotiated rate isn't their
                            // concern; showing it just invites irrelevant "why did
                            // mine cost more" comparisons between coworkers. ?>
                      <div><span class="font-medium" style="color:var(--ink);">Provider:</span> <?= htmlspecialchars($rec['found_provider']) ?></div>
                      <?php if (!empty($rec['found_dates'])): ?><div><span class="font-medium" style="color:var(--ink);">Date(s):</span> <?= htmlspecialchars($rec['found_dates']) ?></div><?php endif; ?>
                      <?php if ($timeRange !== ''): ?><div><span class="font-medium" style="color:var(--ink);">Time:</span> <?= htmlspecialchars($timeRange) ?></div><?php endif; ?>
                      <?php if ($durationText !== ''): ?><div><span class="font-medium" style="color:var(--ink);">Duration:</span> <?= htmlspecialchars($durationText) ?></div><?php endif; ?>
                      <?php
                        // Label matches the actual modality HR/dean recorded
                        // (2026-09-01) - a physical "Venue" label reads oddly
                        // next to a Zoom link. Not the employee's own pick -
                        // that picker was removed per the adviser's guidance
                        // that modality is decided once HR talks to the real
                        // provider, not guessed by the employee beforehand.
                        $venueLabel = $rec['actual_modality'] === 'Online' ? 'Platform / Meeting Link'
                            : ($rec['actual_modality'] === 'Face-to-Face' ? 'Venue' : 'Venue/Format');
                      ?>
                      <?php if (!empty($rec['found_venue'])): ?><div><span class="font-medium" style="color:var(--ink);"><?= htmlspecialchars($venueLabel) ?>:</span> <?= htmlspecialchars($rec['found_venue']) ?></div><?php endif; ?>
                      <?php if (!empty($rec['actual_modality'])): ?><div><span class="font-medium" style="color:var(--ink);">Modality:</span> <?= htmlspecialchars($rec['actual_modality']) ?></div><?php endif; ?>
                    </dl>
                    <?php if (!empty($rec['found_notes'])): ?>
                      <p class="text-sm mt-2" style="color:var(--ink-soft);"><span class="font-medium" style="color:var(--ink);">Notes:</span> <?= htmlspecialchars($rec['found_notes']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($rec['found_updated_at'])): ?>
                      <p class="text-xs mt-2" style="color:var(--slate);"><i class="ri-history-line"></i> Last updated <?= date('M j, Y g:i A', strtotime($rec['found_updated_at'])) ?></p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($rec['status'] === 'Recommended'): ?>
                <!-- 2026-09-03 - py-2 measured ~36px tall on mobile (a
                     real Playwright pass ahead of TAM testing), under
                     the ~44px minimum recommended touch-target size.
                     py-3 + an explicit min-height gets these to 44px
                     regardless of font-metric rounding. -->
                <div class="flex gap-2 lg:flex-col flex-shrink-0">
                  <button onclick="updateStatus(<?= $rec['id'] ?>, 'Accepted')"
                          class="btn-royal px-4 py-3 text-white text-sm rounded-lg flex items-center justify-center gap-1" style="min-height:44px;">
                    <i class="ri-check-line"></i> Accept
                  </button>
                  <button onclick="updateStatus(<?= $rec['id'] ?>, 'Declined')"
                          class="px-4 py-3 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-all flex items-center justify-center gap-1" style="min-height:44px;">
                    <i class="ri-close-line"></i> Decline
                  </button>
                </div>
              <?php elseif ($rec['status'] === 'Confirmed'):
                $proofComplete = !empty($rec['proof_certificate_path']) && !empty($rec['proof_approval_letter_path'])
                    && !empty($rec['proof_program_path']) && $rec['proof_hours'] !== null;
              ?>
                <div class="flex-shrink-0 flex flex-col gap-2 items-end">
                  <?php if (!$proofComplete): ?>
                    <!-- Helper text moved inside openProofUpload()'s modal itself
                         (2026-08-31) - it used to sit here, wrapping awkwardly
                         under the button on every card; it only matters at the
                         moment someone is actually uploading, not before. -->
                    <button onclick='openProofUpload(<?= $rec['id'] ?>, <?= json_encode([
                        "cert"    => !empty($rec['proof_certificate_path']),
                        "letter"  => !empty($rec['proof_approval_letter_path']),
                        "program" => !empty($rec['proof_program_path']),
                        "hours"   => $rec['proof_hours'],
                    ]) ?>)'
                            class="px-4 py-3 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-all flex items-center justify-center gap-1 whitespace-nowrap" style="min-height:44px;">
                      <i class="ri-upload-2-line"></i> Upload Proof
                    </button>
                  <?php else: ?>
                    <!-- Defensive fallback only: normally proof completion auto-fires
                         Completed inside upload_training_proof.php the moment the 4th
                         piece lands, so this branch shouldn't render for new rows. Kept
                         for any pre-existing row that reached full proof before that
                         auto-transition existed. -->
                    <button onclick="updateStatus(<?= $rec['id'] ?>, 'Completed')"
                            class="btn-forest px-4 py-3 text-white text-sm rounded-lg flex items-center justify-center gap-1" style="min-height:44px;">
                      <i class="ri-check-line"></i> Mark Complete
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="card-gradient rounded-2xl text-center py-20 fade-up">
          <div class="w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-6" style="background:var(--gold-soft);">
            <i class="ri-inbox-line text-4xl" style="color:var(--brass-strong,#B8922A);"></i>
          </div>
          <h3 class="text-xl font-semibold mb-2 section-title">No Training Recommendations Yet</h3>
          <p class="max-w-md mx-auto" style="color:var(--ink-soft);">
            <?= $hasProfile ? 'Your recommendations are being generated. Please check back shortly.' : 'Complete your profile to receive personalized training recommendations.' ?>
          </p>
          <?php if ($showAssessmentButton): ?>
            <a href="user_page.php#assessmentFormWrapper" class="btn-royal inline-flex items-center gap-2 mt-6 px-6 py-3 text-white rounded-xl font-medium">
              <i class="ri-file-edit-line"></i>
              Take Assessment
            </a>
          <?php else: ?>
            <a href="<?= $hasProfile ? 'user_page.php' : 'profile.php' ?>" class="btn-royal inline-flex items-center gap-2 mt-6 px-6 py-3 text-white rounded-xl font-medium">
              <i class="ri-<?= $hasProfile ? 'dashboard-line' : 'user-settings-line' ?>"></i>
              <?= $hasProfile ? 'Go to Dashboard' : 'Complete Profile' ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// IDP dropdown functionality
document.addEventListener('DOMContentLoaded', function() {
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

  // Mobile sidebar drawer
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

// Word-limit counter for the "Don't See What You Need?" box (2026-09-02).
// 60 words is enough to describe a specific training need in a sentence
// or two - long enough to be specific, short enough that HR can actually
// read every submission when reviewing a cluster, not an essay field.
const CUSTOM_REQUEST_MAX_WORDS = 60;

// No real word runs longer than this - a token beyond it counts as
// multiple "words" worth of budget instead of just 1 (2026-09-02 fix, a
// real gap found via testing: typing ~190 characters with no spaces at
// all still showed "1 / 60 words," since a plain split-on-whitespace
// count treats one giant unbroken blob as a single word).
const CUSTOM_REQUEST_MAX_WORD_LENGTH = 30;

function tokenWeight(token) {
  return Math.max(1, Math.ceil(token.length / CUSTOM_REQUEST_MAX_WORD_LENGTH));
}

function countWords(text) {
  const trimmed = text.trim();
  if (trimmed === '') return 0;
  return trimmed.split(/\s+/).reduce((sum, w) => sum + tokenWeight(w), 0);
}

function updateCustomRequestWordCount() {
  const field = document.getElementById('customRequestText');
  const counter = document.getElementById('customRequestWordCount');
  let text = field.value;

  // Trim from the end - one character at a time - until the weighted
  // count fits. Handles "too many real words" and "one huge run of
  // characters" the same way, and the 500-char maxlength on the textarea
  // already bounds how many iterations this can ever take.
  while (countWords(text) > CUSTOM_REQUEST_MAX_WORDS && text.length > 0) {
    text = text.slice(0, -1);
  }
  if (text !== field.value) {
    field.value = text;
  }

  const count = countWords(field.value);
  counter.textContent = `${count} / ${CUSTOM_REQUEST_MAX_WORDS} words`;
  counter.style.color = count >= CUSTOM_REQUEST_MAX_WORDS ? '#DC3545' : 'var(--slate, #5B7288)';
}

// Filter bar (2026-09-02, sort control removed 2026-09-03) - purely
// client-side since every card's data already rendered server-side; no
// need to round-trip for this.
//
// 2026-09-03 - the manual Sort control (Newest/Oldest/Priority) was
// removed: a real Playwright pass ahead of TAM testing found it looked
// broken in practice - every recommendation for a given user gets
// inserted in one ML-refresh batch sharing the same recommended_date,
// so "Newest/Oldest First" had nothing to visibly differentiate, and a
// tester could easily read that as the control being broken rather than
// just uninformative. Rather than fix a control most respondents would
// never get real signal from anyway, removed it and leaned on a good
// default order instead - getTrainingRecommendations() in
// ml_recommendations.php already sorts server-side by still-actionable
// status first, then High/Medium/Low priority, so the most relevant
// items are always at the top with no user action needed (less
// scrolling to find what matters, which was the actual goal).
function applyRecommendationFilters() {
  const statusFilter = document.getElementById('statusFilter').value;
  const cards = document.querySelectorAll('#recommendationsList .training-card');

  let visibleCount = 0;
  cards.forEach(card => {
    const matches = statusFilter === 'all' || card.dataset.status === statusFilter;
    card.style.display = matches ? '' : 'none';
    if (matches) visibleCount++;
  });

  const countLabel = document.getElementById('filterResultCount');
  if (countLabel) {
    countLabel.textContent = statusFilter === 'all'
      ? `${visibleCount} training(s)`
      : `${visibleCount} of ${cards.length} shown`;
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const wordCounter = document.getElementById('customRequestWordCount');
  if (wordCounter) updateCustomRequestWordCount();
  if (document.getElementById('statusFilter')) applyRecommendationFilters();
});

function submitCustomTrainingRequest(force) {
  const field = document.getElementById('customRequestText');
  const text = field.value.trim();
  if (!text) {
    Swal.fire({ icon: 'warning', title: 'Say a bit more', text: 'Please describe the training you need first.', confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
    return;
  }
  if (countWords(text) > CUSTOM_REQUEST_MAX_WORDS) {
    Swal.fire({ icon: 'warning', title: 'A bit too long', text: `Please keep it to ${CUSTOM_REQUEST_MAX_WORDS} words or fewer.`, confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
    return;
  }
  const body = `request_text=${encodeURIComponent(text)}` + (force ? '&force=1' : '');
  fetch('submit_custom_training_request.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        field.value = '';
        Swal.fire({ icon: 'success', title: 'Sent!', text: data.message, confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' }, timer: 2500, timerProgressBar: true });
      } else if (data.existing_match) {
        // A close match already exists in the catalog or an existing
        // request (2026-09-01) - not a hard block, since a wording overlap
        // can genuinely be a coincidence, but worth surfacing before
        // silently creating a duplicate of something that already exists.
        Swal.fire({
          icon: 'question',
          title: 'Something similar already exists',
          html: `This sounds like <strong>"${data.existing_match.title}"</strong>, which is already ${data.existing_match.source === 'catalog' ? 'one of the suggested trainings' : 'a pending request'}. If that's what you mean, look for it above and Accept it instead - it'll be pooled with anyone else who's already asked for it. Otherwise, you can still send this as a new request.`,
          showCancelButton: true,
          confirmButtonText: 'Send anyway',
          cancelButtonText: 'Never mind',
          confirmButtonColor: '#1A4B8C',
          cancelButtonColor: '#6b7280',
          customClass: { popup: 'rounded-2xl swal-modality-popup' }
        }).then((result) => {
          if (result.isConfirmed) submitCustomTrainingRequest(true);
        });
      } else {
        Swal.fire({ icon: 'error', title: 'Could not send', text: data.message || 'Please try again.', confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
      }
    })
    .catch(() => {
      Swal.fire({ icon: 'error', title: 'Could not send', text: 'Please try again.', confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
    });
}

function expressInterest(demandId, btnEl) {
  btnEl.disabled = true;
  fetch('express_interest_in_opportunity.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `demand_id=${demandId}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Swal.fire({ icon: 'success', title: 'You\'re in!', text: data.message, confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } })
          .then(() => location.reload());
      } else {
        btnEl.disabled = false;
        Swal.fire({ icon: 'error', title: 'Could not sign up', text: data.message || 'Please try again.', confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
      }
    })
    .catch(() => {
      btnEl.disabled = false;
      Swal.fire({ icon: 'error', title: 'Could not sign up', text: 'Please try again.', confirmButtonColor: '#1A4B8C', customClass: { popup: 'rounded-2xl swal-modality-popup' } });
    });
}

function updateStatus(id, status) {
  const actionText = status === 'Accepted' ? 'accept' : status === 'Completed' ? 'complete' : 'decline';

  Swal.fire({
    title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Training?`,
    text: `Are you sure you want to ${actionText} this training recommendation?`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: status === 'Declined' ? '#DC3545' : '#1A4B8C',
    cancelButtonColor: '#6b7280',
    confirmButtonText: `Yes, ${actionText} it`,
    customClass: { popup: 'rounded-2xl swal-modality-popup' }
  }).then((result) => {
    if (result.isConfirmed) {
      fetch('update_training_recommendation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&status=${encodeURIComponent(status)}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          Swal.fire({
            title: 'Updated!',
            text: `Training recommendation has been marked as "${status}".`,
            icon: 'success',
            confirmButtonColor: '#1A4B8C',
            customClass: { popup: 'rounded-2xl swal-modality-popup' },
            timer: 2000,
            timerProgressBar: true
          }).then(() => location.reload());
        } else {
          Swal.fire({
            title: 'Error!',
            text: data.message || 'Failed to update. Please try again.',
            icon: 'error',
            confirmButtonColor: '#1A4B8C',
            customClass: { popup: 'rounded-2xl swal-modality-popup' }
          });
        }
      });
    }
  });
}

// Updates a proof dropzone's look once a file is actually picked - swaps
// the icon/text/border into the same "done" state used for already-saved
// documents, and shows the chosen filename so it's clear what's about to
// be uploaded.
function handleProofFileChosen(fieldId) {
  const input = document.getElementById(fieldId);
  const zone = document.getElementById(fieldId + 'Zone');
  const text = document.getElementById(fieldId + 'Text');
  if (!input.files[0]) return;
  zone.classList.add('proof-dropzone-done');
  zone.querySelector('i').className = 'ri-checkbox-circle-fill';
  text.textContent = input.files[0].name;
}

// File inputs redesigned as click-to-upload dropzones (2026-08-31) - the
// raw browser "Choose File / No file chosen" control never matched this
// page's own design language, and the old modal also had a real bug: a
// second `customClass` key further down in this same Swal.fire() call was
// silently overwriting the Fraunces-title styling set here, so the title
// never actually rendered as intended.
function openProofUpload(id, existing) {
  existing = existing || {};
  const fieldLabel = 'display:block;font-size:0.78rem;font-weight:600;color:var(--ink,#16233A);margin-bottom:0.3rem;';
  const fieldInput = 'width:100%;padding:0.5rem 0.7rem;border:1px solid var(--slate-soft,#CBD8E3);border-radius:8px;font-size:0.82rem;color:var(--ink,#374151);box-sizing:border-box;';
  const hoursValue = (existing.hours !== null && existing.hours !== undefined) ? existing.hours : '';

  const dropzone = (fieldId, label, isDone) => `
    <div style="margin-bottom:0.85rem;">
      <label style="${fieldLabel}">${label}</label>
      <label class="proof-dropzone${isDone ? ' proof-dropzone-done' : ''}" id="${fieldId}Zone" for="${fieldId}">
        <i class="${isDone ? 'ri-checkbox-circle-fill' : 'ri-upload-cloud-2-line'}"></i>
        <span class="proof-dropzone-text" id="${fieldId}Text">${isDone ? 'Already uploaded - click to replace' : 'Click to upload (PDF or image)'}</span>
      </label>
      <input type="file" id="${fieldId}" accept=".pdf,.jpg,.jpeg,.png,.gif" style="display:none" onchange="handleProofFileChosen('${fieldId}')">
    </div>`;

  Swal.fire({
    title: 'Upload Proof of Completion',
    width: 460,
    customClass: { popup: 'rounded-2xl swal-modality-popup' },
    html: `
      <div style="text-align:left;">
        <p style="font-size:0.83rem;color:var(--slate,#5B7288);line-height:1.5;margin:-0.2rem 0 1rem;">
          Please upload your certificate, approval letter, event program, and hours attended. This training will be marked complete automatically once all four are received.
        </p>
        ${dropzone('proofCert', 'Certificate of Completion', !!existing.cert)}
        ${dropzone('proofLetter', 'HR Approval Letter', !!existing.letter)}
        ${dropzone('proofProgram', 'Event Program', !!existing.program)}
        <div style="margin-bottom:0.2rem;">
          <label style="${fieldLabel}">Hours Attended</label>
          <input type="number" id="proofHours" style="${fieldInput}" min="0.5" max="999" step="0.5" placeholder="e.g. 8" value="${hoursValue}">
        </div>
        <p style="font-size:0.74rem;color:#9ca3af;margin:0.6rem 0 0;">You can upload one document at a time if you don't have everything on hand yet - anything marked done above is already saved.</p>
      </div>`,
    confirmButtonText: 'Save',
    confirmButtonColor: '#1A4B8C',
    showCancelButton: true,
    cancelButtonColor: '#6b7280',
    preConfirm: () => {
      const cert = document.getElementById('proofCert').files[0];
      const letter = document.getElementById('proofLetter').files[0];
      const program = document.getElementById('proofProgram').files[0];
      const hours = document.getElementById('proofHours').value;

      if (!cert && !letter && !program && !hours) {
        Swal.showValidationMessage('Add at least one document or the hours before saving.');
        return false;
      }

      const fd = new FormData();
      fd.append('recommendation_id', id);
      if (cert) fd.append('proof_certificate', cert);
      if (letter) fd.append('proof_approval_letter', letter);
      if (program) fd.append('proof_program', program);
      if (hours) fd.append('hours', hours);

      return fetch('upload_training_proof.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            Swal.showValidationMessage(data.message || 'Upload failed');
            return false;
          }
          return data;
        })
        .catch(() => {
          Swal.showValidationMessage('Upload failed - please try again');
          return false;
        });
    }
  }).then((result) => {
    if (result.isConfirmed && result.value) {
      const message = result.value.all_complete
        ? 'All documents and hours are in. This training has been marked Completed.'
        : 'Saved. Upload the rest when you have them.';
      Swal.fire({
        title: result.value.all_complete ? 'Completed!' : 'Uploaded!',
        text: message,
        icon: 'success',
        confirmButtonColor: '#1A4B8C',
        customClass: { popup: 'rounded-2xl swal-modality-popup' },
        timer: 2200,
        timerProgressBar: true
      }).then(() => location.reload());
    }
  });
}
</script>
</body>
</html>
