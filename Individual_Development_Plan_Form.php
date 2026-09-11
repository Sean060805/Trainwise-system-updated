<?php
/* ===================================================================
   IDP SUBMISSIONS — Admin view (with Department Admin Approval)
   -------------------------------------------------------------------
   Flow: User submits IDP → AdminByDepartment reviews/approves → 
   Main Admin sees approved IDPs for final signatures
   =================================================================== */

session_start();
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function jsonResponse($data)
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

function ensureDirectory($dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function getUserIDPForms($user_id)
{
    global $con;

    // 2026-09-10 fix - was 'approved_by_dept', a status this codebase's
    // idp_forms enum/dean files never actually produce. The real value a
    // dean writes once they've reviewed and forwarded a form to HR is
    // 'submitted_to_hr' (see e.g. CCS_admin/CCS_Individual_Development_Plan_Form.php),
    // matching the same status vocabulary already used across all 13
    // college dean files.
    $stmt = $con->prepare("
        SELECT id, form_data, submitted_at
        FROM idp_forms
        WHERE user_id = ? AND status = 'submitted_to_hr'
        ORDER BY submitted_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $forms = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $forms;
}

function loadImageResource($tmpPath, $mime)
{
    switch ($mime) {
        case 'image/png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : false;
        case 'image/jpeg':
        case 'image/jpg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpPath) : false;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false;
        default:
            return false;
    }
}

/*
|--------------------------------------------------------------------------
| Process signature upload
| - If GD is available: convert to transparent PNG with black ink only
| - If GD is not available: save original upload as fallback
|--------------------------------------------------------------------------
*/
function processSignatureUpload($file, $role, $formId)
{
    if (!isset($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [
            'status' => 'skipped',
            'path'   => '',
            'message'=> ''
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'status' => 'error',
            'path'   => '',
            'message'=> 'Failed to upload ' . ucfirst($role) . ' signature.'
        ];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return [
            'status' => 'error',
            'path'   => '',
            'message'=> ucfirst($role) . ' signature must be 5MB or smaller.'
        ];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/webp'
    ];

    if (!in_array($mime, $allowed, true)) {
        return [
            'status' => 'error',
            'path'   => '',
            'message'=> ucfirst($role) . ' signature must be PNG, JPG, JPEG, or WEBP.'
        ];
    }

    $uploadDir = 'uploads/signatures/admin/';
    ensureDirectory($uploadDir);

    $pngPath = $uploadDir . $role . '_signature_form_' . $formId . '.png';

    $src = loadImageResource($file['tmp_name'], $mime);

    // If GD image processing is available, clean signature to black-on-transparent
    if ($src && function_exists('imagecreatetruecolor') && function_exists('imagesavealpha')) {
        $width  = imagesx($src);
        $height = imagesy($src);

        $dest = imagecreatetruecolor($width, $height);
        imagesavealpha($dest, true);

        $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
        imagefill($dest, 0, 0, $transparent);

        $alphaCache = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($src, $x, $y);

                $a = ($rgba & 0x7F000000) >> 24;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $gray = (int) round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
                $effectiveGray = min(255, $gray + ($a * 2));

                // Make near-white pixels transparent
                if ($effectiveGray >= 240) {
                    continue;
                }

                // Darker pixels become more opaque black
                $opacity = 127 - (int) round(((255 - $effectiveGray) / 255) * 127);
                $opacity = max(0, min(127, $opacity));

                if (!isset($alphaCache[$opacity])) {
                    $alphaCache[$opacity] = imagecolorallocatealpha($dest, 0, 0, 0, $opacity);
                }

                imagesetpixel($dest, $x, $y, $alphaCache[$opacity]);
            }
        }

        imagepng($dest, $pngPath);
        imagedestroy($src);
        imagedestroy($dest);

        return [
            'status' => 'saved',
            'path'   => $pngPath,
            'message'=> ''
        ];
    }

    // Fallback if GD is not available
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'png';
    }

    $fallbackPath = $uploadDir . $role . '_signature_form_' . $formId . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], $fallbackPath)) {
        return [
            'status' => 'error',
            'path'   => '',
            'message'=> 'Could not save ' . ucfirst($role) . ' signature.'
        ];
    }

    return [
        'status' => 'saved',
        'path'   => $fallbackPath,
        'message'=> ''
    ];
}

/*
|--------------------------------------------------------------------------
| AJAX: Get forms for modal (only submitted_to_hr status)
|--------------------------------------------------------------------------
*/
if (isset($_GET['action']) && $_GET['action'] === 'get_user_forms') {
    $requestedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    if ($requestedUserId <= 0) {
        jsonResponse([]);
    }

    $forms = getUserIDPForms($requestedUserId);
    jsonResponse($forms);
}

/*
|--------------------------------------------------------------------------
| AJAX: Save admin signatures and mark as completed
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_admin_signatures') {
    $formId = isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0;

    if ($formId <= 0) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid form ID.'
        ]);
    }

    $stmt = $con->prepare("SELECT id, form_data, status FROM idp_forms WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $formId);
    $stmt->execute();
    $result = $stmt->get_result();
    $formRow = $result->fetch_assoc();
    $stmt->close();

    if (!$formRow) {
        jsonResponse([
            'success' => false,
            'message' => 'IDP form not found.'
        ]);
    }

    // Only allow forms the dean has already reviewed and forwarded to HR.
    // 2026-09-10 fix - see the same fix/comment in getUserIDPForms() above.
    if ($formRow['status'] !== 'submitted_to_hr') {
        jsonResponse([
            'success' => false,
            'message' => 'This form must be approved by department admin first.'
        ]);
    }

    $formData = json_decode($formRow['form_data'], true);
    if (!is_array($formData)) {
        $formData = [];
    }

    if (!isset($formData['certification']) || !is_array($formData['certification'])) {
        $formData['certification'] = [];
    }

    $certification = array_merge([
        'employee_name'        => '',
        'employee_date'        => '',
        'employee_signature'   => '',
        'supervisor_name'      => '',
        'supervisor_date'      => '',
        'supervisor_signature' => '',
        'director_name'        => '',
        'director_date'        => '',
        'director_signature'   => ''
    ], $formData['certification']);

    $supervisorUpload = processSignatureUpload($_FILES['supervisor_signature_file'] ?? null, 'supervisor', $formId);
    if ($supervisorUpload['status'] === 'error') {
        jsonResponse([
            'success' => false,
            'message' => $supervisorUpload['message']
        ]);
    }

    $directorUpload = processSignatureUpload($_FILES['director_signature_file'] ?? null, 'director', $formId);
    if ($directorUpload['status'] === 'error') {
        jsonResponse([
            'success' => false,
            'message' => $directorUpload['message']
        ]);
    }

    $updatedAny = false;

    if ($supervisorUpload['status'] === 'saved') {
        $certification['supervisor_signature'] = $supervisorUpload['path'];
        $updatedAny = true;
    }

    if ($directorUpload['status'] === 'saved') {
        $certification['director_signature'] = $directorUpload['path'];
        $updatedAny = true;
    }

    if (!$updatedAny) {
        jsonResponse([
            'success' => false,
            'message' => 'Please upload at least one signature image.'
        ]);
    }

    $formData['certification'] = $certification;
    $jsonData = json_encode($formData, JSON_UNESCAPED_UNICODE);

    $con->begin_transaction();

    try {
        // Update main form JSON and change status to 'completed'
        $stmt = $con->prepare("UPDATE idp_forms SET form_data = ?, status = 'completed', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $jsonData, $formId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update IDP form.");
        }
        $stmt->close();

        // Sync certification table as well
        $stmt = $con->prepare("
            REPLACE INTO idp_certification
            (
                form_id,
                employee_name,
                employee_date,
                employee_signature,
                supervisor_name,
                supervisor_date,
                supervisor_signature,
                director_name,
                director_date,
                director_signature
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "isssssssss",
            $formId,
            $certification['employee_name'],
            $certification['employee_date'],
            $certification['employee_signature'],
            $certification['supervisor_name'],
            $certification['supervisor_date'],
            $certification['supervisor_signature'],
            $certification['director_name'],
            $certification['director_date'],
            $certification['director_signature']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to update certification table.");
        }
        $stmt->close();

        $con->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Supervisor and Director signatures saved successfully. IDP is now completed.',
            'certification' => $certification
        ]);
    } catch (Exception $e) {
        $con->rollback();
        jsonResponse([
            'success' => false,
            'message' => 'Error saving signatures: ' . $e->getMessage()
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Main employee list - Only show forms approved by department admin
|--------------------------------------------------------------------------
*/
$query = "
    SELECT 
        u.id as user_id,
        u.name, 
        ip.position, 
        u.department,
        COUNT(f.id) as total_idps,
        MAX(f.submitted_at) as last_submission
    FROM idp_forms f
    JOIN users u ON f.user_id = u.id
    JOIN idp_personal_info ip ON f.id = ip.form_id
    WHERE f.status = 'submitted_to_hr'
    GROUP BY u.id, u.name, ip.position, u.department
    ORDER BY u.name ASC
";

$result = $con->query($query);
$employees = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

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

$currentPageUrl = $_SERVER['PHP_SELF'];

// Derived stats (computed from $employees; no extra queries).
$total_submissions = 0;
foreach ($employees as $employee) { $total_submissions += (int) $employee['total_idps']; }
$dept_count = [];
foreach ($employees as $employee) {
    if (!empty($employee['department']) && !in_array($employee['department'], $dept_count, true)) {
        $dept_count[] = $employee['department'];
    }
}
$departments_represented = count($dept_count);

// Get pending department approvals count. 2026-09-10 fix - was
// 'pending_dept_approval', not a real value in this codebase; the actual
// status an employee's form sits in while awaiting dean review is
// 'submitted' (see the 13 college dean files' own "pending" queries).
$pendingQuery = "SELECT COUNT(*) as pending FROM idp_forms WHERE status = 'submitted'";
$pendingResult = $con->query($pendingQuery);
$pendingCount = 0;
if ($pendingResult && $pendingResult->num_rows > 0) {
    $row = $pendingResult->fetch_assoc();
    $pendingCount = (int) $row['pending'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>IDP Submissions · LSPU TNA</title>

  <!-- FONTS — Plus Jakarta Sans (display) + Inter (body) + JetBrains Mono (data) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <!-- Icon sets (kept from original) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- TAILWIND (CDN) — palette extended to the enterprise tokens -->
  <link rel="stylesheet" href="assets/css/tw-43.css">
<style>
  /* ==========================================================
     DESIGN SYSTEM — shared with admin_page.php / Assessment Form.php
     ========================================================== */

  /* 1. TOKENS */
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

  /* 4. SIDEBAR */
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

  /* 5. TOPBAR */
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

  /* 6. PAGE HEADER */
  .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
  .page-head h2 { font-size:1.55rem; font-weight:800; color:var(--ink); }
  .page-head p { color:var(--muted); font-size:0.9rem; margin-top:0.25rem; }

  /* 7. CARDS + STATS */
  .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease), border-color 0.22s var(--ease); }
  .card-body { padding:1.3rem; }
  .stat { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
  .stat:hover { transform:translateY(-3px); box-shadow:var(--shadow-pop); }
  .stat::after { content:''; position:absolute; inset:0 0 auto 0; height:3px; background:var(--stat-accent, var(--accent)); }
  .stat.a-indigo  { --stat-accent:linear-gradient(90deg, #6366f1, #4f46e5); }
  .stat.a-violet  { --stat-accent:linear-gradient(90deg, #a78bfa, #7c3aed); }
  .stat.a-emerald { --stat-accent:linear-gradient(90deg, #34d399, #059669); }
  .stat.a-warning { --stat-accent:linear-gradient(90deg, #fbbf24, #d97706); }
  .stat-body { padding:1.25rem 1.3rem; display:flex; align-items:center; justify-content:space-between; }
  .stat-label { font-size:0.8rem; font-weight:500; color:var(--muted); }
  .stat-value { font-size:2rem; font-weight:700; margin-top:0.35rem; line-height:1; }
  .stat-sub { font-size:0.72rem; color:var(--faint); margin-top:0.4rem; }
  .stat-ico { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
  .ico-indigo  { background:var(--accent-soft); color:var(--accent); }
  .ico-violet  { background:#f5f3ff; color:#7c3aed; }
  .ico-emerald { background:var(--ok-soft); color:var(--ok); }
  .ico-warning { background:var(--warn-soft); color:var(--warn); }

  /* 8. BUTTONS (incl. legacy .view-btn / .print-btn used by injected HTML) */
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600; padding:0.65rem 1.1rem; border-radius:var(--radius-sm); border:1px solid transparent; cursor:pointer; white-space:nowrap; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease); }
  .btn:active { transform:translateY(1px); }
  .btn-primary { background:var(--accent); color:#fff; box-shadow:0 8px 18px -10px rgba(79,70,229,0.7); }
  .btn-primary:hover { background:var(--accent-700); }
  .btn-ghost { background:var(--surface); color:var(--ink-2); border-color:var(--line); }
  .btn-ghost:hover { background:var(--surface-2); border-color:#cbd5e1; }
  .view-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--accent); color:#fff; padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.85rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(79,70,229,0.7); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
  .view-btn:hover { background:var(--accent-700); }
  .view-btn:active { transform:translateY(1px); }
  .view-btn:disabled { opacity:0.6; cursor:not-allowed; }
  .print-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--ok); color:#fff; padding:0.6rem 1.1rem; border-radius:var(--radius-sm); font-size:0.85rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
  .print-btn:hover { background:#047857; }
  .print-btn:active { transform:translateY(1px); }

  /* 9. BADGES */
  .department-badge { display:inline-flex; align-items:center; gap:0.3rem; background:var(--accent-soft); color:var(--accent-ink); padding:0.32rem 0.7rem; border-radius:999px; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; }
  .submission-count { background:var(--accent-soft); color:var(--accent-ink); padding:0.25rem 0.5rem; border-radius:8px; font-size:0.72rem; font-weight:700; margin-left:0.5rem; }
  .status-approved { display:inline-flex; align-items:center; background:var(--ok-soft); color:var(--ok-ink); padding:0.32rem 0.75rem; border-radius:999px; font-size:0.72rem; font-weight:600; }
  .status-pending { display:inline-flex; align-items:center; background:var(--warn-soft); color:var(--warn-ink); padding:0.32rem 0.75rem; border-radius:999px; font-size:0.72rem; font-weight:600; }

  /* 10. MAIN TABLE */
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
  .emp-avatar { width:38px; height:38px; border-radius:10px; background:var(--accent-soft); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }

  /* 11. SEARCH + FILTER BAR */
  .search { position:relative; flex:1 1 240px; min-width:200px; }
  .search input { width:100%; height:42px; padding:0 2.6rem 0 2.6rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.875rem; color:var(--ink); transition:border-color 0.18s var(--ease), box-shadow 0.18s var(--ease); }
  .search input::placeholder { color:var(--faint); }
  .search input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(79,70,229,0.12); }
  .search > i { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--faint); font-size:1.05rem; pointer-events:none; }
  .search-go { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; border-radius:8px; background:var(--accent); color:#fff; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background 0.18s var(--ease); }
  .search-go:hover { background:var(--accent-700); }
  .filterbar { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; }
  .filter { position:relative; }
  .filter-btn { display:inline-flex; align-items:center; gap:0.55rem; height:42px; padding:0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; font-size:0.85rem; color:var(--ink-2); font-weight:500; transition:border-color 0.18s var(--ease), background 0.18s var(--ease); white-space:nowrap; }
  .filter-btn:hover { border-color:#cbd5e1; background:var(--surface-2); }
  .filter-btn .flt-label { color:var(--faint); font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
  .filter-btn .flt-value { color:var(--ink); font-weight:600; max-width:200px; overflow:hidden; text-overflow:ellipsis; }
  .filter-btn i.caret { color:var(--muted); transition:transform 0.18s var(--ease); }
  .filter.open .filter-btn { border-color:var(--accent); }
  .filter.open .filter-btn i.caret { transform:rotate(180deg); }
  .filter-menu { position:absolute; left:0; top:calc(100% + 0.4rem); min-width:260px; max-height:320px; overflow-y:auto; background:var(--surface); border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow-pop); padding:0.4rem; z-index:50; opacity:0; transform:translateY(-6px); pointer-events:none; transition:opacity 0.16s var(--ease), transform 0.16s var(--ease); }
  .filter.open .filter-menu { opacity:1; transform:translateY(0); pointer-events:auto; }
  .filter-menu a { display:flex; align-items:center; padding:0.6rem 0.75rem; border-radius:9px; font-size:0.85rem; color:var(--ink-2); transition:background 0.14s var(--ease); }
  .filter-menu a:hover { background:var(--surface-2); }
  .status-select { height:42px; padding:0 2.4rem 0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.85rem; color:var(--ink-2); font-weight:500; cursor:pointer; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='16' height='16'%3E%3Cpath d='M12 15l-4.243-4.243 1.415-1.414L12 12.172l2.828-2.829 1.415 1.414z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 0.7rem center; }
  .status-select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(79,70,229,0.12); }

  /* 12. EMPTY STATE */
  .empty { text-align:center; padding:3rem 1rem; color:var(--faint); }
  .empty i { font-size:3.2rem; color:#cbd5e1; }
  .empty h4 { font-size:1.05rem; color:var(--muted); margin-top:0.8rem; font-weight:600; }
  .empty p { font-size:0.85rem; color:var(--faint); margin-top:0.3rem; }

  /* ==========================================================
     MODAL — original .modal-overlay/.active classes kept so the
     page's JS (which toggles .active) is untouched; restyled here.
     ========================================================== */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); backdrop-filter:blur(6px); z-index:1000; align-items:center; justify-content:center; padding:1rem; }
  .modal-overlay.active { display:flex; animation:fadeIn 0.18s var(--ease); }
  @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
  .modal-content { background:var(--surface); border-radius:20px; width:100%; max-width:1000px; max-height:90vh; overflow:hidden; box-shadow:0 30px 60px -20px rgba(15,23,42,0.45); animation:popIn 0.26s var(--ease); display:flex; flex-direction:column; }
  @keyframes popIn { from { opacity:0; transform:translateY(14px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
  .modal-header { padding:1.15rem 1.5rem; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; background:var(--surface); flex-shrink:0; }
  .modal-header h3 { font-size:1.2rem; font-weight:700; color:var(--ink); }
  .modal-body { padding:1.4rem 1.5rem; overflow-y:auto; flex:1 1 auto; }
  .modal-footer { padding:1rem 1.5rem; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:0.7rem; background:var(--surface-2); flex-shrink:0; }
  .modal-x { width:38px; height:38px; border-radius:10px; border:1px solid var(--line); background:var(--surface); color:var(--muted); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); }
  .modal-x:hover { background:var(--bad-soft); color:var(--bad); border-color:#fecdd3; }

  /* ==========================================================
     INJECTED MODAL CONTENT — accordion, form sections, tables,
     signatures. Classes preserved (JS builds this markup).
     ========================================================== */
  .idp-list { display:flex; flex-direction:column; gap:1rem; }
  .form-card { border:1px solid var(--line); border-radius:14px; overflow:hidden; background:var(--surface); }
  .accordion-toggle { background:none; border:none; width:100%; text-align:left; padding:1.25rem; cursor:pointer; display:flex; justify-content:space-between; align-items:center; transition:background-color 0.18s var(--ease); }
  .accordion-toggle:hover { background:var(--surface-2); }
  .accordion-toggle h3 { color:var(--ink); }
  .accordion-arrow { color:var(--muted); font-size:1.3rem; }
  .accordion-content { max-height:0; overflow:hidden; transition:max-height 0.3s ease-out; }
  .accordion-content.expanded { max-height:6000px; }
  .form-section { margin-bottom:1.5rem; padding:1.25rem; background:var(--surface-2); border-radius:12px; border:1px solid var(--line); }
  .form-section-title { font-size:1.02rem; font-weight:700; color:var(--ink); margin-bottom:1rem; padding-bottom:0.5rem; border-bottom:1px solid var(--line); }
  .grid-form { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem; }
  .form-field { margin-bottom:0.75rem; }
  .form-field label { display:block; font-size:0.78rem; font-weight:600; color:var(--muted); margin-bottom:0.3rem; }
  .form-field .value { padding:0.55rem 0.7rem; background:var(--surface); border-radius:9px; border:1px solid var(--line); min-height:2.5rem; display:flex; align-items:center; word-break:break-word; color:var(--ink-2); font-size:0.875rem; }
  .table-responsive { overflow-x:auto; margin:0.5rem 0; border-radius:10px; border:1px solid var(--line); }
  .modal-table { width:100%; border-collapse:collapse; background:var(--surface); }
  .modal-table th { background:var(--surface-2); padding:0.7rem 0.85rem; text-align:left; font-weight:700; font-size:0.7rem; letter-spacing:0.04em; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--line); }
  .modal-table td { padding:0.7rem 0.85rem; border-bottom:1px solid var(--line-soft); color:var(--ink-2); font-size:0.85rem; vertical-align:top; }
  .modal-table tr:nth-child(even) td { background:var(--surface-2); }
  .modal-table tr:hover td { background:var(--accent-soft); }
  .modal-table tr:last-child td { border-bottom:none; }
  .checkbox-custom { width:18px; height:18px; accent-color:var(--accent); }
  .signature-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:1.25rem; margin-top:1rem; }
  .signature-box { padding:1rem; background:var(--surface); border-radius:12px; border:1px solid var(--line); text-align:center; }
  .signature-box > div:first-child { font-size:0.78rem; color:var(--muted); font-weight:600; }
  .signature-name { font-weight:700; color:var(--ink); margin-top:0.5rem; padding-top:0.5rem; border-top:1px dashed var(--line); word-break:break-word; }
  .signature-date { font-size:0.8rem; color:var(--muted); margin-top:0.25rem; }
  .signature-preview { max-width:100%; min-height:90px; border:1px dashed #cbd5e1; border-radius:10px; padding:0.75rem; background:var(--surface-2); display:flex; align-items:center; justify-content:center; margin-top:0.75rem; }
  .signature-preview img { max-width:100%; max-height:100px; display:block; margin:0 auto; }
  .signature-upload-card { background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:1rem; }
  .empty-row { text-align:center; color:var(--faint); font-style:italic; padding:1rem; }
  .hidden-preview { display:none !important; }

  /* TOAST (page's own system; classes preserved, restyled) */
  .toast-message { position:fixed; top:1.1rem; right:1.1rem; z-index:2000; padding:0.9rem 1.1rem; border-radius:12px; color:#fff; font-weight:600; font-size:0.86rem; box-shadow:var(--shadow-pop); opacity:0; transform:translateY(-10px); transition:all 0.25s var(--ease); }
  .toast-message.show { opacity:1; transform:translateY(0); }
  .toast-success { background:linear-gradient(135deg, #10b981, #059669); }
  .toast-error { background:linear-gradient(135deg, #f43f5e, #e11d48); }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:9px; height:9px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; border:2px solid transparent; background-clip:content-box; }
  ::-webkit-scrollbar-thumb:hover { background:#94a3b8; background-clip:content-box; }

  /* GRID + RESPONSIVE */
  .grid-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1.15rem; margin-bottom:1.4rem; }
  @media (max-width:1200px) { .grid-stats { grid-template-columns:repeat(2,1fr); } }
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
        <p>IDP Forms</p>
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
      <a href="Individual_Development_Plan_Form.php" class="rail-link active">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>
      <a href="Evaluation_Form.php" class="rail-link">
        <i class="ri-file-search-line"></i><span>Evaluation Forms</span>
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
          <span class="avatar">A</span>
          <span class="avatar-meta"><span class="nm">Admin</span><span class="rl">IDP Forms</span></span>
        </div>
      </div>
    </header>

    <!-- SCROLLABLE CONTENT -->
    <div class="content-scroll">
      <div class="content">

        <!-- PAGE HEADER -->
        <div class="page-head">
          <div>
            <p class="eyebrow">Individual Development Plans</p>
            <h2>IDP Submissions</h2>
            <p>View and manage IDPs approved by Department Administrators.</p>
          </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid-stats">
          <div class="stat a-indigo">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Employees</p>
                <p class="stat-value num"><?php echo count($employees); ?></p>
                <p class="stat-sub">with approved IDPs</p>
              </div>
              <div class="stat-ico ico-indigo"><i class="ri-team-line"></i></div>
            </div>
          </div>
          <div class="stat a-violet">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Submissions</p>
                <p class="stat-value num"><?php echo $total_submissions; ?></p>
                <p class="stat-sub">IDPs approved by dept</p>
              </div>
              <div class="stat-ico ico-violet"><i class="ri-file-list-3-line"></i></div>
            </div>
          </div>
          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Departments</p>
                <p class="stat-value num"><?php echo $departments_represented; ?></p>
                <p class="stat-sub">represented</p>
              </div>
              <div class="stat-ico ico-emerald"><i class="ri-building-line"></i></div>
            </div>
          </div>
          <div class="stat a-warning">
            <div class="stat-body">
              <div>
                <p class="stat-label">Pending Dept Approval</p>
                <p class="stat-value num"><?php echo $pendingCount; ?></p>
                <p class="stat-sub">awaiting department admin</p>
              </div>
              <div class="stat-ico ico-warning"><i class="ri-time-line"></i></div>
            </div>
          </div>
        </div>

        <!-- FILTER BAR — Department (client-side filter) · Status · Search -->
        <!-- overflow:visible so the open dropdown isn't clipped by the card -->
        <div class="card" style="margin-bottom:1.4rem; overflow:visible; position:relative; z-index:20;">
          <div class="card-body" style="padding:1.05rem 1.15rem;">
            <div class="filterbar">

              <!-- Department filter (click dropdown; links carry data-department) -->
              <div class="filter" id="fltDept">
                <button class="filter-btn" type="button" onclick="toggleFilter('fltDept')">
                  <span class="flt-label">Department</span>
                  <span class="flt-value" id="department-selected">All Departments</span>
                  <i class="ri-arrow-down-s-line caret"></i>
                </button>
                <div class="filter-menu">
                  <a href="#" data-department="">All Departments</a>
                  <?php foreach ($departments as $abbr => $name): ?>
                    <a href="#" data-department="<?= htmlspecialchars($abbr) ?>"><?= htmlspecialchars($name) ?></a>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Status (decorative, matches original) -->
              <select class="status-select" aria-label="Filter by status">
                <option>All Status</option>
                <option selected>Approved by Dept</option>
              </select>

              <!-- Search (client-side filter on employee name) -->
              <div class="search">
                <i class="ri-search-line"></i>
                <input type="text" id="search-input" placeholder="Search by employee name…" value="" />
                <button type="button" class="search-go" onclick="performSearch()" aria-label="Search"><i class="ri-arrow-right-line"></i></button>
              </div>

            </div>
          </div>
        </div>

        <!-- RESULTS META -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.8rem;">
          <p style="font-size:0.82rem; color:var(--muted);">
            Showing <span class="num"><?= count($employees) > 0 ? 1 : 0 ?></span>–<span class="num"><?= count($employees) ?></span>
            of <span class="num"><?= count($employees) ?></span> employees
          </p>
        </div>

        <!-- EMPLOYEE TABLE -->
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="data">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Position</th>
                  <th>Department</th>
                  <th>IDPs Approved</th>
                  <th>Last Submission</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="forms-table-body">
                <?php if (empty($employees)): ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty">
                        <i class="ri-file-list-3-line"></i>
                        <h4>No approved IDP submissions found</h4>
                        <p>IDPs will appear here once approved by Department Administrators.</p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($employees as $employee): ?>
                    <?php $department_name = $departments[$employee['department']] ?? $employee['department']; ?>
                    <tr class="employee-item" data-department="<?= htmlspecialchars($employee['department']) ?>">
                      <td>
                        <div style="display:flex; align-items:center; gap:0.7rem;">
                          <div class="emp-avatar"><i class="ri-user-line"></i></div>
                          <div class="cell-name"><?= htmlspecialchars($employee['name']) ?></div>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($employee['position']) ?></td>
                      <td><span class="department-badge"><?= htmlspecialchars($department_name) ?></span></td>
                      <td><span class="num" style="font-weight:700; color:var(--accent); font-size:1.05rem;"><?= (int) $employee['total_idps'] ?></span></td>
                      <td class="num" style="color:var(--muted);">
                        <?php echo !empty($employee['last_submission']) ? date('M j, Y', strtotime($employee['last_submission'])) : 'N/A'; ?>
                      </td>
                      <td>
                        <button class="view-btn view-idp-btn"
                                data-user-id="<?= (int) $employee['user_id'] ?>"
                                data-user-name="<?= htmlspecialchars($employee['name']) ?>">
                          <i class="ri-eye-line"></i> View & Sign
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
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
     VIEW MODAL — IDP forms (content injected by JS).
     Uses .modal-overlay/.active so existing JS works unchanged.
     ============================================================= -->
<div class="modal-overlay" id="view-idp-modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="modal-employee-name">Employee IDP Forms</h3>
      <button type="button" class="modal-x close-modal-btn" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="modal-body">
      <div class="idp-list" id="idp-forms-list"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost close-modal-btn">Close</button>
      <button type="button" class="print-btn" id="print-idp-btn"><i class="ri-printer-line"></i> Print</button>
    </div>
  </div>
</div>
<script>
  /* ============================================================
     SHELL HELPERS — sidebar collapse/drawer + filter dropdown
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

  function toggleFilter(id) {
    const target = document.getElementById(id);
    document.querySelectorAll('.filter.open').forEach(f => { if (f !== target) f.classList.remove('open'); });
    target.classList.toggle('open');
  }
  function closeAllFilters() { document.querySelectorAll('.filter.open').forEach(f => f.classList.remove('open')); }
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.filter')) closeAllFilters();
  });

  /* ============================================================
     PAGE LOGIC (preserved from original)
     ============================================================ */
  const currentPageUrl = <?php echo json_encode($currentPageUrl); ?>;

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  function safeValue(value, fallback = 'N/A') {
    if (value === null || value === undefined || value === '') {
      return fallback;
    }
    return escapeHtml(String(value));
  }

  function safeParseJSON(value) {
    try {
      return JSON.parse(value);
    } catch (e) {
      return {};
    }
  }

  function formatDateString(value) {
    if (!value) return 'N/A';
    const d = new Date(value);
    if (isNaN(d.getTime())) {
      return escapeHtml(String(value));
    }
    return d.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  }

  function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-message ${type === 'success' ? 'toast-success' : 'toast-error'}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => {
        toast.remove();
      }, 250);
    }, 2800);
  }

  function buildLongTermRows(goals) {
    if (!Array.isArray(goals) || goals.length === 0) {
      return `<tr><td colspan="4" class="empty-row">No long-term goals specified</td></tr>`;
    }

    return goals.map(goal => `
      <tr>
        <td>${safeValue(goal.area)}</td>
        <td>${safeValue(goal.activity)}</td>
        <td>${safeValue(goal.target_date)}</td>
        <td>${safeValue(goal.stage)}</td>
      </tr>
    `).join('');
  }

  function buildShortTermRows(goals) {
    if (!Array.isArray(goals) || goals.length === 0) {
      return `<tr><td colspan="6" class="empty-row">No short-term goals specified</td></tr>`;
    }

    return goals.map(goal => `
      <tr>
        <td>${safeValue(goal.area)}</td>
        <td>${safeValue(goal.priority)}</td>
        <td>${safeValue(goal.activity)}</td>
        <td>${safeValue(goal.target_date)}</td>
        <td>${safeValue(goal.responsible)}</td>
        <td>${safeValue(goal.stage)}</td>
      </tr>
    `).join('');
  }

  function buildStaticSignaturePreview(src, altText) {
    if (!src) {
      return `<div class="text-sm text-gray-400 italic mt-3">No signature uploaded yet</div>`;
    }

    return `
      <div class="signature-preview">
        <img src="${escapeHtml(src)}" alt="${escapeHtml(altText)}">
      </div>
    `;
  }

  function attachPreviewHandlers(scope) {
    const fileInputs = scope.querySelectorAll('.signature-file-input');

    fileInputs.forEach(input => {
      input.addEventListener('change', function() {
        const file = this.files[0];
        const previewId = this.getAttribute('data-preview-id');
        const emptyId = this.getAttribute('data-empty-id');
        const previewImg = document.getElementById(previewId);
        const emptyText = document.getElementById(emptyId);

        if (!file) {
          return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
          previewImg.src = e.target.result;
          previewImg.classList.remove('hidden-preview');
          if (emptyText) emptyText.classList.add('hidden-preview');
        };
        reader.readAsDataURL(file);
      });
    });
  }

  function attachAccordionHandlers(scope) {
    const toggles = scope.querySelectorAll('.accordion-toggle');

    toggles.forEach(toggle => {
      toggle.addEventListener('click', function() {
        const formId = this.getAttribute('data-id');
        const content = document.getElementById(`content-${formId}`);
        const arrow = this.querySelector('.accordion-arrow');

        if (!content) return;

        content.classList.toggle('expanded');

        if (arrow) {
          arrow.classList.toggle('ri-arrow-down-s-line');
          arrow.classList.toggle('ri-arrow-up-s-line');
        }

        if (content.classList.contains('expanded')) {
          document.getElementById('print-idp-btn').setAttribute('data-form-id', formId);
        }
      });
    });
  }

  function attachSignatureSaveHandlers(scope, userId, userName) {
    const forms = scope.querySelectorAll('.admin-signature-form');

    forms.forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();

        const submitBtn = this.querySelector('.save-signatures-btn');
        const formId = this.getAttribute('data-form-id');

        const fd = new FormData(this);

        submitBtn.disabled = true;
        submitBtn.innerHTML = `<i class="ri-loader-4-line mr-2 animate-spin"></i> Saving...`;

        fetch(currentPageUrl, {
          method: 'POST',
          body: fd
        })
        .then(response => response.json())
        .then(result => {
          if (result.success) {
            showToast(result.message, 'success');
            showIDPModal(userId, userName, formId);
          } else {
            showToast(result.message || 'Failed to save signatures.', 'error');
          }
        })
        .catch(() => {
          showToast('An error occurred while saving signatures.', 'error');
        })
        .finally(() => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = `<i class="ri-save-line mr-2"></i> Save Signatures`;
        });
      });
    });
  }

  function renderFormsIntoModal(data, userId, userName, expandFormId = null) {
    const modalContent = document.getElementById('idp-forms-list');

    if (!Array.isArray(data) || data.length === 0) {
      modalContent.innerHTML = `
        <div class="empty">
          <i class="ri-file-list-3-line"></i>
          <h4>No approved IDP forms found</h4>
          <p>This employee's IDPs need to be approved by the Department Administrator first.</p>
        </div>
      `;
      return;
    }

    let html = '';

    data.forEach((form, index) => {
      const formData = safeParseJSON(form.form_data);
      const personalInfo = formData.personal_info || {};
      const purpose = formData.purpose || {};
      const longTermGoals = formData.long_term_goals || [];
      const shortTermGoals = formData.short_term_goals || [];
      const certification = formData.certification || {};
      const submittedDate = formatDateString(form.submitted_at);

      html += `
        <div class="form-card">
          <button class="accordion-toggle w-full text-left focus:outline-none" data-id="${form.id}">
            <div>
              <h3 class="font-bold text-lg">IDP Form #${index + 1}</h3>
              <p style="color:var(--muted); font-size:0.85rem; margin-top:2px;">Submitted: ${submittedDate}</p>
            </div>
            <div class="flex items-center" style="gap:1rem;">
              <span class="status-approved">Approved by Dept</span>
              <i class="accordion-arrow ri-arrow-down-s-line transition-transform duration-300"></i>
            </div>
          </button>

          <div class="accordion-content" id="content-${form.id}" style="padding:0 1.25rem;">
            <!-- Personal Information -->
            <div class="form-section">
              <h4 class="form-section-title">Personal Information</h4>
              <div class="grid-form">
                <div class="form-field"><label>Name</label><div class="value">${safeValue(personalInfo.name)}</div></div>
                <div class="form-field"><label>Position</label><div class="value">${safeValue(personalInfo.position)}</div></div>
                <div class="form-field"><label>Salary Grade</label><div class="value">${safeValue(personalInfo.salary_grade)}</div></div>
                <div class="form-field"><label>Years in Position</label><div class="value">${safeValue(personalInfo.years_position)}</div></div>
                <div class="form-field"><label>Years in LSPU</label><div class="value">${safeValue(personalInfo.years_lspu)}</div></div>
                <div class="form-field"><label>Years in Other Office/Agency</label><div class="value">${safeValue(personalInfo.years_other)}</div></div>
                <div class="form-field"><label>Division</label><div class="value">${safeValue(personalInfo.division)}</div></div>
                <div class="form-field"><label>Office</label><div class="value">${safeValue(personalInfo.office)}</div></div>
                <div class="form-field"><label>Office Address</label><div class="value">${safeValue(personalInfo.address)}</div></div>
                <div class="form-field"><label>Supervisor's Name</label><div class="value">${safeValue(personalInfo.supervisor)}</div></div>
              </div>
            </div>

            <!-- Purpose -->
            <div class="form-section">
              <h4 class="form-section-title">Purpose</h4>
              <div class="space-y-2">
                <div class="flex items-center">
                  <input type="checkbox" class="checkbox-custom mr-2" ${purpose.purpose1 ? 'checked' : ''} disabled>
                  <label style="color:var(--ink-2);">To meet the competencies in the current positions</label>
                </div>
                <div class="flex items-center">
                  <input type="checkbox" class="checkbox-custom mr-2" ${purpose.purpose2 ? 'checked' : ''} disabled>
                  <label style="color:var(--ink-2);">To increase the level of competencies of current positions</label>
                </div>
                <div class="flex items-center">
                  <input type="checkbox" class="checkbox-custom mr-2" ${purpose.purpose3 ? 'checked' : ''} disabled>
                  <label style="color:var(--ink-2);">To meet the competencies in the next higher position</label>
                </div>
                <div class="flex items-center">
                  <input type="checkbox" class="checkbox-custom mr-2" ${purpose.purpose4 ? 'checked' : ''} disabled>
                  <label style="color:var(--ink-2);">To acquire new competencies across different functions/position</label>
                </div>
                <div class="flex items-center">
                  <input type="checkbox" class="checkbox-custom mr-2" ${purpose.purpose5 ? 'checked' : ''} disabled>
                  <label style="color:var(--ink-2);">Others, please specify:</label>
                  <span class="ml-2" style="color:var(--ink);">${safeValue(purpose.purpose_other)}</span>
                </div>
              </div>
            </div>

            <!-- Long Term Goals -->
            <div class="form-section">
              <h4 class="form-section-title">Training/Development Interventions for Long Term Goals (Next Five Years)</h4>
              <div class="table-responsive">
                <table class="modal-table">
                  <thead>
                    <tr>
                      <th>Area of Development</th>
                      <th>Development Activity</th>
                      <th>Target Completion Date</th>
                      <th>Completion Stage</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${buildLongTermRows(longTermGoals)}
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Short Term Goals -->
            <div class="form-section">
              <h4 class="form-section-title">Short Term Development Goals Next Year</h4>
              <div class="table-responsive">
                <table class="modal-table">
                  <thead>
                    <tr>
                      <th>Area of Development</th>
                      <th>Priority for Learning and Development Program (LDP)</th>
                      <th>Development Activity</th>
                      <th>Target Completion Date</th>
                      <th>Who is Responsible</th>
                      <th>Completion Stage</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${buildShortTermRows(shortTermGoals)}
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Certification -->
            <div class="form-section">
              <h4 class="form-section-title">Certification and Commitment</h4>

              <div class="signature-grid">
                <div class="signature-box">
                  <div>Employee Name</div>
                  <div class="signature-name">${safeValue(certification.employee_name)}</div>
                  <div class="signature-date">Date: ${safeValue(certification.employee_date)}</div>
                  ${buildStaticSignaturePreview(certification.employee_signature || '', 'Employee Signature')}
                </div>

                <div class="signature-box">
                  <div>Supervisor Name</div>
                  <div class="signature-name">${safeValue(certification.supervisor_name)}</div>
                  <div class="signature-date">Date: ${safeValue(certification.supervisor_date)}</div>
                  ${buildStaticSignaturePreview(certification.supervisor_signature || '', 'Supervisor Signature')}
                </div>

                <div class="signature-box">
                  <div>Director Name</div>
                  <div class="signature-name">${safeValue(certification.director_name)}</div>
                  <div class="signature-date">Date: ${safeValue(certification.director_date)}</div>
                  ${buildStaticSignaturePreview(certification.director_signature || '', 'Director Signature')}
                </div>
              </div>

              <div class="mt-4 p-4 bg-warn-soft rounded-lg border border-warn">
                <p class="text-sm text-warn-ink">
                  <i class="ri-information-line mr-2"></i>
                  This IDP has been approved by the Department Administrator. 
                  Please upload the Supervisor and Director signatures to complete the process.
                </p>
              </div>

              <form class="admin-signature-form mt-6" data-form-id="${form.id}" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_admin_signatures">
                <input type="hidden" name="form_id" value="${form.id}">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div class="signature-upload-card">
                    <label class="block text-sm font-semibold mb-2" style="color:var(--ink-2);">Upload Supervisor Signature</label>
                    <input
                      type="file"
                      name="supervisor_signature_file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      class="block w-full text-sm
                             file:mr-4 file:py-2 file:px-4
                             file:rounded-lg file:border-0
                             file:text-sm file:font-semibold
                             file:bg-primary file:text-white
                             hover:file:bg-secondary
                             cursor-pointer signature-file-input"
                      data-preview-id="supervisor-preview-${form.id}"
                      data-empty-id="supervisor-empty-${form.id}"
                    >
                    <p class="text-xs mt-2" style="color:var(--muted);">Allowed: PNG, JPG, JPEG, WEBP</p>
                    <div class="signature-preview">
                      <img
                        id="supervisor-preview-${form.id}"
                        src="${certification.supervisor_signature ? escapeHtml(certification.supervisor_signature) : ''}"
                        alt="Supervisor Signature Preview"
                        class="${certification.supervisor_signature ? '' : 'hidden-preview'}"
                      >
                      <div id="supervisor-empty-${form.id}" class="${certification.supervisor_signature ? 'hidden-preview' : ''} text-sm italic" style="color:var(--faint);">
                        No supervisor signature uploaded yet
                      </div>
                    </div>
                  </div>

                  <div class="signature-upload-card">
                    <label class="block text-sm font-semibold mb-2" style="color:var(--ink-2);">Upload Director Signature</label>
                    <input
                      type="file"
                      name="director_signature_file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      class="block w-full text-sm
                             file:mr-4 file:py-2 file:px-4
                             file:rounded-lg file:border-0
                             file:text-sm file:font-semibold
                             file:bg-primary file:text-white
                             hover:file:bg-secondary
                             cursor-pointer signature-file-input"
                      data-preview-id="director-preview-${form.id}"
                      data-empty-id="director-empty-${form.id}"
                    >
                    <p class="text-xs mt-2" style="color:var(--muted);">Allowed: PNG, JPG, JPEG, WEBP</p>
                    <div class="signature-preview">
                      <img
                        id="director-preview-${form.id}"
                        src="${certification.director_signature ? escapeHtml(certification.director_signature) : ''}"
                        alt="Director Signature Preview"
                        class="${certification.director_signature ? '' : 'hidden-preview'}"
                      >
                      <div id="director-empty-${form.id}" class="${certification.director_signature ? 'hidden-preview' : ''} text-sm italic" style="color:var(--faint);">
                        No director signature uploaded yet
                      </div>
                    </div>
                  </div>
                </div>

                <div class="mt-4 flex items-center justify-between flex-wrap gap-4">
                  <p class="text-sm" style="color:var(--muted);">
                    Employee signature is display-only. Admin uploads only the Supervisor and Director signatures here.
                  </p>
                  <button type="submit" class="view-btn save-signatures-btn">
                    <i class="ri-save-line mr-2"></i> Complete & Save
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      `;
    });

    modalContent.innerHTML = html;

    attachAccordionHandlers(modalContent);
    attachPreviewHandlers(modalContent);
    attachSignatureSaveHandlers(modalContent, userId, userName);

    if (expandFormId) {
      const targetBtn = modalContent.querySelector(`.accordion-toggle[data-id="${expandFormId}"]`);
      if (targetBtn) {
        targetBtn.click();
      }
    }
  }

  function showIDPModal(userId, userName, expandFormId = null) {
    const modal = document.getElementById('view-idp-modal');
    const modalTitle = document.getElementById('modal-employee-name');
    const modalContent = document.getElementById('idp-forms-list');

    modal.dataset.userId = userId;
    modal.dataset.userName = userName;

    modalTitle.textContent = `Loading IDP Forms for ${userName}...`;
    modalContent.innerHTML = `
      <div class="flex justify-center items-center" style="padding:3rem 0;">
        <div style="width:36px;height:36px;border:3px solid var(--accent-soft);border-top-color:var(--accent);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg);}}</style>
    `;

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    fetch(`${currentPageUrl}?action=get_user_forms&user_id=${encodeURIComponent(userId)}`)
      .then(response => response.json())
      .then(data => {
        modalTitle.textContent = `${userName}'s IDP Forms`;
        renderFormsIntoModal(data, userId, userName, expandFormId);
        document.getElementById('print-idp-btn').setAttribute('data-user-id', userId);
      })
      .catch(error => {
        console.error(error);
        modalContent.innerHTML = `
          <div class="empty">
            <i class="ri-error-warning-line" style="color:var(--bad);"></i>
            <h4>Error loading IDP forms</h4>
            <p>Please try again later.</p>
          </div>
        `;
      });
  }

  document.addEventListener('DOMContentLoaded', function() {
    syncCollapseButton();

    const departmentLinks = document.querySelectorAll('.filter-menu a[data-department]');
    const searchInput = document.getElementById('search-input');
    const formsTableBody = document.getElementById('forms-table-body');
    const departmentSelected = document.getElementById('department-selected');
    const viewButtons = document.querySelectorAll('.view-idp-btn');
    const modal = document.getElementById('view-idp-modal');
    const closeButtons = document.querySelectorAll('.close-modal-btn');
    const modalPrintBtn = document.getElementById('print-idp-btn');

    let currentDepartment = '';

    function filterEmployees() {
      const searchTerm = searchInput.value.toLowerCase();
      const rows = formsTableBody.querySelectorAll('.employee-item');

      rows.forEach(row => {
        const department = row.getAttribute('data-department');
        const text = row.textContent.toLowerCase();

        const departmentMatch = !currentDepartment || department === currentDepartment;
        const searchMatch = !searchTerm || text.includes(searchTerm);

        row.style.display = (departmentMatch && searchMatch) ? '' : 'none';
      });
    }

    // Make search function available to inline onclick
    window.performSearch = filterEmployees;

    departmentLinks.forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        currentDepartment = this.getAttribute('data-department') || '';

        departmentSelected.textContent = currentDepartment === '' ? 'All Departments' : this.textContent;
        filterEmployees();
        closeAllFilters();
      });
    });

    searchInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        filterEmployees();
      }
    });

    viewButtons.forEach(button => {
      button.addEventListener('click', function() {
        const userId = this.getAttribute('data-user-id');
        const userName = this.getAttribute('data-user-name');
        showIDPModal(userId, userName);
      });
    });

    closeButtons.forEach(button => {
      button.addEventListener('click', function() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
      });
    });

    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
      }
    });

    modalPrintBtn.addEventListener('click', function() {
      const formId = this.getAttribute('data-form-id');

      if (formId) {
        window.open(`Individual_Development_Plan_pdf.php?form_id=${formId}`, '_blank');
      } else {
        showToast('Please open a specific IDP form before printing.', 'error');
      }
    });
  });
</script>
</body>
</html>