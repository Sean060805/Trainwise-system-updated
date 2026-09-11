<?php
/* ===================================================================
   EVALUATION FORMS — Admin view
   -------------------------------------------------------------------
   UI redesigned to match admin_page.php / Assessment Form.php /
   Individual_Development_Plan_Form.php (enterprise dashboard system).
   ALL backend logic preserved: auth gate, evaluation list query +
   filters, view_evaluation handler, send_to_user workflow + notify,
   departments list, and the server-rendered evaluation modal.
   =================================================================== */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database connection
require_once 'config.php';

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $con->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Initialize variables
$evaluations = [];
$error_message = null;
$evaluation_details = null;
$evaluation_ratings = [];
$workflow_history = [];

// Build the query to get ALL evaluations (not just submitted)
$sql = "
    SELECT 
        e.id as evaluation_id,
        e.user_id,
        e.status,
        e.created_at,
        e.updated_at,
        e.sent_to_user_at,
        u.name as employee_name,
        u.department,
        u.teaching_status,
        evaluator.name as evaluator_name,
        sent_by.name as sent_by_name
    FROM evaluations e
    JOIN users u ON e.user_id = u.id
    JOIN users evaluator ON e.evaluator_id = evaluator.id
    LEFT JOIN users sent_by ON e.sent_by = sent_by.id
    WHERE u.role != 'admin'
    AND u.department IS NOT NULL
    AND u.department != 'admin'
    AND e.status IN ('submitted', 'sent_to_user', 'approved')
";

$params = [];
$types = "";

// Apply filters
$filters = [];
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $sql .= " AND u.department = ?";
    $params[] = $_GET['department'];
    $types .= "s";
    $filters['department'] = $_GET['department'];
}

if (isset($_GET['employment_type']) && !empty($_GET['employment_type'])) {
    $sql .= " AND u.teaching_status = ?";
    $params[] = $_GET['employment_type'];
    $types .= "s";
    $filters['employment_type'] = $_GET['employment_type'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $sql .= " AND (u.name LIKE ? OR u.department LIKE ?)";
    $search_term = "%" . $_GET['search'] . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
    $filters['search'] = $_GET['search'];
}

// Add ordering - show newest first
$sql .= " ORDER BY e.created_at DESC, u.name ASC";

try {
    if (!empty($params)) {
        $stmt = $con->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $con->query($sql);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $evaluations[] = $row;
        }
    } else {
        throw new Exception("Query failed: " . $con->error);
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "An error occurred while fetching evaluation data: " . $e->getMessage();
}

// Handle viewing evaluation form in modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_evaluation'])) {
    $evaluation_id = $_POST['evaluation_id'];
    
    try {
        // Get evaluation data
        $evaluation_sql = "SELECT 
            e.*,
            u.name as employee_name,
            u.department as employee_department,
            evaluator.name as evaluator_name,
            sent_by.name as sent_by_name
        FROM evaluations e
        JOIN users u ON e.user_id = u.id
        JOIN users evaluator ON e.evaluator_id = evaluator.id
        LEFT JOIN users sent_by ON e.sent_by = sent_by.id
        WHERE e.id = ?";

        $stmt = $con->prepare($evaluation_sql);
        $stmt->bind_param("i", $evaluation_id);
        $stmt->execute();
        $evaluation_result = $stmt->get_result();

        if ($evaluation_result->num_rows > 0) {
            $evaluation_details = $evaluation_result->fetch_assoc();

            // Get evaluation ratings
            $ratings_sql = "SELECT * FROM evaluation_ratings WHERE evaluation_id = ? ORDER BY question_number";
            $ratings_stmt = $con->prepare($ratings_sql);
            $ratings_stmt->bind_param("i", $evaluation_id);
            $ratings_stmt->execute();
            $ratings_result = $ratings_stmt->get_result();

            while ($rating = $ratings_result->fetch_assoc()) {
                $evaluation_ratings[$rating['question_number']] = $rating;
            }

            // Get workflow history
            $workflow_sql = "SELECT 
                wf.*,
                u.name as changed_by_name
            FROM evaluation_workflow wf
            JOIN users u ON wf.changed_by = u.id
            WHERE wf.evaluation_id = ?
            ORDER BY wf.created_at ASC";

            $workflow_stmt = $con->prepare($workflow_sql);
            $workflow_stmt->bind_param("i", $evaluation_id);
            $workflow_stmt->execute();
            $workflow_result = $workflow_stmt->get_result();

            while ($history = $workflow_result->fetch_assoc()) {
                $workflow_history[] = $history;
            }

            // Set flag to show modal
            $show_evaluation_modal = true;
        } else {
            $error_message = "Evaluation not found";
        }
    } catch (Exception $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Handle sending evaluation to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_user'])) {
    $evaluation_id = $_POST['evaluation_id'];
    $target_user_id = $_POST['user_id'];
    
    try {
        // Get user first name
        $user_sql = "SELECT name FROM users WHERE id = ?";
        $user_stmt = $con->prepare($user_sql);
        $user_stmt->bind_param("i", $target_user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $target_user = $user_result->fetch_assoc();
        
        if ($target_user) {
            // Extract first name
            $first_name = explode(' ', $target_user['name'])[0];
            
            // Update evaluation status to sent_to_user and record who sent it
            $update_sql = "UPDATE evaluations SET status = 'sent_to_user', sent_by = ?, sent_to_user_at = NOW(), updated_at = NOW() WHERE id = ?";
            $update_stmt = $con->prepare($update_sql);
            $update_stmt->bind_param("ii", $_SESSION['user_id'], $evaluation_id);
            $update_stmt->execute();
            
            // Add to workflow history
            $workflow_sql = "INSERT INTO evaluation_workflow (evaluation_id, from_status, to_status, changed_by, comments) 
                             VALUES (?, 'submitted', 'sent_to_user', ?, ?)";
            $workflow_stmt = $con->prepare($workflow_sql);
            $comments = "Evaluation sent to " . $first_name;
            $workflow_stmt->bind_param("iis", $evaluation_id, $_SESSION['user_id'], $comments);
            $workflow_stmt->execute();

            // Create notification for the user
            $notification_sql = "INSERT INTO notifications (user_id, message, related_type, related_id, is_read) 
                                VALUES (?, ?, 'evaluation', ?, 0)";
            $notification_stmt = $con->prepare($notification_sql);
            $notification_message = "You have been evaluated! View your evaluation results.";
            $notification_stmt->bind_param("isi", $target_user_id, $notification_message, $evaluation_id);
            $notification_stmt->execute();
            
            $_SESSION['success_message'] = "Evaluation successfully sent to " . $first_name . "!";
            header("Location: Evaluation_Form.php?success=1");
            exit();
        } else {
            $error_message = "User not found";
        }
    } catch (Exception $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Define departments from your image
$departments = [
    ['department' => 'College of Agriculture (CA)'],
    ['department' => 'College of Arts and Sciences (CAS)'],
    ['department' => 'College of Business, Administration and Accountancy (CBAA)'],
    ['department' => 'College of Computer Studies (CCS)'],
    ['department' => 'College of Criminal Justice Education (CCJE)'],
    ['department' => 'College of Engineering (COE)'],
    ['department' => 'College of Industrial Technology (CIT)'],
    ['department' => 'College of Food, Nutrition and Dietetics (CFND)'],
    ['department' => 'College of Fisheries (COF)'],
    ['department' => 'College of International Hospitality and Tourism Management (CHTM)'],
    ['department' => 'College of Hospitality Management and Tourism (CHMT)'],
    ['department' => 'College of Teacher Education (CTE)'],
    ['department' => 'College of Nursing and Allied Health (CONAH)'],
    ['department' => 'College of Law (COL)']
];

// Get teaching statuses from database
$employment_types = $con->query("SELECT DISTINCT teaching_status FROM users WHERE teaching_status IS NOT NULL AND teaching_status != '' AND role != 'admin' ORDER BY teaching_status")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Evaluation Forms · LSPU TNA</title>

  <!-- FONTS — Plus Jakarta Sans (display) + Inter (body) + JetBrains Mono (data) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- Icon sets (kept from original) -->
  <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- TAILWIND (CDN) — palette extended to the enterprise tokens -->
  <link rel="stylesheet" href="assets/css/tw-43.css">
<style>
  /* ==========================================================
     DESIGN SYSTEM — shared across all admin pages
     ========================================================== */
  :root {
    --bg:#f5f6f8;
    --bg-grad:radial-gradient(1100px 600px at 100% -8%, rgba(99,102,241,0.05), transparent 60%);
    --surface:#ffffff; --surface-2:#f8fafc; --surface-3:#f1f3f7;
    --ink:#0f172a; --ink-2:#334155; --muted:#64748b; --faint:#94a3b8;
    --line:#e5e7eb; --line-soft:#eef1f5;
    --accent:#4f46e5; --accent-700:#4338ca; --accent-soft:#eef2ff; --accent-ink:#3730a3;
    --ok:#059669; --ok-soft:#ecfdf5; --ok-ink:#065f46;
    --warn:#d97706; --warn-soft:#fffbeb; --warn-ink:#92400e;
    --bad:#e11d48; --bad-soft:#fff1f2; --bad-ink:#9f1239;
    --sky:#0284c7; --sky-soft:#f0f9ff;
    --rail:#0f172a; --rail-2:#111c33; --rail-line:rgba(148,163,184,0.14);
    --rail-text:rgba(226,232,240,0.74); --rail-text-2:rgba(148,163,184,0.55);
    --radius:14px; --radius-sm:10px; --radius-lg:18px;
    --rail-w:264px; --rail-w-min:78px; --topbar-h:66px;
    --ease:cubic-bezier(0.16,1,0.3,1);
    --shadow-card:0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -18px rgba(15,23,42,0.22);
    --shadow-pop:0 16px 40px -12px rgba(15,23,42,0.22);
  }

  *,*::before,*::after { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  html,body { height:100%; }
  body { margin:0; font-family:'Inter',system-ui,sans-serif; color:var(--ink); background:var(--bg-grad), var(--bg); -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
  h1,h2,h3,h4,h5,h6 { font-family:'Plus Jakarta Sans','Inter',sans-serif; letter-spacing:-0.015em; margin:0; }
  .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-feature-settings:"tnum" 1; letter-spacing:-0.02em; }
  a { color:inherit; text-decoration:none; }
  :focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:6px; }
  .eyebrow { font-size:0.68rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--faint); }

  /* SHELL */
  .app { display:grid; grid-template-columns:var(--rail-w) 1fr; min-height:100vh; transition:grid-template-columns 0.28s var(--ease); }
  .app.rail-collapsed { grid-template-columns:var(--rail-w-min) 1fr; }
  .app-main { min-width:0; display:flex; flex-direction:column; max-height:100vh; overflow:hidden; }
  .content-scroll { flex:1 1 auto; overflow-y:auto; overflow-x:hidden; }
  .content { max-width:1640px; margin:0 auto; padding:1.6rem 1.8rem 3rem; }

  /* SIDEBAR */
  .rail { background:linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color:var(--rail-text); display:flex; flex-direction:column; position:sticky; top:0; height:100vh; border-right:1px solid rgba(0,0,0,0.2); z-index: 50; }
  .rail-brand { display:flex; align-items:center; gap:0.75rem; padding:1.15rem 1.25rem; border-bottom:1px solid var(--rail-line); min-height:var(--topbar-h); }
  .rail-logo { width:42px; height:42px; border-radius:12px; background:#fff; padding:5px; flex-shrink:0; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 16px -8px rgba(0,0,0,0.6); }
  .rail-logo-fallback { width:42px; height:42px; border-radius:12px; flex-shrink:0; background:linear-gradient(135deg, var(--accent), #6366f1); display:flex; align-items:center; justify-content:center; }
  .rail-brand-text { min-width:0; transition:opacity 0.2s var(--ease); }
  .rail-brand-text h1 { font-size:1rem; color:#fff; line-height:1.1; white-space:nowrap; }
  .rail-brand-text p  { font-size:0.7rem; color:var(--rail-text-2); margin-top:2px; white-space:nowrap; }
  .rail-nav { flex:1 1 auto; overflow-y:auto; padding:1rem 0.7rem; }
  .rail-section-label { font-size:0.64rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--rail-text-2); padding:0 0.85rem; margin:0.4rem 0 0.55rem; transition:opacity 0.2s var(--ease); }
  .rail-link { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; margin:2px 0; border-radius:11px; color:var(--rail-text); font-size:0.875rem; font-weight:500; border:1px solid transparent; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); position:relative; white-space:nowrap; }
  .rail-link i:first-child { font-size:1.2rem; flex-shrink:0; width:22px; text-align:center; }
  .rail-link:hover { background:rgba(255,255,255,0.06); color:#fff; }
  .rail-link.active { background:linear-gradient(100deg, rgba(99,102,241,0.22), rgba(99,102,241,0.08)); color:#fff; border-color:rgba(129,140,248,0.32); }
  .rail-link.active::before { content:''; position:absolute; left:-0.7rem; top:50%; transform:translateY(-50%); width:3px; height:22px; border-radius:0 4px 4px 0; background:#818cf8; }
  .rail-link .chev { margin-left:auto; opacity:0.5; font-size:1rem; }
  .rail-foot { padding:0.7rem; border-top:1px solid var(--rail-line); }
  .rail-signout { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; border-radius:11px; color:#fda4af; font-size:0.875rem; font-weight:500; border:1px solid rgba(244,63,94,0.18); transition:background 0.18s var(--ease); white-space:nowrap; }
  .rail-signout i { font-size:1.2rem; width:22px; text-align:center; }
  .rail-signout:hover { background:rgba(244,63,94,0.16); color:#fecdd3; }
  .app.rail-collapsed .rail-brand-text,
  .app.rail-collapsed .rail-section-label,
  .app.rail-collapsed .rail-link span,
  .app.rail-collapsed .rail-link .chev,
  .app.rail-collapsed .rail-signout span { opacity:0; pointer-events:none; width:0; overflow:hidden; }
  .rail-scrim { position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(2px); opacity:0; visibility:hidden; transition:opacity 0.3s var(--ease); z-index:39; }

  /* TOPBAR */
  .topbar { height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:1rem; padding:0 1.4rem; background:rgba(255,255,255,0.85); backdrop-filter:blur(12px); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:30; }
  .icon-btn { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); background:var(--surface); color:var(--ink-2); font-size:1.2rem; cursor:pointer; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease); flex-shrink:0; }
  .icon-btn:hover { background:var(--surface-2); border-color:#cbd5e1; color:var(--ink); }
  .hamburger { display:none; }
  .topbar-right { margin-left:auto; display:flex; align-items:center; gap:0.6rem; }
  .avatar-chip { display:flex; align-items:center; gap:0.6rem; padding:0.3rem 0.55rem 0.3rem 0.35rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); cursor:default; }
  .avatar { width:34px; height:34px; border-radius:9px; flex-shrink:0; background:linear-gradient(135deg, var(--accent), #818cf8); color:#fff; font-weight:700; font-size:0.85rem; display:flex; align-items:center; justify-content:center; font-family:'Plus Jakarta Sans',sans-serif; }
  .avatar-meta { line-height:1.15; text-align:left; }
  .avatar-meta .nm { font-size:0.82rem; font-weight:600; color:var(--ink); }
  .avatar-meta .rl { font-size:0.7rem; color:var(--muted); }
  .date-chip { display:inline-flex; align-items:center; gap:0.55rem; padding:0.5rem 0.85rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); }
  .date-chip i { color:var(--accent); font-size:1.05rem; }
  .date-chip span { font-size:0.82rem; font-weight:600; color:var(--ink-2); }

  /* PAGE HEADER */
  .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
  .page-head h2 { font-size:1.55rem; font-weight:800; color:var(--ink); }
  .page-head p { color:var(--muted); font-size:0.9rem; margin-top:0.25rem; }

  /* CARDS + STAT */
  .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; }
  .card-body { padding:1.3rem; }
  .stat { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
  .stat:hover { transform:translateY(-3px); box-shadow:var(--shadow-pop); }
  .stat::after { content:''; position:absolute; inset:0 0 auto 0; height:3px; background:linear-gradient(90deg, #6366f1, #4f46e5); }
  .stat-body { padding:1.25rem 1.3rem; display:flex; align-items:center; justify-content:space-between; }
  .stat-label { font-size:0.8rem; font-weight:500; color:var(--muted); }
  .stat-value { font-size:2rem; font-weight:700; margin-top:0.35rem; line-height:1; }
  .stat-sub { font-size:0.72rem; color:var(--faint); margin-top:0.4rem; }
  .stat-ico { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; background:var(--accent-soft); color:var(--accent); }

  /* BUTTONS (incl. legacy view/send/sent used in markup) */
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600; padding:0.65rem 1.1rem; border-radius:var(--radius-sm); border:1px solid transparent; cursor:pointer; white-space:nowrap; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), transform 0.12s var(--ease); }
  .btn:active { transform:translateY(1px); }
  .btn-primary { background:var(--accent); color:#fff; }
  .btn-primary:hover { background:var(--accent-700); }
  .btn-ghost { background:var(--surface); color:var(--ink-2); border-color:var(--line); }
  .btn-ghost:hover { background:var(--surface-2); border-color:#cbd5e1; }
  .btn-success { background:var(--ok); color:#fff; }
  .btn-success:hover { background:#047857; }
  .view-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--accent); color:#fff; padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.83rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(79,70,229,0.7); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
  .view-btn:hover { background:var(--accent-700); }
  .view-btn:active { transform:translateY(1px); }
  .send-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--ok); color:#fff; padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.83rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); transition:background 0.18s var(--ease), transform 0.12s var(--ease); margin-left:0.5rem; }
  .send-btn:hover { background:#047857; }
  .send-btn:active { transform:translateY(1px); }
  .sent-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--surface-3); color:var(--muted); padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.83rem; font-weight:600; border:1px solid var(--line); margin-left:0.5rem; cursor:not-allowed; }

  /* STATUS BADGES (class names preserved from original markup) */
  .status-badge { display:inline-flex; align-items:center; gap:0.3rem; padding:0.32rem 0.7rem; border-radius:999px; font-size:0.7rem; font-weight:600; letter-spacing:0.02em; }
  .status-teaching    { background:var(--ok-soft);     color:var(--ok-ink); }
  .status-nonteaching { background:var(--accent-soft); color:var(--accent-ink); }
  .status-submitted   { background:var(--warn-soft);   color:var(--warn-ink); }
  .status-sent        { background:var(--ok-soft);     color:var(--ok-ink); }
  .status-approved    { background:var(--accent-soft); color:var(--accent-ink); }
  .status-rejected    { background:var(--bad-soft);    color:var(--bad-ink); }
  .department-badge { display:inline-flex; align-items:center; gap:0.3rem; background:var(--accent-soft); color:var(--accent-ink); padding:0.32rem 0.7rem; border-radius:999px; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; }
  .timestamp { font-size:0.74rem; color:var(--faint); }

  /* MAIN TABLE */
  .table-wrap { border:1px solid var(--line); border-radius:var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; background:var(--surface); }
  .table-scroll { overflow-x:auto; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { background:var(--surface-2); text-align:left; font-size:0.7rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--muted); padding:0.85rem 1.15rem; border-bottom:1px solid var(--line); white-space:nowrap; }
  table.data td { padding:0.95rem 1.15rem; border-bottom:1px solid var(--line-soft); font-size:0.875rem; color:var(--ink-2); vertical-align:middle; }
  table.data tbody tr { transition:background 0.15s var(--ease); }
  table.data tbody tr:nth-child(even) { background:var(--surface-2); }
  table.data tbody tr:hover { background:var(--accent-soft); }
  table.data tbody tr:last-child td { border-bottom:none; }
  .cell-name { font-weight:600; color:var(--ink); }
  .cell-sub { font-size:0.74rem; color:var(--faint); margin-top:2px; }
  .emp-avatar { width:38px; height:38px; border-radius:10px; background:var(--accent-soft); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }

  /* FILTER BAR (native auto-submit selects + search inside a GET form) */
  .filterbar { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; }
  .status-select { height:42px; padding:0 2.4rem 0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.85rem; color:var(--ink-2); font-weight:500; cursor:pointer; appearance:none; max-width:340px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='16' height='16'%3E%3Cpath d='M12 15l-4.243-4.243 1.415-1.414L12 12.172l2.828-2.829 1.415 1.414z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 0.7rem center; }
  .status-select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(79,70,229,0.12); }
  .search { position:relative; flex:1 1 240px; min-width:200px; }
  .search input { width:100%; height:42px; padding:0 2.6rem 0 2.6rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.875rem; color:var(--ink); transition:border-color 0.18s var(--ease), box-shadow 0.18s var(--ease); }
  .search input::placeholder { color:var(--faint); }
  .search input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(79,70,229,0.12); }
  .search > i { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--faint); font-size:1.05rem; pointer-events:none; }
  .search-go { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; border-radius:8px; background:var(--accent); color:#fff; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background 0.18s var(--ease); }
  .search-go:hover { background:var(--accent-700); }

  /* ALERTS */
  .alert { display:flex; align-items:center; gap:0.6rem; padding:0.9rem 1.1rem; border-radius:12px; font-size:0.875rem; margin-bottom:1.4rem; border:1px solid; }
  .alert-success { background:var(--ok-soft); border-color:#a7f3d0; color:var(--ok-ink); }
  .alert-error { background:var(--bad-soft); border-color:#fecdd3; color:var(--bad-ink); }
  .alert i { font-size:1.2rem; }

  /* EMPTY */
  .empty { text-align:center; padding:3rem 1rem; color:var(--faint); }
  .empty i { font-size:3.2rem; color:#cbd5e1; }
  .empty h4 { font-size:1.05rem; color:var(--muted); margin-top:0.8rem; font-weight:600; }
  .empty p { font-size:0.85rem; color:var(--faint); margin-top:0.3rem; }

  /* ==========================================================
     MODAL — .modal-overlay/.active kept so existing JS works.
     ========================================================== */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); backdrop-filter:blur(6px); z-index:1000; align-items:center; justify-content:center; padding:1rem; }
  .modal-overlay.active { display:flex; animation:fadeIn 0.18s var(--ease); }
  @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
  .modal-content { background:var(--surface); border-radius:20px; width:100%; max-width:1000px; max-height:90vh; overflow:hidden; box-shadow:0 30px 60px -20px rgba(15,23,42,0.45); animation:popIn 0.26s var(--ease); display:flex; flex-direction:column; }
  @keyframes popIn { from { opacity:0; transform:translateY(14px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
  .modal-header { padding:1.15rem 1.5rem; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; background:var(--surface); flex-shrink:0; }
  .modal-header h3 { font-size:1.1rem; font-weight:700; color:var(--ink); }
  .modal-body { padding:1.4rem 1.5rem; overflow-y:auto; flex:1 1 auto; }
  .modal-footer { padding:1rem 1.5rem; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:0.7rem; background:var(--surface-2); flex-shrink:0; }
  .modal-x { width:38px; height:38px; border-radius:10px; border:1px solid var(--line); background:var(--surface); color:var(--muted); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); }
  .modal-x:hover { background:var(--bad-soft); color:var(--bad); border-color:#fecdd3; }

  /* MODAL CONTENT COMPONENTS (class names preserved from original) */
  .form-field { border:1px solid var(--line); background:var(--surface-2); padding:0.55rem 0.85rem; border-radius:9px; width:100%; color:var(--ink-2); font-size:0.875rem; }
  .rating-cell { text-align:center; padding:8px 4px; }
  .rating-selected { background:var(--accent); color:#fff; border-radius:6px; }
  .workflow-timeline { border-left:2px solid var(--line); margin-left:10px; }
  .workflow-step { position:relative; padding-left:20px; margin-bottom:1rem; }
  .workflow-step::before { content:''; position:absolute; left:-6px; top:6px; width:10px; height:10px; border-radius:50%; background:var(--accent); }
  .signature-preview { border:1px dashed #cbd5e1; background:var(--surface-2); height:80px; display:flex; align-items:center; justify-content:center; border-radius:10px; }
  .signature-image { max-width:100%; max-height:70px; }
  .sent-info { background:var(--sky-soft); border:1px solid #bae6fd; border-radius:10px; padding:1rem; margin-top:1rem; }
  .sent-info p { margin:0.25rem 0; color:#0c4a6e; font-size:0.875rem; }
  .evaluation-detail { margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:1px solid var(--line); }
  .evaluation-detail:last-child { border-bottom:none; }
  .detail-label { font-weight:600; color:var(--ink-2); margin-bottom:0.5rem; }
  .detail-value { color:var(--muted); }
  .auto-submit { cursor:pointer; }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:9px; height:9px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; border:2px solid transparent; background-clip:content-box; }
  ::-webkit-scrollbar-thumb:hover { background:#94a3b8; background-clip:content-box; }

  /* RESPONSIVE */
  @media (max-width:900px) {
    .app, .app.rail-collapsed { grid-template-columns:1fr; }
    .rail { position:fixed; top:0; left:0; width:var(--rail-w); height: 100vh; height: 100dvh; transform:translateX(-100%); transition:transform 0.3s var(--ease); }
    .app.rail-open .rail { transform:translateX(0); box-shadow:24px 0 60px -20px rgba(0,0,0,0.5); }
    .app.rail-open .rail-scrim { opacity:1; visibility:visible; }
    .hamburger { display:inline-flex; }
    .content { padding:1.1rem 1.1rem 2.5rem; }
    .avatar-meta, .date-chip { display:none; }
  }
  @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
</style>
</head>
<body>
<div class="app" id="app">
  <div class="rail-scrim" onclick="closeMobileRail()" aria-hidden="true"></div>

  <!-- ---------- SIDEBAR ---------- -->
  <aside class="rail" id="rail">
    <div class="rail-brand">
      <img src="images/lspu-logo.png" alt="LSPU" class="rail-logo"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
      <div class="rail-logo-fallback" style="display:none;">
        <i class="ri-government-line text-white text-2xl"></i>
      </div>
      <div class="rail-brand-text">
        <h1>LSPU Admin</h1>
        <p>Evaluation Forms</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="admin_page.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>

      <p class="rail-section-label">Training</p>
      <a href="training_pipeline.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Pipeline</span>
      </a>
      <a href="reports_analytics.php" class="rail-link">
        <i class="ri-bar-chart-box-line"></i><span>Reports &amp; Analytics</span>
      </a>
      <a href="audit_log.php" class="rail-link">
        <i class="ri-history-line"></i><span>Audit Log</span>
      </a>

      <p class="rail-section-label">Forms</p>
      <a href="Assessment Form.php" class="rail-link">
        <i class="ri-survey-line"></i><span>Assessment Forms</span>
      </a>
      <a href="Individual_Development_Plan_Form.php" class="rail-link">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
      </a>
      <a href="Evaluation_Form.php" class="rail-link active">
        <i class="ri-file-search-line"></i><span>Evaluation Forms</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>
    </nav>

    <div class="rail-foot">
      <a href="index.php" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- ---------- MAIN COLUMN ---------- -->
  <div class="app-main">

    <!-- TOPBAR -->
    <header class="topbar">
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu"><i class="ri-menu-line"></i></button>
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn" style="display:none;"><i class="ri-side-bar-line"></i></button>
      <div class="topbar-right">
        <div class="date-chip"><i class="ri-calendar-2-line"></i><span><?php echo date('F j, Y'); ?></span></div>
        <div class="avatar-chip" aria-label="Account">
          <span class="avatar"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></span>
          <span class="avatar-meta"><span class="nm"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></span><span class="rl">Evaluation Forms</span></span>
        </div>
      </div>
    </header>

    <!-- SCROLLABLE CONTENT -->
    <div class="content-scroll">
      <div class="content">

        <!-- PAGE HEADER -->
        <div class="page-head">
          <div>
            <p class="eyebrow">Training Impact Assessment</p>
            <h2>Evaluation Forms</h2>
            <p>View and manage submitted evaluation forms.</p>
          </div>
        </div>

        <!-- SUCCESS MESSAGE -->
        <?php if (isset($_SESSION['success_message'])): ?>
          <div class="alert alert-success">
            <i class="ri-checkbox-circle-line"></i>
            <span><?= $_SESSION['success_message'] ?></span>
          </div>
          <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- ERROR MESSAGE -->
        <?php if ($error_message): ?>
          <div class="alert alert-error">
            <i class="ri-error-warning-line"></i>
            <span><?= $error_message ?></span>
          </div>
        <?php endif; ?>

        <!-- STAT CARD -->
        <div class="stat" style="max-width:340px; margin-bottom:1.4rem;">
          <div class="stat-body">
            <div>
              <p class="stat-label">Total Evaluations</p>
              <p class="stat-value num"><?= count($evaluations) ?></p>
              <p class="stat-sub">all evaluation forms</p>
            </div>
            <div class="stat-ico"><i class="ri-file-list-3-line"></i></div>
          </div>
        </div>

        <!-- FILTER BAR — GET form, native auto-submit selects + search -->
        <div class="card" style="margin-bottom:1.1rem;">
          <div class="card-body" style="padding:1.05rem 1.15rem;">
            <form method="GET" action="Evaluation_Form.php">
              <div class="filterbar">
                <!-- Department -->
                <select name="department" class="status-select auto-submit" aria-label="Filter by department">
                  <option value="">All Departments</option>
                  <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept['department']) ?>"
                      <?= isset($_GET['department']) && $_GET['department'] === $dept['department'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($dept['department']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <!-- Teaching status -->
                <select name="employment_type" class="status-select auto-submit" aria-label="Filter by teaching status">
                  <option value="">All Status</option>
                  <?php foreach ($employment_types as $status): ?>
                    <option value="<?= htmlspecialchars($status['teaching_status']) ?>"
                      <?= isset($_GET['employment_type']) && $_GET['employment_type'] === $status['teaching_status'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($status['teaching_status']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <!-- Search -->
                <div class="search">
                  <i class="ri-search-line"></i>
                  <input type="text" name="search" id="search-input" placeholder="Search by name or department…"
                         value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" />
                  <button type="submit" class="search-go" aria-label="Search"><i class="ri-arrow-right-line"></i></button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- CLEAR FILTERS + RESULTS COUNT -->
        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:0.9rem;">
          <p style="font-size:0.82rem; color:var(--muted);">
            Showing <span class="num"><?= count($evaluations) ?></span> evaluation<?= count($evaluations) !== 1 ? 's' : '' ?>
            <?php if (!empty($filters)): ?>with applied filters<?php endif; ?>
          </p>
          <?php if (!empty($filters)): ?>
            <a href="Evaluation_Form.php" class="btn btn-ghost btn-sm" style="padding:0.5rem 0.85rem;">
              <i class="ri-close-line"></i> Clear All Filters
            </a>
          <?php endif; ?>
        </div>

        <!-- EVALUATIONS TABLE -->
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="data">
              <thead>
                <tr>
                  <th>Employee Name</th>
                  <th>Department</th>
                  <th>Teaching Status</th>
                  <th>Status</th>
                  <th>Submitted Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($evaluations)): ?>
                  <?php foreach ($evaluations as $eval): ?>
                    <tr>
                      <td>
                        <div style="display:flex; align-items:center; gap:0.7rem;">
                          <div class="emp-avatar"><i class="ri-user-line"></i></div>
                          <div>
                            <div class="cell-name"><?= htmlspecialchars($eval['employee_name']) ?></div>
                            <div class="cell-sub">Evaluated by: <?= htmlspecialchars($eval['evaluator_name']) ?></div>
                          </div>
                        </div>
                      </td>
                      <td><span class="department-badge"><?= htmlspecialchars($eval['department']) ?></span></td>
                      <td>
                        <?php if (strtolower($eval['teaching_status']) == 'teaching'): ?>
                          <span class="status-badge status-teaching"><i class="ri-user-star-fill"></i> Teaching</span>
                        <?php else: ?>
                          <span class="status-badge status-nonteaching"><i class="ri-user-fill"></i> Non-Teaching</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($eval['status'] === 'submitted'): ?>
                          <span class="status-badge status-submitted"><i class="ri-send-plane-fill"></i> Submitted</span>
                        <?php elseif ($eval['status'] === 'sent_to_user'): ?>
                          <span class="status-badge status-sent"><i class="ri-check-double-fill"></i> Sent to User</span>
                          <?php if (!empty($eval['sent_by_name'])): ?>
                            <div class="cell-sub">By: <?= htmlspecialchars($eval['sent_by_name']) ?></div>
                          <?php endif; ?>
                        <?php elseif ($eval['status'] === 'approved'): ?>
                          <span class="status-badge status-approved"><i class="ri-checkbox-circle-fill"></i> Approved</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="num" style="color:var(--ink-2);"><?= date('M j, Y', strtotime($eval['created_at'])) ?></div>
                        <div class="timestamp num"><?= date('g:i A', strtotime($eval['created_at'])) ?></div>
                      </td>
                      <td>
                        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:0;">
                          <form method="POST" action="" class="inline">
                            <input type="hidden" name="evaluation_id" value="<?= $eval['evaluation_id'] ?>">
                            <button type="submit" name="view_evaluation" class="view-btn"><i class="ri-eye-line"></i> View</button>
                          </form>
                          <?php if ($eval['status'] === 'submitted'): ?>
                            <form method="POST" action="" class="inline">
                              <input type="hidden" name="evaluation_id" value="<?= $eval['evaluation_id'] ?>">
                              <input type="hidden" name="user_id" value="<?= $eval['user_id'] ?>">
                              <button type="submit" name="send_to_user" class="send-btn" onclick="return confirm('Send this evaluation to <?= htmlspecialchars(explode(' ', $eval['employee_name'])[0]) ?>?')">
                                <i class="ri-send-plane-line"></i> Send
                              </button>
                            </form>
                          <?php elseif ($eval['status'] === 'sent_to_user'): ?>
                            <button class="sent-btn" disabled><i class="ri-check-double-line"></i> Sent</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty">
                        <i class="ri-file-search-line"></i>
                        <h4>No evaluations found</h4>
                        <p>
                          <?php if (!empty($filters)): ?>Try adjusting your filters or search terms<?php else: ?>No evaluation forms have been submitted yet<?php endif; ?>
                        </p>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /.content -->
    </div><!-- /.content-scroll -->
  </div><!-- /.app-main -->
</div><!-- /.app -->

<!-- =============================================================
     EVALUATION VIEW MODAL — server-rendered (PHP). All logic kept;
     wrapper + buttons restyled to the shared modal system.
     ============================================================= -->
<?php if (isset($show_evaluation_modal) && $show_evaluation_modal && $evaluation_details): ?>
<div class="modal-overlay active">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Training Program Impact Assessment Form</h3>
      <button type="button" class="modal-x close-modal-btn" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">

      <!-- Header Information -->
      <div class="flex justify-between items-center mb-6" style="border-bottom:1px solid var(--line); padding-bottom:1rem;">
        <div>
          <?php if ($evaluation_details['status'] === 'submitted'): ?>
            <span class="status-badge status-submitted"><i class="ri-send-plane-fill"></i> Submitted</span>
          <?php elseif ($evaluation_details['status'] === 'sent_to_user'): ?>
            <span class="status-badge status-sent"><i class="ri-check-double-fill"></i> Sent to User</span>
          <?php elseif ($evaluation_details['status'] === 'approved'): ?>
            <span class="status-badge status-approved"><i class="ri-checkbox-circle-fill"></i> Approved</span>
          <?php endif; ?>
          <span class="text-sm ml-4 num" style="color:var(--muted);">
            Created: <?= date('M d, Y h:i A', strtotime($evaluation_details['created_at'])) ?>
          </span>
          <?php if ($evaluation_details['updated_at'] != $evaluation_details['created_at']): ?>
          <span class="text-sm ml-4 num" style="color:var(--muted);">
            Updated: <?= date('M d, Y h:i A', strtotime($evaluation_details['updated_at'])) ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="flex space-x-2">
          <button onclick="window.open('evaluation_pdf.php?id=<?= $evaluation_details['id'] ?>', '_blank')" class="btn btn-success">
            <i class="ri-printer-line"></i> Print
          </button>
        </div>
      </div>

      <?php if ($evaluation_details['status'] === 'sent_to_user' && !empty($evaluation_details['sent_by_name'])): ?>
        <div class="sent-info">
          <p><strong>Sent to User:</strong> <?= date('M d, Y \a\t g:i A', strtotime($evaluation_details['sent_to_user_at'])) ?></p>
          <p><strong>Sent by:</strong> <?= htmlspecialchars($evaluation_details['sent_by_name']) ?></p>
        </div>
      <?php endif; ?>

      <!-- Basic Information -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6" style="margin-top:1.25rem;">
        <div>
          <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Name of Employee:</label>
          <div class="form-field"><?= htmlspecialchars($evaluation_details['employee_name']) ?></div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Department/Unit:</label>
          <div class="form-field"><?= htmlspecialchars($evaluation_details['employee_department']) ?></div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Title of Training/Seminar Attended:</label>
          <div class="form-field"><?= htmlspecialchars($evaluation_details['training_title']) ?></div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Date Conducted:</label>
          <div class="form-field"><?= date('M d, Y', strtotime($evaluation_details['date_conducted'])) ?></div>
        </div>
      </div>

      <!-- Objectives -->
      <div class="mb-6">
        <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Objective/s:</label>
        <div class="form-field" style="min-height:80px;"><?= nl2br(htmlspecialchars($evaluation_details['objectives'])) ?></div>
      </div>

      <!-- Ratings Table -->
      <div class="mb-6">
        <div style="background:var(--surface-2); border:1px solid var(--line); padding:0.85rem 1rem; border-radius:10px; margin-bottom:1rem;">
          <p class="text-sm" style="color:var(--ink-2);"><span class="font-semibold">INSTRUCTION:</span> Please check (&#10003;) in the appropriate column the impact/benefits gained by the employee in attending the training program in a scale of 1-5 (where 5 – Strongly Agree; 4 – Agree; 3 – Neither agree nor disagree; 2 – Disagree; 1 – Strongly Disagree)</p>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full" style="border-collapse:collapse; border:1px solid var(--line);">
            <thead>
              <tr style="background:var(--surface-2);">
                <th class="text-left py-3 px-4 font-semibold" style="color:var(--muted); border:1px solid var(--line); width:50%; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em;">Impact/Benefits Gained</th>
                <th class="text-center py-3 px-2 font-semibold" style="color:var(--muted); border:1px solid var(--line);">1</th>
                <th class="text-center py-3 px-2 font-semibold" style="color:var(--muted); border:1px solid var(--line);">2</th>
                <th class="text-center py-3 px-2 font-semibold" style="color:var(--muted); border:1px solid var(--line);">3</th>
                <th class="text-center py-3 px-2 font-semibold" style="color:var(--muted); border:1px solid var(--line);">4</th>
                <th class="text-center py-3 px-2 font-semibold" style="color:var(--muted); border:1px solid var(--line);">5</th>
                <th class="text-left py-3 px-4 font-semibold" style="color:var(--muted); border:1px solid var(--line); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em;">Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $questions = [
                  "1. The employee's performance became more efficient as shown with no/less commitment of mistakes on work.",
                  "2. The employee enhanced his/her ability to generate ideas and recommendations.",
                  "3. He/She has developed new system or improved the present system through contributing new ideas.",
                  "4. The employee's morale has been upgraded.",
                  "5. The employee has applied new skills in the performance of his/her work.",
                  "6. The employee became more proud and confident in his/her tasks.",
                  "7. The employee can now be entrusted higher/greater responsibility.",
                  "8. He/She transferred the knowledge and skills gained through conduct of workshop or demonstration to co-employee."
              ];

              for ($i = 1; $i <= 8; $i++):
                  $rating = $evaluation_ratings[$i] ?? null;
              ?>
              <tr style="background:<?= $i % 2 === 0 ? 'var(--surface-2)' : 'var(--surface)' ?>;">
                <td class="py-3 px-4 text-sm" style="border:1px solid var(--line); color:var(--ink-2);">
                  <?= $questions[$i-1] ?>
                </td>
                <?php for ($j = 1; $j <= 5; $j++): ?>
                <td class="rating-cell" style="border:1px solid var(--line);">
                  <?php if ($rating && $rating['rating'] == $j): ?>
                  <div class="rating-selected w-8 h-8 flex items-center justify-center mx-auto"><i class="ri-check-line"></i></div>
                  <?php else: ?>
                  <div class="w-8 h-8 flex items-center justify-center mx-auto num" style="color:var(--faint);"><?= $j ?></div>
                  <?php endif; ?>
                </td>
                <?php endfor; ?>
                <td class="py-2 px-4" style="border:1px solid var(--line);">
                  <div class="text-sm" style="color:var(--ink-2);"><?= htmlspecialchars($rating['remark'] ?? '') ?></div>
                </td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Comments -->
      <div class="mb-6">
        <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Comments:</label>
        <div class="form-field" style="min-height:80px;"><?= nl2br(htmlspecialchars($evaluation_details['comments'])) ?></div>
      </div>

      <!-- Future Training Needs -->
      <div class="mb-6">
        <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Please list down other training programs he/she might need in the future:</label>
        <div class="form-field" style="min-height:100px;"><?= nl2br(htmlspecialchars($evaluation_details['future_training_needs'])) ?></div>
      </div>

      <!-- Signature Section -->
      <div style="border-top:1px solid var(--line); padding-top:1.5rem;">
        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Rated by:</label>
            <div class="form-field"><?= htmlspecialchars($evaluation_details['rated_by']) ?></div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Signature:</label>
            <div class="signature-preview">
              <?php if (!empty($evaluation_details['signature_date'])): ?>
                <img src="<?= htmlspecialchars($evaluation_details['signature_date']) ?>" alt="Signature" class="signature-image">
              <?php else: ?>
                <span style="color:var(--faint);">No signature</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--ink-2);">Date:</label>
            <div class="form-field num">
              <?= $evaluation_details['created_at'] ? date('M d, Y', strtotime($evaluation_details['created_at'])) : 'Not set' ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Workflow History -->
      <?php if (!empty($workflow_history)): ?>
      <div class="mt-8" style="border-top:1px solid var(--line); padding-top:1.5rem;">
        <h3 class="text-lg font-semibold mb-4" style="color:var(--ink);">Evaluation History</h3>
        <div class="workflow-timeline">
          <?php foreach ($workflow_history as $history): ?>
          <div class="workflow-step">
            <div style="background:var(--surface); border:1px solid var(--line); padding:0.85rem; border-radius:12px;">
              <div class="flex justify-between items-start">
                <div>
                  <p class="font-medium" style="color:var(--ink);">
                    Status changed from
                    <span style="color:var(--accent);"><?= $history['from_status'] ? ucfirst($history['from_status']) : 'None' ?></span>
                    to
                    <span style="color:var(--ok);"><?= ucfirst($history['to_status']) ?></span>
                  </p>
                  <p class="text-sm mt-1" style="color:var(--muted);">By: <?= htmlspecialchars($history['changed_by_name']) ?></p>
                  <?php if (!empty($history['comments'])): ?>
                  <p class="text-sm mt-2" style="color:var(--ink-2);"><strong>Comment:</strong> <?= htmlspecialchars($history['comments']) ?></p>
                  <?php endif; ?>
                </div>
                <span class="text-xs num" style="color:var(--faint);"><?= date('M d, Y h:i A', strtotime($history['created_at'])) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <div class="modal-footer">
      <?php if ($evaluation_details['status'] === 'submitted'): ?>
      <form method="POST" action="" class="inline">
        <input type="hidden" name="evaluation_id" value="<?= $evaluation_details['id'] ?>">
        <input type="hidden" name="user_id" value="<?= $evaluation_details['user_id'] ?>">
        <button type="submit" name="send_to_user" class="btn btn-success" onclick="return confirm('Send this evaluation to <?= htmlspecialchars(explode(' ', $evaluation_details['employee_name'])[0]) ?>?')">
          <i class="ri-send-plane-line"></i> Send to <?= htmlspecialchars(explode(' ', $evaluation_details['employee_name'])[0]) ?>
        </button>
      </form>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost close-modal-btn">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  /* ============================================================
     SHELL HELPERS — sidebar collapse (desktop) + drawer (mobile)
     ============================================================ */
  function toggleRailCollapse() { document.getElementById('app').classList.toggle('rail-collapsed'); }
  function openMobileRail()  { document.getElementById('app').classList.add('rail-open'); }
  function closeMobileRail() { document.getElementById('app').classList.remove('rail-open'); }
  function syncCollapseButton() {
    const btn = document.getElementById('collapseBtn');
    if (!btn) return;
    btn.style.display = window.innerWidth > 900 ? 'inline-flex' : 'none';
    if (window.innerWidth > 900) closeMobileRail();
  }
  window.addEventListener('resize', syncCollapseButton);

  /* ============================================================
     PAGE LOGIC (preserved from original)
     ============================================================ */
  document.addEventListener('DOMContentLoaded', () => {
    syncCollapseButton();

    // Auto-submit functionality for filters
    const autoSubmitElements = document.querySelectorAll('.auto-submit');
    autoSubmitElements.forEach(element => {
      element.addEventListener('change', function() {
        this.closest('form').submit();
      });
    });

    // Modal functionality
    const modal = document.querySelector('.modal-overlay');
    const closeButtons = document.querySelectorAll('.close-modal-btn');

    // Close modal functionality (redirect clears the modal state)
    closeButtons.forEach(button => {
      button.addEventListener('click', function() {
        if (modal) {
          modal.classList.remove('active');
          window.location.href = 'Evaluation_Form.php';
        }
      });
    });

    // Close modal when clicking outside
    if (modal) {
      modal.addEventListener('click', function(e) {
        if (e.target === this) {
          modal.classList.remove('active');
          window.location.href = 'Evaluation_Form.php';
        }
      });
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
        modal.classList.remove('active');
        window.location.href = 'Evaluation_Form.php';
      }
    });
  });

  function printForm() {
    window.print();
  }
</script>
</body>
</html>