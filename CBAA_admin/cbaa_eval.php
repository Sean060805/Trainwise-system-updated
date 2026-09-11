<?php
/* ===================================================================
   CBAA EVALUATION — Department view for College of Business, Administration and Accountancy
   -------------------------------------------------------------------
   UI designed to match CBAA.php (gold accent, dark slate rail, 
   off-white canvas, JetBrains Mono for data).
   =================================================================== */

session_start();

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// ISO 25010 Security audit (2026-09-06): the above check only confirmed
// *a* user was logged in, not that they were THIS college's dean - any
// authenticated account (another dean, or a plain employee) could load
// this page directly by URL. Added the missing role check.
if (($_SESSION['user_role'] ?? '') !== 'admin_cbaa') {
    header("Location: ../index.php");
    exit();
}

// Database connection - adjusted path (one level up from admin folder)
require_once '../config.php';

// 2026-09-04 - sidebar "Training Demand" badge count, same helper the
// dashboard and dedicated Training Demand page use, so the number matches everywhere.
require_once '../ml_recommendations.php';
$forwardedDemandCount = getForwardedDemandCountForCollege($con, 'CBAA');

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $con->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// Check if we should auto-open modal for specific user
$auto_open_modal = false;
$auto_open_user_id = null;
$auto_open_user_name = null;
$auto_open_user_department = null;

if (isset($_GET['user_id'])) {
    $auto_open_user_id = (int)$_GET['user_id'];
    $auto_open_modal = true;
    
    // Get user details for the modal
    $stmt = $con->prepare("SELECT name, department FROM users WHERE id = ?");
    $stmt->bind_param("i", $auto_open_user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        $auto_open_user_name = $user_data['name'];
        $auto_open_user_department = $user_data['department'];
    }
    $stmt->close();
}

// Initialize variables
$result = null;
$error_message = null;

try {
    // UPDATED query - Only show users with non-null teaching_status
    $sql = "SELECT 
                u.id AS user_id,
                u.name,
                u.department,
                u.teaching_status,
                e.id AS evaluation_id,
                e.status AS evaluation_status,
                e.created_at AS evaluation_created
            FROM users u
            LEFT JOIN evaluations e ON u.id = e.user_id 
            WHERE u.department = 'CBAA' 
            AND u.teaching_status IS NOT NULL 
            AND u.teaching_status != ''
            ORDER BY u.name ASC";
    
    error_log("SQL Query: " . $sql);
    
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $con->error);
    }

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    error_log("Number of rows: " . $result->num_rows);

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "An error occurred while fetching data. Please try again later.";
}

// Handle sending evaluation to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_admin'])) {
    $evaluation_id = (int)$_POST['evaluation_id'];
    
    try {
        // Update evaluation status to submitted
        $update_sql = "UPDATE evaluations SET status = 'submitted', updated_at = NOW() WHERE id = ?";
        $update_stmt = $con->prepare($update_sql);
        $update_stmt->bind_param("i", $evaluation_id);
        $update_stmt->execute();
        
        // Add to workflow history
        $workflow_sql = "INSERT INTO evaluation_workflow (evaluation_id, from_status, to_status, changed_by) 
                         VALUES (?, 'draft', 'submitted', ?)";
        $workflow_stmt = $con->prepare($workflow_sql);
        $workflow_stmt->bind_param("ii", $evaluation_id, $user_id);
        $workflow_stmt->execute();
        
        $_SESSION['success_message'] = "Evaluation sent to admin successfully!";
        header("Location: cbaa_eval.php");
        exit();
        
    } catch (Exception $e) {
        error_log("Send to admin error: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to send evaluation to admin.";
    }
}

// Get stats for sidebar
$total_faculty = $result ? $result->num_rows : 0;
$evaluated_count = 0;
$pending_count = 0;

if ($result) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $status = $row['evaluation_status'] ?? 'pending';
        if ($status !== 'pending' && $status !== null && $status !== '') {
            $evaluated_count++;
        } else {
            $pending_count++;
        }
    }
    $result->data_seek(0);
}

// Get current user info
$adminName = $user['name'] ?? 'CBAA Admin';
$initials = strtoupper(substr($adminName, 0, 1));
$parts = preg_split('/\s+/', trim($adminName));
if (count($parts) > 1) { $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1)); }

$departments = [
    'CA'    => 'College of Agriculture',
    'CBAA'  => 'College of Business, Administration and Accountancy',
    'CAS'   => 'College of Arts and Sciences',
    'CCJE'  => 'College of Criminal Justice Education',
    'CCS'   => 'College of Computer Studies',
    'CFND'  => 'College of Food Nutrition and Dietetics',
    'CHMT'  => 'College of Hospitality and Tourism Management',
    'CIT'   => 'College of Industrial Technology',
    'COE'   => 'College of Engineering',
    'COF'   => 'College of Fisheries',
    'COL'   => 'College of Law',
    'CONAH' => 'College of Nursing and Allied Health',
    'CTE'   => 'College of Teacher Education'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CBAA Evaluation · LSPU TNA</title>

  <!-- FONTS — Plus Jakarta Sans (display) + Inter (body) + JetBrains Mono (data) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <!-- Icon sets -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- TAILWIND (CDN) — palette extended to enterprise tokens with CBAA gold accent -->
  <link rel="stylesheet" href="../assets/css/tw-23.css">

  <style>
    /* ==========================================================
       DESIGN SYSTEM — shared with CBAA.php
       ========================================================== */

    /* 1. TOKENS */
    :root {
      --bg:#f5f6f8;
      --bg-grad:radial-gradient(1100px 600px at 100% -8%, rgba(245,158,11,0.05), transparent 60%);
      --surface:#ffffff; --surface-2:#f8fafc; --surface-3:#f1f3f7;
      --ink:#0f172a; --ink-2:#334155; --muted:#64748b; --faint:#94a3b8;
      --line:#e5e7eb; --line-soft:#eef1f5;
      --accent:#d97706; --accent-700:#b45309; --accent-soft:#fef3c7; --accent-ink:#92400e;
      --gold:#f59e0b; --gold-light:#fbbf24; --gold-soft:#fef3c7;
      --dark:#1f2937; --dark-soft:#374151;
      --ok:#059669; --ok-soft:#ecfdf5; --ok-ink:#065f46;
      --warn:#d97706; --warn-soft:#fffbeb; --warn-ink:#92400e;
      --bad:#e11d48; --bad-soft:#fff1f2; --bad-ink:#9f1239;
      --sky:#0284c7; --sky-soft:#f0f9ff;
      --rail:#1a1a1a; --rail-2:#2d2d2d; --rail-line:rgba(148,163,184,0.14);
      --rail-text:rgba(226,232,240,0.74); --rail-text-2:rgba(148,163,184,0.55);
      --radius:14px; --radius-sm:10px; --radius-lg:18px;
      --rail-w:264px; --rail-w-min:78px; --topbar-h:66px;
      --ease:cubic-bezier(0.16,1,0.3,1);
      --shadow-card:0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -18px rgba(15,23,42,0.22);
      --shadow-pop:0 16px 40px -12px rgba(15,23,42,0.22);
    }

    /* 2. BASE */
    *,*::before,*::after { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    html,body { height:100%; }
    body { margin:0; font-family:'Inter',system-ui,sans-serif; color:var(--ink); background:var(--bg-grad), var(--bg); -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
    h1,h2,h3,h4,h5,h6 { font-family:'Plus Jakarta Sans','Inter',sans-serif; letter-spacing:-0.015em; margin:0; }
    .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-feature-settings:"tnum" 1; letter-spacing:-0.02em; }
    a { color:inherit; text-decoration:none; }
    :focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:6px; }
    .eyebrow { font-size:0.68rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--faint); }

    /* 3. APP SHELL */
    .app { display:grid; grid-template-columns:var(--rail-w) 1fr; min-height:100vh; transition:grid-template-columns 0.28s var(--ease); }
    .app.rail-collapsed { grid-template-columns:var(--rail-w-min) 1fr; }
    .app-main { min-width:0; display:flex; flex-direction:column; max-height:100vh; overflow:hidden; }
    .content-scroll { flex:1 1 auto; overflow-y:auto; overflow-x:hidden; }
    .content { max-width:1640px; margin:0 auto; padding:1.6rem 1.8rem 3rem; }

    /* 4. SIDEBAR — CBAA gold accent version */
    .rail { background:linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color:var(--rail-text); display:flex; flex-direction:column; position:sticky; top:0; height:100vh; border-right:1px solid rgba(0,0,0,0.2); z-index: 50; }
    .rail-brand { display:flex; align-items:center; gap:0.65rem; padding:1.0rem 1.15rem; border-bottom:1px solid var(--rail-line); min-height:var(--topbar-h); }
    .rail-logo { width:38px; height:38px; border-radius:10px; background:#fff; padding:4px; flex-shrink:0; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 16px -8px rgba(0,0,0,0.6); }
    .rail-logo-fallback { width:38px; height:38px; border-radius:10px; flex-shrink:0; background:linear-gradient(135deg, var(--gold), var(--gold-light)); display:flex; align-items:center; justify-content:center; }
    .rail-brand-text { min-width:0; transition:opacity 0.2s var(--ease); }
    .rail-brand-text h1 { font-size:0.92rem; color:#fff; line-height:1.15; white-space:nowrap; }
    .rail-brand-text p  { font-size:0.68rem; color:var(--rail-text-2); margin-top:2px; white-space:nowrap; }
    .rail-nav { flex:1 1 auto; overflow-y:auto; padding:1rem 0.7rem; }
    .rail-section-label { font-size:0.64rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--rail-text-2); padding:0 0.85rem; margin:0.4rem 0 0.55rem; transition:opacity 0.2s var(--ease); }
    .rail-link { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; margin:2px 0; border-radius:11px; color:var(--rail-text); font-size:0.875rem; font-weight:500; border:1px solid transparent; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); position:relative; white-space:nowrap; }
    .rail-link i:first-child { font-size:1.2rem; flex-shrink:0; width:22px; text-align:center; }
    .rail-link:hover { background:rgba(255,255,255,0.06); color:#fff; }
    .rail-link.active { background:linear-gradient(100deg, rgba(245,158,11,0.26), rgba(245,158,11,0.08)); color:#fff; border-color:rgba(251,191,36,0.35); }
    .rail-link.active::before { content:''; position:absolute; left:-0.7rem; top:50%; transform:translateY(-50%); width:3px; height:22px; border-radius:0 4px 4px 0; background:var(--gold); }
    .rail-link .chev { margin-left:auto; opacity:0.5; font-size:1rem; }
  .rail-badge { margin-left:auto; background:var(--bad); color:#fff; font-size:0.68rem; font-weight:700; padding:0.15rem 0.48rem; border-radius:999px; line-height:1.4; flex-shrink:0; }
    .rail-foot { padding:0.7rem; border-top:1px solid var(--rail-line); }
    .rail-signout { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; border-radius:11px; color:#fda4af; font-size:0.875rem; font-weight:500; border:1px solid rgba(244,63,94,0.18); transition:background 0.18s var(--ease); white-space:nowrap; }
    .rail-signout i { font-size:1.2rem; width:22px; text-align:center; }
    .rail-signout:hover { background:rgba(244,63,94,0.16); color:#fecdd3; }
    .app.rail-collapsed .rail-brand-text,
    .app.rail-collapsed .rail-section-label,
    .app.rail-collapsed .rail-link span,
    .app.rail-collapsed .rail-link .chev,
    .app.rail-collapsed .rail-link .rail-badge,
    .app.rail-collapsed .rail-signout span { opacity:0; pointer-events:none; width:0; overflow:hidden; }
    .app.rail-collapsed .rail-link, .app.rail-collapsed .rail-signout { justify-content:center; gap:0; }
    .app.rail-collapsed .rail-brand { justify-content:center; padding-left:0; padding-right:0; }
    .app.rail-collapsed .rail-section-label { height:0; margin:0; padding:0; }
    .rail-scrim { position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(2px); opacity:0; visibility:hidden; transition:opacity 0.3s var(--ease); z-index:39; }

    /* 5. TOPBAR */
    .topbar { height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:1rem; padding:0 1.4rem; background:rgba(255,255,255,0.85); backdrop-filter:blur(12px); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:30; }
    .icon-btn { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); background:var(--surface); color:var(--ink-2); font-size:1.2rem; cursor:pointer; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease); flex-shrink:0; }
    .icon-btn:hover { background:var(--surface-2); border-color:#cbd5e1; color:var(--ink); }
    .hamburger { display:none; }
    .topbar-right { margin-left:auto; display:flex; align-items:center; gap:0.6rem; }
    .avatar-chip { display:flex; align-items:center; gap:0.6rem; padding:0.3rem 0.55rem 0.3rem 0.35rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); cursor:default; }
    .avatar { width:34px; height:34px; border-radius:9px; flex-shrink:0; background:linear-gradient(135deg, var(--gold), var(--gold-light)); color:#fff; font-weight:700; font-size:0.85rem; display:flex; align-items:center; justify-content:center; font-family:'Plus Jakarta Sans',sans-serif; }
    .avatar-meta { line-height:1.15; text-align:left; }
    .avatar-meta .nm { font-size:0.82rem; font-weight:600; color:var(--ink); }
    .avatar-meta .rl { font-size:0.7rem; color:var(--muted); }
    .date-chip { display:inline-flex; align-items:center; gap:0.55rem; padding:0.5rem 0.85rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); }
    .date-chip i { color:var(--gold); font-size:1.05rem; }
    .date-chip span { font-size:0.82rem; font-weight:600; color:var(--ink-2); }

    /* 6. PAGE HEADER */
    .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
    .page-head h2 { font-size:1.55rem; font-weight:800; color:var(--ink); }
    .page-head p { color:var(--muted); font-size:0.9rem; margin-top:0.25rem; }

    /* 7. CARDS + STATS */
    .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease), border-color 0.22s var(--ease); }
    .card-body { padding:1.3rem; }
    .stat { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
    .stat:hover { transform:translateY(-3px); box-shadow:var(--shadow-pop); }
    .stat::after { content:''; position:absolute; inset:0 0 auto 0; height:3px; background:var(--stat-accent, var(--gold)); }
    .stat.a-gold   { --stat-accent:linear-gradient(90deg, var(--gold-light), var(--gold)); }
    .stat.a-indigo  { --stat-accent:linear-gradient(90deg, #818cf8, #4f46e5); }
    .stat.a-emerald { --stat-accent:linear-gradient(90deg, #34d399, #059669); }
    .stat.a-dark    { --stat-accent:linear-gradient(90deg, #4b5563, #1f2937); }
    .stat-body { padding:1.25rem 1.3rem; display:flex; align-items:center; justify-content:space-between; }
    .stat-label { font-size:0.8rem; font-weight:500; color:var(--muted); }
    .stat-value { font-size:2rem; font-weight:700; margin-top:0.35rem; line-height:1; }
    .stat-sub { font-size:0.72rem; color:var(--faint); margin-top:0.4rem; }
    .stat-ico { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
    .ico-gold   { background:var(--gold-soft); color:#92400e; }
    .ico-indigo  { background:var(--accent-soft); color:var(--accent); }
    .ico-emerald { background:var(--ok-soft); color:var(--ok); }
    .ico-dark    { background:#f3f4f6; color:#1f2937; }

    /* 8. BUTTONS */
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600; padding:0.65rem 1.1rem; border-radius:var(--radius-sm); border:1px solid transparent; cursor:pointer; white-space:nowrap; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease); }
    .btn:active { transform:translateY(1px); }
    .btn-sm { padding:0.5rem 0.85rem; font-size:0.82rem; }
    .btn-primary { background:var(--accent); color:#fff; box-shadow:0 8px 18px -10px rgba(217,119,6,0.7); }
    .btn-primary:hover { background:var(--accent-700); }
    .btn-ghost { background:var(--surface); color:var(--ink-2); border-color:var(--line); }
    .btn-ghost:hover { background:var(--surface-2); border-color:#cbd5e1; }
    .btn-gold { background:var(--gold); color:#fff; box-shadow:0 8px 18px -10px rgba(245,158,11,0.6); }
    .btn-gold:hover { background:#d97706; }
    .btn-success { background:var(--ok); color:#fff; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); }
    .btn-success:hover { background:#047857; }
    .btn-danger { background:var(--bad); color:#fff; box-shadow:0 8px 18px -10px rgba(225,29,72,0.55); }
    .btn-danger:hover { background:#be123c; }
    .evaluate-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--gold); color:#fff; padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.82rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(245,158,11,0.6); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
    .evaluate-btn:hover { background:#d97706; }
    .evaluate-btn:active { transform:translateY(1px); }
    .evaluate-btn:disabled { opacity:0.6; cursor:not-allowed; }

    /* 9. BADGES */
    .badge { display:inline-flex; align-items:center; gap:0.3rem; padding:0.32rem 0.7rem; border-radius:999px; font-size:0.7rem; font-weight:600; letter-spacing:0.02em; }
    .badge-pending  { background:var(--warn-soft); color:var(--warn-ink); }
    .badge-draft    { background:#f3f4f6; color:#374151; }
    .badge-submitted { background:#fef3c7; color:#92400e; }
    .badge-approved { background:var(--ok-soft); color:var(--ok-ink); }
    .badge-rejected { background:var(--bad-soft); color:var(--bad-ink); }
    .badge-department { background:var(--gold-soft); color:#92400e; padding:0.32rem 0.7rem; border-radius:999px; font-size:0.7rem; font-weight:700; letter-spacing:0.02em; }
    .badge-teaching { background:#e0f2fe; color:#0369a1; }
    .badge-non-teaching { background:#fef3c7; color:#92400e; }

    /* 10. MAIN TABLE */
    .table-wrap { border:1px solid var(--line); border-radius:var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; background:var(--surface); }
    .table-scroll { overflow-x:auto; }
    table.data { width:100%; border-collapse:collapse; }
    table.data th { background:var(--surface-2); text-align:left; font-size:0.7rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--muted); padding:0.85rem 1.15rem; border-bottom:1px solid var(--line); white-space:nowrap; }
    table.data td { padding:0.95rem 1.15rem; border-bottom:1px solid var(--line-soft); font-size:0.875rem; color:var(--ink-2); vertical-align:middle; }
    table.data tbody tr { transition:background 0.15s var(--ease); }
    table.data tbody tr:nth-child(even) { background:var(--surface-2); }
    table.data tbody tr:hover { background:var(--gold-soft); }
    table.data tbody tr:last-child td { border-bottom:none; }
    .cell-name { font-weight:600; color:var(--ink); }
    .emp-avatar { width:38px; height:38px; border-radius:10px; background:var(--gold-soft); color:#92400e; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }

    /* 11. SEARCH + FILTER BAR */
    .search { position:relative; flex:1 1 240px; min-width:200px; }
    .search input { width:100%; height:42px; padding:0 2.6rem 0 2.6rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.875rem; color:var(--ink); transition:border-color 0.18s var(--ease), box-shadow 0.18s var(--ease); }
    .search input::placeholder { color:var(--faint); }
    .search input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 4px rgba(245,158,11,0.12); }
    .search > i { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--faint); font-size:1.05rem; pointer-events:none; }
    .search-go { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; border-radius:8px; background:var(--gold); color:#fff; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background 0.18s var(--ease); }
    .search-go:hover { background:#d97706; }
    .filterbar { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; }
    .filter { position:relative; }
    .filter-btn { display:inline-flex; align-items:center; gap:0.55rem; height:42px; padding:0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; font-size:0.85rem; color:var(--ink-2); font-weight:500; transition:border-color 0.18s var(--ease), background 0.18s var(--ease); white-space:nowrap; }
    .filter-btn:hover { border-color:#cbd5e1; background:var(--surface-2); }
    .filter-btn .flt-label { color:var(--faint); font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
    .filter-btn .flt-value { color:var(--ink); font-weight:600; max-width:200px; overflow:hidden; text-overflow:ellipsis; }
    .filter-btn i.caret { color:var(--muted); transition:transform 0.18s var(--ease); }
    .filter.open .filter-btn { border-color:var(--gold); }
    .filter.open .filter-btn i.caret { transform:rotate(180deg); }
    .filter-menu { position:absolute; left:0; top:calc(100% + 0.4rem); min-width:200px; max-height:320px; overflow-y:auto; background:var(--surface); border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow-pop); padding:0.4rem; z-index:50; opacity:0; transform:translateY(-6px); pointer-events:none; transition:opacity 0.16s var(--ease), transform 0.16s var(--ease); }
    .filter.open .filter-menu { opacity:1; transform:translateY(0); pointer-events:auto; }
    .filter-menu a { display:flex; align-items:center; padding:0.6rem 0.75rem; border-radius:9px; font-size:0.85rem; color:var(--ink-2); transition:background 0.14s var(--ease); }
    .filter-menu a:hover { background:var(--surface-2); }

    /* 12. EMPTY STATE */
    .empty { text-align:center; padding:3rem 1rem; color:var(--faint); }
    .empty i { font-size:3.2rem; color:#cbd5e1; }
    .empty h4 { font-size:1.05rem; color:var(--muted); margin-top:0.8rem; font-weight:600; }
    .empty p { font-size:0.85rem; color:var(--faint); margin-top:0.3rem; }

    /* 13. MODAL */
    .modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); backdrop-filter:blur(6px); z-index:1000; align-items:center; justify-content:center; padding:1rem; }
    .modal.open { display:flex; animation:fadeIn 0.18s var(--ease); }
    @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
    .modal-box { background:var(--surface); border-radius:20px; width:100%; max-width:1000px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 30px 60px -20px rgba(15,23,42,0.45); animation:popIn 0.26s var(--ease); }
    @keyframes popIn { from { opacity:0; transform:translateY(14px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:1.15rem 1.5rem; border-bottom:1px solid var(--line); flex-shrink:0; }
    .modal-head h3 { font-size:1.2rem; font-weight:700; display:flex; align-items:center; gap:0.6rem; }
    .modal-head h3 i { color:var(--gold); }
    .modal-body { padding:1.4rem 1.5rem; overflow-y:auto; flex:1 1 auto; }
    .modal-foot { display:flex; justify-content:flex-end; gap:0.7rem; padding:1rem 1.5rem; border-top:1px solid var(--line); background:var(--surface-2); flex-shrink:0; }
    .modal-x { width:38px; height:38px; border-radius:10px; border:1px solid var(--line); background:var(--surface); color:var(--muted); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); }
    .modal-x:hover { background:var(--bad-soft); color:var(--bad); border-color:#fecdd3; }
    .modal-iframe { width:100%; height:70vh; border:none; border-radius:12px; }

    /* 14. TOAST */
    .toast-message { position:fixed; top:1.1rem; right:1.1rem; z-index:2000; padding:0.9rem 1.1rem; border-radius:12px; color:#fff; font-weight:600; font-size:0.86rem; box-shadow:var(--shadow-pop); opacity:0; transform:translateY(-10px); transition:all 0.25s var(--ease); }
    .toast-message.show { opacity:1; transform:translateY(0); }
    .toast-success { background:linear-gradient(135deg, #10b981, #059669); }
    .toast-error { background:linear-gradient(135deg, #f43f5e, #e11d48); }

    /* 15. SIDEBAR STATS */
    .rail-stats { padding:0.75rem 0.85rem; background:rgba(255,255,255,0.06); border-radius:11px; border:1px solid var(--rail-line); margin-top:0.5rem; }
    .rail-stats-item { display:flex; justify-content:space-between; padding:0.3rem 0; font-size:0.78rem; color:var(--rail-text); border-bottom:1px solid var(--rail-line); }
    .rail-stats-item:last-child { border-bottom:none; }
    .rail-stats-item .num { color:#fff; font-weight:600; }

    /* SCROLLBAR */
    ::-webkit-scrollbar { width:9px; height:9px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; border:2px solid transparent; background-clip:content-box; }
    ::-webkit-scrollbar-thumb:hover { background:#94a3b8; background-clip:content-box; }

    /* GRID + RESPONSIVE */
    .grid-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1.15rem; margin-bottom:1.4rem; }
    @media (max-width:1000px) { .grid-stats { grid-template-columns:1fr; } }
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

  <!-- ---------- SIDEBAR (CBAA Gold Accent) ---------- -->
  <aside class="rail" id="rail">
    <div class="rail-brand">
      <div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">
        <img src="../images/lspu-logo.png" alt="LSPU" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-government-line text-white text-lg"></i>
        </div>
        <img src="../images/cbaa-logo.png" alt="CBAA" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-business-line text-white text-lg"></i>
        </div>
      </div>
      <div class="rail-brand-text">
        <h1>CBAA Admin</h1>
        <p>Evaluation</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="CBAA.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>
      <a href="CBAA_Training_Demand.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Demand</span>
        <?php if ($forwardedDemandCount > 0): ?><span class="rail-badge num"><?= $forwardedDemandCount ?></span><?php endif; ?>
      </a>
      <p class="rail-section-label">Forms</p>
      <a href="CBAA_Assessment Form.php" class="rail-link">
        <i class="ri-survey-line"></i><span>Assessment Form</span>
      </a>
      <a href="CBAA_Individual_Development_Plan_Form.php" class="rail-link">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
      </a>
      <a href="cbaa_eval.php" class="rail-link active">
        <i class="ri-file-list-3-line"></i><span>Evaluation</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>
    </nav>

    <!-- Quick Stats in Sidebar -->
    <div style="padding:0 0.7rem 0.7rem;">
      <div class="rail-stats">
        <div class="rail-stats-item"><span>Total Faculty</span><span class="num"><?= $total_faculty ?></span></div>
        <div class="rail-stats-item"><span>Evaluated</span><span class="num" style="color:#34d399;"><?= $evaluated_count ?></span></div>
        <div class="rail-stats-item"><span>Pending</span><span class="num" style="color:#fbbf24;"><?= $pending_count ?></span></div>
      </div>
    </div>

    <div class="rail-foot">
      <a href="?logout=true" class="rail-signout">
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
          <span class="avatar"><?= htmlspecialchars($initials) ?></span>
          <span class="avatar-meta"><span class="nm"><?= htmlspecialchars($adminName) ?></span> <span class="rl">CBAA Admin</span></span>
        </div>
      </div>
    </header>

    <!-- SCROLLABLE CONTENT -->
    <div class="content-scroll">
      <div class="content">

        <!-- PAGE HEADER -->
        <div class="page-head">
          <div>
            <p class="eyebrow">Training Program Impact Assessment</p>
            <h2>CBAA Faculty Evaluations</h2>
            <p>View and manage CBAA faculty training evaluations — <span class="num"><?= $total_faculty ?></span> faculty members</p>
          </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid-stats">
          <div class="stat a-indigo">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Faculty</p>
                <p class="stat-value num"><?= $total_faculty ?></p>
                <p class="stat-sub">with teaching status</p>
              </div>
              <div class="stat-ico ico-indigo"><i class="ri-team-line"></i></div>
            </div>
          </div>
          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Evaluated</p>
                <p class="stat-value num" style="color:var(--ok);"><?= $evaluated_count ?></p>
                <p class="stat-sub"><?= $total_faculty > 0 ? round(($evaluated_count / $total_faculty) * 100, 1) : 0 ?>% of faculty</p>
              </div>
              <div class="stat-ico ico-emerald"><i class="ri-check-double-line"></i></div>
            </div>
          </div>
          <div class="stat a-dark">
            <div class="stat-body">
              <div>
                <p class="stat-label">Pending</p>
                <p class="stat-value num" style="color:var(--warn);"><?= $pending_count ?></p>
                <p class="stat-sub">awaiting evaluation</p>
              </div>
              <div class="stat-ico ico-dark"><i class="ri-time-line"></i></div>
            </div>
          </div>
        </div>

        <!-- FILTER BAR -->
        <div class="card" style="margin-bottom:1.4rem; overflow:visible; position:relative; z-index:20;">
          <div class="card-body" style="padding:1.05rem 1.15rem;">
            <div class="filterbar">

              <!-- Faculty Type Filter -->
              <div class="filter" id="fltType">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltType')">
                  <span class="flt-label">Type</span>
                  <span class="flt-value" id="type-selected">All</span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="#" data-type="all">All Faculty</a>
                  <a href="#" data-type="teaching">Teaching</a>
                  <a href="#" data-type="non-teaching">Non-Teaching</a>
                </div>
              </div>

              <!-- Status Filter -->
              <div class="filter" id="fltStatus">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltStatus')">
                  <span class="flt-label">Status</span>
                  <span class="flt-value" id="status-selected">All</span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="#" data-status="all">All Status</a>
                  <a href="#" data-status="pending">Pending</a>
                  <a href="#" data-status="draft">Draft</a>
                  <a href="#" data-status="submitted">Submitted</a>
                  <a href="#" data-status="approved">Approved</a>
                  <a href="#" data-status="rejected">Rejected</a>
                </div>
              </div>

              <!-- Search -->
              <div class="search">
                <i class="ri-search-line"></i>
                <input type="text" id="search-input" placeholder="Search by name…" value="" />
                <button type="button" class="search-go" onclick="performSearch()" aria-label="Search"><i class="ri-arrow-right-line"></i></button>
              </div>

            </div>
          </div>
        </div>

        <!-- RESULTS META -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.8rem;">
          <p style="font-size:0.82rem; color:var(--muted);">
            Showing <span class="num" id="result-start"><?= $total_faculty > 0 ? 1 : 0 ?></span>–<span class="num" id="result-end"><?= $total_faculty ?></span>
            of <span class="num" id="result-total"><?= $total_faculty ?></span> faculty members
          </p>
        </div>

        <!-- MAIN TABLE -->
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="data">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Department</th>
                  <th>Employment Type</th>
                  <th>Evaluation Status</th>
                  <th>Last Evaluation</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody id="evaluation-table-body">
                <?php if ($result && $result->num_rows > 0): ?>
                  <?php 
                  $row_index = 0;
                  while ($row = $result->fetch_assoc()): 
                    $row_index++;
                    $status = $row['evaluation_status'] ?? 'pending';
                    $type = $row['teaching_status'] === 'Teaching' ? 'teaching' : 'non-teaching';
                    $department = $row['department'] ?? 'CBAA';
                    $department_name = $departments[$department] ?? $department;
                    $evaluation_date = $row['evaluation_created'] ? date('M j, Y', strtotime($row['evaluation_created'])) : 'Never';
                    $has_teaching_status = !empty($row['teaching_status']) && $row['teaching_status'] !== 'NULL';
                    
                    $status_config = [
                        'pending'   => ['class' => 'badge-pending', 'label' => 'Pending'],
                        'draft'     => ['class' => 'badge-draft', 'label' => 'Draft'],
                        'submitted' => ['class' => 'badge-submitted', 'label' => 'Submitted'],
                        'approved'  => ['class' => 'badge-approved', 'label' => 'Approved'],
                        'rejected'  => ['class' => 'badge-rejected', 'label' => 'Rejected']
                    ];
                    $status_info = $status_config[$status] ?? $status_config['pending'];
                  ?>
                    <tr class="employee-item" 
                        data-name="<?= htmlspecialchars(strtolower($row['name'])) ?>"
                        data-type="<?= $type ?>"
                        data-status="<?= $status ?>"
                        data-user-id="<?= (int)$row['user_id'] ?>">
                      <td>
                        <div style="display:flex; align-items:center; gap:0.7rem;">
                          <div class="emp-avatar"><i class="ri-user-line"></i></div>
                          <div class="cell-name"><?= htmlspecialchars($row['name']) ?></div>
                        </div>
                      </td>
                      <td><span class="badge-department"><?= htmlspecialchars($department_name) ?></span></td>
                      <td>
                        <?php if ($has_teaching_status): ?>
                          <span class="badge <?= $type === 'teaching' ? 'badge-teaching' : 'badge-non-teaching' ?>">
                            <?= htmlspecialchars($row['teaching_status']) ?>
                          </span>
                        <?php else: ?>
                          <span class="badge badge-rejected">Not Set</span>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge <?= $status_info['class'] ?>"><?= $status_info['label'] ?></span></td>
                      <td style="color:var(--muted); font-size:0.85rem;"><?= $evaluation_date ?></td>
                      <td>
                        <div style="display:flex; justify-content:flex-end; gap:0.5rem; flex-wrap:wrap;">
                          <?php if ($has_teaching_status && ($status === 'pending' || $status === 'rejected' || $status === 'draft')): ?>
                            <button type="button" class="evaluate-btn evaluate-btn-action"
                                    data-name="<?= htmlspecialchars($row['name']) ?>"
                                    data-evaluation-id="<?= htmlspecialchars($row['evaluation_id'] ?? '') ?>"
                                    data-user-id="<?= (int)$row['user_id'] ?>"
                                    data-department="<?= htmlspecialchars($department) ?>">
                              <i class="ri-star-line"></i>
                              <?= $status === 'draft' ? 'Continue' : 'Evaluate' ?>
                            </button>
                          <?php elseif (!$has_teaching_status): ?>
                            <span style="font-size:0.72rem;color:var(--bad);font-weight:500;">Set Teaching Status</span>
                          <?php endif; ?>
                          
                          <?php if ($has_teaching_status && $status === 'draft'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Send this evaluation to admin for review?')">
                              <input type="hidden" name="evaluation_id" value="<?= htmlspecialchars($row['evaluation_id']) ?>">
                              <button type="submit" name="send_to_admin" class="btn btn-success btn-sm">
                                <i class="ri-send-plane-line"></i> Send to Admin
                              </button>
                            </form>
                          <?php elseif ($has_teaching_status && $status === 'submitted'): ?>
                            <span style="font-size:0.75rem;color:var(--muted);font-weight:500;">Pending Review</span>
                          <?php elseif ($has_teaching_status && $status === 'approved'): ?>
                            <span style="font-size:0.75rem;color:var(--ok);font-weight:600;"><i class="ri-checkbox-circle-line"></i> Completed</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty">
                        <i class="ri-user-search-line"></i>
                        <h4><?= $error_message ? 'Database Error' : 'No CBAA Faculty Found' ?></h4>
                        <p><?= $error_message ? 'Please check your database connection.' : 'No CBAA faculty members with teaching status found.' ?></p>
                        <?php if (!$error_message): ?>
                        <p style="font-size:0.75rem;color:var(--faint);margin-top:0.5rem;">
                          Users need to have teaching status set to appear in this list.
                        </p>
                        <?php endif; ?>
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
     EVALUATION MODAL
     ============================================================= -->
<div class="modal" id="evaluation-modal">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="ri-file-list-3-line"></i> Training Program Impact Assessment</h3>
      <button type="button" class="modal-x" id="modal-close-btn"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <iframe id="evaluation-iframe" class="modal-iframe" src="about:blank"></iframe>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" id="modal-close-btn-footer">Close</button>
      <button type="button" class="btn btn-gold" id="modal-print-btn"><i class="ri-printer-line"></i> Print</button>
    </div>
  </div>
</div>

<!-- =============================================================
     TOAST CONTAINER
     ============================================================= -->
<div id="toast-container" style="position:fixed;top:1.1rem;right:1.1rem;z-index:2000;display:flex;flex-direction:column;gap:0.6rem;max-width:420px;width:calc(100% - 2rem);"></div>

<script>
/* ============================================================
   SHELL HELPERS
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
   TOAST
   ============================================================ */
function showToast(message, type = 'success') {
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = `toast-message show ${type === 'success' ? 'toast-success' : 'toast-error'}`;
  toast.textContent = message;
  container.appendChild(toast);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

/* ============================================================
   FILTER DROPDOWNS
   ============================================================ */
function toggleFilter(id) {
  const target = document.getElementById(id);
  document.querySelectorAll('.filter.open').forEach(f => { if (f !== target) f.classList.remove('open'); });
  target.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.filter')) {
    document.querySelectorAll('.filter.open').forEach(f => f.classList.remove('open'));
  }
});

/* ============================================================
   FILTER + SEARCH LOGIC
   ============================================================ */
let currentType = 'all';
let currentStatus = 'all';

document.querySelectorAll('#fltType .filter-menu a').forEach(link => {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    currentType = this.getAttribute('data-type');
    document.getElementById('type-selected').textContent = this.textContent;
    document.getElementById('fltType').classList.remove('open');
    filterRows();
  });
});

document.querySelectorAll('#fltStatus .filter-menu a').forEach(link => {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    currentStatus = this.getAttribute('data-status');
    document.getElementById('status-selected').textContent = this.textContent;
    document.getElementById('fltStatus').classList.remove('open');
    filterRows();
  });
});

function performSearch() { filterRows(); }
window.performSearch = performSearch;

document.getElementById('search-input').addEventListener('keypress', function(e) {
  if (e.key === 'Enter') filterRows();
});

function filterRows() {
  const searchTerm = document.getElementById('search-input').value.toLowerCase().trim();
  const rows = document.querySelectorAll('#evaluation-table-body .employee-item');
  let visibleCount = 0;

  rows.forEach(row => {
    const name = row.getAttribute('data-name') || '';
    const type = row.getAttribute('data-type') || '';
    const status = row.getAttribute('data-status') || '';

    const matchType = currentType === 'all' || type === currentType;
    const matchStatus = currentStatus === 'all' || status === currentStatus;
    const matchSearch = !searchTerm || name.includes(searchTerm);

    const visible = matchType && matchStatus && matchSearch;
    row.style.display = visible ? '' : 'none';
    if (visible) visibleCount++;
  });

  // Update result counts
  const total = rows.length;
  document.getElementById('result-start').textContent = visibleCount > 0 ? 1 : 0;
  document.getElementById('result-end').textContent = visibleCount;
  document.getElementById('result-total').textContent = visibleCount;
}

/* ============================================================
   MODAL
   ============================================================ */
const modal = document.getElementById('evaluation-modal');
const modalIframe = document.getElementById('evaluation-iframe');
const closeBtns = document.querySelectorAll('#modal-close-btn, #modal-close-btn-footer');

function openEvaluationModal(url) {
  modalIframe.src = url;
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeEvaluationModal() {
  modal.classList.remove('open');
  document.body.style.overflow = '';
  setTimeout(() => { modalIframe.src = 'about:blank'; }, 300);
}

closeBtns.forEach(btn => {
  btn.addEventListener('click', closeEvaluationModal);
});

modal.addEventListener('click', function(e) {
  if (e.target === modal) closeEvaluationModal();
});

// Print button
document.getElementById('modal-print-btn').addEventListener('click', function() {
  try { modalIframe.contentWindow.print(); } catch(e) { showToast('Unable to print. Please try again.', 'error'); }
});

// Evaluate buttons
document.querySelectorAll('.evaluate-btn-action').forEach(btn => {
  btn.addEventListener('click', function() {
    const name = this.getAttribute('data-name');
    const evaluationId = this.getAttribute('data-evaluation-id');
    const userId = this.getAttribute('data-user-id');
    const department = this.getAttribute('data-department');
    
    const url = `../training_program_impact_assessment_form.php?name=${encodeURIComponent(name)}&evaluation_id=${encodeURIComponent(evaluationId)}&user_id=${encodeURIComponent(userId)}&department=${encodeURIComponent(department)}`;
    openEvaluationModal(url);
  });
});

// Auto-open modal if user_id is provided
<?php if ($auto_open_modal && $auto_open_user_id): ?>
document.addEventListener('DOMContentLoaded', function() {
  const url = `../training_program_impact_assessment_form.php?name=<?= urlencode($auto_open_user_name) ?>&user_id=<?= $auto_open_user_id ?>&department=<?= urlencode($auto_open_user_department) ?>&auto_open=1`;
  openEvaluationModal(url);
  // Scrub ?user_id=... from the address bar now that it's been
  // honored once, so a later reload doesn't re-trigger this same
  // auto-open (see CLAUDE.md, 2026-09-03 fix).
  history.replaceState(null, '', window.location.pathname);
});
<?php endif; ?>

// Handle messages from iframe
window.addEventListener('message', function(e) {
  if (e.data === 'closeModal') {
    closeEvaluationModal();
    window.location.reload();
  }
});

/* ============================================================
   FLASH MESSAGES
   ============================================================ */
<?php if (isset($_SESSION['success_message'])): ?>
  showToast('<?= addslashes($_SESSION['success_message']) ?>', 'success');
  <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
  showToast('<?= addslashes($_SESSION['error_message']) ?>', 'error');
  <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {
  syncCollapseButton();
  filterRows();
});
</script>
</body>
</html>