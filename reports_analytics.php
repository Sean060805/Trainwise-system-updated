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
ensureTrainingRecommendationsTable($con);

// Top 10 most-requested trainings, computed live from real submitted demand
// (training_demand.requester_count) rather than a static document snapshot -
// see getTopRequestedTrainings() for why. Moved here from
// training_pipeline.php 2026-09-21: it's a reporting view, not an action item.
$topRequestedTrainings = getTopRequestedTrainings($con, 10);

$pendingUsers = $con->query("SELECT id FROM users WHERE status = 'pending'");
$pendingCount = $pendingUsers ? $pendingUsers->num_rows : 0;

/* -----------------------------------------------------------------------------
   TRAINING DEMOGRAPHICS - a summary of where every employee's training
   actually stands, grouped BY PEOPLE (Teaching vs Non-Teaching).
   ----------------------------------------------------------------------------- */
$trainingStatsQuery = $con->query("
    SELECT u.teaching_status, tr.status, COUNT(*) AS c
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.status IN ('Completed', 'Confirmed', 'Accepted', 'Training Available', 'Not Selected', 'Cancelled')
    GROUP BY u.teaching_status, tr.status
");
$trainingDemographics = [
    'Teaching'    => ['completed' => 0, 'confirmed' => 0, 'pending' => 0, 'not_selected' => 0, 'cancelled' => 0],
    'Non-Teaching' => ['completed' => 0, 'confirmed' => 0, 'pending' => 0, 'not_selected' => 0, 'cancelled' => 0],
];
if ($trainingStatsQuery) {
    while ($row = $trainingStatsQuery->fetch_assoc()) {
        $bucket = $row['teaching_status'] === 'Teaching' ? 'Teaching' : 'Non-Teaching';
        $statusKey = match ($row['status']) {
            'Completed' => 'completed',
            'Confirmed' => 'confirmed',
            'Accepted', 'Training Available' => 'pending',
            'Not Selected' => 'not_selected',
            // 2026-09-15 - a demand-level "No Training Found" outcome
            // (see reject_training_demand.php), distinct from Not
            // Selected (a training existed, this person wasn't picked).
            'Cancelled' => 'cancelled',
            default => null,
        };
        if ($statusKey) {
            $trainingDemographics[$bucket][$statusKey] += (int)$row['c'];
        }
    }
}
$trainingDemoTotals = [
    'completed' => $trainingDemographics['Teaching']['completed'] + $trainingDemographics['Non-Teaching']['completed'],
    'confirmed' => $trainingDemographics['Teaching']['confirmed'] + $trainingDemographics['Non-Teaching']['confirmed'],
    'pending' => $trainingDemographics['Teaching']['pending'] + $trainingDemographics['Non-Teaching']['pending'],
    'not_selected' => $trainingDemographics['Teaching']['not_selected'] + $trainingDemographics['Non-Teaching']['not_selected'],
    'cancelled' => $trainingDemographics['Teaching']['cancelled'] + $trainingDemographics['Non-Teaching']['cancelled'],
];
$trainingResolvedPool = $trainingDemoTotals['completed'] + $trainingDemoTotals['confirmed'] + $trainingDemoTotals['not_selected'] + $trainingDemoTotals['cancelled'];
$trainingCompletionRate = $trainingResolvedPool > 0 ? round(($trainingDemoTotals['completed'] / $trainingResolvedPool) * 100) : null;

$collegeStatsQuery = $con->query("
    SELECT u.department, tr.status, COUNT(*) AS c
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.status IN ('Completed', 'Confirmed', 'Accepted', 'Training Available')
    GROUP BY u.department, tr.status
");
$trainingByCollege = [];
if ($collegeStatsQuery) {
    while ($row = $collegeStatsQuery->fetch_assoc()) {
        $code = canonicalTnaCollegeCode($row['department']) ?: ($row['department'] ?: 'Unknown');
        if (!isset($trainingByCollege[$code])) {
            $trainingByCollege[$code] = ['completed' => 0, 'confirmed' => 0, 'pending' => 0];
        }
        $statusKey = match ($row['status']) {
            'Completed' => 'completed',
            'Confirmed' => 'confirmed',
            'Accepted', 'Training Available' => 'pending',
            default => null,
        };
        if ($statusKey) {
            $trainingByCollege[$code][$statusKey] += (int)$row['c'];
        }
    }
}
ksort($trainingByCollege);

/* -----------------------------------------------------------------------------
   MONTHLY TRAINING ANALYTICS - last 12 months of completions, split
   Teaching/Non-Teaching, sourced from training_history_log.
   ----------------------------------------------------------------------------- */
$monthlyStmt = $con->query("
    SELECT DATE_FORMAT(thl.completion_date, '%Y-%m') AS ym, u.teaching_status, COUNT(*) AS c
    FROM training_history_log thl
    JOIN users u ON u.id = thl.user_id
    WHERE thl.completion_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY ym, u.teaching_status
");
$monthlyRaw = [];
if ($monthlyStmt) {
    while ($row = $monthlyStmt->fetch_assoc()) {
        $bucket = $row['teaching_status'] === 'Teaching' ? 'Teaching' : 'Non-Teaching';
        $monthlyRaw[$row['ym']][$bucket] = (int)$row['c'];
    }
}
$monthlyLabels = [];
$monthlyTeaching = [];
$monthlyNonTeaching = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthlyLabels[] = date('M Y', strtotime($ym . '-01'));
    $monthlyTeaching[] = $monthlyRaw[$ym]['Teaching'] ?? 0;
    $monthlyNonTeaching[] = $monthlyRaw[$ym]['Non-Teaching'] ?? 0;
}
$monthlyTotal = array_sum($monthlyTeaching) + array_sum($monthlyNonTeaching);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reports and Analytics - LSPU TNA</title>

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
    /* 2026-09-15 - same tile layout as .grid-stats, just 5 columns wide -
       the Training Demographics section grew a 5th tile (Cancelled, see
       reject_training_demand.php) and .grid-stats itself is reused
       elsewhere on this page at 4, so this is a sibling class rather than
       widening the shared one. */
    .grid-stats-5  { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-actions  { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-teaching { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-main     { display: grid; grid-template-columns: 1fr 1.85fr; gap: 1.15rem; align-items: start; }
    .stack { display: flex; flex-direction: column; gap: 1.15rem; }

    @media (max-width: 1200px) {
      .grid-stats { grid-template-columns: repeat(2, 1fr); }
      .grid-stats-5 { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 1024px) {
      .grid-actions, .grid-main, .grid-teaching { grid-template-columns: 1fr; }
      .grid-stats-5 { grid-template-columns: repeat(2, 1fr); }
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
      .grid-stats-5 { grid-template-columns: 1fr 1fr; }
      .avatar-meta { display: none; }
      .page-head h2 { font-size: 1.3rem; }
    }
    @media (max-width: 420px) {
      .grid-stats { grid-template-columns: 1fr; }
      .grid-stats-5 { grid-template-columns: 1fr; }
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
      <a href="training_pipeline.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Pipeline</span>
      </a>
      <a href="reports_analytics.php" class="rail-link active">
        <i class="ri-bar-chart-box-line"></i><span>Reports &amp; Analytics</span>
        <i class="ri-arrow-right-s-line chev"></i>
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
            <h2>Reports &amp; Analytics</h2>
            <p>Training outcomes, completion trends, and per-college breakdowns.</p>
          </div>
          <div class="date-chip">
            <i class="ri-calendar-2-line"></i>
            <span><?= date('l, F j, Y') ?></span>
          </div>
        </div>

        <section class="card" style="margin-top:1.15rem;">
          <div class="card-head">
            <h3>
              <i class="ri-bar-chart-box-line"></i> Training Demographics
              <?php if ($trainingCompletionRate !== null): ?>
                <span class="badge badge-on-time num"><?= $trainingCompletionRate ?>% completion rate</span>
              <?php endif; ?>
            </h3>
          </div>
          <div class="card-body">
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">
              Every employee currently in the training pipeline, by outcome. Completion rate is Completed ÷ (Completed + Confirmed + Not Selected + Cancelled) - people still Pending haven't had a chance to resolve either way yet, so they're not counted against it.
            </p>
            <section class="grid-stats-5" style="margin-bottom:1.15rem;">
              <div class="card hoverable">
                <div class="card-body">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                    <div>
                      <p class="stat-label">Completed</p>
                      <h3 class="stat-value num" style="color:var(--ink);"><?= $trainingDemoTotals['completed'] ?></h3>
                    </div>
                    <div class="stat-ico ico-emerald"><i class="ri-checkbox-circle-line"></i></div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
                    <span class="badge badge-on-time" style="justify-content:center;">Teaching: <span class="num"><?= $trainingDemographics['Teaching']['completed'] ?></span></span>
                    <span class="badge badge-on-time" style="justify-content:center;">Non-Teach: <span class="num"><?= $trainingDemographics['Non-Teaching']['completed'] ?></span></span>
                  </div>
                </div>
              </div>
              <div class="card hoverable">
                <div class="card-body">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                    <div>
                      <p class="stat-label">Confirmed</p>
                      <h3 class="stat-value num" style="color:var(--ink);"><?= $trainingDemoTotals['confirmed'] ?></h3>
                    </div>
                    <div class="stat-ico ico-indigo"><i class="ri-shield-check-line"></i></div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
                    <span class="badge badge-accepted" style="justify-content:center;">Teaching: <span class="num"><?= $trainingDemographics['Teaching']['confirmed'] ?></span></span>
                    <span class="badge badge-accepted" style="justify-content:center;">Non-Teach: <span class="num"><?= $trainingDemographics['Non-Teaching']['confirmed'] ?></span></span>
                  </div>
                </div>
              </div>
              <div class="card hoverable">
                <div class="card-body">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                    <div>
                      <p class="stat-label">Pending</p>
                      <h3 class="stat-value num" style="color:var(--ink);"><?= $trainingDemoTotals['pending'] ?></h3>
                    </div>
                    <div class="stat-ico ico-amber"><i class="ri-time-line"></i></div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
                    <span class="badge badge-pending" style="justify-content:center;">Teaching: <span class="num"><?= $trainingDemographics['Teaching']['pending'] ?></span></span>
                    <span class="badge badge-pending" style="justify-content:center;">Non-Teach: <span class="num"><?= $trainingDemographics['Non-Teaching']['pending'] ?></span></span>
                  </div>
                </div>
              </div>
              <div class="card hoverable">
                <div class="card-body">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                    <div>
                      <p class="stat-label">Not Selected</p>
                      <h3 class="stat-value num" style="color:var(--ink);"><?= $trainingDemoTotals['not_selected'] ?></h3>
                    </div>
                    <div class="stat-ico ico-rose"><i class="ri-user-unfollow-line"></i></div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
                    <span class="badge badge-no-sub" style="justify-content:center;">Teaching: <span class="num"><?= $trainingDemographics['Teaching']['not_selected'] ?></span></span>
                    <span class="badge badge-no-sub" style="justify-content:center;">Non-Teach: <span class="num"><?= $trainingDemographics['Non-Teaching']['not_selected'] ?></span></span>
                  </div>
                </div>
              </div>
              <div class="card hoverable">
                <div class="card-body">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                    <div>
                      <p class="stat-label">Cancelled</p>
                      <h3 class="stat-value num" style="color:var(--ink);"><?= $trainingDemoTotals['cancelled'] ?></h3>
                    </div>
                    <div class="stat-ico ico-rose"><i class="ri-close-circle-line"></i></div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
                    <span class="badge badge-declined" style="justify-content:center;">Teaching: <span class="num"><?= $trainingDemographics['Teaching']['cancelled'] ?></span></span>
                    <span class="badge badge-declined" style="justify-content:center;">Non-Teach: <span class="num"><?= $trainingDemographics['Non-Teaching']['cancelled'] ?></span></span>
                  </div>
                </div>
              </div>
            </section>
            <?php if (!empty($trainingByCollege)): ?>
              <div class="table-wrap">
                <table class="data">
                  <thead>
                    <tr><th>College</th><th style="text-align:center;">Completed</th><th style="text-align:center;">Confirmed</th><th style="text-align:center;">Pending</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($trainingByCollege as $code => $stats): ?>
                      <tr>
                        <td class="strong"><?= htmlspecialchars($code) ?></td>
                        <td class="num" style="text-align:center;"><?= $stats['completed'] ?></td>
                        <td class="num" style="text-align:center;"><?= $stats['confirmed'] ?></td>
                        <td class="num" style="text-align:center;"><?= $stats['pending'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <?php if (!empty($topRequestedTrainings)): ?>
        <!-- ===========================================================
             TOP REQUESTED TRAININGS — live leaderboard computed from
             actual submitted demand (training_demand.requester_count),
             not a static document. See getTopRequestedTrainings() in
             ml_recommendations.php.
             =========================================================== -->
        <section class="card" style="margin-top:1.15rem;">
          <div class="card-head">
            <h3><i class="ri-trophy-line"></i> Top <?= count($topRequestedTrainings) ?> Most Requested Trainings</h3>
          </div>
          <div class="card-body">
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">
              Ranked by how many employees across all colleges have accepted or requested each one so far — updates automatically as more people submit.
            </p>
            <div class="table-wrap">
              <table class="data">
                <thead>
                  <tr>
                    <th style="width:3rem;">#</th>
                    <th>Training / Idea</th>
                    <th class="num">Requesters</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topRequestedTrainings as $i => $item): ?>
                    <tr>
                      <td>
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:999px;background:var(--gold-soft,#F5E6C8);color:#8C6423;font-size:0.78rem;font-weight:700;"><?= $i + 1 ?></span>
                      </td>
                      <td><?= htmlspecialchars(demandDisplayTitle($item)) ?></td>
                      <td class="num"><?= (int)$item['requester_count'] ?></td>
                      <td><span class="badge <?= demandStatusBadgeClass($item['pipeline_status']) ?>"><?= htmlspecialchars(demandStatusLabel($item['pipeline_status'])) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <!-- ===========================================================
             MONTHLY TRAINING ANALYTICS (2026-09-02, per the adviser) —
             a trend view alongside the point-in-time Demographics card
             above. Same Teaching/Non-Teaching split, same data source
             family (training_history_log), just aggregated by month
             instead of by current status.
             =========================================================== -->
        <section class="card" style="margin-top:1.15rem;">
          <div class="card-head">
            <h3><i class="ri-line-chart-line"></i> Monthly Training Analytics</h3>
            <span class="badge badge-info num"><?= $monthlyTotal ?> completed (last 12 mo.)</span>
          </div>
          <div class="card-body">
            <?php if ($monthlyTotal > 0): ?>
              <div class="chart-box"><canvas id="monthlyAnalyticsChart"></canvas></div>
            <?php else: ?>
              <div class="empty compact">
                <i class="ri-line-chart-line"></i>
                <h4>No completions yet</h4>
                <p>This chart fills in as employees complete confirmed trainings.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </div>
</div>

<!-- =============================================================
     TOAST STACK - flash messages render here (see JS bootstrap)
     ============================================================= -->
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<!-- =============================================================
     VENDOR — Chart.js for the department breakdown chart.
     ============================================================= -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<!-- =============================================================
     APP SCRIPT
     Sections:
       A. Toast system
       B. Flash bootstrap (PHP → toast)
       C. Sidebar: collapse + mobile drawer
       D. Dropdowns (bell + avatar)
       E. Modals (open / close / esc / outside-click)
       F. Tabs
       G. Search (pending / user-mgmt / topbar deadline filter)
       H. Confirm guards
       I. Deadline form (validation + live preview)
       J. Department chart + detail popup
       K. Submissions modal (async + skeleton + pagination)
       K2. Training demand modal (async + forward + approve)
       L. Boot
     ============================================================= -->
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
   MONTHLY ANALYTICS CHART
   ============================================================ */

const MONTHLY_LABELS      = <?php echo json_encode($monthlyLabels); ?>;
const MONTHLY_TEACHING    = <?php echo json_encode($monthlyTeaching); ?>;
const MONTHLY_NONTEACHING = <?php echo json_encode($monthlyNonTeaching); ?>;

function initMonthlyAnalyticsChart() {
  const canvas = document.getElementById('monthlyAnalyticsChart');
  if (!canvas || typeof Chart === 'undefined') return;
  const ctx = canvas.getContext('2d');

  const base = {
    borderWidth: 1, borderRadius: 6, borderSkipped: false,
    barPercentage: 0.6, categoryPercentage: 0.8, stack: 'total'
  };

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: MONTHLY_LABELS,
      datasets: [
        { label: 'Teaching',     data: MONTHLY_TEACHING,    backgroundColor: '#1A4B8C', borderColor: '#0F3460', ...base },
        { label: 'Non-Teaching', data: MONTHLY_NONTEACHING, backgroundColor: '#D4A843', borderColor: '#B8922A', ...base },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: true, position: 'top', labels: { font: { family: 'Inter', weight: '600' }, boxWidth: 14 } },
        tooltip: {
          backgroundColor: '#0f172a', padding: 12, cornerRadius: 10,
          titleFont: { family: 'Inter', weight: '700' }, bodyFont: { family: 'Inter' },
          callbacks: {
            footer(items) {
              const total = items.reduce((sum, it) => sum + (it.raw || 0), 0);
              return `Total: ${total}`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { family: 'JetBrains Mono', size: 11 } },
          title: { display: true, text: 'Month', font: { family: 'Inter', size: 12, weight: '700' }, padding: { top: 8 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: '#eef1f5' },
          ticks: { stepSize: 1, font: { family: 'JetBrains Mono', size: 11 }, callback: v => Math.floor(v) },
          title: { display: true, text: 'Trainings Completed', font: { family: 'Inter', size: 12, weight: '700' } }
        }
      }
    }
  });
}

/* ============================================================
   L. BOOT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  syncCollapseButton();
  initMonthlyAnalyticsChart();

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) closeAllDropdowns();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeAllDropdowns(); closeMobileRail(); }
  });

  window.addEventListener('resize', syncCollapseButton);
});
</script>

</body>
</html>