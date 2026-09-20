<?php
/* =============================================================================
   LSPU — MAIN ADMIN DASHBOARD
   Training Needs Assessment (TNA) System
   -----------------------------------------------------------------------------
   This file is the entry point for the *main* administrator (role = 'admin').
   It handles:
     • Session + status + role gating
     • Deadline management (create / set-active / open-close submissions)
     • User management (accept / decline / delete)
     • Email + in-app notifications (PHPMailer)
     • Dashboard statistics + per-department submission charting
   Only the presentation layer was redesigned — all backend logic and queries
   are functionally identical to the original.
   ============================================================================= */

session_start();
require 'config.php';

/* -----------------------------------------------------------------------------
   ACCESS CONTROL
   ----------------------------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
$checkStatus = $con->prepare("SELECT status FROM users WHERE id = ?");
$checkStatus->bind_param("i", $_SESSION['user_id']);
$checkStatus->execute();
$userData = $checkStatus->get_result()->fetch_assoc();
$checkStatus->close();
if (!$userData || $userData['status'] !== 'accepted') {
    session_destroy();
    header("Location: index.php");
    exit();
}
$userRole = $_SESSION['user_role'] ?? '';
if ($userRole !== 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'ml_recommendations.php';
ensureTrainingRecommendationsTable($con); // also ensures training_demand exists

$pendingUsers = $con->query("SELECT id FROM users WHERE status = 'pending'");
$pendingCount = $pendingUsers ? $pendingUsers->num_rows : 0;

/* -----------------------------------------------------------------------------
   TRAINING DEMAND - HR's pooled, cross-college view of accepted training
   requests, ranked by requester count.

   2026-09-21 - orphaned demands are excluded. A demand row outlives the
   employee who created it: deleting a user cascades to their
   training_recommendations rows but leaves the training_demand row behind,
   so HR saw "Awaiting Your Review" trainings with 0 requesters and nothing
   to act on (they also inflated the pending-review badge). An orphan =
   no recommendation row linked in ANY status AND not dean-posted. The
   status-agnostic EXISTS is deliberate: a 'No Training Found' round keeps
   its requesters as 'Cancelled' rows (history worth keeping), and a
   dean-posted opportunity legitimately starts with no requesters at all
   (sourced_college is set). If someone later accepts the same title,
   findOrCreateTrainingDemand() re-attaches to the row and it reappears.
   ----------------------------------------------------------------------------- */
$trainingDemandQuery = $con->query("
    SELECT d.id, d.title, d.found_training_title, d.training_type, d.pipeline_status, d.sourced_college,
           COUNT(tr.id) AS requester_count
    FROM training_demand d
    LEFT JOIN training_recommendations tr ON tr.demand_id = d.id
      AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
    WHERE d.sourced_college IS NOT NULL
       OR EXISTS (SELECT 1 FROM training_recommendations x WHERE x.demand_id = d.id)
    GROUP BY d.id
    ORDER BY requester_count DESC, d.created_at DESC
");
$trainingDemandRows = $trainingDemandQuery ? $trainingDemandQuery->fetch_all(MYSQLI_ASSOC) : [];
$pendingDemandCount = count(array_filter($trainingDemandRows, fn($d) => $d['pipeline_status'] === 'Pending HR Review'));

$demandDeptStmt = $con->query("
    SELECT tr.demand_id, u.department
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
");
$deptsByDemand = [];
if ($demandDeptStmt) {
    while ($row = $demandDeptStmt->fetch_assoc()) {
        $deptsByDemand[$row['demand_id']][] = $row['department'];
    }
}
$existingDeanRolesResult = $con->query("SELECT DISTINCT role FROM users WHERE role LIKE 'admin\\_%'");
$existingDeanRoles = $existingDeanRolesResult ? array_column($existingDeanRolesResult->fetch_all(MYSQLI_ASSOC), 'role') : [];
foreach ($trainingDemandRows as &$d) {
    $codes = array_unique(array_filter(array_map('canonicalTnaCollegeCode', $deptsByDemand[$d['id']] ?? [])));
    $hasDean = false;
    foreach ($codes as $code) {
        if (in_array('admin_' . strtolower($code), $existingDeanRoles, true)) {
            $hasDean = true;
            break;
        }
    }
    $d['has_dean'] = $hasDean;
}
unset($d);

ensureCustomTrainingRequestsTable($con);
$customOriginResult = $con->query("SELECT DISTINCT demand_id FROM custom_training_requests WHERE demand_id IS NOT NULL");
$customOriginDemandIds = $customOriginResult ? array_column($customOriginResult->fetch_all(MYSQLI_ASSOC), 'demand_id') : [];
foreach ($trainingDemandRows as &$d) {
    $d['from_employee_request'] = in_array($d['id'], $customOriginDemandIds);
}
unset($d);

// 2026-09-06 - "Origin" as a real, filterable field instead of a badge
// tucked under the title. Three real origins exist in the data now:
// an employee typed it in themselves (custom_training_requests),
// a dean posted it directly with no employee request behind it at all
// (sourced_college is only ever set by create_dean_sourced_training.php),
// or the default/most common case - the AI recommended it from the
// catalog and enough employees Accepted it to pool into a demand.
foreach ($trainingDemandRows as &$d) {
    if ($d['from_employee_request']) {
        $d['origin'] = 'Requested';
    } elseif (!empty($d['sourced_college'])) {
        $d['origin'] = 'Dean-Posted';
    } else {
        $d['origin'] = 'Recommended';
    }
}
unset($d);

// demandStatusBadgeClass() / demandStatusLabel() live in ml_recommendations.php
// (shared with reports_analytics.php's Top Requested card).
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Training Pipeline - LSPU TNA</title>

  <!-- ===========================================================
       FONTS — Plus Jakarta Sans (display) + Inter (body/UI)
                + JetBrains Mono (numeric / data emphasis)
       =========================================================== -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <!-- Icon sets (kept from original markup dependencies) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- ===========================================================
       TAILWIND (CDN) — palette extended to the new enterprise tokens
       so existing utility classes keep resolving against the new theme.
       =========================================================== -->
  <link rel="stylesheet" href="assets/css/tw-48.css">

  <!-- ===========================================================
       DESIGN SYSTEM — hand-authored CSS, organized by concern.
       Sections:
         1.  Tokens (CSS custom properties)
         2.  Base / reset
         3.  App shell layout
         4.  Sidebar (rail + collapsed + mobile)
         5.  Topbar + dropdowns
         6.  Page header
         7.  Cards + stat cards
         8.  Buttons
         9.  Badges + pills
         10. Progress bars
         11. Tables
         12. Forms + toggle
         13. Modals
         14. Toasts
         15. Skeletons (loading)
         16. Empty states
         17. Chart container
         18. Scrollbar
         19. Responsive
         20. Motion preferences
       =========================================================== -->
  <style>
    /* ----------------------------------------------------------
       1. TOKENS
       ---------------------------------------------------------- */
    :root {
      /* Surfaces + neutrals */
      --bg:          #f5f6f8;
      --bg-grad:     radial-gradient(1100px 600px at 100% -8%, rgba(99,102,241,0.05), transparent 60%);
      --surface:     #ffffff;
      --surface-2:   #f8fafc;
      --surface-3:   #f1f3f7;

      /* Text */
      --ink:         #0f172a;
      --ink-2:       #334155;
      --muted:       #64748b;
      --faint:       #94a3b8;

      /* Lines */
      --line:        #e5e7eb;
      --line-soft:   #eef1f5;

      /* Accent (indigo) */
      --accent:      #4f46e5;
      --accent-600:  #4f46e5;
      --accent-700:  #4338ca;
      --accent-soft: #eef2ff;
      --accent-ink:  #3730a3;

      /* Status — emerald / amber / rose / sky */
      --ok:          #059669;
      --ok-soft:     #ecfdf5;
      --ok-ink:      #065f46;
      --warn:        #d97706;
      --warn-soft:   #fffbeb;
      --warn-ink:    #92400e;
      --bad:         #e11d48;
      --bad-soft:    #fff1f2;
      --bad-ink:     #9f1239;
      --sky:         #0284c7;
      --sky-soft:    #f0f9ff;

      /* Sidebar (dark slate rail) */
      --rail:        #0f172a;
      --rail-2:      #111c33;
      --rail-line:   rgba(148,163,184,0.14);
      --rail-text:   rgba(226,232,240,0.74);
      --rail-text-2: rgba(148,163,184,0.55);

      /* Geometry */
      --radius:      14px;
      --radius-sm:   10px;
      --radius-lg:   18px;
      --rail-w:      264px;
      --rail-w-min:  78px;
      --topbar-h:    66px;

      /* Easing */
      --ease:        cubic-bezier(0.16, 1, 0.3, 1);
      --shadow-card: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -18px rgba(15,23,42,0.22);
      --shadow-pop:  0 16px 40px -12px rgba(15,23,42,0.22);
    }

    /* ----------------------------------------------------------
       2. BASE / RESET
       ---------------------------------------------------------- */
    *, *::before, *::after { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

    html, body { height: 100%; }

    body {
      margin: 0;
      font-family: 'Inter', system-ui, sans-serif;
      color: var(--ink);
      background: var(--bg-grad), var(--bg);
      -webkit-font-smoothing: antialiased;
      text-rendering: optimizeLegibility;
    }

    h1, h2, h3, h4, h5 {
      font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
      letter-spacing: -0.015em;
      margin: 0;
    }

    /* Monospace numeric treatment — the "data" signature of the dashboard. */
    .num { font-family: 'JetBrains Mono', ui-monospace, monospace; font-feature-settings: "tnum" 1; letter-spacing: -0.02em; }

    a { color: inherit; text-decoration: none; }

    /* Visible keyboard focus everywhere (a11y floor). */
    :focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px;
      border-radius: 6px;
    }

    .eyebrow {
      font-size: 0.68rem; font-weight: 600; letter-spacing: 0.12em;
      text-transform: uppercase; color: var(--faint);
    }

    /* ----------------------------------------------------------
       3. APP SHELL LAYOUT
       ---------------------------------------------------------- */
    .app {
      display: grid;
      grid-template-columns: var(--rail-w) 1fr;
      min-height: 100vh;
      transition: grid-template-columns 0.28s var(--ease);
    }
    .app.rail-collapsed { grid-template-columns: var(--rail-w-min) 1fr; }

    .app-main {
      min-width: 0;                 /* prevents grid blowout from wide tables */
      display: flex;
      flex-direction: column;
      max-height: 100vh;
      overflow: hidden;
    }

    .content-scroll {
      flex: 1 1 auto;
      overflow-y: auto;
      overflow-x: hidden;
    }

    .content {
      max-width: 1640px;
      margin: 0 auto;
      padding: 1.6rem 1.8rem 3rem;
    }

    /* ----------------------------------------------------------
       4. SIDEBAR
       ---------------------------------------------------------- */
    .rail {
      background: linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%);
      color: var(--rail-text);
      display: flex;
      flex-direction: column;
      position: sticky;
      top: 0;
      height: 100vh;
      border-right: 1px solid rgba(0,0,0,0.2);
      z-index: 50;
    }

    .rail-brand {
      display: flex; align-items: center; gap: 0.75rem;
      padding: 1.15rem 1.25rem;
      border-bottom: 1px solid var(--rail-line);
      min-height: var(--topbar-h);
    }
    .rail-logo {
      width: 42px; height: 42px; border-radius: 12px;
      background: #fff; padding: 5px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 6px 16px -8px rgba(0,0,0,0.6);
    }
    .rail-logo-fallback {
      width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
      background: linear-gradient(135deg, var(--accent), #6366f1);
      display: flex; align-items: center; justify-content: center;
    }
    .rail-brand-text { min-width: 0; transition: opacity 0.2s var(--ease); }
    .rail-brand-text h1 { font-size: 1rem; color: #fff; line-height: 1.1; white-space: nowrap; }
    .rail-brand-text p  { font-size: 0.7rem; color: var(--rail-text-2); margin-top: 2px; white-space: nowrap; }

    .rail-nav { flex: 1 1 auto; overflow-y: auto; padding: 1rem 0.7rem; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
    .rail-section-label {
      font-size: 0.64rem; font-weight: 700; letter-spacing: 0.12em;
      text-transform: uppercase; color: var(--rail-text-2);
      padding: 0 0.85rem; margin: 0.4rem 0 0.55rem;
      transition: opacity 0.2s var(--ease);
    }

    .rail-link {
      display: flex; align-items: center; gap: 0.85rem;
      padding: 0.7rem 0.85rem; margin: 2px 0;
      border-radius: 11px; color: var(--rail-text);
      font-size: 0.875rem; font-weight: 500;
      border: 1px solid transparent;
      transition: background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease);
      position: relative; white-space: nowrap;
    }
    .rail-link i:first-child { font-size: 1.2rem; flex-shrink: 0; width: 22px; text-align: center; }
    .rail-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
    .rail-link.active {
      background: linear-gradient(100deg, rgba(99,102,241,0.22), rgba(99,102,241,0.08));
      color: #fff;
      border-color: rgba(129,140,248,0.32);
    }
    .rail-link.active::before {
      content: ''; position: absolute; left: -0.7rem; top: 50%; transform: translateY(-50%);
      width: 3px; height: 22px; border-radius: 0 4px 4px 0; background: #818cf8;
    }
    .rail-link .chev { margin-left: auto; opacity: 0.5; font-size: 1rem; }

    .rail-foot { padding: 0.7rem; border-top: 1px solid var(--rail-line); }
    .rail-signout {
      display: flex; align-items: center; gap: 0.85rem;
      padding: 0.7rem 0.85rem; border-radius: 11px;
      color: #fda4af; font-size: 0.875rem; font-weight: 500;
      border: 1px solid rgba(244,63,94,0.18);
      transition: background 0.18s var(--ease);
      white-space: nowrap;
    }
    .rail-signout i { font-size: 1.2rem; width: 22px; text-align: center; }
    .rail-signout:hover { background: rgba(244,63,94,0.16); color: #fecdd3; }

    /* Collapsed rail — hide text, center icons. */
    .app.rail-collapsed .rail-brand-text,
    .app.rail-collapsed .rail-section-label,
    .app.rail-collapsed .rail-link span,
    .app.rail-collapsed .rail-link .chev,
    .app.rail-collapsed .rail-signout span { opacity: 0; pointer-events: none; width: 0; overflow: hidden; }
    .app.rail-collapsed .rail-link,
    .app.rail-collapsed .rail-signout { justify-content: center; gap: 0; }
    .app.rail-collapsed .rail-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .app.rail-collapsed .rail-section-label { height: 0; margin: 0; padding: 0; }

    /* Mobile slide-in behaviour (driven by .rail-open on .app). */
    .rail-scrim {
      position: fixed; inset: 0; background: rgba(15,23,42,0.5);
      backdrop-filter: blur(2px); z-index: 45; opacity: 0; visibility: hidden;
      transition: opacity 0.25s var(--ease), visibility 0.25s var(--ease);
    }

    /* ----------------------------------------------------------
       5. TOPBAR + DROPDOWNS
       ---------------------------------------------------------- */
    .topbar {
      height: var(--topbar-h); flex-shrink: 0;
      display: flex; align-items: center; gap: 1rem;
      padding: 0 1.4rem;
      background: rgba(255,255,255,0.85);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--line);
      position: sticky; top: 0; z-index: 30;
    }

    .icon-btn {
      width: 40px; height: 40px; border-radius: 11px;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--line); background: var(--surface);
      color: var(--ink-2); font-size: 1.2rem; cursor: pointer;
      transition: background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease);
      position: relative; flex-shrink: 0;
    }
    .icon-btn:hover { background: var(--surface-2); border-color: #cbd5e1; color: var(--ink); }

    .hamburger { display: none; }   /* shown on mobile via media query */

    .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 0.6rem; }

    /* Notification dot on the bell. */
    .bell-dot {
      position: absolute; top: 8px; right: 9px; width: 8px; height: 8px;
      border-radius: 50%; background: var(--bad); border: 2px solid var(--surface);
    }

    /* User avatar chip. */
    .avatar-chip {
      display: flex; align-items: center; gap: 0.6rem;
      padding: 0.3rem 0.55rem 0.3rem 0.35rem;
      border: 1px solid var(--line); border-radius: 12px; background: var(--surface);
      cursor: pointer; transition: background 0.18s var(--ease), border-color 0.18s var(--ease);
    }
    .avatar-chip:hover { background: var(--surface-2); border-color: #cbd5e1; }
    .avatar {
      width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
      background: linear-gradient(135deg, var(--accent), #818cf8);
      color: #fff; font-weight: 700; font-size: 0.85rem;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .avatar-meta { line-height: 1.15; text-align: left; }
    .avatar-meta .nm { font-size: 0.82rem; font-weight: 600; color: var(--ink); }
    .avatar-meta .rl { font-size: 0.7rem; color: var(--muted); }

    /* Generic dropdown panel (bell + avatar). */
    .dropdown { position: relative; }
    .dropdown-panel {
      position: absolute; right: 0; top: calc(100% + 0.55rem);
      width: 320px; background: var(--surface);
      border: 1px solid var(--line); border-radius: 16px;
      box-shadow: var(--shadow-pop); overflow: hidden;
      opacity: 0; transform: translateY(-6px) scale(0.98); transform-origin: top right;
      pointer-events: none; transition: opacity 0.18s var(--ease), transform 0.18s var(--ease);
      z-index: 50;
    }
    .dropdown.open .dropdown-panel { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    .dropdown-head {
      padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--line-soft);
      display: flex; align-items: center; justify-content: space-between;
    }
    .dropdown-head h4 { font-size: 0.92rem; }
    .dropdown-item {
      display: flex; gap: 0.75rem; padding: 0.8rem 1.1rem;
      border-bottom: 1px solid var(--line-soft); transition: background 0.15s var(--ease);
    }
    .dropdown-item:hover { background: var(--surface-2); }
    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-menu-item {
      display: flex; align-items: center; gap: 0.7rem; width: 100%;
      padding: 0.7rem 1.1rem; font-size: 0.86rem; color: var(--ink-2);
      transition: background 0.15s var(--ease); cursor: pointer;
    }
    .dropdown-menu-item i { font-size: 1.05rem; color: var(--muted); width: 20px; text-align: center; }
    .dropdown-menu-item:hover { background: var(--surface-2); color: var(--ink); }
    .dropdown-menu-item.danger { color: var(--bad-ink); }
    .dropdown-menu-item.danger i { color: var(--bad); }
    .dropdown-menu-item.danger:hover { background: var(--bad-soft); }

    /* ----------------------------------------------------------
       6. PAGE HEADER
       ---------------------------------------------------------- */
    .page-head {
      display: flex; align-items: flex-end; justify-content: space-between;
      gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;
    }
    .page-head h2 { font-size: 1.55rem; font-weight: 800; color: var(--ink); }
    .page-head p { color: var(--muted); font-size: 0.9rem; margin-top: 0.25rem; }
    .date-chip {
      display: inline-flex; align-items: center; gap: 0.55rem;
      padding: 0.55rem 0.9rem; border: 1px solid var(--line);
      border-radius: 12px; background: var(--surface); box-shadow: var(--shadow-card);
    }
    .date-chip i { color: var(--accent); font-size: 1.1rem; }
    .date-chip span { font-size: 0.85rem; font-weight: 600; color: var(--ink-2); }

    /* ----------------------------------------------------------
       7. CARDS + STAT CARDS
       ---------------------------------------------------------- */
    .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow-card);
      overflow: hidden;
      transition: transform 0.22s var(--ease), box-shadow 0.22s var(--ease), border-color 0.22s var(--ease);
    }
    .card.hoverable:hover { transform: translateY(-3px); box-shadow: var(--shadow-pop); border-color: #d8dde6; }

    .card-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1.05rem 1.3rem; border-bottom: 1px solid var(--line-soft);
    }
    .card-head h3 { font-size: 1rem; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 0.55rem; }
    .card-head h3 i { color: var(--accent); font-size: 1.15rem; }
    .card-head-icon {
      width: 38px; height: 38px; border-radius: 11px; background: var(--accent-soft);
      display: flex; align-items: center; justify-content: center; color: var(--accent); font-size: 1.05rem;
    }
    .card-body { padding: 1.3rem; }

    /* Stat card with a colored top accent rule. */
    .stat {
      position: relative; background: var(--surface);
      border: 1px solid var(--line); border-radius: var(--radius);
      box-shadow: var(--shadow-card); overflow: hidden;
      transition: transform 0.22s var(--ease), box-shadow 0.22s var(--ease);
    }
    .stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-pop); }
    .stat::after {
      content: ''; position: absolute; inset: 0 0 auto 0; height: 3px;
      background: var(--stat-accent, var(--accent));
    }
    .stat.a-indigo  { --stat-accent: linear-gradient(90deg, #6366f1, #4f46e5); }
    .stat.a-amber   { --stat-accent: linear-gradient(90deg, #fbbf24, #d97706); }
    .stat.a-emerald { --stat-accent: linear-gradient(90deg, #34d399, #059669); }
    .stat.a-rose    { --stat-accent: linear-gradient(90deg, #fb7185, #e11d48); }
    .stat-body { padding: 1.25rem 1.3rem; display: flex; align-items: center; justify-content: space-between; }
    .stat-label { font-size: 0.8rem; font-weight: 500; color: var(--muted); }
    .stat-value { font-size: 2rem; font-weight: 700; margin-top: 0.35rem; line-height: 1; }
    .stat-ico {
      width: 52px; height: 52px; border-radius: 14px;
      display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
    }
    .ico-indigo  { background: var(--accent-soft); color: var(--accent); }
    .ico-amber   { background: var(--warn-soft);   color: var(--warn); }
    .ico-emerald { background: var(--ok-soft);     color: var(--ok); }
    .ico-rose    { background: var(--bad-soft);    color: var(--bad); }
    .ico-violet  { background: #f5f3ff;            color: #7c3aed; }

    /* ----------------------------------------------------------
       8. BUTTONS
       ---------------------------------------------------------- */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
      font-family: 'Inter', sans-serif; font-size: 0.875rem; font-weight: 600;
      padding: 0.65rem 1.1rem; border-radius: var(--radius-sm);
      border: 1px solid transparent; cursor: pointer; white-space: nowrap;
      transition: background 0.18s var(--ease), border-color 0.18s var(--ease),
                  box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease);
    }
    .btn:active { transform: translateY(1px); }
    .btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .btn-sm { padding: 0.5rem 0.85rem; font-size: 0.82rem; }
    .btn-lg { padding: 0.85rem 1.3rem; font-size: 0.92rem; }
    .btn-block { width: 100%; }

    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 8px 18px -10px rgba(79,70,229,0.7); }
    .btn-primary:hover { background: var(--accent-700); }
    .btn-primary:focus-visible { box-shadow: 0 0 0 4px rgba(79,70,229,0.25); }

    .btn-ghost { background: var(--surface); color: var(--ink-2); border-color: var(--line); }
    .btn-ghost:hover { background: var(--surface-2); border-color: #cbd5e1; }

    .btn-success { background: var(--ok); color: #fff; box-shadow: 0 8px 18px -10px rgba(5,150,105,0.6); }
    .btn-success:hover { background: #047857; }

    .btn-warning { background: var(--warn); color: #fff; box-shadow: 0 8px 18px -10px rgba(217,119,6,0.55); }
    .btn-warning:hover { background: #b45309; }

    .btn-danger { background: var(--bad); color: #fff; box-shadow: 0 8px 18px -10px rgba(225,29,72,0.55); }
    .btn-danger:hover { background: #be123c; }

    .btn-delete { background: var(--surface); color: var(--bad); border-color: #fecdd3; }
    .btn-delete:hover { background: var(--bad-soft); border-color: #fda4af; }

    /* ----------------------------------------------------------
       9. BADGES + PILLS
       ---------------------------------------------------------- */
    .badge {
      display: inline-flex; align-items: center; gap: 0.3rem;
      padding: 0.32rem 0.7rem; border-radius: 999px;
      font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em;
    }
    .badge-pending  { background: var(--warn-soft); color: var(--warn-ink); }
    .badge-accepted { background: var(--ok-soft);   color: var(--ok-ink); }
    .badge-declined { background: var(--bad-soft);  color: var(--bad-ink); }
    .badge-on-time  { background: var(--ok-soft);   color: var(--ok-ink); }
    .badge-late     { background: var(--warn-soft); color: var(--warn-ink); }
    .badge-no-sub   { background: var(--bad-soft);  color: var(--bad-ink); }
    .badge-info     { background: var(--accent-soft); color: var(--accent-ink); }
    .badge-count    { background: var(--accent-soft); color: var(--accent-ink); padding: 0.28rem 0.6rem; }

    /* Open/closed submission pill. */
    .pill {
      display: inline-flex; align-items: center; gap: 0.4rem;
      padding: 0.4rem 0.85rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;
    }
    .pill-open  { background: var(--ok-soft);  color: var(--ok-ink); }
    .pill-closed{ background: var(--bad-soft); color: var(--bad-ink); }

    /* ----------------------------------------------------------
       10. PROGRESS BARS
       ---------------------------------------------------------- */
    .progress { height: 9px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
    .progress > span { display: block; height: 100%; border-radius: 999px; transition: width 0.8s var(--ease); }
    .pf-ok   { background: var(--ok); }
    .pf-warn { background: var(--warn); }
    .pf-bad  { background: var(--bad); }

    /* ----------------------------------------------------------
       11. TABLES
       ---------------------------------------------------------- */
    .table-wrap { border: 1px solid var(--line); border-radius: var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
    /* ISO 25010 audit (2026-09-06): the raw custom-training-request list
       (get_custom_training_requests.php) queries with no LIMIT and
       renderCustomRequestsRaw() renders every row with no pagination -
       unlike the Training Demand table above, which already paginates
       client-side (DEMAND_PER_PAGE). Fine at today's data volume, but
       nothing stops the raw list from growing into a page-breaking wall
       of rows as more submissions accumulate. Capping height with an
       internal scroll (same fix already used for the notification
       dropdown) keeps the modal a sane, predictable size without hiding
       any data - if this keeps growing, real pagination is the next step. */
    .table-wrap-scroll { max-height: 420px; overflow-y: auto; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th {
      background: var(--surface-2); text-align: left;
      font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
      color: var(--muted); padding: 0.8rem 1.15rem; border-bottom: 1px solid var(--line);
    }
    table.data td { padding: 0.85rem 1.15rem; border-bottom: 1px solid var(--line-soft); font-size: 0.875rem; color: var(--ink-2); }
    table.data tbody tr:nth-child(even) { background: var(--surface-2); }       /* zebra striping */
    table.data tbody tr:hover { background: var(--accent-soft); }
    table.data tbody tr:last-child td { border-bottom: none; }
    table.data td.strong { font-weight: 600; color: var(--ink); }

    /* ----------------------------------------------------------
       12. FORMS + TOGGLE
       ---------------------------------------------------------- */
    .field { margin-bottom: 1.15rem; }
    .field > label {
      display: block; font-size: 0.82rem; font-weight: 600; color: var(--ink-2); margin-bottom: 0.5rem;
    }
    .field .req { color: var(--bad); }
    .input, .textarea {
      width: 100%; font-family: 'Inter', sans-serif; font-size: 0.875rem; color: var(--ink);
      padding: 0.7rem 0.9rem; border: 1px solid var(--line); border-radius: var(--radius-sm);
      background: var(--surface); transition: border-color 0.18s var(--ease), box-shadow 0.18s var(--ease);
    }
    .textarea { resize: vertical; min-height: 84px; }
    .input::placeholder, .textarea::placeholder { color: var(--faint); }
    .input:focus, .textarea:focus {
      outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(79,70,229,0.12);
    }
    .input.has-error, .textarea.has-error { border-color: var(--bad); box-shadow: 0 0 0 4px rgba(225,29,72,0.1); }
    .field-help { font-size: 0.76rem; color: var(--muted); margin-top: 0.4rem; }
    .field-error { font-size: 0.78rem; color: var(--bad); margin-top: 0.4rem; display: none; }
    .field-error.show { display: block; }

    /* Search box (inside modals). */
    .search {
      position: relative;
    }
    .search input {
      width: 100%; height: 44px; padding: 0 1rem 0 2.7rem;
      border: 1px solid var(--line); border-radius: 12px; background: var(--surface-2);
      font-size: 0.875rem; color: var(--ink);
      transition: border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), background 0.18s var(--ease);
    }
    .search input::placeholder { color: var(--faint); }
    .search input:focus { outline: none; background: #fff; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(79,70,229,0.12); }
    .search i { position: absolute; left: 0.95rem; top: 50%; transform: translateY(-50%); color: var(--faint); font-size: 1.1rem; }

    /* Toggle switch (accessible, label-driven). */
    .switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
    .switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .switch .track {
      position: absolute; inset: 0; border-radius: 999px; background: #cbd5e1;
      transition: background 0.2s var(--ease); cursor: pointer;
    }
    .switch .track::before {
      content: ''; position: absolute; height: 20px; width: 20px; left: 3px; top: 3px;
      border-radius: 50%; background: #fff; transition: transform 0.2s var(--ease);
      box-shadow: 0 1px 3px rgba(0,0,0,0.25);
    }
    .switch input:checked + .track { background: var(--accent); }
    .switch input:checked + .track::before { transform: translateX(20px); }
    .switch input:focus-visible + .track { box-shadow: 0 0 0 4px rgba(79,70,229,0.22); }

    /* ----------------------------------------------------------
       13. MODALS
       ---------------------------------------------------------- */
    .modal {
      position: fixed; inset: 0; z-index: 60;
      display: none; align-items: center; justify-content: center; padding: 1rem;
      background: rgba(15,23,42,0.55); backdrop-filter: blur(6px);
    }
    .modal.open { display: flex; animation: fadeIn 0.18s var(--ease); }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .modal-box {
      background: var(--surface); border-radius: 20px; width: 100%;
      max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column;
      overflow: hidden; box-shadow: 0 30px 60px -20px rgba(15,23,42,0.45);
      animation: popIn 0.26s var(--ease);
    }
    @keyframes popIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

    .modal-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--line); flex-shrink: 0;
    }
    /* flex-wrap added 2026-09-01 - a real mobile finding: with nowrap
       (the flex default), a long dynamic title (e.g. "Submissions ·
       <deadline name>") doesn't drop to a new line as a whole unit at
       narrow widths - the text inside each flex item wraps on its own
       instead, interleaving into a jumbled staggered layout. Wrapping
       lets a long suffix drop cleanly below the icon+label instead. */
    .modal-head h3 { font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
    .modal-head h3 i { color: var(--accent); }
    .modal-body { padding: 1.4rem 1.5rem; overflow-y: auto; flex: 1 1 auto; }
    .modal-foot {
      display: flex; justify-content: flex-end; gap: 0.7rem;
      padding: 1rem 1.5rem; border-top: 1px solid var(--line); background: var(--surface-2); flex-shrink: 0;
    }
    .modal-x {
      width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line);
      background: var(--surface); color: var(--muted); cursor: pointer;
      display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem;
      transition: background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease);
    }
    .modal-x:hover { background: var(--bad-soft); color: var(--bad); border-color: #fecdd3; }

    /* Tabs (inside user management modal). */
    .tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid var(--line); margin-bottom: 1.3rem; }
    .tab {
      padding: 0.65rem 1.1rem; font-size: 0.875rem; font-weight: 500; color: var(--muted);
      border-bottom: 2px solid transparent; cursor: pointer;
      transition: color 0.18s var(--ease), border-color 0.18s var(--ease);
    }
    .tab:hover { color: var(--ink-2); }
    .tab.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
    .tab-panel.hidden { display: none; }

    /* ----------------------------------------------------------
       14. TOASTS
       ---------------------------------------------------------- */
    .toast-stack {
      position: fixed; top: 1.1rem; right: 1.1rem; z-index: 80;
      display: flex; flex-direction: column; gap: 0.7rem; width: 360px; max-width: calc(100vw - 2rem);
    }
    .toast {
      display: flex; gap: 0.8rem; align-items: flex-start;
      background: var(--surface); border: 1px solid var(--line);
      border-left: 4px solid var(--accent); border-radius: 14px;
      padding: 0.9rem 1rem; box-shadow: var(--shadow-pop);
      animation: toastIn 0.3s var(--ease);
    }
    .toast.leaving { animation: toastOut 0.3s var(--ease) forwards; }
    @keyframes toastIn  { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(20px); } }
    .toast.ok   { border-left-color: var(--ok); }
    .toast.warn { border-left-color: var(--warn); }
    .toast.err  { border-left-color: var(--bad); }
    .toast-ico { font-size: 1.3rem; flex-shrink: 0; margin-top: 1px; }
    .toast.ok   .toast-ico { color: var(--ok); }
    .toast.warn .toast-ico { color: var(--warn); }
    .toast.err  .toast-ico { color: var(--bad); }
    .toast-body { flex: 1 1 auto; min-width: 0; }
    .toast-title { font-size: 0.86rem; font-weight: 700; color: var(--ink); }
    .toast-msg { font-size: 0.8rem; color: var(--muted); margin-top: 2px; word-break: break-word; }
    .toast-close { color: var(--faint); cursor: pointer; font-size: 1.1rem; flex-shrink: 0; }
    .toast-close:hover { color: var(--ink); }

    /* ----------------------------------------------------------
       15. SKELETONS (loading placeholders)
       ---------------------------------------------------------- */
    .skel {
      background: linear-gradient(90deg, #eef1f5 25%, #e2e8f0 37%, #eef1f5 63%);
      background-size: 400% 100%; border-radius: 8px; animation: shimmer 1.4s ease infinite;
    }
    @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
    .skel-line { height: 12px; }
    .skel-pill { height: 22px; width: 76px; border-radius: 999px; }

    /* ----------------------------------------------------------
       16. EMPTY STATES
       ---------------------------------------------------------- */
    .empty { text-align: center; padding: 3rem 1rem; color: var(--faint); }
    .empty i { font-size: 3.2rem; color: #cbd5e1; }
    .empty h4 { font-size: 1.05rem; color: var(--muted); margin-top: 0.8rem; font-weight: 600; }
    .empty p { font-size: 0.85rem; color: var(--faint); margin-top: 0.3rem; }

    /* ----------------------------------------------------------
       17. CHART CONTAINER
       ---------------------------------------------------------- */
    .chart-box { position: relative; height: 420px; width: 100%; padding: 0.25rem; }
    .legend { display: flex; justify-content: center; gap: 1.6rem; flex-wrap: wrap; margin-top: 1.2rem; }
    .legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; font-weight: 500; color: var(--muted); }
    .legend-swatch { width: 14px; height: 14px; border-radius: 4px; }

    /* ----------------------------------------------------------
       18. SCROLLBAR
       ---------------------------------------------------------- */
    ::-webkit-scrollbar { width: 9px; height: 9px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; border: 2px solid transparent; background-clip: content-box; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }

    /* ----------------------------------------------------------
       19. GRID HELPERS + RESPONSIVE
       ---------------------------------------------------------- */
    .grid-stats    { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-actions  { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-teaching { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-main     { display: grid; grid-template-columns: 1fr 1.85fr; gap: 1.15rem; align-items: start; }
    .stack { display: flex; flex-direction: column; gap: 1.15rem; }

    @media (max-width: 1200px) {
      .grid-stats { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 1024px) {
      .grid-actions, .grid-main, .grid-teaching { grid-template-columns: 1fr; }
    }
    @media (max-width: 900px) {
      /* Collapse the shell to a single column; rail becomes an off-canvas drawer. */
      .app, .app.rail-collapsed { grid-template-columns: 1fr; }
      .rail {
        position: fixed; top: 0; left: 0; width: var(--rail-w); height: 100vh; height: 100dvh;
        transform: translateX(-100%); transition: transform 0.3s var(--ease);
      }
      .app.rail-open .rail { transform: translateX(0); box-shadow: 24px 0 60px -20px rgba(0,0,0,0.5); }
      .app.rail-open .rail-scrim { opacity: 1; visibility: visible; }
      .hamburger { display: inline-flex; }
      .content { padding: 1.1rem 1.1rem 2.5rem; }
    }
    @media (max-width: 640px) {
      .grid-stats { grid-template-columns: 1fr 1fr; }
      .avatar-meta { display: none; }
      .page-head h2 { font-size: 1.3rem; }
    }
    @media (max-width: 420px) {
      .grid-stats { grid-template-columns: 1fr; }
    }

    /* ----------------------------------------------------------
       20. MOTION PREFERENCES
       ---------------------------------------------------------- */
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }
    }
  </style>
</head>

<body>
<!-- =============================================================
     APP SHELL — grid: [ sidebar rail | main column ]
     State classes toggled by JS on #app:
       .rail-collapsed → desktop mini-rail
       .rail-open      → mobile drawer open
     ============================================================= -->
<div class="app" id="app">

  <!-- Scrim shown behind the mobile drawer. -->
  <div class="rail-scrim" onclick="closeMobileRail()" aria-hidden="true"></div>

  <!-- ===========================================================
       SIDEBAR RAIL
       =========================================================== -->
  <aside class="rail" id="rail">
    <!-- Brand -->
    <div class="rail-brand">
      <img src="images/lspu-logo.png" alt="LSPU" class="rail-logo"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
      <div class="rail-logo-fallback" style="display:none;">
        <i class="ri-government-line text-white text-2xl"></i>
      </div>
      <div class="rail-brand-text">
        <h1>LSPU Admin</h1>
        <p>Main Administrator</p>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="admin_page.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>

      <p class="rail-section-label">Training</p>
      <a href="training_pipeline.php" class="rail-link active">
        <i class="ri-stack-line"></i><span>Training Pipeline</span>
        <i class="ri-arrow-right-s-line chev"></i>
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
      <a href="Evaluation_Form.php" class="rail-link">
        <i class="ri-file-search-line"></i><span>Evaluation Forms</span>
      </a>
    </nav>

    <!-- Sign out -->
    <div class="rail-foot">
      <a href="logout.php" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- ===========================================================
       MAIN COLUMN
       =========================================================== -->
  <div class="app-main">

    <!-- ---------- TOPBAR ---------- -->
    <header class="topbar">
      <!-- Mobile: open drawer -->
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu">
        <i class="ri-menu-line"></i>
      </button>
      <!-- Desktop: collapse/expand rail -->
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn"
              style="display:none;">
        <i class="ri-side-bar-line"></i>
      </button>

      <div class="topbar-right">
        <!-- Notification bell -->
        <div class="dropdown" id="bellDropdown">
          <button class="icon-btn" onclick="toggleDropdown('bellDropdown')" aria-label="Notifications">
            <i class="ri-notification-3-line"></i>
            <?php if ($pendingCount > 0): ?><span class="bell-dot"></span><?php endif; ?>
          </button>
          <div class="dropdown-panel">
            <div class="dropdown-head">
              <h4>Notifications</h4>
              <?php if ($pendingCount > 0): ?>
                <span class="badge badge-count num"><?= $pendingCount ?> new</span>
              <?php endif; ?>
            </div>
            <?php if ($pendingCount > 0): ?>
              <div class="dropdown-item">
                <div class="stat-ico ico-amber" style="width:38px;height:38px;font-size:1.05rem;">
                  <i class="ri-user-add-line"></i>
                </div>
                <div>
                  <p style="font-size:0.85rem;font-weight:600;color:var(--ink);">
                    <?= $pendingCount ?> pending registration<?= $pendingCount > 1 ? 's' : '' ?>
                  </p>
                  <p style="font-size:0.78rem;color:var(--muted);margin-top:2px;">Waiting for your review.</p>
                  <a href="admin_page.php" class="btn btn-ghost btn-sm" style="margin-top:0.55rem;display:inline-block;text-decoration:none;">
                    Review now
                  </a>
                </div>
              </div>
            <?php else: ?>
              <div class="empty" style="padding:2rem 1rem;">
                <i class="ri-check-double-line"></i>
                <h4>You're all caught up</h4>
                <p>No pending registrations.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- User avatar + menu -->
        <div class="dropdown" id="avatarDropdown">
          <?php
            $adminName = $_SESSION['user_name'] ?? 'Admin';
            // Build up to two-letter initials for the avatar tile.
            $initials = strtoupper(substr($adminName, 0, 1));
            $parts = preg_split('/\s+/', trim($adminName));
            if (count($parts) > 1) { $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1)); }
          ?>
          <button class="avatar-chip" onclick="toggleDropdown('avatarDropdown')" aria-label="Account menu">
            <span class="avatar"><?= htmlspecialchars($initials) ?></span>
            <span class="avatar-meta">
              <span class="nm"><?= htmlspecialchars($adminName) ?></span>
              <span class="rl">HR Admin</span>
            </span>
            <i class="ri-arrow-down-s-line" style="color:var(--muted);"></i>
          </button>
          <div class="dropdown-panel" style="width:240px;">
            <div class="dropdown-head">
              <div style="display:flex;align-items:center;gap:0.65rem;">
                <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                <div style="line-height:1.2;">
                  <div style="font-size:0.85rem;font-weight:700;"><?= htmlspecialchars($adminName) ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);">Main Administrator</div>
                </div>
              </div>
            </div>
            <a class="dropdown-menu-item" href="logout.php">
              <i class="ri-logout-box-line"></i> Sign out
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- ---------- SCROLLABLE CONTENT ---------- -->
    <div class="content-scroll">
      <div class="content">

        <!-- Page header -->
        <div class="page-head">
          <div>
            <p class="eyebrow">Training Needs Assessment</p>
            <h2>Training Pipeline</h2>
            <p>Pooled training demand and employee-requested trainings, ready for your action.</p>
          </div>
          <div class="date-chip">
            <i class="ri-calendar-2-line"></i>
            <span><?= date('l, F j, Y') ?></span>
          </div>
        </div>

        <!-- ===========================================================
             TRAINING DEMAND — HR aggregation pipeline (cross-college,
             ranked by requester count). See ml_recommendations.php for
             the training_demand schema, get_demand_detail.php,
             forward_training_demand.php and approve_training_demand.php.
             =========================================================== -->
        <section class="card" style="margin-top:1.15rem;">
          <div class="card-head">
            <h3>
              <i class="ri-stack-line"></i> Training Demand
              <?php if ($pendingDemandCount > 0): ?>
                <span class="badge badge-pending num"><?= $pendingDemandCount ?> pending review</span>
              <?php endif; ?>
            </h3>
            <button type="button" class="btn btn-ghost btn-sm" onclick="showTrainingNeedsSummary()">
              <i class="ri-file-chart-line"></i> Summary Report
            </button>
          </div>
          <div class="card-body">
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">
              Trainings employees across all colleges have accepted, ranked by how many people requested each one. Forward a request to the relevant dean(s) to source a paid provider, then approve once they report back.
            </p>
            <?php if (!empty($trainingDemandRows)): ?>
              <div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-bottom:0.8rem;flex-wrap:wrap;">
                <select id="demandOriginFilter" onchange="applyDemandFilterAndPagination()"
                        style="padding:0.5rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.82rem;color:var(--ink-2);background:var(--surface);cursor:pointer;">
                  <option value="">All Origins</option>
                  <option value="Recommended">Recommended (AI/Catalog)</option>
                  <option value="Requested">Requested (Employee)</option>
                  <option value="Dean-Posted">Dean-Posted</option>
                </select>
                <select id="demandStatusFilter" onchange="applyDemandFilterAndPagination()"
                        style="padding:0.5rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.82rem;color:var(--ink-2);background:var(--surface);cursor:pointer;">
                  <option value="">All Statuses (Active)</option>
                  <option value="Pending HR Review">Awaiting Your Review</option>
                  <option value="Forwarded to Dean">Forwarded to Dean</option>
                  <option value="Training Found">Training Found</option>
                  <option value="HR Approved">Approved</option>
                  <option value="Confirmed">Confirmed</option>
                  <option value="Training Completed">Completed (Archived)</option>
                  <option value="No Training Found">No Training Found (Archived)</option>
                  <option value="Closed">Closed, No One Eligible (Archived)</option>
                </select>
              </div>
              <div class="table-wrap">
                <table class="data">
                  <thead>
                    <tr><th>Training</th><th>Origin</th><th>Type</th><th>Requesters</th><th>Sourcing</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
                  </thead>
                  <tbody id="demandTableBody">
                    <?php foreach ($trainingDemandRows as $d): ?>
                      <tr data-status="<?= htmlspecialchars($d['pipeline_status']) ?>" data-origin="<?= htmlspecialchars($d['origin']) ?>">
                        <td class="strong">
                          <?= htmlspecialchars(demandDisplayTitle($d)) ?>
                          <?php if (!empty($d['found_training_title'])): ?>
                            <br><span style="font-size:0.72rem;font-weight:400;color:var(--muted);" title="What this started as, before a real training was found">Originally requested as: <?= htmlspecialchars($d['title']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php
                            // 2026-09-06 design pass - this used to be a filled
                            // pill in the SAME indigo as badge-info (which the
                            // Status column also uses for "Forwarded to Dean",
                            // and Sourcing used for "Sourced by Dean") - three
                            // different columns all reaching for the same
                            // color in one row reads as visual noise, not
                            // information. Status is the one thing HR actually
                            // needs to scan quickly, so it keeps the filled
                            // badges; Origin and Sourcing (below) are now
                            // plain colored text with an icon - present, but
                            // quiet, so Status is what the eye lands on first.
                            $originColor = [
                                'Requested'    => 'var(--accent)',
                                'Dean-Posted'  => '#a16207',
                                'Recommended'  => 'var(--muted)',
                            ][$d['origin']];
                            $originIcon = [
                                'Requested'   => 'ri-chat-quote-line',
                                'Dean-Posted' => 'ri-megaphone-line',
                                'Recommended' => 'ri-robot-2-line',
                            ][$d['origin']];
                            $originTitle = [
                                'Requested'   => "This didn't come from an AI-recommended catalog title - an employee typed it in directly, and it got pooled with others asking for the same idea.",
                                'Dean-Posted' => 'The dean found and posted this directly - no employee request started it.',
                                'Recommended' => 'The AI recommended this from the training catalog, and enough employees Accepted it to pool into a demand.',
                            ][$d['origin']];
                          ?>
                          <span style="color:<?= $originColor ?>;font-weight:600;font-size:0.85rem;white-space:nowrap;" title="<?= htmlspecialchars($originTitle) ?>">
                            <i class="<?= $originIcon ?>"></i> <?= htmlspecialchars($d['origin']) ?>
                          </span>
                        </td>
                        <td><?= $d['training_type'] ? htmlspecialchars($d['training_type']) : '<span style="color:var(--muted);font-style:italic;">To be determined</span>' ?></td>
                        <td class="num">
                          <?= (int)$d['requester_count'] ?>
                          <?php if ($d['origin'] === 'Dean-Posted'): ?>
                            <br><span style="font-size:0.7rem;font-weight:400;color:var(--muted);" title="Nobody requested this - the dean posted it and these people signed up afterward.">interested</span>
                          <?php else: ?>
                            <br><span style="font-size:0.7rem;font-weight:400;color:var(--muted);">requester<?= (int)$d['requester_count'] !== 1 ? 's' : '' ?></span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($d['has_dean']): ?>
                            <span style="color:var(--ink-2);font-size:0.85rem;white-space:nowrap;" title="A dean is involved in sourcing this - at least one requester belongs to a college with a dean account.">
                              <i class="ri-shield-user-line" style="color:var(--muted);"></i> Sourced by Dean
                            </span>
                          <?php else: ?>
                            <span style="color:var(--ink-2);font-size:0.85rem;white-space:nowrap;" title="No requester belongs to a college with a dean account (e.g. non-teaching/central office staff), so HR sources this one directly.">
                              <i class="ri-user-star-line" style="color:var(--muted);"></i> Sourced by HR
                            </span>
                          <?php endif; ?>
                        </td>
                        <td><span class="badge <?= demandStatusBadgeClass($d['pipeline_status']) ?>"><?= htmlspecialchars(demandStatusLabel($d['pipeline_status'])) ?></span></td>
                        <td style="text-align:center;">
                          <button class="btn btn-ghost btn-sm" onclick="showDemandDetail(<?= $d['id'] ?>)">
                            <i class="ri-eye-line"></i> View
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <div id="demandNoMatches" class="empty" style="display:none;">
                  <i class="ri-filter-off-line"></i>
                  <h4>No matches</h4>
                  <p>No training demand has this status right now.</p>
                </div>
              </div>
              <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
                <button class="btn btn-ghost btn-sm" onclick="changeDemandPage(-1)"><i class="ri-arrow-left-line"></i> Prev</button>
                <span id="demandPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
                <button class="btn btn-ghost btn-sm" onclick="changeDemandPage(1)">Next <i class="ri-arrow-right-line"></i></button>
              </div>
            <?php else: ?>
              <div class="empty">
                <i class="ri-inbox-line"></i>
                <h4>No training demand yet</h4>
                <p>Once employees start accepting AI-recommended trainings, requests will pool here.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ===========================================================
             EMPLOYEE-REQUESTED TRAININGS (2026-09-01) - free-text
             requests submitted from training_recommendations.php when
             none of the AI-suggested catalog titles fit. See
             get_custom_training_requests.php, clusterCustomTrainingRequests()
             in ml_recommendations.php, and promote_custom_request_cluster.php.
             =========================================================== -->
        <section class="card" style="margin-top:1.15rem;">
          <div class="card-head">
            <h3>
              <i class="ri-chat-quote-line"></i> Employee-Requested Trainings
              <span class="badge badge-pending num" id="customRequestBadge" style="display:none;"></span>
            </h3>
            <button type="button" class="btn btn-ghost btn-sm" onclick="openCustomRequestsModal()">
              <i class="ri-eye-line"></i> View
            </button>
          </div>
          <div class="card-body">
            <p style="font-size:0.85rem;color:var(--muted);">
              Specific trainings employees typed in themselves, for when none of the AI's suggestions fit. Group similar requests together by idea (not just shared words) to see which one has the most real demand.
            </p>
          </div>
        </section>


      </div>
    </div>
  </div>
</div>

<!-- =============================================================
     AUDIT LOG MODAL
     ============================================================= -->
<div class="modal" id="customRequestsModal" role="dialog" aria-modal="true" aria-labelledby="customRequestsTitle">
  <div class="modal-box" style="max-width:1040px;">
    <div class="modal-head">
      <h3 id="customRequestsTitle"><i class="ri-chat-quote-line"></i> Employee-Requested Trainings</h3>
      <button class="modal-x" onclick="closeModal('customRequestsModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.6rem;">
        <p style="font-size:0.8rem;color:var(--muted);margin:0;">Every raw submission is listed below. Group them by idea to see which ones repeat.</p>
        <button class="btn btn-primary btn-sm" id="runClusteringBtn" onclick="runCustomRequestClustering()">
          <i class="ri-robot-2-line"></i> Group Similar Requests
        </button>
      </div>

      <!-- 2026-09-06 - was a stack of cards with an internal scrollbar,
           which the user found both hard to scan (a paragraph of names
           instead of a table) and awkward to scroll through. Now a plain
           paginated table, "View" opens clusterDetailModal for the actual
           per-person breakdown + the promote action. -->
      <div id="customClustersSection" style="display:none;margin-bottom:1.4rem;">
        <p class="verify-label" style="font-family:inherit;font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);margin-bottom:0.6rem;">Grouped by Idea (Most Requested First)</p>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Idea</th><th>Department</th><th>Requests</th><th>Notes</th><th style="text-align:center;">Actions</th></tr></thead>
            <tbody id="customClustersBody"><!-- rendered from the last clustering run --></tbody>
          </table>
        </div>
        <div id="clustersNoMatches" class="empty" style="display:none;">
          <i class="ri-checkbox-circle-line"></i><h4>Nothing pending</h4><p>Every request has already been promoted.</p>
        </div>
        <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
          <button class="btn btn-ghost btn-sm" onclick="changeClusterPage(-1)"><i class="ri-arrow-left-line"></i> Prev</button>
          <span id="clusterPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
          <button class="btn btn-ghost btn-sm" onclick="changeClusterPage(1)">Next <i class="ri-arrow-right-line"></i></button>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem;flex-wrap:wrap;gap:0.6rem;">
        <p class="verify-label" style="font-family:inherit;font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);margin:0;">All Raw Submissions</p>
        <div style="display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap;">
          <!-- 2026-09-06 - already-promoted submissions used to sit
               permanently mixed in here alongside ones still needing
               review, which is the wrong default for what's really an
               inbox of things to act on - a promoted one already has its
               full story tracked on the Training Demand board, so it
               doesn't need to keep cluttering this list. Hidden by
               default, one click away for anyone who wants to audit it. -->
          <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--muted);cursor:pointer;white-space:nowrap;">
            <input type="checkbox" id="rawShowPromoted" onchange="changeRawPage(0)"> Show already-promoted
          </label>
          <select id="rawDeptFilter" onchange="changeRawPage(0)" style="padding:0.4rem 0.7rem;border:1px solid var(--line);border-radius:8px;font-size:0.8rem;color:var(--ink-2);background:var(--surface);">
            <option value="">All Departments</option>
          </select>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Employee</th><th>Department</th><th>Request</th><th>Status</th><th>Submitted</th></tr></thead>
          <tbody id="customRequestsRawBody"><!-- async rows --></tbody>
        </table>
      </div>
      <div id="rawNoMatches" class="empty" style="display:none;">
        <i class="ri-filter-off-line"></i><h4>No matches</h4><p>Nothing matches these filters - try a different department, or check "Show already-promoted".</p>
      </div>
      <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
        <button class="btn btn-ghost btn-sm" onclick="changeRawPage(-1)"><i class="ri-arrow-left-line"></i> Prev</button>
        <span id="rawPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
        <button class="btn btn-ghost btn-sm" onclick="changeRawPage(1)">Next <i class="ri-arrow-right-line"></i></button>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('customRequestsModal')">Close</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · CLUSTER DETAIL — who requested this idea + promote
     (2026-09-06 - replaces the inline paragraph-of-names + inline form
     that used to live directly on the cluster card).
     ============================================================= -->
<div class="modal" id="clusterDetailModal" role="dialog" aria-modal="true" aria-labelledby="clusterDetailTitle">
  <div class="modal-box" style="max-width:760px;">
    <div class="modal-head">
      <h3 id="clusterDetailTitle"><i class="ri-lightbulb-line"></i> Idea Details</h3>
      <button class="modal-x" onclick="closeModal('clusterDetailModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <div class="table-wrap" style="margin-bottom:1.2rem;">
        <table class="data">
          <thead><tr><th>Employee</th><th>Department</th><th>Request (their own words)</th></tr></thead>
          <tbody id="clusterDetailRequesters"><!-- rendered from the selected cluster --></tbody>
        </table>
      </div>

      <label for="clusterDetailLabel" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">
        Idea / Demand title
      </label>
      <input type="text" id="clusterDetailLabel" style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.88rem;font-weight:600;box-sizing:border-box;">
      <p id="clusterDetailRawHint" style="display:none;margin-top:0.4rem;font-size:0.78rem;color:var(--muted);">
        <i class="ri-information-line"></i> Only 1 requester - the title above is their raw wording. Consider tightening it into a proper training name before promoting.
      </p>

      <!-- 2026-09-06 - was an alarming yellow warning box; this is genuinely
           just a heads-up/suggestion, not a problem, so it gets the same
           calm, neutral treatment as the "What was found" info card
           elsewhere in this modal system, not a warning color. -->
      <div id="clusterDetailMatch" style="display:none;margin-top:0.8rem;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:12px;padding:0.8rem 1rem;">
        <p style="font-size:0.78rem;color:var(--ink-2);margin:0 0 0.5rem;">
          <i class="ri-search-eye-line" style="color:var(--accent);"></i> This looks similar to an existing <span id="clusterDetailMatchSource"></span>:
          <strong id="clusterDetailMatchTitle"></strong>
        </p>
        <button type="button" class="btn btn-ghost btn-sm" id="clusterDetailUseMatchBtn">Use this title instead</button>
      </div>

      <p id="clusterDetailIncompleteHint" style="display:none;margin-top:0.8rem;font-size:0.78rem;color:var(--muted);">
        <i class="ri-wifi-off-line"></i> The AI service dropped out while grouping this college's requests - this one may not be fully grouped. Safe to promote as-is, or try "Group Similar Requests" again in a moment.
      </p>

      <!-- 2026-09-06 - Training Type deliberately removed from this modal.
           This is still just an idea/topic - nobody actually knows if the
           real training that eventually gets found will be a workshop or
           a seminar until a real provider/session exists. That gets
           chosen in report_training_demand.php instead, when a dean or HR
           reports what they actually found. -->
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('clusterDetailModal')">Close</button>
      <button class="btn btn-primary" id="clusterDetailPromoteBtn" onclick="promoteCustomClusterFromModal(this)">
        <i class="ri-arrow-right-circle-line"></i> Promote to Demand
      </button>
    </div>
  </div>
</div>

<!-- =============================================================
     TOAST STACK — flash messages render here (see JS bootstrap)
     ============================================================= -->
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<!-- =============================================================
     MODAL · PENDING REGISTRATIONS
     ============================================================= -->
<div class="modal" id="demandDetailModal" role="dialog" aria-modal="true" aria-labelledby="demandDetailTitle">
  <div class="modal-box" style="max-width:920px;">
    <div class="modal-head">
      <h3 id="demandDetailTitle"><i class="ri-stack-line"></i> <span id="demandDetailName">Loading…</span></h3>
      <button class="modal-x" onclick="closeDemandModal()" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>

    <div class="modal-body">
      <div style="display:flex;flex-wrap:wrap;gap:0.8rem;justify-content:space-between;align-items:center;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:12px;padding:0.9rem 1.1rem;margin-bottom:1.2rem;">
        <div style="display:flex;flex-direction:column;gap:0.3rem;">
          <p style="font-size:0.82rem;color:var(--muted);">Status: <span id="demandDetailStatus" class="badge badge-pending" style="font-weight:600;">—</span></p>
          <p style="font-size:0.82rem;color:var(--muted);">Requesters: <span id="demandDetailCount" class="num" style="font-weight:600;color:var(--ink-2);">—</span></p>
        </div>
      </div>

      <div id="demandBudgetHintField" style="display:none;margin-bottom:1.2rem;">
        <label for="demandBudgetHintInput" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">
          Budget guidance for the dean <span style="font-weight:400;color:var(--muted);">(optional)</span>
        </label>
        <input type="text" id="demandBudgetHintInput" placeholder="e.g. around ₱50,000, or leave blank if not yet known"
               style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;">
        <p style="font-size:0.74rem;color:var(--muted);margin-top:0.3rem;">Shown to the dean in their notification. A firm number often isn't known until they've actually talked to a provider - leave blank if you don't have one yet.</p>
      </div>

      <div class="table-wrap" style="margin-bottom:1.2rem;">
        <table class="data">
          <thead><tr><th>Name</th><th>Department</th><th>Status</th><th id="demandProofColumnHeader" style="display:none;">Proof of Completion</th><th></th></tr></thead>
          <tbody id="demandRequestersBody"><!-- async rows --></tbody>
        </table>
      </div>

      <div id="demandNotSelectedSection" style="display:none;margin-bottom:1.2rem;">
        <p style="font-size:0.76rem;color:var(--muted);margin-bottom:0.4rem;">
          <i class="ri-user-unfollow-line"></i> Not selected this round - closed out, no action needed:
        </p>
        <div id="demandNotSelectedBody" style="display:flex;flex-direction:column;gap:0.3rem;"></div>
      </div>

      <!-- 2026-09-15 - people whose Accepted row was cancelled because no
           training could be sourced at all (see reject_training_demand.php)
           - kept out of the main requesters table for the same reason Not
           Selected is: sitting next to still-active rows with a dash in
           the Proof column reads as "still waiting on something." -->
      <div id="demandCancelledSection" style="display:none;margin-bottom:1.2rem;">
        <p style="font-size:0.76rem;color:var(--bad-ink);margin-bottom:0.4rem;">
          <i class="ri-close-circle-line"></i> Cancelled - no training could be found:
        </p>
        <div id="demandCancelledBody" style="display:flex;flex-direction:column;gap:0.3rem;"></div>
      </div>

      <div id="demandCancellationDetails" style="display:none;background:var(--bad-soft);border:1px solid var(--bad-ink);border-radius:12px;padding:0.9rem 1.1rem;margin-bottom:1.2rem;">
        <p id="demandCancelledByLabel" style="font-size:0.95rem;font-weight:700;color:var(--bad-ink);margin:0 0 0.4rem;"><i class="ri-close-circle-line"></i> No Training Found</p>
        <p id="demandCancellationReason" style="font-size:0.88rem;color:var(--ink-2);margin:0;"></p>
      </div>

      <div id="demandFoundDetails" style="display:none;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:12px;padding:0.9rem 1.1rem;margin-bottom:1.2rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
          <p id="demandFoundByLabel" style="font-size:0.95rem;font-weight:700;color:var(--ink-2);margin:0;">What was found</p>
          <button id="demandEditFoundBtn" class="btn btn-ghost" style="display:none;padding:0.3rem 0.7rem;font-size:0.76rem;" onclick="openEditFoundModal()">
            <i class="ri-edit-2-line"></i> Edit
          </button>
        </div>
        <!-- 2026-09-06 - was a single-column table (one field per full-width
             row), which is exactly the "long modal" the user asked to
             broaden - same 2-column treatment as the Report Found /
             Post Opportunity forms now use. Every id is unchanged, this
             only restructures the container the JS already fills in. -->
        <div id="demandFoundStructured" style="display:none;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.7rem 1.5rem;font-size:1rem;">
            <div><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Provider</span><span id="demandFoundProvider"></span></div>
            <div><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Cost</span><span id="demandFoundCost"></span></div>
            <div style="grid-column:1/-1;"><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Date(s)</span><span id="demandFoundDates"></span></div>
            <div id="demandFoundTimeRow"><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Time</span><span id="demandFoundTime"></span></div>
            <div id="demandFoundDurationRow"><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Duration</span><span id="demandFoundDuration"></span></div>
            <div><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Capacity</span><span id="demandFoundCapacity"></span></div>
            <div id="demandFoundVenueRow"><span id="demandFoundVenueLabel" style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Venue/Format</span><span id="demandFoundVenue"></span></div>
            <div id="demandFoundModalityRow"><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Modality</span><span id="demandFoundModality"></span></div>
            <div id="demandFoundNotesRow" style="grid-column:1/-1;"><span style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.03em;">Notes</span><span id="demandFoundNotes"></span></div>
          </div>
          <p id="demandFoundUpdatedNote" style="display:none;font-size:0.74rem;color:var(--muted);margin:0.5rem 0 0;"></p>
        </div>
        <p id="demandFoundDetailsText" style="font-size:0.85rem;color:var(--ink-2);"></p>
      </div>

      <div id="demandShortlistSection" style="display:none;">
        <p style="font-size:0.78rem;font-weight:700;color:var(--ink-2);margin-bottom:0.4rem;">
          <i class="ri-robot-2-line" style="color:var(--accent);"></i> AI Shortlist: Pick Who to Confirm
        </p>
        <p id="demandCapacityNote" style="display:none;font-size:0.8rem;font-weight:600;color:var(--accent);margin-bottom:0.4rem;"></p>
        <p style="font-size:0.78rem;color:var(--muted);margin-bottom:0.7rem;">
          Ranked by years of service, prior trainings already confirmed, and whether this training was officially requested by the college in a Training Needs Assessment (or, if a college has no official submission on file, whether colleagues in the same college have asked for something similar in their own assessments). This is decision support only. You make the final call.
        </p>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th style="width:2rem;"></th><th>Name</th><th>Department</th><th>Tenure (yrs)</th><th>Prior Trainings</th><th>Score</th></tr></thead>
            <tbody id="demandShortlistBody"><!-- async rows --></tbody>
          </table>
        </div>
        <div id="demandExcludedSection" style="display:none;margin-top:0.9rem;">
          <p style="font-size:0.76rem;color:var(--muted);margin-bottom:0.4rem;">
            <i class="ri-forbid-line"></i> Excluded: Already Completed a Similar Training
          </p>
          <div id="demandExcludedBody" style="display:flex;flex-direction:column;gap:0.4rem;"></div>
        </div>
      </div>
    </div>

    <div class="modal-foot">
      <span id="demandWaitingNote" style="display:none;margin-right:auto;font-size:0.82rem;color:var(--muted);align-items:center;gap:0.4rem;"></span>
      <button class="btn btn-ghost" onclick="closeDemandModal()">Close</button>
      <button id="demandForwardBtn" class="btn btn-primary" onclick="forwardDemand()" style="display:none;">
        <i class="ri-send-plane-line"></i> Forward to Dean(s)
      </button>
      <button id="demandSearchProvidersBtn" class="btn btn-ghost" onclick="openSearchProvidersModal()" style="display:none;">
        <i class="ri-search-eye-line"></i> Search for Providers
      </button>
      <button id="demandReportDirectBtn" class="btn btn-primary" onclick="openReportDirectModal()" style="display:none;">
        <i class="ri-file-edit-line"></i> Report Training Found
      </button>
      <button id="demandRejectDirectBtn" class="btn btn-danger" onclick="openRejectDemandModal()" style="display:none;">
        <i class="ri-close-circle-line"></i> No Training Found
      </button>
      <button id="demandApproveBtn" class="btn btn-success" onclick="approveDemand()" style="display:none;">
        <i class="ri-check-double-line"></i> Approve and Notify Requesters
      </button>
      <button id="demandConfirmBtn" class="btn btn-success" onclick="confirmSelectedParticipants()" style="display:none;">
        <i class="ri-user-star-line"></i> Confirm Selected
      </button>
      <button id="demandCloseDeadEndBtn" class="btn btn-danger" onclick="closeDeadEndDemand()" style="display:none;">
        <i class="ri-close-circle-line"></i> Close (No One Eligible)
      </button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · REPORT TRAINING FOUND DIRECTLY (HR self-report, 2026-08-29)
     Native .modal/.modal-box, matching every other modal in this file -
     NOT SweetAlert2. Rebuilt from a SweetAlert2 popup after live testing
     showed it rendering with the library's own generic default look,
     which never actually matched this file's real modal language (this
     file uses its own toast-stack + .modal system everywhere else -
     SweetAlert2 was never loaded here before this feature, and adding it
     just for one popup was the wrong call in the first place).
     ============================================================= -->
<div class="modal" id="reportDirectModal" role="dialog" aria-modal="true" aria-labelledby="reportDirectTitle">
  <div class="modal-box" style="max-width:760px;">
    <div class="modal-head">
      <h3 id="reportDirectTitle"><i class="ri-file-edit-line"></i> Report Training Found</h3>
      <button class="modal-x" onclick="closeModal('reportDirectModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <p id="reportDirectDemandTitle" style="font-size:0.85rem;color:var(--muted);margin-bottom:1.2rem;"></p>

      <!-- 2026-09-06 - widened + 2-column, same treatment as the dean-side
           Report Found / Post Opportunity forms, plus this was missing
           Training Type / free-training / actual-title entirely - a real
           gap: report_training_demand.php now REQUIRES training_type
           unconditionally (see that file's 2026-09-06 comment), so this
           form's "Report Training Found" submissions (the non-edit path)
           would have started failing validation the moment that change
           shipped, since this parallel HR-side form never sent it. -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1.2rem;">
        <div style="grid-column:1/-1;">
          <label for="reportDirectFoundTitle" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Actual Training Title <span style="font-weight:400;color:var(--muted);">(optional - fill in if the real training has a different, more specific name)</span></label>
          <input type="text" id="reportDirectFoundTitle" autocomplete="off" placeholder="e.g. BFAR Regional Aquaculture Innovation Seminar 2026"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <div>
          <label for="reportDirectProvider" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Training Provider</label>
          <input type="text" id="reportDirectProvider" autocomplete="off" placeholder="e.g. XYZ Institute"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <div>
          <label for="reportDirectCost" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Cost</label>
          <input type="text" id="reportDirectCost" autocomplete="off" placeholder="e.g. ₱4,000/head"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
          <label style="display:flex;align-items:center;gap:0.4rem;margin-top:0.4rem;font-size:0.78rem;color:var(--ink-2);cursor:pointer;">
            <input type="checkbox" id="reportDirectIsFree" onchange="document.getElementById('reportDirectCost').disabled=this.checked; if(this.checked) document.getElementById('reportDirectCost').value='';"> This training is free
          </label>
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Date(s)</label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <input type="date" id="reportDirectDateStart" autocomplete="off"
                   onchange="document.getElementById('reportDirectDateEnd').min=this.value"
                   style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
            <span style="color:var(--muted);font-size:0.78rem;flex-shrink:0;">to</span>
            <input type="date" id="reportDirectDateEnd" autocomplete="off"
                   style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
          </div>
          <p style="font-size:0.72rem;color:var(--muted);margin:0.3rem 0 0;">Leave "to" blank for a single-day training.</p>
        </div>
        <div>
          <label for="reportDirectStartTime" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Start Time <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <input type="time" id="reportDirectStartTime" oninput="checkReportDirectTimeValidity()"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <div>
          <label for="reportDirectEndTime" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">End Time <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <input type="time" id="reportDirectEndTime" oninput="checkReportDirectTimeValidity()"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <p id="reportDirectTimeFeedback" style="grid-column:1/-1;font-size:0.78rem;margin:-0.35rem 0 0;"></p>
        <div>
          <label for="reportDirectCapacity" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Capacity (max people)</label>
          <input type="number" id="reportDirectCapacity" min="1" autocomplete="off" placeholder="e.g. 10"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <div>
          <label for="reportDirectVenue" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;"><span id="reportDirectVenueLabelText">Venue / Platform</span> <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <input type="text" id="reportDirectVenue" autocomplete="off" placeholder="e.g. LSPU Main Campus, or Zoom/Google Meet"
                 style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;box-sizing:border-box;">
        </div>
        <div>
          <label for="reportDirectModality" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Modality</label>
          <select id="reportDirectModality" onchange="updateReportDirectVenueLabel(this.value)" style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;background:var(--surface);box-sizing:border-box;">
            <option value="">Select modality</option>
            <option value="Face-to-Face">Face-to-Face</option>
            <option value="Online">Online</option>
          </select>
        </div>
        <div>
          <label for="reportDirectTrainingType" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Training Type</label>
          <select id="reportDirectTrainingType" style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;background:var(--surface);box-sizing:border-box;">
            <option value="">Select training type</option>
            <option value="Workshop">Workshop</option>
            <option value="Seminar">Seminar</option>
            <option value="Webinar">Webinar</option>
            <option value="Conference">Conference</option>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label for="reportDirectNotes" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Additional Notes <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <textarea id="reportDirectNotes" rows="2" placeholder="Anything else worth noting"
                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"
                    style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;resize:none;overflow-y:auto;max-height:160px;box-sizing:border-box;"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('reportDirectModal')">Cancel</button>
      <button id="reportDirectSaveBtn" class="btn btn-primary" onclick="submitReportDirect()">
        <i class="ri-save-line"></i> Save
      </button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · NO TRAINING FOUND (HR self-report reject, 2026-09-15)
     Counterpart to reportDirectModal above - HR's own equivalent of the
     dean-side "No Training Found" button (see CCS_Training_Demand.php and
     its 12 siblings), for the case where NO dean exists to review this
     demand in the first place (see reject_training_demand.php's $isHr
     branch). Native .modal, same convention as every other modal here.
     ============================================================= -->
<div class="modal" id="rejectDemandModal" role="dialog" aria-modal="true" aria-labelledby="rejectDemandTitle">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-head">
      <h3 id="rejectDemandTitle"><i class="ri-close-circle-line"></i> No Training Found</h3>
      <button class="modal-x" onclick="closeModal('rejectDemandModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <p id="rejectDemandDemandTitle" style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;"></p>
      <p style="font-size:0.85rem;color:var(--ink-2);margin-bottom:0.9rem;">Every requester on this demand will be notified that it was cancelled, along with the reason below.</p>
      <label for="rejectDemandReason" style="font-size:0.78rem;font-weight:700;color:var(--ink-2);display:block;margin-bottom:0.3rem;">Reason <span style="font-weight:400;color:var(--muted);">(shown to the requesters)</span></label>
      <textarea id="rejectDemandReason" rows="3" placeholder="e.g. No available provider offers this in Region IV-A, and no online equivalent was found either."
                style="width:100%;padding:0.6rem 0.8rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;resize:none;overflow-y:auto;max-height:160px;box-sizing:border-box;"></textarea>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('rejectDemandModal')">Cancel</button>
      <button id="rejectDemandSaveBtn" class="btn btn-danger" onclick="submitRejectDemand()">
        <i class="ri-close-circle-line"></i> Confirm - No Training Found
      </button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · SEARCH FOR PROVIDERS (2026-09-16) - HR's own version of the
     research-assist button on each college dean dashboard. Read-only, no
     state change - see search_training_providers.php's header comment for
     the full reasoning (real suggestion from a CFND faculty respondent,
     and why this can't just be an open web search).
     ============================================================= -->
<div class="modal" id="searchProvidersModal" role="dialog" aria-modal="true" aria-labelledby="searchProvidersTitle">
  <div class="modal-box" style="max-width:640px;">
    <div class="modal-head">
      <h3 id="searchProvidersTitle"><i class="ri-search-eye-line"></i> Search for Providers</h3>
      <button class="modal-x" onclick="closeModal('searchProvidersModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <div id="searchProvidersBody"><p style="font-size:0.85rem;color:var(--muted);">Searching real training-provider sites...</p></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('searchProvidersModal')">Close</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · GENERIC CONFIRM DIALOG (2026-08-31) - a reusable, on-brand
     replacement for the browser's native confirm(), which renders as a
     generic "localhost says" box with no styling control at all. Used
     wherever code needs a real Continue?/Cancel decision with an
     explanation, not just a one-line yes/no. See confirmDialog() below.
     ============================================================= -->
<div class="modal" id="confirmActionModal" role="dialog" aria-modal="true" aria-labelledby="confirmActionTitle">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-head">
      <h3 id="confirmActionTitle"><i class="ri-question-line"></i> <span id="confirmActionTitleText">Confirm</span></h3>
      <button class="modal-x" onclick="resolveConfirmDialog(false)" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <p id="confirmActionMessage" style="font-size:0.88rem;color:var(--ink-2);line-height:1.55;white-space:pre-line;"></p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="resolveConfirmDialog(false)">Cancel</button>
      <button id="confirmActionOkBtn" class="btn btn-primary" onclick="resolveConfirmDialog(true)">Continue</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · TRAINING NEEDS SUMMARY (live replacement for the old
     yearly manually-compiled report — see get_training_needs_summary.php
     and training_needs_summary_pdf.php)
     ============================================================= -->
<div class="modal" id="trainingNeedsSummaryModal" role="dialog" aria-modal="true" aria-labelledby="trainingNeedsSummaryTitle">
  <div class="modal-box" style="max-width:900px;">
    <div class="modal-head">
      <h3 id="trainingNeedsSummaryTitle"><i class="ri-file-chart-line"></i> Training Needs Summary</h3>
      <button class="modal-x" onclick="closeModal('trainingNeedsSummaryModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>

    <div class="modal-body">
      <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem;">
        A live view of what employees across every college have requested, ranked by demand. This replaces the old, manually compiled yearly report.
      </p>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Training</th><th>Type</th><th>Requesters</th><th>Requested By</th><th>Status</th></tr></thead>
          <tbody id="trainingNeedsSummaryBody"><!-- async rows --></tbody>
        </table>
      </div>
    </div>

    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('trainingNeedsSummaryModal')">Close</button>
      <a href="training_needs_summary_pdf.php" target="_blank" class="btn btn-primary" style="text-decoration:none;">
        <i class="ri-printer-line"></i> Print / Export PDF
      </a>
    </div>
  </div>
</div>

<script>
/* ============================================================
   A. TOAST SYSTEM
   ============================================================ */
const ToastIcons = { ok: 'ri-checkbox-circle-line', warn: 'ri-error-warning-line', err: 'ri-close-circle-line', info: 'ri-information-line' };
const ToastTitles = { ok: 'Success', warn: 'Heads up', err: 'Something went wrong', info: 'Notice' };

/**
 * Push a toast onto the stack.
 * @param {'ok'|'warn'|'err'|'info'} type
 * @param {string} title  short heading
 * @param {string} msg    body text
 * @param {number} ttl    auto-dismiss in ms (default 5000)
 */
function showToast(type, title, msg, ttl = 5000) {
  type = (type in ToastIcons) ? type : 'info';
  const stack = document.getElementById('toastStack');
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

/* Map PHP message_type → toast type. */
function mapType(t) { return t === 'success' ? 'ok' : (t === 'error' ? 'err' : (t === 'warning' ? 'warn' : 'info')); }

/* ============================================================
   C. SIDEBAR — collapse (desktop) + drawer (mobile)
   ============================================================ */
function toggleRailCollapse() { document.getElementById('app').classList.toggle('rail-collapsed'); }
function openMobileRail()  { document.getElementById('app').classList.add('rail-open'); }
function closeMobileRail() { document.getElementById('app').classList.remove('rail-open'); }

/* Show the desktop collapse button only on wider viewports. */
function syncCollapseButton() {
  const btn = document.getElementById('collapseBtn');
  if (!btn) return;
  btn.style.display = window.innerWidth > 900 ? 'inline-flex' : 'none';
  if (window.innerWidth > 900) closeMobileRail();   // reset drawer when leaving mobile
}

/* ============================================================
   D. DROPDOWNS
   ============================================================ */
function toggleDropdown(id) {
  const target = document.getElementById(id);
  // Close other open dropdowns first.
  document.querySelectorAll('.dropdown.open').forEach(d => { if (d !== target) d.classList.remove('open'); });
  target.classList.toggle('open');
}
function closeAllDropdowns() { document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open')); }

/* ============================================================
   E. MODALS
   ============================================================ */
function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
// closeAllModals() used to live here as a plain "close everything" helper,
// called only from the Escape-key handler below - inlined there instead
// (2026-08-31) since Escape now needs to skip reportDirectModal specifically,
// which a shared close-everything helper can't express without a param.

// On-brand replacement for the browser's native confirm() (2026-08-31) -
// that renders as an unstyled "localhost says" box with zero styling
// control, which looked out of place next to the rest of this dashboard's
// modal system. Promise-based so call sites can just `await confirmDialog(...)`
// in place of the old synchronous `confirm(...)` call, same true/false
// result. opts: { title, okText, danger } - danger swaps the OK button to
// the destructive/red style already used elsewhere in this file.
let _confirmDialogResolve = null;
function confirmDialog(message, opts = {}) {
  document.getElementById('confirmActionTitleText').textContent = opts.title || 'Confirm';
  document.getElementById('confirmActionMessage').textContent = message;
  const okBtn = document.getElementById('confirmActionOkBtn');
  okBtn.textContent = opts.okText || 'Continue';
  okBtn.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
  openModal('confirmActionModal');
  return new Promise(resolve => { _confirmDialogResolve = resolve; });
}
function resolveConfirmDialog(result) {
  closeModal('confirmActionModal');
  if (_confirmDialogResolve) {
    const resolve = _confirmDialogResolve;
    _confirmDialogResolve = null;
    resolve(result);
  }
}

/* ============================================================
   K1b. TRAINING DEMAND CARD — client-side status filter + pagination.
   The dataset here is small (dozens of rows, not thousands), so this
   filters/paginates rows already rendered server-side rather than adding
   a new paginated backend endpoint - the PDF export (training_needs_summary_pdf.php)
   is a fully separate script and always includes everything regardless
   of this filter/page state.
   ============================================================ */
let demandCurrentPage = 1;
const DEMAND_PER_PAGE = 5;

function applyDemandFilterAndPagination() {
  const tbody = document.getElementById('demandTableBody');
  if (!tbody) return;
  const filter = document.getElementById('demandStatusFilter').value;
  const originFilter = document.getElementById('demandOriginFilter').value;
  const allRows = Array.from(tbody.querySelectorAll('tr'));

  // "All Statuses (active)" deliberately excludes both terminal states -
  // Training Completed and Closed are archived out of the default view,
  // not deleted; pick the "(archived)" option to see them.
  const archivedStatuses = ['Training Completed', 'Closed', 'No Training Found'];
  const matching = allRows.filter(row =>
    (filter ? row.dataset.status === filter : !archivedStatuses.includes(row.dataset.status)) &&
    (!originFilter || row.dataset.origin === originFilter)
  );
  const totalPages = Math.max(1, Math.ceil(matching.length / DEMAND_PER_PAGE));
  if (demandCurrentPage > totalPages) demandCurrentPage = totalPages;
  if (demandCurrentPage < 1) demandCurrentPage = 1;

  const start = (demandCurrentPage - 1) * DEMAND_PER_PAGE;
  const end = start + DEMAND_PER_PAGE;

  allRows.forEach(row => row.style.display = 'none');
  matching.forEach((row, i) => {
    row.style.display = (i >= start && i < end) ? '' : 'none';
  });

  document.getElementById('demandPageIndicator').textContent = `Page ${demandCurrentPage} of ${totalPages}`;
  document.getElementById('demandNoMatches').style.display = matching.length === 0 ? 'block' : 'none';
  tbody.closest('.table-wrap').style.display = matching.length === 0 ? 'none' : '';
}

function changeDemandPage(delta) {
  demandCurrentPage += delta;
  applyDemandFilterAndPagination();
}

/* ============================================================
   K2. TRAINING DEMAND MODAL — async load, forward, approve
   ============================================================ */
let currentDemandId = 0;
let currentDemandFoundData = null; // last-loaded found_* fields, for prefilling the Edit modal
let reportDirectMode = 'report'; // 'report' (Report Training Found) or 'edit' (HR editing an already-found training)

// Formats DB TIME values ("HH:MM:SS" or null) into "1:00 PM - 4:00 PM"
// style text; returns '' if neither time is set.
function formatTimeRange(start, end) {
  const fmt = (t) => {
    if (!t) return null;
    const [h, m] = t.split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
  };
  const s = fmt(start), e = fmt(end);
  if (s && e) return `${s} - ${e}`;
  return s || e || '';
}
// Derives a human-readable duration ("4 hrs", "1 hr 30 mins") from two DB
// TIME values (2026-09-01) - shown as its own line next to Time, not
// merged into it, since Time is a fact and Duration is a computed
// convenience. Returns '' if either time is missing or the range is zero/negative.
function formatDuration(start, end) {
  if (!start || !end) return '';
  const toMinutes = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const mins = toMinutes(end) - toMinutes(start);
  if (mins <= 0) return '';
  const hrs = Math.floor(mins / 60), rem = mins % 60;
  const parts = [];
  if (hrs > 0) parts.push(`${hrs} hr${hrs !== 1 ? 's' : ''}`);
  if (rem > 0) parts.push(`${rem} min${rem !== 1 ? 's' : ''}`);
  return parts.join(' ');
}
// Set true whenever an action inside the modal actually changes a demand's
// state (forward/approve/confirm). The modal then refreshes itself in
// place (see closeDemandModal below) rather than closing+reloading the
// whole page after every single action - that was forcing HR to click
// View a second time just to see the AI Shortlist appear right after
// approving, a genuinely redundant extra step.
let demandDataChanged = false;

function closeDemandModal() {
  closeModal('demandDetailModal');
  if (demandDataChanged) {
    location.reload(); // keeps the dashboard's status badges/table in sync
  }
}

// Mirrors PHP's demandStatusLabel() - display-only wording for the HR/
// admin account's own view, see that function's comment for why.
function demandStatusLabel(status) {
  const labels = {
    'Pending HR Review': 'Awaiting Your Review',
    'HR Approved': 'Approved',
    'Training Completed': 'Completed',
  };
  return labels[status] || status;
}

function showDemandDetail(demandId) {
  currentDemandId = demandId;
  document.getElementById('demandDetailName').textContent = 'Loading…';
  document.getElementById('demandDetailStatus').textContent = '—';
  document.getElementById('demandDetailCount').textContent = '—';
  document.getElementById('demandRequestersBody').innerHTML = '';
  document.getElementById('demandProofColumnHeader').style.display = 'none';
  document.getElementById('demandNotSelectedSection').style.display = 'none';
  document.getElementById('demandNotSelectedBody').innerHTML = '';
  document.getElementById('demandCancelledSection').style.display = 'none';
  document.getElementById('demandCancelledBody').innerHTML = '';
  document.getElementById('demandCancellationDetails').style.display = 'none';
  document.getElementById('demandFoundDetails').style.display = 'none';
  document.getElementById('demandFoundStructured').style.display = 'none';
  document.getElementById('demandBudgetHintField').style.display = 'none';
  document.getElementById('demandBudgetHintInput').value = '';
  document.getElementById('demandCapacityNote').style.display = 'none';
  document.getElementById('demandForwardBtn').style.display = 'none';
  document.getElementById('demandSearchProvidersBtn').style.display = 'none';
  document.getElementById('demandReportDirectBtn').style.display = 'none';
  document.getElementById('demandRejectDirectBtn').style.display = 'none';
  document.getElementById('demandWaitingNote').style.display = 'none';
  document.getElementById('demandApproveBtn').style.display = 'none';
  document.getElementById('demandConfirmBtn').style.display = 'none';
  document.getElementById('demandCloseDeadEndBtn').style.display = 'none';
  document.getElementById('demandShortlistSection').style.display = 'none';
  document.getElementById('demandEditFoundBtn').style.display = 'none';
  currentDemandFoundData = null;
  document.getElementById('demandShortlistBody').innerHTML = '';
  document.getElementById('demandExcludedSection').style.display = 'none';
  document.getElementById('demandExcludedBody').innerHTML = '';
  openModal('demandDetailModal');

  fetch(`get_demand_detail.php?id=${demandId}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showToast('err', 'Load failed', data.message || 'Could not load this demand.');
        return;
      }
      document.getElementById('demandDetailName').textContent = data.demand.found_training_title || data.demand.title;
      const statusEl = document.getElementById('demandDetailStatus');
      statusEl.textContent = demandStatusLabel(data.demand.pipeline_status);
      statusEl.className = 'badge ' + data.demand.status_badge_class;
      // Break down by status instead of one flat number - "4 requesters"
      // reads as "4 people still need something," but the count spans the
      // whole lifecycle including people who already finished (a real
      // confusion point found during manual testing, 2026-08-28). Not
      // Selected people are counted separately, not folded into this
      // breakdown, since they're shown in their own section below, not
      // this table - see get_demand_detail.php.
      const statusTally = {};
      data.requesters.forEach(r => { statusTally[r.status] = (statusTally[r.status] || 0) + 1; });
      const breakdown = Object.entries(statusTally).map(([status, count]) => `${count} ${status}`).join(', ');
      const totalCount = data.requesters.length + data.not_selected.length;
      const notSelectedNote = data.not_selected.length > 0 ? `, ${data.not_selected.length} Not Selected` : '';
      document.getElementById('demandDetailCount').textContent = totalCount + (breakdown ? ` (${breakdown}${notSelectedNote})` : notSelectedNote.replace(/^, /, ''));

      // Proof of Completion only means anything once someone has actually
      // reached Confirmed/Completed - showing an always-dash column
      // before that reads as something to check on people who haven't
      // even been picked yet (a real confusion point found 2026-08-28).
      const anyProofRelevant = data.requesters.some(r => r.status === 'Confirmed' || r.status === 'Completed');
      document.getElementById('demandProofColumnHeader').style.display = anyProofRelevant ? '' : 'none';

      const tbody = document.getElementById('demandRequestersBody');
      if (data.requesters.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5"><div class="empty"><i class="ri-inbox-line"></i><h4>No active requesters</h4></div></td></tr>`;
      } else {
        tbody.innerHTML = data.requesters.map(r => `
          <tr><td class="strong">${r.name}</td><td>${r.department}</td><td><span class="badge ${requesterStatusBadgeClass(r.status)}">${r.status}</span></td>${anyProofRelevant ? `<td>${renderProofLinks(r)}</td>` : ''}<td>${r.status === 'Confirmed' ? `<button class="btn btn-ghost" style="padding:0.25rem 0.6rem;font-size:0.74rem;" title="Remove from the confirmed list" onclick="unconfirmParticipant(${r.recommendation_id}, '${r.name.replace(/'/g, "\\'")}')"><i class="ri-user-unfollow-line"></i> Bump</button>` : ''}</td></tr>
        `).join('');
      }

      if (data.not_selected.length > 0) {
        document.getElementById('demandNotSelectedSection').style.display = 'block';
        document.getElementById('demandNotSelectedBody').innerHTML = data.not_selected.map(r => `
          <div style="display:flex;justify-content:space-between;align-items:center;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:10px;padding:0.6rem 0.9rem;font-size:0.82rem;">
            <span>${r.name} <span style="color:var(--muted);">(${r.department})</span></span>
          </div>
        `).join('');
      }

      if (data.cancelled.length > 0) {
        document.getElementById('demandCancelledSection').style.display = 'block';
        document.getElementById('demandCancelledBody').innerHTML = data.cancelled.map(r => `
          <div style="display:flex;justify-content:space-between;align-items:center;background:var(--bad-soft);border:1px solid var(--line-soft);border-radius:10px;padding:0.6rem 0.9rem;font-size:0.82rem;">
            <span>${r.name} <span style="color:var(--muted);">(${r.department})</span></span>
          </div>
        `).join('');
      }

      if (data.demand.pipeline_status === 'No Training Found') {
        document.getElementById('demandCancellationDetails').style.display = 'block';
        const cancelledByName = data.demand.cancelled_by_name;
        if (cancelledByName) {
          const isDean = (data.demand.cancelled_by_role || '').startsWith('admin_');
          const roleTag = data.demand.cancelled_by_role === 'admin' ? 'HR' : isDean ? 'Dean' : '';
          document.getElementById('demandCancelledByLabel').innerHTML = `<i class="ri-close-circle-line"></i> No Training Found - reported by ${cancelledByName}` + (roleTag ? ` (${roleTag})` : '');
        } else {
          document.getElementById('demandCancelledByLabel').innerHTML = '<i class="ri-close-circle-line"></i> No Training Found';
        }
        document.getElementById('demandCancellationReason').textContent = data.demand.cancellation_reason || '';
      }

      // Structured fields (provider/cost/dates/capacity) if this was
      // reported after 2026-08-28; falls back to the old free-text blob
      // for anything reported before that, so old demands still display.
      if (data.demand.found_provider) {
        document.getElementById('demandFoundDetails').style.display = 'block';
        document.getElementById('demandFoundStructured').style.display = 'block';
        document.getElementById('demandFoundDetailsText').style.display = 'none';
        // 2026-09-06 - shows the actual person's name now, not just a
        // generic "the dean"/"HR" guess - real transparency for HR asking
        // "who actually found/posted this". Falls back to the old generic
        // wording only for legacy rows with no found_by_user_id on file.
        if (data.demand.found_by_name) {
          const isDean = (data.demand.found_by_role || '').startsWith('admin_');
          const roleTag = data.demand.found_by_role === 'admin' ? 'HR' : isDean ? 'Dean' : '';
          // Department only makes sense for a dean (HR isn't tied to one
          // college), and only shown if the account actually has one on file.
          const deptTag = (isDean && data.demand.found_by_department) ? `, ${data.demand.found_by_department}` : '';
          document.getElementById('demandFoundByLabel').textContent = `Found by ${data.demand.found_by_name}` + (roleTag ? ` (${roleTag}${deptTag})` : '');
        } else {
          document.getElementById('demandFoundByLabel').textContent = data.has_dean ? 'What the dean found' : 'What HR found';
        }
        document.getElementById('demandFoundProvider').textContent = data.demand.found_provider;
        document.getElementById('demandFoundCost').textContent = data.demand.found_cost || '—';
        document.getElementById('demandFoundDates').textContent = data.demand.found_dates || '—';
        const timeRow = document.getElementById('demandFoundTimeRow');
        const timeRange = formatTimeRange(data.demand.found_start_time, data.demand.found_end_time);
        timeRow.style.display = timeRange ? '' : 'none';
        document.getElementById('demandFoundTime').textContent = timeRange || '';
        const durationRow = document.getElementById('demandFoundDurationRow');
        const durationText = formatDuration(data.demand.found_start_time, data.demand.found_end_time);
        durationRow.style.display = durationText ? '' : 'none';
        document.getElementById('demandFoundDuration').textContent = durationText || '';
        document.getElementById('demandFoundCapacity').textContent = data.demand.found_capacity ? `${data.demand.found_capacity} people` : '—';
        const venueRow = document.getElementById('demandFoundVenueRow');
        venueRow.style.display = data.demand.found_venue ? '' : 'none';
        document.getElementById('demandFoundVenue').textContent = data.demand.found_venue || '';
        document.getElementById('demandFoundVenueLabel').textContent =
          data.demand.actual_modality === 'Online' ? 'Platform / Meeting Link' :
          data.demand.actual_modality === 'Face-to-Face' ? 'Venue' : 'Venue/Format';
        const modalityRow = document.getElementById('demandFoundModalityRow');
        modalityRow.style.display = data.demand.actual_modality ? '' : 'none';
        document.getElementById('demandFoundModality').textContent = data.demand.actual_modality || '';
        const notesRow = document.getElementById('demandFoundNotesRow');
        notesRow.style.display = data.demand.found_notes ? '' : 'none';
        document.getElementById('demandFoundNotes').textContent = data.demand.found_notes || '';

        // Editable by HR after the fact (2026-08-31) - date/time/venue
        // adjustments are common once a provider confirms details, and
        // requesters need to see the update, not just HR.
        const updatedNote = document.getElementById('demandFoundUpdatedNote');
        if (data.demand.found_updated_at) {
          updatedNote.style.display = 'block';
          updatedNote.innerHTML = `<i class="ri-history-line"></i> Last updated ${new Date(data.demand.found_updated_at.replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } else {
          updatedNote.style.display = 'none';
        }
        currentDemandFoundData = data.demand;
        document.getElementById('demandEditFoundBtn').style.display = 'inline-flex';
      } else if (data.demand.found_training_details) {
        document.getElementById('demandFoundDetails').style.display = 'block';
        document.getElementById('demandFoundDetailsText').style.display = 'block';
        document.getElementById('demandFoundDetailsText').textContent = data.demand.found_training_details;
      }

      if (data.demand.pipeline_status === 'Pending HR Review') {
        // No dean exists for any requester's college (e.g. every
        // requester is in the central ADMIN office) - forwarding would
        // silently reach nobody, so HR reports the found training
        // directly instead of routing through a dean that doesn't exist.
        if (data.has_dean) {
          document.getElementById('demandForwardBtn').style.display = 'inline-flex';
          document.getElementById('demandBudgetHintField').style.display = 'block';
        } else {
          document.getElementById('demandSearchProvidersBtn').style.display = 'inline-flex';
          document.getElementById('demandReportDirectBtn').style.display = 'inline-flex';
          document.getElementById('demandRejectDirectBtn').style.display = 'inline-flex';
        }
      } else if (data.demand.pipeline_status === 'Forwarded to Dean') {
        // Purely informational - confirms the forward genuinely went
        // through (2026-08-31: a real case where the forward succeeded
        // server-side but the confirmation toast didn't reach the user,
        // leaving no visible sign anything had happened once they
        // reopened the modal and just saw no action button).
        const note = document.getElementById('demandWaitingNote');
        note.style.display = 'flex';
        note.innerHTML = '<i class="ri-time-line"></i> Forwarded - waiting for the dean to report back.';
      } else if (data.demand.pipeline_status === 'Training Found') {
        document.getElementById('demandApproveBtn').style.display = 'inline-flex';
      } else if (data.demand.pipeline_status === 'HR Approved' || data.demand.pipeline_status === 'Confirmed') {
        // Still show the shortlist once attendees are Confirmed too - a
        // seat can open back up (someone declines after being picked) and
        // HR needs to be able to pull from the remaining pool, not just
        // during the single moment the demand sat at "HR Approved".
        loadDemandShortlist(demandId);
      }
    })
    .catch(() => showToast('err', 'Load failed', 'Could not load this demand.'));
}

function showTrainingNeedsSummary() {
  document.getElementById('trainingNeedsSummaryBody').innerHTML = `<tr><td colspan="5"><div class="empty"><i class="ri-loader-4-line"></i><h4>Loading…</h4></div></td></tr>`;
  openModal('trainingNeedsSummaryModal');

  fetch('get_training_needs_summary.php')
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showToast('err', 'Load failed', data.message || 'Could not load the summary.');
        return;
      }
      const tbody = document.getElementById('trainingNeedsSummaryBody');
      if (data.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5"><div class="empty"><i class="ri-inbox-line"></i><h4>Nothing requested yet</h4></div></td></tr>`;
        return;
      }
      const badgeClass = {
        'Pending HR Review': 'badge-pending',
        'Forwarded to Dean': 'badge-info',
        'Training Found': 'badge-on-time',
        'No Training Found': 'badge-declined',
        'HR Approved': 'badge-accepted',
        'Confirmed': 'badge-accepted',
        'Training Completed': 'badge-on-time',
        'Closed': 'badge-no-sub',
      };
      tbody.innerHTML = data.rows.map(r => `
        <tr>
          <td class="strong">${escapeHtml(r.title)}</td>
          <td>${r.training_type ? escapeHtml(r.training_type) : '<span style="color:var(--muted);font-style:italic;">To be determined</span>'}</td>
          <td class="num">${r.total}</td>
          <td>${escapeHtml(r.breakdown)}</td>
          <td><span class="badge ${badgeClass[r.pipeline_status] || 'badge-info'}">${demandStatusLabel(r.pipeline_status)}</span></td>
        </tr>
      `).join('');
    })
    .catch(() => showToast('err', 'Load failed', 'Could not load the summary.'));
}

function proofChip(path, icon, label) {
  return `<a href="uploads/training_proofs/${encodeURIComponent(path)}" target="_blank" rel="noopener"
             style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.65rem;margin:0.15rem 0.25rem 0.15rem 0;
                    background:var(--accent-soft);border:1px solid #c7d2fe;border-radius:999px;font-size:0.72rem;font-weight:600;
                    color:var(--accent);text-decoration:none;white-space:nowrap;">
            <i class="${icon}"></i> ${label}
          </a>`;
}

function requesterStatusBadgeClass(status) {
  const map = {
    'Accepted': 'badge-pending',
    'Training Available': 'badge-info',
    'Confirmed': 'badge-accepted',
    'Completed': 'badge-on-time',
    'Not Selected': 'badge-no-sub',
    'Cancelled': 'badge-declined',
  };
  return map[status] || 'badge-info';
}

function renderProofLinks(r) {
  const chips = [];
  if (r.proof_certificate_path) chips.push(proofChip(r.proof_certificate_path, 'ri-award-line', 'Certificate'));
  if (r.proof_approval_letter_path) chips.push(proofChip(r.proof_approval_letter_path, 'ri-file-text-line', 'Approval Letter'));
  if (r.proof_program_path) chips.push(proofChip(r.proof_program_path, 'ri-calendar-event-line', 'Program'));
  if (chips.length === 0) return '<span style="color:var(--muted);font-style:italic;">Not yet submitted</span>';
  const hours = r.proof_hours ? `<span style="font-size:0.72rem;color:var(--muted);margin-left:0.2rem;">${r.proof_hours} hrs</span>` : '';
  return `<div style="display:flex;flex-wrap:wrap;align-items:center;">${chips.join('')}${hours}</div>`;
}

let currentShortlistCapacity = null;

// Actually blocks over-capacity confirmation instead of just warning about
// it (2026-08-28 finding: the warning note alone didn't stop HR from
// checking more people than the reported capacity - all 3 got confirmed
// against a capacity of 2). Once as many boxes are checked as there's
// room for, every other checkbox greys out; unchecking one re-opens a slot.
function enforceShortlistCapacity() {
  if (!currentShortlistCapacity) return;
  const boxes = Array.from(document.querySelectorAll('.shortlist-check'));
  const checkedCount = boxes.filter(cb => cb.checked).length;
  boxes.forEach(cb => {
    cb.disabled = !cb.checked && checkedCount >= currentShortlistCapacity;
  });
}

function loadDemandShortlist(demandId) {
  fetch(`get_demand_shortlist.php?demand_id=${demandId}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      const section = document.getElementById('demandShortlistSection');
      const tbody = document.getElementById('demandShortlistBody');
      const excludedSection = document.getElementById('demandExcludedSection');
      const excludedBody = document.getElementById('demandExcludedBody');

      if (data.shortlist.length === 0 && data.excluded.length === 0) {
        section.style.display = 'none';
        return;
      }

      section.style.display = 'block';

      currentShortlistCapacity = data.capacity || null;
      const capacityNote = document.getElementById('demandCapacityNote');
      if (data.capacity) {
        const eligible = data.shortlist.length;
        capacityNote.style.display = 'block';
        capacityNote.innerHTML = eligible > data.capacity
          ? `<i class="ri-error-warning-line"></i> Capacity is ${data.capacity}, and ${eligible} people are eligible. You can select up to ${data.capacity}; the rest will be marked Not Selected.`
          : `<i class="ri-checkbox-circle-line"></i> Capacity is ${data.capacity}, with ${eligible} eligible. There is room for everyone.`;
      }
      // A demand-level dead end (everyone who requested it already got
      // hard-excluded, capacity.total_requesters > 0 but shortlist is
      // empty) gets a "Close" action instead of just an empty table - see
      // close_training_demand.php. Distinct from "nobody requested this at
      // all" (total_requesters === 0), which just shows nothing to do.
      const deadEnd = data.shortlist.length === 0 && (data.total_requesters || 0) > 0;
      currentDemandDeadEnd = deadEnd ? demandId : null;
      // The actual close action now lives as a normal-sized button in the
      // modal footer, next to Close/Approve/Confirm - it used to render as
      // an oversized button inline in this empty state, which looked out
      // of proportion with everything else in the modal (2026-09-01).
      document.getElementById('demandCloseDeadEndBtn').style.display = deadEnd ? 'inline-flex' : 'none';

      tbody.innerHTML = data.shortlist.length === 0
        ? `<tr><td colspan="6"><div class="empty compact">
             <i class="ri-inbox-line"></i>
             <h4>Everyone eligible was already excluded</h4>
             ${deadEnd ? `<p style="font-size:0.8rem;color:var(--muted);margin:0.4rem 0 0;">Every requester already has an equivalent training on file. There's no one left HR could confirm for this - use "Close (No One Eligible)" below.</p>` : ''}
           </div></td></tr>`
        : data.shortlist.map(s => `
          <tr>
            <td><input type="checkbox" class="shortlist-check" value="${s.recommendation_id}"></td>
            <td class="strong">${s.name}<br><span style="font-size:0.72rem;color:var(--muted);">${s.designation}</span></td>
            <td>${s.department}</td>
            <td class="num">${s.tenure_years}</td>
            <td class="num">${s.prior_confirmed_count}</td>
            <td class="num" style="font-weight:700;color:var(--accent);">
              ${s.score}
              <!-- 2026-09-07 fix - was hardcoded "2025 TNA" (badge text
                   and tooltip both) - factually wrong the moment 2024/2027
                   data was added, since the exact colleges that data was
                   added for (CCS, CHMT, COF) submitted nothing in 2025
                   specifically. tna_2025_demand pools every year together
                   per college with no per-title year tracking, so no
                   single year can be named accurately here. -->
              ${s.tna_requested && s.tna_source === 'official' ? `<br><span class="badge badge-info" style="font-size:0.62rem;font-weight:600;" title="A related training was officially requested by this college in a Training Needs Assessment">Official TNA</span>` : ''}
              ${s.tna_requested && s.tna_source === 'self_reported' ? `<br><span class="badge badge-info" style="font-size:0.62rem;font-weight:600;" title="No official TNA submission on file for this college - based on what colleagues here have asked for in their own assessments instead">Colleagues Asked</span>` : ''}
              ${s.related_flag ? `<br><span class="badge badge-late" style="font-size:0.62rem;font-weight:600;" title="${s.related_flag} - shown for context only, still eligible">Related training on file</span>` : ''}
            </td>
          </tr>
        `).join('');

      document.querySelectorAll('.shortlist-check').forEach(cb => cb.addEventListener('change', enforceShortlistCapacity));
      enforceShortlistCapacity(); // in case capacity is already 0 or something is pre-checked

      if (data.excluded.length > 0) {
        excludedSection.style.display = 'block';
        excludedBody.innerHTML = data.excluded.map(e => `
          <div style="background:var(--surface-2);border:1px solid var(--line-soft);border-radius:10px;padding:0.6rem 0.8rem;font-size:0.8rem;">
            <span class="strong">${e.name}</span> (${e.department}): ${e.excluded_reason}
          </div>
        `).join('');
      } else {
        excludedSection.style.display = 'none';
      }

      if (data.shortlist.length > 0) {
        document.getElementById('demandConfirmBtn').style.display = 'inline-flex';
      }
    })
    .catch(() => showToast('err', 'Load failed', 'Could not load the AI shortlist.'));
}

let currentDemandDeadEnd = null;

async function closeDeadEndDemand() {
  if (!currentDemandDeadEnd) return;
  const proceed = await confirmDialog(
    'Everyone still pending will be marked "Not Selected" and notified that it will not be pursued further this round. This cannot be undone from here.',
    { title: 'Close This Demand?', okText: 'Close Demand', danger: true }
  );
  if (!proceed) return;

  fetch('close_training_demand.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `demand_id=${currentDemandDeadEnd}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('ok', 'Closed', `Demand closed. ${data.closed_count} requester(s) notified.`);
        demandDataChanged = true;
        showDemandDetail(currentDemandId); // refresh in place - status flips to "Closed"
      } else {
        showToast('err', 'Failed', data.message || 'Could not close this demand.');
        if (currentDemandId) loadDemandShortlist(currentDemandId); // re-sync in case eligibility changed
      }
    })
    .catch(() => showToast('err', 'Failed', 'Could not close this demand.'));
}

async function confirmSelectedParticipants() {
  const allChecks = Array.from(document.querySelectorAll('.shortlist-check'));
  const selected = allChecks.filter(cb => cb.checked).map(cb => cb.value);
  if (selected.length === 0) {
    showToast('warn', 'Nothing selected', 'Check at least one person to confirm.');
    return;
  }

  // Confirming is final for this round - anyone shortlisted but left
  // unchecked is marked "Not Selected" and notified, not left hanging
  // indefinitely (see confirm_training_participant.php). Make that
  // consequence explicit before it happens, since it can't be undone
  // from this button.
  const leftoverCount = allChecks.length - selected.length;
  if (leftoverCount > 0) {
    const proceed = await confirmDialog(
      `You're confirming ${selected.length} of ${allChecks.length} shortlisted people.\n\n` +
      `The other ${leftoverCount} will be marked "Not Selected" and notified they didn't make this round.`,
      { title: 'Confirm Selected Attendees?', okText: 'Confirm Selected' }
    );
    if (!proceed) return;
  }

  const body = selected.map(id => `recommendation_ids[]=${id}`).join('&');
  fetch('confirm_training_participant.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const extra = data.not_selected_count > 0 ? `, ${data.not_selected_count} marked Not Selected` : '';
        showToast('ok', 'Confirmed', `${data.confirmed_count} participant(s) confirmed and notified${extra}.`);
        demandDataChanged = true;
        showDemandDetail(currentDemandId); // refresh in place - shortlist shrinks to reflect who's left
      } else {
        showToast('err', 'Failed', data.message || 'Could not confirm participants.');
      }
    })
    .catch(() => showToast('err', 'Failed', 'Could not confirm participants.'));
}

// "Bump" a Confirmed participant back out (2026-08-31) - the gap the user
// found: once someone is Confirmed there was no way back, so a capacity
// correction (or any other reason HR needs to free a seat) had nowhere to
// go. Moves them to 'Not Selected' - the same terminal state used for
// anyone who didn't make the original cut - so they show up in the same
// place and can be pulled back into consideration the same way (see
// edit_training_demand_found.php's capacity-increase reopening below).
async function unconfirmParticipant(recommendationId, name) {
  const proceed = await confirmDialog(
    `They'll be notified and moved to "Not Selected" - they can be reconsidered later if a spot opens up.`,
    { title: `Remove ${name} From the Confirmed List?`, okText: 'Remove', danger: true }
  );
  if (!proceed) return;

  fetch('unconfirm_training_participant.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `recommendation_id=${recommendationId}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        let msg = `${name} has been notified and moved to Not Selected.`;
        if (data.reopened_count > 0) {
          msg += ` ${data.reopened_count} previously not-selected person(s) are back in the pool since a seat opened up.`;
        }
        showToast('ok', 'Removed', msg);
        demandDataChanged = true;
        showDemandDetail(currentDemandId);
      } else {
        showToast('err', 'Failed', data.message || 'Could not remove this participant.');
      }
    })
    .catch(() => showToast('err', 'Failed', 'Could not remove this participant.'));
}

function forwardDemand() {
  const budgetHint = document.getElementById('demandBudgetHintInput').value.trim();
  fetch('forward_training_demand.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `demand_id=${currentDemandId}&budget_hint=${encodeURIComponent(budgetHint)}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('ok', 'Forwarded', 'Sent to the relevant dean(s).');
      } else {
        showToast('err', 'Failed', data.message || 'Could not forward this demand.');
      }
      // Refresh regardless of the reported outcome (2026-08-31) - a real
      // case surfaced where this call succeeded on the server (status
      // genuinely flipped to "Forwarded to Dean") but the client-side
      // promise still reported failure, leaving the modal showing stale,
      // pre-forward state with no visible sign anything had happened.
      // Always reloading here means the UI can never drift from ground
      // truth, whatever caused that mismatch.
      demandDataChanged = true;
      showDemandDetail(currentDemandId);
    })
    .catch(() => {
      // 2026-08-31: this specific failure mode (fetch's own promise
      // rejecting/failing to parse) has shown up twice now with the write
      // itself always having actually succeeded server-side - confirmed
      // both times via direct DB check, and ruled out PHP-side causes
      // (no php_error_log entries, Apache uptime unbroken across both
      // occurrences) - so it's a local network/connection hiccup between
      // browser and Apache on this dev machine, not an app bug. Worded as
      // "confirming" rather than "failed" since the true outcome is
      // almost always success; the refresh below shows the real status
      // either way.
      showToast('warn', 'Checking...', 'That took a moment - confirming what actually happened.');
      demandDataChanged = true;
      showDemandDetail(currentDemandId);
    });
}

// HR self-report (2026-08-29) - for a demand with no dean to forward to
// (see demandReportDirectBtn above). Same structured fields as the dean-
// facing openReportDemandModal() in every college file, posting to the
// same report_training_demand.php endpoint, which now accepts this role
// for exactly this case. Native .modal, not SweetAlert2 - rebuilt after
// live testing showed the SweetAlert2 version rendering with the
// library's own generic default look, which never actually matched
// this file's real modal language (this file uses its own toast-stack
// + .modal system everywhere else; SweetAlert2 wasn't loaded here at
// all until this one feature added it, which was the wrong call).
// Live time validation + auto-computed duration (2026-08-31) - catches
// the classic 8:00 AM / 12:00 AM mix-up (midnight, not noon) the moment
// it's typed rather than only after clicking Save. Disables the Save
// button while the range is invalid; submitReportDirect() re-checks the
// same thing as a submit-time guard in case the button state was somehow
// bypassed (e.g. pressing Enter), and report_training_demand.php /
// edit_training_demand_found.php both already reject it server-side too.
function checkReportDirectTimeValidity() {
  const start = document.getElementById('reportDirectStartTime').value;
  const end = document.getElementById('reportDirectEndTime').value;
  const feedback = document.getElementById('reportDirectTimeFeedback');
  const saveBtn = document.getElementById('reportDirectSaveBtn');

  if (!start || !end) {
    feedback.textContent = '';
    saveBtn.disabled = false;
    return true;
  }

  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const diffMinutes = (eh * 60 + em) - (sh * 60 + sm);

  if (diffMinutes <= 0) {
    feedback.textContent = 'Invalid - end time must be after start time (check for an AM/PM mix-up, e.g. 12 AM is midnight, not noon).';
    feedback.style.color = 'var(--bad, #e11d48)';
    saveBtn.disabled = true;
    return false;
  }

  const hours = Math.floor(diffMinutes / 60);
  const minutes = diffMinutes % 60;
  const parts = [];
  if (hours > 0) parts.push(`${hours} hour${hours !== 1 ? 's' : ''}`);
  if (minutes > 0) parts.push(`${minutes} minute${minutes !== 1 ? 's' : ''}`);
  feedback.textContent = `Duration: ${parts.join(' ')}`;
  feedback.style.color = 'var(--ok, #16a34a)';
  saveBtn.disabled = false;
  return true;
}

// 2026-09-01: "Venue/Format" used to double as a free-text way to say
// "this is Online" before the Actual Modality field existed - now that
// modality is a real, separate field, asking for a physical venue makes
// no sense once someone picks Online. This adapts the label/placeholder
// to match what was actually picked, instead of leaving a physical-venue
// field on screen for a training that isn't in a physical place.
function updateReportDirectVenueLabel(modality) {
  const labelText = document.getElementById('reportDirectVenueLabelText');
  const input = document.getElementById('reportDirectVenue');
  if (!labelText || !input) return;
  if (modality === 'Online') {
    labelText.textContent = 'Platform / Meeting Link';
    input.placeholder = 'e.g. Zoom, Google Meet (link once scheduled)';
  } else if (modality === 'Face-to-Face') {
    labelText.textContent = 'Venue';
    input.placeholder = 'e.g. LSPU Main Campus';
  } else {
    labelText.textContent = 'Venue / Platform';
    input.placeholder = 'e.g. LSPU Main Campus, or Zoom/Google Meet';
  }
}

// Same date-range formatting as the 13 college dean modals
// (openReportDemandModal in each *_admin/*.php) - kept here as its own
// copy rather than a shared include, matching this codebase's existing
// convention of small per-context helpers over a cross-file JS module.
function formatReportDateRange(startStr, endStr) {
  if (!startStr) return '';
  const opts = { year: 'numeric', month: 'long', day: 'numeric' };
  const start = new Date(startStr + 'T00:00:00');
  if (!endStr || endStr === startStr) {
    return start.toLocaleDateString('en-US', opts);
  }
  const end = new Date(endStr + 'T00:00:00');
  if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
    return `${start.toLocaleDateString('en-US', { month: 'long' })} ${start.getDate()}-${end.getDate()}, ${start.getFullYear()}`;
  }
  return `${start.toLocaleDateString('en-US', opts)} - ${end.toLocaleDateString('en-US', opts)}`;
}

// Best-effort reverse of the above, for pre-filling the picker when
// editing a demand whose found_dates was set before this feature existed
// (or typed as free text some other way) - found_dates is stored as one
// plain string, not separate start/end columns, so this can only ever be
// a best guess. Falls back to leaving the pickers blank (forcing a real
// re-entry) rather than guessing wrong silently.
function parseFoundDatesForEdit(str) {
  if (!str) return { start: '', end: '' };
  const toIsoDate = (s) => {
    const d = new Date(s.trim());
    if (isNaN(d.getTime())) return '';
    // 2026-09-06 fix - .toISOString() converts to UTC first, which shifts
    // the date back a day in any timezone ahead of UTC (the Philippines is
    // UTC+8) - "September 16, 2026" parses as local midnight, which is
    // still "September 15" in UTC, so this was silently off by one day
    // for exactly the timezone this system actually runs in. Building the
    // string from the LOCAL date parts instead avoids the UTC round-trip.
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };
  // Cross-month/year range: "September 16, 2026 - October 2, 2026"
  if (str.includes(' - ')) {
    const [a, b] = str.split(' - ');
    const start = toIsoDate(a);
    const end = toIsoDate(b);
    if (start) return { start, end: end || '' };
  }
  // 2026-09-06 fix - the OTHER range shape formatReportDateRange() can
  // produce, "September 16-20, 2026" (same month/year, so the month and
  // year are only written once, no spaces around the hyphen), was never
  // handled here at all - it fell through to new Date() on the whole
  // string, which doesn't throw, it just silently parses to some
  // unrelated nonsense date (a real bug found live: pre-filled the Edit
  // modal with 09/15/2020 for what was actually Sept 16-20, 2026).
  const compactRangeMatch = str.match(/^([A-Za-z]+)\s+(\d{1,2})-(\d{1,2}),\s*(\d{4})$/);
  if (compactRangeMatch) {
    const [, month, startDay, endDay, year] = compactRangeMatch;
    const start = toIsoDate(`${month} ${startDay}, ${year}`);
    const end = toIsoDate(`${month} ${endDay}, ${year}`);
    if (start) return { start, end: end || '' };
  }
  const single = toIsoDate(str);
  return single ? { start: single, end: '' } : { start: '', end: '' };
}

function openReportDirectModal() {
  reportDirectMode = 'report';
  document.getElementById('reportDirectTitle').innerHTML = '<i class="ri-file-edit-line"></i> Report Training Found';
  document.getElementById('reportDirectSaveBtn').innerHTML = '<i class="ri-save-line"></i> Save';
  document.getElementById('reportDirectDemandTitle').textContent = document.getElementById('demandDetailName').textContent;
  ['reportDirectFoundTitle', 'reportDirectProvider', 'reportDirectCost', 'reportDirectDateStart', 'reportDirectDateEnd', 'reportDirectStartTime', 'reportDirectEndTime', 'reportDirectCapacity', 'reportDirectVenue', 'reportDirectModality', 'reportDirectTrainingType', 'reportDirectNotes'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('reportDirectIsFree').checked = false;
  document.getElementById('reportDirectCost').disabled = false;
  const todayStr = new Date().toISOString().split('T')[0];
  document.getElementById('reportDirectDateStart').min = todayStr;
  document.getElementById('reportDirectDateEnd').min = todayStr;
  document.getElementById('reportDirectNotes').style.height = 'auto';
  updateReportDirectVenueLabel('');
  document.getElementById('reportDirectTimeFeedback').textContent = '';
  document.getElementById('reportDirectSaveBtn').disabled = false;
  openModal('reportDirectModal');
}

// HR editing an already-found training (2026-08-31) - reuses the same
// modal/fields/validation as the initial report, just pre-filled and
// posting to edit_training_demand_found.php instead, which doesn't touch
// pipeline_status and notifies affected requesters of the change.
function openEditFoundModal() {
  if (!currentDemandFoundData) return;
  reportDirectMode = 'edit';
  const d = currentDemandFoundData;
  document.getElementById('reportDirectTitle').innerHTML = '<i class="ri-edit-2-line"></i> Edit Found Training Details';
  document.getElementById('reportDirectSaveBtn').innerHTML = '<i class="ri-save-line"></i> Save Changes';
  document.getElementById('reportDirectDemandTitle').textContent = document.getElementById('demandDetailName').textContent;
  document.getElementById('reportDirectFoundTitle').value = d.found_training_title || '';
  document.getElementById('reportDirectProvider').value = d.found_provider || '';
  document.getElementById('reportDirectIsFree').checked = !!(+d.is_free);
  document.getElementById('reportDirectCost').disabled = !!(+d.is_free);
  document.getElementById('reportDirectCost').value = (+d.is_free) ? '' : (d.found_cost || '');
  document.getElementById('reportDirectTrainingType').value = d.training_type || '';
  const parsedDates = parseFoundDatesForEdit(d.found_dates || '');
  document.getElementById('reportDirectDateStart').value = parsedDates.start;
  document.getElementById('reportDirectDateEnd').value = parsedDates.end;
  document.getElementById('reportDirectDateEnd').min = parsedDates.start || '';
  document.getElementById('reportDirectStartTime').value = (d.found_start_time || '').slice(0, 5);
  document.getElementById('reportDirectEndTime').value = (d.found_end_time || '').slice(0, 5);
  document.getElementById('reportDirectCapacity').value = d.found_capacity || '';
  document.getElementById('reportDirectVenue').value = d.found_venue || '';
  document.getElementById('reportDirectModality').value = d.actual_modality || '';
  updateReportDirectVenueLabel(d.actual_modality || '');
  document.getElementById('reportDirectNotes').value = d.found_notes || '';
  openModal('reportDirectModal');
  // 2026-09-06 fix - this used to measure scrollHeight and resize the
  // textarea BEFORE openModal() ran, while the modal (and everything in
  // it) was still display:none. scrollHeight on a hidden element reads
  // as 0, so the textarea's height got set to ~0px while still holding
  // real text - the text then visually spilled out past the collapsed
  // box the instant the modal became visible (the "overflowing notes"
  // the user saw live). Has to run AFTER openModal(), once there's an
  // actual layout to measure.
  const notesEl = document.getElementById('reportDirectNotes');
  notesEl.style.height = 'auto';
  notesEl.style.height = notesEl.scrollHeight + 'px';
  checkReportDirectTimeValidity(); // show the duration immediately for the prefilled times
}

function submitReportDirect() {
  const foundTitle = document.getElementById('reportDirectFoundTitle').value.trim();
  const provider = document.getElementById('reportDirectProvider').value.trim();
  const cost = document.getElementById('reportDirectCost').value.trim();
  const isFree = document.getElementById('reportDirectIsFree').checked;
  const dateStart = document.getElementById('reportDirectDateStart').value;
  const dateEnd = document.getElementById('reportDirectDateEnd').value;
  const dates = formatReportDateRange(dateStart, dateEnd);
  const capacity = document.getElementById('reportDirectCapacity').value.trim();
  const modality = document.getElementById('reportDirectModality').value;
  const trainingType = document.getElementById('reportDirectTrainingType').value;
  // 2026-08-31: names exactly which field(s) are empty instead of always
  // listing all four regardless of which were actually filled in - the
  // old blanket message read as "none of this is filled in" even when
  // someone had only missed one field (e.g. forgot capacity), which is
  // genuinely confusing, not just imprecise wording.
  const missing = [];
  if (!provider) missing.push('Training Provider');
  if (!isFree && !cost) missing.push('Cost (or check "This training is free")');
  if (!dateStart) missing.push('Date(s)');
  if (!capacity) missing.push('Capacity');
  if (!modality) missing.push('Modality');
  // Only the initial report (not editing) hard-requires Training Type -
  // matches report_training_demand.php's own rule; the edit endpoint
  // keeps whatever's already there if this is ever left blank.
  if (reportDirectMode === 'report' && !trainingType) missing.push('Training Type');
  if (missing.length > 0) {
    showToast('warn', 'Missing details', `Please fill in: ${missing.join(', ')}.`);
    return;
  }
  if (dateEnd && dateEnd < dateStart) {
    showToast('warn', 'Invalid dates', 'End date must be on or after the start date.');
    return;
  }
  if (!checkReportDirectTimeValidity()) {
    showToast('warn', 'Invalid time', 'End time must be after start time - check for an AM/PM mix-up.');
    return;
  }

  const saveBtn = document.getElementById('reportDirectSaveBtn');
  saveBtn.disabled = true;
  const body = new URLSearchParams({
    demand_id: currentDemandId,
    found_training_title: foundTitle,
    found_provider: provider,
    found_cost: cost,
    is_free: isFree ? '1' : '0',
    found_dates: dates,
    found_start_time: document.getElementById('reportDirectStartTime').value,
    found_end_time: document.getElementById('reportDirectEndTime').value,
    found_capacity: capacity,
    found_venue: document.getElementById('reportDirectVenue').value.trim(),
    actual_modality: modality,
    training_type: trainingType,
    found_notes: document.getElementById('reportDirectNotes').value.trim(),
  });
  const endpoint = reportDirectMode === 'edit' ? 'edit_training_demand_found.php' : 'report_training_demand.php';
  fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
    .then(r => r.json())
    .then(data => {
      saveBtn.disabled = false;
      if (data.success) {
        closeModal('reportDirectModal');
        if (reportDirectMode === 'edit') {
          let editMsg = data.notified_count > 0
            ? `Updated. ${data.notified_count} requester(s) were notified of the change.`
            : 'Updated.';
          if (data.reopened_count > 0) {
            editMsg += ` ${data.reopened_count} previously not-selected person(s) are back in the pool since capacity went up.`;
          }
          showToast('ok', 'Saved', editMsg);
        } else {
          // 2026-08-31: this self-report path now approves in the same step
          // (see report_training_demand.php's $isHr branch) - there's no
          // dean review to wait for here, so a second manual Approve click
          // would just be re-confirming what was just typed. Requesters are
          // notified immediately.
          showToast('ok', 'Saved', 'Training details recorded and requesters notified.');
        }
        demandDataChanged = true;
        showDemandDetail(currentDemandId); // refresh in place
      } else {
        showToast('err', 'Failed', data.message || 'Could not save this report.');
      }
    })
    .catch(() => {
      saveBtn.disabled = false;
      showToast('err', 'Failed', 'Could not save this report - please try again.');
    });
}

// HR's own "No Training Found" - same endpoint and outcome as the
// dean-side button on each college dashboard, only reachable here for the
// case where no dean exists to review this demand at all (see
// reject_training_demand.php's $isHr branch).
// 2026-09-16 - "Search for Providers" research-assist, HR's own version
// of the dean-side button. Read-only, no state change - just a starting
// point for whoever's sourcing this, pulled from real, legitimate
// training-provider sites (never an open web search - see
// search_training_providers.php's header comment for the real,
// live-tested reason why).
function openSearchProvidersModal() {
  const body = document.getElementById('searchProvidersBody');
  body.innerHTML = '<p style="font-size:0.85rem;color:var(--muted);">Searching real training-provider sites...</p>';
  openModal('searchProvidersModal');

  fetch(`search_training_providers.php?demand_id=${currentDemandId}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        body.innerHTML = `<p style="font-size:0.85rem;color:var(--muted);">${data.message || 'Could not search right now.'}</p>`;
        return;
      }
      if (data.results.length === 0) {
        body.innerHTML = `<p style="font-size:0.85rem;color:var(--muted);">No results found among ${data.domains_searched.join(', ')}. Try contacting them directly, or use "No Training Found" if nothing turns up.</p>`;
        return;
      }
      const resultsHtml = data.results.map(r => `
        <div style="border:1px solid var(--line);border-radius:10px;padding:0.7rem 0.9rem;margin-bottom:0.6rem;">
          <a href="${r.link}" target="_blank" rel="noopener" style="font-weight:700;font-size:0.86rem;color:var(--accent);text-decoration:none;">${r.title}</a>
          <p style="font-size:0.72rem;color:var(--ok-ink);margin:0.15rem 0;">${r.displayed_link}</p>
          <p style="font-size:0.8rem;color:var(--ink-2);margin:0;">${r.snippet}</p>
        </div>
      `).join('');
      body.innerHTML = `
        <p style="font-size:0.78rem;color:var(--muted);margin-bottom:0.7rem;">Real results from ${data.domains_searched.join(', ')} - verify and contact before promising anything to an employee.</p>
        <div style="max-height:420px;overflow-y:auto;">${resultsHtml}</div>
      `;
    })
    .catch(() => {
      body.innerHTML = '<p style="font-size:0.85rem;color:var(--muted);">Could not search right now. Please try again.</p>';
    });
}

function openRejectDemandModal() {
  document.getElementById('rejectDemandDemandTitle').textContent = document.getElementById('demandDetailName').textContent;
  document.getElementById('rejectDemandReason').value = '';
  document.getElementById('rejectDemandSaveBtn').disabled = false;
  openModal('rejectDemandModal');
}

function submitRejectDemand() {
  const reason = document.getElementById('rejectDemandReason').value.trim();
  if (!reason) {
    showToast('warn', 'Reason required', 'Please explain why no training could be found.');
    return;
  }
  const saveBtn = document.getElementById('rejectDemandSaveBtn');
  saveBtn.disabled = true;
  const body = new URLSearchParams({ demand_id: currentDemandId, reason });
  fetch('reject_training_demand.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
    .then(r => r.json())
    .then(data => {
      saveBtn.disabled = false;
      if (data.success) {
        closeModal('rejectDemandModal');
        showToast('ok', 'Cancelled', 'Requesters have been notified.');
        demandDataChanged = true;
        showDemandDetail(currentDemandId); // refresh in place
      } else {
        showToast('err', 'Failed', data.message || 'Could not cancel this demand.');
      }
    })
    .catch(() => {
      saveBtn.disabled = false;
      showToast('err', 'Failed', 'Could not cancel this demand - please try again.');
    });
}

function approveDemand() {
  fetch('approve_training_demand.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `demand_id=${currentDemandId}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('ok', 'Approved', 'Requesters have been notified.');
        demandDataChanged = true;
        showDemandDetail(currentDemandId); // refresh in place - AI Shortlist now loads immediately, no second click needed
      } else {
        showToast('err', 'Failed', data.message || 'Could not approve this demand.');
      }
    })
    .catch(() => showToast('err', 'Failed', 'Could not approve this demand.'));
}

/* ============================================================
   EMPLOYEE-REQUESTED TRAININGS (2026-09-01)
   ============================================================ */

// Loads the pending-count badge on the collapsed card, cheap enough to
// run on every dashboard load (no ML call - just a COUNT).
function refreshCustomRequestBadge() {
  fetch('get_custom_training_requests.php')
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      const pending = data.raw.filter(r => r.status === 'Unclustered').length;
      const badge = document.getElementById('customRequestBadge');
      if (pending > 0) {
        badge.textContent = `${pending} awaiting review`;
        badge.style.display = 'inline-flex';
      } else {
        badge.style.display = 'none';
      }
    })
    .catch(() => {}); // non-critical - just skip the badge if this fails
}

// ISO 25010 Security audit (2026-09-06): stored-XSS fix. request_text is
// free text any employee can type verbatim (submit_custom_training_request.php
// only limits length/word count, not HTML content) and was being dropped
// straight into innerHTML - a payload like <img src=x onerror=...> would
// execute in whichever HR admin opened this modal. Escape on the way in.
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ============================================================
   Raw submissions - department filter + pagination
   (2026-09-06 - was one unpaginated list with an internal scrollbar;
   with real employee volume that was still too much scrolling, and
   there was no way to narrow it down to one department.)
   ============================================================ */
const RAW_PER_PAGE = 15;
let rawCurrentPage = 1;
let lastRawRequests = [];

function renderCustomRequestsRaw(raw) {
  lastRawRequests = raw;
  rawCurrentPage = 1;

  // Populate the department filter from whatever departments actually
  // appear in the data, rather than a hardcoded list that could drift
  // out of sync with what's really in the users table.
  const filter = document.getElementById('rawDeptFilter');
  const prevValue = filter.value;
  // Grouped into Teaching (colleges) vs Non-Teaching (offices) so a long
  // flat list isn't a guessing game about which is which - the 5 office
  // names are the same fixed set used everywhere else in this app for
  // the non-teaching/ADMIN pool (see TNA_COLLEGE_CODE_MAP), anything else
  // is a college code.
  // 2026-09-07 - real 13 offices added alongside the original 5 (kept for
  // any account registered before this date - see the same note in
  // admin_page.php's $nonTeachingOfficeAliases).
  const NON_TEACHING_OFFICES = ["Registrar's Office", "Accounting/Budget Office", "Human Resource Management Office (HRMO)", "Supply/Property Office", "Library", "Office of the Campus Director", "Guidance Counselor", "Disbursing and Cashiering", "Records Office", "General Services Unit", "Library Services", "Supply", "Admission and Registrarship", "Accounting Office", "Budget and Finance", "Human Resource Management", "Medical and Dental Services", "Procurement"];
  const depts = Array.from(new Set(raw.map(r => r.department).filter(Boolean))).sort();
  const teachingDepts = depts.filter(d => !NON_TEACHING_OFFICES.includes(d));
  const nonTeachingDepts = depts.filter(d => NON_TEACHING_OFFICES.includes(d));
  const optionsFor = list => list.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');

  filter.innerHTML = '<option value="">All Departments</option>' +
    (teachingDepts.length ? `<optgroup label="Teaching">${optionsFor(teachingDepts)}</optgroup>` : '') +
    (nonTeachingDepts.length ? `<optgroup label="Non-Teaching">${optionsFor(nonTeachingDepts)}</optgroup>` : '');
  filter.value = depts.includes(prevValue) ? prevValue : '';

  applyRawView();
}

function applyRawView() {
  const tbody = document.getElementById('customRequestsRawBody');
  if (lastRawRequests.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5"><div class="empty"><i class="ri-inbox-line"></i><h4>No requests yet</h4></div></td></tr>`;
    document.getElementById('rawNoMatches').style.display = 'none';
    document.getElementById('rawPageIndicator').textContent = 'Page 1';
    return;
  }

  const deptFilter = document.getElementById('rawDeptFilter').value;
  const showPromoted = document.getElementById('rawShowPromoted').checked;
  const matching = lastRawRequests.filter(r =>
    (showPromoted || r.status !== 'Clustered') &&
    (!deptFilter || r.department === deptFilter)
  );

  const totalPages = Math.max(1, Math.ceil(matching.length / RAW_PER_PAGE));
  if (rawCurrentPage > totalPages) rawCurrentPage = totalPages;
  if (rawCurrentPage < 1) rawCurrentPage = 1;
  const start = (rawCurrentPage - 1) * RAW_PER_PAGE;
  const pageRows = matching.slice(start, start + RAW_PER_PAGE);

  document.getElementById('rawNoMatches').style.display = matching.length === 0 ? '' : 'none';
  document.getElementById('rawPageIndicator').textContent = `Page ${rawCurrentPage} of ${totalPages}`;

  tbody.innerHTML = pageRows.map(r => `
    <tr>
      <td class="strong">${escapeHtml(r.name)}</td>
      <td>${escapeHtml(r.department) || '—'}</td>
      <td>${escapeHtml(r.request_text)}</td>
      <td><span class="badge ${r.status === 'Clustered' ? 'badge-accepted' : 'badge-pending'}">${r.status === 'Clustered' ? 'Promoted' : 'Awaiting Review'}</span></td>
      <td style="white-space:nowrap;color:var(--muted);font-size:0.8rem;">${new Date(r.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</td>
    </tr>
  `).join('');
}

function changeRawPage(delta) {
  rawCurrentPage += delta;
  applyRawView();
}

function openCustomRequestsModal() {
  document.getElementById('customClustersSection').style.display = 'none';
  document.getElementById('customClustersBody').innerHTML = '';
  lastClusters = [];
  document.getElementById('rawShowPromoted').checked = false;
  document.getElementById('customRequestsRawBody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);">Loading…</td></tr>';
  openModal('customRequestsModal');
  fetch('get_custom_training_requests.php')
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showToast('err', 'Load failed', data.message || 'Could not load requests.');
        return;
      }
      renderCustomRequestsRaw(data.raw);
    })
    .catch(() => showToast('err', 'Load failed', 'Could not load requests.'));
}

/* ============================================================
   Clusters - table + pagination (2026-09-06 rework)
   -----------------------------------------------------------
   Replaces the old stack of cards (a paragraph of names, an inline
   editable title, an inline warning box, all crammed into one card per
   cluster) with a plain table - "View" opens clusterDetailModal, which
   is where the actual per-person breakdown, the title edit, and the
   Promote action now live.
   ============================================================ */
const CLUSTER_PER_PAGE = 10;
let clusterCurrentPage = 1;
let lastClusters = [];

function runCustomRequestClustering() {
  const btn = document.getElementById('runClusteringBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="ri-loader-4-line"></i> Grouping…';
  fetch('get_custom_training_requests.php?cluster=1')
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="ri-robot-2-line"></i> Group Similar Requests';
      if (!data.success) {
        showToast('err', 'Failed', data.message || 'Could not load requests.');
        return;
      }
      renderCustomRequestsRaw(data.raw);
      if (data.cluster_error) {
        showToast('err', 'Grouping unavailable', data.cluster_error);
        return;
      }
      // clusterCustomTrainingRequests() only ever clusters rows that are
      // still 'Unclustered' in the first place, so nothing further to
      // filter here - every cluster returned is real and promotable.
      lastClusters = data.clusters || [];
      clusterCurrentPage = 1;
      document.getElementById('customClustersSection').style.display = 'block';
      applyClusterView();
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="ri-robot-2-line"></i> Group Similar Requests';
      showToast('err', 'Failed', 'Could not group these requests - please try again.');
    });
}

function applyClusterView() {
  const tbody = document.getElementById('customClustersBody');
  const noMatches = document.getElementById('clustersNoMatches');

  if (lastClusters.length === 0) {
    tbody.innerHTML = '';
    noMatches.style.display = '';
    document.getElementById('clusterPageIndicator').textContent = 'Page 1 of 1';
    return;
  }
  noMatches.style.display = 'none';

  const totalPages = Math.max(1, Math.ceil(lastClusters.length / CLUSTER_PER_PAGE));
  if (clusterCurrentPage > totalPages) clusterCurrentPage = totalPages;
  if (clusterCurrentPage < 1) clusterCurrentPage = 1;
  const start = (clusterCurrentPage - 1) * CLUSTER_PER_PAGE;
  const pageClusters = lastClusters.slice(start, start + CLUSTER_PER_PAGE);

  document.getElementById('clusterPageIndicator').textContent = `Page ${clusterCurrentPage} of ${totalPages}`;

  tbody.innerHTML = pageClusters.map((c, pageIdx) => {
    const i = start + pageIdx; // real index into lastClusters
    // ADMIN is the shared pool every non-teaching office folds into
    // (see canonicalTnaCollegeCode) - shown with its plain-language name
    // rather than the internal bucket code.
    const bucketLabel = c.bucket === 'ADMIN' ? 'Non-Teaching' : (c.bucket === 'UNMAPPED' ? 'Unrecognized dept.' : c.bucket);
    // 2026-09-06 - was icon-only badges with the explanation hidden in a
    // hover tooltip, which the user couldn't read without knowing to
    // hover in the first place. Says what it means right on the badge.
    const flags = [
      c.existing_match ? '<span class="badge badge-pending" style="white-space:normal;text-align:left;" title="This idea looks similar to something already in the catalog or already in the pipeline, so check before creating a duplicate."><i class="ri-search-eye-line"></i> Might already exist</span>' : '',
      c.bucket_incomplete ? '<span class="badge badge-pending" style="white-space:normal;text-align:left;" title="The AI service dropped out partway through, so this group might not be fully accurate (still safe to use)."><i class="ri-wifi-off-line"></i> Grouping incomplete</span>' : '',
    ].filter(Boolean).join('<br>');
    return `
      <tr>
        <td class="strong">${escapeHtml(c.label)}</td>
        <td>${escapeHtml(bucketLabel)}</td>
        <td class="num">${c.requests.length}</td>
        <td>${flags}</td>
        <td style="text-align:center;">
          <button class="btn btn-primary btn-sm" onclick="openClusterDetail(${i})"><i class="ri-eye-line"></i> View</button>
        </td>
      </tr>`;
  }).join('');
}

function changeClusterPage(delta) {
  clusterCurrentPage += delta;
  applyClusterView();
}

/* ============================================================
   Cluster detail modal - per-person breakdown + promote
   ============================================================ */
let currentClusterIndex = -1;

function openClusterDetail(index) {
  const c = lastClusters[index];
  if (!c) return;
  currentClusterIndex = index;

  document.getElementById('clusterDetailRequesters').innerHTML = c.requests.map(r => `
    <tr>
      <td class="strong">${escapeHtml(r.name)}</td>
      <td>${escapeHtml(r.department) || '—'}</td>
      <td>${escapeHtml(r.request_text)}</td>
    </tr>
  `).join('');

  document.getElementById('clusterDetailLabel').value = c.label;

  // A cluster of exactly 1 has no other members' wording to draw a shared
  // label from, so it defaults to that one person's raw sentence verbatim
  // (2026-09-02 fix - a real gap the adviser caught: this went unnoticed
  // and got promoted as-is once, becoming a demand titled "I want a
  // hands-on session on..."). Flagged rather than silently rewritten - HR
  // should tighten the wording, not have it auto-rewritten and possibly
  // miss the point.
  document.getElementById('clusterDetailRawHint').style.display = c.requests.length === 1 ? '' : 'none';

  // 2026-09-06 - clusterCustomTrainingRequests() now groups per
  // college/office; if the AI service dropped out partway through one
  // bucket, that bucket's remaining requests still show up (as ungrouped
  // singles) rather than being hidden, but they may not have been
  // compared against each other yet - safe to promote, just possibly not
  // as fully grouped as it could be.
  document.getElementById('clusterDetailIncompleteHint').style.display = c.bucket_incomplete ? '' : 'none';

  // Surfaced, not auto-applied (2026-09-01) - a real gap the adviser
  // caught: without this, a cluster could get promoted as a brand-new
  // demand even when it's really the same idea as something already in
  // the catalog or an existing demand, splitting requester counts
  // instead of pooling them. HR still decides.
  const matchBox = document.getElementById('clusterDetailMatch');
  if (c.existing_match) {
    document.getElementById('clusterDetailMatchSource').textContent = c.existing_match.source === 'catalog' ? 'catalog title' : 'demand';
    document.getElementById('clusterDetailMatchTitle').textContent = `"${c.existing_match.title}"`;
    document.getElementById('clusterDetailUseMatchBtn').onclick = () => {
      document.getElementById('clusterDetailLabel').value = c.existing_match.title;
    };
    matchBox.style.display = '';
  } else {
    matchBox.style.display = 'none';
  }

  openModal('clusterDetailModal');
}

function promoteCustomClusterFromModal(btnEl) {
  const c = lastClusters[currentClusterIndex];
  if (!c) return;
  const label = document.getElementById('clusterDetailLabel').value.trim();
  if (!label) {
    showToast('warn', 'Missing title', 'Please give this a title before promoting.');
    return;
  }
  const ids = c.requests.map(r => r.id).join(',');

  btnEl.disabled = true;
  fetch('promote_custom_request_cluster.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `label=${encodeURIComponent(label)}&request_ids=${encodeURIComponent(ids)}`
  })
    .then(r => r.json())
    .then(data => {
      btnEl.disabled = false;
      if (data.success) {
        // 2026-09-07 fix - the promotion itself already succeeded server-
        // side at this point (the DB write happened before this response
        // was even sent). Reporting success and closing the modal must
        // not depend on the refresh step below - previously both were
        // covered by the same .catch(), so a failure in the *refresh*
        // (an unrelated, non-critical step) got misreported as "Failed"
        // for the whole promotion. Real consequence hit live: HR saw
        // "Failed" on a promotion that had actually gone through, then
        // got a second, differently-worded (and accurate) "already
        // promoted" error on retry - confusing and made a working feature
        // look broken. Show success and close first, guaranteed; only the
        // optional refresh gets its own separate, honestly-worded failure
        // path.
        showToast('ok', 'Promoted', `${data.promoted_count} requester(s) added to the Training Demand board as "${label}".`);
        closeModal('clusterDetailModal');
        try {
          runCustomRequestClustering(); // refresh the cluster list in place
        } catch (refreshErr) {
          console.error('Promotion succeeded but refreshing the list failed:', refreshErr);
          showToast('warn', 'Promoted, but list not refreshed', 'The promotion went through - reopen this to see the updated list.');
        }
      } else {
        showToast('err', 'Failed', data.message || 'Could not promote this group.');
      }
    })
    .catch(() => {
      btnEl.disabled = false;
      showToast('err', 'Failed', 'Could not promote this group - please try again.');
    });
}

/* ============================================================
   L. BOOT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  syncCollapseButton();
  applyDemandFilterAndPagination();
  refreshCustomRequestBadge();

  // Close dropdowns when clicking outside any dropdown.
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) closeAllDropdowns();
  });

  // Close modals on backdrop click - except reportDirectModal (2026-08-31):
  // a real report found that a full accidental outside-click while typing
  // silently discarded everything, with no confirmation and no way back.
  // This is a real multi-field form someone might spend a minute filling
  // in, unlike this file's simpler view-only modals, so it's worth
  // protecting specifically rather than disabling backdrop-close globally.
  const NO_BACKDROP_CLOSE = ['reportDirectModal', 'customRequestsModal'];
  document.querySelectorAll('.modal').forEach(m => {
    if (NO_BACKDROP_CLOSE.includes(m.id)) return;
    m.addEventListener('click', (e) => {
      if (e.target !== m) return;
      // confirmActionModal counts as Cancel when dismissed this way, not
      // a plain close - it has a pending Promise waiting on an answer
      // (see confirmDialog() below) that needs to actually resolve, or
      // whatever called it hangs forever.
      if (m.id === 'confirmActionModal') { resolveConfirmDialog(false); return; }
      closeModal(m.id);
    });
  });

  // Escape closes whatever's open - except the protected modals above,
  // same reasoning (an accidental Escape mid-typing shouldn't silently
  // discard a form either).
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal.open').forEach(m => {
        if (NO_BACKDROP_CLOSE.includes(m.id)) return;
        if (m.id === 'confirmActionModal') { resolveConfirmDialog(false); return; }
        m.classList.remove('open');
      });
      document.body.style.overflow = document.querySelector('.modal.open') ? 'hidden' : '';
      closeAllDropdowns(); closeMobileRail();
    }
  });

  // Keep the collapse button in sync with viewport.
  window.addEventListener('resize', syncCollapseButton);
});
</script>

</body>
</html>