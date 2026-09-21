<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

// 2026-09-07 - this page had zero cache-control headers, so a normal
// refresh (not a hard one) could legitimately serve a browser-cached copy
// of the whole page - inline <script> included - from before a fix was
// deployed. Real consequence just hit during live debugging: a JS fix
// (try/catch + alert() around the assessment form's Submit flow) was
// confirmed live via direct HTTP fetch, but a plain refresh kept showing
// the old broken behavior with no way to tell whether the fix simply
// hadn't loaded yet, or was still broken. Force every load of this page
// to be fresh so that question never comes up again.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

// Initialize variables
$submissionStatus = '';
$formattedDeadline = '';
$rawDeadline = null;
$hasProfile = false;
$user = null;
$show_assessment = false;
$welcomeMessage = '';
$hasSubmitted = false;
$userLastSubmitted = null;
$currentDeadlinePassed = false;
$unreadNotifications = [];
$error = null;
$showAssessmentButton = false;
$hasUnreadAssessmentNotification = false;
$profileCompletionPercentage = 0;
$missingProfileFields = [];
$currentDeadlineId = null;
$allowSubmissions = false;
$hasDeadline = false;
$userId = $_SESSION['user_id'];

// Set upload directory path
$upload_dir = 'uploads/profile_images/';

// Ensure upload directory exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    // Check database connection
    if (!$con || $con->connect_error) {
        throw new Exception("Database connection error");
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
                $formattedDeadline = date("F j, Y, g:i a", strtotime($rawDeadline));
            }
        }
        $deadlineQuery->close();
    }

    // Check if deadline has passed
    if ($hasDeadline) {
        $now = new DateTime();
        $deadlineDT = new DateTime($rawDeadline);
        $currentDeadlinePassed = ($now > $deadlineDT);
    }

    // Get user profile with completion check
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
            $welcomeMessage = "Welcome, " . htmlspecialchars($user['name'] ?? 'User') . "!";

            // Save profile data to session
            $_SESSION['profile_name'] = $user['name'] ?? '';
            $_SESSION['profile_designation'] = $user['designation'] ?? '';
            $_SESSION['profile_educationalAttainment'] = $user['educationalAttainment'] ?? '';
            $_SESSION['profile_specialization'] = $user['specialization'] ?? '';
            $_SESSION['profile_department'] = $user['department'] ?? '';
            $_SESSION['profile_yearsInLSPU'] = $user['yearsInLSPU'] ?? '';
            $_SESSION['profile_teaching_status'] = $user['teaching_status'] ?? '';

            if (!empty($user['profile_image'])) {
                $_SESSION['profile_image'] = $user['profile_image'];
            }

            // Calculate profile completion
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
            $missingProfileFields = [];

            foreach ($requiredFields as $field => $label) {
                if (!empty(trim($user[$field] ?? ''))) {
                    $completedFields++;
                } else {
                    $missingProfileFields[$field] = $label;
                }
            }

            $profileCompletionPercentage = round(($completedFields / count($requiredFields)) * 100);
        }
        $profileQuery->close();
    }

    // Check for unread notifications - deadline reminders and, per adviser
    // feedback, a notification when a dean approves a link for a training
    // recommendation (see save_dean_note.php), so the employee knows
    // Start/Decline just became clickable without having to keep checking.
    $notifQuery = $con->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 AND related_type IN ('deadline', 'training_recommendation') ORDER BY created_at DESC");
    if ($notifQuery) {
        $notifQuery->bind_param("i", $userId);
        $notifQuery->execute();
        $notifResult = $notifQuery->get_result();
        $unreadNotifications = $notifResult->fetch_all(MYSQLI_ASSOC);
        $hasUnreadAssessmentNotification = count($unreadNotifications) > 0;
        $notifQuery->close();
    }

    // Check assessment submission status for current deadline
    if ($hasProfile && $hasDeadline && $currentDeadlineId) {
        $submissionStmt = $con->prepare("
            SELECT created_at, submission_date, status 
            FROM assessments 
            WHERE user_id = ? AND deadline_id = ?
            ORDER BY created_at DESC 
            LIMIT 1
        ");

        if ($submissionStmt) {
            $submissionStmt->bind_param("ii", $userId, $currentDeadlineId);
            $submissionStmt->execute();
            $submissionResult = $submissionStmt->get_result();

            if ($submissionResult && $submissionResult->num_rows > 0) {
                $row = $submissionResult->fetch_assoc();
                $hasSubmitted = true;
                $submissionStatus = $row['status'] ?? 'submitted';

                if (!empty($row['submission_date'])) {
                    $userLastSubmitted = new DateTime($row['submission_date']);
                } elseif (!empty($row['created_at'])) {
                    $userLastSubmitted = new DateTime($row['created_at']);
                }

                // Mark notifications as read after submission
                $markRead = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND related_type = 'deadline'");
                if ($markRead) {
                    $markRead->bind_param("i", $userId);
                    $markRead->execute();
                    $markRead->close();
                }
            }
            $submissionStmt->close();
        }
    }

    // Determine if assessment button should be shown
    $showAssessmentButton = $hasProfile && $hasDeadline && !$hasSubmitted;

    // Automatically show assessment form if there are unread notifications
    if ($showAssessmentButton && $hasUnreadAssessmentNotification) {
        $show_assessment = true;
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Error in user_page.php: " . $error);
}

/* =====================================================================
   ML TRAINING RECOMMENDATIONS (trainwise-ml: XGBoost + SBERT)
   ===================================================================== */

$trainingRecommendations = [];
$mlError = null;

if ($hasProfile && $hasSubmitted && isset($user)) {
    try {
        ensureTrainingRecommendationsTable($con);
        if (needsMLRefresh($con, $userId)) {
            refreshMLRecommendations($con, $userId);
        }
        $trainingRecommendations = getTrainingRecommendations($con, $userId);
    } catch (Exception $e) {
        $mlError = $e->getMessage();
        error_log("ML recommendation error in user_page.php: " . $mlError);
    }
}

// Proof-backed completion history - independent of the current assessment
// cycle (someone's past completions don't disappear just because they
// haven't submitted this cycle's assessment yet).
$trainingHistory = [];
try {
    ensureTrainingHistoryLogTable($con);
    $trainingHistory = getTrainingHistoryLog($con, $userId);
} catch (Exception $e) {
    error_log("Training history log error in user_page.php: " . $e->getMessage());
}

// 2026-09-17 addition - self-reported trainings (from the assessment
// form's own "trainings you've attended" section) used to be entered and
// then never shown anywhere again. Kept as a visually distinct section
// from the proof-backed list above, not merged into it - see
// getSelfReportedTrainingHistory()'s own comment for why.
$selfReportedTrainingHistory = [];
try {
    $selfReportedTrainingHistory = getSelfReportedTrainingHistory($con, $userId);
} catch (Exception $e) {
    error_log("Self-reported training history error in user_page.php: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard | LSPU Training Tracker</title>
  <!-- 2026-09-16: cache-busted with the bundle's own mtime - this link
       used to have no version param at all, so a browser that had
       visited before would keep serving its cached copy of tw-46.css
       forever after a rebuild, silently missing any new utility class
       (e.g. this exact redesign's w-24/h-24 avatar sizing rendered as an
       unconstrained giant circle on a cached page - rounded-full was
       already present in the old bundle, w-24/h-24 was not). -->
  <link rel="stylesheet" href="assets/css/tw-46.css?v=<?= @filemtime(__DIR__ . '/assets/css/tw-46.css') ?: time() ?>">
  <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
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
      --indigo: #1A4B8C;
      --indigo-dark: #0F3460;
      --emerald: #0D6B4D;
      --emerald-dark: #084A34;
      --danger: #DC3545;
    }

    * {
      font-family: 'Inter', sans-serif;
    }

    /* Fix: Only one scrollbar - body handles scrolling */
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

    /* Custom scrollbar styling */
    body::-webkit-scrollbar {
      width: 8px;
    }

    body::-webkit-scrollbar-track {
      background: rgba(245, 237, 223, 0.5);
      border-radius: 10px;
    }

    body::-webkit-scrollbar-thumb {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      border-radius: 10px;
    }

    body::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%);
    }

    .font-display {
      font-family: 'Fraunces', serif;
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
      /* 2026-09-03 fix - 100vh on real mobile browsers is often the
         LARGEST possible viewport (as if the address bar/bottom toolbar
         were hidden), taller than what's actually visible once that
         chrome is on screen. Sign Out sits at the very bottom of this
         sidebar - with height:100vh taller than the real viewport, it
         was rendering below the visible area. 100dvh tracks the real
         visible viewport as browser chrome shows/hides; kept the 100vh
         line above as a fallback for older browsers without dvh
         support. Same root cause and fix already applied to the HR/dean
         side's .rail sidebar - see CLAUDE.md. */
      height: 100dvh;
      width: 16rem;
      overflow-y: auto;
      overflow-x: hidden;
      -webkit-overflow-scrolling: touch;
      z-index: 50;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
    }

    .sidebar-fixed::-webkit-scrollbar {
      width: 4px;
    }

    .sidebar-fixed::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.05);
    }

    .sidebar-fixed::-webkit-scrollbar-thumb {
      background: rgba(212, 168, 67, 0.5);
      border-radius: 10px;
    }

    .sidebar-gradient {
      background:
        radial-gradient(circle at 85% 0%, rgba(212,168,67,0.22), transparent 55%),
        linear-gradient(180deg, var(--royal) 0%, var(--royal-2) 100%);
    }

    /* Main content - offset for sidebar */
    .main-content {
      margin-left: 16rem;
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      position: relative;
    }

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

    .main-content::-webkit-scrollbar {
      width: 8px;
    }

    .main-content::-webkit-scrollbar-track {
      background: rgba(245, 237, 223, 0.3);
      border-radius: 10px;
    }

    .main-content::-webkit-scrollbar-thumb {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      border-radius: 10px;
    }

    .main-content::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(135deg, #B8922A 0%, #8C6423 100%);
    }

    .card-gradient {
      background: linear-gradient(145deg, #ffffff 0%, var(--cream) 100%);
      border: 1px solid #E8DDD0;
    }

    .profile-gradient {
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
    }

    .assessment-btn {
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      transition: all 0.3s ease;
      box-shadow: 0 10px 24px -8px rgba(26, 75, 140, 0.55), inset 0 1px 0 rgba(255,255,255,0.2);
    }

    .assessment-modal-close {
      background: rgba(255,255,255,0.12);
      color: #fff;
    }
    .assessment-modal-close:hover {
      background: rgba(255,255,255,0.24);
    }

    .assessment-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 30px -8px rgba(26, 75, 140, 0.6), inset 0 1px 0 rgba(255,255,255,0.25);
    }

    .late-submission-btn {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      transition: all 0.3s ease;
      box-shadow: 0 10px 24px -8px rgba(184, 146, 42, 0.5), inset 0 1px 0 rgba(255,255,255,0.2);
    }

    .late-submission-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 30px -8px rgba(184, 146, 42, 0.55);
    }

    .notification-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      background: linear-gradient(135deg, #EF4444 0%, var(--danger) 100%);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      font-size: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: pulse-gentle 2s infinite;
    }

    .submission-status {
      background: linear-gradient(135deg, var(--forest) 0%, var(--forest-2) 100%);
      color: white;
      border-radius: 1.25rem;
      padding: 1.5rem;
      margin-top: 1.5rem;
      box-shadow: 0 10px 30px -10px rgba(13, 107, 77, 0.45);
    }

    .submission-status-late {
      background: linear-gradient(135deg, #D4A843 0%, #B8922A 100%);
      box-shadow: 0 10px 30px -10px rgba(184, 146, 42, 0.45);
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 15, 32, 0.72);
      backdrop-filter: blur(6px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      /* 2026-09-03 fix - this used to also carry `animation: fadeIn
         0.3s ease-out`. fadeIn animates *opacity*, and opacity applies
         to the whole element INCLUDING its children - so for the first
         300ms after opening, the modal box sitting inside this overlay
         (profileModal / the assessment form) was also rendered
         semi-transparent, briefly showing the dashboard page underneath
         right through its own supposedly-opaque white background. Found
         via a TAM pre-test mobile walkthrough (looked like an
         intermittent rendering glitch, but it's deterministic - just
         easy to miss at 60fps on a fast machine). The modal box already
         has its own separate slideUp/popIn entrance animation, so
         dropping the overlay's own opacity fade loses nothing visually
         and makes the backdrop fully opaque from frame one. */
    }

    .modal-container {
      background: var(--cream);
      border-radius: 1.5rem;
      padding: 2rem;
      max-width: 500px;
      width: 90%;
      animation: slideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 30px 70px rgba(6, 10, 26, 0.45);
      border: 1px solid rgba(212, 168, 67, 0.3);
    }

    /* "Still Needed" rows in #profileModal - deliberately a lighter
       affordance (hover tint only, no fill/border) than the
       "Complete Profile Now" button below it, so the button stays the
       one unambiguous primary action even though these are now clickable. */
    .missing-field-row:hover {
      background: rgba(255, 255, 255, 0.7);
      box-shadow: 0 1px 3px rgba(15, 24, 48, 0.06);
    }
    .missing-field-row:focus-visible {
      outline: 2px solid var(--gold);
      outline-offset: 2px;
      background: rgba(255, 255, 255, 0.7);
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(16px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .fade-up { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
    .fade-up-1 { animation-delay: .05s; }
    .fade-up-2 { animation-delay: .12s; }
    .fade-up-3 { animation-delay: .19s; }
    .fade-up-4 { animation-delay: .26s; }

    .hover-lift {
      transition: transform 0.25s cubic-bezier(0.16,1,0.3,1), box-shadow 0.25s ease;
    }

    .hover-lift:hover {
      transform: translateY(-5px);
      box-shadow: 0 16px 34px -12px rgba(15, 24, 48, 0.22);
    }

    /* ============================================================
       CALENDAR (FullCalendar) — 2026-09-03 reskin. FullCalendar ships
       with only its own plain default look (thin gray grid, stock blue
       buttons) - nothing here overrode it before, so it stuck out
       against the rest of this page's warm/rounded/serif-accented
       design. Every value below reuses this file's own :root tokens
       rather than introducing new colors.
       ============================================================ */
    #calendar .fc { font-family: 'Inter', sans-serif; }

    /* 2026-09-16 - shrunk considerably. This card sits in a ~1/3-width
       column and a real 6-row month grid at the old row height (3.1rem)
       read as tall/"portrait" - a lot of scroll for a calendar. Rows
       tightened from 3.1rem to 2.15rem (6 rows: ~298px -> ~206px) plus
       trimmed toolbar/header spacing on top of that; the 44px touch
       target on the prev/next buttons is kept as-is (a deliberate
       mobile-accessibility fix from 2026-09-03, not part of this ask). */
    #calendar .fc-toolbar.fc-header-toolbar { margin-bottom: 0.65rem; }
    #calendar .fc-toolbar-title {
      font-family: 'Fraunces', serif;
      font-size: 0.98rem;
      font-weight: 600;
      color: var(--ink);
    }
    #calendar .fc-button {
      background: #fff;
      border: 1px solid var(--border-soft);
      color: var(--royal);
      border-radius: 0.65rem;
      /* 2026-09-03 - was 2.1rem (~33.6px), under the ~44px minimum
         recommended touch target (found via a real mobile QA pass). */
      width: 2.75rem;
      height: 2.75rem;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-shadow: none;
      transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    }
    #calendar .fc-button:hover, #calendar .fc-button:not(:disabled):active {
      background: var(--royal) !important;
      border-color: var(--royal) !important;
      color: #fff !important;
    }
    #calendar .fc-button:focus { box-shadow: 0 0 0 3px rgba(26,75,140,0.15); }
    #calendar .fc-button:disabled { opacity: 0.35; }

    #calendar .fc-scrollgrid { border: none; }
    #calendar .fc-scrollgrid-sync-table, #calendar table { border-color: var(--cream-dim); }
    #calendar th.fc-col-header-cell { border: none; padding-bottom: 0.3rem; }
    #calendar .fc-col-header-cell-cushion {
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--slate);
      text-decoration: none;
      padding: 0.2rem 0;
    }

    #calendar .fc-daygrid-day { border-color: var(--cream-dim) !important; }
    #calendar .fc-daygrid-day-frame { padding: 0.05rem; min-height: 2.15rem; }
    #calendar .fc-daygrid-day-top { justify-content: center; }
    #calendar .fc-daygrid-day-number {
      font-size: 0.72rem;
      font-weight: 500;
      color: var(--ink-soft);
      text-decoration: none;
      padding: 0.15rem;
      margin: 0.05rem;
    }
    #calendar .fc-day-other .fc-daygrid-day-number { color: var(--slate-soft); }
    #calendar .fc-day-today { background: var(--gold-soft) !important; }
    #calendar .fc-day-today .fc-daygrid-day-number {
      background: var(--royal);
      color: #fff;
      font-weight: 700;
      border-radius: 999px;
      width: 1.3rem;
      height: 1.3rem;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
    }

    #calendar .fc-daygrid-event-dot { display: none; }
    #calendar .fc-event {
      border: none;
      border-radius: 0.35rem;
      padding: 0.05rem 0.3rem;
      font-size: 0.6rem;
      font-weight: 600;
      margin-top: 0.1rem;
      cursor: pointer;
    }
    #calendar .fc-more-link {
      font-size: 0.6rem;
      font-weight: 700;
      color: var(--royal);
    }
    #calendar .fc-popover {
      border-radius: 0.9rem;
      border: 1px solid var(--border-soft);
      box-shadow: 0 16px 34px -12px rgba(15,24,48,0.22);
      overflow: hidden;
    }
    #calendar .fc-popover-header {
      background: var(--cream-dim);
      font-family: 'Fraunces', serif;
      font-weight: 600;
      color: var(--ink);
    }

    .calendar-card {
      background: linear-gradient(145deg, #ffffff 0%, var(--cream) 100%);
      border: 1px solid #E8DDD0;
      border-radius: 1.25rem;
      padding: 1.5rem;
      box-shadow: 0 4px 20px rgba(15, 24, 48, 0.06);
    }

    .deadline-badge {
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      color: white;
      padding: 0.5rem 1.1rem;
      border-radius: 2rem;
      font-size: 0.85rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      border: 1px solid rgba(212, 168, 67, 0.45);
      box-shadow: 0 8px 20px -8px rgba(15, 24, 48, 0.4);
    }

    .welcome-text {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .section-title {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      color: var(--ink);
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

    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 8px;
    }

    .status-submitted { background-color: var(--forest); }
    .status-pending { background-color: #F59E0B; }
    .status-overdue { background-color: var(--danger); }

    /* ===== Progress ring for profile completion ===== */
    .progress-ring circle {
      transition: stroke-dashoffset 1s cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* ===== Getting started tip strip ===== */
    .tip-step {
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }

    .tip-num {
      flex: 0 0 auto;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: var(--royal);
      color: var(--gold-light);
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 700;
      font-size: 0.72rem;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .tip-step.done .tip-num {
      background: var(--forest);
      color: #fff;
    }

    /* ===== AI recommendation cards ===== */
    .ai-card {
      background: #fff;
      border: 1px solid #E8DDD0;
      border-radius: 14px;
      padding: 16px;
      transition: box-shadow 0.25s ease, transform 0.25s ease;
      position: relative;
      overflow: hidden;
    }

    .ai-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 4px; height: 100%;
      background: var(--gold);
    }

    .ai-card:hover {
      box-shadow: 0 14px 28px -16px rgba(15, 24, 48, 0.35);
      transform: translateY(-2px);
    }

    .ai-priority {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 0.62rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      font-weight: 700;
      padding: 2px 9px;
      border-radius: 999px;
    }

    .priority-high { background: #FEE2E2; color: #991B1B; }
    .priority-medium { background: #FEF3C7; color: #92400E; }
    .priority-low { background: #D1FAE5; color: #065F46; }

    .ai-locked-icon {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: var(--cream-dim);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #B8922A;
      font-size: 1.4rem;
      animation: pulse-gentle 2.4s infinite;
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
        scroll-behavior: auto !important;
      }
    }
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
      <a href="user_page.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg active">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-dashboard-line text-lg"></i>
        </div>
        Dashboard
        <i class="ri-arrow-right-s-line ml-auto"></i>
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

      <a href="training_recommendations.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium rounded-lg">
        <div class="w-6 h-6 flex items-center justify-center mr-3">
          <i class="ri-lightbulb-flash-line text-lg"></i>
        </div>
        Training Recommendations
      </a>

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
          <?php if ($profileCompletionPercentage < 100): /* 2026-09-21 tester feedback: a permanent "100%" bar is noise - show profile progress only while it is incomplete */ ?>
          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center mr-3">
                <i class="ri-user-line text-sm" style="color:var(--gold-light);"></i>
              </div>
              <span class="text-sm text-white/80">Profile</span>
            </div>
            <div class="flex items-center">
              <span class="text-sm font-semibold text-white mr-2" id="sidebarProfilePct"><?= $profileCompletionPercentage ?>%</span>
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
              <?= $hasSubmitted ? 'Submitted' : ($hasDeadline ? 'Pending' : 'No Deadline') ?>
            </span>
          </div>

          <!-- Deadline Counter -->
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

          <!-- Notifications -->
          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center mr-3 relative">
                <i class="ri-notification-3-line text-sm" style="color:var(--gold-light);"></i>
                <?php if ($hasUnreadAssessmentNotification): ?>
                  <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full flex items-center justify-center">
                    <span class="text-xs text-white"><?= count($unreadNotifications) ?></span>
                  </span>
                <?php endif; ?>
              </div>
              <span class="text-sm text-white/80">Alerts</span>
            </div>
            <span class="text-sm font-semibold <?= $hasUnreadAssessmentNotification ? 'text-red-300' : 'text-white/60' ?>">
              <?= count($unreadNotifications) ?>
            </span>
          </div>
        </div>

        <!-- Overall Status -->
        <div class="mt-4 pt-3 border-t border-white/10">
          <div class="flex items-center justify-between">
            <span class="text-xs text-white/50">System Status:</span>
            <?php if ($hasProfile && $hasSubmitted): ?>
              <span class="flex items-center text-xs text-emerald-300 font-medium">
                <i class="ri-checkbox-circle-line mr-1"></i> Complete
              </span>
            <?php elseif (!$hasProfile && isset($user)): ?>
              <span class="flex items-center text-xs text-red-300 font-medium">
                <i class="ri-alert-line mr-1"></i> Profile Required
              </span>
            <?php elseif ($hasDeadline && !$hasSubmitted): ?>
              <span class="flex items-center text-xs text-amber-300 font-medium animate-pulse">
                <i class="ri-timer-flash-line mr-1"></i> Assessment Due
              </span>
            <?php else: ?>
              <span class="flex items-center text-xs text-white/60 font-medium">
                <i class="ri-information-line mr-1"></i> Active
              </span>
            <?php endif; ?>
          </div>
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
        <!-- 2026-09-03 fix - was a bare href="index.php" with no
             logout param, so clicking Sign Out just navigated to the
             login page WITHOUT actually ending the session (the
             $_GET['logout'] handler that destroys the session lives
             HERE, in this file, near the closing </body> - a link to
             index.php would never reach it). Kept relative (no
             filename), same as profile.php's already-correct pattern,
             so it reloads this same page with the param instead. -->
        <a href="?logout=1" class="p-2 rounded-lg hover:bg-red-600/20 text-red-200 border border-red-400/20 transition-all">
          <i class="ri-logout-box-line"></i>
        </a>
      </div>
    </div>
  </div>
</aside>

<!-- Main Content -->
<main class="main-content">
  <?php if (!$hasProfile && isset($user)): ?>
    <!-- Modal Overlay -->
    <div id="profileModal" class="modal-overlay">
      <div class="modal-container">
        <div class="mb-4">
          <h2 class="modal-title text-2xl font-bold section-title">
            <i class="ri-user-settings-line mr-2 text-2xl" style="color:#B8922A;"></i>
            Complete Your Profile
          </h2>
        </div>

        <div class="modal-content">
          <p class="text-gray-600 mb-6">A few details are still missing. Complete these first so you can take your assessment and get personalized training suggestions.</p>

          <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-semibold text-gray-700">Profile Completion</span>
              <span class="text-lg font-bold" style="color:#B8922A;"><?= $profileCompletionPercentage ?>%</span>
            </div>

            <div class="w-full bg-gray-200 rounded-full h-3">
              <div class="h-3 rounded-full transition-all duration-500"
                   style="width: <?= $profileCompletionPercentage ?>%; background:linear-gradient(90deg,#1A4B8C,#0F3460);"></div>
            </div>
          </div>

          <?php if (!empty($missingProfileFields)): ?>
            <div class="rounded-xl p-4 mb-6" style="background:var(--cream-dim); border:1px solid #E5DFCC;">
              <p class="text-sm font-semibold mb-3 flex items-center" style="color:#B8922A;">
                <i class="ri-information-line mr-2"></i> Still Needed
              </p>
              <!-- 2026-09-16: these used to be inert <li> text. Testers
                   were clicking them expecting them to jump to the field -
                   so they now actually do that, instead of fighting the
                   expectation. Kept visually lighter than the button below
                   (thin hover state, no fill) so "Complete Profile Now"
                   stays the one unambiguous primary action. -->
              <ul class="space-y-1.5">
                <?php foreach ($missingProfileFields as $field => $label): ?>
                  <li>
                    <a href="profile.php?focus=<?= urlencode($field) ?>"
                       class="missing-field-row flex items-center justify-between text-sm text-gray-700 px-3 py-2.5 rounded-lg transition-all">
                      <span class="flex items-center">
                        <i class="ri-arrow-right-s-line mr-2" style="color:#B8922A;"></i>
                        <?= htmlspecialchars($label) ?>
                      </span>
                      <i class="ri-edit-line text-xs text-gray-400"></i>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

        <div class="modal-actions">
          <a href="profile.php" class="w-full inline-flex items-center justify-center px-6 py-3.5 text-white rounded-xl font-semibold text-base transition-all assessment-btn">
            <i class="ri-user-settings-line mr-3"></i>
            Complete Profile Now
          </a>
        </div>
      </div>
    </div>

    <!-- Blurred Content -->
    <div class="blur-sm filter backdrop-blur-sm">
  <?php endif; ?>

  <!-- Assessment Form Modal (2026-08-29: converted from an inline page
       section to an actual modal). MUST live here, as a direct child of
       <main>, NOT nested inside #dashboardSection below - #dashboardSection
       has the .fade-up animation class, and .fade-up's fill-mode:both
       leaves a permanent transform:translateY(0) on it even after the
       animation finishes. ANY non-none transform on an ancestor (even a
       "zero" one) creates a new containing block for position:fixed
       descendants, so the modal was rendering relative to
       #dashboardSection's box instead of the true viewport - it looked
       like a z-index bug (only part of the screen got dark/blurred) but
       was actually this. Confirmed by moving it next to #profileModal
       above, which sits here for the same reason and never had the bug.
       Reuses the same .modal-overlay class #profileModal uses (z-index:
       9999, proper full-viewport fixed backdrop). Shown/hidden via inline
       display, not Tailwind's hidden/flex classes, since .modal-overlay's
       own display:flex would otherwise fight a competing .hidden class of
       equal or lower specificity. -->
  <div id="assessmentFormWrapper" class="modal-overlay" style="display:<?= $show_assessment ? 'flex' : 'none' ?>; padding:1rem;">
    <div id="assessmentModalBox" class="w-full flex flex-col rounded-2xl overflow-hidden" style="max-width:900px; max-height:90vh; background:#fff; box-shadow:0 30px 60px -20px rgba(15,24,48,0.5);">
      <div class="form-header p-6 flex items-start justify-between gap-4 flex-shrink-0" style="background:linear-gradient(135deg,var(--royal),var(--royal-2));">
        <div>
          <h3 class="text-xl font-bold text-white flex items-center font-display">
            <i class="ri-file-text-line mr-3 text-2xl" style="color:var(--gold-light);"></i>
            Training Needs Assessment Form
          </h3>
          <p class="mt-2" style="color:rgba(255,255,255,0.75);">Complete the form below to submit your training needs assessment</p>
        </div>
        <button type="button" id="closeAssessmentModal" aria-label="Close" class="assessment-modal-close flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center transition-colors">
          <i class="ri-close-line text-xl"></i>
        </button>
      </div>
      <div class="p-6 overflow-y-auto flex-1" style="min-height:0;">
        <?php if (file_exists('assessment_form_partial.php')): ?>
          <?php include 'assessment_form_partial.php'; ?>
        <?php else: ?>
          <div class="text-center py-8 text-gray-500">
            <i class="ri-file-warning-line text-4xl mb-4 text-gray-300"></i>
            <p>Assessment form not found</p>
            <p class="text-sm text-gray-400 mt-2">Please create assessment_form_partial.php</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="p-8">
    <!-- Welcome Section -->
    <div id="dashboardSection" class="fade-up fade-up-1">
      <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
        <div>
          <span class="eyebrow">Your Dashboard</span>
          <h1 class="text-4xl welcome-text mt-1 mb-2">
            <?= $welcomeMessage ?>
          </h1>
          <p class="text-gray-600 flex items-center">
            <i class="ri-calendar-line mr-2"></i>
            <?= date('l, F j, Y') ?>
          </p>
        </div>

        <div class="flex items-center gap-6">
          <?php if ($hasDeadline): ?>
            <div class="deadline-badge">
              <i class="ri-time-line"></i>
              Deadline: <?= htmlspecialchars($formattedDeadline) ?>
            </div>
          <?php endif; ?>

          <?php if ($hasUnreadAssessmentNotification): ?>
            <div class="relative">
              <button id="notificationBtn" class="relative p-3 bg-white rounded-full shadow-custom hover:shadow-custom-hover transition-all hover-lift">
                <i class="ri-notification-3-fill text-2xl" style="color:#B8922A;"></i>
                <span class="notification-badge">
                  <?= count($unreadNotifications) ?>
                </span>
              </button>
              <div id="notificationDropdown" class="hidden absolute right-0 mt-3 w-80 bg-white rounded-xl shadow-2xl border border-gray-100 overflow-hidden" style="z-index:9999; max-height:420px; overflow-y:auto;">
                <div class="p-4" style="background:linear-gradient(135deg,var(--royal),var(--royal-2));">
                  <h3 class="text-sm font-semibold text-white flex items-center">
                    <i class="ri-notification-3-line mr-2"></i>
                    Notifications
                  </h3>
                </div>
                <div class="max-h-96 overflow-y-auto">
                  <?php if (!empty($unreadNotifications)): ?>
                    <?php foreach ($unreadNotifications as $notif): ?>
                      <div class="p-4 border-b border-gray-100 hover:bg-amber-50/50 transition-colors notification-item">
                        <p class="text-sm text-gray-700 mb-2"><?= htmlspecialchars($notif['message'] ?? '') ?></p>
                        <p class="text-xs text-gray-500 flex items-center">
                          <i class="ri-time-line mr-1"></i>
                          <?= date('M j, g:i a', strtotime($notif['created_at'] ?? '')) ?>
                        </p>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="p-8 text-center text-gray-500">
                      <i class="ri-inbox-line text-3xl mb-3"></i>
                      <p>No new notifications</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Getting Started tip strip - helps non-technical users know what to do next -->
      <?php if ($hasProfile && !$hasSubmitted && $hasDeadline): ?>
        <div class="card-gradient rounded-2xl shadow-custom p-5 mb-6 flex flex-col sm:flex-row gap-5">
          <div class="tip-step done flex-1">
            <div class="tip-num"><i class="ri-check-line"></i></div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Profile complete</p>
              <p class="text-xs text-gray-500">You're all set on your details.</p>
            </div>
          </div>
          <div class="tip-step flex-1">
            <div class="tip-num">2</div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Take the assessment</p>
              <p class="text-xs text-gray-500">Tell us about your training needs below.</p>
            </div>
          </div>
          <div class="tip-step flex-1">
            <div class="tip-num">3</div>
            <div>
              <p class="text-sm font-semibold text-gray-800">Get suggestions</p>
              <p class="text-xs text-gray-500">Receive trainings picked just for you.</p>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2026-09-16: promoted out of the left column (where it used to
           sit AFTER the Assessment Forms table, so a longer table could
           push it below the fold) to right here - the first actionable
           thing after the welcome banner, guaranteed visible with no
           scrolling regardless of table length. Same #showFormBtn id, so
           the existing openAssessmentModal() JS wiring needs no change. -->
      <?php if ($hasProfile && $showAssessmentButton): ?>
        <div class="rounded-2xl shadow-custom-hover p-6 md:p-8 mb-8 hover-lift fade-up flex flex-col md:flex-row items-center justify-between gap-6"
             style="background:linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);">
          <div class="text-white text-center md:text-left">
            <span class="eyebrow" style="color:var(--gold-light);">Action Needed</span>
            <h2 class="text-2xl font-display font-semibold mt-1 mb-1">
              <?= $currentDeadlinePassed ? 'Fill Out Your Training Needs Assessment (Late Submission)' : 'Fill Out Your Training Needs Assessment' ?>
            </h2>
            <p style="color:rgba(255,255,255,0.82);" class="text-sm">
              <?= $currentDeadlinePassed
                    ? 'The deadline has passed, but you can still submit your assessment.'
                    : 'Takes about 5 minutes — tell us your training needs so we can recommend the right trainings for you.' ?>
            </p>
          </div>
          <button id="showFormBtn" class="flex-shrink-0 px-8 py-4 rounded-xl font-semibold flex items-center shadow-lg hover-lift transition-all duration-300"
                  style="background:var(--gold-light); color:var(--royal-2);">
            <i class="ri-edit-box-line mr-3 text-xl"></i>
            Fill Out Assessment
            <i class="ri-arrow-right-line ml-3"></i>
          </button>
        </div>
      <?php endif; ?>
    </div>

    <!-- Profile Card - 2026-09-16: rebuilt landscape and pulled out of the
         narrow lg:w-1/3 right column entirely (used to be a ~900px-tall
         portrait card: 128px avatar, then 6 full-width stacked rows -
         the single biggest reason the calendar below it needed a deep
         scroll to reach). Now a full-width band, avatar+identity on the
         left and a compact chip grid on the right, roughly 220-260px
         tall regardless of viewport width. -->
    <?php if (isset($user)): ?>
      <div class="bg-white rounded-2xl shadow-custom overflow-hidden hover-lift fade-up mb-8">
        <div class="flex flex-col md:flex-row items-stretch">
          <div class="profile-gradient p-6 flex flex-row md:flex-col items-center gap-4 md:w-64 flex-shrink-0 text-center">
            <div class="relative inline-block flex-shrink-0">
              <svg class="progress-ring" width="104" height="104" style="position:absolute; top:-4px; left:50%; transform:translateX(-50%);">
                <circle cx="52" cy="52" r="47" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="3" />
                <circle class="progress-ring-fg" cx="52" cy="52" r="47" fill="none" stroke="#D4A843" stroke-width="3"
                        stroke-dasharray="<?= 2 * 3.1416 * 47 ?>"
                        stroke-dashoffset="<?= 2 * 3.1416 * 47 * (1 - $profileCompletionPercentage / 100) ?>"
                        stroke-linecap="round" transform="rotate(-90 52 52)" />
              </svg>
              <img class="w-24 h-24 rounded-full border-4 border-white/20 shadow-xl relative"
                  src="<?= htmlspecialchars($mainImageSrc) ?>"
                  alt="Profile Picture"
                  onerror="this.onerror=null;this.src='<?= $defaultImage; ?>';">
              <div class="absolute bottom-0 right-0 w-6 h-6 bg-emerald-500 rounded-full border-2 border-white flex items-center justify-center">
                <i class="ri-check-line text-white text-xs"></i>
              </div>
            </div>
            <div class="text-left md:text-center min-w-0">
              <h2 class="text-lg font-display font-semibold text-white mb-0.5 leading-tight truncate">
                <?= htmlspecialchars($user['name'] ?? 'User'); ?>
              </h2>
              <p class="text-sm truncate" style="color:var(--gold-light);"><?= htmlspecialchars($user['department'] ?? 'Department'); ?></p>
              <span class="inline-block mt-1.5 px-2.5 py-0.5 bg-white/15 backdrop-blur-sm rounded-full text-xs text-white font-medium border border-white/20">
                <?= htmlspecialchars($user['designation'] ?? 'Staff') ?>
              </span>
            </div>
          </div>

          <div class="p-6 flex-1 min-w-0">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
              <?php
                $profileChips = [
                  ['ri-graduation-cap-line', 'Educational Attainment', $user['educationalAttainment'] ?? ''],
                  ['ri-medal-line', 'Specialization', $user['specialization'] ?? ''],
                  ['ri-briefcase-line', 'Designation', $user['designation'] ?? ''],
                  ['ri-building-line', 'Department', $user['department'] ?? ''],
                  ['ri-history-line', 'Years in LSPU', !empty($user['yearsInLSPU']) ? $user['yearsInLSPU'] . ' years' : ''],
                  ['ri-user-settings-line', 'Employment Type', $user['teaching_status'] ?? ''],
                ];
              ?>
              <?php foreach ($profileChips as [$icon, $label, $value]): ?>
                <?php if (!empty($value)): ?>
                  <div class="flex items-start p-2.5 rounded-lg" style="background:var(--cream-dim);">
                    <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center mr-2.5 flex-shrink-0">
                      <i class="<?= $icon ?> text-sm" style="color:var(--royal);"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="text-xs font-medium text-gray-500 truncate"><?= htmlspecialchars($label) ?></p>
                      <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($value) ?></p>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <div class="mt-4 flex justify-end">
              <a href="profile.php"
                class="inline-flex items-center px-5 py-2.5 text-white rounded-xl font-medium text-sm transition-all shadow-lg hover:shadow-xl assessment-btn">
                <i class="ri-edit-line mr-2"></i>
                Edit Profile
              </a>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ===================== AI TRAINING RECOMMENDATIONS ===================== -->
    <!-- 2026-09-16: promoted to a full-width section right after the
         Profile Card, above the Assessment Forms/Calendar row - this is
         the thing worth seeing immediately after submitting an
         assessment, not something to hunt for further down the page. -->
    <?php if ($hasProfile): ?>
      <?php if ($hasSubmitted): ?>
        <div id="recommendedForYou" class="card-gradient rounded-2xl shadow-custom p-8 mb-8 hover-lift fade-up">
          <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
            <div>
              <span class="eyebrow mb-1">Powered by AI</span>
              <h2 class="text-2xl section-title mt-1 flex items-center">
                <i class="ri-sparkling-2-fill mr-3 text-2xl" style="color:#B8922A;"></i>
                Recommended For You
              </h2>
            </div>
            <a href="training_recommendations.php"
               class="inline-flex items-center px-4 py-2.5 text-white rounded-xl font-medium text-sm transition-all shadow-lg hover:shadow-xl assessment-btn">
              Go to Training Recommendations
              <i class="ri-arrow-right-line ml-2"></i>
            </a>
          </div>
          <p class="text-gray-600 text-sm mb-6">Based on your role and profile, here are trainings that could help you grow. This list updates the next time you submit a new assessment.</p>

          <?php if (!empty($trainingRecommendations)): ?>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <?php foreach ($trainingRecommendations as $rec):
                  $priority = strtolower($rec['priority'] ?? 'medium');
                  $priorityClass = $priority === 'high' ? 'priority-high' : ($priority === 'low' ? 'priority-low' : 'priority-medium');
                  // 2026-09-03, updated same day - same detection as
                  // training_recommendations.php (exact-match against
                  // recommender.py's LOW_CONFIDENCE_REASON - keep all
                  // three in sync if that string ever changes). Now
                  // only actually fires for a genuinely blank
                  // submission - an irrelevant match is excluded
                  // outright on the ML side now, not shown at all
                  // (see recommender.py's has_real_query).
                  $recReasonText = trim($rec['reason'] ?? '');
                  $recIsLowConfidence = ($recReasonText === "No close match yet — shown as a general suggestion based on your role");
              ?>
                <div class="ai-card">
                  <div class="flex items-start justify-between gap-2 mb-2">
                    <h4 class="font-display font-semibold text-gray-800 text-base leading-snug"><?= htmlspecialchars($rec['title'] ?? 'Suggested Training') ?></h4>
                    <span class="ai-priority <?= $priorityClass ?> whitespace-nowrap"><?= htmlspecialchars($rec['priority'] ?? 'Medium') ?></span>
                  </div>
                  <p class="text-sm text-gray-600"><?= htmlspecialchars($rec['description'] ?? '') ?></p>
                  <?php if ($recIsLowConfidence): ?>
                    <p class="text-xs mt-2 flex items-start gap-1" style="color:#B8922A;">
                      <i class="ri-search-eye-line mt-0.5"></i>
                      <span>General suggestion — no particular training need specified.</span>
                    </p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php elseif ($mlError): ?>
            <div class="text-center py-8 text-gray-500 border border-dashed border-gray-200 rounded-xl">
              <i class="ri-cloud-off-line text-3xl mb-3 text-gray-300"></i>
              <p class="font-medium">Recommendations are temporarily unavailable</p>
              <p class="text-xs text-gray-400 mt-1">Please check back later. Your assessment has still been recorded.</p>
            </div>
          <?php else: ?>
            <div class="text-center py-8 text-gray-500 border border-dashed border-gray-200 rounded-xl">
              <i class="ri-loader-4-line text-3xl mb-3 text-gray-300"></i>
              <p>No suggestions generated yet.</p>
            </div>
          <?php endif; ?>
        </div>
      <?php elseif ($hasDeadline): ?>
        <!-- Locked preview so non-technical users understand what's coming -->
        <div class="card-gradient rounded-2xl shadow-custom p-8 mb-8 text-center fade-up">
          <div class="ai-locked-icon mx-auto mb-3">
            <i class="ri-lock-line"></i>
          </div>
          <h3 class="section-title text-lg mb-1">AI Training Suggestions</h3>
          <p class="text-sm text-gray-500 max-w-sm mx-auto">This unlocks right after you submit your Training Needs Assessment below. We will suggest trainings picked for your role.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Dashboard Layout -->
    <div class="flex flex-col lg:flex-row gap-8">
      <!-- Left Column -->
      <div class="flex-1 space-y-8">
        <!-- Assessment Summary -->
        <div class="card-gradient rounded-2xl shadow-custom p-8 hover-lift fade-up fade-up-2">
          <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
            <div>
              <span class="eyebrow mb-1">Records</span>
              <h2 class="text-2xl section-title mb-1 flex items-center mt-1">
                <i class="ri-file-list-3-line mr-3 text-2xl" style="color:#B8922A;"></i>
                Assessment Forms
              </h2>
              <p class="text-gray-600 text-sm">View and manage your training needs assessments</p>
            </div>

            <?php if ($hasDeadline && $hasProfile): ?>
              <div class="flex items-center gap-4">
                <?php if ($hasSubmitted): ?>
                  <span class="px-4 py-2 bg-emerald-100 text-emerald-800 rounded-full text-sm font-medium flex items-center">
                    <i class="ri-checkbox-circle-line mr-2"></i> Submitted
                  </span>
                <?php elseif ($currentDeadlinePassed): ?>
                  <span class="px-4 py-2 bg-amber-100 text-amber-800 rounded-full text-sm font-medium flex items-center">
                    <i class="ri-time-line mr-2"></i> Deadline Passed
                  </span>
                <?php else: ?>
                  <span class="px-4 py-2 bg-indigo-100 text-indigo-800 rounded-full text-sm font-medium flex items-center">
                    <i class="ri-time-line mr-2"></i> Active
                  </span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Assessment Summary Table -->
          <?php if (file_exists('assessment_summary_table.php')): ?>
            <?php include 'assessment_summary_table.php'; ?>
          <?php else: ?>
            <div class="text-center py-8 text-gray-500">
              <i class="ri-file-list-3-line text-4xl mb-4 text-gray-300"></i>
              <p>No assessment summary available</p>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($hasProfile): ?>
          <!-- Submission Status Display -->
          <?php if ($hasSubmitted): ?>
            <!-- 2026-09-17 redesign: was a large, centered celebratory
                 block with a nested translucent panel repeating the same
                 "recorded" message twice. Compacted into one row and,
                 more importantly, fixed a real copy bug - it used to say
                 "See your training suggestions below," but Recommended
                 For You now renders ABOVE this card (see the 2026-09-16
                 promotion above), so "below" was pointing the wrong way.
                 Replaced with an actual button that scrolls back up to
                 it, rather than a directional claim that can go stale
                 again the next time this page's layout changes. -->
            <div class="submission-status <?= $submissionStatus === 'late' ? 'submission-status-late' : '' ?> mt-8 rounded-2xl p-6 fade-up">
              <div class="flex flex-col md:flex-row md:items-center justify-between gap-5">
                <div class="flex items-center gap-4">
                  <div class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center flex-shrink-0">
                    <i class="ri-checkbox-circle-fill text-2xl text-white"></i>
                  </div>
                  <div>
                    <h3 class="submission-status-title text-xl font-display font-semibold">
                      <?= $submissionStatus === 'late' ? 'Late Submission Received' : 'Assessment Successfully Submitted' ?>
                    </h3>
                    <p class="text-white/85 text-sm mt-0.5">
                      Recorded on <?= $userLastSubmitted ? $userLastSubmitted->format('F j, Y \a\t g:i a') : date('F j, Y') ?><?= $submissionStatus === 'late' ? ', after the deadline' : ' — thank you for your submission' ?>.
                    </p>
                  </div>
                </div>
                <?php if ($submissionStatus === 'late'): ?>
                  <div class="flex items-center gap-2 text-sm bg-white/15 rounded-lg px-4 py-2.5 flex-shrink-0">
                    <i class="ri-information-line"></i>
                    <span>Received after the deadline</span>
                  </div>
                <?php else: ?>
                  <a href="#recommendedForYou" class="flex-shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-sm transition-all hover-lift" style="background:#fff; color:var(--forest);">
                    <i class="ri-sparkling-2-fill"></i>
                    View My Recommendations
                  </a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- No Active Deadline Message -->
          <?php if (!$hasDeadline): ?>
            <div class="mt-8 p-8 rounded-2xl text-center fade-up" style="background:var(--cream-dim); border:1px solid #E5DFCC;">
              <div class="flex flex-col items-center">
                <div class="w-20 h-20 rounded-full flex items-center justify-center mb-4" style="background:#fff;">
                  <i class="ri-time-line text-3xl" style="color:#B8922A;"></i>
                </div>
                <h3 class="text-xl section-title mb-3">No Active Assessment Period</h3>
                <p class="text-gray-700 max-w-md mb-4">There is currently no active assessment period. Please wait for the administrator to announce the next assessment cycle.</p>
                <div class="flex items-center" style="color:#B8922A;">
                  <i class="ri-information-line mr-2"></i>
                  <span class="text-sm">You will be notified when the next assessment opens</span>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

      </div>

      <!-- Right Column: Calendar and Training History -->
      <div class="w-full lg:w-1/3 space-y-8">
        <!-- Calendar Section - 2026-09-16: moved ABOVE Training History
             (used to be the 3rd stacked card here, after a tall portrait
             Profile Card that has since moved out of this column
             entirely - putting the more time-sensitive item, the
             deadline/calendar, first minimizes whatever scroll distance
             is left). -->
        <div class="calendar-card hover-lift fade-up fade-up-2">
          <div class="mb-4">
            <span class="eyebrow mb-1">This Month</span>
            <h3 class="text-xl section-title mt-1 flex items-center">
              <i class="ri-calendar-line mr-3 text-2xl" style="color:#B8922A;"></i>
              Calendar
            </h3>
          </div>
          <!-- 2026-09-03 - dropped the static "<?= date('F Y') ?>" label
               that used to sit here: FullCalendar's own toolbar title
               already shows the month, and unlike this static one it
               actually updates when the employee clicks prev/next -
               the static one used to keep reading the current month even
               after navigating away from it. -->

          <div id="calendar" class="mb-6"></div>

          <?php if ($hasDeadline): ?>
            <div class="mt-6 p-4 rounded-xl" style="background:var(--cream-dim); border:1px solid #E5DFCC;">
              <div class="flex items-center">
                <div class="w-12 h-12 rounded-full flex items-center justify-center mr-3" style="background:var(--royal);">
                  <i class="ri-time-line text-white text-xl"></i>
                </div>
                <div>
                  <p class="text-sm font-semibold" style="color:#B8922A;">Current Deadline</p>
                  <p class="text-sm text-gray-700 font-medium"><?= htmlspecialchars($formattedDeadline) ?></p>
                  <?php if ($currentDeadlinePassed): ?>
                    <span class="inline-block px-2 py-1 bg-amber-100 text-amber-800 rounded text-xs font-medium mt-1">
                      <i class="ri-alert-line mr-1"></i> Deadline Passed
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- ===================== TRAINING HISTORY ===================== -->
        <!-- 2026-09-17 redesign: this card used to show ONLY proof-backed
             completions - anything typed into the assessment form's own
             "trainings you've attended" section was entered and then
             never shown anywhere again. Now shows both, in two clearly
             labeled sections so "proof on file" and "self-reported" are
             never confused for the same kind of record. -->
        <div class="card-gradient rounded-2xl shadow-custom p-6 hover-lift fade-up fade-up-3">
          <div class="mb-2">
            <span class="eyebrow mb-1">Your Record</span>
            <h2 class="text-xl section-title mt-1 flex items-center">
              <i class="ri-award-line mr-3 text-xl" style="color:#B8922A;"></i>
              Training History
            </h2>
          </div>

          <?php if (!empty($selfReportedTrainingHistory)): ?>
            <p class="text-xs font-bold uppercase tracking-wider mt-4 mb-2" style="color:var(--slate-strong);">Self-Reported (from your assessments)</p>
            <div class="grid gap-3">
              <?php foreach ($selfReportedTrainingHistory as $h):
                $hModality = $h['modality'] ?? 'Face-to-Face';
                $hType = $h['training_type'] ?? '';
                $hTypeLabel = ($hType === 'Other' && !empty($h['training_type_other'])) ? $h['training_type_other'] : $hType;
                $hStart = $h['start_time'] ?? '';
                $hEnd = $h['end_time'] ?? '';
              ?>
                <div class="ai-card">
                  <div class="flex items-start justify-between gap-2">
                    <h4 class="font-display font-semibold text-gray-800 text-sm leading-snug"><?= htmlspecialchars($h['training'] ?? '') ?></h4>
                    <?php if ($hTypeLabel): ?>
                      <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0" style="background:var(--gold-soft); color:#8C6423;"><?= htmlspecialchars($hTypeLabel) ?></span>
                    <?php endif; ?>
                  </div>
                  <p class="text-xs text-gray-600 mt-1.5 flex items-center gap-1">
                    <i class="ri-calendar-event-line"></i>
                    <?= htmlspecialchars(format_training_date_range($h) ?: ($h['date'] ?? 'N/A')) ?>
                    <?php if ($hStart): ?>
                      &middot; <?= htmlspecialchars(date('g:i A', strtotime($hStart))) ?><?= $hEnd ? ' - ' . htmlspecialchars(date('g:i A', strtotime($hEnd))) : '' ?>
                    <?php endif; ?>
                  </p>
                  <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                    <?php if ($hModality === 'Online'): ?>
                      <i class="ri-global-line"></i> Online
                    <?php else: ?>
                      <i class="ri-map-pin-line"></i> <?= htmlspecialchars($h['venue'] ?? 'N/A') ?>
                    <?php endif; ?>
                  </p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="text-xs font-bold uppercase tracking-wider mb-2 <?= !empty($selfReportedTrainingHistory) ? 'mt-5' : 'mt-4' ?>" style="color:var(--slate-strong);">Verified (Proof on File)</p>
          <?php if (!empty($trainingHistory)): ?>
            <div class="grid gap-3">
              <?php foreach ($trainingHistory as $h): ?>
                <div class="ai-card">
                  <h4 class="font-display font-semibold text-gray-800 text-base leading-snug"><?= htmlspecialchars($h['title']) ?></h4>
                  <p class="text-sm text-gray-600 mt-1">
                    <?= htmlspecialchars(date('M j, Y', strtotime($h['completion_date']))) ?>
                    <?php if (!empty($h['hours'])): ?> &middot; <?= htmlspecialchars($h['hours']) ?> hrs<?php endif; ?>
                  </p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-center py-8 text-gray-500 border border-dashed border-gray-200 rounded-xl">
              <i class="ri-award-line text-3xl mb-3 text-gray-300"></i>
              <p class="font-medium">No completed trainings yet</p>
              <p class="text-xs text-gray-400 mt-1">Finished trainings with proof on file will show up here.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$hasProfile && isset($user)): ?>
    </div>
  <?php endif; ?>
</main>

<!-- FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // The .fade-up entrance animation's "both" fill-mode leaves a
  // permanent transform:translateY(0) on elements like #dashboardSection
  // after it finishes playing. A non-"none" transform creates a new CSS
  // stacking context, which was silently trapping the notification
  // dropdown's z-index:9999 inside it - no z-index value could ever let
  // it paint above the separate profile-card section next to it. Once
  // the animation has visibly finished, releasing it removes the trap
  // without affecting the entrance effect itself (it already played).
  document.querySelectorAll('.fade-up').forEach(el => {
    el.addEventListener('animationend', function () {
      this.style.animation = 'none';
    }, { once: true });
  });

  // Initialize calendar
  const calendarEl = document.getElementById('calendar');

  const deadline = '<?= $rawDeadline ?? '' ?>';
  const hasDeadline = '<?= $hasDeadline ? 1 : 0 ?>';
  const hasSubmitted = '<?= $hasSubmitted ? 1 : 0 ?>';
  const submissionDate = '<?= $userLastSubmitted ? $userLastSubmitted->format('Y-m-d H:i:s') : '' ?>';
  const showAssessment = '<?= $show_assessment ? 1 : 0 ?>';
  const hasProfile = '<?= $hasProfile ? 1 : 0 ?>';
  const submissionStatus = '<?= $submissionStatus ?>';
  const currentDeadlinePassed = '<?= $currentDeadlinePassed ? 1 : 0 ?>';

  const calendarEvents = [];

  // 2026-09-03 fix - these titles used to carry an emoji glyph
  // (📅/⏰/✅) baked directly into the text, rendered as plain text by
  // FullCalendar - inconsistent with the rest of this app, which uses
  // Remix Icons (ri-*) everywhere else, never emoji. Titles are now
  // plain text; the icon is rendered separately via eventContent below,
  // matching the app's actual icon system.
  if (hasDeadline && deadline) {
    calendarEvents.push({
      title: 'Submission Deadline',
      start: deadline,
      color: '#D4A843',
      allDay: false,
      extendedProps: { type: 'deadline' }
    });
  }

  if (hasSubmitted && submissionDate) {
    calendarEvents.push({
      title: submissionStatus === 'late' ? 'Late Submission' : 'Your Submission',
      start: submissionDate,
      color: submissionStatus === 'late' ? '#D4A843' : '#0D6B4D',
      allDay: false,
      extendedProps: { type: 'submission' }
    });
  }

  if (calendarEl) {
    const calendar = new FullCalendar.Calendar(calendarEl, {
      initialView: 'dayGridMonth',
      headerToolbar: { left: 'prev', center: 'title', right: 'next' },
      events: calendarEvents,
      eventClick: function(info) {
        const eventDate = new Date(info.event.start).toLocaleString('en-US', {
          month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });

        let description = '';
        let icon = 'info';
        if (info.event.extendedProps.type === 'deadline') {
          description = 'Final deadline for assessment submission';
          icon = 'warning';
        } else if (info.event.extendedProps.type === 'submission') {
          description = info.event.title.includes('Late')
            ? 'Your submission was received after the deadline'
            : 'Your assessment was successfully submitted';
          icon = 'success';
        }

        Swal.fire({
          title: info.event.title,
          html: `<div class="text-left">
                  <p class="text-gray-700 mb-2"><strong>Date:</strong> ${eventDate}</p>
                  ${description ? `<p class="text-gray-500">${description}</p>` : ''}
                </div>`,
          icon: icon,
          confirmButtonColor: '#1A4B8C',
          confirmButtonText: 'OK',
          customClass: { popup: 'rounded-2xl' }
        });
      },
      // 2026-09-03 - renders each event chip's icon via Remix Icon
      // (matching every other icon in this app) instead of the emoji
      // glyph that used to be baked into the title text.
      eventContent: function(arg) {
        const type = arg.event.extendedProps.type;
        let iconClass = 'ri-information-line';
        if (type === 'deadline') {
          iconClass = 'ri-flag-line';
        } else if (type === 'submission') {
          iconClass = arg.event.title === 'Late Submission' ? 'ri-time-line' : 'ri-checkbox-circle-fill';
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'fc-event-main-frame';
        wrapper.innerHTML = `<i class="${iconClass}" style="margin-right:0.3rem;vertical-align:-1px;"></i><span class="fc-event-title">${arg.event.title}</span>`;
        return { domNodes: [wrapper] };
      },
      // 2026-09-03 fix - was a fixed 320px. The calendar reskin grew each
      // day cell to min-height:3.1rem (better readability/tap targets),
      // but this fixed height was never increased to match - so months
      // needing 5-6 rows (which is most of them) got silently clipped,
      // losing the last 1-2 weeks of dates with no scrollbar to reveal
      // them. 'auto' lets the card grow to fit however many rows the
      // current month actually needs.
      height: 'auto',
      eventDisplay: 'block',
      dayMaxEvents: 2,
      dayCellContent: function(e) {
        e.dayNumberText = e.dayNumberText.replace('日', '');
      }
    });
    calendar.render();
  }

  // Notification dropdown toggle
  const notificationBtn = document.getElementById('notificationBtn');
  const notificationDropdown = document.getElementById('notificationDropdown');

  if (notificationBtn && notificationDropdown) {
    notificationBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notificationDropdown.classList.toggle('hidden');
      // Mark "dean approved a link" notifications read once the employee
      // actually opens the bell to see them - not just by visiting the
      // Training Recommendations page for some other reason.
      if (!notificationDropdown.classList.contains('hidden')) {
        fetch('mark_training_notifications_read.php', { method: 'POST' }).catch(() => {});
      }
    });

    document.addEventListener('click', (e) => {
      if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
        notificationDropdown.classList.add('hidden');
      }
    });
  }

  const assessmentFormWrapper = document.getElementById('assessmentFormWrapper');
  const assessmentModalBox = document.getElementById('assessmentModalBox');
  const showFormBtn = document.getElementById('showFormBtn');
  const closeAssessmentBtn = document.getElementById('closeAssessmentModal');

  // The empty-state card's own "Take Assessment Now" button (and its
  // reveal-then-scroll wiring that used to live here) was removed
  // 2026-08-29 - it duplicated this page's one real "Fill Out Training
  // Needs Assessment" button below, just styled differently and off-brand.

  // Assessment form as an actual modal (2026-08-29) - it used to be an
  // inline section that pushed the rest of the dashboard down and relied
  // on scrolling to it, which read as less clean than a focused popup.
  // Attached to window since the sidebar "Take Assessment" link's click
  // handler lives in a separate script block further down the page.
  window.openAssessmentModal = function() {
    if (!assessmentFormWrapper) return;
    assessmentFormWrapper.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    const mainContent = document.querySelector('.main-content');
    if (mainContent) mainContent.style.overflow = 'hidden';
  };

  window.closeAssessmentModal = function() {
    if (!assessmentFormWrapper) return;
    assessmentFormWrapper.style.display = 'none';
    document.body.style.overflow = '';
    const mainContent = document.querySelector('.main-content');
    if (mainContent) mainContent.style.overflow = '';
  };

  function maybeWarnLateSubmission(delay) {
    if (currentDeadlinePassed != 1) return;
    setTimeout(() => {
      Swal.fire({
        title: '⚠️ Late Submission',
        html: `<div class="text-left">
                <p class="mb-3">The submission deadline has passed, but you can still submit your assessment.</p>
                <div class="p-3 rounded-lg" style="background:#FDF6E8; border:1px solid #F5E6C8;">
                  <p class="text-sm" style="color:#B8922A;"><strong>Note:</strong> Your submission will be marked as late.</p>
                </div>
              </div>`,
        icon: 'warning',
        confirmButtonColor: '#D4A843',
        confirmButtonText: 'Continue',
        customClass: { popup: 'rounded-2xl' }
      });
    }, delay || 0);
  }

  if (showFormBtn) {
    showFormBtn.addEventListener('click', () => {
      openAssessmentModal();
      maybeWarnLateSubmission();
    });
  }

  if (closeAssessmentBtn) {
    closeAssessmentBtn.addEventListener('click', closeAssessmentModal);
  }
  // 2026-09-16: backdrop-click-to-close removed on purpose - a user could
  // lose a partially-filled assessment with one misplaced click. The X
  // button and Escape remain as the only explicit ways to dismiss it.
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && assessmentFormWrapper && assessmentFormWrapper.style.display === 'flex') {
      closeAssessmentModal();
    }
  });

  if (showAssessment == 1) {
    openAssessmentModal();
    maybeWarnLateSubmission(500);
  }

  <?php if (isset($_SESSION['form_submission_status'])): ?>
    const status = '<?= $_SESSION['form_submission_status'] ?>';
    const message = '<?= $_SESSION['form_submission_message'] ?? '' ?>';

    if (status === 'success') {
      Swal.fire({
        title: '🎉 Success!',
        text: message,
        icon: 'success',
        confirmButtonColor: '#0D6B4D',
        customClass: { popup: 'rounded-2xl' },
        willClose: () => { window.location.reload(); }
      });
    } else if (status === 'error') {
      Swal.fire({
        title: '❌ Error!',
        text: message,
        icon: 'error',
        confirmButtonColor: '#DC3545',
        customClass: { popup: 'rounded-2xl' }
      });
    }

    <?php
    unset($_SESSION['form_submission_status']);
    unset($_SESSION['form_submission_message']);
    ?>
  <?php endif; ?>

  <?php if (isset($_GET['profile_updated']) && $_GET['profile_updated'] === '1') : ?>
    Swal.fire({
      title: '✅ Profile Updated!',
      text: 'Your profile has been successfully updated.',
      icon: 'success',
      confirmButtonColor: '#1A4B8C',
      customClass: { popup: 'rounded-2xl' },
      timer: 2000,
      timerProgressBar: true
    }).then(() => {
      history.replaceState(null, null, window.location.pathname);
    });
  <?php endif; ?>

});

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
</script>

<?php if (isset($_GET['logout'])): ?>
  <script>
    localStorage.clear();
    sessionStorage.clear();
    window.location.href = 'index.php';
  </script>
  <?php
    session_destroy();
    exit();
  ?>
<?php endif; ?>
</body>
</html>