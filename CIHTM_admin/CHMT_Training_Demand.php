<?php
/* =============================================================================
   LSPU — CHMT TRAINING DEMAND (dedicated page)
   -----------------------------------------------------------------------------
   2026-09-04 - split out of CHMT.php's dashboard, same treatment as
   CCS_Training_Demand.php (see that file's header comment for the full
   rationale). Gives the dean's "Training Demand Forwarded to You" queue
   its own sidebar entry instead of burying it at the bottom of a long
   dashboard scroll. CHMT.php keeps a slim teaser card linking here.
   ============================================================================= */

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// ISO 25010 Security audit (2026-09-06): the above check only confirmed
// *a* user was logged in, not that they were THIS college's dean - any
// authenticated account (another dean, or a plain employee) could load
// this page directly by URL. Added the missing role check.
if (($_SESSION['user_role'] ?? '') !== 'admin_chmt') {
    header("Location: ../index.php");
    exit();
}

require_once '../config.php';

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

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

/* -----------------------------------------------------------------------------
   TRAINING DEMAND FORWARDED TO CHMT — identical to CHMT.php's own block.
   ----------------------------------------------------------------------------- */
require_once '../ml_recommendations.php';
ensureTrainingRecommendationsTable($con);

$forwardedDemandStmt = $con->query("
    SELECT d.id, d.title, d.description, d.training_type, d.budget_hint, d.updated_at, u.department, tr.preferred_modality
    FROM training_demand d
    JOIN training_recommendations tr ON tr.demand_id = d.id
    JOIN users u ON u.id = tr.user_id
    WHERE d.pipeline_status = 'Forwarded to Dean'
      AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
    ORDER BY d.updated_at DESC
");
$forwardedDemandCandidates = $forwardedDemandStmt ? $forwardedDemandStmt->fetch_all(MYSQLI_ASSOC) : [];

$forwardedDemandRows = [];
foreach ($forwardedDemandCandidates as $candidate) {
    $code = canonicalTnaCollegeCode($candidate['department']);
    if (!isset($forwardedDemandRows[$candidate['id']])) {
        $forwardedDemandRows[$candidate['id']] = [
            'id' => $candidate['id'],
            'title' => $candidate['title'],
            'description' => $candidate['description'],
            'training_type' => $candidate['training_type'],
            'budget_hint' => $candidate['budget_hint'],
            'own_requester_count' => 0,
            'college_counts' => [],
            'modality_counts' => [],
        ];
    }
    $modalityKey = in_array($candidate['preferred_modality'] ?? null, ['Face-to-Face', 'Online'], true) ? $candidate['preferred_modality'] : 'Unspecified';
    $forwardedDemandRows[$candidate['id']]['modality_counts'][$modalityKey] = ($forwardedDemandRows[$candidate['id']]['modality_counts'][$modalityKey] ?? 0) + 1;
    if ($code !== null) {
        $forwardedDemandRows[$candidate['id']]['college_counts'][$code] = ($forwardedDemandRows[$candidate['id']]['college_counts'][$code] ?? 0) + 1;
        if ($code === 'CHMT') {
            $forwardedDemandRows[$candidate['id']]['own_requester_count']++;
        }
    }
}
$forwardedDemandRows = array_values(array_filter($forwardedDemandRows, fn($d) => $d['own_requester_count'] > 0));
foreach ($forwardedDemandRows as &$d) {
    $parts = [];
    foreach ($d['college_counts'] as $collegeCode => $count) {
        $parts[] = "$count $collegeCode";
    }
    $d['requester_count'] = array_sum($d['college_counts']);
    $d['requester_breakdown'] = implode(', ', $parts);
    $modalityParts = [];
    foreach (['Face-to-Face', 'Online', 'Unspecified'] as $mk) {
        if (!empty($d['modality_counts'][$mk])) {
            $modalityParts[] = "{$d['modality_counts'][$mk]} $mk";
        }
    }
    $d['modality_breakdown'] = implode(', ', $modalityParts);
}
unset($d);

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// 2026-09-06 fix - the notification bell only belongs on the Dashboard
// (matching Assessment Form / IDP Forms / Evaluation, none of which have
// one) - removed the bell's backend query block here too. See CLAUDE.md.

$adminName = $user['name'] ?? 'CHMT Admin';
$initials = strtoupper(substr($adminName, 0, 1));
$parts = preg_split('/\s+/', trim($adminName));
if (count($parts) > 1) { $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CHMT Training Demand · LSPU TNA</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <link rel="stylesheet" href="../assets/css/tw-7.css">

  <!-- Design system — same shell as CHMT.php, recolored via this
       college's own accent/rail tokens. -->
  <style>
    :root {
      --bg: #f5f6f8;
      --bg-grad: radial-gradient(1100px 600px at 100% -8%, rgba(0,0,0,0.04), transparent 60%);
      --surface: #ffffff; --surface-2: #f8fafc; --surface-3: #f1f3f7;
      --ink: #0f172a; --ink-2: #334155; --muted: #64748b; --faint: #94a3b8;
      --line: #e5e7eb; --line-soft: #eef1f5;
      --accent: #9f1239; --accent-600: #9f1239; --accent-700: #731c1c; --accent-soft: #fdf2f8; --accent-ink: #4c0519;
      --ok: #059669; --ok-soft: #ecfdf5; --ok-ink: #065f46;
      --warn: #d97706; --warn-soft: #fffbeb; --warn-ink: #92400e;
      --bad: #e11d48; --bad-soft: #fff1f2; --bad-ink: #9f1239;
      --sky: #0284c7; --sky-soft: #f0f9ff;
      --rail: #0f172a; --rail-2: #111c33; --rail-line: rgba(148,163,184,0.14);
      --rail-text: rgba(226,232,240,0.74); --rail-text-2: rgba(148,163,184,0.55);
      --radius: 14px; --radius-sm: 10px; --radius-lg: 18px;
      --rail-w: 264px; --rail-w-min: 78px; --topbar-h: 66px;
      --ease: cubic-bezier(0.16, 1, 0.3, 1);
      --shadow-card: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -18px rgba(15,23,42,0.22);
      --shadow-pop: 0 16px 40px -12px rgba(15,23,42,0.22);
    }

    *, *::before, *::after { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; }
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; color: var(--ink); background: var(--bg-grad), var(--bg); -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; }
    h1, h2, h3, h4, h5 { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; letter-spacing: -0.015em; margin: 0; }
    .num { font-family: 'JetBrains Mono', ui-monospace, monospace; font-feature-settings: "tnum" 1; letter-spacing: -0.02em; }
    a { color: inherit; text-decoration: none; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 6px; }
    .eyebrow { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--faint); }

    .app { display: grid; grid-template-columns: var(--rail-w) 1fr; min-height: 100vh; transition: grid-template-columns 0.28s var(--ease); }
    .app.rail-collapsed { grid-template-columns: var(--rail-w-min) 1fr; }
    .app-main { min-width: 0; display: flex; flex-direction: column; max-height: 100vh; overflow: hidden; }
    .content-scroll { flex: 1 1 auto; overflow-y: auto; overflow-x: hidden; }
    .content { max-width: 1640px; margin: 0 auto; padding: 1.6rem 1.8rem 3rem; }

    .rail { background: linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color: var(--rail-text); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; height: 100dvh; border-right: 1px solid rgba(0,0,0,0.2); z-index: 50; -webkit-overflow-scrolling: touch; }
    .rail-brand { display: flex; align-items: center; gap: 0.65rem; padding: 1.0rem 1.15rem; border-bottom: 1px solid var(--rail-line); min-height: var(--topbar-h); }
    .rail-logo { width: 38px; height: 38px; border-radius: 10px; background: #fff; padding: 4px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px -8px rgba(0,0,0,0.6); }
    .rail-logo-fallback { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; background: linear-gradient(135deg, var(--accent), var(--accent-700)); display: flex; align-items: center; justify-content: center; }
    .rail-brand-text { min-width: 0; flex: 1 1 auto; transition: opacity 0.2s var(--ease); }
    .rail-brand-text h1 { font-size: 0.92rem; color: #fff; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rail-brand-text p  { font-size: 0.68rem; color: var(--rail-text-2); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .rail-nav { flex: 1 1 auto; overflow-y: auto; padding: 1rem 0.7rem; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
    .rail-section-label { font-size: 0.64rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--rail-text-2); padding: 0 0.85rem; margin: 0.4rem 0 0.55rem; transition: opacity 0.2s var(--ease); }
    .rail-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.7rem 0.85rem; margin: 2px 0; border-radius: 11px; color: var(--rail-text); font-size: 0.875rem; font-weight: 500; border: 1px solid transparent; transition: background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); position: relative; white-space: nowrap; }
    .rail-link i:first-child { font-size: 1.2rem; flex-shrink: 0; width: 22px; text-align: center; }
    .rail-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
    .rail-link.active { background: linear-gradient(100deg, rgba(0,0,0,0.24), rgba(0,0,0,0.08)); color: #fff; border-color: rgba(255,255,255,0.2); }
    .rail-link.active::before { content: ''; position: absolute; left: -0.7rem; top: 50%; transform: translateY(-50%); width: 3px; height: 22px; border-radius: 0 4px 4px 0; background: var(--accent); }
    .rail-link .chev { margin-left: auto; opacity: 0.5; font-size: 1rem; }
    .rail-badge { margin-left: auto; background: var(--bad); color: #fff; font-size: 0.68rem; font-weight: 700; padding: 0.15rem 0.48rem; border-radius: 999px; line-height: 1.4; flex-shrink: 0; }
    .rail-foot { padding: 0.7rem; border-top: 1px solid var(--rail-line); }
    .rail-signout { display: flex; align-items: center; gap: 0.85rem; padding: 0.7rem 0.85rem; border-radius: 11px; color: #fda4af; font-size: 0.875rem; font-weight: 500; border: 1px solid rgba(244,63,94,0.18); transition: background 0.18s var(--ease); white-space: nowrap; }
    .rail-signout i { font-size: 1.2rem; width: 22px; text-align: center; }
    .rail-signout:hover { background: rgba(244,63,94,0.16); color: #fecdd3; }

    .app.rail-collapsed .rail-brand-text, .app.rail-collapsed .rail-section-label, .app.rail-collapsed .rail-link span,
    .app.rail-collapsed .rail-link .chev, .app.rail-collapsed .rail-link .rail-badge, .app.rail-collapsed .rail-signout span { opacity: 0; pointer-events: none; width: 0; overflow: hidden; }
    .app.rail-collapsed .rail-link, .app.rail-collapsed .rail-signout { justify-content: center; gap: 0; }
    .app.rail-collapsed .rail-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .app.rail-collapsed .rail-section-label { height: 0; margin: 0; padding: 0; }

    .rail-scrim { position: fixed; inset: 0; background: rgba(15,23,42,0.5); backdrop-filter: blur(2px); z-index: 45; opacity: 0; visibility: hidden; transition: opacity 0.25s var(--ease), visibility 0.25s var(--ease); }

    .topbar { height: var(--topbar-h); flex-shrink: 0; display: flex; align-items: center; gap: 1rem; padding: 0 1.4rem; background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 30; }
    .icon-btn { width: 40px; height: 40px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); background: var(--surface); color: var(--ink-2); font-size: 1.2rem; cursor: pointer; transition: background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease); position: relative; flex-shrink: 0; }
    .icon-btn:hover { background: var(--surface-2); border-color: #cbd5e1; color: var(--ink); }
    .hamburger { display: none; }
    .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 0.6rem; }
    .bell-dot { position: absolute; top: 8px; right: 9px; width: 8px; height: 8px; border-radius: 50%; background: var(--bad); border: 2px solid var(--surface); }
    .avatar-chip { display: flex; align-items: center; gap: 0.6rem; padding: 0.3rem 0.55rem 0.3rem 0.35rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); cursor: pointer; transition: background 0.18s var(--ease), border-color 0.18s var(--ease); }
    .avatar-chip:hover { background: var(--surface-2); border-color: #cbd5e1; }
    .avatar { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; background: linear-gradient(135deg, var(--accent), var(--accent-700)); color: #fff; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', sans-serif; }
    .avatar-meta { line-height: 1.15; text-align: left; }
    .avatar-meta .nm { font-size: 0.82rem; font-weight: 600; color: var(--ink); }
    .avatar-meta .rl { font-size: 0.7rem; color: var(--muted); }
    .dropdown { position: relative; }
    .dropdown-panel { position: absolute; right: 0; top: calc(100% + 0.55rem); width: 340px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow-pop); overflow: hidden; opacity: 0; transform: translateY(-6px) scale(0.98); transform-origin: top right; pointer-events: none; transition: opacity 0.18s var(--ease), transform 0.18s var(--ease); z-index: 50; }
    .dropdown.open .dropdown-panel { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    .dropdown-head { padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--line-soft); display: flex; align-items: center; justify-content: space-between; }
    .dropdown-head h4 { font-size: 0.92rem; }
    .dropdown-item { display: flex; gap: 0.75rem; padding: 0.8rem 1.1rem; border-bottom: 1px solid var(--line-soft); transition: background 0.15s var(--ease); }
    .dropdown-item:hover { background: var(--surface-2); }
    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-menu-item { display: flex; align-items: center; gap: 0.7rem; width: 100%; padding: 0.7rem 1.1rem; font-size: 0.86rem; color: var(--ink-2); transition: background 0.15s var(--ease); cursor: pointer; }
    .dropdown-menu-item i { font-size: 1.05rem; color: var(--muted); width: 20px; text-align: center; }
    .dropdown-menu-item:hover { background: var(--surface-2); color: var(--ink); }
    .dropdown-foot { padding: 0.7rem 1.1rem; border-top: 1px solid var(--line-soft); background: var(--surface-2); }

    .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .page-head h2 { font-size: 1.55rem; font-weight: 800; color: var(--ink); }
    .page-head p { color: var(--muted); font-size: 0.9rem; margin-top: 0.25rem; }
    .date-chip { display: inline-flex; align-items: center; gap: 0.55rem; padding: 0.55rem 0.9rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: var(--shadow-card); }
    .date-chip i { color: var(--accent); font-size: 1.1rem; }
    .date-chip span { font-size: 0.85rem; font-weight: 600; color: var(--ink-2); }

    .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-card); overflow: hidden; transition: transform 0.22s var(--ease), box-shadow 0.22s var(--ease), border-color 0.22s var(--ease); }
    .card.hoverable:hover { transform: translateY(-3px); box-shadow: var(--shadow-pop); border-color: #d8dde6; }
    .card-head { display: flex; align-items: center; justify-content: space-between; padding: 1.05rem 1.3rem; border-bottom: 1px solid var(--line-soft); gap: 0.7rem; flex-wrap: wrap; }
    .card-head h3 { font-size: 1rem; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 0.55rem; }
    .card-head h3 i { color: var(--accent); font-size: 1.15rem; }
    .card-head-icon { width: 38px; height: 38px; border-radius: 11px; background: var(--accent-soft); display: flex; align-items: center; justify-content: center; color: var(--accent); font-size: 1.05rem; flex-shrink: 0; }
    .card-body { padding: 1.3rem; }

    .stat { position: relative; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-card); overflow: hidden; transition: transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
    .stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-pop); }
    .stat::after { content: ''; position: absolute; inset: 0 0 auto 0; height: 3px; background: var(--stat-accent, var(--accent)); }
    .stat.a-demand { --stat-accent: linear-gradient(90deg, var(--accent-700), var(--accent)); }
    .stat.a-warn { --stat-accent: linear-gradient(90deg, #fbbf24, #d97706); }
    .stat-body { padding: 1.25rem 1.3rem; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
    .stat-label { font-size: 0.8rem; font-weight: 500; color: var(--muted); }
    .stat-value { font-size: 2rem; font-weight: 700; margin-top: 0.35rem; line-height: 1; }
    .stat-ico { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
    .ico-demand { background: var(--accent-soft); color: var(--accent-ink); }
    .ico-warn { background: var(--warn-soft); color: var(--warn-ink); }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.875rem; font-weight: 600; padding: 0.65rem 1.1rem; border-radius: var(--radius-sm); border: 1px solid transparent; cursor: pointer; white-space: nowrap; transition: background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease); }
    .btn:active { transform: translateY(1px); }
    .btn-sm { padding: 0.5rem 0.85rem; font-size: 0.82rem; }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 8px 18px -10px rgba(0,0,0,0.35); }
    .btn-primary:hover { background: var(--accent-700); }
    .btn-ghost { background: var(--surface); color: var(--ink-2); border-color: var(--line); }
    .btn-ghost:hover { background: var(--surface-2); border-color: #cbd5e1; }

    .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.32rem 0.7rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em; }
    .badge-info { background: var(--accent-soft); color: var(--accent-ink); }
    .badge-count { background: var(--accent-soft); color: var(--accent-ink); padding: 0.28rem 0.6rem; }

    .evaluation-grid { display: grid; gap: 0.75rem; }
    .evaluation-row { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: 1rem; background: var(--surface); border-radius: 12px; border: 1px solid var(--line); transition: all 0.3s ease; }
    .evaluation-row:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: var(--accent); }
    .evaluation-info { display: flex; flex-direction: column; gap: 0.25rem; }
    .evaluation-name { font-weight: 600; color: var(--ink); font-size: 0.95rem; }
    .evaluation-meta { display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; color: var(--muted); flex-wrap: wrap; }
    .evaluation-meta-item { display: flex; align-items: center; gap: 0.25rem; }

    .toast-stack { position: fixed; top: 1.1rem; right: 1.1rem; z-index: 80; display: flex; flex-direction: column; gap: 0.7rem; width: 360px; max-width: calc(100vw - 2rem); }
    .toast { display: flex; gap: 0.8rem; align-items: flex-start; background: var(--surface); border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 14px; padding: 0.9rem 1rem; box-shadow: var(--shadow-pop); animation: toastIn 0.3s var(--ease); }
    .toast.leaving { animation: toastOut 0.3s var(--ease) forwards; }
    @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(20px); } }
    .toast.ok { border-left-color: var(--ok); } .toast.warn { border-left-color: var(--warn); } .toast.err { border-left-color: var(--bad); }
    .toast-ico { font-size: 1.3rem; flex-shrink: 0; margin-top: 1px; }
    .toast.ok .toast-ico { color: var(--ok); } .toast.warn .toast-ico { color: var(--warn); } .toast.err .toast-ico { color: var(--bad); }
    .toast-body { flex: 1 1 auto; min-width: 0; }
    .toast-title { font-size: 0.86rem; font-weight: 700; color: var(--ink); }
    .toast-msg { font-size: 0.8rem; color: var(--muted); margin-top: 2px; word-break: break-word; }
    .toast-close { color: var(--faint); cursor: pointer; font-size: 1.1rem; flex-shrink: 0; }
    .toast-close:hover { color: var(--ink); }

    .empty { text-align: center; padding: 3rem 1rem; color: var(--faint); }
    .empty i { font-size: 3.2rem; color: #cbd5e1; }
    .empty h4 { font-size: 1.05rem; color: var(--muted); margin-top: 0.8rem; font-weight: 600; }
    .empty p { font-size: 0.85rem; color: var(--faint); margin-top: 0.3rem; }
    .empty.compact { padding: 1.6rem 1rem; }
    .empty.compact i { font-size: 2.2rem; }

    ::-webkit-scrollbar { width: 9px; height: 9px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; border: 2px solid transparent; background-clip: content-box; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }

    .grid-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }

    @media (max-width: 1200px) { .grid-stats { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 900px) {
      .app, .app.rail-collapsed { grid-template-columns: 1fr; }
      .rail { position: fixed; top: 0; left: 0; width: var(--rail-w); height: 100vh; height: 100dvh; transform: translateX(-100%); transition: transform 0.3s var(--ease); }
      .app.rail-open .rail { transform: translateX(0); box-shadow: 24px 0 60px -20px rgba(0,0,0,0.5); }
      .app.rail-open .rail-scrim { opacity: 1; visibility: visible; }
      .hamburger { display: inline-flex; }
      .content { padding: 1.1rem 1.1rem 2.5rem; }
    }
    @media (max-width: 640px) {
      .grid-stats { grid-template-columns: 1fr; }
      .avatar-meta { display: none; }
      .page-head h2 { font-size: 1.3rem; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; } }
  </style>
</head>

<body>
<div class="app" id="app">
  <div class="rail-scrim" onclick="closeMobileRail()" aria-hidden="true"></div>

  <aside class="rail" id="rail">
    <div class="rail-brand">
      <div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">
        <img src="../images/lspu-logo.png" alt="LSPU" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-government-line text-white text-lg"></i>
        </div>
        <img src="../images/chmt-logo.png" alt="CHMT" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-hotel-line text-white text-lg"></i>
        </div>
      </div>
      <div class="rail-brand-text">
        <h1>CHMT Admin</h1>
        <p>Training Demand</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="CHMT.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>
      <a href="CHMT_Training_Demand.php" class="rail-link active">
        <i class="ri-stack-line"></i><span>Training Demand</span>
        <?php if (!empty($forwardedDemandRows)): ?>
          <span class="rail-badge num"><?= count($forwardedDemandRows) ?></span>
        <?php else: ?>
          <i class="ri-arrow-right-s-line chev"></i>
        <?php endif; ?>
      </a>

      <p class="rail-section-label">Forms</p>
      <a href="CHMT_Assessment Form.php" class="rail-link">
        <i class="ri-survey-line"></i><span>Assessment Form</span>
      </a>
      <a href="CHMT_Individual_Development_Plan_Form.php" class="rail-link">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
      </a>
      <a href="chmt_eval.php" class="rail-link">
        <i class="ri-file-list-3-line"></i><span>Evaluation</span>
      </a>
    </nav>

    <div class="rail-foot">
      <a href="?logout=true" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <div class="app-main">
    <header class="topbar">
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu">
        <i class="ri-menu-line"></i>
      </button>
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn" style="display:none;">
        <i class="ri-side-bar-line"></i>
      </button>

      <div class="topbar-right">
        <!-- 2026-09-06 fix - the notification bell only belongs on the
             Dashboard (matching Assessment Form / IDP Forms / Evaluation,
             none of which have one). Removed here for consistency - see
             CLAUDE.md. -->
        <div class="dropdown" id="avatarDropdown">
          <button class="avatar-chip" onclick="toggleDropdown('avatarDropdown')" aria-label="Account menu">
            <span class="avatar"><?= htmlspecialchars($initials) ?></span>
            <span class="avatar-meta">
              <span class="nm"><?= htmlspecialchars($adminName) ?></span>
              <span class="rl">CHMT Dean / Admin</span>
            </span>
            <i class="ri-arrow-down-s-line" style="color:var(--muted);"></i>
          </button>
          <div class="dropdown-panel" style="width:240px;">
            <div class="dropdown-head">
              <div style="display:flex;align-items:center;gap:0.65rem;">
                <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                <div style="line-height:1.2;">
                  <div style="font-size:0.85rem;font-weight:700;"><?= htmlspecialchars($adminName) ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);">College of Hospitality Management and Tourism</div>
                </div>
              </div>
            </div>
            <a class="dropdown-menu-item" href="?logout=true">
              <i class="ri-logout-box-line"></i> Sign out
            </a>
          </div>
        </div>
      </div>
    </header>

    <div class="content-scroll">
      <div class="content">

        <div class="page-head">
          <div>
            <p class="eyebrow">HR Training Demand Pipeline</p>
            <h2>Training Demand</h2>
            <p>Trainings HR has forwarded to CHMT because your own faculty/staff requested them — report back once you've found a provider.</p>
          </div>
          <div style="display:flex;align-items:center;gap:0.7rem;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openPostOpportunityModal()">
              <i class="ri-megaphone-line"></i> Post a Training Opportunity
            </button>
            <div class="date-chip">
              <i class="ri-calendar-2-line"></i>
              <span><?= date('l, F j, Y') ?></span>
            </div>
          </div>
        </div>

        <!-- 2026-09-06 - "Post a Training Opportunity": for when the dean
             already found a real training on their own (no employee ever
             asked for it through the system first) - the "hey, I found a
             training, who wants to join?" case an ISO tester reported
             happening informally over Messenger. Posts it directly into
             the Training Found stage; employees see it as an open
             opportunity and sign themselves up (training_recommendations.php),
             same pipeline from there on. -->
        <p style="font-size:0.8rem;color:var(--muted);margin:-0.6rem 0 1.1rem;">
          Already found something on your own, with no employee request behind it? <a href="#" onclick="openPostOpportunityModal();return false;" style="color:var(--accent);font-weight:600;">Post it as an opportunity</a> instead of a group chat - your <?= htmlspecialchars('CHMT') ?> staff will see it and can sign up right in the system.
        </p>
        </div>

        <section class="grid-stats" style="grid-template-columns:repeat(2,1fr);max-width:640px;">
          <div class="stat a-warn">
            <div class="stat-body">
              <div>
                <p class="stat-label">Awaiting Your Response</p>
                <h3 class="stat-value num" style="color:var(--warn-ink);"><?= count($forwardedDemandRows) ?></h3>
              </div>
              <div class="stat-ico ico-warn"><i class="ri-time-line"></i></div>
            </div>
          </div>
          <div class="stat a-demand">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Requesters (cross-college)</p>
                <h3 class="stat-value num" style="color:var(--accent);"><?= array_sum(array_map(fn($d) => $d['requester_count'], $forwardedDemandRows)) ?></h3>
              </div>
              <div class="stat-ico ico-demand"><i class="ri-team-line"></i></div>
            </div>
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <h3><i class="ri-stack-line"></i> Training Demand Forwarded to You</h3>
            <?php if (!empty($forwardedDemandRows)): ?>
              <span class="badge badge-info num"><?= count($forwardedDemandRows) ?> awaiting your response</span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!empty($forwardedDemandRows)): ?>
              <div class="evaluation-grid" style="grid-template-columns:repeat(auto-fit, minmax(340px, 1fr));">
                <?php foreach ($forwardedDemandRows as $d): ?>
                  <div class="evaluation-row" style="grid-template-columns:1fr auto;align-items:start;">
                    <div class="evaluation-info">
                      <div class="evaluation-name"><?= htmlspecialchars($d['title']) ?></div>
                      <div class="evaluation-meta">
                        <span class="evaluation-meta-item"><i class="ri-price-tag-3-line" style="color:var(--accent);"></i> <?= $d['training_type'] ? htmlspecialchars($d['training_type']) : '<span style="color:var(--muted);font-style:italic;">To be determined</span>' ?></span>
                        <span class="evaluation-meta-item"><i class="ri-team-line" style="color:var(--accent);"></i> <?= (int)$d['requester_count'] ?> requester(s) total (<?= htmlspecialchars($d['requester_breakdown']) ?>)</span>
                        <?php if (!empty($d['budget_hint'])): ?>
                          <span class="evaluation-meta-item"><i class="ri-wallet-3-line" style="color:var(--accent);"></i> Budget guidance: <?= htmlspecialchars($d['budget_hint']) ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($d['description'])): ?>
                        <p style="font-size:0.82rem;color:var(--muted);margin-top:0.5rem;"><?= htmlspecialchars($d['description']) ?></p>
                      <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.4rem;flex-shrink:0;align-items:stretch;">
                      <button type="button" class="btn btn-primary btn-sm" style="flex-shrink:0;"
                        onclick="openReportDemandModal(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['title']), ENT_QUOTES) ?>)">
                        <i class="ri-send-plane-line"></i> Report to HR
                      </button>
                      <button type="button" onclick="viewDemandRequesters(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['title']), ENT_QUOTES) ?>)" style="background:transparent;border:1px solid #94a3b8;color:#475569;padding:0.45rem 0.75rem;border-radius:8px;font-size:0.72rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                        <i class="ri-eye-line"></i> View Requesters
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="empty">
                <i class="ri-inbox-line"></i>
                <h4>Nothing forwarded yet</h4>
                <p>HR will forward training demand requests here once your faculty/staff accept AI-recommended trainings.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const ToastIcons = { ok: 'ri-checkbox-circle-line', warn: 'ri-error-warning-line', err: 'ri-close-circle-line', info: 'ri-information-line' };
const ToastTitles = { ok: 'Success', warn: 'Heads up', err: 'Something went wrong', info: 'Notice' };

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

function toggleRailCollapse() { document.getElementById('app').classList.toggle('rail-collapsed'); }
function openMobileRail() { document.getElementById('app').classList.add('rail-open'); }
function closeMobileRail() { document.getElementById('app').classList.remove('rail-open'); }
function syncCollapseButton() {
  const btn = document.getElementById('collapseBtn');
  if (!btn) return;
  btn.style.display = window.innerWidth > 900 ? 'inline-flex' : 'none';
  if (window.innerWidth > 900) closeMobileRail();
}

function toggleDropdown(id) {
  const target = document.getElementById(id);
  document.querySelectorAll('.dropdown.open').forEach(d => { if (d !== target) d.classList.remove('open'); });
  target.classList.toggle('open');
}
function closeAllDropdowns() { document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open')); }

// 2026-09-06 fix - the notification bell (and its poll/mark-read JS)
// only belongs on the Dashboard, matching Assessment Form / IDP Forms /
// Evaluation. Removed here. See CLAUDE.md.

/* Training Demand — report a found paid training back to HR. Same
   behavior as every other college's dashboard/dedicated page. */
function checkTimeValidityGeneric(startId, endId, feedbackId) {
  const start = document.getElementById(startId)?.value;
  const end = document.getElementById(endId)?.value;
  const feedback = document.getElementById(feedbackId);
  if (!feedback) return true;
  if (!start || !end) { feedback.textContent = ''; return true; }
  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const diffMinutes = (eh * 60 + em) - (sh * 60 + sm);
  if (diffMinutes <= 0) {
    feedback.textContent = 'Invalid - end time must be after start time (check for an AM/PM mix-up, e.g. 12 AM is midnight, not noon).';
    feedback.style.color = '#dc2626';
    return false;
  }
  const hours = Math.floor(diffMinutes / 60);
  const minutes = diffMinutes % 60;
  const parts = [];
  if (hours > 0) parts.push(`${hours} hour${hours !== 1 ? 's' : ''}`);
  if (minutes > 0) parts.push(`${minutes} minute${minutes !== 1 ? 's' : ''}`);
  feedback.textContent = `Duration: ${parts.join(' ')}`;
  feedback.style.color = '#16a34a';
  return true;
}

function checkReportTimeValidity() {
  return checkTimeValidityGeneric('reportStartTime', 'reportEndTime', 'reportTimeFeedback');
}

function updateReportVenueLabel(modality) {
  const labelText = document.getElementById('reportVenueLabelText');
  const input = document.getElementById('reportVenue');
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

function formatReportDateRange(startStr, endStr) {
  if (!startStr) return '';
  const opts = { year: 'numeric', month: 'long', day: 'numeric' };
  const start = new Date(startStr + 'T00:00:00');
  if (!endStr || endStr === startStr) { return start.toLocaleDateString('en-US', opts); }
  const end = new Date(endStr + 'T00:00:00');
  if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
    return `${start.toLocaleDateString('en-US', { month: 'long' })} ${start.getDate()}-${end.getDate()}, ${start.getFullYear()}`;
  }
  return `${start.toLocaleDateString('en-US', opts)} - ${end.toLocaleDateString('en-US', opts)}`;
}


function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// 2026-09-06 rework - was a fixed-height scrolling div with position:sticky
// table headers, which renders broken (header overlapping data rows on
// scroll) in combination with border-collapse - a known CSS interaction,
// not something worth fighting. Paginating instead means the table never
// needs to scroll internally at all, which removes the bug at its root
// and was asked for anyway.
const REQUESTERS_PER_PAGE = 8;
let requesterPageState = { rows: [], title: '', page: 1 };
const REQUESTER_STATUS_META = {
  'Accepted': { label: 'Forwarded to HR', color: '#2563eb', bg: 'rgba(37,99,235,0.1)' },
  'Training Available': { label: 'Training Found', color: '#0d9488', bg: 'rgba(13,148,136,0.1)' },
  'Confirmed': { label: 'Confirmed', color: '#059669', bg: 'rgba(5,150,105,0.1)' },
  'Completed': { label: 'Completed', color: '#047857', bg: 'rgba(4,120,87,0.1)' },
  'Not Selected': { label: 'Not Selected', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
};

function buildRequestersModalHtml() {
  const totalPages = Math.max(1, Math.ceil(requesterPageState.rows.length / REQUESTERS_PER_PAGE));
  if (requesterPageState.page > totalPages) requesterPageState.page = totalPages;
  if (requesterPageState.page < 1) requesterPageState.page = 1;
  const start = (requesterPageState.page - 1) * REQUESTERS_PER_PAGE;
  const pageRows = requesterPageState.rows.slice(start, start + REQUESTERS_PER_PAGE);
  const rowsHtml = pageRows.map(r => {
    const meta = REQUESTER_STATUS_META[r.status] || { label: r.status, color: '#64748b', bg: 'rgba(100,116,139,0.1)' };
    return `
    <tr style="border-bottom:1px solid #e5e7eb;">
      <td style="padding:0.6rem 0.7rem;text-align:left;font-size:0.85rem;font-weight:600;color:#0f172a;">${escapeHtml(r.name)}</td>
      <td style="padding:0.6rem 0.7rem;text-align:left;font-size:0.8rem;color:#64748b;">${escapeHtml(r.department)}</td>
      <td style="padding:0.6rem 0.7rem;text-align:left;">
        <span style="display:inline-flex;align-items:center;padding:0.28rem 0.65rem;border-radius:999px;font-size:0.68rem;font-weight:700;letter-spacing:0.02em;color:${meta.color};background:${meta.bg};">${escapeHtml(meta.label)}</span>
      </td>
    </tr>`;
  }).join('');
  return `
    <p style="font-size:0.82rem;color:#64748b;text-align:left;margin-bottom:1rem;">${escapeHtml(requesterPageState.title)}</p>
    <div style="text-align:left;border:1px solid #e5e7eb;border-radius:10px;">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:2px solid #e5e7eb;background:#f8fafc;">
            <th style="padding:0.55rem 0.7rem;text-align:left;font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#475569;">Name</th>
            <th style="padding:0.55rem 0.7rem;text-align:left;font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#475569;">College</th>
            <th style="padding:0.55rem 0.7rem;text-align:left;font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#475569;">Status</th>
          </tr>
        </thead>
        <tbody>${rowsHtml || '<tr><td colspan="3" style="padding:1rem;text-align:center;color:#94a3b8;">No requesters found.</td></tr>'}</tbody>
      </table>
    </div>
    <div style="display:flex;justify-content:center;align-items:center;gap:0.7rem;margin-top:0.9rem;">
      <button type="button" onclick="changeRequesterPage(-1)" ${requesterPageState.page <= 1 ? 'disabled' : ''} style="padding:0.35rem 0.7rem;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:0.78rem;cursor:pointer;">Prev</button>
      <span style="font-size:0.78rem;color:#64748b;">Page ${requesterPageState.page} of ${totalPages}</span>
      <button type="button" onclick="changeRequesterPage(1)" ${requesterPageState.page >= totalPages ? 'disabled' : ''} style="padding:0.35rem 0.7rem;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:0.78rem;cursor:pointer;">Next</button>
    </div>`;
}

function changeRequesterPage(delta) {
  requesterPageState.page += delta;
  Swal.update({ html: buildRequestersModalHtml() });
}
function viewDemandRequesters(demandId, title) {
  fetch('../get_demand_requesters.php?id=' + demandId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        Swal.fire({ icon: 'error', title: 'Could not load', text: data.message || 'Please try again.', confirmButtonColor: '#2563eb' });
        return;
      }
      requesterPageState = { rows: data.requesters, title: data.title || title, page: 1 };
      Swal.fire({
        title: 'Who Requested This',
        width: 500,
        customClass: { popup: 'rounded-2xl' },
        didOpen: () => {
          const t = document.querySelector('.swal2-title');
          if (t) t.style.cssText = "font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:1.35rem;color:#0f172a;";
        },
        html: buildRequestersModalHtml(),
        confirmButtonText: 'Close',
        confirmButtonColor: '#2563eb',
      });
    })
    .catch(() => {
      Swal.fire({ icon: 'error', title: 'Could not load', text: 'Please try again.', confirmButtonColor: '#2563eb' });
    });
}

// 2026-09-06 - this system also covers free seminars/workshops. Checking
// the box disables the cost field (rather than just leaving it optional)
// so it's unambiguous whether "blank" means "free" or "forgot to fill in".
function toggleReportFreeTraining(isFree) {
  const costInput = document.getElementById('reportCost');
  costInput.disabled = isFree;
  if (isFree) costInput.value = '';
}

// "Post a Training Opportunity" (2026-09-06) - see create_dean_sourced_training.php
// for the full design. Deliberately its own function rather than reusing
// openReportDemandModal() with a null demandId: that function's whole
// premise is "HR already created this demand, you're reporting back on
// it" (title comes from the existing idea) - this one starts from
// nothing, so it needs its own Title/Description fields up front instead.
function openPostOpportunityModal() {
  const todayStr = new Date().toISOString().split('T')[0];
  const fieldLabel = 'display:block;font-size:0.78rem;font-weight:600;color:#334155;margin-bottom:0.3rem;';
  const fieldInput = 'width:100%;padding:0.5rem 0.7rem;border:1px solid #e5e7eb;border-radius:8px;font-size:0.82rem;color:#0f172a;box-sizing:border-box;';
  Swal.fire({
    title: 'Post a Training Opportunity',
    width: 720,
    customClass: { popup: 'rounded-2xl' },
    didOpen: () => {
      const t = document.querySelector('.swal2-title');
      if (t) t.style.cssText = "font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:1.35rem;color:#0f172a;";
    },
    html: `
      <p style="font-size:0.82rem;color:#64748b;text-align:left;margin-bottom:1rem;">A real training you already found, with no employee request behind it - your college's staff will see this and can sign up directly.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1rem;text-align:left;">
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Training Title</label>
          <input type="text" id="oppTitle" style="${fieldInput}" placeholder="e.g. BFAR Regional Aquaculture Innovation Seminar 2026">
        </div>
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Description <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <textarea id="oppDescription" style="${fieldInput}min-height:50px;resize:none;overflow:hidden;" placeholder="What's it about, who should attend" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"></textarea>
        </div>
        <div>
          <label style="${fieldLabel}">Training Provider</label>
          <input type="text" id="oppProvider" style="${fieldInput}" placeholder="e.g. XYZ Institute">
        </div>
        <div>
          <label style="${fieldLabel}">Cost</label>
          <input type="text" id="oppCost" style="${fieldInput}" placeholder="e.g. ₱4,000/head">
          <label style="display:flex;align-items:center;gap:0.4rem;margin-top:0.4rem;font-size:0.78rem;color:#334155;cursor:pointer;">
            <input type="checkbox" id="oppIsFree" onchange="document.getElementById('oppCost').disabled=this.checked; if(this.checked) document.getElementById('oppCost').value='';"> This training is free
          </label>
        </div>
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Date(s)</label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <input type="date" id="oppDateStart" style="${fieldInput}" min="${todayStr}" onchange="document.getElementById('oppDateEnd').min=this.value">
            <span style="color:#94a3b8;font-size:0.78rem;flex-shrink:0;">to</span>
            <input type="date" id="oppDateEnd" style="${fieldInput}" min="${todayStr}">
          </div>
          <p style="font-size:0.72rem;color:#94a3b8;margin:0.3rem 0 0;">Leave "to" blank for a single-day training.</p>
        </div>
        <div>
          <label style="${fieldLabel}">Start Time <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="time" id="oppStartTime" style="${fieldInput}" oninput="checkTimeValidityGeneric('oppStartTime','oppEndTime','oppTimeFeedback')">
        </div>
        <div>
          <label style="${fieldLabel}">End Time <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="time" id="oppEndTime" style="${fieldInput}" oninput="checkTimeValidityGeneric('oppStartTime','oppEndTime','oppTimeFeedback')">
        </div>
        <p id="oppTimeFeedback" style="grid-column:1/-1;font-size:0.76rem;margin:-0.35rem 0 0;"></p>
        <div>
          <label style="${fieldLabel}">Capacity (max people)</label>
          <input type="number" id="oppCapacity" style="${fieldInput}" min="1" placeholder="e.g. 10">
        </div>
        <div>
          <label style="${fieldLabel}">Venue / Platform <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="text" id="oppVenue" style="${fieldInput}" placeholder="e.g. LSPU Main Campus, or Zoom/Google Meet">
        </div>
        <div>
          <label style="${fieldLabel}">Modality</label>
          <select id="oppModality" style="${fieldInput}">
            <option value="">Select modality</option>
            <option value="Face-to-Face">Face-to-Face</option>
            <option value="Online">Online</option>
          </select>
        </div>
        <div>
          <label style="${fieldLabel}">Training Type</label>
          <select id="oppTrainingType" style="${fieldInput}">
            <option value="">Select training type</option>
            <option value="Workshop">Workshop</option>
            <option value="Seminar">Seminar</option>
            <option value="Webinar">Webinar</option>
            <option value="Conference">Conference</option>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Additional Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <textarea id="oppNotes" style="${fieldInput}min-height:50px;resize:none;overflow:hidden;" placeholder="Anything else worth noting" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"></textarea>
        </div>
      </div>
    `,
    confirmButtonText: 'Post Opportunity',
    confirmButtonColor: '#2563eb',
    cancelButtonColor: '#64748b',
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    preConfirm: () => {
      const oppTitle = document.getElementById('oppTitle').value.trim();
      const description = document.getElementById('oppDescription').value.trim();
      const provider = document.getElementById('oppProvider').value.trim();
      const cost = document.getElementById('oppCost').value.trim();
      const isFree = document.getElementById('oppIsFree').checked;
      const dateStart = document.getElementById('oppDateStart').value;
      const dateEnd = document.getElementById('oppDateEnd').value;
      const dates = formatReportDateRange(dateStart, dateEnd);
      const capacity = document.getElementById('oppCapacity').value.trim();
      const venue = document.getElementById('oppVenue').value.trim();
      const modality = document.getElementById('oppModality').value;
      const trainingType = document.getElementById('oppTrainingType').value;
      const notes = document.getElementById('oppNotes').value.trim();
      const missing = [];
      if (!oppTitle) missing.push('Training Title');
      if (!provider) missing.push('Training Provider');
      if (!isFree && !cost) missing.push('Cost (or check "This training is free")');
      if (!dateStart) missing.push('Date(s)');
      if (!capacity) missing.push('Capacity');
      if (!modality) missing.push('Modality');
      if (!trainingType) missing.push('Training Type');
      if (missing.length > 0) { Swal.showValidationMessage(`Please fill in: ${missing.join(', ')}.`); return false; }
      if (dateEnd && dateStart && dateEnd < dateStart) { Swal.showValidationMessage('End date must be on or after the start date.'); return false; }
      if (!checkTimeValidityGeneric('oppStartTime', 'oppEndTime', 'oppTimeFeedback')) { Swal.showValidationMessage('End time must be after start time - check for an AM/PM mix-up (12 AM is midnight, not noon).'); return false; }
      const startTime = document.getElementById('oppStartTime').value;
      const endTime = document.getElementById('oppEndTime').value;
      const body = new URLSearchParams({
        title: oppTitle, description, found_provider: provider, found_cost: cost, is_free: isFree ? '1' : '0',
        found_dates: dates, found_start_time: startTime, found_end_time: endTime,
        found_capacity: capacity, found_venue: venue, actual_modality: modality, training_type: trainingType, found_notes: notes
      });
      return fetch('../create_dean_sourced_training.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(r => r.json())
        .then(data => { if (!data.success) { Swal.showValidationMessage(data.message || 'Could not post this opportunity.'); return false; } return data; })
        .catch(() => { Swal.showValidationMessage('Could not post this opportunity.'); return false; });
    }
  }).then((result) => {
    if (result.isConfirmed && result.value) {
      showToast('ok', 'Posted', `Your staff has been notified (${result.value.notified_count} employee(s)).`);
    }
  });
}

function openReportDemandModal(demandId, title) {
  const todayStr = new Date().toISOString().split('T')[0];
  const fieldLabel = 'display:block;font-size:0.78rem;font-weight:600;color:#334155;margin-bottom:0.3rem;';
  const fieldInput = 'width:100%;padding:0.5rem 0.7rem;border:1px solid #e5e7eb;border-radius:8px;font-size:0.82rem;color:#0f172a;box-sizing:border-box;';
  Swal.fire({
    title: 'Report Training Found',
    width: 720,
    customClass: { popup: 'rounded-2xl' },
    didOpen: () => {
      const t = document.querySelector('.swal2-title');
      if (t) t.style.cssText = "font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:1.35rem;color:#0f172a;";
    },
    html: `
      <p style="font-size:0.82rem;color:#64748b;text-align:left;margin-bottom:1rem;">${title}</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1rem;text-align:left;">
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Actual Training Title <span style="font-weight:400;color:#94a3b8;">(optional - fill in if the real training has a different, more specific name)</span></label>
          <input type="text" id="reportFoundTitle" style="${fieldInput}" placeholder="e.g. BFAR Regional Aquaculture Innovation Seminar 2026">
        </div>
        <div>
          <label style="${fieldLabel}">Training Provider</label>
          <input type="text" id="reportProvider" style="${fieldInput}" placeholder="e.g. XYZ Institute">
        </div>
        <div>
          <label style="${fieldLabel}">Cost</label>
          <input type="text" id="reportCost" style="${fieldInput}" placeholder="e.g. ₱4,000/head">
          <label style="display:flex;align-items:center;gap:0.4rem;margin-top:0.4rem;font-size:0.78rem;color:#334155;cursor:pointer;">
            <input type="checkbox" id="reportIsFree" onchange="toggleReportFreeTraining(this.checked)"> This training is free
          </label>
        </div>
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Date(s)</label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <input type="date" id="reportDateStart" style="${fieldInput}" min="${todayStr}" onchange="document.getElementById('reportDateEnd').min=this.value">
            <span style="color:#94a3b8;font-size:0.78rem;flex-shrink:0;">to</span>
            <input type="date" id="reportDateEnd" style="${fieldInput}" min="${todayStr}">
          </div>
          <p style="font-size:0.72rem;color:#94a3b8;margin:0.3rem 0 0;">Leave "to" blank for a single-day training.</p>
        </div>
        <div>
          <label style="${fieldLabel}">Start Time <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="time" id="reportStartTime" style="${fieldInput}" oninput="checkReportTimeValidity()">
        </div>
        <div>
          <label style="${fieldLabel}">End Time <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="time" id="reportEndTime" style="${fieldInput}" oninput="checkReportTimeValidity()">
        </div>
        <p id="reportTimeFeedback" style="grid-column:1/-1;font-size:0.76rem;margin:-0.35rem 0 0;"></p>
        <div>
          <label style="${fieldLabel}">Capacity (max people)</label>
          <input type="number" id="reportCapacity" style="${fieldInput}" min="1" placeholder="e.g. 10">
        </div>
        <div>
          <label style="${fieldLabel}"><span id="reportVenueLabelText">Venue / Platform</span> <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <input type="text" id="reportVenue" style="${fieldInput}" placeholder="e.g. LSPU Main Campus, or Zoom/Google Meet">
        </div>
        <div>
          <label style="${fieldLabel}">Modality</label>
          <select id="reportModality" style="${fieldInput}" onchange="updateReportVenueLabel(this.value)">
            <option value="">Select modality</option>
            <option value="Face-to-Face">Face-to-Face</option>
            <option value="Online">Online</option>
          </select>
        </div>
        <div>
          <label style="${fieldLabel}">Training Type</label>
          <select id="reportTrainingType" style="${fieldInput}">
            <option value="">Select training type</option>
            <option value="Workshop">Workshop</option>
            <option value="Seminar">Seminar</option>
            <option value="Webinar">Webinar</option>
            <option value="Conference">Conference</option>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label style="${fieldLabel}">Additional Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
          <textarea id="reportNotes" style="${fieldInput}min-height:60px;resize:none;overflow:hidden;" placeholder="Anything else HR should know" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"></textarea>
        </div>
      </div>
    `,
    confirmButtonText: 'Report to HR',
    confirmButtonColor: '#2563eb',
    cancelButtonColor: '#64748b',
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    allowEscapeKey: false,
    preConfirm: () => {
      const provider = document.getElementById('reportProvider').value.trim();
      const cost = document.getElementById('reportCost').value.trim();
      const dateStart = document.getElementById('reportDateStart').value;
      const dateEnd = document.getElementById('reportDateEnd').value;
      const dates = formatReportDateRange(dateStart, dateEnd);
      const capacity = document.getElementById('reportCapacity').value.trim();
      const venue = document.getElementById('reportVenue').value.trim();
      const modality = document.getElementById('reportModality').value;
      const trainingType = document.getElementById('reportTrainingType').value;
      const isFree = document.getElementById('reportIsFree').checked;
      const foundTitle = document.getElementById('reportFoundTitle').value.trim();
      const notes = document.getElementById('reportNotes').value.trim();
      const missing = [];
      if (!provider) missing.push('Training Provider');
      if (!isFree && !cost) missing.push('Cost (or check "This training is free")');
      if (!dateStart) missing.push('Date(s)');
      if (!capacity) missing.push('Capacity');
      if (!modality) missing.push('Modality');
      if (!trainingType) missing.push('Training Type');
      if (missing.length > 0) { Swal.showValidationMessage(`Please fill in: ${missing.join(', ')}.`); return false; }
      if (dateEnd && dateStart && dateEnd < dateStart) { Swal.showValidationMessage('End date must be on or after the start date.'); return false; }
      if (!checkReportTimeValidity()) { Swal.showValidationMessage('End time must be after start time - check for an AM/PM mix-up (12 AM is midnight, not noon).'); return false; }
      const startTime = document.getElementById('reportStartTime').value;
      const endTime = document.getElementById('reportEndTime').value;
      const body = new URLSearchParams({
        demand_id: demandId, found_provider: provider, found_cost: cost, is_free: isFree ? '1' : '0', found_training_title: foundTitle,
        found_dates: dates, found_start_time: startTime, found_end_time: endTime,
        found_capacity: capacity, found_venue: venue, actual_modality: modality, training_type: trainingType, found_notes: notes
      });
      return fetch('../report_training_demand.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(r => r.json())
        .then(data => { if (!data.success) { Swal.showValidationMessage(data.message || 'Could not report this training.'); return false; } return data; })
        .catch(() => { Swal.showValidationMessage('Could not report this training.'); return false; });
    }
  }).then((result) => {
    if (result.isConfirmed && result.value) {
      showToast('ok', 'Reported', 'HR has been notified of the training you found.');
      setTimeout(() => location.reload(), 800);
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  syncCollapseButton();
  document.addEventListener('click', (e) => { if (!e.target.closest('.dropdown')) closeAllDropdowns(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeAllDropdowns(); closeMobileRail(); } });
  window.addEventListener('resize', syncCollapseButton);

  <?php if (!empty($success_message)): ?>
    showToast('ok', 'Success', <?= json_encode($success_message) ?>);
  <?php endif; ?>
  <?php if (!empty($error_message)): ?>
    showToast('err', 'Error', <?= json_encode($error_message) ?>);
  <?php endif; ?>
});
</script>
</body>
</html>
