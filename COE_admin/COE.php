<?php
/* =============================================================================
   LSPU — COLLEGE OF ENGINEERING (COE) DEPARTMENT ADMIN DASHBOARD
   -----------------------------------------------------------------------------
   This file mirrors the visual system of admin_page.php (rail sidebar, topbar,
   stat cards, cards, badges, progress bars, tables, modals, toasts) while
   keeping COE.php's original backend logic (faculty stats, evaluation
   workflow, notifications) functionally the same.

   NEW IN THIS VERSION:
     • Sidebar links for "Assessment Form" and "IDP Forms".
     • Assessment Form analytics block (based on the `assessments` +
       `settings` tables, same pattern as admin_page.php).
     • IDP Forms analytics block. ⚠ ASSUMPTION: no IDP table was present in
       the files shared with me, so this queries a guessed table named
       `idp_forms` (id, user_id, status, created_at). It fails SAFELY (shows
       an "not available" empty state) if that table doesn't exist — it will
       not break the page. Please confirm/adjust the table & column names to
       match your real schema.
   ============================================================================= */

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
if (($_SESSION['user_role'] ?? '') !== 'admin_coe') {
    header("Location: ../index.php");
    exit();
}

// Database connection - adjusted path (one level up)
require_once '../config.php';
require_once '../ml_recommendations.php';

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

// Get current year for evaluation tracking
$current_year = date('Y');

// Initialize arrays to prevent undefined variable warnings
$stats = [];
$unevaluated_faculty = [];
$recent_evaluations = [];
$evaluation_details = null;
$evaluation_ratings = [];
$workflow_history = [];
$evaluation_stats = [];
$error_message = '';
$show_evaluation_modal = false;

// Default values for stats
$stats = [
    'total_faculty' => 0,
    'teaching' => 0,
    'non_teaching' => 0,
    'evaluated_this_year' => 0,
    'pending_evaluations' => 0,
        'progress_percentage' => 0
];

/* -----------------------------------------------------------------------------
   TRAINING DEMAND FORWARDED TO COE — HR's demand-aggregation pipeline
   (see ../ml_recommendations.php for the training_demand schema and
   ../report_training_demand.php for the endpoint this section posts to).
   Only demand HR has forwarded, with at least one COE requester.
   2026-09-03 - ported into this redesigned dashboard from the pre-redesign
   COE.php, which had this feature but this file's visual backbone (from
   docs/department.zip) didn't - see CLAUDE.md.
   ----------------------------------------------------------------------------- */
ensureTrainingRecommendationsTable($con); // also ensures training_demand exists

// users.department is inconsistently populated (short codes vs. full
// college names) - fetch every "Forwarded to Dean" demand's requesters
// with their raw department, then filter/count in PHP via
// canonicalTnaCollegeCode() rather than an exact 'COE' string match, so
// this keeps working correctly regardless of how a given employee's
// department happens to be stored (see forward_training_demand.php and
// report_training_demand.php for the same fix, same reasoning).
$forwardedDemandStmt = $con->query("
    SELECT d.id, d.title, d.description, d.training_type, d.updated_at, u.department, tr.preferred_modality
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
            'own_requester_count' => 0,
            'college_counts' => [],
            'modality_counts' => [],
        ];
    }
    // Modality (2026-08-29) - independent of department resolution, so
    // tallied unconditionally, not gated on $code !== null like college is.
    $modalityKey = in_array($candidate['preferred_modality'] ?? null, ['Face-to-Face', 'Online'], true) ? $candidate['preferred_modality'] : 'Unspecified';
    $forwardedDemandRows[$candidate['id']]['modality_counts'][$modalityKey] = ($forwardedDemandRows[$candidate['id']]['modality_counts'][$modalityKey] ?? 0) + 1;
    if ($code !== null) {
        $forwardedDemandRows[$candidate['id']]['college_counts'][$code] = ($forwardedDemandRows[$candidate['id']]['college_counts'][$code] ?? 0) + 1;
        if ($code === 'COE') {
            $forwardedDemandRows[$candidate['id']]['own_requester_count']++;
        }
    }
}
// Only show demands where COE actually has a requester (unchanged gating) -
// but show the TRUE cross-college total, not just COE's own count, so the
// dean sourcing a provider knows who they're actually sourcing for.
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

// Get COE department statistics
try {
    // Total COE employees (users with role 'user' AND teaching_status is not null)
    $total_result = $con->query("SELECT COUNT(*) as count FROM users WHERE department = 'COE' AND role = 'user' AND teaching_status IS NOT NULL AND teaching_status != ''");
    if ($total_result) {
        $stats['total_faculty'] = $total_result->fetch_assoc()['count'];
    }

    // Teaching staff - Using flexible query to handle different cases
    $teaching_result = $con->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE department = 'COE' 
        AND role = 'user' 
        AND (teaching_status = 'Teaching' OR teaching_status = 'teaching' OR LOWER(teaching_status) LIKE '%teaching%' AND LOWER(teaching_status) NOT LIKE '%non%')
    ");
    if ($teaching_result) {
        $stats['teaching'] = $teaching_result->fetch_assoc()['count'];
    }

    // Non-teaching staff - Using flexible query
    $non_teaching_result = $con->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE department = 'COE' 
        AND role = 'user' 
        AND (teaching_status = 'Non-Teaching' OR teaching_status = 'Non Teaching' OR teaching_status = 'non-teaching' OR LOWER(teaching_status) LIKE '%non%teaching%')
    ");
    if ($non_teaching_result) {
        $stats['non_teaching'] = $non_teaching_result->fetch_assoc()['count'];
    }

    // Alternative approach: If still zero, get all and categorize
    if ($stats['teaching'] == 0 && $stats['non_teaching'] == 0) {
        $all_result = $con->query("
            SELECT teaching_status, COUNT(*) as count 
            FROM users 
            WHERE department = 'COE' 
            AND role = 'user' 
            AND teaching_status IS NOT NULL 
            AND teaching_status != '' 
            GROUP BY teaching_status
        ");

        if ($all_result) {
            while ($row = $all_result->fetch_assoc()) {
                $status = strtolower($row['teaching_status']);
                if (strpos($status, 'non') !== false || strpos($status, 'non-teaching') !== false || strpos($status, 'non teaching') !== false) {
                    $stats['non_teaching'] += $row['count'];
                } else {
                    $stats['teaching'] += $row['count'];
                }
            }
        }
    }

    // Get evaluation statistics
    $evaluation_stats_result = $con->query("
        SELECT 
            COUNT(*) as total_evaluations,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM evaluations
        WHERE EXISTS (
            SELECT 1 FROM users u WHERE u.id = evaluations.user_id AND u.department = 'COE'
        )
    ");

    if ($evaluation_stats_result) {
        $evaluation_stats = $evaluation_stats_result->fetch_assoc();
    } else {
        $evaluation_stats = [
            'total_evaluations' => 0,
            'approved' => 0,
            'submitted' => 0,
            'draft' => 0,
            'rejected' => 0
        ];
    }

    // Evaluated faculty (have at least one evaluation record)
    $evaluated_result = $con->query("
        SELECT COUNT(DISTINCT e.user_id) as count 
        FROM evaluations e
        JOIN users u ON e.user_id = u.id
        WHERE u.department = 'COE'
        AND YEAR(e.created_at) = '$current_year'
    ");
    if ($evaluated_result) {
        $stats['evaluated_this_year'] = $evaluated_result->fetch_assoc()['count'];
    }

    // Pending evaluations (faculty without any evaluation record for current year)
    $pending_result = $con->query("
        SELECT COUNT(*) as count 
        FROM users u 
        WHERE u.department = 'COE' 
        AND u.role = 'user'
        AND u.teaching_status IS NOT NULL 
        AND u.teaching_status != ''
        AND NOT EXISTS (
            SELECT 1 FROM evaluations e WHERE e.user_id = u.id AND YEAR(e.created_at) = '$current_year'
        )
    ");
    if ($pending_result) {
        $stats['pending_evaluations'] = $pending_result->fetch_assoc()['count'];
    }

    // Calculate progress percentage
    $stats['progress_percentage'] = $stats['total_faculty'] > 0 ?
        round(($stats['evaluated_this_year'] / $stats['total_faculty']) * 100) : 0;

    // Get unevaluated faculty (COE users without any evaluation record for current year)
    $unevaluated_result = $con->query("
        SELECT id, name, teaching_status 
        FROM users 
        WHERE department = 'COE' 
        AND role = 'user'
        AND teaching_status IS NOT NULL 
        AND teaching_status != ''
        AND NOT EXISTS (
            SELECT 1 FROM evaluations WHERE user_id = users.id AND YEAR(created_at) = '$current_year'
        )
        ORDER BY name ASC
    ");

    if ($unevaluated_result && $unevaluated_result->num_rows > 0) {
        while ($row = $unevaluated_result->fetch_assoc()) {
            $unevaluated_faculty[] = $row;
        }
    }

    // Get recent evaluations with status information
    $recent_result = $con->query("
        SELECT 
            u.id,
            u.name,
            u.teaching_status,
            e.id as evaluation_id,
            e.status as evaluation_status,
            e.created_at
        FROM evaluations e
        JOIN users u ON e.user_id = u.id
        WHERE u.department = 'COE'
        ORDER BY e.created_at DESC 
        LIMIT 5
    ");

    if ($recent_result && $recent_result->num_rows > 0) {
        while ($row = $recent_result->fetch_assoc()) {
            $recent_evaluations[] = $row;
        }
    }

} catch (Exception $e) {
    error_log("Database error in COE.php: " . $e->getMessage());
    $error_message = "An error occurred while loading dashboard data. Please try again later.";
}

/* =============================================================================
   NEW · ASSESSMENT FORM ANALYTICS (COE department)
   Reuses the same `assessments` + `settings` tables/columns as admin_page.php
   (settings: id, title, submission_deadline, is_active — assessments: id,
   user_id, deadline_id, submission_date).
   ============================================================================= */
$assessmentStats = [
    'has_active_deadline' => false,
    'active_title'        => null,
    'active_deadline'     => null,
    'total_eligible'      => 0,
    'on_time'             => 0,
    'late'                => 0,
    'no_submission'       => 0,
    'total_submitted'     => 0,
    'submission_rate'     => 0.0,
];

try {
    $activeDeadlineRes = $con->query("SELECT * FROM settings WHERE is_active = 1 LIMIT 1");
    $activeDeadline = $activeDeadlineRes ? $activeDeadlineRes->fetch_assoc() : null;

    if ($activeDeadline) {
        $assessmentStats['has_active_deadline'] = true;
        $assessmentStats['active_title']    = $activeDeadline['title'] ?? 'Training Needs Assessment';
        $assessmentStats['active_deadline'] = $activeDeadline['submission_deadline'] ?? null;

        $eligibleRes = $con->query("SELECT COUNT(*) AS c FROM users WHERE department = 'COE' AND status = 'accepted'");
        $assessmentStats['total_eligible'] = $eligibleRes ? (int)$eligibleRes->fetch_assoc()['c'] : 0;

        $stmt = $con->prepare("
            SELECT 
                CASE 
                    WHEN a.id IS NULL THEN 'No Submission'
                    WHEN a.submission_date <= ? THEN 'On Time'
                    ELSE 'Late'
                END AS submission_status,
                COUNT(DISTINCT u.id) AS count
            FROM users u
            LEFT JOIN assessments a ON u.id = a.user_id AND a.deadline_id = ?
            WHERE u.department = 'COE' AND u.status = 'accepted'
            GROUP BY submission_status
        ");
        if ($stmt) {
            $stmt->bind_param("si", $activeDeadline['submission_deadline'], $activeDeadline['id']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                switch ($row['submission_status']) {
                    case 'On Time':       $assessmentStats['on_time']       = (int)$row['count']; break;
                    case 'Late':          $assessmentStats['late']          = (int)$row['count']; break;
                    case 'No Submission': $assessmentStats['no_submission'] = (int)$row['count']; break;
                }
            }
            $stmt->close();
        }

        $assessmentStats['total_submitted'] = $assessmentStats['on_time'] + $assessmentStats['late'];
        $assessmentStats['submission_rate'] = $assessmentStats['total_eligible'] > 0
            ? round(($assessmentStats['total_submitted'] / $assessmentStats['total_eligible']) * 100, 1)
            : 0.0;
    }
} catch (Exception $e) {
    error_log("Assessment Form analytics error in COE.php: " . $e->getMessage());
}

/* =============================================================================
   NEW · IDP (INDIVIDUAL DEVELOPMENT PLAN) FORM ANALYTICS — COE department
   ⚠ ASSUMPTION: queries a table named `idp_forms` with columns
   (id, user_id, status, created_at) where status is one of
   draft / submitted / approved / rejected. This table was not present in
   the files shared with me — please confirm/adjust to your real schema.
   This block fails safely: if the table doesn't exist, the dashboard shows
   a "not available yet" empty state instead of breaking.
   ============================================================================= */
$idpStats = [
    'table_available' => false,
    'total_eligible'   => 0,
    'draft'            => 0,
    'submitted'        => 0,
    'approved'         => 0,
    'rejected'         => 0,
    'total_created'    => 0,
    'no_idp'           => 0,
    'completion_rate'  => 0.0,
];

$idpTableCheck = @$con->query("SHOW TABLES LIKE 'idp_forms'");
if ($idpTableCheck && $idpTableCheck->num_rows > 0) {
    $idpStats['table_available'] = true;
    try {
        $eligibleRes = $con->query("SELECT COUNT(*) AS c FROM users WHERE department = 'COE' AND status = 'accepted'");
        $idpStats['total_eligible'] = $eligibleRes ? (int)$eligibleRes->fetch_assoc()['c'] : 0;

        $idpCountsRes = $con->query("
            SELECT i.status, COUNT(DISTINCT i.user_id) AS count
            FROM idp_forms i
            JOIN users u ON i.user_id = u.id
            WHERE u.department = 'COE'
            GROUP BY i.status
        ");
        if ($idpCountsRes) {
            while ($row = $idpCountsRes->fetch_assoc()) {
                $status = strtolower(trim($row['status']));
                if (array_key_exists($status, $idpStats)) {
                    $idpStats[$status] = (int)$row['count'];
                }
            }
        }

        $idpStats['total_created'] = $idpStats['draft'] + $idpStats['submitted'] + $idpStats['approved'] + $idpStats['rejected'];
        $idpStats['no_idp']        = max(0, $idpStats['total_eligible'] - $idpStats['total_created']);
        $idpStats['completion_rate'] = $idpStats['total_eligible'] > 0
            ? round(($idpStats['approved'] / $idpStats['total_eligible']) * 100, 1)
            : 0.0;
    } catch (Exception $e) {
        error_log("IDP Form analytics error in COE.php: " . $e->getMessage());
    }
}

// Handle sending evaluation to HR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_evaluation'])) {
    $evaluation_id = $_POST['evaluation_id'];

    try {
        // Update evaluation status to submitted
        $stmt = $con->prepare("UPDATE evaluations SET status = 'submitted', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $evaluation_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            // Add to workflow history
            $workflow_sql = "INSERT INTO evaluation_workflow (evaluation_id, from_status, to_status, changed_by) 
                             VALUES (?, 'draft', 'submitted', ?)";
            $workflow_stmt = $con->prepare($workflow_sql);
            $workflow_stmt->bind_param("ii", $evaluation_id, $user_id);
            $workflow_stmt->execute();

            $_SESSION['success_message'] = "Evaluation submitted to HR successfully!";
            header("Location: COE.php?success=1");
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to update evaluation status. The evaluation may have already been submitted.";
            header("Location: COE.php");
            exit();
        }
    } catch (Exception $e) {
        error_log("Error sending evaluation: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: Unable to submit evaluation. Please try again.";
        header("Location: COE.php");
        exit();
    }
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
            evaluator.name as evaluator_name
        FROM evaluations e
        JOIN users u ON e.user_id = u.id
        LEFT JOIN users evaluator ON e.evaluator_id = evaluator.id
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
            $error_message = "Evaluation not found or you don't have permission to view it.";
        }
    } catch (Exception $e) {
        error_log("Error viewing evaluation: " . $e->getMessage());
        $error_message = "Database error: Unable to load evaluation details.";
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get real-time notifications for the bell (2026-09-06 fix - see
// getDeanNotifications() in ml_recommendations.php: this also replaces
// the dead "system_activities" query above, which referenced a table
// that doesn't exist in the real DB and always silently returned
// nothing, with the same real training_demand notification source
// CCS/CCJE already had).
$deanNotif = getDeanNotifications($con, $user_id, 'COE');
$notifications = $deanNotif['items'];
$notificationCount = $deanNotif['unread_count'];

/** Percentage helper used by the progress bars (same as admin_page.php). */
function pct(float $part, float $whole): float {
    return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>COE Admin Dashboard · LSPU TNA</title>

  <!-- Fonts (matches admin_page.php) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- Tailwind (CDN) — same enterprise token extension as admin_page.php -->
  <link rel="stylesheet" href="../assets/css/tw-33.css">

  <!-- ===========================================================
       DESIGN SYSTEM — copied 1:1 from admin_page.php so both dashboards
       share the exact same look. COE-only additions are appended at the
       bottom under "COE EXTRAS".
       =========================================================== -->
  <style>
    :root {
      --bg:          #f5f6f8;
      --bg-grad:     radial-gradient(1100px 600px at 100% -8%, rgba(6,95,70,0.05), transparent 60%);
      --surface:     #ffffff;
      --surface-2:   #f8fafc;
      --surface-3:   #f1f3f7;
      --ink:         #0f172a;
      --ink-2:       #334155;
      --muted:       #64748b;
      --faint:       #94a3b8;
      --line:        #e5e7eb;
      --line-soft:   #eef1f5;
      --accent:      #065f46;
      --accent-600:  #065f46;
      --accent-700:  #047857;
      --accent-soft: #d1fae5;
      --accent-ink:  #064e3b;
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
      --rail:        #0a2e1a;
      --rail-2:      #1a4d2a;
      --rail-line:   rgba(148,163,184,0.14);
      --rail-text:   rgba(226,232,240,0.74);
      --rail-text-2: rgba(148,163,184,0.55);
      --radius:      14px;
      --radius-sm:   10px;
      --radius-lg:   18px;
      --rail-w:      264px;
      --rail-w-min:  78px;
      --topbar-h:    66px;
      --ease:        cubic-bezier(0.16, 1, 0.3, 1);
      --shadow-card: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -18px rgba(15,23,42,0.22);
      --shadow-pop:  0 16px 40px -12px rgba(15,23,42,0.22);
      --green:       #065f46;
      --green-soft:  #d1fae5;
      --grey:        #6b7280;
      --grey-soft:   #f3f4f6;
    }

    *, *::before, *::after { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; }
    body {
      margin: 0; font-family: 'Inter', system-ui, sans-serif; color: var(--ink);
      background: var(--bg-grad), var(--bg); -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
    }
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

    .rail {
      background: linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color: var(--rail-text);
      display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh;
      border-right: 1px solid rgba(0,0,0,0.2); z-index: 50;
    }
    .rail-brand { display: flex; align-items: center; gap: 0.65rem; padding: 1.0rem 1.15rem; border-bottom: 1px solid var(--rail-line); min-height: var(--topbar-h); }
    .rail-logo { width: 38px; height: 38px; border-radius: 10px; background: #fff; padding: 4px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px -8px rgba(0,0,0,0.6); }
    .rail-logo-fallback { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; background: linear-gradient(135deg, var(--green), var(--green-light)); display: flex; align-items: center; justify-content: center; }
    .rail-brand-text { min-width: 0; transition: opacity 0.2s var(--ease); }
    .rail-brand-text h1 { font-size: 0.92rem; color: #fff; line-height: 1.15; white-space: nowrap; }
    .rail-brand-text p  { font-size: 0.68rem; color: var(--rail-text-2); margin-top: 2px; white-space: nowrap; }

    .rail-nav { flex: 1 1 auto; overflow-y: auto; padding: 1rem 0.7rem; }
    .rail-section-label { font-size: 0.64rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--rail-text-2); padding: 0 0.85rem; margin: 0.4rem 0 0.55rem; transition: opacity 0.2s var(--ease); }
    .rail-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.7rem 0.85rem; margin: 2px 0; border-radius: 11px; color: var(--rail-text); font-size: 0.875rem; font-weight: 500; border: 1px solid transparent; transition: background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); position: relative; white-space: nowrap; }
    .rail-link i:first-child { font-size: 1.2rem; flex-shrink: 0; width: 22px; text-align: center; }
    .rail-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
    .rail-link.active { background: linear-gradient(100deg, rgba(6,95,70,0.26), rgba(6,95,70,0.08)); color: #fff; border-color: rgba(5,150,105,0.35); }
    .rail-link.active::before { content: ''; position: absolute; left: -0.7rem; top: 50%; transform: translateY(-50%); width: 3px; height: 22px; border-radius: 0 4px 4px 0; background: var(--green); }
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
    .avatar { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; background: linear-gradient(135deg, var(--green), var(--green-light)); color: #fff; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', sans-serif; }
    .avatar-meta { line-height: 1.15; text-align: left; }
    .avatar-meta .nm { font-size: 0.82rem; font-weight: 600; color: var(--ink); }
    .avatar-meta .rl { font-size: 0.7rem; color: var(--muted); }
    .dropdown { position: relative; }
    .dropdown-panel { position: absolute; right: 0; top: calc(100% + 0.55rem); width: 340px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow-pop); overflow: hidden; opacity: 0; transform: translateY(-6px) scale(0.98); transform-origin: top right; pointer-events: none; transition: opacity 0.18s var(--ease), transform 0.18s var(--ease); z-index: 50; }
    .dropdown.open .dropdown-panel { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    /* 2026-09-06 fix - .dropdown-panel had no height cap, so with more
       than a couple of notifications the panel just grew past the
       bottom of the screen and took the "Mark all as read" footer with
       it - visible in the dropdown but physically unreachable/unclickable
       below the fold. The list itself scrolls now; the header and footer
       stay pinned and always reachable. See CLAUDE.md. */
    .dropdown-panel { max-height: min(70vh, 480px); display: flex; flex-direction: column; }
    #notifDropdownList { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
    .dropdown-head { padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--line-soft); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .dropdown-head h4 { font-size: 0.92rem; }
    .dropdown-item { display: flex; gap: 0.75rem; padding: 0.8rem 1.1rem; border-bottom: 1px solid var(--line-soft); transition: background 0.15s var(--ease); }
    .dropdown-item:hover { background: var(--surface-2); }
    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-menu-item { display: flex; align-items: center; gap: 0.7rem; width: 100%; padding: 0.7rem 1.1rem; font-size: 0.86rem; color: var(--ink-2); transition: background 0.15s var(--ease); cursor: pointer; }
    .dropdown-menu-item i { font-size: 1.05rem; color: var(--muted); width: 20px; text-align: center; }
    .dropdown-menu-item:hover { background: var(--surface-2); color: var(--ink); }
    .dropdown-menu-item.danger { color: var(--bad-ink); }
    .dropdown-menu-item.danger i { color: var(--bad); }
    .dropdown-menu-item.danger:hover { background: var(--bad-soft); }
    .dropdown-foot { padding: 0.7rem 1.1rem; border-top: 1px solid var(--line-soft); background: var(--surface-2); flex-shrink: 0; }

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
    .stat.a-green  { --stat-accent: linear-gradient(90deg, #059669, #065f46); }
    .stat.a-grey   { --stat-accent: linear-gradient(90deg, #9ca3af, #6b7280); }
    .stat.a-emerald { --stat-accent: linear-gradient(90deg, #34d399, #059669); }
    .stat.a-rose   { --stat-accent: linear-gradient(90deg, #fb7185, #e11d48); }
    .stat-body { padding: 1.25rem 1.3rem; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
    .stat-label { font-size: 0.8rem; font-weight: 500; color: var(--muted); }
    .stat-value { font-size: 2rem; font-weight: 700; margin-top: 0.35rem; line-height: 1; }
    .stat-ico { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
    .ico-green  { background: var(--accent-soft); color: var(--accent); }
    .ico-grey   { background: var(--grey-soft); color: var(--grey); }
    .ico-emerald { background: var(--ok-soft);     color: var(--ok); }
    .ico-rose   { background: var(--bad-soft);    color: var(--bad); }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.875rem; font-weight: 600; padding: 0.65rem 1.1rem; border-radius: var(--radius-sm); border: 1px solid transparent; cursor: pointer; white-space: nowrap; transition: background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease); }
    .btn:active { transform: translateY(1px); }
    .btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .btn-sm { padding: 0.5rem 0.85rem; font-size: 0.82rem; }
    .btn-lg { padding: 0.85rem 1.3rem; font-size: 0.92rem; }
    .btn-block { width: 100%; }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 8px 18px -10px rgba(6,95,70,0.7); }
    .btn-primary:hover { background: var(--accent-700); }
    .btn-ghost { background: var(--surface); color: var(--ink-2); border-color: var(--line); }
    .btn-ghost:hover { background: var(--surface-2); border-color: #cbd5e1; }
    .btn-success { background: var(--ok); color: #fff; box-shadow: 0 8px 18px -10px rgba(5,150,105,0.6); }
    .btn-success:hover { background: #047857; }
    .btn-warning { background: var(--warn); color: #fff; box-shadow: 0 8px 18px -10px rgba(217,119,6,0.55); }
    .btn-warning:hover { background: #b45309; }
    .btn-danger { background: var(--bad); color: #fff; box-shadow: 0 8px 18px -10px rgba(225,29,72,0.55); }
    .btn-danger:hover { background: #be123c; }
    .btn-green { background: var(--green); color: #fff; box-shadow: 0 8px 18px -10px rgba(6,95,70,0.55); }
    .btn-green:hover { background: #047857; }
    .btn-grey { background: var(--grey); color: #fff; box-shadow: 0 8px 18px -10px rgba(107,114,128,0.55); }
    .btn-grey:hover { background: #4b5563; }

    .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.32rem 0.7rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em; }
    .badge-pending  { background: var(--warn-soft); color: var(--warn-ink); }
    .badge-accepted { background: var(--ok-soft);   color: var(--ok-ink); }
    .badge-declined { background: var(--bad-soft);  color: var(--bad-ink); }
    .badge-on-time  { background: var(--ok-soft);   color: var(--ok-ink); }
    .badge-late     { background: var(--warn-soft); color: var(--warn-ink); }
    .badge-no-sub   { background: var(--bad-soft);  color: var(--bad-ink); }
    .badge-info     { background: var(--accent-soft); color: var(--accent-ink); }
    .badge-count    { background: var(--accent-soft); color: var(--accent-ink); padding: 0.28rem 0.6rem; }
    .badge-green    { background: var(--green-soft); color: #064e3b; }
    .badge-grey     { background: var(--grey-soft); color: #374151; }
    .badge-neutral  { background: var(--surface-3); color: var(--ink-2); }

    .progress { height: 9px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
    .progress > span { display: block; height: 100%; border-radius: 999px; transition: width 0.8s var(--ease); }
    .pf-ok   { background: var(--ok); }
    .pf-warn { background: var(--warn); }
    .pf-bad  { background: var(--bad); }
    .pf-green { background: var(--green); }
    .pf-grey { background: var(--grey); }
    .pf-accent { background: var(--accent); }

    .table-wrap { border: 1px solid var(--line); border-radius: var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: var(--surface-2); text-align: left; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); padding: 0.8rem 1.15rem; border-bottom: 1px solid var(--line); }
    table.data td { padding: 0.85rem 1.15rem; border-bottom: 1px solid var(--line-soft); font-size: 0.875rem; color: var(--ink-2); }
    table.data tbody tr:nth-child(even) { background: var(--surface-2); }
    table.data tbody tr:hover { background: var(--accent-soft); }
    table.data tbody tr:last-child td { border-bottom: none; }
    table.data td.strong { font-weight: 600; color: var(--ink); }

    .modal { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15,23,42,0.55); backdrop-filter: blur(6px); }
    .modal.open { display: flex; animation: fadeIn 0.18s var(--ease); }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-box { background: var(--surface); border-radius: 20px; width: 100%; max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 30px 60px -20px rgba(15,23,42,0.45); animation: popIn 0.26s var(--ease); }
    @keyframes popIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--line); flex-shrink: 0; }
    .modal-head h3 { font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.6rem; }
    .modal-head h3 i { color: var(--accent); }
    .modal-body { padding: 1.4rem 1.5rem; overflow-y: auto; flex: 1 1 auto; }
    .modal-foot { display: flex; justify-content: flex-end; gap: 0.7rem; padding: 1rem 1.5rem; border-top: 1px solid var(--line); background: var(--surface-2); flex-shrink: 0; }
    .modal-x { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; transition: background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); }
    .modal-x:hover { background: var(--bad-soft); color: var(--bad); border-color: #fecdd3; }

    .toast-stack { position: fixed; top: 1.1rem; right: 1.1rem; z-index: 80; display: flex; flex-direction: column; gap: 0.7rem; width: 360px; max-width: calc(100vw - 2rem); }
    .toast { display: flex; gap: 0.8rem; align-items: flex-start; background: var(--surface); border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 14px; padding: 0.9rem 1rem; box-shadow: var(--shadow-pop); animation: toastIn 0.3s var(--ease); }
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

    .empty { text-align: center; padding: 3rem 1rem; color: var(--faint); }
    .empty i { font-size: 3.2rem; color: #cbd5e1; }
    .empty h4 { font-size: 1.05rem; color: var(--muted); margin-top: 0.8rem; font-weight: 600; }
    .empty p { font-size: 0.85rem; color: var(--faint); margin-top: 0.3rem; }
    .empty.compact { padding: 1.6rem 1rem; }
    .empty.compact i { font-size: 2.2rem; }

    .chart-box { position: relative; height: 260px; width: 100%; padding: 0.25rem; }
    .legend { display: flex; justify-content: center; gap: 1.2rem; flex-wrap: wrap; margin-top: 0.9rem; }
    .legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; font-weight: 500; color: var(--muted); }
    .legend-swatch { width: 12px; height: 12px; border-radius: 4px; }

    ::-webkit-scrollbar { width: 9px; height: 9px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; border: 2px solid transparent; background-clip: content-box; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }

    .grid-stats    { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-2col     { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.15rem; margin-bottom: 1.4rem; }
    .grid-main     { display: grid; grid-template-columns: 1fr 1fr; gap: 1.15rem; align-items: start; }
    .stack { display: flex; flex-direction: column; gap: 1.15rem; }

    @media (max-width: 1200px) { .grid-stats { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 1024px) { .grid-2col, .grid-main { grid-template-columns: 1fr; } }
    @media (max-width: 900px) {
      .app, .app.rail-collapsed { grid-template-columns: 1fr; }
      .rail { position: fixed; top: 0; left: 0; width: var(--rail-w); height: 100vh; height: 100dvh; transform: translateX(-100%); transition: transform 0.3s var(--ease); }
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
    @media (max-width: 420px) { .grid-stats { grid-template-columns: 1fr; } }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; } }

    /* ===========================================================
       COE EXTRAS — kept from the original COE.php for the faculty
       evaluation grid, the evaluation-detail modal, and the
       notification list. Reskinned to use the tokens above.
       =========================================================== */
    .evaluation-grid { display: grid; gap: 0.75rem; }
    .evaluation-row { display: grid; grid-template-columns: 1fr auto auto; gap: 1rem; align-items: center; padding: 1rem; background: var(--surface); border-radius: 12px; border: 1px solid var(--line); transition: all 0.3s ease; }
    .evaluation-row:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: var(--green); }
    .evaluation-info { display: flex; flex-direction: column; gap: 0.25rem; }
    .evaluation-name { font-weight: 600; color: var(--ink); font-size: 0.95rem; }
    .evaluation-meta { display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; color: var(--muted); flex-wrap: wrap; }
    .evaluation-meta-item { display: flex; align-items: center; gap: 0.25rem; }
    .evaluation-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }

    .status-badge { display: inline-flex; align-items: center; padding: 0.32rem 0.7rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; }
    .status-draft     { background: #f3f4f6; color: #374151; }
    .status-submitted { background: #d1fae5; color: #064e3b; }
    .status-approved  { background: var(--ok-soft); color: var(--ok-ink); }
    .status-rejected  { background: var(--bad-soft); color: var(--bad-ink); }

    .workflow-timeline { border-left: 2px solid var(--line); margin-left: 10px; }
    .workflow-step { position: relative; padding-left: 20px; margin-bottom: 1rem; }
    .workflow-step::before { content: ''; position: absolute; left: -6px; top: 6px; width: 10px; height: 10px; border-radius: 50%; background: var(--green); }

    .signature-preview { border: 1px solid var(--line); background: var(--surface-2); height: 80px; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
    .signature-image { max-width: 100%; max-height: 70px; }

    .modal-backdrop { backdrop-filter: blur(8px); background: rgba(15,23,42,0.6); animation: backdropFadeIn 0.25s ease forwards; opacity: 0; }
    @keyframes backdropFadeIn { to { opacity: 1; } }
    .modal-container { max-height: 90vh; overflow-y: auto; width: 95%; max-width: 1200px; animation: modalSlideIn 0.4s var(--ease) forwards; transform: translateY(-30px) scale(0.96); opacity: 0; }
    @keyframes modalSlideIn { to { transform: translateY(0) scale(1); opacity: 1; } }

    .notification-item { transition: all 0.2s ease; border-left: 3px solid transparent; }
    .notification-item:hover { border-left-color: var(--green); background-color: var(--surface-2); }
  </style>
</head>

<body class="min-h-screen <?php echo $show_evaluation_modal ? 'overflow-hidden' : ''; ?>">
<div class="app" id="app">
  <div class="rail-scrim" onclick="closeMobileRail()" aria-hidden="true"></div>

  <!-- ===========================================================
       SIDEBAR RAIL
       =========================================================== -->
  <aside class="rail" id="rail">
    <div class="rail-brand">
      <div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">
        <img src="../images/lspu-logo.png" alt="LSPU" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-government-line text-white text-lg"></i>
        </div>
        <img src="../images/coe-logo.png" alt="COE" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-cpu-line text-white text-lg"></i>
        </div>
      </div>
      <div class="rail-brand-text">
        <h1>COE Admin</h1>
        <p>College of Engineering</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="COE.php" class="rail-link active">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>

      <a href="COE_Training_Demand.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Demand</span>
        <?php if (!empty($forwardedDemandRows)): ?><span class="rail-badge num"><?= count($forwardedDemandRows) ?></span><?php endif; ?>
      </a>

      <p class="rail-section-label">Forms</p>
      <!-- NOTE: verify these paths match your actual department form pages. -->
      <a href="COE_Assessment Form.php" class="rail-link">
        <i class="ri-survey-line"></i><span>Assessment Form</span>
      </a>
      <a href="COE_Individual_Development_Plan_Form.php" class="rail-link">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
      </a>
      <a href="coe_eval.php" class="rail-link">
        <i class="ri-file-list-3-line"></i><span>Evaluation</span>
      </a>
    </nav>

    <div class="rail-foot">
      <a href="?logout=true" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- ===========================================================
       MAIN COLUMN
       =========================================================== -->
  <div class="app-main">
    <header class="topbar">
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu">
        <i class="ri-menu-line"></i>
      </button>
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn" style="display:none;">
        <i class="ri-side-bar-line"></i>
      </button>

      <div class="topbar-right">
        <!-- Notification bell -->
        <div class="dropdown" id="bellDropdown">
          <button class="icon-btn" onclick="toggleDropdown('bellDropdown')" aria-label="Notifications">
            <i class="ri-notification-3-line"></i>
            <?php if ($notificationCount > 0): ?><span class="bell-dot" id="bellDot"></span><?php endif; ?>
          </button>
          <div class="dropdown-panel">
            <div class="dropdown-head">
              <h4>Notifications</h4>
              <?php if ($notificationCount > 0): ?>
                <span class="badge badge-count num" id="notifCountBadge"><?= $notificationCount ?> new</span>
              <?php endif; ?>
            </div>
            <div id="notifDropdownList">
              <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $notification):
                  $ico = 'ri-information-line';
                  if ($notification['type'] === 'evaluation') $ico = 'ri-file-list-3-line';
                  elseif ($notification['type'] === 'pending') $ico = 'ri-time-line';
                  elseif ($notification['type'] === 'training_demand') $ico = 'ri-stack-line';
                  // 2026-09-06 fix - only real, dismissible events (training_demand)
                  // carry is_read; ambient items (evaluation/pending) render as
                  // permanently "seen" since they were never a one-time alert.
                  $isUnread = isset($notification['is_read']) && (int)$notification['is_read'] === 0;
                ?>
                  <div class="dropdown-item"<?= $isUnread ? ' style="background:var(--accent-soft, #d1fae5);"' : '' ?>>
                    <div class="stat-ico ico-green" style="width:38px;height:38px;font-size:1.05rem;<?= $isUnread ? '' : 'opacity:0.6;' ?>">
                      <i class="<?= $ico ?>"></i>
                    </div>
                    <div>
                      <p style="font-size:0.85rem;font-weight:<?= $isUnread ? '700' : '500' ?>;color:var(--ink);"><?= htmlspecialchars($notification['message']) ?></p>
                      <p style="font-size:0.74rem;color:var(--muted);margin-top:2px;"><?= date('M d, Y h:i A', strtotime($notification['timestamp'])) ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="empty compact">
                  <i class="ri-check-double-line"></i>
                  <h4>You're all caught up</h4>
                  <p>No new notifications.</p>
                </div>
              <?php endif; ?>
            </div>
            <div class="dropdown-foot">
              <button class="btn btn-ghost btn-sm btn-block" onclick="markAllAsRead()">Mark all as read</button>
            </div>
          </div>
        </div>

        <!-- User avatar -->
        <div class="dropdown" id="avatarDropdown">
          <?php
            $adminName = $user['name'] ?? 'COE Admin';
            $initials = strtoupper(substr($adminName, 0, 1));
            $parts = preg_split('/\s+/', trim($adminName));
            if (count($parts) > 1) { $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1)); }
          ?>
          <button class="avatar-chip" onclick="toggleDropdown('avatarDropdown')" aria-label="Account menu">
            <span class="avatar"><?= htmlspecialchars($initials) ?></span>
            <span class="avatar-meta">
              <span class="nm"><?= htmlspecialchars($adminName) ?></span>
              <span class="rl">COE Dean / Admin</span>
            </span>
            <i class="ri-arrow-down-s-line" style="color:var(--muted);"></i>
          </button>
          <div class="dropdown-panel" style="width:240px;">
            <div class="dropdown-head">
              <div style="display:flex;align-items:center;gap:0.65rem;">
                <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                <div style="line-height:1.2;">
                  <div style="font-size:0.85rem;font-weight:700;"><?= htmlspecialchars($adminName) ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);">College of Engineering</div>
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
            <p class="eyebrow">Training Needs Assessment</p>
            <h2>COE Admin Dashboard</h2>
            <p>Welcome back, <?= htmlspecialchars($adminName) ?> — here's the current state of your college's faculty evaluations and forms.</p>
          </div>
          <div class="date-chip">
            <i class="ri-calendar-2-line"></i>
            <span><?= date('l, F j, Y') ?></span>
          </div>
        </div>

        <!-- ===========================================================
             STAT CARDS
             =========================================================== -->
        <section class="grid-stats">
          <div class="stat a-green">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Faculty</p>
                <h3 class="stat-value num" style="color:var(--ink);"><?= $stats['total_faculty'] ?></h3>
              </div>
              <div class="stat-ico ico-green"><i class="fas fa-users"></i></div>
            </div>
          </div>

          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Teaching Staff</p>
                <h3 class="stat-value num" style="color:var(--ok);"><?= $stats['teaching'] ?></h3>
              </div>
              <div class="stat-ico ico-emerald"><i class="fas fa-chalkboard-teacher"></i></div>
            </div>
          </div>

          <div class="stat a-grey">
            <div class="stat-body">
              <div>
                <p class="stat-label">Non-Teaching Staff</p>
                <h3 class="stat-value num" style="color:var(--grey);"><?= $stats['non_teaching'] ?></h3>
              </div>
              <div class="stat-ico ico-grey"><i class="fas fa-user-tie"></i></div>
            </div>
          </div>

          <div class="stat a-rose">
            <div class="stat-body">
              <div>
                <p class="stat-label">Evaluation Progress</p>
                <h3 class="stat-value num" style="color:var(--bad);"><?= $stats['progress_percentage'] ?>%</h3>
              </div>
              <div class="stat-ico ico-rose"><i class="fas fa-tasks"></i></div>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             NEW · ASSESSMENT FORM  +  IDP FORMS ANALYTICS
             =========================================================== -->
        <section class="grid-2col">

          <!-- Assessment Form -->
          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-survey-line"></i> Assessment Form Summary</h3>
              <div class="card-head-icon"><i class="ri-bar-chart-2-line"></i></div>
            </div>
            <div class="card-body">
              <?php if ($assessmentStats['has_active_deadline']): ?>
                <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">
                  <i class="ri-calendar-event-line"></i>
                  <strong style="color:var(--ink-2);"><?= htmlspecialchars($assessmentStats['active_title']) ?></strong>
                  — due <?= date('F j, Y · g:i A', strtotime($assessmentStats['active_deadline'])) ?>
                </p>

                <div class="chart-box" style="height:200px;"><canvas id="assessmentChart"></canvas></div>

                <div class="stack" style="gap:0.9rem;margin-top:1rem;">
                  <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.4rem;">
                      <span style="font-weight:500;color:var(--ink-2);">On Time</span>
                      <span class="num" style="font-weight:700;color:var(--ok);"><?= $assessmentStats['on_time'] ?></span>
                    </div>
                    <div class="progress"><span class="pf-ok" style="width:<?= pct($assessmentStats['on_time'], $assessmentStats['total_eligible']) ?>%"></span></div>
                  </div>
                  <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.4rem;">
                      <span style="font-weight:500;color:var(--ink-2);">Late</span>
                      <span class="num" style="font-weight:700;color:var(--warn);"><?= $assessmentStats['late'] ?></span>
                    </div>
                    <div class="progress"><span class="pf-warn" style="width:<?= pct($assessmentStats['late'], $assessmentStats['total_eligible']) ?>%"></span></div>
                  </div>
                  <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.4rem;">
                      <span style="font-weight:500;color:var(--ink-2);">No Submission</span>
                      <span class="num" style="font-weight:700;color:var(--bad);"><?= $assessmentStats['no_submission'] ?></span>
                    </div>
                    <div class="progress"><span class="pf-bad" style="width:<?= pct($assessmentStats['no_submission'], $assessmentStats['total_eligible']) ?>%"></span></div>
                  </div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--line-soft);">
                  <span style="font-size:0.82rem;color:var(--muted);">Submission rate</span>
                  <span class="badge badge-info num"><?= $assessmentStats['submission_rate'] ?>%</span>
                </div>
              <?php else: ?>
                <div class="empty compact">
                  <i class="ri-calendar-close-line"></i>
                  <h4>No active deadline</h4>
                  <p>Once the main admin sets an active TNA deadline, submission analytics for COE will appear here.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- IDP Forms -->
          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-contacts-book-2-line"></i> IDP Forms Summary</h3>
              <div class="card-head-icon" style="background:var(--green-soft);color:#064e3b;"><i class="ri-pie-chart-line"></i></div>
            </div>
            <div class="card-body">
              <?php if ($idpStats['table_available']): ?>
                <div class="chart-box" style="height:200px;"><canvas id="idpChart"></canvas></div>

                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.6rem;margin-top:1rem;">
                  <span class="badge badge-neutral" style="justify-content:center;">Draft: <span class="num">&nbsp;<?= $idpStats['draft'] ?></span></span>
                  <span class="badge badge-info" style="justify-content:center;">Submitted: <span class="num">&nbsp;<?= $idpStats['submitted'] ?></span></span>
                  <span class="badge badge-accepted" style="justify-content:center;">Approved: <span class="num">&nbsp;<?= $idpStats['approved'] ?></span></span>
                  <span class="badge badge-declined" style="justify-content:center;">Rejected: <span class="num">&nbsp;<?= $idpStats['rejected'] ?></span></span>
                </div>

                <div style="margin-top:1rem;">
                  <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.4rem;">
                    <span style="font-weight:500;color:var(--ink-2);">No IDP yet</span>
                    <span class="num" style="font-weight:700;color:var(--bad);"><?= $idpStats['no_idp'] ?></span>
                  </div>
                  <div class="progress"><span class="pf-bad" style="width:<?= pct($idpStats['no_idp'], $idpStats['total_eligible']) ?>%"></span></div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--line-soft);">
                  <span style="font-size:0.82rem;color:var(--muted);">Completion rate (approved)</span>
                  <span class="badge badge-green num"><?= $idpStats['completion_rate'] ?>%</span>
                </div>
              <?php else: ?>
                <div class="empty compact">
                  <i class="ri-tools-line"></i>
                  <h4>IDP analytics not available yet</h4>
                  <p>This dashboard expects an <code>idp_forms</code> table (id, user_id, status, created_at). Add it — or tell me your real IDP schema — and this card will populate automatically.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             CHARTS — Evaluation Progress + Faculty Distribution
             =========================================================== -->
        <section class="grid-2col">
          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-progress-4-line"></i> Evaluation Progress Overview</h3>
              <div class="card-head-icon"><i class="ri-pie-chart-2-line"></i></div>
            </div>
            <div class="card-body">
              <div class="chart-box"><canvas id="progressChart"></canvas></div>
            </div>
          </div>

          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-bar-chart-grouped-line"></i> Faculty Distribution</h3>
              <div class="card-head-icon"><i class="ri-bar-chart-2-line"></i></div>
            </div>
            <div class="card-body">
              <div class="chart-box"><canvas id="distributionChart"></canvas></div>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             PENDING + RECENT EVALUATIONS
             =========================================================== -->
        <section class="grid-main">

          <!-- Pending Evaluations -->
          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-time-line"></i> Pending Evaluations</h3>
              <span class="badge badge-green num"><?= count($unevaluated_faculty) ?> pending</span>
            </div>
            <div class="card-body">
              <?php if (!empty($unevaluated_faculty)): ?>
                <div class="evaluation-grid">
                  <?php foreach (array_slice($unevaluated_faculty, 0, 5) as $faculty): ?>
                    <div class="evaluation-row">
                      <div class="evaluation-info">
                        <div class="evaluation-name"><?= htmlspecialchars($faculty['name']) ?></div>
                        <div class="evaluation-meta">
                          <span class="evaluation-meta-item"><i class="fas fa-building" style="color:var(--accent);"></i> College of Engineering</span>
                          <span class="evaluation-meta-item">
                            <?php if ($faculty['teaching_status'] === 'Teaching'): ?>
                              <i class="fas fa-chalkboard-teacher" style="color:var(--ok);"></i> Teaching
                            <?php else: ?>
                              <i class="fas fa-user-tie" style="color:var(--grey);"></i> Non-Teaching
                            <?php endif; ?>
                          </span>
                        </div>
                      </div>
                      <div class="evaluation-actions">
                        <a href="coe_eval.php?user_id=<?= $faculty['id'] ?>" class="btn btn-green btn-sm">Evaluate Now</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php if (count($unevaluated_faculty) > 5): ?>
                  <div style="margin-top:1rem;text-align:center;">
                    <a href="coe_eval.php" class="btn btn-ghost btn-sm">
                      View all <?= count($unevaluated_faculty) ?> pending evaluations <i class="ri-arrow-right-line"></i>
                    </a>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div class="empty compact">
                  <i class="ri-checkbox-circle-line"></i>
                  <h4>All caught up</h4>
                  <p>All evaluations are completed for A.Y. <?= $current_year ?>-<?= $current_year + 1 ?>.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Recent Evaluations -->
          <div class="card hoverable">
            <div class="card-head">
              <h3><i class="ri-history-line"></i> Recent Evaluations</h3>
              <span class="badge badge-info num"><?= count($recent_evaluations) ?> recent</span>
            </div>
            <div class="card-body">
              <?php if (!empty($recent_evaluations)): ?>
                <div class="evaluation-grid">
                  <?php foreach ($recent_evaluations as $evaluation):
                    $status_config = [
                        'approved'  => ['class' => 'status-approved',  'icon' => 'fa-check-circle'],
                        'submitted' => ['class' => 'status-submitted', 'icon' => 'fa-paper-plane'],
                        'draft'     => ['class' => 'status-draft',     'icon' => 'fa-edit'],
                        'rejected'  => ['class' => 'status-rejected',  'icon' => 'fa-times-circle']
                    ];
                    $status = $status_config[$evaluation['evaluation_status']] ?? $status_config['draft'];
                  ?>
                    <div class="evaluation-row">
                      <div class="evaluation-info">
                        <div class="evaluation-name"><?= htmlspecialchars($evaluation['name']) ?></div>
                        <div class="evaluation-meta">
                          <span class="evaluation-meta-item"><i class="fas fa-building" style="color:var(--accent);"></i> College of Engineering</span>
                          <span class="evaluation-meta-item">
                            <?php if ($evaluation['teaching_status'] === 'Teaching'): ?>
                              <i class="fas fa-chalkboard-teacher" style="color:var(--ok);"></i> Teaching
                            <?php else: ?>
                              <i class="fas fa-user-tie" style="color:var(--grey);"></i> Non-Teaching
                            <?php endif; ?>
                          </span>
                          <span class="evaluation-meta-item"><i class="fas fa-calendar" style="color:var(--faint);"></i> <?= date('M d, Y', strtotime($evaluation['created_at'])) ?></span>
                        </div>
                      </div>
                      <div class="evaluation-actions">
                        <span class="status-badge <?= $status['class'] ?>"><i class="fas <?= $status['icon'] ?>"></i> <?= ucfirst($evaluation['evaluation_status']) ?></span>
                        <?php if ($evaluation['evaluation_status'] === 'draft'): ?>
                        <form method="POST" action="" class="m-0" style="display:inline;">
                          <input type="hidden" name="evaluation_id" value="<?= $evaluation['evaluation_id'] ?>">
                          <button type="submit" name="send_evaluation" class="btn btn-success btn-sm" onclick="return confirm('Submit this evaluation to HR for review?')">Submit</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="" class="m-0" style="display:inline;">
                          <input type="hidden" name="evaluation_id" value="<?= $evaluation['evaluation_id'] ?>">
                          <button type="submit" name="view_evaluation" class="btn btn-primary btn-sm">View</button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="empty compact">
                  <i class="ri-file-list-3-line"></i>
                  <h4>No Evaluations Yet</h4>
                  <p>Completed evaluations will appear here.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             TRAINING DEMAND — slim teaser card linking to the dedicated
             page (moved 2026-09-04, see CLAUDE.md). The full listing +
             Report to HR modal now live in COE_Training_Demand.php.
             =========================================================== -->
        <a href="COE_Training_Demand.php" style="display:block;margin-top:1.15rem;">
          <section class="card hoverable">
            <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
              <div style="display:flex;align-items:center;gap:0.9rem;min-width:0;">
                <div class="stat-ico" style="background:var(--warn-soft);color:var(--warn-ink);"><i class="ri-stack-line"></i></div>
                <div style="min-width:0;">
                  <h3 style="font-size:0.95rem;font-weight:700;color:var(--ink);">Training Demand Forwarded to You</h3>
                  <p style="font-size:0.82rem;color:var(--muted);margin-top:2px;">
                    <?php if (!empty($forwardedDemandRows)): ?>
                      <?= count($forwardedDemandRows) ?> training<?= count($forwardedDemandRows) === 1 ? '' : 's' ?> awaiting your response
                    <?php else: ?>
                      Nothing forwarded yet
                    <?php endif; ?>
                  </p>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:0.6rem;flex-shrink:0;">
                <?php if (!empty($forwardedDemandRows)): ?>
                  <span class="badge badge-info num"><?= count($forwardedDemandRows) ?></span>
                <?php endif; ?>
                <i class="ri-arrow-right-line" style="color:var(--muted);font-size:1.2rem;"></i>
              </div>
            </div>
          </section>
        </a>

      </div>
    </div>
  </div>
</div>

<!-- =============================================================
     TOAST STACK — flash messages render here
     ============================================================= -->
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<!-- =============================================================
     EVALUATION VIEW MODAL (server-rendered, shown via PHP flag)
     ============================================================= -->
<?php if ($show_evaluation_modal && $evaluation_details): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop">
    <div class="bg-white rounded-2xl shadow-2xl modal-container w-full max-w-6xl">
        <div class="p-6 rounded-t-2xl text-white" style="background:linear-gradient(135deg, var(--accent) 0%, var(--accent-700) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold">FACULTY PERFORMANCE EVALUATION FORM</h2>
                    <div class="flex items-center space-x-4 mt-2">
                        <span class="status-badge status-<?= $evaluation_details['status'] ?>"><?= ucfirst($evaluation_details['status']) ?></span>
                        <span class="text-sm text-gray-100">Created: <?= date('M d, Y h:i A', strtotime($evaluation_details['created_at'])) ?></span>
                        <?php if ($evaluation_details['updated_at'] != $evaluation_details['created_at']): ?>
                        <span class="text-sm text-gray-100">Updated: <?= date('M d, Y h:i A', strtotime($evaluation_details['updated_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="printForm()" class="btn btn-success btn-sm"><i class="ri-printer-line"></i>Print</button>
                    <button onclick="closeModal()" class="btn btn-ghost btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.3);"><i class="ri-close-line"></i>Close</button>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name of Faculty:</label>
                    <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);"><?= htmlspecialchars($evaluation_details['employee_name']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department:</label>
                    <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);"><?= htmlspecialchars($evaluation_details['employee_department']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Academic Year:</label>
                    <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);"><?= $current_year ?>-<?= $current_year + 1 ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Evaluation Period:</label>
                    <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);"><?= date('M d, Y', strtotime($evaluation_details['created_at'])) ?></div>
                </div>
            </div>

            <div class="mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Performance Ratings</h3>
                <div class="overflow-x-auto table-wrap">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr style="background:var(--accent);">
                                <th class="text-left py-3 px-4 font-medium text-white w-2/3">EVALUATION CRITERIA</th>
                                <th class="text-center py-3 px-2 font-medium text-white w-8">1</th>
                                <th class="text-center py-3 px-2 font-medium text-white w-8">2</th>
                                <th class="text-center py-3 px-2 font-medium text-white w-8">3</th>
                                <th class="text-center py-3 px-2 font-medium text-white w-8">4</th>
                                <th class="text-center py-3 px-2 font-medium text-white w-8">5</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $criteria = [
                                "1. Teaching Effectiveness and Classroom Management",
                                "2. Subject Matter Knowledge and Expertise",
                                "3. Student Engagement and Motivation",
                                "4. Assessment and Evaluation Methods",
                                "5. Professional Development and Growth",
                                "6. Collegiality and Collaboration",
                                "7. Adherence to Institutional Policies",
                                "8. Contribution to Department Goals"
                            ];

                            for ($i = 1; $i <= 8; $i++):
                                $rating = $evaluation_ratings[$i] ?? null;
                            ?>
                            <tr>
                                <td class="py-3 px-4 text-gray-700 text-sm"><?= $criteria[$i-1] ?></td>
                                <?php for ($j = 1; $j <= 5; $j++): ?>
                                <td class="text-center py-2 px-1">
                                    <?php if ($rating && $rating['rating'] == $j): ?>
                                    <div class="w-8 h-8 flex items-center justify-center mx-auto text-white rounded" style="background:var(--green);">
                                        <i class="ri-check-line text-sm"></i>
                                    </div>
                                    <?php else: ?>
                                    <div class="w-8 h-8 flex items-center justify-center mx-auto text-gray-400"><?= $j ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Overall Comments:</label>
                <div class="w-full p-3 rounded-lg border min-h-[80px]" style="background:var(--surface-2);border-color:var(--line);"><?= nl2br(htmlspecialchars($evaluation_details['comments'])) ?></div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Recommendations for Improvement:</label>
                <div class="w-full p-3 rounded-lg border min-h-[100px]" style="background:var(--surface-2);border-color:var(--line);"><?= nl2br(htmlspecialchars($evaluation_details['future_training_needs'])) ?></div>
            </div>

            <div class="border-t pt-6" style="border-color:var(--line);">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Evaluated by:</label>
                        <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);"><?= htmlspecialchars($evaluation_details['rated_by']) ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Signature:</label>
                        <div class="signature-preview">
                            <?php if (!empty($evaluation_details['signature_date'])): ?>
                                <img src="<?= htmlspecialchars($evaluation_details['signature_date']) ?>" alt="Signature" class="signature-image">
                            <?php else: ?>
                                <span class="text-gray-400">No signature</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date:</label>
                        <div class="w-full p-3 rounded-lg border" style="background:var(--surface-2);border-color:var(--line);">
                            <?= $evaluation_details['created_at'] ? date('M d, Y', strtotime($evaluation_details['created_at'])) : 'Not set' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($workflow_history)): ?>
            <div class="mt-8 border-t pt-6" style="border-color:var(--line);">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Evaluation History</h3>
                <div class="workflow-timeline">
                    <?php foreach ($workflow_history as $history): ?>
                    <div class="workflow-step">
                        <div class="card" style="padding:0.9rem 1rem;">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-medium text-gray-800">
                                        Status changed from
                                        <span class="text-gray-600"><?= $history['from_status'] ? ucfirst($history['from_status']) : 'None' ?></span>
                                        to
                                        <span style="color:var(--ok);"><?= ucfirst($history['to_status']) ?></span>
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">By: <?= htmlspecialchars($history['changed_by_name']) ?></p>
                                    <?php if (!empty($history['comments'])): ?>
                                    <p class="text-sm text-gray-600 mt-2"><strong>Comment:</strong> <?= htmlspecialchars($history['comments']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs text-gray-400"><?= date('M d, Y h:i A', strtotime($history['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-8 flex justify-end space-x-4 pt-6 border-t" style="border-color:var(--line);">
                <?php if ($evaluation_details['status'] === 'draft'): ?>
                <form method="POST" action="" class="inline">
                    <input type="hidden" name="evaluation_id" value="<?= $evaluation_details['id'] ?>">
                    <button type="submit" name="send_evaluation" class="btn btn-success" onclick="return confirm('Submit this evaluation to HR?')"><i class="ri-send-plane-line"></i>Submit to HR</button>
                </form>
                <?php endif; ?>
                <a href="coe_eval.php?evaluation_id=<?= $evaluation_details['id'] ?>&user_id=<?= $evaluation_details['user_id'] ?>" class="btn btn-primary"><i class="ri-edit-line"></i>Edit Evaluation</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- =============================================================
     VENDOR — Chart.js
     ============================================================= -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
/* ============================================================
   TOASTS
   ============================================================ */
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

/* ============================================================
   SIDEBAR
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

/* ============================================================
   DROPDOWNS
   ============================================================ */
function toggleDropdown(id) {
  const target = document.getElementById(id);
  document.querySelectorAll('.dropdown.open').forEach(d => { if (d !== target) d.classList.remove('open'); });
  target.classList.toggle('open');
}
function closeAllDropdowns() { document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open')); }

/* ============================================================
   EVALUATION MODAL (server-rendered)
   ============================================================ */
function closeModal() {
  document.body.classList.remove('overflow-hidden');
  window.location.href = 'COE.php';
}
function printForm() { window.print(); }

/* ============================================================
   NOTIFICATIONS
   ============================================================ */
function updateNotifications() {
  // 2026-09-06 fix - this was fetching 'get_notifications.php' (no ../),
  // which 404s from inside COE_admin/ since the endpoint lives at the
  // project root; the .catch() swallowed it silently, so the dropdown
  // list just never refreshed. Same fix applied to markAllAsRead() below
  // - see CLAUDE.md.
  fetch('../get_notifications.php')
    .then(response => response.json())
    .then(data => {
      const list = document.getElementById('notifDropdownList');
      const items = data.notifications || [];
      if (!list) return;
      if (items.length > 0) {
        list.innerHTML = '';
        items.forEach(notification => {
          let ico = 'ri-information-line';
          if (notification.type === 'evaluation') ico = 'ri-file-list-3-line';
          else if (notification.type === 'pending') ico = 'ri-time-line';
          else if (notification.type === 'training_demand') ico = 'ri-stack-line';
          const isUnread = notification.is_read !== undefined && Number(notification.is_read) === 0;

          const item = document.createElement('div');
          item.className = 'dropdown-item';
          if (isUnread) item.style.background = '#d1fae5';
          item.innerHTML = `
            <div class="stat-ico ico-green" style="width:38px;height:38px;font-size:1.05rem;${isUnread ? '' : 'opacity:0.6;'}"><i class="${ico}"></i></div>
            <div>
              <p style="font-size:0.85rem;font-weight:${isUnread ? '700' : '500'};color:var(--ink);">${notification.message}</p>
              <p style="font-size:0.74rem;color:var(--muted);margin-top:2px;">${new Date(notification.timestamp).toLocaleString()}</p>
            </div>`;
          list.appendChild(item);
        });
      } else {
        list.innerHTML = `<div class="empty compact"><i class="ri-check-double-line"></i><h4>You're all caught up</h4><p>No new notifications.</p></div>`;
      }

      const unread = data.unreadCount || 0;
      const bellBtn = document.querySelector('#bellDropdown > .icon-btn');
      let dot = document.getElementById('bellDot');
      if (unread > 0 && !dot && bellBtn) {
        dot = document.createElement('span');
        dot.id = 'bellDot';
        dot.className = 'bell-dot';
        bellBtn.appendChild(dot);
      } else if (unread === 0 && dot) {
        dot.remove();
      }
      const badge = document.getElementById('notifCountBadge');
      if (badge) {
        if (unread > 0) { badge.textContent = unread + ' new'; }
        else { badge.remove(); }
      }
    })
    .catch(error => console.error('Error fetching notifications:', error));
}
setInterval(updateNotifications, 30000);

function markAllAsRead() {
  fetch('../mark_notifications_read.php', { method: 'POST', headers: { 'Content-Type': 'application/json' } })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const dot = document.getElementById('bellDot');
        if (dot) dot.remove();
        const badge = document.getElementById('notifCountBadge');
        if (badge) badge.remove();
        closeAllDropdowns();
      }
    })
    .catch(error => console.error('Error marking notifications as read:', error));
}

/* ============================================================
   CHARTS
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  syncCollapseButton();

  document.addEventListener('click', (e) => { if (!e.target.closest('.dropdown')) closeAllDropdowns(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeAllDropdowns(); closeMobileRail(); } });
  window.addEventListener('resize', syncCollapseButton);

  // Evaluation progress (pie)
  const progressEl = document.getElementById('progressChart');
  if (progressEl && typeof Chart !== 'undefined') {
    new Chart(progressEl.getContext('2d'), {
      type: 'pie',
      data: {
        labels: ['Completed', 'Pending', 'In Progress'],
        datasets: [{
          data: [<?= (int)$stats['evaluated_this_year'] ?>, <?= (int)$stats['pending_evaluations'] ?>, <?= (int)($evaluation_stats['draft'] ?? 0) ?>],
          backgroundColor: ['#059669', '#065f46', '#6b7280'],
          borderWidth: 3, borderColor: '#ffffff', borderRadius: 8
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { family: 'Inter', size: 11, weight: '600' } } },
          tooltip: {
            backgroundColor: '#0f172a', padding: 10, cornerRadius: 8,
            callbacks: {
              label(c) {
                const total = c.dataset.data.reduce((a, b) => a + b, 0);
                const p = total > 0 ? Math.round((c.raw / total) * 100) : 0;
                return ` ${c.label}: ${c.raw} (${p}%)`;
              }
            }
          }
        }
      }
    });
  }

  // Faculty distribution (bar)
  const distEl = document.getElementById('distributionChart');
  if (distEl && typeof Chart !== 'undefined') {
    new Chart(distEl.getContext('2d'), {
      type: 'bar',
      data: {
        labels: ['Teaching', 'Non-Teaching'],
        datasets: [{
          label: 'Faculty Count',
          data: [<?= (int)$stats['teaching'] ?>, <?= (int)$stats['non_teaching'] ?>],
          backgroundColor: ['rgba(5,150,105,0.85)', 'rgba(107,114,128,0.85)'],
          borderColor: ['#059669', '#6b7280'],
          borderWidth: 2, borderRadius: 10
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: '#eef1f5' }, ticks: { font: { family: 'JetBrains Mono', size: 11 } } },
          x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 12, weight: '600' } } }
        }
      }
    });
  }

  // Assessment Form status (pie)
  const assessEl = document.getElementById('assessmentChart');
  if (assessEl && typeof Chart !== 'undefined') {
    new Chart(assessEl.getContext('2d'), {
      type: 'pie',
      data: {
        labels: ['On Time', 'Late', 'No Submission'],
        datasets: [{
          data: [<?= (int)$assessmentStats['on_time'] ?>, <?= (int)$assessmentStats['late'] ?>, <?= (int)$assessmentStats['no_submission'] ?>],
          backgroundColor: ['#059669', '#d97706', '#e11d48'],
          borderWidth: 3, borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { family: 'Inter', size: 11 } } },
          tooltip: {
            backgroundColor: '#0f172a', padding: 10, cornerRadius: 8,
            callbacks: {
              label(c) {
                const total = c.dataset.data.reduce((a, b) => a + b, 0);
                const p = total > 0 ? Math.round((c.raw / total) * 100) : 0;
                return ` ${c.label}: ${c.raw} (${p}%)`;
              }
            }
          }
        }
      }
    });
  }

  // IDP status (horizontal bar — deliberately a different chart type than
  // the Assessment Form pie above, and includes "No IDP yet" for context)
  const idpEl = document.getElementById('idpChart');
  if (idpEl && typeof Chart !== 'undefined') {
    new Chart(idpEl.getContext('2d'), {
      type: 'bar',
      data: {
        labels: ['No IDP Yet', 'Draft', 'Submitted', 'Approved', 'Rejected'],
        datasets: [{
          label: 'Faculty',
          data: [
            <?= (int)$idpStats['no_idp'] ?>,
            <?= (int)$idpStats['draft'] ?>,
            <?= (int)$idpStats['submitted'] ?>,
            <?= (int)$idpStats['approved'] ?>,
            <?= (int)$idpStats['rejected'] ?>
          ],
          backgroundColor: ['#cbd5e1', '#94a3b8', '#0284c7', '#059669', '#e11d48'],
          borderRadius: 8,
          barThickness: 16
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, grid: { color: '#eef1f5' }, ticks: { stepSize: 1, font: { family: 'JetBrains Mono', size: 11 }, callback: v => Math.floor(v) } },
          y: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11, weight: '600' } } }
        }
      }
    });
  }

  /* ---- FLASH BOOTSTRAP (PHP session messages → toasts) ---- */
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