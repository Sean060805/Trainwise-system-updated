<?php
session_start();
require_once 'config.php'; // Make sure this contains your DB connection

$errors = [
  'login' => $_SESSION['login_error'] ?? '',
  'register' => $_SESSION['register_error'] ?? '',
  'register_success' => $_SESSION['register_success'] ?? '',
  'account_status' => $_SESSION['account_status'] ?? '',
  'pending' => $_SESSION['pending_message'] ?? '',
  'declined' => $_SESSION['declined_message'] ?? '',
  'disabled' => $_SESSION['disabled_message'] ?? '',
  'password_reset' => $_SESSION['password_reset'] ?? ''
];
$activeForm = $_SESSION['active_form'] ?? 'login';

// Clear session messages
unset($_SESSION['login_error']);
unset($_SESSION['register_error']);
unset($_SESSION['register_success']);
unset($_SESSION['account_status']);
unset($_SESSION['pending_message']);
unset($_SESSION['declined_message']);
unset($_SESSION['disabled_message']);
unset($_SESSION['password_reset']);
unset($_SESSION['active_form']);

function showError($error) {
  return !empty($error) ? "<p class='error_message'>$error</p>" : '';
}

function isActiveForm($formName, $activeForm) {
  return $formName === $activeForm ? 'active' : '';
}

// Department mapping for College Deans
$departments = [
  'CA' => 'College of Agriculture (CA)',
  'CAS' => 'College of Arts and Sciences (CAS)',
  'CBAA' => 'College of Business, Administration and Accountancy (CBAA)',
  'CCS' => 'College of Computer Studies (CCS)',
  'CCJE' => 'College of Criminal Justice Education (CCJE)',
  'COE' => 'College of Engineering (COE)',
  'CIT' => 'College of Industrial Technology (CIT)',
  'CFND' => 'College of Food, Nutrition and Dietetics (CFND)',
  'COF' => 'College of Fisheries (COF)',
  // 2026-09-17 fix - this was the actual root cause of real employees
  // ending up with department='CIHTM' while the college's own dean
  // account (set up separately, earlier) is 'CHMT': this registration
  // dropdown's option VALUE was literally 'CIHTM', so every new signup
  // who picked this college got that stored, never matching the dean's
  // code anywhere role-derivation or reporting depends on it. Value
  // changed to 'CHMT' (matching the dean's account and every other
  // reference to this college in the codebase) - label kept as the
  // college's real name/acronym.
  'CHMT' => 'College of International Hospitality and Tourism Management (CIHTM)',
  'CTE' => 'College of Teacher Education (CTE)',
  'CONAH' => 'College of Nursing and Allied Health (CONAH)',
  'COL' => 'College of Law (COL)'
];

// 2026-09-03 - added after real TAM testing feedback: the Faculty Member
// registration form had no designation/position field at all (silently
// left null), so profile.php's read-only "Designation" display just
// showed a generic "Faculty" for everyone with no real rank data. The
// respondent (COF faculty, real rank holder) pointed out this should be
// a dropdown of actual SUC/CHED academic ranks, same UX as the
// Department dropdown - not free text. Matches DBM-CHED Joint Circular
// No. 3 s.2022's salary-grade rank ladder. Kept as one flat list (not
// grouped by rank tier) since the visual salary-grade table it's sourced
// from already reads top-to-bottom in this exact order.
$facultyRanks = [
  'Instructor I', 'Instructor II', 'Instructor III',
  // 2026-09-17 - not part of the official salary-grade ladder above (part-
  // time faculty are paid per-unit, not on this scale), but added anyway:
  // real feedback from the first ~20 testers found most of them are
  // part-time and had no matching option, falling through to free-text
  // Other for what's actually a common, real designation.
  'Part-Time Instructor',
  'Assistant Professor I', 'Assistant Professor II', 'Assistant Professor III', 'Assistant Professor IV',
  'Associate Professor I', 'Associate Professor II', 'Associate Professor III', 'Associate Professor IV', 'Associate Professor V',
  'Professor I', 'Professor II', 'Professor III', 'Professor IV', 'Professor V', 'Professor VI',
];

// Kunin ang lahat ng existing positions at offices mula sa database
$existing_nonteaching_positions = [];
$existing_nonteaching_offices = [];
$existing_admin_positions = [];

try {
    $conn = new mysqli('localhost', 'root', '', 'lspu_training_tracker'); // I-update ang credentials
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Non-teaching positions
    $result = $conn->query("
        SELECT DISTINCT position 
        FROM users 
        WHERE role = 'non_teaching' 
          AND position IS NOT NULL 
          AND position != '' 
        ORDER BY position
    ");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $existing_nonteaching_positions[] = $row['position'];
        }
    }

    // Non-teaching offices/departments
    $result = $conn->query("
        SELECT DISTINCT department 
        FROM users 
        WHERE role = 'non_teaching' 
          AND department IS NOT NULL 
          AND department != '' 
        ORDER BY department
    ");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $existing_nonteaching_offices[] = $row['department'];
        }
    }

    // Admin positions only
    $result = $conn->query("
        SELECT DISTINCT position 
        FROM users 
        WHERE role = 'admin' 
          AND position IS NOT NULL 
          AND position != '' 
        ORDER BY position
    ");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $existing_admin_positions[] = $row['position'];
        }
    }

    $conn->close();
} catch (Exception $e) {
    // Kung walang database connection, mag-default sa empty array
    $existing_nonteaching_positions = [];
    $existing_nonteaching_offices = [];
    $existing_admin_positions = [];
}

// Role display names
$roleNames = [
  'user' => 'Faculty Member',
  'admin' => 'Human Resource Administrator',
  'dean' => 'College Dean',
  'non_teaching' => 'Non-Teaching Personnel'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>LSPU Training Tracker</title>

  <!-- Tailwind CSS CDN -->
  <link rel="stylesheet" href="assets/css/tw-45.css">

  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,500;9..144,600;9..144,700&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />

  <!-- External CSS -->
  <link rel="stylesheet" href="test_style.css" />

  <!-- Animate.css for smooth animations -->
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
      /* 2026-09-16 makeover - was referenced by .notification-error below
         but never actually defined, so that border silently fell back to
         nothing. Small real bug fix, found while redesigning this file. */
      --danger: #DC2626;
    }

    html, body {
      max-width: 100%;
      overflow-x: hidden;
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: 'Inter', sans-serif;
      color: var(--ink);
    }

    /* 2026-09-16 makeover - replaces the particles.js canvas + heavy
       diagonal gradient. A physics canvas of drifting dots reads as a
       template demo, not an official university portal - this keeps the
       exact same brand colors (royal/forest/gold, unchanged) but as a
       calm, fixed backdrop: a deep navy-to-royal base with two large,
       soft, out-of-focus color fields (a common "modern SaaS auth
       screen" treatment) instead of 70 moving particles competing with
       the form for attention. Zero JS, zero canvas, zero moving parts -
       lighter to load and calmer to look at. */
    body {
      position: relative;
      background: linear-gradient(165deg, #0A1628 0%, #123B6E 48%, #0D2B1A 100%);
      overflow: hidden;
    }

    body::before,
    body::after {
      content: '';
      position: fixed;
      border-radius: 50%;
      filter: blur(90px);
      pointer-events: none;
      z-index: 0;
    }

    body::before {
      width: 620px;
      height: 620px;
      top: -180px;
      left: -160px;
      background: radial-gradient(circle, rgba(212, 168, 67, 0.30), transparent 70%);
    }

    body::after {
      width: 720px;
      height: 720px;
      bottom: -240px;
      right: -200px;
      background: radial-gradient(circle, rgba(26, 75, 140, 0.38), transparent 70%);
    }

    .font-display { font-family: 'Fraunces', serif; }
    .font-eyebrow { font-family: 'Space Grotesk', sans-serif; }

    .eyebrow {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 0.62rem;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: var(--slate-strong);
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .eyebrow::before {
      content: '';
      width: 16px;
      height: 1px;
      background: var(--gold);
      display: inline-block;
    }

    .eyebrow.center {
      justify-content: center;
    }

    /* Para siguradong 100% viewport height ang main container */
    .page-wrap {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      min-height: 100dvh;
      height: 100vh;
      height: 100dvh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 15px 16px 70px;
      box-sizing: border-box;
      overflow: hidden;
    }

    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1000;
      max-width: 350px;
      width: 100%;
    }

    .notification-item {
      animation: slideInRight 0.3s ease-out forwards;
      box-shadow: 0 10px 30px -8px rgba(0, 0, 0, 0.25);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 10px;
      backdrop-filter: blur(6px);
    }

    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    .notification-success {
      background-color: #ECFDF5;
      border-left: 4px solid var(--forest);
      color: #065F46;
    }

    .notification-error {
      background-color: #FEF2F2;
      border-left: 4px solid var(--danger);
      color: #991B1B;
    }

    .notification-warning {
      background-color: #FFFBEB;
      border-left: 4px solid #F59E0B;
      color: #92400E;
    }

    .notification-info {
      background-color: #EFF6FF;
      border-left: 4px solid #3B82F6;
      color: #1E40AF;
    }

    /* ===== AUTO-FIT WRAPPER =====
       Ito ang nagpapa-scale sa buong seal + form container
       papasok sa loob ng 100% na visible viewport area, anuman ang laki
       ng active form (login vs register vs role forms). */
    .fit-container {
      width: 100%;
      display: flex;
      justify-content: center;
      transform-origin: top center;
      transition: transform 0.25s ease-out;
      will-change: transform;
    }

    .main-container {
      position: relative;
      width: 100%;
      max-width: 500px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding-top: 108px;
      transition: padding-top 0.25s ease-out;
    }

    /* 2026-09-09 - real accessibility feedback: hide the seal during
       registration and reclaim its reserved space for a bigger, more
       readable form (see showForm()'s comment for the full reasoning).
       Login keeps the seal since it's just one field. */
    .main-container.registering {
      padding-top: 18px;
    }

    .main-container.registering .logo-wrapper {
      display: none;
    }

    /* 2026-09-09 - real, live feedback (screenshot with the extra space
       circled): .login-container's 3rem top padding exists to clear the
       seal, which overlaps down into the card during login - with the
       seal hidden during registration (above), that padding is now just
       dead space stacked on top of #mainHeader's own margin. Both
       scoped to .registering only - login keeps its original spacing,
       since the seal genuinely needs that clearance there. Eyebrow/title
       text bumped up too per the same accessibility ask as .reg-field. */
    .main-container.registering .login-container {
      padding-top: 1.3rem;
    }

    /* 2026-09-09 - "make the box bigger, use the space below" was about
       the field-filled role forms specifically - scoping to .registering
       alone caught Login too (fixed), and ALSO caught the 4-tile role-
       PICKER screen (found live via a screenshot: it inherited the same
       wide cap and looked oversized for how little it holds). .role-
       form-active is toggled only while an actual role form is showing
       (see showRoleForm()/showRoleSelection()) - the picker screen and
       Login both keep the original 500px/480px sizing untouched. */
    .main-container.role-form-active {
      max-width: 640px;
    }

    .main-container.role-form-active .login-container {
      max-width: 620px;
    }

    .main-container.registering #mainHeader {
      margin-bottom: 6px;
    }

    .main-container.registering #mainHeader .eyebrow {
      font-size: 0.9rem;
    }

    .main-container.registering .role-badge {
      font-size: 0.85rem;
      padding: 5px 16px;
    }

    .main-container.registering h2 {
      font-size: 1.8rem !important;
    }

    .main-container.registering .back-to-roles {
      font-size: 1rem;
    }

    /* ===================================================
       LOGIN CONTAINER - clean, modern card
       =================================================== */
    /* 2026-09-16 makeover ("I don't feel the vibe... more professional") -
       keeps the same crisp white card + neutral shadow from the previous
       pass, tightened further: a single flat border instead of a
       border+inset-highlight combo, a calmer shadow (no hover-triggered
       jump), and a touch more corner radius for a softer, more current
       feel. Brand color (royal/gold) still only appears in the seal and
       the buttons - the card itself stays quiet so the content reads
       clearly, which is the actual "professional" signal. */
    .login-container {
      position: relative;
      z-index: 10;
      display: flex;
      flex-direction: column;
      background: #ffffff;
      border-radius: 1.35rem;
      padding: 2rem 2rem 1.75rem;
      padding-top: 3.1rem;
      width: 100%;
      max-width: 480px;
      box-shadow:
        0 24px 60px -20px rgba(2, 8, 20, 0.5),
        0 1px 2px rgba(2, 8, 20, 0.06);
      border: 1px solid rgba(22, 35, 58, 0.06);
      overflow: visible;
    }

    /* ===================================================
       LOGO / SEAL - clean static emblem
       =================================================== */
    /* 2026-09-16 makeover - the previous seal spun a ring of microtext
       around the logo continuously. Constant motion behind a form
       someone is trying to read/type into is the opposite of "calm and
       professional" - this keeps the same wrapper geometry (so the
       auto-fit measurement JS above needs zero changes) but replaces the
       spinning text ring with one still, soft gold ring and drops the
       floating bob animation on the logo itself. The emblem now just
       sits there, the way a university seal on an official letterhead
       does. */
    .logo-wrapper {
      position: absolute;
      top: -102px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 20;
      width: 190px;
      height: 190px;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .seal-ring {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
    }

    .logo-wrapper .logo-circle {
      position: relative;
      width: 124px;
      height: 124px;
      border-radius: 50%;
      background: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow:
        0 16px 32px -12px rgba(2, 8, 20, 0.5),
        0 0 0 6px rgba(255, 255, 255, 0.95),
        0 0 0 8px rgba(212, 168, 67, 0.5);
      border: none;
      overflow: hidden;
      padding: 12px;
    }

    .logo-wrapper .logo-circle img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      filter: none;
      image-rendering: auto;
    }

    .main-container:hover .login-container {
      box-shadow:
        0 30px 70px -18px rgba(2, 8, 20, 0.55),
        0 1px 2px rgba(2, 8, 20, 0.06);
    }

    .card-body {
      flex: 1 1 auto;
      overflow: visible;
      padding: 0.25rem 0 0;
      max-height: none;
    }

    footer {
      position: fixed;
      bottom: 0;
      left: 0;
      width: 100%;
      padding: 0.6rem 0;
      text-align: center;
      z-index: 10;
      background: transparent;
      border: none;
      pointer-events: none;
    }

    .footer-content {
      background: transparent;
      border: none;
      padding: 0 1rem;
      max-width: 800px;
      margin: 0 auto;
      pointer-events: auto;
    }

    footer p {
      margin: 0;
      line-height: 1.4;
      color: rgba(255, 255, 255, 0.95);
      font-size: 0.75rem;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(0, 0, 0, 0.8);
      font-weight: 500;
      letter-spacing: 0.3px;
    }

    footer a, footer span[role="button"] {
      color: var(--gold-light);
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
      cursor: pointer;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(0, 0, 0, 0.8);
      border-bottom: 1px dotted rgba(232, 201, 110, 0.5);
    }

    footer a:hover, footer span[role="button"]:hover {
      color: #FFD700;
      border-bottom-color: #FFD700;
      text-shadow: 0 2px 12px rgba(212, 168, 67, 0.8);
    }

    footer strong {
      color: #FFD700;
      font-weight: 700;
      text-shadow: 0 2px 10px rgba(212, 168, 67, 0.8);
    }

    /* ===================================================
       SIDE PANELS - dossier style, hero band + paper body
       =================================================== */
    .side-panel {
      height: 100vh;
      height: 100dvh;
      overflow: hidden;
      position: fixed;
      top: 0;
      width: 780px;
      max-width: 92%;
      background-color: var(--cream);
      box-shadow: 0 0 40px rgba(0, 0, 0, 0.35);
      transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
      z-index: 100;
      display: flex;
      flex-direction: column;
    }

    .panel-hero {
      position: relative;
      flex: 0 0 auto;
      padding: 28px 56px 22px 28px;
      background:
        radial-gradient(circle at 85% 0%, rgba(212,168,67,0.25), transparent 55%),
        linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      border-bottom: 1px solid rgba(212, 168, 67, 0.4);
    }

    .panel-hero .eyebrow {
      color: var(--gold-light);
    }

    .panel-hero .eyebrow::before {
      background: var(--gold-light);
    }

    .panel-hero h2 {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      color: #FDF8F0;
      font-size: 1.9rem;
      margin: 6px 0 4px;
      letter-spacing: 0.01em;
    }

    .panel-hero p {
      color: rgba(253, 248, 240, 0.72);
      font-size: 0.85rem;
      max-width: 460px;
    }

    .panel-hero-rule {
      width: 56px;
      height: 2px;
      background: var(--gold-light);
      margin-top: 12px;
      border-radius: 2px;
    }

    .side-panel-content {
      flex: 1 1 auto;
      overflow-y: auto;
      padding: 26px 28px 40px;
      position: relative;
      background: var(--cream);
    }

    .side-panel-content::-webkit-scrollbar {
      width: 8px;
    }

    .side-panel-content::-webkit-scrollbar-track {
      background: var(--cream-dim);
      border-radius: 10px;
    }

    .side-panel-content::-webkit-scrollbar-thumb {
      background: var(--gold-soft);
      border-radius: 10px;
    }

    .side-panel-content::-webkit-scrollbar-thumb:hover {
      background: var(--gold);
    }

    .side-panel-content::after {
      content: '';
      position: sticky;
      bottom: -1px;
      left: 0;
      right: 0;
      height: 40px;
      background: linear-gradient(to bottom, rgba(253,248,240,0), rgba(253,248,240,1));
      pointer-events: none;
      display: block;
    }

    /* Dossier content primitives */
    .dossier-card {
      background: #FFFFFF;
      border: 1px solid var(--border-soft);
      border-radius: 14px;
      padding: 20px;
      box-shadow: 0 12px 24px -18px rgba(0, 15, 35, 0.35);
    }

    .dossier-card + .dossier-card {
      margin-top: 20px;
    }

    .section-heading {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      color: var(--ink);
      font-size: 1.35rem;
      margin-bottom: 14px;
    }

    .stat-strip {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      border: 1px solid var(--border-soft);
      border-radius: 14px;
      overflow: hidden;
      background: #FFFFFF;
    }

    .stat-strip .stat {
      padding: 16px 10px;
      text-align: center;
      border-left: 1px solid #E8DDD0;
    }

    .stat-strip .stat:first-child {
      border-left: none;
    }

    .stat-num {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      font-size: 1.55rem;
      color: var(--royal);
      display: block;
      line-height: 1;
    }

    .stat-label {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 0.62rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--ink-soft);
      display: block;
      margin-top: 6px;
    }

    .feature-grid {
      display: grid;
      gap: 14px;
    }

    @media (min-width: 640px) {
      .feature-grid { grid-template-columns: 1fr 1fr; }
    }

    .feature-card {
      background: #FFFFFF;
      border: 1px solid var(--border-soft);
      border-radius: 12px;
      padding: 16px;
      transition: box-shadow 0.25s ease, transform 0.25s ease;
    }

    .feature-card:hover {
      box-shadow: 0 14px 26px -16px rgba(0, 15, 35, 0.4);
      transform: translateY(-2px);
    }

    .feature-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 10px;
      font-size: 1.1rem;
      color: #fff;
    }

    .feature-card h4 {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      font-size: 1rem;
      color: var(--ink);
      margin-bottom: 4px;
    }

    .feature-card p {
      color: var(--ink-soft);
      font-size: 0.85rem;
      line-height: 1.45;
    }

    .step-row {
      display: flex;
      gap: 14px;
      align-items: flex-start;
    }

    .step-row + .step-row {
      margin-top: 16px;
    }

    .step-num {
      flex: 0 0 auto;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--royal);
      color: var(--gold-light);
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 700;
      font-size: 0.8rem;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .step-row h5 {
      font-weight: 700;
      font-size: 0.9rem;
      color: var(--ink);
      margin-bottom: 2px;
    }

    .step-row p {
      font-size: 0.83rem;
      color: var(--ink-soft);
    }

    .tech-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border: 1px solid var(--border-soft);
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--ink);
      background: #fff;
    }

    .tech-chip i {
      color: var(--royal);
    }

    .faq-item {
      border: 1px solid var(--border-soft);
      border-radius: 12px;
      background: #fff;
      padding: 4px 16px;
    }

    .faq-item + .faq-item {
      margin-top: 10px;
    }

    .faq-item summary {
      cursor: pointer;
      list-style: none;
      padding: 12px 0;
      font-weight: 700;
      font-size: 0.88rem;
      color: var(--ink);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .faq-item summary::-webkit-details-marker { display: none; }

    .faq-item summary::after {
      content: '\f234';
      font-family: 'remixicon';
      color: var(--royal);
      transition: transform 0.25s ease;
      font-size: 0.9rem;
    }

    .faq-item[open] summary::after {
      transform: rotate(45deg);
    }

    .faq-item p {
      padding-bottom: 14px;
      color: var(--ink-soft);
      font-size: 0.85rem;
      line-height: 1.5;
    }

    .pull-quote {
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      border-radius: 14px;
      padding: 22px 24px;
      position: relative;
      color: #FDF8F0;
    }

    .pull-quote i {
      color: var(--gold-light);
      font-size: 1.6rem;
      opacity: 0.8;
    }

    .pull-quote p {
      font-family: 'Fraunces', serif;
      font-size: 1.05rem;
      line-height: 1.5;
      margin-top: 6px;
    }

    .advisor-card {
      background: #FFFFFF;
      border: 1px solid var(--border-soft);
      border-radius: 12px;
      padding: 18px;
      text-align: center;
    }

    .advisor-avatar {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
      font-size: 1.3rem;
      color: #fff;
    }

    .advisor-card h4 {
      font-weight: 700;
      font-size: 0.9rem;
      color: var(--ink);
    }

    .advisor-card .role {
      font-size: 0.75rem;
      font-weight: 600;
      margin-top: 2px;
    }

    .advisor-card .desc {
      font-size: 0.78rem;
      color: var(--ink-soft);
      margin-top: 8px;
      line-height: 1.4;
    }

    .form-transition {
      transition: all 0.3s ease-in-out;
    }

    .error-message {
      background-color: #FEF2F2;
      border: 1px solid #FECACA;
      color: #B91C1C;
      padding: 0.5rem;
      border-radius: 0.5rem;
      margin-bottom: 0.6rem;
      font-size: 0.78rem;
      display: flex;
      align-items: center;
    }

    .error-message i {
      margin-right: 0.4rem;
    }

    .success-message {
      background-color: #ECFDF5;
      border: 1px solid #A7F3D0;
      color: #065F46;
      padding: 0.5rem;
      border-radius: 0.5rem;
      margin-bottom: 0.6rem;
      font-size: 0.78rem;
      display: flex;
      align-items: center;
    }

    .success-message i {
      margin-right: 0.4rem;
    }

    /* 2026-09-16 makeover - bigger tap targets and a left-aligned
       icon+text layout instead of the old cramped, centered stack (icon
       chip / bold title / tiny caption all squeezed into ~12px of
       padding) - reads as a real selectable option now, not a small
       decorative tile. Same onclick="showRoleForm(...)" hooks, so this
       is a pure visual change. */
    .role-card {
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1.5px solid var(--border-soft);
      border-radius: 14px;
      padding: 14px;
      cursor: pointer;
      transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
      text-align: left;
      background: #ffffff;
      position: relative;
    }

    .role-card:hover {
      border-color: var(--gold);
      transform: translateY(-2px);
      box-shadow: 0 12px 24px -12px rgba(0, 15, 35, 0.28);
    }

    .role-card.selected {
      border-color: var(--gold);
      background-color: var(--gold-soft);
    }

    .role-icon-chip {
      flex: 0 0 auto;
      width: 42px;
      height: 42px;
      border-radius: 11px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      color: #fff;
    }

    /* Groups the title+caption as one stacked column next to the icon -
       without this, h3/p were separate flex siblings alongside the icon
       and the caption wrapped into a cramped, misaligned strip. */
    .role-card-text {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .role-card h3 {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      margin-bottom: 1px;
      font-size: 0.92rem;
      color: var(--ink);
      line-height: 1.2;
    }

    .role-card p {
      font-size: 0.74rem;
      color: var(--ink-soft);
      margin: 0;
      line-height: 1.3;
    }

    .role-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .department-select {
      width: 100%;
      padding: 14px 38px 14px 16px;
      background: #FAFBFC;
      border: 1.5px solid #E2E8F0;
      border-radius: 10px;
      outline: none;
      font-size: 18px;
      color: var(--ink);
      appearance: none;
      background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg width='12' height='8' viewBox='0 0 10 7' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 6L9 1' stroke='%2352627B' stroke-width='2'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 12px 8px;
      cursor: pointer;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }

    .department-select:focus {
      border-color: var(--royal);
      box-shadow: 0 0 0 4px rgba(26, 75, 140, 0.12);
      background-color: #fff;
    }

    .form-input {
      width: 100%;
      padding: 14px 16px;
      background: #FAFBFC;
      border: 1.5px solid #E2E8F0;
      border-radius: 10px;
      outline: none;
      font-size: 18px;
      color: var(--ink);
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }

    /* 2026-09-09 - real accessibility feedback: the real user base is
       largely staff in their 30s-40s, some needing glasses (named
       example: Dexter) - the registration fields were reading too small.
       Added alongside the existing Tailwind utility classes on these
       inputs rather than replacing them (border/radius/focus-ring still
       come from those, confirmed working) - !important on just these two
       properties since this page's Tailwind build is a purged bundle
       (see .name-fields-row's comment above) and cascade order against
       whatever utilities happened to survive that purge isn't reliable
       to predict. */
    .reg-field {
      font-size: 18px !important;
      padding: 14px 16px !important;
      /* 2026-09-16 makeover - matches .form-input/.department-select's new
         look (same !important reasoning as font-size/padding above: this
         page's Tailwind build is a purged bundle, cascade order against
         whatever utilities survived isn't reliable). */
      background: #FAFBFC !important;
      border: 1.5px solid #E2E8F0 !important;
      border-radius: 10px !important;
    }

    .reg-field:focus {
      background: #ffffff !important;
      border-color: var(--royal) !important;
    }

    /* 2026-09-09 - second round, same accessibility ask ("make them
       bigger more") - the field/select text above already went 13px ->
       16px -> 18px; this round covers everything that was still at its
       original small size: labels, the submit button, and the badge/
       title/eyebrow bumped earlier get bumped again to match. */
    /* !important because .compact-form label (defined later in this file,
       same specificity) was winning the cascade and holding every
       registration label down at 0.95rem (~15px) despite this rule -
       confirmed live via computed styles, not just theorized. */
    .role-specific-form label {
      font-size: 19px !important;
    }

    /* 2026-09-16 - real, reported bug found while making another round
       of text bigger per accessibility feedback: "form > p" only matches
       a <p> that's a DIRECT child of <form>, but "Use your official LSPU
       email address" sits one level deeper (inside the field's own
       wrapper <div>), so this rule was never actually applying to it -
       it had been rendering at Tailwind's text-xs (~12px) the whole
       time. Matches on the exact class combo instead, at any depth. */
    .role-specific-form p.text-xs.text-gray-500 {
      font-size: 15px !important;
    }

    /* "Don't have an account? Register here" / "Already have an
       account? Login here" - same text-xs default, same fix. Not
       scoped to .role-specific-form since the login form's version
       lives outside it. */
    p.text-xs.text-gray-600 {
      font-size: 16px !important;
    }

    .reg-field::placeholder {
      font-size: 16px;
    }

    /* 2026-09-09 - First/M.I./Last Name row for the 4 registration forms.
       Real CSS grid defined here (like .role-grid above), NOT a Tailwind
       utility class - this page's grid/gap classes are served from a
       purged/compiled build (assets/css/tw-45.css) that only includes
       whatever combinations the original templates happened to use; a
       bare grid-cols-3 added later compiles to nothing and silently
       collapses to a plain stacked block, which is exactly the "awkward"
       layout this replaces. M.I. gets a narrow fixed column since it's
       at most a couple characters; First/Last share the rest evenly. */
    /* 2026-09-16 - "Full Name" should read as a section header over the
       First/M.I./Last row, not just another field label at the same
       weight as everything else. The row of 3 name fields right below
       this (.name-fields-row) is untouched, per explicit instruction. */
    .name-section-label {
      font-weight: 700;
      font-size: 1.15rem;
      color: var(--ink);
      margin-bottom: 6px;
    }

    .name-fields-row {
      display: grid;
      grid-template-columns: 1fr 76px 1fr;
      gap: 10px;
    }

    /* 2026-09-16 - same purged-Tailwind gotcha as .name-fields-row above:
       "sm:grid-cols-2" was never used by the original templates this
       bundle was compiled from, so it silently compiles to nothing and
       the two Development Team cards collapsed to a single stacked
       column instead of sitting side by side. Real CSS grid, like every
       other fix for this class of bug on this page. */
    .team-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    @media (max-width: 640px) {
      .team-grid {
        grid-template-columns: 1fr;
      }
    }

    .name-fields-row .form-input {
      text-align: left;
    }

    .form-input:focus {
      border-color: var(--royal);
      box-shadow: 0 0 0 4px rgba(26, 75, 140, 0.12);
      background: #fff;
    }

    /* 2026-09-16 - the Non-Teaching form's two dropdowns use .form-input
       (shared with plain text inputs) rather than .department-select, so
       they rendered with the browser's default select arrow instead of
       the custom chevron every other dropdown on this page uses -
       inconsistent within the same form. Scoped to select.form-input so
       text inputs sharing the class are unaffected. */
    select.form-input {
      appearance: none;
      background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg width='12' height='8' viewBox='0 0 10 7' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 6L9 1' stroke='%2352627B' stroke-width='2'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 12px 8px;
      padding-right: 38px;
      cursor: pointer;
    }

    .left-panel {
      left: -400px;
      transform: translateX(-100%);
    }

    .left-panel.open {
      transform: translateX(0);
      left: 0;
    }

    .right-panel {
      right: -400px;
      transform: translateX(100%);
    }

    .right-panel.open {
      transform: translateX(0);
      right: 0;
    }

    .click-zone {
      position: fixed;
      top: 0;
      width: 50px;
      height: 100%;
      z-index: 50;
      cursor: pointer;
      transition: background-color 0.3s;
    }

    .click-zone:hover {
      background-color: transparent;
    }

    .left-zone {
      left: 0;
    }

    .right-zone {
      right: 0;
    }

    .main-zone {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 90;
      cursor: pointer;
      display: none;
    }

    .main-zone.active {
      display: block;
    }

    .panel-close-btn {
      position: absolute;
      top: 18px;
      right: 18px;
      background: rgba(253, 248, 240, 0.08);
      border: 1px solid rgba(212, 168, 67, 0.5);
      font-size: 1.1rem;
      cursor: pointer;
      color: var(--gold-light);
      z-index: 101;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
    }

    .panel-close-btn:hover {
      color: #ffffff;
      background-color: rgba(26, 75, 140, 0.35);
      border-color: var(--royal);
    }

    .spinner {
      animation: spin 1s linear infinite;
      display: inline-block;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* 2026-09-16 makeover - same three brand gradients (royal/forest),
       consistent radius/weight system across all four button roles
       instead of four slightly different font-sizes and border-radii.
       Dropped the ripple pseudo-element (.btn::after) and the
       active:scale(0.95) squash - a clean, slightly-lifted hover plus a
       real focus-visible ring reads as more deliberate/professional than
       a bouncing click effect, and works better for keyboard users. */
    .btn-login,
    .btn-register,
    .btn-submit,
    .btn-signin {
      border-radius: 12px;
      font-weight: 600;
      letter-spacing: 0.2px;
      transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
    }

    .btn-login:focus-visible,
    .btn-register:focus-visible,
    .btn-submit:focus-visible,
    .btn-signin:focus-visible {
      outline: 2px solid var(--gold);
      outline-offset: 2px;
    }

    .btn-login {
      font-size: 1.05rem;
      padding: 0.95rem 1rem;
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      color: white;
      box-shadow: 0 12px 26px -10px rgba(26, 75, 140, 0.55);
    }

    .btn-login:hover {
      transform: translateY(-2px);
      filter: brightness(1.06);
      box-shadow: 0 16px 32px -10px rgba(26, 75, 140, 0.6);
    }

    .btn-register {
      font-size: 1.05rem;
      padding: 0.9rem 1rem;
      background: #ffffff;
      color: var(--forest);
      border: 1.5px solid var(--forest);
      box-shadow: none;
    }

    .btn-register:hover {
      background: #F0FAF6;
      transform: translateY(-2px);
    }

    .btn-submit {
      font-size: 1.05rem;
      padding: 0.95rem 1rem;
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      color: white;
      box-shadow: 0 12px 26px -10px rgba(26, 75, 140, 0.5);
    }

    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 32px -10px rgba(26, 75, 140, 0.55);
    }

    .btn-signin {
      font-size: 1.05rem;
      padding: 0.95rem 1rem;
      background: linear-gradient(135deg, var(--royal) 0%, var(--royal-2) 100%);
      color: white;
      box-shadow: 0 12px 26px -10px rgba(26, 75, 140, 0.5);
    }

    .btn-signin:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 32px -10px rgba(26, 75, 140, 0.55);
    }

    .btn:active {
      transform: translateY(0);
    }

    .role-specific-form {
      background: #F8F9FB;
      border-radius: 14px;
      padding: 22px 24px;
      margin-top: 10px;
      border: 1px solid var(--border-soft);
    }

    /* 2026-09-16 makeover - bumped from a 0.63rem all-caps micro-tag to a
       readable pill that actually states which registration this is,
       matching the "big and readable" ask everywhere else on this form. */
    .role-badge {
      display: inline-block;
      padding: 5px 14px;
      border-radius: 20px;
      font-size: 0.78rem;
      letter-spacing: 0.01em;
      font-weight: 700;
      margin-bottom: 12px;
    }

    .badge-user {
      background-color: #e0f2fe;
      color: #0369a1;
    }

    .badge-admin {
      background-color: #dbeafe;
      color: #1e3a8a;
    }

    .badge-dean {
      background-color: #e0e7ef;
      color: #123A44;
    }

    .badge-nonteaching {
      background-color: #e2e8f0;
      color: #334155;
    }

    .back-to-roles {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--royal);
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      margin-bottom: 12px;
      padding: 5px 10px;
      border-radius: 20px;
      transition: background-color 0.2s ease, transform 0.2s ease;
    }

    .back-to-roles:hover {
      background-color: var(--gold-soft);
      transform: translateX(-3px);
    }

    .back-to-roles i {
      font-size: 0.85rem;
    }

    .role-form-container {
      position: relative;
      min-height: auto;
    }

    .role-section {
      transition: all 0.4s ease-in-out;
    }

    .role-section.hidden {
      display: none;
      opacity: 0;
      transform: translateY(20px);
    }

    .role-section:not(.hidden) {
      display: block;
      opacity: 1;
      transform: translateY(0);
    }

    /* Compact styles para sa forms.
       2026-09-03 - bumped up from 0.8rem/0.85rem after real TAM testing
       feedback: a respondent in his 40s-50s (wearing reading glasses)
       had to zoom the browser to 120% just to read the login form
       comfortably. Older users are a real part of the userbase here
       (LSPU faculty spans a wide age range), not an edge case - the
       "compact" sizing traded readability for fitting more on screen,
       which is the wrong tradeoff for this audience. The panel already
       has overflow-y:auto (see .side-panel-content), so a slightly
       taller form just scrolls instead of breaking anything. */
    .compact-form label {
      font-size: 0.95rem;
      margin-bottom: 0.3rem;
      font-weight: 500;
    }

    .compact-form input,
    .compact-form select {
      font-size: 1.05rem;
      padding: 11px 13px;
    }

    .compact-form .space-y-3 > * + * {
      margin-top: 0.6rem;
    }

    .tracker-title {
      font-family: 'Fraunces', serif;
      font-size: 1.7rem;
      margin-bottom: 4px;
      font-weight: 700;
      color: var(--ink);
      letter-spacing: -0.01em;
    }

    .tracker-subtitle {
      font-size: 0.85rem;
      margin-bottom: 12px;
      color: var(--ink-soft);
      line-height: 1.4;
    }

    /* ===== MOBILE / RESPONSIVE ===== */
    @media (max-width: 640px) {
      .page-wrap {
        padding: 10px 12px 60px;
      }

      .main-container {
        padding-top: 92px;
        max-width: 100%;
      }

      .login-container {
        padding: 1rem 0.8rem;
        padding-top: 2.4rem;
        border-radius: 1rem;
        max-width: 100%;
      }

      .logo-wrapper {
        top: -80px;
        width: 148px;
        height: 148px;
      }

      .logo-wrapper .logo-circle {
        width: 100px;
        height: 100px;
        padding: 7px;
      }

      .tracker-title {
        font-size: 1.3rem;
      }

      .tracker-subtitle {
        font-size: 0.7rem;
      }

      .role-grid {
        grid-template-columns: 1fr 1fr;
        gap: 6px;
      }

      .role-card {
        padding: 9px 6px;
      }

      .role-card h3 {
        font-size: 0.7rem;
      }

      .role-card p {
        font-size: 0.6rem;
      }

      .side-panel {
        width: 100%;
        max-width: 100%;
      }

      .panel-hero {
        padding: 22px 50px 18px 20px;
      }

      .panel-hero h2 {
        font-size: 1.5rem;
      }

      .side-panel-content {
        padding: 18px 18px 34px;
      }

      .stat-strip {
        grid-template-columns: repeat(2, 1fr);
      }

      .stat-strip .stat:nth-child(3) {
        border-left: none;
      }

      .click-zone {
        width: 20px;
      }

      .notification {
        right: 10px;
        left: 10px;
        max-width: none;
        width: auto;
        top: 10px;
      }

      .btn-login {
        font-size: 0.95rem;
        padding: 0.7rem 0.85rem;
      }

      .btn-register {
        font-size: 0.9rem;
        padding: 0.6rem 0.8rem;
      }

      .btn-submit {
        font-size: 0.9rem;
        padding: 0.6rem 0.8rem;
      }

      .btn-signin {
        font-size: 0.9rem;
        padding: 0.65rem 0.8rem;
      }

      .role-specific-form {
        padding: 10px 12px;
      }
    }

    @media (max-width: 380px) {
      .role-grid {
        grid-template-columns: 1fr;
      }

      .logo-wrapper {
        top: -66px;
        width: 122px;
        height: 122px;
      }

      .logo-wrapper .logo-circle {
        width: 82px;
        height: 82px;
        padding: 6px;
      }

      .login-container {
        padding: 0.85rem 0.65rem;
        padding-top: 2rem;
      }

      .tracker-title {
        font-size: 1.05rem;
      }
    }

    @media (max-height: 700px) {
      .main-container {
        padding-top: 80px;
      }

      .login-container {
        padding: 0.75rem 0.95rem;
        padding-top: 2rem;
      }

      .logo-wrapper {
        top: -66px;
        width: 128px;
        height: 128px;
      }

      .logo-wrapper .logo-circle {
        width: 88px;
        height: 88px;
        padding: 6px;
      }

      .tracker-title {
        font-size: 1.15rem;
      }

      .tracker-subtitle {
        font-size: 0.65rem;
        margin-bottom: 5px;
      }

      .role-card {
        padding: 6px 4px;
      }

      .role-card h3 {
        font-size: 0.65rem;
      }

      .btn-login {
        font-size: 0.85rem;
        padding: 0.5rem 0.7rem;
      }

      .btn-register {
        font-size: 0.75rem;
        padding: 0.4rem 0.6rem;
      }

      .btn-submit {
        font-size: 0.65rem;
        padding: 0.35rem 0.55rem;
      }

      .btn-signin {
        font-size: 0.8rem;
        padding: 0.45rem 0.65rem;
      }

      .compact-form input,
      .compact-form select {
        padding: 9px 11px;
        font-size: 0.95rem;
      }

      .compact-form label {
        font-size: 0.85rem;
      }

      .role-specific-form {
        padding: 6px 8px;
        margin-top: 5px;
      }

      .role-grid {
        gap: 4px;
      }
    }

    @media (min-height: 900px) {
      .logo-wrapper {
        width: 208px;
        height: 208px;
        top: -112px;
      }

      .logo-wrapper .logo-circle {
        width: 140px;
        height: 140px;
      }
    }

    /* Respect users who prefer less motion */
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
        scroll-behavior: auto !important;
      }
      .fit-container {
        transition: none !important;
      }
    }
  </style>
</head>
<body>

<!-- Notification area -->
<div class="notification" id="notificationArea">
  <?php if (!empty($errors['login'])): ?>
    <div class="notification-item notification-error animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-error-warning-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Login Error</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['login']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['register'])): ?>
    <div class="notification-item notification-error animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-error-warning-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Registration Error</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['register']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['register_success'])): ?>
    <div class="notification-item notification-success animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-checkbox-circle-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Registration Successful</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['register_success']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['pending'])): ?>
    <div class="notification-item notification-warning animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-time-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Account Pending</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['pending']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['declined'])): ?>
    <div class="notification-item notification-error animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-close-circle-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Account Declined</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['declined']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['disabled'])): ?>
    <div class="notification-item notification-error animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="ri-forbid-line text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Account Disabled</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['disabled']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors['account_status'])): ?>
    <div class="notification-item notification-<?php echo strpos($errors['account_status'], 'approved') !== false ? 'success' : 'warning'; ?> animate__animated animate__slideInRight">
      <div class="p-3 flex items-start">
        <i class="<?php echo strpos($errors['account_status'], 'approved') !== false ? 'ri-checkbox-circle-line' : 'ri-alert-line'; ?> text-lg mr-2"></i>
        <div>
          <h4 class="font-bold text-sm mb-0.5">Account Status</h4>
          <p class="text-sm"><?php echo htmlspecialchars($errors['account_status']); ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Click zones -->
<div class="click-zone left-zone" id="left-zone" title="System Info" role="button" aria-controls="left-panel" aria-expanded="false" tabindex="0"></div>
<div class="click-zone right-zone" id="right-zone" title="About Us" role="button" aria-controls="right-panel" aria-expanded="false" tabindex="0"></div>
<div class="main-zone" id="main-zone" title="Close Panel" role="button" tabindex="0"></div>

<!-- Left panel (About the System) -->
<aside class="side-panel left-panel" id="left-panel" aria-hidden="true" tabindex="-1">
  <div class="panel-hero">
    <span class="eyebrow">Dossier 01 · System Overview</span>
    <h2>About the System</h2>
    <p>What the LSPU Training Tracker does, who it's for, and how it fits into your daily record-keeping.</p>
    <div class="panel-hero-rule"></div>
  </div>
  <button class="panel-close-btn" id="close-left-panel" aria-label="Close panel">
    <i class="ri-close-line"></i>
  </button>

  <div class="side-panel-content">

    <div class="dossier-card mb-6">
      <p style="color:var(--ink); line-height:1.65;">
        The <strong style="color:var(--royal);">LSPU Training Tracker System</strong> is an AI-assisted platform built for the Los Baños campus. It matches every faculty member, non-teaching personnel, or administrative staff to the trainings that matter for their role, then carries that request all the way through to a real, sourced training — from a personalized recommendation, to pooled institutional demand, to a college dean sourcing a provider, to a completed, documented record.
      </p>
    </div>

    <div class="pull-quote mb-6">
      <i class="ri-double-quotes-l"></i>
      <p>From a personalized recommendation to a completed, documented training — one connected pipeline.</p>
    </div>

    <div class="stat-strip mb-8">
      <div class="stat">
        <span class="stat-num">4</span>
        <span class="stat-label">User Roles</span>
      </div>
      <div class="stat">
        <span class="stat-num">2</span>
        <span class="stat-label">AI Models</span>
      </div>
      <div class="stat">
        <span class="stat-num">13</span>
        <span class="stat-label">Colleges Connected</span>
      </div>
      <div class="stat">
        <span class="stat-num">Digital</span>
        <span class="stat-label">Recordkeeping</span>
      </div>
    </div>

    <span class="eyebrow mb-3">Capabilities</span>
    <h3 class="section-heading" style="margin-top:6px;">Key Features</h3>

    <div class="feature-grid mb-8">
      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#1A4B8C,#0F3460);">
          <i class="ri-sparkling-2-fill"></i>
        </div>
        <h4>AI-Powered Recommendations</h4>
        <p>An XGBoost + SBERT recommendation engine matches each employee to trainings based on their role, profile, and stated needs.</p>
      </div>

      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#0D6B4D,#084A34);">
          <i class="ri-git-branch-fill"></i>
        </div>
        <h4>Training Demand Pipeline</h4>
        <p>Real employee requests are pooled by HR, routed to college deans to source a paid provider, and tracked through to completion.</p>
      </div>

      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#1A4B8C,#0F3460);">
          <i class="ri-file-list-3-fill"></i>
        </div>
        <h4>Individual Development Plans</h4>
        <p>Document long-term and short-term growth goals, with digital sign-off from employee, supervisor, and campus director.</p>
      </div>

      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#D4A843,#B8922A);">
          <i class="ri-bar-chart-box-fill"></i>
        </div>
        <h4>Reports & Analytics</h4>
        <p>Track completion rates, training demographics, and monthly training trends across every college.</p>
      </div>

      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#5B7288,#37485C);">
          <i class="ri-history-fill"></i>
        </div>
        <h4>Audit Log</h4>
        <p>A full accountability trail of key actions across the system, from approvals to demand-pipeline decisions.</p>
      </div>

      <div class="feature-card">
        <div class="feature-icon" style="background:linear-gradient(135deg,#16233A,#1A4B8C);">
          <i class="ri-notification-fill"></i>
        </div>
        <h4>Real-Time Notifications</h4>
        <p>Get timely alerts for deadlines, approvals, and training updates as your request moves through the pipeline.</p>
      </div>
    </div>

    <div class="dossier-card mb-8" style="background:var(--cream-dim); border-color:#E8DDD0;">
      <h3 class="section-heading">System Benefits</h3>
      <ul class="space-y-2.5" style="color:var(--ink-soft); font-size:0.88rem; line-height:1.5;">
        <li class="flex items-start gap-2"><i class="ri-check-line" style="color:var(--royal); margin-top:3px;"></i> Matches every employee to relevant trainings using AI, not a generic catalog list</li>
        <li class="flex items-start gap-2"><i class="ri-check-line" style="color:var(--royal); margin-top:3px;"></i> Pools real training demand across all 13 colleges instead of one-off requests</li>
        <li class="flex items-start gap-2"><i class="ri-check-line" style="color:var(--royal); margin-top:3px;"></i> Gives HR and college deans a clear pipeline from request to a sourced, confirmed training</li>
        <li class="flex items-start gap-2"><i class="ri-check-line" style="color:var(--royal); margin-top:3px;"></i> Keeps a full audit trail of key decisions for accountability and accreditation</li>
        <li class="flex items-start gap-2"><i class="ri-check-line" style="color:var(--royal); margin-top:3px;"></i> Makes training history easy to find for professional growth reviews</li>
      </ul>
    </div>

    <h3 class="section-heading">Getting Started</h3>
    <div class="dossier-card mb-8">
      <p class="eyebrow mb-3">For First-Time Users</p>
      <div class="mb-6">
        <div class="step-row">
          <div class="step-num">1</div>
          <div><h5>Register</h5><p>Sign up using your official LSPU email address.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">2</div>
          <div><h5>Complete your profile</h5><p>Fill in your department, position, and role details.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">3</div>
          <div><h5>Wait for verification</h5><p>Account approval typically takes within 24 hours.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">4</div>
          <div><h5>Log in</h5><p>Access your personal training dashboard.</p></div>
        </div>
      </div>

      <p class="eyebrow mb-3">After Login</p>
      <div>
        <div class="step-row">
          <div class="step-num">1</div>
          <div><h5>Take your Training Needs Assessment</h5><p>Tell the system what skills or trainings you're looking for.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">2</div>
          <div><h5>Get AI-matched recommendations</h5><p>Review trainings picked specifically for your role and stated needs.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">3</div>
          <div><h5>Accept a recommendation</h5><p>Your request joins the pooled demand HR and your college dean act on.</p></div>
        </div>
        <div class="step-row">
          <div class="step-num">4</div>
          <div><h5>Upload proof once confirmed</h5><p>Once you're confirmed for a training, upload your certificate to complete the record.</p></div>
        </div>
      </div>
    </div>

    <h3 class="section-heading">Frequently Asked Questions</h3>
    <div class="mb-6">
      <details class="faq-item">
        <summary>Who can register for an account?</summary>
        <p>Faculty members, non-teaching personnel, college deans, and Human Resource administrators of the Los Baños campus can all register, each under their own role-specific form.</p>
      </details>
      <details class="faq-item">
        <summary>What happens while my account is pending?</summary>
        <p>Your registration is reviewed by an administrator before you can log in. You'll see a status notification here once your account has been acted on.</p>
      </details>
      <details class="faq-item">
        <summary>Can I update my department or position later?</summary>
        <p>Yes — once logged in, profile details can be updated from your dashboard so your training records stay accurate.</p>
      </details>
      <details class="faq-item">
        <summary>How are training recommendations chosen?</summary>
        <p>An AI model matches your role, department, and stated needs against the training catalog and real institutional demand data, so suggestions are specific to you rather than a generic list.</p>
      </details>
    </div>

    <div class="dossier-card" style="text-align:center;">
      <p class="text-sm" style="color:var(--ink-soft);">
        Need assistance? Contact the system administrator at
        <span style="color:var(--royal); font-weight:600;">training.tracker@lspu.edu.ph</span>
      </p>
    </div>
  </div>
</aside>

<!-- Right panel (About Us) -->
<aside class="side-panel right-panel" id="right-panel" aria-hidden="true" tabindex="-1">
  <div class="panel-hero">
    <span class="eyebrow">Dossier 02 · Our Team</span>
    <h2>About Us</h2>
    <p>The people, guidance, and process behind the LSPU Training Tracker System.</p>
    <div class="panel-hero-rule"></div>
  </div>
  <button class="panel-close-btn" id="close-right-panel" aria-label="Close panel">
    <i class="ri-close-line"></i>
  </button>

  <div class="side-panel-content">

    <div class="dossier-card mb-6">
      <p style="color:var(--ink); line-height:1.65;">
        The <strong style="color:var(--royal);">LSPU Training Tracker System</strong> was built in two stages by two students from the <em>Bachelor of Science in Information Technology (BSIT)</em> program at the <strong style="color:var(--royal);">College of Computer Studies, Laguna State Polytechnic University – Los Baños Campus</strong>: the original record-keeping system, and a later expansion that added its AI-powered recommendation and training-demand pipeline.
      </p>
    </div>

    <div class="stat-strip mb-8">
      <div class="stat">
        <span class="stat-num">2</span>
        <span class="stat-label">Developers</span>
      </div>
      <div class="stat">
        <span class="stat-num">3</span>
        <span class="stat-label">Advisors</span>
      </div>
      <div class="stat">
        <span class="stat-num">4</span>
        <span class="stat-label">User Roles Built</span>
      </div>
      <div class="stat">
        <span class="stat-num">BSIT</span>
        <span class="stat-label">Program</span>
      </div>
    </div>

    <span class="eyebrow mb-3">Leadership</span>
    <h3 class="section-heading" style="margin-top:6px;">Development Team</h3>

    <div class="team-grid mb-8">
      <div class="dossier-card" style="max-width:100%;">
        <div class="flex flex-col items-center text-center">
          <div class="advisor-avatar" style="width:76px; height:76px; font-size:1.6rem; background:linear-gradient(135deg,#1A4B8C,#0F3460);">
            <i class="ri-code-box-fill"></i>
          </div>
          <h4 style="font-family:'Fraunces',serif; font-size:1.15rem; font-weight:600; color:var(--ink);">Gian Carlo I. Maranan</h4>
          <p style="color:var(--royal); font-weight:600; font-size:0.85rem; margin-top:2px;">Founding Developer</p>
          <p style="color:var(--ink-soft); font-size:0.85rem; margin-top:10px; line-height:1.5;">
            Designed and built the original LSPU-LBC Training Tracker System — the core training documentation, record-keeping, and account-management platform — from initial concept to deployment.
          </p>
        </div>
      </div>

      <div class="dossier-card" style="max-width:100%;">
        <div class="flex flex-col items-center text-center">
          <div class="advisor-avatar" style="width:76px; height:76px; font-size:1.6rem; background:linear-gradient(135deg,#0D6B4D,#084A34);">
            <i class="ri-brain-line"></i>
          </div>
          <h4 style="font-family:'Fraunces',serif; font-size:1.15rem; font-weight:600; color:var(--ink);">Sean John R. Looc</h4>
          <p style="color:var(--forest); font-weight:600; font-size:0.85rem; margin-top:2px;">AI &amp; Systems Integration Developer</p>
          <p style="color:var(--ink-soft); font-size:0.85rem; margin-top:10px; line-height:1.5;">
            Extended the system with its AI-powered capabilities: the XGBoost + SBERT training recommendation engine, the HR Training Demand pipeline, Reports &amp; Analytics, and the Audit Log.
          </p>
        </div>
      </div>
    </div>

    <h3 class="section-heading">Project Advisors</h3>
    <p class="mb-4" style="color:var(--ink-soft); font-size:0.88rem; line-height:1.55;">
      The project was developed under the guidance of esteemed advisors from the College of Computer Studies, whose expertise and mentorship were invaluable throughout development.
    </p>

    <div class="grid gap-4 sm:grid-cols-3 mb-8">
      <div class="advisor-card">
        <div class="advisor-avatar" style="background:linear-gradient(135deg,#1A4B8C,#0F3460);">
          <i class="ri-user-3-fill"></i>
        </div>
        <h4>Sir Alejandro V. Matute, Jr.</h4>
        <p class="role" style="color:var(--royal);">Project Coordinator</p>
        <p class="desc">Provided overall project supervision and academic direction.</p>
      </div>

      <div class="advisor-card">
        <div class="advisor-avatar" style="background:linear-gradient(135deg,#D4A843,#B8922A);">
          <i class="ri-user-3-fill"></i>
        </div>
        <h4>Sir Crisanto F. Gulay</h4>
        <p class="role" style="color:#B8922A;">Technical Advisor</p>
        <p class="desc">Guided the technical implementation and system architecture.</p>
      </div>

      <div class="advisor-card">
        <div class="advisor-avatar" style="background:linear-gradient(135deg,#0D6B4D,#084A34);">
          <i class="ri-user-3-fill"></i>
        </div>
        <h4>Sir Merardo A. Camba, Jr.</h4>
        <p class="role" style="color:var(--forest);">System Consultant</p>
        <p class="desc">Offered valuable insights on system design and implementation.</p>
      </div>
    </div>

    <div class="pull-quote mb-8">
      <i class="ri-double-quotes-l"></i>
      <p style="font-size:0.95rem;">We extend our deepest gratitude to our advisors for their continuous support, valuable feedback, and for sharing their wealth of knowledge throughout this project's development.</p>
    </div>

    <span class="eyebrow mb-3">Under the Hood</span>
    <h3 class="section-heading" style="margin-top:6px;">Built With</h3>
    <div class="flex flex-wrap gap-2 mb-8">
      <span class="tech-chip"><i class="ri-code-s-slash-line"></i> PHP</span>
      <span class="tech-chip"><i class="ri-database-2-line"></i> MySQL</span>
      <span class="tech-chip"><i class="ri-tailwind-css-line"></i> Tailwind CSS</span>
      <span class="tech-chip"><i class="ri-shape-2-line"></i> Animate.css</span>
      <span class="tech-chip"><i class="ri-flashlight-line"></i> FastAPI</span>
      <span class="tech-chip"><i class="ri-braces-line"></i> Python</span>
      <span class="tech-chip"><i class="ri-node-tree"></i> XGBoost</span>
      <span class="tech-chip"><i class="ri-chat-quote-line"></i> Sentence-BERT</span>
    </div>

    <h3 class="section-heading">Project Development</h3>
    <div class="dossier-card mb-8">
      <div class="step-row">
        <div class="step-num"><i class="ri-search-line" style="font-size:0.85rem;"></i></div>
        <div><h5>Research and Planning</h5><p>Conducted research to identify system requirements and user needs.</p></div>
      </div>
      <div class="step-row">
        <div class="step-num"><i class="ri-code-line" style="font-size:0.85rem;"></i></div>
        <div><h5>System Development</h5><p>Implemented the core features and functionality of the training tracker.</p></div>
      </div>
      <div class="step-row">
        <div class="step-num"><i class="ri-bug-line" style="font-size:0.85rem;"></i></div>
        <div><h5>Testing and Refinement</h5><p>Performed rigorous testing to ensure reliability and user satisfaction.</p></div>
      </div>
      <div class="step-row">
        <div class="step-num"><i class="ri-brain-line" style="font-size:0.85rem;"></i></div>
        <div><h5>AI &amp; Pipeline Expansion</h5><p>Added the AI recommendation engine and the HR Training Demand pipeline, Reports &amp; Analytics, and Audit Log on top of the original system.</p></div>
      </div>
      <div class="step-row">
        <div class="step-num"><i class="ri-checkbox-circle-line" style="font-size:0.85rem;"></i></div>
        <div><h5>Final Implementation</h5><p>Deployed the completed system for university-wide use.</p></div>
      </div>
    </div>

    <div class="pull-quote" style="text-align:center;">
      <i class="ri-double-quotes-l"></i>
      <p>This project reflects our commitment to serving the university community through meaningful, technology-driven solutions.</p>
    </div>
  </div>
</aside>

<!-- Main login container -->
<div class="page-wrap" id="pageWrap">
  <div class="fit-container" id="fitContainer">
    <div class="main-container">

      <!-- Logo / Seal -->
      <div class="logo-wrapper" aria-hidden="true">
        <svg class="seal-ring" viewBox="0 0 200 200">
          <circle cx="100" cy="100" r="97" fill="none" stroke="var(--gold)" stroke-width="1.4" opacity="0.4"/>
        </svg>
        <div class="logo-circle">
          <img src="images/lspu-logo.png" alt="LSPU Logo">
        </div>
      </div>

      <div class="login-container">
        <div class="card-body">
          <div id="mainHeader" class="text-center mb-3 animate__animated animate__fadeInDown">
            <span class="eyebrow center mb-1">Official Portal</span>
            <h1 id="mainTitle" class="tracker-title">
              LSPU Training Tracker
            </h1>
            <p id="mainSubtitle" class="tracker-subtitle">
              Your Gateway to Continuous Learning and Professional Growth
            </p>
          </div>

          <div class="space-y-3">

            <div id="mainButtons" class="space-y-2.5">
              <!-- LOGIN BUTTON - PINAKAMALAKI -->
              <button id="loginBtn" type="button" class="btn-login w-full flex items-center justify-center" onclick="showForm('loginForm', 'slide-right')">
                <span class="w-5 h-5 flex items-center justify-center mr-2">
                  <i class="ri-login-circle-line"></i>
                </span>
                Login to Your Account
              </button>

              <!-- REGISTER BUTTON - MEDIUM -->
              <button id="registerBtn" type="button" class="btn-register w-full flex items-center justify-center" onclick="showForm('registerForm', 'slide-left')">
                <span class="w-5 h-5 flex items-center justify-center mr-2">
                  <i class="ri-user-add-line"></i>
                </span>
                Create New Account
              </button>
            </div>

            <!-- Login Form -->
            <div id="loginForm" class="relative hidden form-transition">
              <button type="button" class="absolute top-0 right-0 text-lg text-gray-600 hover:text-red-500 transition-colors" onclick="closeForm()">&times;</button>
              <h2 class="text-center text-xl font-bold mb-3 mt-1" style="font-family:'Fraunces',serif; color:var(--ink);">Welcome Back</h2>

              <?php if (!empty($errors['account_status']) && strpos($errors['account_status'], 'approved') !== false): ?>
                <div class="success-message mb-2">
                  <i class="ri-checkbox-circle-line"></i>
                  <span><?php echo htmlspecialchars($errors['account_status']); ?></span>
                </div>
              <?php endif; ?>

              <form action="login_process.php" method="POST" class="space-y-2.5 compact-form">
                <div>
                  <label for="email" class="block font-medium text-gray-700">Email</label>
                  <input type="email" id="email" name="email" required placeholder="Enter your email" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-300" />
                </div>

                <?php if (!empty($errors['login'])): ?>
                  <div class="error-message">
                    <i class="ri-error-warning-line"></i>
                    <span><?php echo htmlspecialchars($errors['login']); ?></span>
                  </div>
                <?php endif; ?>

                <button type="submit" name="login" class="btn-signin w-full">
                  Sign In
                </button>
              </form>
              <p class="mt-2 text-center text-xs text-gray-600">
                Don't have an account?
                <button type="button" class="underline text-primary hover:text-primary-dark font-semibold transition-colors" onclick="showForm('registerForm', 'slide-right')">Register here</button>
              </p>
            </div>

            <!-- Register Form -->
            <div id="registerForm" class="relative hidden form-transition">
              <button type="button" class="absolute top-0 right-0 text-lg text-gray-600 hover:text-red-500 transition-colors" onclick="closeForm()">&times;</button>
              <h2 class="text-center text-xl font-bold mb-3 mt-1" style="font-family:'Fraunces',serif; color:var(--ink);">Create Account</h2>

              <?php if (!empty($errors['register_success'])): ?>
                <div class="success-message mb-2">
                  <i class="ri-checkbox-circle-line"></i>
                  <span><?php echo htmlspecialchars($errors['register_success']); ?></span>
                </div>
              <?php endif; ?>

              <div class="role-form-container">
                <div id="roleSelectionSection" class="role-section">
                  <p class="text-gray-600 font-medium mb-1.5 text-sm">Select your role:</p>

                  <div class="role-grid">
                    <div class="role-card" onclick="showRoleForm('user')" id="role-user-card">
                      <div class="role-icon-chip" style="background:linear-gradient(135deg,#1A4B8C,#0F3460);"><i class="ri-user-line"></i></div>
                      <div class="role-card-text">
                        <h3>Faculty Member</h3>
                        <p>Regular faculty account</p>
                      </div>
                    </div>

                    <div class="role-card" onclick="showRoleForm('non_teaching')" id="role-nonteaching-card">
                      <div class="role-icon-chip" style="background:linear-gradient(135deg,#D4A843,#B8922A);"><i class="ri-user-settings-line"></i></div>
                      <div class="role-card-text">
                        <h3>Non-Teaching</h3>
                        <p>Admin &amp; support staff</p>
                      </div>
                    </div>

                    <div class="role-card" onclick="showRoleForm('admin')" id="role-admin-card">
                      <div class="role-icon-chip" style="background:linear-gradient(135deg,#0D6B4D,#084A34);"><i class="ri-admin-line"></i></div>
                      <div class="role-card-text">
                        <h3>HR Administrator</h3>
                        <p>Human Resource admin</p>
                      </div>
                    </div>

                    <div class="role-card" onclick="showRoleForm('dean')" id="role-dean-card">
                      <div class="role-icon-chip" style="background:linear-gradient(135deg,#5B7288,#37485C);"><i class="ri-building-line"></i></div>
                      <div class="role-card-text">
                        <h3>College Dean</h3>
                        <p>Dept-level admin</p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Faculty Member Form -->
                <div id="userFormSection" class="role-section hidden">
                  <div class="back-to-roles" onclick="showRoleSelection()">
                    <i class="ri-arrow-left-line"></i> Back to Roles
                  </div>
                  <div class="role-specific-form">
                    <div class="role-badge badge-user">Faculty Member Registration</div>
                    <form action="process_registration.php" method="POST" class="space-y-2.5 compact-form">
                      <input type="hidden" name="role" value="user">

                      <p class="name-section-label">Full Name</p>
                      <div class="name-fields-row">
                        <div class="col-span-1">
                          <label for="userFirstName" class="block font-medium text-gray-700">First Name</label>
                          <input type="text" id="userFirstName" name="first_name" required placeholder="First name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="userMiddleInitial" class="block font-medium text-gray-700">M.I.</label>
                          <input type="text" id="userMiddleInitial" name="middle_initial" maxlength="5" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="userLastName" class="block font-medium text-gray-700">Last Name</label>
                          <input type="text" id="userLastName" name="last_name" required placeholder="Last name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                      </div>

                      <div>
                        <label for="userEmail" class="block font-medium text-gray-700">Email</label>
                        <input type="email" id="userEmail" name="email" required placeholder="Enter your LSPU email" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        <p class="text-xs text-gray-500 mt-0.5">Use your official LSPU email address</p>
                      </div>

                      <div>
                        <label for="userDepartment" class="block font-medium text-gray-700">Department</label>
                        <select id="userDepartment" name="department" required class="department-select">
                          <option value="" disabled selected>Select your department</option>
                          <?php foreach ($departments as $code => $name): ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div>
                        <label for="userDesignation" class="block font-medium text-gray-700">Designation / Position</label>
                        <!--
                          2026-09-03 - added per real TAM testing feedback
                          (see $facultyRanks's comment above). "Other"
                          fallback so nobody's blocked if their exact rank
                          isn't on the CHED ladder (e.g. a part-time/
                          adjunct title) - same pattern as the Non-Teaching
                          Office field below.
                        -->
                        <select id="userDesignation" required class="department-select">
                          <option value="" disabled selected>Select your designation/position</option>
                          <?php foreach ($facultyRanks as $rank): ?>
                            <option value="<?php echo htmlspecialchars($rank); ?>"><?php echo htmlspecialchars($rank); ?></option>
                          <?php endforeach; ?>
                          <option value="OTHER">Other (please specify)</option>
                        </select>
                        <input
                          type="text"
                          id="userDesignationOtherInput"
                          placeholder="Type your designation/position"
                          class="form-input mt-2 hidden"
                          autocomplete="off"
                        />
                        <input type="hidden" id="userDesignationHidden" name="designation" />
                      </div>

                      <button type="submit" name="register_request" class="btn-submit w-full">
                        Submit Request
                      </button>
                    </form>
                  </div>
                </div>
                <script>
                  // Mirrors the Designation/Position select (+ Other
                  // fallback) into the actual name="designation" hidden
                  // field, same pattern as the Non-Teaching Office sync
                  // script below.
                  (function () {
                    const sel = document.getElementById('userDesignation');
                    const other = document.getElementById('userDesignationOtherInput');
                    const hidden = document.getElementById('userDesignationHidden');
                    if (!sel || !other || !hidden) return;

                    function sync() {
                      if (sel.value === 'OTHER') {
                        other.classList.remove('hidden');
                        other.required = true;
                        hidden.value = other.value.trim();
                      } else {
                        other.classList.add('hidden');
                        other.required = false;
                        hidden.value = sel.value;
                      }
                    }

                    sel.addEventListener('change', sync);
                    other.addEventListener('input', sync);
                    const form = sel.closest('form');
                    if (form) form.addEventListener('submit', sync);
                    sync();
                  })();
                </script>

                <!-- Human Resource Administrator Form -->
                <div id="adminFormSection" class="role-section hidden">
                  <div class="back-to-roles" onclick="showRoleSelection()">
                    <i class="ri-arrow-left-line"></i> Back to Roles
                  </div>
                  <div class="role-specific-form">
                    <div class="role-badge badge-admin">Human Resource Administrator Registration</div>
                    <form action="process_registration.php" method="POST" class="space-y-2.5 compact-form">
                      <input type="hidden" name="role" value="admin">

                      <p class="name-section-label">Full Name</p>
                      <div class="name-fields-row">
                        <div class="col-span-1">
                          <label for="adminFirstName" class="block font-medium text-gray-700">First Name</label>
                          <input type="text" id="adminFirstName" name="first_name" required placeholder="First name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="adminMiddleInitial" class="block font-medium text-gray-700">M.I.</label>
                          <input type="text" id="adminMiddleInitial" name="middle_initial" maxlength="5" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="adminLastName" class="block font-medium text-gray-700">Last Name</label>
                          <input type="text" id="adminLastName" name="last_name" required placeholder="Last name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                      </div>

                      <div>
                        <label for="adminEmail" class="block font-medium text-gray-700">Email</label>
                        <input type="email" id="adminEmail" name="email" required placeholder="Enter your LSPU email" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        <p class="text-xs text-gray-500 mt-0.5">Use your official LSPU email address</p>
                      </div>

                      <div>
                        <label for="adminPosition" class="block font-medium text-gray-700">Position</label>
                        <input
                          type="text"
                          id="adminPosition"
                          name="position"
                          list="adminPositionList"
                          required
                          placeholder="Type or select your position"
                          class="form-input"
                          autocomplete="off"
                        />
                        <datalist id="adminPositionList">
                          <?php foreach ($existing_admin_positions as $position): ?>
                            <option value="<?php echo htmlspecialchars($position); ?>">
                          <?php endforeach; ?>
                        </datalist>
                      </div>

                      <button type="submit" name="register_request" class="btn-submit w-full">
                        Submit Request
                      </button>
                    </form>
                  </div>
                </div>

                <!-- College Dean Form -->
                <div id="deanFormSection" class="role-section hidden">
                  <div class="back-to-roles" onclick="showRoleSelection()">
                    <i class="ri-arrow-left-line"></i> Back to Roles
                  </div>
                  <div class="role-specific-form">
                    <div class="role-badge badge-dean">College Dean Registration</div>
                    <form action="process_registration.php" method="POST" class="space-y-2.5 compact-form">
                      <input type="hidden" name="role" value="dean">

                      <p class="name-section-label">Full Name</p>
                      <div class="name-fields-row">
                        <div class="col-span-1">
                          <label for="deanFirstName" class="block font-medium text-gray-700">First Name</label>
                          <input type="text" id="deanFirstName" name="first_name" required placeholder="First name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="deanMiddleInitial" class="block font-medium text-gray-700">M.I.</label>
                          <input type="text" id="deanMiddleInitial" name="middle_initial" maxlength="5" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="deanLastName" class="block font-medium text-gray-700">Last Name</label>
                          <input type="text" id="deanLastName" name="last_name" required placeholder="Last name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                      </div>

                      <div>
                        <label for="deanEmail" class="block font-medium text-gray-700">Email</label>
                        <input type="email" id="deanEmail" name="email" required placeholder="Enter your LSPU email" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        <p class="text-xs text-gray-500 mt-0.5">Use your official LSPU email address</p>
                      </div>

                      <div>
                        <label for="deanCollege" class="block font-medium text-gray-700">College/Department</label>
                        <select id="deanCollege" name="department" required class="department-select">
                          <option value="" disabled selected>Select your college</option>
                          <?php foreach ($departments as $code => $name): ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <button type="submit" name="register_request" class="btn-submit w-full">
                        Submit Request
                      </button>
                    </form>
                  </div>
                </div>

                <!-- Non-Teaching Personnel Form -->
                <div id="non_teachingFormSection" class="role-section hidden">
                  <div class="back-to-roles" onclick="showRoleSelection()">
                    <i class="ri-arrow-left-line"></i> Back to Roles
                  </div>
                  <div class="role-specific-form">
                    <div class="role-badge badge-nonteaching">Non-Teaching Personnel Registration</div>
                    <form action="process_registration.php" method="POST" class="space-y-2.5 compact-form">
                      <input type="hidden" name="role" value="non_teaching">

                      <p class="name-section-label">Full Name</p>
                      <div class="name-fields-row">
                        <div class="col-span-1">
                          <label for="nonTeachingFirstName" class="block font-medium text-gray-700">First Name</label>
                          <input type="text" id="nonTeachingFirstName" name="first_name" required placeholder="First name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="nonTeachingMiddleInitial" class="block font-medium text-gray-700">M.I.</label>
                          <input type="text" id="nonTeachingMiddleInitial" name="middle_initial" maxlength="5" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                        <div class="col-span-1">
                          <label for="nonTeachingLastName" class="block font-medium text-gray-700">Last Name</label>
                          <input type="text" id="nonTeachingLastName" name="last_name" required placeholder="Last name" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        </div>
                      </div>

                      <div>
                        <label for="nonTeachingEmail" class="block font-medium text-gray-700">Email</label>
                        <input type="email" id="nonTeachingEmail" name="email" required placeholder="Enter your LSPU email" class="w-full px-3 py-2 border border-gray-300 rounded-button focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent reg-field" />
                        <p class="text-xs text-gray-500 mt-0.5">Use your official LSPU email address</p>
                      </div>

                      <div>
                        <label for="nonTeachingOffice" class="block font-medium text-gray-700">Office/Department</label>
                        <!--
                          2026-09-02 - replaced with a real dropdown (was a
                          free-text+datalist field, which was error prone -
                          typos meant an employee's office never matched
                          anything downstream). Keep this option list in
                          sync with $officeOptions in profile.php,
                          $nonTeachingOfficeAliases in admin_page.php, and
                          TNA_COLLEGE_CODE_MAP in ml_recommendations.php.
                          "Other" still falls back to free text so nobody
                          is blocked if their real office isn't listed.
                          2026-09-07 - replaced the original 5-office
                          placeholder list with the real 13 non-teaching
                          offices, given directly by HR (Dr. Imee Prescilla
                          P. Sanchez, HRMO).
                        -->
                        <select id="nonTeachingOffice" required class="form-input" autocomplete="off">
                          <option value="" disabled selected>Select your office/department</option>
                          <option value="Office of the Campus Director">Office of the Campus Director</option>
                          <option value="Guidance Counselor">Guidance Counselor</option>
                          <option value="Disbursing and Cashiering">Disbursing and Cashiering</option>
                          <option value="Records Office">Records Office</option>
                          <option value="General Services Unit">General Services Unit</option>
                          <option value="Library Services">Library Services</option>
                          <option value="Supply">Supply</option>
                          <option value="Admission and Registrarship">Admission and Registrarship</option>
                          <option value="Accounting Office">Accounting Office</option>
                          <option value="Budget and Finance">Budget and Finance</option>
                          <option value="Human Resource Management">Human Resource Management</option>
                          <option value="Medical and Dental Services">Medical and Dental Services</option>
                          <option value="Procurement">Procurement</option>
                          <option value="OTHER">Other (please specify)</option>
                        </select>
                        <input
                          type="text"
                          id="nonTeachingOfficeOtherInput"
                          list="nonTeachingOfficeList"
                          placeholder="Type your office/department"
                          class="form-input mt-2 hidden"
                          autocomplete="off"
                        />
                        <datalist id="nonTeachingOfficeList">
                          <?php foreach ($existing_nonteaching_offices as $office): ?>
                            <option value="<?php echo htmlspecialchars($office); ?>">
                          <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" id="nonTeachingOfficeHidden" name="department" />
                      </div>

                      <div>
                        <label for="nonTeachingDesignation" class="block font-medium text-gray-700">Designation / Position</label>
                        <!--
                          2026-09-07 - was a free-text+datalist "Position"
                          field that only ever wrote to users.position - a
                          column profile.php never reads or edits. So an
                          employee who filled this in at registration would
                          later hit profile.php's Designation/Position field
                          (which reads users.designation, left NULL by this
                          form) completely blank, and have to answer the
                          same question twice. Replaced with a real dropdown
                          of LSPU's actual non-teaching position list (given
                          directly by the adviser - keep in sync with
                          $nonTeachingPositions in profile.php), synced into
                          BOTH the designation and position hidden fields so
                          it lands in the column profile.php actually shows
                          AND stays backward-compatible with anything still
                          reading users.position (e.g. ml_recommendations.php's
                          refreshMLRecommendations()). "Other" still falls
                          back to free text so nobody is blocked if their
                          real position isn't on the list.
                        -->
                        <select id="nonTeachingDesignationSelect" required class="form-input" autocomplete="off">
                          <option value="" disabled selected>Select your designation/position</option>
                          <?php
                            $nonTeachingPositions = [
                              'Head',
                              'Administrative Officer I', 'Administrative Officer II', 'Administrative Officer III', 'Administrative Officer IV', 'Administrative Officer V',
                              'Administrative Aide I', 'Administrative Aide II', 'Administrative Aide III', 'Administrative Aide IV', 'Administrative Aide V', 'Administrative Aide VI',
                              'Staff',
                            ];
                          ?>
                          <?php foreach ($nonTeachingPositions as $pos): ?>
                            <option value="<?php echo htmlspecialchars($pos); ?>"><?php echo htmlspecialchars($pos); ?></option>
                          <?php endforeach; ?>
                          <option value="OTHER">Other (please specify)</option>
                        </select>
                        <input
                          type="text"
                          id="nonTeachingDesignationOtherInput"
                          placeholder="Type your designation/position"
                          class="form-input mt-2 hidden"
                          autocomplete="off"
                        />
                        <input type="hidden" id="nonTeachingDesignationHidden" name="designation" />
                        <input type="hidden" id="nonTeachingPositionHidden" name="position" />
                      </div>

                      <button type="submit" name="register_request" class="btn-submit w-full">
                        Submit Request
                      </button>
                    </form>
                  </div>
                </div>
                <script>
                  // Mirrors the Designation/Position select (+ Other
                  // fallback) into BOTH name="designation" and
                  // name="position" hidden fields - see the comment on the
                  // field above for why both.
                  (function () {
                    const sel = document.getElementById('nonTeachingDesignationSelect');
                    const other = document.getElementById('nonTeachingDesignationOtherInput');
                    const hiddenDesig = document.getElementById('nonTeachingDesignationHidden');
                    const hiddenPos = document.getElementById('nonTeachingPositionHidden');
                    if (!sel || !other || !hiddenDesig || !hiddenPos) return;

                    function sync() {
                      const val = sel.value === 'OTHER' ? other.value.trim() : sel.value;
                      if (sel.value === 'OTHER') {
                        other.classList.remove('hidden');
                        other.required = true;
                      } else {
                        other.classList.add('hidden');
                        other.required = false;
                      }
                      hiddenDesig.value = val;
                      hiddenPos.value = val;
                    }

                    sel.addEventListener('change', sync);
                    other.addEventListener('input', sync);
                    const form = sel.closest('form');
                    if (form) form.addEventListener('submit', sync);
                    sync();
                  })();
                </script>
              </div>

              <script>
                // Mirrors the Office/Department select (+ Other fallback)
                // into the actual name="department" hidden field that gets
                // submitted - same pattern used in profile.php.
                (function () {
                  const sel = document.getElementById('nonTeachingOffice');
                  const other = document.getElementById('nonTeachingOfficeOtherInput');
                  const hidden = document.getElementById('nonTeachingOfficeHidden');
                  if (!sel || !other || !hidden) return;

                  function sync() {
                    if (sel.value === 'OTHER') {
                      other.classList.remove('hidden');
                      other.required = true;
                      hidden.value = other.value.trim();
                    } else {
                      other.classList.add('hidden');
                      other.required = false;
                      hidden.value = sel.value;
                    }
                  }

                  sel.addEventListener('change', sync);
                  other.addEventListener('input', sync);
                  const form = sel.closest('form');
                  if (form) form.addEventListener('submit', sync);
                  sync();
                })();
              </script>

              <p class="mt-2 text-center text-xs text-gray-600">
                Already have an account?
                <button type="button" class="underline text-secondary hover:text-secondary-dark font-semibold transition-colors" onclick="showForm('loginForm', 'slide-left')">Login here</button>
              </p>
            </div>

            <script>
              // 2026-09-09 - soft duplicate-name warning, see
              // check_duplicate_name.php's comment for the full reasoning
              // (registration only ever checked email uniqueness; this
              // doesn't block, just asks the person to double-check before
              // proceeding - two real people can legitimately share a name).
              // Applies to all 4 registration forms (Faculty/Non-Teaching/
              // Dean/Admin) since they all POST to process_registration.php.
              (function () {
                const forms = document.querySelectorAll('form[action="process_registration.php"]');
                forms.forEach(function (form) {
                  // 2026-09-09 - name is now First/M.I./Last (see
                  // config.php's buildFullName() comment) instead of one
                  // field, so the duplicate check now reads both name
                  // inputs and matches on first+last (check_duplicate_name.php
                  // deliberately ignores middle initial - see its comment).
                  const firstInput = form.querySelector('[name="first_name"]');
                  const lastInput = form.querySelector('[name="last_name"]');
                  [firstInput, lastInput].forEach(function (input) {
                    if (input) {
                      input.addEventListener('input', function () {
                        delete form.dataset.nameConfirmed;
                      });
                    }
                  });
                  form.addEventListener('submit', function (e) {
                    if (form.dataset.nameConfirmed === '1') {
                      return; // already checked (or check failed open) - let it through
                    }
                    const firstName = firstInput ? firstInput.value.trim() : '';
                    const lastName = lastInput ? lastInput.value.trim() : '';
                    if (!firstName || !lastName) {
                      return; // let the browser's own "required" validation handle it
                    }
                    e.preventDefault();
                    // 2026-09-09 fix - a real, live bug: requestSubmit() called
                    // with no argument does NOT include the triggering submit
                    // button's name/value pair (name="register_request") in the
                    // POST body, only a real click (or requestSubmit(button))
                    // does. Without it, process_registration.php's
                    // isset($_POST['register_request']) check fails, nothing
                    // in the file matches, and it falls all the way through to
                    // a blank 200 response - reproduced live via a real browser
                    // submission during pre-testing QA. e.submitter is exactly
                    // the button that triggered this event; capture it now and
                    // replay it as the explicit submitter below.
                    const submitter = e.submitter;
                    const fullName = firstName + ' ' + lastName;
                    fetch('check_duplicate_name.php?first_name=' + encodeURIComponent(firstName) + '&last_name=' + encodeURIComponent(lastName))
                      .then(function (r) { return r.json(); })
                      .then(function (data) {
                        form.dataset.nameConfirmed = '1';
                        if (data.exists) {
                          const proceed = confirm(
                            'An account named "' + fullName + '" is already registered' +
                            (data.department ? ' (' + data.department + ')' : '') +
                            '.\n\nIf this is you, click Cancel and use "Login" instead.\n' +
                            'If you are a different person who happens to share this name, click OK to continue registering.'
                          );
                          if (!proceed) {
                            delete form.dataset.nameConfirmed;
                            return;
                          }
                        }
                        form.requestSubmit(submitter);
                      })
                      .catch(function () {
                        // Fail open - never block a real registration because this check failed.
                        form.dataset.nameConfirmed = '1';
                        form.requestSubmit(submitter);
                      });
                  });
                });
              })();
            </script>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Footer -->
<footer>
  <div class="footer-content">
    <p>
      © 2025 Laguna State Polytechnic University Los Baños Campus. All rights reserved. <br>
      Developed by <strong>Gian Carlo I. Maranan</strong> &amp; <strong>Sean John R. Looc</strong>. Learn more
      <span onclick="openPanel('left')" role="button" tabindex="0" class="cursor-pointer hover:underline focus:outline-none transition-colors">
        about the system
      </span>
      or
      <span onclick="openPanel('right')" role="button" tabindex="0" class="cursor-pointer hover:underline focus:outline-none transition-colors">
        about us
      </span>.
    </p>
  </div>
</footer>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const loginBtn = document.getElementById('loginBtn');
    const registerBtn = document.getElementById('registerBtn');
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const mainButtons = document.getElementById('mainButtons');
    const mainTitle = document.getElementById('mainTitle');
    const mainSubtitle = document.getElementById('mainSubtitle');
    const notificationArea = document.getElementById('notificationArea');
    const leftZone = document.getElementById('left-zone');
    const rightZone = document.getElementById('right-zone');
    const mainZone = document.getElementById('main-zone');
    const leftPanel = document.getElementById('left-panel');
    const rightPanel = document.getElementById('right-panel');
    const closeLeftPanel = document.getElementById('close-left-panel');
    const closeRightPanel = document.getElementById('close-right-panel');
    const pageWrap = document.getElementById('pageWrap');
    const fitContainer = document.getElementById('fitContainer');

    /* =====================================================
       AUTO-FIT: laging kita ang buong seal + form container
       sa loob ng 100% na visible viewport, kahit magbago
       ang laki ng aktibong form (login/register/role forms).
       ===================================================== */
    // 2026-09-09 rewrite - real, live bug found via a screenshot: the
    // seal (.logo-wrapper, absolutely positioned with a large negative
    // top offset above .main-container) kept poking off the top of the
    // viewport even after a scale was applied. Root cause, confirmed by
    // directly inspecting computed geometry with a real headless
    // browser: .page-wrap flex-centers fitContainer based on its
    // UNSCALED layout size, and `transform: scale()` then shrinks
    // visually around transform-origin (top center) - an anchor point
    // computed from that same unscaled, flex-centered position, which
    // can itself sit above y=0 when the unscaled content is tall.
    // Scaling around an already-negative anchor doesn't reliably pull
    // the seal into view; a first attempt at fixing this by predicting
    // the overhang mathematically and folding it into the scale
    // calculation was ALSO verified insufficient the same way (measured
    // live, still negative). This version stops predicting the geometry
    // entirely: apply the scale, synchronously measure where the seal
    // actually ended up (getBoundingClientRect forces layout immediately,
    // no extra frame needed), and nudge the whole group down by exactly
    // however far it's still short. One synchronous pass, not nested
    // rAF calls - fewer event-loop turns for the ResizeObserver below to
    // interleave with and clobber a half-applied correction.
    // 2026-09-09 - real, live feedback via a screenshot comparing two
    // role forms side by side: Faculty/Non-Teaching (2 dropdowns: Dept +
    // Designation) are visibly taller than Admin (1 field: Position) or
    // Dean, so the auto-fit scale below - computed from whichever form
    // happens to be showing - shrinks the taller ones more, making the
    // SAME modal look like a different zoom level depending on which
    // role tab you're on. Fix: always scale against the TALLEST role
    // form's height, not the currently-visible one's, so every role
    // shares one consistent zoom level. Measured once by briefly taking
    // each hidden form out of `display:none` (via visibility:hidden +
    // position:absolute, so it never actually paints or affects layout)
    // and cached, since these forms' field counts don't change at runtime.
    let cachedMaxRoleFormHeight = null;
    const roleFormIds = ['userFormSection', 'non_teachingFormSection', 'adminFormSection', 'deanFormSection'];
    function getMaxRoleFormHeight() {
      if (cachedMaxRoleFormHeight !== null) return cachedMaxRoleFormHeight;
      let max = 0;
      roleFormIds.forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        const wasHidden = el.classList.contains('hidden');
        if (wasHidden) {
          el.classList.remove('hidden');
          el.style.visibility = 'hidden';
          el.style.position = 'absolute';
        }
        max = Math.max(max, el.scrollHeight);
        if (wasHidden) {
          el.classList.add('hidden');
          el.style.visibility = '';
          el.style.position = '';
        }
      });
      cachedMaxRoleFormHeight = max;
      return max;
    }

    let fitRaf = null;
    function fitToViewport() {
      if (fitRaf) cancelAnimationFrame(fitRaf);
      fitRaf = requestAnimationFrame(() => {
        // 2026-09-09 fix - the actual final bug, found by logging before/
        // after values around the correction: .fit-container has `transition:
        // transform 0.25s ease-out` (declared in its CSS, for a smooth
        // visual snap on resize), so every getBoundingClientRect() read
        // immediately after a style.transform assignment was capturing
        // the START of an in-progress animation, not the target end
        // state - "after" always came back identical to "before"
        // regardless of what was set, since 0ms had elapsed on a 250ms
        // transition. Disabling the transition for this synchronous
        // measure-and-correct pass makes every assignment apply and
        // read back instantly, as plain layout math expects.
        fitContainer.style.transition = 'none';
        fitContainer.style.transform = 'none';

        const wrapStyles = getComputedStyle(pageWrap);
        const paddingTop = parseFloat(wrapStyles.paddingTop) || 0;
        const paddingBottom = parseFloat(wrapStyles.paddingBottom) || 0;

        // 2026-09-09 fix - real, live feedback via a screenshot: the "-8"
        // buffer here was nearly nothing, so on a normal ~880px browser
        // window the taller, accessibility-resized register card (see
        // .reg-field's comment) filled almost the entire available height
        // and sat flush against the top edge with no visible breathing
        // room - centering was working correctly, there just wasn't any
        // slack left to center WITH. Reserving a real margin on both
        // top and bottom forces a small proportional scale-down whenever
        // needed to guarantee visible space around the card, instead of
        // only ever scaling when content technically overflows.
        const minMargin = 28;
        const availableHeight = pageWrap.clientHeight - paddingTop - paddingBottom - (minMargin * 2);

        // Include the seal's overhang above .main-container in the TRUE
        // content height up front, so the scale itself already accounts
        // for the seal's full extent (not just fitContainer.scrollHeight,
        // which excludes an absolutely-positioned child's negative
        // offset) - shrinking everything proportionally from the start
        // keeps spacing looking natural, rather than relying entirely on
        // the translateY safety net below to do all the work.
        const logoWrapper = fitContainer.querySelector('.logo-wrapper');
        // .registering hides the seal via display:none, which reports an
        // all-zero rect - offsetParent is null exactly when an element
        // (or an ancestor) is display:none, so this guards against
        // reading that meaningless zero rect as "the seal is at y=0".
        const logoVisible = !!(logoWrapper && logoWrapper.offsetParent !== null);
        let overhang = 0;
        if (logoVisible) {
          const containerTop = fitContainer.getBoundingClientRect().top;
          const logoTop = logoWrapper.getBoundingClientRect().top;
          overhang = Math.max(0, containerTop - logoTop);
        }
        // Swap out whichever role form is currently showing for the
        // TALLEST one's height (see getMaxRoleFormHeight()'s comment) so
        // every role tab scales identically - a no-op (delta 0) outside
        // the registering state, since none of these IDs are visible
        // during login or the landing screen.
        //
        // 2026-09-09 fix - real, live bug: after Register -> a role form
        // -> clicking X, the landing screen rendered tiny/crushed.
        // closeForm() only hides the OUTER loginForm/registerForm
        // containers, never resets the INNER role-form-section's own
        // .hidden class - so e.g. non_teachingFormSection still had no
        // .hidden on itself, just an ancestor that's hidden. Checking
        // only classList here found it "active" anyway, then measured
        // its scrollHeight as 0 (correctly zero, since a hidden
        // ancestor collapses it) and added the FULL cached max-role-
        // form-height as "delta" against that false zero - massively
        // inflating contentHeight for a screen with no role form
        // showing at all. offsetParent is null whenever an element OR
        // any ancestor is display:none, so this now reflects real
        // visibility instead of just the element's own class.
        const activeRoleFormEl = roleFormIds
          .map(function (id) { return document.getElementById(id); })
          .find(function (el) { return el && el.offsetParent !== null; });
        let roleFormDelta = 0;
        if (activeRoleFormEl) {
          roleFormDelta = Math.max(0, getMaxRoleFormHeight() - activeRoleFormEl.scrollHeight);
        }
        const contentHeight = fitContainer.scrollHeight + overhang + roleFormDelta;

        // 'scale(1)' rather than 'none' as the base value - the
        // correction block below combines this with translateY() in one
        // transform string, and `translateY(Npx) none` is invalid CSS
        // (none can only ever be the WHOLE value, never combined with
        // another function) - silently no-oping the correction exactly
        // when scale is 1, which is now a common case since this can
        // scale up to fill space as well as down to avoid overflow.
        let transform = 'scale(1)';
        let scale = 1;
        // 2026-09-09 fix - "make the box bigger" was specifically about
        // the field-filled role forms - gating on .registering alone
        // caught Login AND the 4-tile role-picker screen too (both
        // fixed - see .role-form-active's comment above). Reusing
        // activeRoleFormEl (already computed above for the cross-role
        // height unification) is even more precise than checking the
        // class: it's true exactly when an actual role form is the
        // visible content, matching .role-form-active by construction
        // but without depending on that toggle being correctly in sync.
        if (contentHeight > availableHeight && availableHeight > 0) {
          scale = Math.max(availableHeight / contentHeight, 0.5);
        } else if (activeRoleFormEl && contentHeight > 0 && availableHeight > 0) {
          // 2026-09-09 fix - real, live feedback: the card was sitting at
          // its natural size with a lot of unused page visible around
          // it ("use the space below") - this only ever scaled DOWN to
          // prevent overflow, never UP to actually fill extra room when
          // there was some to spare. Scale up toward the available
          // height, but cap it by available WIDTH too (measured from
          // .main-container's actual unscaled rendered width, right
          // above) so a wide upscale never pushes the card past the
          // viewport's sides on a narrower window - and cap the upscale
          // itself so text doesn't blow up to an absurd size on a very
          // tall, narrow window.
          const heightScale = availableHeight / contentHeight;
          const mainContainerWidth = document.querySelector('.main-container').getBoundingClientRect().width;
          const availableWidth = pageWrap.clientWidth - 32;
          const widthScale = mainContainerWidth > 0 ? availableWidth / mainContainerWidth : heightScale;
          scale = Math.min(heightScale, widthScale, 1.35);
        }
        if (scale !== 1) {
          // 2026-09-16 fix - real, reported bug: role forms (bigger than
          // Login, so they often scale up slightly via the "fill extra
          // space" branch above) rendered visibly off-center, sitting
          // high with dead space below. Root cause: `transform-origin:
          // top center` (declared on .fit-container, load-bearing for
          // the overflow-guard math below, which assumes a top-anchored
          // scale - not changed here) means scale() grows/shrinks the
          // box from its TOP edge only. Flexbox already centered the
          // UNSCALED box; growing/shrinking purely downward from that
          // fixed top edge then eats into (or adds to) the bottom margin
          // only, leaving the top margin untouched - the box drifts off
          // center by exactly half the size change. Shifting up by half
          // the height delta makes the visible growth/shrink symmetric
          // around the original center instead, without touching
          // transform-origin itself (the overflow guard right below
          // measures the REAL rendered position after this shift, so it
          // still self-corrects if this pushes anything off-screen).
          const heightDelta = contentHeight * (scale - 1);
          const centeringShift = -heightDelta / 2;
          transform = `translateY(${centeringShift}px) scale(${scale})`;
        }
        fitContainer.style.transform = transform;

        // 2026-09-09 fix - real bug found via a real user's DevTools
        // console output (not reproducible in headless testing at the
        // window sizes tried): this safety net only ever checked the
        // seal's position (guarded by logoVisible). During registration
        // the seal is hidden entirely (see .registering), so on a
        // shorter/DPI-scaled real window where the CARD itself still
        // needs scaling, nothing corrected the card's position - it hit
        // the exact same transform-origin: top center anchoring problem
        // the seal had (scaling around a point computed from .page-wrap's
        // flex-centered, UNSCALED layout position, which can itself sit
        // above y=0 when content overflows), just with the card as the
        // visible casualty instead of the seal. Generalized: always
        // measure and correct whichever element is the actual topmost
        // visible one - the seal when it's shown, the card itself when
        // it's not - rather than hard-coding the seal as the only thing
        // that gets this treatment.
        const topRefEl = logoVisible ? logoWrapper : fitContainer.querySelector('.login-container');
        if (topRefEl) {
          const topRefTop = topRefEl.getBoundingClientRect().top;
          if (topRefTop < 8) {
            let shortfall = 8 - topRefTop;
            const containerBottom = fitContainer.getBoundingClientRect().bottom;
            const wrapTop = pageWrap.getBoundingClientRect().top;
            const maxBottom = wrapTop + paddingTop + availableHeight;
            const bottomRoom = maxBottom - containerBottom;
            if (bottomRoom < shortfall) {
              shortfall = Math.max(0, bottomRoom);
            }
            // translateY listed FIRST (outermost) so it moves in real,
            // already-scaled screen pixels - listing it after scale()
            // would apply the translation in the pre-scale coordinate
            // space instead, shrinking the correction by the same
            // factor and under-correcting.
            fitContainer.style.transform = `translateY(${shortfall}px) ${transform}`;
          }
        }

        // Re-enable the transition now that the final transform is set,
        // so a real resize still animates smoothly for the user - only
        // the measurement pass above needed it off.
        requestAnimationFrame(() => {
          fitContainer.style.transition = '';
        });
      });
    }

    fitToViewport();
    window.addEventListener('resize', fitToViewport);
    window.addEventListener('orientationchange', fitToViewport);

    if (window.ResizeObserver) {
      const ro = new ResizeObserver(() => fitToViewport());
      ro.observe(fitContainer);
    }

    window.openPanel = function(side) {
      if (side === 'left') {
        leftPanel.classList.add('open');
        rightPanel.classList.remove('open');
      } else if (side === 'right') {
        rightPanel.classList.add('open');
        leftPanel.classList.remove('open');
      }
      mainZone.classList.add('active');
      document.body.style.overflow = 'hidden';
    };

    function closePanels() {
      leftPanel.classList.remove('open');
      rightPanel.classList.remove('open');
      mainZone.classList.remove('active');
      document.body.style.overflow = '';
    }

    leftZone.addEventListener('click', () => openPanel('left'));
    rightZone.addEventListener('click', () => openPanel('right'));
    mainZone.addEventListener('click', closePanels);
    closeLeftPanel.addEventListener('click', closePanels);
    closeRightPanel.addEventListener('click', closePanels);

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closePanels();
      }
    });

    const notifications = notificationArea.querySelectorAll('.notification-item');
    notifications.forEach(notification => {
      setTimeout(() => {
        notification.classList.add('animate__animated', 'animate__fadeOutRight');
        setTimeout(() => notification.remove(), 300);
      }, 5000);
    });

    window.showForm = function(formId, animation = 'fade-in') {
      loginForm.classList.add('hidden');
      registerForm.classList.add('hidden');

      loginForm.classList.remove('animate__animated', 'animate__fadeIn', 'animate__slideInRight', 'animate__slideInLeft');
      registerForm.classList.remove('animate__animated', 'animate__fadeIn', 'animate__slideInRight', 'animate__slideInLeft');

      const formToShow = document.getElementById(formId);
      formToShow.classList.remove('hidden');

      if (animation === 'slide-right') {
        formToShow.classList.add('animate__animated', 'animate__slideInRight');
      } else if (animation === 'slide-left') {
        formToShow.classList.add('animate__animated', 'animate__slideInLeft');
      } else {
        formToShow.classList.add('animate__animated', 'animate__fadeIn');
      }

      mainButtons.classList.add('hidden');
      mainTitle.classList.add('hidden');
      mainSubtitle.classList.add('hidden');

      if (formId === 'registerForm') {
        showRoleSelection();
      }

      // 2026-09-09 - real accessibility feedback: the registration card
      // needs to be bigger/more readable for the actual user base (real
      // LSPU staff in their 30s-40s, some needing glasses), and the seal
      // was competing with the form for vertical space. Login stays
      // simple (one field) so the seal keeps its spot there; the
      // register flow hides it and reclaims that space for the form.
      const mainContainerEl = document.querySelector('.main-container');
      if (mainContainerEl) {
        mainContainerEl.classList.toggle('registering', formId === 'registerForm');
      }

      fitToViewport();
      setTimeout(fitToViewport, 60);
      setTimeout(fitToViewport, 350);
    };

    window.closeForm = function() {
      loginForm.classList.add('hidden');
      registerForm.classList.add('hidden');

      mainButtons.classList.remove('hidden');
      mainTitle.classList.remove('hidden');
      mainSubtitle.classList.remove('hidden');
      mainButtons.classList.add('animate__animated', 'animate__fadeIn');

      const mainContainerEl = document.querySelector('.main-container');
      if (mainContainerEl) {
        mainContainerEl.classList.remove('registering');
        mainContainerEl.classList.remove('role-form-active');
      }

      // 2026-09-09 fix - real bug: this never reset the INNER role-form-
      // section .hidden states, only the outer loginForm/registerForm -
      // so a role form's own .hidden class could still be missing next
      // time, even though showRoleForm()'s offsetParent-based active
      // check now handles that case safely too (belt and suspenders:
      // fixing the state itself, not just working around it downstream).
      const roleSelectionSectionEl = document.getElementById('roleSelectionSection');
      if (roleSelectionSectionEl) {
        roleSelectionSectionEl.classList.remove('hidden');
      }
      ['userFormSection', 'adminFormSection', 'deanFormSection', 'non_teachingFormSection'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
      });

      fitToViewport();
      setTimeout(fitToViewport, 60);
    };

    window.showRoleSelection = function() {
      document.getElementById('userFormSection').classList.add('hidden');
      document.getElementById('adminFormSection').classList.add('hidden');
      document.getElementById('deanFormSection').classList.add('hidden');
      document.getElementById('non_teachingFormSection').classList.add('hidden');

      // 2026-09-09 fix - "bigger" was specifically about the field-filled
      // role forms, not the 4-tile role-picker screen - that screen was
      // inheriting the same wider max-width/upscale treatment via
      // .registering alone (which covers this whole Create Account flow,
      // selection screen included) and looked disproportionately huge
      // for how little content it holds. This class narrows the "bigger"
      // CSS/JS to specifically when a role FORM is showing.
      document.querySelector('.main-container').classList.remove('role-form-active');

      document.getElementById('roleSelectionSection').classList.remove('hidden');
      document.getElementById('roleSelectionSection').classList.add('animate__animated', 'animate__fadeIn');

      setTimeout(() => {
        document.getElementById('roleSelectionSection').classList.remove('animate__animated', 'animate__fadeIn');
      }, 500);

      fitToViewport();
      setTimeout(fitToViewport, 60);
    };

    window.showRoleForm = function(role) {
      document.getElementById('roleSelectionSection').classList.add('hidden');
      document.querySelector('.main-container').classList.add('role-form-active');

      const selectedForm = document.getElementById(`${role}FormSection`);
      selectedForm.classList.remove('hidden');
      selectedForm.classList.add('animate__animated', 'animate__fadeIn');

      setTimeout(() => {
        selectedForm.classList.remove('animate__animated', 'animate__fadeIn');
      }, 500);

      fitToViewport();
      setTimeout(fitToViewport, 60);
      setTimeout(fitToViewport, 350);
    };
  });
</script>

</body>
</html>