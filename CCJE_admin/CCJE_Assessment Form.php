<?php
/* ===================================================================
   CCJE — ASSESSMENT FORM SUBMISSIONS (College of Criminal Justice
   Education only)
   -------------------------------------------------------------------
   2026-09-03 - this page did not exist before today; CCJE was the last
   college dashboard still on the pre-rail design and had never received
   this page at all (every other college got it earlier). Built as a
   CCJE-scoped adaptation of CA_admin/CA_Assessment Form.php - same rail
   sidebar, same modal/table/pagination structure, department scope
   swapped to CCJE, accent color swapped to navy/gold (CA already owns
   green; navy+gold fits a justice-education college and doesn't
   collide with any other college's accent).

   EVERY query below is constrained to department = 'CCJE' — a CCJE
   admin can only ever see or act on CCJE records. Assumed to live in
   the same folder as CCJE.php (hence '../config.php', '../images/...'
   below).
   =================================================================== */

session_start();

// Same auth gate as CCJE.php — keeps this page inside the protected CCJE area.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// ISO 25010 Security audit (2026-09-06): the above check only confirmed
// *a* user was logged in, not that they were THIS college's dean - any
// authenticated account (another dean, or a plain employee) could load
// this page directly by URL. Added the missing role check.
if (($_SESSION['user_role'] ?? '') !== 'admin_ccje') {
    header("Location: ../index.php");
    exit();
}
require_once '../config.php';

// 2026-09-04 - sidebar "Training Demand" badge count, same helper the
// dashboard and dedicated Training Demand page use, so the number matches everywhere.
require_once '../ml_recommendations.php';
$forwardedDemandCount = getForwardedDemandCountForCollege($con, 'CCJE');

// Make sure the DB connection actually exists before using it.
// (Prevents a silent blank/white page if config.php fails to connect.)
if (!isset($con) || !$con) {
    die('Database connection failed. Please check config.php.');
}

$user_id = $_SESSION['user_id'];
$stmt = $con->prepare("SELECT * FROM users WHERE id = ?");
if (!$stmt) {
    die('Database error (auth check): ' . $con->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// ----- Fixed department scope ---------------------------------------
// Hardcoded (not read from $_GET) so it can never be overridden by a
// tampered query string — every query below filters on this constant.
const CCJE_DEPT = 'CCJE';

// ----- Query params (filters) — department is NOT a filter anymore --
$selected_year   = isset($_GET['year'])  ? intval($_GET['year'])  : date('Y');
$selected_month  = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 = all months
$search          = isset($_GET['search'])          ? $con->real_escape_string($_GET['search'])          : '';
$teaching_status = isset($_GET['teaching_status']) ? $con->real_escape_string($_GET['teaching_status']) : '';
$view_id         = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;

// ----- Delete handler (POST) — only allowed for CCJE-owned records ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $delete_id = intval($_POST['delete_id']);
    if ($delete_id > 0) {
        // Ownership check: the assessment must belong to a CCJE user.
        $ccjeDept = CCJE_DEPT;
        $own_check = $con->prepare("
            SELECT a.id FROM assessments a
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ? AND u.department = ?
        ");
        if (!$own_check) {
            die('Database error (ownership check): ' . $con->error);
        }
        $own_check->bind_param("is", $delete_id, $ccjeDept);
        $own_check->execute();
        $owned = $own_check->get_result()->num_rows > 0;
        $own_check->close();

        if ($owned) {
            $con->query("DELETE FROM assessments WHERE id = $delete_id");
        }

        $redirect_url = $_SERVER['PHP_SELF'] . "?year=" . $selected_year;
        if ($selected_month > 0)      { $redirect_url .= "&month=" . $selected_month; }
        if (!empty($search))          { $redirect_url .= "&search=" . urlencode($search); }
        if (!empty($teaching_status)) { $redirect_url .= "&teaching_status=" . urlencode($teaching_status); }
        header("Location: $redirect_url");
        exit();
    }
}

// ----- Distinct years / months / statuses — CCJE only ----------------
$years_result = $con->query("
    SELECT DISTINCT YEAR(a.submission_date) AS year
    FROM assessments a
    JOIN users u ON a.user_id = u.id
    WHERE u.department = '" . CCJE_DEPT . "' AND a.submission_date IS NOT NULL
    ORDER BY year ASC
");
$months_result = $con->query("
    SELECT DISTINCT MONTH(a.submission_date) AS month
    FROM assessments a
    JOIN users u ON a.user_id = u.id
    WHERE u.department = '" . CCJE_DEPT . "'
      AND YEAR(a.submission_date) = $selected_year
      AND a.submission_date IS NOT NULL
    ORDER BY month ASC
");
$statuses_result = $con->query("
    SELECT DISTINCT teaching_status
    FROM users
    WHERE department = '" . CCJE_DEPT . "'
      AND teaching_status IS NOT NULL AND teaching_status != ''
");

if ($years_result === false || $months_result === false || $statuses_result === false) {
    die('Database query failed (filters): ' . $con->error);
}

// ----- Detail lookup for the View modal (server-side fallback) — CCJE only
$view_data = null;
if ($view_id > 0) {
    $view_sql = "SELECT
                    u.*,
                    a.training_history,
                    a.desired_skills,
                    a.comments,
                    a.submission_date,
                    a.id as assessment_id
                FROM users u
                JOIN assessments a ON u.id = a.user_id
                WHERE a.id = $view_id AND u.department = '" . CCJE_DEPT . "'";
    $view_result = $con->query($view_sql);
    if ($view_result && $view_result->num_rows > 0) {
        $view_data = $view_result->fetch_assoc();
    }
}

// ----- Pagination setup ----------------------------------------------
$perPage = 5;
$page    = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// ----- Count total rows (respects active filters, CCJE only) ---------
$count_sql = "SELECT COUNT(*) AS total
              FROM users u
              LEFT JOIN assessments a ON u.id = a.user_id
              WHERE u.department = '" . CCJE_DEPT . "'
                AND YEAR(a.submission_date) = $selected_year";

if ($selected_month > 0) { $count_sql .= " AND MONTH(a.submission_date) = $selected_month"; }
if (!empty($search)) {
    // NOTE: $search is already escaped above — do NOT escape it again here,
    // double-escaping was corrupting searches that contain quotes/apostrophes.
    $count_sql .= " AND (u.name LIKE '%$search%' OR u.email LIKE '%$search%')";
}
if (!empty($teaching_status)) { $count_sql .= " AND u.teaching_status = '$teaching_status'"; }

$count_result = $con->query($count_sql);
if ($count_result === false) {
    die("Database query failed (count): " . $con->error);
}
$total_rows   = ($count_result && $count_result->num_rows > 0) ? $count_result->fetch_assoc()['total'] : 0;
$total_pages  = ceil($total_rows / $perPage);

// ----- Sorting helper: extract surname --------------------------------
function getSurname($name) {
    $parts = explode(' ', $name);
    return end($parts);
}

// ----- Main paginated query (sorted by surname, CCJE only) ------------
$sql = "SELECT
            u.id as user_id,
            u.name,
            u.department,
            u.teaching_status,
            u.email,
            a.training_history,
            a.desired_skills,
            a.comments,
            a.submission_date,
            a.id
        FROM users u
        LEFT JOIN assessments a ON u.id = a.user_id
        WHERE u.department = '" . CCJE_DEPT . "'
          AND YEAR(a.submission_date) = $selected_year";

if ($selected_month > 0)      { $sql .= " AND MONTH(a.submission_date) = $selected_month"; }
if (!empty($search))          { $sql .= " AND (u.name LIKE '%$search%' OR u.email LIKE '%$search%')"; }
if (!empty($teaching_status)) { $sql .= " AND u.teaching_status = '$teaching_status'"; }

$sql .= " ORDER BY SUBSTRING_INDEX(u.name, ' ', -1) ASC, u.name ASC LIMIT $perPage OFFSET $offset";
$result = $con->query($sql);

if ($result === false) {
    die("Database query failed: " . $con->error);
}

// ----- Export query (all CCJE rows, no pagination, same sort) ---------
$export_sql = "SELECT
                  u.id as user_id,
                  u.name,
                  u.department,
                  u.teaching_status,
                  u.email,
                  a.training_history,
                  a.desired_skills,
                  a.comments,
                  a.submission_date,
                  a.id
              FROM users u
              LEFT JOIN assessments a ON u.id = a.user_id
              WHERE u.department = '" . CCJE_DEPT . "'
                AND YEAR(a.submission_date) = $selected_year";

if ($selected_month > 0)      { $export_sql .= " AND MONTH(a.submission_date) = $selected_month"; }
if (!empty($search))          { $export_sql .= " AND (u.name LIKE '%$search%' OR u.email LIKE '%$search%')"; }
if (!empty($teaching_status)) { $export_sql .= " AND u.teaching_status = '$teaching_status'"; }

$export_sql .= " ORDER BY SUBSTRING_INDEX(u.name, ' ', -1) ASC, u.name ASC";
$export_result       = $con->query($export_sql);
if ($export_result === false) {
    die("Database query failed (export): " . $con->error);
}
$all_rows_for_export = $export_result ? $export_result->fetch_all(MYSQLI_ASSOC) : [];

/* ----- Derived stat counts (computed from data already fetched) ----- */
$stat_teaching    = 0;
$stat_nonteaching = 0;
foreach ($all_rows_for_export as $r) {
    if (strtolower(trim($r['teaching_status'] ?? '')) === 'teaching') {
        $stat_teaching++;
    } else {
        $stat_nonteaching++;
    }
}

// Response rate for the period: submissions (this filtered set) vs all
// accepted CCJE faculty/staff, so the 4th stat card carries real signal
// now that "Departments" is meaningless (it's always CCJE).
$ccje_eligible_result = $con->query("SELECT COUNT(*) AS c FROM users WHERE department = '" . CCJE_DEPT . "' AND status = 'accepted'");
$ccje_eligible = ($ccje_eligible_result && $ccje_eligible_result->num_rows > 0) ? (int)$ccje_eligible_result->fetch_assoc()['c'] : 0;
$response_rate = $ccje_eligible > 0 ? round(($total_rows / $ccje_eligible) * 100, 1) : 0;

// Human-readable label for the active period.
$period_label = $selected_month > 0
    ? date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year))
    : (string) $selected_year;

$adminName = $user['name'] ?? 'CCJE Admin';
$initials = strtoupper(substr($adminName, 0, 1));
$parts = preg_split('/\s+/', trim($adminName));
if (count($parts) > 1) { $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CCJE Assessment Forms · LSPU TNA</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <link rel="stylesheet" href="../assets/css/tw-22.css">
<style>
  /* Design system — same tokens/structure as CA.php / admin_page.php's
     rail template, re-themed navy+gold for CCJE (CA already owns green;
     navy/gold reads as "justice/authority" without colliding with any
     other college's accent). NOTE: the CSS variable names below (--green,
     --brown, etc.) are kept identical to the reference template on
     purpose - hundreds of rules throughout this file reference them by
     name, and renaming them file-wide would be far riskier than just
     assigning CCJE's own hex values under the same names. Treat --green
     as "this college's primary accent" and --brown as "this college's
     secondary accent," not literally green/brown. */
  :root {
    --bg:#f5f6f8; --bg-grad:radial-gradient(1100px 600px at 100% -8%, rgba(30,58,95,0.05), transparent 60%);
    --surface:#ffffff; --surface-2:#f8fafc; --surface-3:#f1f3f7;
    --ink:#0f172a; --ink-2:#334155; --muted:#64748b; --faint:#94a3b8;
    --line:#e5e7eb; --line-soft:#eef1f5;
    --accent:#2c5282; --accent-700:#1e3a5f; --accent-soft:#dbeafe; --accent-ink:#1e3a5f;
    --green:#1e3a5f; --green-light:#4a7ba6; --green-soft:#dbeafe;
    --brown:#92702a; --brown-light:#b8902f; --brown-soft:#fef3c7;
    --ok:#059669; --ok-soft:#ecfdf5; --ok-ink:#065f46;
    --warn:#d97706; --warn-soft:#fffbeb; --warn-ink:#92400e;
    --bad:#e11d48; --bad-soft:#fff1f2; --bad-ink:#9f1239;
    --sky:#0284c7; --sky-soft:#f0f9ff;
    --rail:#0a1a2e; --rail-2:#1e3a5f; --rail-line:rgba(148,163,184,0.14);
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
  h1,h2,h3,h4,h5 { font-family:'Plus Jakarta Sans','Inter',sans-serif; letter-spacing:-0.015em; margin:0; }
  .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-feature-settings:"tnum" 1; letter-spacing:-0.02em; }
  a { color:inherit; text-decoration:none; }
  :focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:6px; }
  .eyebrow { font-size:0.68rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--faint); }

  .app { display:grid; grid-template-columns:var(--rail-w) 1fr; min-height:100vh; transition:grid-template-columns 0.28s var(--ease); }
  .app.rail-collapsed { grid-template-columns:var(--rail-w-min) 1fr; }
  .app-main { min-width:0; display:flex; flex-direction:column; max-height:100vh; overflow:hidden; }
  .content-scroll { flex:1 1 auto; overflow-y:auto; overflow-x:hidden; }
  .content { max-width:1640px; margin:0 auto; padding:1.6rem 1.8rem 3rem; }

  .rail { background:linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color:var(--rail-text); display:flex; flex-direction:column; position:sticky; top:0; height:100vh; border-right:1px solid rgba(0,0,0,0.2); z-index: 50; }
  .rail-brand { display:flex; align-items:center; gap:0.75rem; padding:1.0rem 1.15rem; border-bottom:1px solid var(--rail-line); min-height:var(--topbar-h); }
  .rail-logo { width:32px; height:32px; border-radius:9px; background:#fff; padding:3px; flex-shrink:0; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 16px -8px rgba(0,0,0,0.6); }
  .rail-logo-fallback { width:32px; height:32px; border-radius:9px; flex-shrink:0; background:linear-gradient(135deg, var(--green), var(--green-light)); display:flex; align-items:center; justify-content:center; }
  .rail-brand-text { min-width:0; flex:1 1 auto; transition:opacity 0.2s var(--ease); }
  .rail-brand-text h1 { font-size:0.92rem; color:#fff; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .rail-brand-text p  { font-size:0.68rem; color:var(--rail-text-2); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .rail-nav { flex:1 1 auto; overflow-y:auto; padding:1rem 0.7rem; }
  .rail-section-label { font-size:0.64rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--rail-text-2); padding:0 0.85rem; margin:0.4rem 0 0.55rem; }
  .rail-link { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; margin:2px 0; border-radius:11px; color:var(--rail-text); font-size:0.875rem; font-weight:500; border:1px solid transparent; transition:background 0.18s var(--ease), color 0.18s var(--ease); position:relative; white-space:nowrap; }
  .rail-link i:first-child { font-size:1.2rem; flex-shrink:0; width:22px; text-align:center; }
  .rail-link:hover { background:rgba(255,255,255,0.06); color:#fff; }
  .rail-link.active { background:linear-gradient(100deg, rgba(30,58,95,0.35), rgba(30,58,95,0.10)); color:#fff; border-color:rgba(74,123,166,0.4); }
  .rail-link.active::before { content:''; position:absolute; left:-0.7rem; top:50%; transform:translateY(-50%); width:3px; height:22px; border-radius:0 4px 4px 0; background:var(--green); }
  .rail-link .chev { margin-left:auto; opacity:0.5; font-size:1rem; }
  .rail-badge { margin-left:auto; background:var(--bad); color:#fff; font-size:0.68rem; font-weight:700; padding:0.15rem 0.48rem; border-radius:999px; line-height:1.4; flex-shrink:0; }
  .rail-foot { padding:0.7rem; border-top:1px solid var(--rail-line); }
  .rail-signout { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; border-radius:11px; color:#fda4af; font-size:0.875rem; font-weight:500; border:1px solid rgba(244,63,94,0.18); transition:background 0.18s var(--ease); white-space:nowrap; }
  .rail-signout i { font-size:1.2rem; width:22px; text-align:center; }
  .rail-signout:hover { background:rgba(244,63,94,0.16); color:#fecdd3; }
  .app.rail-collapsed .rail-brand-text, .app.rail-collapsed .rail-section-label, .app.rail-collapsed .rail-link span, .app.rail-collapsed .rail-link .chev, .app.rail-collapsed .rail-link .rail-badge, .app.rail-collapsed .rail-signout span { opacity:0; pointer-events:none; width:0; overflow:hidden; }
  .rail-scrim { position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(2px); opacity:0; visibility:hidden; transition:opacity 0.3s var(--ease); z-index:39; }

  .topbar { height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:1rem; padding:0 1.4rem; background:rgba(255,255,255,0.85); backdrop-filter:blur(12px); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:30; }
  .icon-btn { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); background:var(--surface); color:var(--ink-2); font-size:1.2rem; cursor:pointer; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease); flex-shrink:0; }
  .icon-btn:hover { background:var(--surface-2); border-color:#cbd5e1; color:var(--ink); }
  .hamburger { display:none; }
  .topbar-right { margin-left:auto; display:flex; align-items:center; gap:0.6rem; }
  .avatar-chip { display:flex; align-items:center; gap:0.6rem; padding:0.3rem 0.55rem 0.3rem 0.35rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); }
  .avatar { width:34px; height:34px; border-radius:9px; flex-shrink:0; background:linear-gradient(135deg, var(--green), var(--green-light)); color:#fff; font-weight:700; font-size:0.85rem; display:flex; align-items:center; justify-content:center; font-family:'Plus Jakarta Sans',sans-serif; }
  .avatar-meta { line-height:1.15; text-align:left; }
  .avatar-meta .nm { font-size:0.82rem; font-weight:600; color:var(--ink); }
  .avatar-meta .rl { font-size:0.7rem; color:var(--muted); }
  .date-chip { display:inline-flex; align-items:center; gap:0.55rem; padding:0.5rem 0.85rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); }
  .date-chip i { color:var(--green); font-size:1.05rem; }
  .date-chip span { font-size:0.82rem; font-weight:600; color:var(--ink-2); }

  .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
  .page-head h2 { font-size:1.55rem; font-weight:800; color:var(--ink); }
  .page-head p { color:var(--muted); font-size:0.9rem; margin-top:0.25rem; }
  .scope-badge { display:inline-flex; align-items:center; gap:0.4rem; background:var(--green-soft); color:#1e3a5f; padding:0.3rem 0.7rem; border-radius:999px; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; margin-left:0.5rem; vertical-align:middle; }

  .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; }
  .card-body { padding:1.3rem; }
  .stat { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
  .stat:hover { transform:translateY(-3px); box-shadow:var(--shadow-pop); }
  .stat::after { content:''; position:absolute; inset:0 0 auto 0; height:3px; background:var(--stat-accent, var(--accent)); }
  .stat.a-green  { --stat-accent:linear-gradient(90deg, #4a7ba6, #1e3a5f); }
  .stat.a-brown   { --stat-accent:linear-gradient(90deg, #b8902f, #92702a); }
  .stat.a-emerald { --stat-accent:linear-gradient(90deg, #34d399, #059669); }
  .stat.a-amber   { --stat-accent:linear-gradient(90deg, #fbbf24, #d97706); }
  .stat-body { padding:1.25rem 1.3rem; display:flex; align-items:center; justify-content:space-between; }
  .stat-label { font-size:0.8rem; font-weight:500; color:var(--muted); }
  .stat-value { font-size:2rem; font-weight:700; margin-top:0.35rem; line-height:1; }
  .stat-sub { font-size:0.72rem; color:var(--faint); margin-top:0.4rem; }
  .stat-ico { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
  .ico-green  { background:var(--accent-soft); color:var(--accent); }
  .ico-brown   { background:var(--brown-soft);   color:var(--brown); }
  .ico-emerald { background:var(--ok-soft);     color:var(--ok); }
  .ico-amber   { background:var(--warn-soft);   color:var(--warn); }

  .btn { display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600; padding:0.65rem 1.1rem; border-radius:var(--radius-sm); border:1px solid transparent; cursor:pointer; white-space:nowrap; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease); }
  .btn:active { transform:translateY(1px); }
  .btn-sm { padding:0.5rem 0.85rem; font-size:0.82rem; }
  .btn-primary { background:var(--accent); color:#fff; box-shadow:0 8px 18px -10px rgba(44,82,130,0.7); }
  .btn-primary:hover { background:var(--accent-700); }
  .btn-ghost { background:var(--surface); color:var(--ink-2); border-color:var(--line); }
  .btn-ghost:hover { background:var(--surface-2); border-color:#cbd5e1; }
  .btn-success { background:var(--ok); color:#fff; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); }
  .btn-success:hover { background:#047857; }
  .btn-green { background:var(--green); color:#fff; box-shadow:0 8px 18px -10px rgba(30,58,95,0.55); }
  .btn-green:hover { background:#16283f; }
  .btn-brown { background:var(--brown); color:#fff; box-shadow:0 8px 18px -10px rgba(146,112,42,0.55); }
  .btn-brown:hover { background:#78571f; }

  .badge { display:inline-flex; align-items:center; gap:0.3rem; padding:0.32rem 0.7rem; border-radius:999px; font-size:0.7rem; font-weight:600; letter-spacing:0.02em; }
  .badge-teaching { background:var(--ok-soft); color:var(--ok-ink); }
  .badge-nonteaching { background:var(--accent-soft); color:var(--accent-ink); }
  .dept-badge { display:inline-flex; align-items:center; gap:0.3rem; background:var(--green-soft); color:#1e3a5f; padding:0.28rem 0.6rem; border-radius:8px; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; }

  .table-wrap { border:1px solid var(--line); border-radius:var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; background:var(--surface); }
  .table-scroll { overflow-x:auto; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { background:var(--surface-2); text-align:left; font-size:0.7rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--muted); padding:0.85rem 1.15rem; border-bottom:1px solid var(--line); white-space:nowrap; }
  table.data td { padding:0.95rem 1.15rem; border-bottom:1px solid var(--line-soft); font-size:0.875rem; color:var(--ink-2); vertical-align:top; }
  table.data tbody tr { transition:background 0.15s var(--ease); }
  table.data tbody tr:nth-child(even) { background:var(--surface-2); }
  table.data tbody tr:hover { background:var(--accent-soft); }
  table.data tbody tr:last-child td { border-bottom:none; }
  .cell-name { font-weight:600; color:var(--ink); }
  .cell-sub { font-size:0.74rem; color:var(--faint); margin-top:2px; }

  .search { position:relative; flex:1 1 240px; min-width:200px; }
  .search input { width:100%; height:42px; padding:0 2.6rem 0 2.6rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.875rem; color:var(--ink); }
  .search input::placeholder { color:var(--faint); }
  .search input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(44,82,130,0.12); }
  .search > i { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--faint); font-size:1.05rem; pointer-events:none; }
  .search-go { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; border-radius:8px; background:var(--accent); color:#fff; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
  .search-go:hover { background:var(--accent-700); }

  .filterbar { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; }
  .filter { position:relative; }
  .filter-btn { display:inline-flex; align-items:center; gap:0.55rem; height:42px; padding:0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; font-size:0.85rem; color:var(--ink-2); font-weight:500; white-space:nowrap; }
  .filter-btn:hover { border-color:#cbd5e1; background:var(--surface-2); }
  .filter-btn .flt-label { color:var(--faint); font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
  .filter-btn .flt-value { color:var(--ink); font-weight:600; max-width:170px; overflow:hidden; text-overflow:ellipsis; }
  .filter-btn .flt-value.is-set { color:var(--accent); }
  .filter-btn i.caret { margin-left:0.1rem; color:var(--muted); transition:transform 0.18s var(--ease); }
  .filter.open .filter-btn { border-color:var(--accent); }
  .filter.open .filter-btn i.caret { transform:rotate(180deg); }
  .filter-menu { position:absolute; left:0; top:calc(100% + 0.4rem); min-width:200px; max-height:320px; overflow-y:auto; background:var(--surface); border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow-pop); padding:0.4rem; z-index:50; opacity:0; transform:translateY(-6px); pointer-events:none; transition:opacity 0.16s var(--ease), transform 0.16s var(--ease); }
  .filter.open .filter-menu { opacity:1; transform:translateY(0); pointer-events:auto; }
  .filter-menu a { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; padding:0.6rem 0.75rem; border-radius:9px; font-size:0.85rem; color:var(--ink-2); }
  .filter-menu a:hover { background:var(--surface-2); }
  .filter-menu a.sel { background:var(--green-soft); color:#1e3a5f; font-weight:600; }
  .filter-menu a.sel::after { content:'\2713'; font-weight:700; }

  .modal { position:fixed; inset:0; z-index:60; display:none; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,0.55); backdrop-filter:blur(6px); }
  .modal.open { display:flex; animation:fadeIn 0.18s var(--ease); }
  @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
  .modal-box { background:var(--surface); border-radius:20px; width:100%; max-width:960px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 30px 60px -20px rgba(15,23,42,0.45); animation:popIn 0.26s var(--ease); }
  @keyframes popIn { from { opacity:0; transform:translateY(14px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
  .modal-head { display:flex; align-items:center; justify-content:space-between; padding:1.15rem 1.5rem; border-bottom:1px solid var(--line); flex-shrink:0; }
  .modal-head h3 { font-size:1.2rem; font-weight:700; display:flex; align-items:center; gap:0.6rem; }
  .modal-head h3 i { color:var(--accent); }
  .modal-body { padding:1.4rem 1.5rem; overflow-y:auto; flex:1 1 auto; }
  .modal-foot { display:flex; justify-content:flex-end; gap:0.7rem; padding:1rem 1.5rem; border-top:1px solid var(--line); background:var(--surface-2); flex-shrink:0; }
  .modal-x { width:38px; height:38px; border-radius:10px; border:1px solid var(--line); background:var(--surface); color:var(--muted); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; }
  .modal-x:hover { background:var(--bad-soft); color:var(--bad); border-color:#fecdd3; }

  .profile-card { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:1.4rem; box-shadow:var(--shadow-card); }
  .training-item { background:var(--surface-2); border:1px solid var(--line); border-radius:12px; padding:1rem; }

  .toast-stack { position:fixed; top:1.1rem; right:1.1rem; z-index:80; display:flex; flex-direction:column; gap:0.7rem; width:360px; max-width:calc(100vw - 2rem); }
  .toast { display:flex; gap:0.8rem; align-items:flex-start; background:var(--surface); border:1px solid var(--line); border-left:4px solid var(--accent); border-radius:14px; padding:0.9rem 1rem; box-shadow:var(--shadow-pop); animation:toastIn 0.3s var(--ease); }
  .toast.leaving { animation:toastOut 0.3s var(--ease) forwards; }
  @keyframes toastIn  { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:translateX(0); } }
  @keyframes toastOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(20px); } }
  .toast.ok { border-left-color:var(--ok); } .toast.warn { border-left-color:var(--warn); } .toast.err { border-left-color:var(--bad); }
  .toast-ico { font-size:1.3rem; flex-shrink:0; margin-top:1px; }
  .toast.ok .toast-ico { color:var(--ok); } .toast.warn .toast-ico { color:var(--warn); } .toast.err .toast-ico { color:var(--bad); }
  .toast-body { flex:1 1 auto; min-width:0; }
  .toast-title { font-size:0.86rem; font-weight:700; color:var(--ink); }
  .toast-msg { font-size:0.8rem; color:var(--muted); margin-top:2px; word-break:break-word; }
  .toast-close { color:var(--faint); cursor:pointer; font-size:1.1rem; flex-shrink:0; }
  .toast-close:hover { color:var(--ink); }

  .empty { text-align:center; padding:3rem 1rem; color:var(--faint); }
  .empty i { font-size:3.2rem; color:#cbd5e1; }
  .empty h4 { font-size:1.05rem; color:var(--muted); margin-top:0.8rem; font-weight:600; }
  .empty p { font-size:0.85rem; color:var(--faint); margin-top:0.3rem; }

  .pager { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-top:1.2rem; }
  .pager-info { font-size:0.82rem; color:var(--muted); }
  .pager-nav { display:inline-flex; align-items:center; gap:0.3rem; }
  .pg { display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 0.5rem; border:1px solid var(--line); border-radius:10px; background:var(--surface); color:var(--ink-2); font-size:0.85rem; font-weight:600; }
  .pg:hover { background:var(--surface-2); border-color:#cbd5e1; }
  .pg.active { background:var(--green); color:#fff; border-color:var(--green); }
  .pg.disabled { color:var(--faint); background:var(--surface-2); cursor:not-allowed; pointer-events:none; }
  .pg.dots { border:none; background:transparent; pointer-events:none; min-width:24px; }

  ::-webkit-scrollbar { width:9px; height:9px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; border:2px solid transparent; background-clip:content-box; }
  ::-webkit-scrollbar-thumb:hover { background:#94a3b8; background-clip:content-box; }

  .grid-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1.15rem; margin-bottom:1.4rem; }
  @media (max-width:1200px) { .grid-stats { grid-template-columns:repeat(2,1fr); } }
  @media (max-width:900px) {
    .app, .app.rail-collapsed { grid-template-columns:1fr; }
    .rail { position:fixed; top:0; left:0; width:var(--rail-w); height: 100vh; height: 100dvh; transform:translateX(-100%); transition:transform 0.3s var(--ease); }
    .app.rail-open .rail { transform:translateX(0); box-shadow:24px 0 60px -20px rgba(0,0,0,0.5); }
    .app.rail-open .rail-scrim { opacity:1; visibility:visible; }
    .hamburger { display:inline-flex; }
    .content { padding:1.1rem 1.1rem 2.5rem; }
    .avatar-meta, .date-chip { display:none; }
  }
  @media (max-width:560px) { .grid-stats { grid-template-columns:1fr; } }
  @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
</style>
</head>
<body>
<div class="app" id="app">
  <div class="rail-scrim" onclick="closeMobileRail()" aria-hidden="true"></div>

  <!-- ---------- SIDEBAR (matches CCJE.php) ---------- -->
  <aside class="rail" id="rail">
    <div class="rail-brand">
      <div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">
        <img src="../images/lspu-logo.png" alt="LSPU" class="rail-logo"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-government-line text-white text-lg"></i>
        </div>
        <img src="../images/ccje.png" alt="CCJE" class="rail-logo"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-shield-user-line text-white text-lg"></i>
        </div>
      </div>
      <div class="rail-brand-text">
        <h1>CCJE Admin</h1>
        <p>Assessment Form</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="CCJE.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>

      <a href="CCJE_Training_Demand.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Demand</span>
        <?php if ($forwardedDemandCount > 0): ?><span class="rail-badge num"><?= $forwardedDemandCount ?></span><?php endif; ?>
      </a>

      <p class="rail-section-label">Forms</p>
      <a href="CCJE_Assessment Form.php" class="rail-link active">
        <i class="ri-survey-line"></i><span>Assessment Form</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>
      <a href="CCJE_Individual_Development_Plan_Form.php" class="rail-link">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
      </a>
      <a href="ccje_eval.php" class="rail-link">
        <i class="ri-file-list-3-line"></i><span>Evaluation</span>
      </a>
    </nav>

    <div class="rail-foot">
      <a href="?logout=true" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- ---------- MAIN COLUMN ---------- -->
  <div class="app-main">
    <header class="topbar">
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu">
        <i class="ri-menu-line"></i>
      </button>
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn" style="display:none;">
        <i class="ri-side-bar-line"></i>
      </button>

      <div class="topbar-right">
        <div class="date-chip">
          <i class="ri-calendar-2-line"></i>
          <span><?php echo date('F j, Y'); ?></span>
        </div>
        <div class="avatar-chip">
          <span class="avatar"><?= htmlspecialchars($initials) ?></span>
          <span class="avatar-meta">
            <span class="nm"><?= htmlspecialchars($adminName) ?></span>
            <span class="rl">CCJE Admin</span>
          </span>
        </div>
      </div>
    </header>

    <div class="content-scroll">
      <div class="content">

        <div class="page-head">
          <div>
            <p class="eyebrow">Training Needs Assessment</p>
            <h2>CCJE Assessment Form Submissions <span class="scope-badge"><i class="ri-shield-check-line"></i> CCJE only</span></h2>
            <p>Viewing submissions from College of Criminal Justice Education faculty &amp; staff for <span class="num"><?= htmlspecialchars($period_label) ?></span>.</p>
          </div>
          <button onclick="generatePDF()" class="btn btn-green">
            <i class="ri-download-2-line"></i> Export PDF
          </button>
        </div>

        <!-- STAT CARDS -->
        <div class="grid-stats">
          <div class="stat a-green">
            <div class="stat-body">
              <div>
                <p class="stat-label">CCJE Records</p>
                <p class="stat-value num"><?= $total_rows ?></p>
                <p class="stat-sub">for <?= htmlspecialchars($period_label) ?></p>
              </div>
              <div class="stat-ico ico-green"><i class="ri-file-list-3-line"></i></div>
            </div>
          </div>
          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Teaching</p>
                <p class="stat-value num"><?= $stat_teaching ?></p>
                <p class="stat-sub">faculty respondents</p>
              </div>
              <div class="stat-ico ico-emerald"><i class="ri-user-star-line"></i></div>
            </div>
          </div>
          <div class="stat a-brown">
            <div class="stat-body">
              <div>
                <p class="stat-label">Non-Teaching</p>
                <p class="stat-value num"><?= $stat_nonteaching ?></p>
                <p class="stat-sub">staff respondents</p>
              </div>
              <div class="stat-ico ico-brown"><i class="ri-user-line"></i></div>
            </div>
          </div>
          <div class="stat a-amber">
            <div class="stat-body">
              <div>
                <p class="stat-label">Response Rate</p>
                <p class="stat-value num"><?= $response_rate ?>%</p>
                <p class="stat-sub"><?= $total_rows ?> of <?= $ccje_eligible ?> CCJE accepted users</p>
              </div>
              <div class="stat-ico ico-amber"><i class="ri-percent-line"></i></div>
            </div>
          </div>
        </div>

        <!-- FILTER BAR (Year / Month / Status / Search — no Department filter, it's fixed to CCJE) -->
        <div class="card" style="margin-bottom:1.4rem; overflow:visible; position:relative; z-index:20;">
          <div class="card-body" style="padding:1.05rem 1.15rem;">
            <div class="filterbar">

              <!-- Year -->
              <div class="filter" id="fltYear">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltYear')">
                  <span class="flt-label">Year</span>
                  <span class="flt-value <?= $selected_year != 0 ? 'is-set' : '' ?> num"><?= $selected_year == 0 ? 'All' : htmlspecialchars($selected_year) ?></span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="?year=0&month=<?= $selected_month ?>&teaching_status=<?= urlencode($teaching_status) ?>&search=<?= urlencode($search) ?>"
                     class="<?= $selected_year == 0 ? 'sel' : '' ?>">All Years</a>
                  <?php $years_result->data_seek(0); while ($yearRow = $years_result->fetch_assoc()): ?>
                    <a href="?year=<?= urlencode($yearRow['year']) ?>&month=<?= $selected_month ?>&teaching_status=<?= urlencode($teaching_status) ?>&search=<?= urlencode($search) ?>"
                       class="<?= $selected_year == $yearRow['year'] ? 'sel' : '' ?>"><?= htmlspecialchars($yearRow['year']) ?></a>
                  <?php endwhile; ?>
                </div>
              </div>

              <!-- Month -->
              <div class="filter" id="fltMonth">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltMonth')">
                  <span class="flt-label">Month</span>
                  <span class="flt-value <?= $selected_month != 0 ? 'is-set' : '' ?>"><?= $selected_month == 0 ? 'All' : date('F', mktime(0,0,0,$selected_month,1)) ?></span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="?year=<?= $selected_year ?>&month=0&teaching_status=<?= urlencode($teaching_status) ?>&search=<?= urlencode($search) ?>"
                     class="<?= $selected_month == 0 ? 'sel' : '' ?>">All Months</a>
                  <?php
                    $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                    $months_result->data_seek(0);
                    while ($monthRow = $months_result->fetch_assoc()):
                        $monthNum = $monthRow['month']; $monthName = $months[$monthNum] ?? '';
                  ?>
                    <a href="?year=<?= $selected_year ?>&month=<?= $monthNum ?>&teaching_status=<?= urlencode($teaching_status) ?>&search=<?= urlencode($search) ?>"
                       class="<?= $selected_month == $monthNum ? 'sel' : '' ?>"><?= $monthName ?></a>
                  <?php endwhile; ?>
                </div>
              </div>

              <!-- Teaching status -->
              <div class="filter" id="fltStatus">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltStatus')">
                  <span class="flt-label">Status</span>
                  <span class="flt-value <?= !empty($teaching_status) ? 'is-set' : '' ?>"><?= empty($teaching_status) ? 'All' : htmlspecialchars($teaching_status) ?></span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="?year=<?= $selected_year ?>&month=<?= $selected_month ?>&search=<?= urlencode($search) ?>"
                     class="<?= empty($teaching_status) ? 'sel' : '' ?>">All Status</a>
                  <?php $statuses_result->data_seek(0); while ($statusRow = $statuses_result->fetch_assoc()): ?>
                    <a href="?year=<?= $selected_year ?>&month=<?= $selected_month ?>&teaching_status=<?= urlencode($statusRow['teaching_status']) ?>&search=<?= urlencode($search) ?>"
                       class="<?= $teaching_status == $statusRow['teaching_status'] ? 'sel' : '' ?>"><?= htmlspecialchars($statusRow['teaching_status']) ?></a>
                  <?php endwhile; ?>
                </div>
              </div>

              <!-- Search -->
              <div class="search">
                <i class="ri-search-line"></i>
                <input type="search" id="search-input" placeholder="Search name or email…"
                       value="<?= htmlspecialchars($search) ?>" />
                <button type="button" class="search-go" onclick="performSearch()" aria-label="Search">
                  <i class="ri-arrow-right-line"></i>
                </button>
              </div>

            </div>
          </div>
        </div>

        <!-- RESULTS META -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.8rem;">
          <p class="pager-info">
            Page <span class="num"><?= $page ?></span> of <span class="num"><?= max($total_pages,1) ?></span>
            · <span class="num"><?= $total_rows ?></span> CCJE records
          </p>
        </div>

        <!-- DATA TABLE -->
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="data">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Department</th>
                  <th>Status</th>
                  <th>Seminars Attended</th>
                  <th>Desired Training</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                  <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                      <td>
                        <div class="cell-name"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="cell-sub num"><?= $row['submission_date'] ? date('M d, Y', strtotime($row['submission_date'])) : '—' ?></div>
                      </td>
                      <td>
                        <span class="dept-badge"><?= htmlspecialchars($row['department']) ?></span>
                        <div class="cell-sub">College of Criminal Justice Education</div>
                      </td>
                      <td>
                        <?php if (strtolower($row['teaching_status'] ?? '') == 'teaching'): ?>
                          <span class="badge badge-teaching"><i class="ri-user-star-fill"></i> Teaching</span>
                        <?php else: ?>
                          <span class="badge badge-nonteaching"><i class="ri-user-fill"></i> Non-Teaching</span>
                        <?php endif; ?>
                      </td>
                      <td style="max-width:320px;">
                        <?php
                        if (!empty($row['training_history'])) {
                            $seminars = json_decode($row['training_history'], true);
                            if (is_array($seminars)) {
                                echo '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
                                foreach ($seminars as $seminar) {
                                    echo '<div class="training-item" style="padding:0.6rem 0.75rem;">';
                                    echo '<p style="font-weight:600;color:var(--ink);font-size:0.82rem;">' . htmlspecialchars($seminar['training'] ?? '') . '</p>';
                                    echo '<p class="cell-sub" style="margin-top:3px;">' .
                                         htmlspecialchars(format_training_date_range($seminar)) . ' • ' .
                                         (isset($seminar['start_time']) ? htmlspecialchars($seminar['start_time']) : '') .
                                         (isset($seminar['end_time']) ? ' - ' . htmlspecialchars($seminar['end_time']) : '') . ' • ' .
                                         (isset($seminar['duration']) ? htmlspecialchars($seminar['duration']) : '') . ' • ' .
                                         htmlspecialchars($seminar['venue'] ?? '') . '</p>';
                                    echo '</div>';
                                }
                                echo '</div>';
                            } else {
                                echo '<span style="color:var(--faint);">—</span>';
                            }
                        } else {
                            echo '<span style="color:var(--faint);">—</span>';
                        }
                        ?>
                      </td>
                      <td style="max-width:280px;">
                        <div style="white-space:pre-line; color:var(--ink-2);"><?= nl2br(htmlspecialchars($row['desired_skills'] ?? '')) ?></div>
                      </td>
                      <td>
                        <a href="#" class="btn btn-ghost btn-sm view-details-btn" data-id="<?= $row['id'] ?>">
                          <i class="ri-eye-line"></i> View
                        </a>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty">
                        <i class="ri-file-list-3-line"></i>
                        <h4>No CCJE submissions found</h4>
                        <p>
                          for <?= htmlspecialchars($period_label) ?>
                          <?php if (!empty($search) || !empty($teaching_status)): ?>with current filters<?php endif; ?>
                        </p>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
        <div class="pager">
          <div class="pager-info">
            Showing <span class="num"><?= $perPage * ($page - 1) + 1 ?></span>–<span class="num"><?= min($perPage * $page, $total_rows) ?></span>
            of <span class="num"><?= $total_rows ?></span> entries
          </div>
          <nav class="pager-nav" aria-label="Pagination">
            <?php
              $qs = function($p) use ($selected_year, $selected_month, $search, $teaching_status) {
                  return "?year=" . urlencode($selected_year)
                       . "&month=" . $selected_month
                       . "&page=" . $p
                       . "&search=" . urlencode($search)
                       . "&teaching_status=" . urlencode($teaching_status);
              };
            ?>
            <?php if ($page > 1): ?>
              <a href="<?= $qs($page - 1) ?>" class="pg pagination-link"><i class="ri-arrow-left-s-line"></i></a>
            <?php else: ?>
              <span class="pg disabled"><i class="ri-arrow-left-s-line"></i></span>
            <?php endif; ?>

            <?php
            $start_page = max(1, $page - 2);
            $end_page   = min($total_pages, $page + 2);
            if ($start_page > 1) {
                echo '<a href="'.$qs(1).'" class="pg pagination-link">1</a>';
                if ($start_page > 2) { echo '<span class="pg dots">…</span>'; }
            }
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
              <a href="<?= $qs($i) ?>" class="pg <?= $page == $i ? 'active' : '' ?> pagination-link"><?= $i ?></a>
            <?php endfor;
            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) { echo '<span class="pg dots">…</span>'; }
                echo '<a href="'.$qs($total_pages).'" class="pg pagination-link">'.$total_pages.'</a>';
            }
            ?>

            <?php if ($page < $total_pages): ?>
              <a href="<?= $qs($page + 1) ?>" class="pg pagination-link"><i class="ri-arrow-right-s-line"></i></a>
            <?php else: ?>
              <span class="pg disabled"><i class="ri-arrow-right-s-line"></i></span>
            <?php endif; ?>
          </nav>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal" id="view-modal">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="ri-user-3-line"></i> Employee Profile</h3>
      <button type="button" class="modal-x close-modal-btn" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <div id="modal-content"></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost close-modal-btn">Close</button>
      <button type="button" class="btn btn-success" id="modal-print-btn"><i class="ri-printer-line"></i> Print</button>
    </div>
  </div>
</div>

<!-- HIDDEN EXPORT TABLE (CCJE rows only) -->
<table id="full-data-table" style="display:none;">
  <tbody>
    <?php foreach ($all_rows_for_export as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['name']) ?></td>
      <td>College of Criminal Justice Education</td>
      <td><?= htmlspecialchars($row['teaching_status'] ?? '') ?></td>
      <td>
        <?php
          if (!empty($row['training_history'])) {
            $seminars = json_decode($row['training_history'], true);
            if (is_array($seminars)) {
              foreach ($seminars as $seminar) {
                echo "Training: " . htmlspecialchars($seminar['training'] ?? '') . "<br>";
                echo "Date: " . htmlspecialchars(format_training_date_range($seminar)) . "<br>";
                echo "Time: " .
                     (isset($seminar['start_time']) ? htmlspecialchars($seminar['start_time']) : '') .
                     (isset($seminar['end_time']) ? ' - ' . htmlspecialchars($seminar['end_time']) : '') . "<br>";
                echo "Duration: " . (isset($seminar['duration']) ? htmlspecialchars($seminar['duration']) : '') . "<br>";
                echo "Venue: " . htmlspecialchars($seminar['venue'] ?? '') . "<br><br>";
              }
            }
          }
        ?>
      </td>
      <td><?= nl2br(htmlspecialchars($row['desired_skills'] ?? '')) ?></td>
      <td><?= nl2br(htmlspecialchars($row['comments'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="toast-stack" id="toastStack"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script>
const ToastIcons  = { ok:'ri-checkbox-circle-line', warn:'ri-error-warning-line', err:'ri-close-circle-line', info:'ri-information-line' };
const ToastTitles = { ok:'Success', warn:'Heads up', err:'Something went wrong', info:'Notice' };

function showToast(type, title, msg, ttl = 5000) {
  type = (type in ToastIcons) ? type : 'info';
  const stack = document.getElementById('toastStack');
  if (!stack) return;
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `
    <i class="toast-ico ${ToastIcons[type]}"></i>
    <div class="toast-body">
      <p class="toast-title">${title || ToastTitles[type]}</p>
      <p class="toast-msg">${msg || ''}</p>
    </div>
    <i class="toast-close ri-close-line"></i>`;
  el.querySelector('.toast-close').addEventListener('click', () => dismissToast(el));
  stack.appendChild(el);
  if (ttl > 0) setTimeout(() => dismissToast(el), ttl);
}
function dismissToast(el) {
  if (!el || el.classList.contains('leaving')) return;
  el.classList.add('leaving');
  setTimeout(() => el.remove(), 300);
}

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

function toggleFilter(id) {
  const target = document.getElementById(id);
  document.querySelectorAll('.filter.open').forEach(f => { if (f !== target) f.classList.remove('open'); });
  target.classList.toggle('open');
}
document.addEventListener('click', function (e) {
  if (!e.target.closest('.filter')) {
    document.querySelectorAll('.filter.open').forEach(f => f.classList.remove('open'));
  }
});

function openModal(id)  { document.getElementById(id).classList.add('open');  document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    closeModal('view-modal');
    document.querySelectorAll('.filter.open').forEach(f => f.classList.remove('open'));
  }
});

function storeScrollPosition() {
  const sc = document.querySelector('.content-scroll');
  sessionStorage.setItem('scrollPosition', sc ? sc.scrollTop : window.pageYOffset);
}
function restoreScrollPosition() {
  const pos = sessionStorage.getItem('scrollPosition');
  if (pos) {
    const sc = document.querySelector('.content-scroll');
    if (sc) sc.scrollTop = parseInt(pos); else window.scrollTo(0, parseInt(pos));
    sessionStorage.removeItem('scrollPosition');
  }
}

function performSearch() {
  const searchValue = document.getElementById('search-input').value;
  const url = new URL(window.location);
  url.searchParams.set('search', searchValue);
  url.searchParams.set('page', '1');
  storeScrollPosition();
  window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function () {
  syncCollapseButton();
  restoreScrollPosition();

  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('keypress', function (e) {
      if (e.key === 'Enter') performSearch();
    });
  }

  document.querySelectorAll('.pagination-link').forEach(link => {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      storeScrollPosition();
      window.location.href = this.href;
    });
  });

  const viewButtons  = document.querySelectorAll('.view-details-btn');
  const modalContent = document.getElementById('modal-content');
  const closeButtons = document.querySelectorAll('.close-modal-btn');
  const modalPrintBtn = document.getElementById('modal-print-btn');
  const modalEl = document.getElementById('view-modal');

  viewButtons.forEach(button => {
    button.addEventListener('click', function (e) {
      e.preventDefault();
      const assessmentId = this.getAttribute('data-id');

      modalContent.innerHTML = `
        <div style="display:flex;justify-content:center;align-items:center;padding:3rem 0;">
          <div style="width:36px;height:36px;border:3px solid var(--accent-soft);border-top-color:var(--accent);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg);}}</style>`;
      openModal('view-modal');

      // Use the get_assessment_details.php endpoint
      fetch(`get_assessment_details.php?id=${assessmentId}`)
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            const assessment = data.assessment;

      // ISO 25010 Security audit (2026-09-06): stored-XSS fix. Every field
      // rendered below comes from user-controlled input (profile fields,
      // free-text assessment answers) and was being interpolated straight
      // into innerHTML with no escaping - a desired_skills/comments answer
      // containing a script/img-onerror payload would execute in whichever
      // dean's browser opened this modal. Escape on the way into the DOM.
      function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

            modalContent.innerHTML = `
              <div class="profile-card" style="margin-bottom:1.25rem;">
                <div class="flex flex-col md:flex-row gap-6">
                  <div class="md:w-1/3">
                    <div class="flex items-center space-x-4 mb-6">
                      <div style="width:56px;height:56px;border-radius:14px;background:var(--accent-soft);display:flex;align-items:center;justify-content:center;">
                        <i class="ri-user-3-line" style="font-size:1.6rem;color:var(--accent);"></i>
                      </div>
                      <div>
                        <h4 class="text-xl font-bold" style="color:var(--ink);">${escapeHtml(assessment.name || 'N/A')}</h4>
                        <span class="badge ${assessment.teaching_status && assessment.teaching_status.toLowerCase() == 'teaching' ? 'badge-teaching' : 'badge-nonteaching'}" style="margin-top:6px;">
                          ${escapeHtml(assessment.teaching_status || 'N/A')}
                        </span>
                      </div>
                    </div>
                    <div class="space-y-4">
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Educational Attainment</h5><p style="color:var(--ink-2);">${escapeHtml(assessment.educationalAttainment || 'Not specified')}</p></div>
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Specialization</h5><p style="color:var(--ink-2);">${escapeHtml(assessment.specialization || 'Not specified')}</p></div>
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Designation</h5><p style="color:var(--ink-2);">${escapeHtml(assessment.designation || 'Not specified')}</p></div>
                    </div>
                  </div>
                  <div class="md:w-1/3">
                    <div class="space-y-4">
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Department</h5><p style="color:var(--ink-2);">College of Criminal Justice Education</p></div>
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Years in LSPU</h5><p style="color:var(--ink-2);">${escapeHtml(assessment.yearsInLSPU || 'Not specified')}</p></div>
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Type of Employment</h5><p style="color:var(--ink-2);">${escapeHtml(assessment.teaching_status || 'Not specified')}</p></div>
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Submission Date</h5><p class="num" style="color:var(--ink-2);">${assessment.submission_date ? new Date(assessment.submission_date).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' }) : 'Not specified'}</p></div>
                    </div>
                  </div>
                  <div class="md:w-1/3">
                    <div class="space-y-4">
                      <div><h5 class="text-sm font-medium" style="color:var(--muted);">Email</h5><p style="color:var(--ink-2);word-break:break-all;">${escapeHtml(assessment.email || 'Not specified')}</p></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="profile-card" style="margin-bottom:1.25rem;">
                <h4 class="text-lg font-bold" style="color:var(--ink);margin-bottom:1rem;">Training History</h4>
                ${assessment.training_history && assessment.training_history.length > 0 ? `
                  <div class="space-y-4">
                    ${assessment.training_history.map(training => `
                      <div class="training-item">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                          <div><h6 class="text-sm font-medium" style="color:var(--muted);">Training/Seminar</h6><p style="color:var(--ink);font-weight:600;">${escapeHtml(training.training || 'N/A')}</p></div>
                          <div><h6 class="text-sm font-medium" style="color:var(--muted);">Date</h6><p class="num" style="color:var(--ink-2);">${training.date || 'N/A'}</p></div>
                          <div><h6 class="text-sm font-medium" style="color:var(--muted);">Time</h6><p class="num" style="color:var(--ink-2);">${training.start_time || ''}${training.end_time ? ' - ' + training.end_time : ''}</p></div>
                          <div><h6 class="text-sm font-medium" style="color:var(--muted);">Venue</h6><p style="color:var(--ink-2);">${escapeHtml(training.venue || 'N/A')}</p></div>
                        </div>
                      </div>
                    `).join('')}
                  </div>
                ` : `<p style="color:var(--muted);font-style:italic;">No training history recorded.</p>`}
              </div>

              <div class="profile-card" style="margin-bottom:1.25rem;">
                <h4 class="text-lg font-bold" style="color:var(--ink);margin-bottom:1rem;">Desired Training/Seminar</h4>
                <div style="background:var(--surface-2);border:1px solid var(--line);padding:1rem;border-radius:12px;">
                  <p style="color:var(--ink-2);white-space:pre-line;">${escapeHtml(assessment.desired_skills || 'Not specified')}</p>
                </div>
              </div>

              <div class="profile-card">
                <h4 class="text-lg font-bold" style="color:var(--ink);margin-bottom:1rem;">Comments/Suggestions</h4>
                <div style="background:var(--surface-2);border:1px solid var(--line);padding:1rem;border-radius:12px;">
                  <p style="color:var(--ink-2);white-space:pre-line;">${escapeHtml(assessment.comments || 'Not specified')}</p>
                </div>
              </div>`;

            modalPrintBtn.onclick = function () {
              const printForm = document.createElement('form');
              printForm.action = 'Training Needs Assessment Form_pdf.php';
              printForm.method = 'post';
              printForm.target = '_blank';
              const fields = [
                { name:'name', value: assessment.name || '' },
                { name:'educationalAttainment', value: assessment.educationalAttainment || '' },
                { name:'specialization', value: assessment.specialization || '' },
                { name:'designation', value: assessment.designation || '' },
                { name:'department', value: assessment.department || '' },
                { name:'yearsInLSPU', value: assessment.yearsInLSPU || '' },
                { name:'teaching_status', value: assessment.teaching_status || '' },
                { name:'training_history', value: JSON.stringify(assessment.training_history || []) },
                { name:'desired_skills', value: assessment.desired_skills || '' },
                { name:'comments', value: assessment.comments || '' }
              ];
              fields.forEach(field => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = field.name; input.value = field.value;
                printForm.appendChild(input);
              });
              document.body.appendChild(printForm);
              printForm.submit();
              document.body.removeChild(printForm);
            };
          } else {
            modalContent.innerHTML = `
              <div class="empty"><i class="ri-error-warning-line" style="color:var(--bad);"></i>
                <h4>Error loading assessment details</h4>
                <p>${data.message || 'Please try again later.'}</p>
              </div>`;
            showToast('err', 'Load failed', data.message || 'Could not load assessment details.');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          modalContent.innerHTML = `
            <div class="empty"><i class="ri-error-warning-line" style="color:var(--bad);"></i>
              <h4>Error loading assessment details</h4>
              <p>Network error. Please check your connection and try again.</p>
            </div>`;
          showToast('err', 'Connection error', 'Could not load assessment details.');
        });
    });
  });

  closeButtons.forEach(button => button.addEventListener('click', () => closeModal('view-modal')));
  if (modalEl) {
    modalEl.addEventListener('click', function (e) { if (e.target === this) closeModal('view-modal'); });
  }
});

function generatePDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: "landscape" });

  const pageWidth = doc.internal.pageSize.getWidth();
  const title = "SUMMARY OF TRAINING NEEDS ASSESSMENT FORMS — COLLEGE OF CRIMINAL JUSTICE EDUCATION";
  doc.setFontSize(13);
  const titleWidth = doc.getStringUnitWidth(title) * doc.getFontSize() / doc.internal.scaleFactor;
  doc.text(title, (pageWidth - titleWidth) / 2, 15);

  const table = document.querySelector('#full-data-table');
  if (!table) { showToast('err', 'Export failed', 'Full data table not found.'); return; }

  const rows = Array.from(table.querySelectorAll('tbody tr'));
  const data = [];
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length < 6) return;
    const name = cells[0]?.innerText.trim() || '';
    const department = cells[1]?.innerText.trim() || '';
    const employmentType = cells[2]?.innerText.trim() || '';
    const seminarText = (cells[3]?.innerHTML || '')
      .replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]*>/g, '').trim();
    const desiredTraining = cells[4]?.innerText.trim() || '';
    const comments = cells[5]?.innerText.trim() || '';
    data.push([name, department, employmentType, seminarText, desiredTraining, comments]);
  });

  const headers = [[
    'Name', 'Department', 'Type of Employment',
    'Seminar Attended (Date, Time, Duration, Venue)',
    'Desired Training / Seminar', 'Comments / Suggestions'
  ]];

  doc.autoTable({
    head: headers, body: data, startY: 25,
    styles: { fontSize:9, textColor:[0,0,0], halign:'left', valign:'top', lineWidth:0.2, lineColor:[0,0,0] },
    headStyles: { fillColor:[219,234,254], textColor:[0,0,0], fontStyle:'bold', lineWidth:0.2, lineColor:[0,0,0] },
    theme: 'grid'
  });

  doc.save("CCJE_Summary_of_Training_Needs_Assessment_Forms.pdf");
}
</script>

</body>
</html>
