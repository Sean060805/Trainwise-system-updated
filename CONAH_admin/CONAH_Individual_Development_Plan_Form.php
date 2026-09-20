<?php
/* ===================================================================
   CONAH IDP SUBMISSIONS — Department view for College of Nursing and Allied Health
   =================================================================== */

session_start();
require_once '../config.php';

// 2026-09-04 - sidebar "Training Demand" badge count, same helper the
// dashboard and dedicated Training Demand page use, so the number matches everywhere.
require_once '../ml_recommendations.php';
$forwardedDemandCount = getForwardedDemandCountForCollege($con, 'CONAH');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// ISO 25010 Security audit (2026-09-06): the above check only confirmed
// *a* user was logged in, not that they were THIS college's dean - any
// authenticated account (another dean, or a plain employee) could load
// this page directly by URL. Added the missing role check.
if (($_SESSION['user_role'] ?? '') !== 'admin_conah') {
    header("Location: ../index.php");
    exit();
}

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

    $stmt = $con->prepare("
        SELECT id, form_data, submitted_at
        FROM idp_forms
        WHERE user_id = ? AND status = 'submitted'
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
| NEW: Normalize a date string coming from the JSON form_data so it is
| always safe to insert into a real DATE column (empty/garbage -> NULL
| instead of causing the INSERT to fail under strict SQL mode).
|--------------------------------------------------------------------------
*/
function normalizeDateForDb($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

/*
|--------------------------------------------------------------------------
| NEW: Rough heuristic check that an uploaded image actually looks like a
| signature (mostly blank/transparent background with a small amount of
| dark ink strokes, low color variety) rather than a photo or unrelated
| picture. This is NOT a perfect AI detector, just a sanity filter.
|--------------------------------------------------------------------------
*/
function isLikelySignatureImage($src, $width, $height)
{
    if (!$src || $width <= 0 || $height <= 0) {
        // Fail-open: if we can't analyze it, don't block the upload here —
        // the caller already validated mime type/size separately.
        return true;
    }

    // Sample the image on a grid instead of every pixel, for performance.
    $sampleStep   = max(1, (int) floor(min($width, $height) / 150));
    $totalSampled = 0;
    $inkPixels    = 0;
    $colorBuckets = [];

    for ($y = 0; $y < $height; $y += $sampleStep) {
        for ($x = 0; $x < $width; $x += $sampleStep) {
            $rgba = imagecolorat($src, $x, $y);

            $a = ($rgba & 0x7F000000) >> 24; // 0 = opaque, 127 = fully transparent
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            $totalSampled++;

            // Mostly-transparent pixels are background — don't count as "ink".
            if ($a > 100) {
                continue;
            }

            $luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

            // Noticeably darker than a white/near-white background = ink stroke.
            if ($luminance < 200) {
                $inkPixels++;
            }

            // Coarse color quantization to estimate how "colorful" the image is.
            $bucketKey = (int) floor($r / 32) . '-' . (int) floor($g / 32) . '-' . (int) floor($b / 32);
            $colorBuckets[$bucketKey] = true;
        }
    }

    if ($totalSampled === 0) {
        return true;
    }

    $inkRatio   = $inkPixels / $totalSampled;
    $colorCount = count($colorBuckets);

    // Signatures: mostly blank background + a modest amount of dark ink,
    // and usually just one or two ink colors (black/blue).
    // Photos/selfies: much higher ink ratio and/or far more distinct colors.
    if ($inkRatio > 0.55) {
        return false;
    }

    if ($colorCount > 60) {
        return false;
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| NEW: Self-healing helper. Some ENUM columns (idp_forms.status,
| idp_certification.status) may not yet include every status value the
| app uses (e.g. 'submitted_to_hr'). Instead of requiring a manual
| ALTER TABLE every time, this checks the live column definition and
| adds the missing value automatically, preserving whatever values,
| NULL setting, and default were already there.
|--------------------------------------------------------------------------
*/
function ensureEnumHasValue($con, $table, $column, $newValue)
{
    $result = $con->query("SHOW COLUMNS FROM `$table` LIKE '" . $con->real_escape_string($column) . "'");
    if (!$result) {
        return;
    }
    $col = $result->fetch_assoc();
    if (!$col || stripos($col['Type'], 'enum(') !== 0) {
        return; // not an enum column, nothing to do
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $col['Type'], $matches);
    $values = $matches[1] ?? [];
    $values = array_map(function ($v) {
        return str_replace("\\'", "'", $v);
    }, $values);

    if (in_array($newValue, $values, true)) {
        return; // already present, nothing to do
    }

    $values[] = $newValue;
    $enumList = implode(',', array_map(function ($v) use ($con) {
        return "'" . $con->real_escape_string($v) . "'";
    }, $values));

    $nullPart = (strtoupper($col['Null']) === 'NO') ? 'NOT NULL' : 'NULL';
    $defaultPart = '';
    if ($col['Default'] !== null) {
        $defaultPart = "DEFAULT '" . $con->real_escape_string($col['Default']) . "'";
    }

    $sql = "ALTER TABLE `$table` MODIFY `$column` ENUM($enumList) $nullPart $defaultPart";
    $con->query($sql);
}

/*
|--------------------------------------------------------------------------
| Process signature upload
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

    $uploadDir = '../uploads/signatures/conah/';
    ensureDirectory($uploadDir);

    $pngPath = $uploadDir . $role . '_signature_form_' . $formId . '.png';

    $src = loadImageResource($file['tmp_name'], $mime);

    if ($src && function_exists('imagecreatetruecolor') && function_exists('imagesavealpha')) {
        $width  = imagesx($src);
        $height = imagesy($src);

        // NEW: reject files that don't look like an actual signature
        // (e.g. someone accidentally uploads a selfie or random photo).
        if (!isLikelySignatureImage($src, $width, $height)) {
            imagedestroy($src);
            return [
                'status' => 'error',
                'path'   => '',
                'message'=> ucfirst($role) . " file doesn't look like a signature. Please upload a clear image of the actual signature (not a photo or unrelated picture)."
            ];
        }

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

                if ($effectiveGray >= 240) {
                    continue;
                }

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
| AJAX: Get forms for modal
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
| AJAX: Save admin signatures AND submit to HR (FIXED)
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

    if ($formRow['status'] !== 'submitted') {
        jsonResponse([
            'success' => false,
            'message' => 'Only submitted forms can be signed by admin.'
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

    // NEW: normalize the certification dates for the DATE columns in
    // idp_certification. Empty/garbage strings become NULL instead of
    // breaking the INSERT under strict SQL mode.
    $empDateForDb = normalizeDateForDb($certification['employee_date']);
    $supDateForDb = normalizeDateForDb($certification['supervisor_date']);
    $dirDateForDb = normalizeDateForDb($certification['director_date']);

    // NEW: make sure both status ENUM columns actually allow 'submitted_to_hr'
    // before we try to write it. This is what was causing
    // "Data truncated for column 'status'".
    ensureEnumHasValue($con, 'idp_forms', 'status', 'submitted_to_hr');
    ensureEnumHasValue($con, 'idp_certification', 'status', 'submitted_to_hr');

    $con->begin_transaction();

    try {
        // Update main form JSON and change status to 'submitted_to_hr'
        $stmt = $con->prepare("UPDATE idp_forms SET form_data = ?, status = 'submitted_to_hr', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $jsonData, $formId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update IDP form.");
        }
        $stmt->close();

        // Sync certification table with status and hr_approved_date.
        // NOTE: this REPLACE INTO requires a UNIQUE key on form_id in
        // idp_certification, otherwise it will keep inserting new rows
        // instead of updating the existing one. Also requires the
        // 'status' ENUM to include 'submitted_to_hr' as a valid value.
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
                director_signature,
                status,
                hr_approved_date
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $status = 'submitted_to_hr';

        $stmt->bind_param(
            "issssssssss",
            $formId,
            $certification['employee_name'],
            $empDateForDb,
            $certification['employee_signature'],
            $certification['supervisor_name'],
            $supDateForDb,
            $certification['supervisor_signature'],
            $certification['director_name'],
            $dirDateForDb,
            $certification['director_signature'],
            $status
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to update certification table.");
        }
        $stmt->close();

        $con->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Signatures saved and IDP submitted to HR successfully!',
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
| Main employee list - FILTERED TO CONAH DEPARTMENT
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
    WHERE f.status = 'submitted' AND u.department = 'CONAH'
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
    'CHMT'  => 'College of International Hospitality and Tourism Management (CIHTM)',
    'CIT'   => 'College of Industrial Technology',
    'COE'   => 'College of Engineering',
    'COF'   => 'College of Fisheries',
    'COL'   => 'College of Law',
    'CONAH' => 'College of Nursing and Allied Health',
    'CTE'   => 'College of Teacher Education'
];

$currentPageUrl = $_SERVER['PHP_SELF'];

// Derived stats
$total_submissions = 0;
foreach ($employees as $employee) { $total_submissions += (int) $employee['total_idps']; }

// Get CONAH department stats
$conahStats = [
    'total_employees' => 0,
    'with_idp' => 0,
    'submissions' => $total_submissions,
    'submitted_to_hr' => 0
];

$statsResult = $con->query("
    SELECT COUNT(*) as count 
    FROM users 
    WHERE department = 'CONAH' AND role = 'user' 
    AND teaching_status IS NOT NULL AND teaching_status != ''
");
if ($statsResult) {
    $conahStats['total_employees'] = $statsResult->fetch_assoc()['count'];
}
$conahStats['with_idp'] = count($employees);

// Get count of IDPs submitted to HR
$hrResult = $con->query("
    SELECT COUNT(*) as count 
    FROM idp_forms f
    JOIN users u ON f.user_id = u.id
    WHERE f.status = 'submitted_to_hr' AND u.department = 'CONAH'
");
if ($hrResult) {
    $conahStats['submitted_to_hr'] = $hrResult->fetch_assoc()['count'];
}

// Get current user info
$adminName = $user['name'] ?? 'CONAH Admin';
$initials = strtoupper(substr($adminName, 0, 1));
$parts = preg_split('/\s+/', trim($adminName));
if (count($parts) > 1) { $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CONAH IDP Submissions · LSPU TNA</title>

  <!-- FONTS -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

  <!-- Icon sets -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- TAILWIND -->
  <link rel="stylesheet" href="../assets/css/tw-15.css">
<style>
  /* ==========================================================
     DESIGN SYSTEM — CONAH Green Accent Version
     ========================================================== */

  :root {
    --bg:#f5f6f8;
    --bg-grad:radial-gradient(1100px 600px at 100% -8%, rgba(101,163,13,0.05), transparent 60%);
    --surface:#ffffff; --surface-2:#f8fafc; --surface-3:#f1f3f7;
    --ink:#0f172a; --ink-2:#334155; --muted:#64748b; --faint:#94a3b8;
    --line:#e5e7eb; --line-soft:#eef1f5;
    --accent:#65a30d; --accent-700:#4d7c0f; --accent-soft:#f0fdf4; --accent-ink:#3f6212;
    --green:#65a30d; --green-light:#84cc16; --green-soft:#f0fdf4;
    --gold:#ca8a04; --gold-light:#eab308; --gold-soft:#fef3c7;
    --dark:#1f2937; --dark-soft:#374151;
    --ok:#059669; --ok-soft:#ecfdf5; --ok-ink:#065f46;
    --warn:#d97706; --warn-soft:#fffbeb; --warn-ink:#92400e;
    --bad:#e11d48; --bad-soft:#fff1f2; --bad-ink:#9f1239;
    --sky:#0284c7; --sky-soft:#f0f9ff;
    --rail:#0f172a; --rail-2:#1f2937; --rail-line:rgba(148,163,184,0.14);
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

  .app { display:grid; grid-template-columns:var(--rail-w) 1fr; min-height:100vh; transition:grid-template-columns 0.28s var(--ease); }
  .app.rail-collapsed { grid-template-columns:var(--rail-w-min) 1fr; }
  .app-main { min-width:0; display:flex; flex-direction:column; max-height:100vh; overflow:hidden; }
  .content-scroll { flex:1 1 auto; overflow-y:auto; overflow-x:hidden; }
  .content { max-width:1640px; margin:0 auto; padding:1.6rem 1.8rem 3rem; }

  .rail { background:linear-gradient(190deg, var(--rail) 0%, var(--rail-2) 100%); color:var(--rail-text); display:flex; flex-direction:column; position:sticky; top:0; height:100vh; border-right:1px solid rgba(0,0,0,0.2); z-index: 50; }
  .rail-brand { display:flex; align-items:center; gap:0.65rem; padding:1.0rem 1.15rem; border-bottom:1px solid var(--rail-line); min-height:var(--topbar-h); }
  .rail-logo { width:38px; height:38px; border-radius:10px; background:#fff; padding:4px; flex-shrink:0; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 16px -8px rgba(0,0,0,0.6); }
  .rail-logo-fallback { width:38px; height:38px; border-radius:10px; flex-shrink:0; background:linear-gradient(135deg, var(--green), var(--green-light)); display:flex; align-items:center; justify-content:center; }
  .rail-brand-text { min-width:0; transition:opacity 0.2s var(--ease); }
  .rail-brand-text h1 { font-size:0.92rem; color:#fff; line-height:1.15; white-space:nowrap; }
  .rail-brand-text p  { font-size:0.68rem; color:var(--rail-text-2); margin-top:2px; white-space:nowrap; }
  .rail-nav { flex:1 1 auto; overflow-y:auto; padding:1rem 0.7rem; }
  .rail-section-label { font-size:0.64rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--rail-text-2); padding:0 0.85rem; margin:0.4rem 0 0.55rem; transition:opacity 0.2s var(--ease); }
  .rail-link { display:flex; align-items:center; gap:0.85rem; padding:0.7rem 0.85rem; margin:2px 0; border-radius:11px; color:var(--rail-text); font-size:0.875rem; font-weight:500; border:1px solid transparent; transition:background 0.18s var(--ease), color 0.18s var(--ease), border-color 0.18s var(--ease); position:relative; white-space:nowrap; }
  .rail-link i:first-child { font-size:1.2rem; flex-shrink:0; width:22px; text-align:center; }
  .rail-link:hover { background:rgba(255,255,255,0.06); color:#fff; }
  .rail-link.active { background:linear-gradient(100deg, rgba(101,163,13,0.26), rgba(101,163,13,0.08)); color:#fff; border-color:rgba(132,204,22,0.35); }
  .rail-link.active::before { content:''; position:absolute; left:-0.7rem; top:50%; transform:translateY(-50%); width:3px; height:22px; border-radius:0 4px 4px 0; background:var(--green); }
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

  .topbar { height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:1rem; padding:0 1.4rem; background:rgba(255,255,255,0.85); backdrop-filter:blur(12px); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:30; }
  .icon-btn { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); background:var(--surface); color:var(--ink-2); font-size:1.2rem; cursor:pointer; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), color 0.18s var(--ease); flex-shrink:0; }
  .icon-btn:hover { background:var(--surface-2); border-color:#cbd5e1; color:var(--ink); }
  .hamburger { display:none; }
  .topbar-right { margin-left:auto; display:flex; align-items:center; gap:0.6rem; }
  .avatar-chip { display:flex; align-items:center; gap:0.6rem; padding:0.3rem 0.55rem 0.3rem 0.35rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); cursor:default; }
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

  .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease), border-color 0.22s var(--ease); }
  .card-body { padding:1.3rem; }
  .stat { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-card); overflow:hidden; transition:transform 0.22s var(--ease), box-shadow 0.22s var(--ease); }
  .stat:hover { transform:translateY(-3px); box-shadow:var(--shadow-pop); }
  .stat::after { content:''; position:absolute; inset:0 0 auto 0; height:3px; background:var(--stat-accent, var(--green)); }
  .stat.a-green   { --stat-accent:linear-gradient(90deg, var(--green-light), var(--green)); }
  .stat.a-indigo  { --stat-accent:linear-gradient(90deg, #818cf8, #4f46e5); }
  .stat.a-emerald { --stat-accent:linear-gradient(90deg, #34d399, #059669); }
  .stat.a-gold    { --stat-accent:linear-gradient(90deg, #eab308, #ca8a04); }
  .stat.a-sky     { --stat-accent:linear-gradient(90deg, #38bdf8, #0284c7); }
  .stat-body { padding:1.25rem 1.3rem; display:flex; align-items:center; justify-content:space-between; }
  .stat-label { font-size:0.8rem; font-weight:500; color:var(--muted); }
  .stat-value { font-size:2rem; font-weight:700; margin-top:0.35rem; line-height:1; }
  .stat-sub { font-size:0.72rem; color:var(--faint); margin-top:0.4rem; }
  .stat-ico { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
  .ico-green   { background:var(--green-soft); color:#3f6212; }
  .ico-indigo  { background:var(--accent-soft); color:var(--accent); }
  .ico-emerald { background:var(--ok-soft); color:var(--ok); }
  .ico-gold    { background:var(--gold-soft); color:#854d0e; }
  .ico-sky     { background:var(--sky-soft); color:var(--sky); }

  .btn { display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600; padding:0.65rem 1.1rem; border-radius:var(--radius-sm); border:1px solid transparent; cursor:pointer; white-space:nowrap; transition:background 0.18s var(--ease), border-color 0.18s var(--ease), box-shadow 0.18s var(--ease), transform 0.12s var(--ease), color 0.18s var(--ease); }
  .btn:active { transform:translateY(1px); }
  .btn-primary { background:var(--accent); color:#fff; box-shadow:0 8px 18px -10px rgba(101,163,13,0.7); }
  .btn-primary:hover { background:var(--accent-700); }
  .btn-ghost { background:var(--surface); color:var(--ink-2); border-color:var(--line); }
  .btn-ghost:hover { background:var(--surface-2); border-color:#cbd5e1; }
  .btn-green { background:var(--green); color:#fff; box-shadow:0 8px 18px -10px rgba(101,163,13,0.6); }
  .btn-green:hover { background:#4d7c0f; }
  .btn-success { background:var(--ok); color:#fff; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); }
  .btn-success:hover { background:#047857; }
  .btn-hr { background:var(--sky); color:#fff; box-shadow:0 8px 18px -10px rgba(2,132,199,0.6); }
  .btn-hr:hover { background:#0369a1; }
  .view-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--green); color:#fff; padding:0.5rem 0.95rem; border-radius:var(--radius-sm); font-size:0.85rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(101,163,13,0.6); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
  .view-btn:hover { background:#4d7c0f; }
  .view-btn:active { transform:translateY(1px); }
  .view-btn:disabled { opacity:0.6; cursor:not-allowed; }
  .print-btn { display:inline-flex; align-items:center; gap:0.4rem; background:var(--ok); color:#fff; padding:0.6rem 1.1rem; border-radius:var(--radius-sm); font-size:0.85rem; font-weight:600; border:none; cursor:pointer; box-shadow:0 8px 18px -10px rgba(5,150,105,0.6); transition:background 0.18s var(--ease), transform 0.12s var(--ease); }
  .print-btn:hover { background:#047857; }
  .print-btn:active { transform:translateY(1px); }

  .department-badge { display:inline-flex; align-items:center; gap:0.3rem; background:var(--green-soft); color:#3f6212; padding:0.32rem 0.7rem; border-radius:999px; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; }
  .status-submitted { display:inline-flex; align-items:center; background:var(--ok-soft); color:var(--ok-ink); padding:0.32rem 0.75rem; border-radius:999px; font-size:0.72rem; font-weight:600; }

  .table-wrap { border:1px solid var(--line); border-radius:var(--radius); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; background:var(--surface); }
  .table-scroll { overflow-x:auto; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { background:var(--surface-2); text-align:left; font-size:0.7rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--muted); padding:0.85rem 1.15rem; border-bottom:1px solid var(--line); white-space:nowrap; }
  table.data td { padding:0.95rem 1.15rem; border-bottom:1px solid var(--line-soft); font-size:0.875rem; color:var(--ink-2); vertical-align:middle; }
  table.data tbody tr { transition:background 0.15s var(--ease); }
  table.data tbody tr:nth-child(even) { background:var(--surface-2); }
  table.data tbody tr:hover { background:var(--green-soft); }
  table.data tbody tr:last-child td { border-bottom:none; }
  .cell-name { font-weight:600; color:var(--ink); }
  .emp-avatar { width:38px; height:38px; border-radius:10px; background:var(--green-soft); color:#3f6212; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }

  .search { position:relative; flex:1 1 240px; min-width:200px; }
  .search input { width:100%; height:42px; padding:0 2.6rem 0 2.6rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.875rem; color:var(--ink); transition:border-color 0.18s var(--ease), box-shadow 0.18s var(--ease); }
  .search input::placeholder { color:var(--faint); }
  .search input:focus { outline:none; border-color:var(--green); box-shadow:0 0 0 4px rgba(101,163,13,0.12); }
  .search > i { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--faint); font-size:1.05rem; pointer-events:none; }
  .search-go { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; border-radius:8px; background:var(--green); color:#fff; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background 0.18s var(--ease); }
  .search-go:hover { background:#4d7c0f; }
  .filterbar { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; }
  .filter { position:relative; }
  .filter-btn { display:inline-flex; align-items:center; gap:0.55rem; height:42px; padding:0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; font-size:0.85rem; color:var(--ink-2); font-weight:500; transition:border-color 0.18s var(--ease), background 0.18s var(--ease); white-space:nowrap; }
  .filter-btn:hover { border-color:#cbd5e1; background:var(--surface-2); }
  .filter-btn .flt-label { color:var(--faint); font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
  .filter-btn .flt-value { color:var(--ink); font-weight:600; max-width:200px; overflow:hidden; text-overflow:ellipsis; }
  .filter-btn i.caret { color:var(--muted); transition:transform 0.18s var(--ease); }
  .filter.open .filter-btn { border-color:var(--green); }
  .filter.open .filter-btn i.caret { transform:rotate(180deg); }
  .filter-menu { position:absolute; left:0; top:calc(100% + 0.4rem); min-width:260px; max-height:320px; overflow-y:auto; background:var(--surface); border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow-pop); padding:0.4rem; z-index:50; opacity:0; transform:translateY(-6px); pointer-events:none; transition:opacity 0.16s var(--ease), transform 0.16s var(--ease); }
  .filter.open .filter-menu { opacity:1; transform:translateY(0); pointer-events:auto; }
  .filter-menu a { display:flex; align-items:center; padding:0.6rem 0.75rem; border-radius:9px; font-size:0.85rem; color:var(--ink-2); transition:background 0.14s var(--ease); }
  .filter-menu a:hover { background:var(--surface-2); }
  .status-select { height:42px; padding:0 2.4rem 0 0.9rem; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface); font-size:0.85rem; color:var(--ink-2); font-weight:500; cursor:pointer; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='16' height='16'%3E%3Cpath d='M12 15l-4.243-4.243 1.415-1.414L12 12.172l2.828-2.829 1.415 1.414z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 0.7rem center; }
  .status-select:focus { outline:none; border-color:var(--green); box-shadow:0 0 0 4px rgba(101,163,13,0.12); }

  .empty { text-align:center; padding:3rem 1rem; color:var(--faint); }
  .empty i { font-size:3.2rem; color:#cbd5e1; }
  .empty h4 { font-size:1.05rem; color:var(--muted); margin-top:0.8rem; font-weight:600; }
  .empty p { font-size:0.85rem; color:var(--faint); margin-top:0.3rem; }

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
  .modal-table tr:hover td { background:var(--green-soft); }
  .modal-table tr:last-child td { border-bottom:none; }
  .checkbox-custom { width:18px; height:18px; accent-color:var(--green); }
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

  .toast-message { position:fixed; top:1.1rem; right:1.1rem; z-index:2000; padding:0.9rem 1.1rem; border-radius:12px; color:#fff; font-weight:600; font-size:0.86rem; box-shadow:var(--shadow-pop); opacity:0; transform:translateY(-10px); transition:all 0.25s var(--ease); }
  .toast-message.show { opacity:1; transform:translateY(0); }
  .toast-success { background:linear-gradient(135deg, #10b981, #059669); }
  .toast-error { background:linear-gradient(135deg, #f43f5e, #e11d48); }
  .toast-hr { background:linear-gradient(135deg, #38bdf8, #0284c7); }

  ::-webkit-scrollbar { width:9px; height:9px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; border:2px solid transparent; background-clip:content-box; }
  ::-webkit-scrollbar-thumb:hover { background:#94a3b8; background-clip:content-box; }

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

  <!-- SIDEBAR -->
  <aside class="rail" id="rail">
    <div class="rail-brand">
      <div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">
        <img src="../images/lspu-logo.png" alt="LSPU" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-government-line text-white text-lg"></i>
        </div>
        <img src="../images/conah-logo.png" alt="CONAH" class="rail-logo" style="width:32px;height:32px;padding:3px;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="rail-logo-fallback" style="display:none;width:32px;height:32px;">
          <i class="ri-heart-pulse-line text-white text-lg"></i>
        </div>
      </div>
      <div class="rail-brand-text">
        <h1>CONAH Admin</h1>
        <p>IDP Forms</p>
      </div>
    </div>

    <nav class="rail-nav">
      <p class="rail-section-label">Overview</p>
      <a href="CONAH.php" class="rail-link">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
      </a>
      <a href="CONAH_Training_Demand.php" class="rail-link">
        <i class="ri-stack-line"></i><span>Training Demand</span>
        <?php if ($forwardedDemandCount > 0): ?><span class="rail-badge num"><?= $forwardedDemandCount ?></span><?php endif; ?>
      </a>
      <p class="rail-section-label">Forms</p>
      <a href="CONAH_Assessment Form.php" class="rail-link">
        <i class="ri-survey-line"></i><span>Assessment Form</span>
      </a>
      <a href="CONAH_Individual_Development_Plan_Form.php" class="rail-link active">
        <i class="ri-contacts-book-2-line"></i><span>IDP Forms</span>
        <i class="ri-arrow-right-s-line chev"></i>
      </a>
      <a href="conah_eval.php" class="rail-link">
        <i class="ri-file-search-line"></i><span>Evaluation</span>
      </a>
    </nav>

    <div class="rail-foot">
      <a href="?logout=true" class="rail-signout">
        <i class="ri-logout-box-line"></i><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- MAIN COLUMN -->
  <div class="app-main">
    <header class="topbar">
      <button class="icon-btn hamburger" onclick="openMobileRail()" aria-label="Open menu"><i class="ri-menu-line"></i></button>
      <button class="icon-btn" onclick="toggleRailCollapse()" aria-label="Collapse sidebar" id="collapseBtn" style="display:none;"><i class="ri-side-bar-line"></i></button>
      <div class="topbar-right">
        <div class="date-chip"><i class="ri-calendar-2-line"></i><span><?php echo date('F j, Y'); ?></span></div>
        <div class="avatar-chip" aria-label="Account">
          <span class="avatar"><?= htmlspecialchars($initials) ?></span>
          <span class="avatar-meta"><span class="nm"><?= htmlspecialchars($adminName) ?></span> <span class="rl">CONAH Admin</span></span>
        </div>
      </div>
    </header>

    <div class="content-scroll">
      <div class="content">
        <div class="page-head">
          <div>
            <p class="eyebrow">Individual Development Plans · CONAH</p>
            <h2>CONAH IDP Submissions</h2>
            <p>View and manage IDP forms submitted by College of Nursing and Allied Health faculty and staff.</p>
          </div>
        </div>

        <div class="grid-stats">
          <div class="stat a-indigo">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total CONAH Faculty</p>
                <p class="stat-value num"><?php echo $conahStats['total_employees']; ?></p>
                <p class="stat-sub">eligible for IDP</p>
              </div>
              <div class="stat-ico ico-indigo"><i class="ri-team-line"></i></div>
            </div>
          </div>
          <div class="stat a-green">
            <div class="stat-body">
              <div>
                <p class="stat-label">With IDP Submitted</p>
                <p class="stat-value num"><?php echo $conahStats['with_idp']; ?></p>
                <p class="stat-sub"><?php echo $conahStats['total_employees'] > 0 ? round(($conahStats['with_idp'] / $conahStats['total_employees']) * 100, 1) : 0; ?>% of faculty</p>
              </div>
              <div class="stat-ico ico-green"><i class="ri-file-list-3-line"></i></div>
            </div>
          </div>
          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Submissions</p>
                <p class="stat-value num"><?php echo $total_submissions; ?></p>
                <p class="stat-sub">all IDP forms submitted</p>
              </div>
              <div class="stat-ico ico-emerald"><i class="ri-file-copy-line"></i></div>
            </div>
          </div>
          <div class="stat a-sky">
            <div class="stat-body">
              <div>
                <p class="stat-label">Submitted to HR</p>
                <p class="stat-value num"><?php echo $conahStats['submitted_to_hr']; ?></p>
                <p class="stat-sub">IDPs forwarded to HR</p>
              </div>
              <div class="stat-ico ico-sky"><i class="ri-send-plane-line"></i></div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-bottom:1.4rem; overflow:visible; position:relative; z-index:20;">
          <div class="card-body" style="padding:1.05rem 1.15rem;">
            <div class="filterbar">
              <div style="display:inline-flex;align-items:center;gap:0.55rem;height:42px;padding:0 0.9rem;border:1px solid var(--green);border-radius:var(--radius-sm);background:var(--green-soft);">
                <i class="ri-building-line" style="color:#3f6212;"></i>
                <span style="font-weight:600;color:#3f6212;font-size:0.85rem;">CONAH</span>
                <span style="font-size:0.7rem;color:#3f6212;opacity:0.7;">College of Nursing & Allied Health</span>
              </div>

              <select class="status-select" aria-label="Filter by status">
                <option>All Status</option>
                <option selected>Submitted</option>
              </select>

              <div class="search">
                <i class="ri-search-line"></i>
                <input type="text" id="search-input" placeholder="Search by employee name…" value="" />
                <button type="button" class="search-go" onclick="performSearch()" aria-label="Search"><i class="ri-arrow-right-line"></i></button>
              </div>
            </div>
          </div>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.8rem;">
          <p style="font-size:0.82rem; color:var(--muted);">
            Showing <span class="num"><?= count($employees) > 0 ? 1 : 0 ?></span>–<span class="num"><?= count($employees) ?></span>
            of <span class="num"><?= count($employees) ?></span> CONAH employees with submitted IDPs
          </p>
        </div>

        <div class="table-wrap">
          <div class="table-scroll">
            <table class="data">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Position</th>
                  <th>Department</th>
                  <th>IDPs Submitted</th>
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
                        <h4>No IDP submissions found for CONAH</h4>
                        <p>CONAH faculty and staff will appear here once they submit their IDP forms.</p>
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
                      <td><span class="num" style="font-weight:700; color:var(--green); font-size:1.05rem;"><?= (int) $employee['total_idps'] ?></span></td>
                      <td class="num" style="color:var(--muted);">
                        <?php echo !empty($employee['last_submission']) ? date('M j, Y', strtotime($employee['last_submission'])) : 'N/A'; ?>
                      </td>
                      <td>
                        <button class="view-btn view-idp-btn"
                                data-user-id="<?= (int) $employee['user_id'] ?>"
                                data-user-name="<?= htmlspecialchars($employee['name']) ?>">
                          <i class="ri-eye-line"></i> View
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL -->
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
     PAGE LOGIC
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
    toast.className = `toast-message ${type === 'success' ? 'toast-success' : type === 'hr' ? 'toast-hr' : 'toast-error'}`;
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

        if (!file) return;

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
        submitBtn.innerHTML = `<i class="ri-loader-4-line mr-2 animate-spin"></i> Submitting to HR...`;

        fetch(currentPageUrl, {
          method: 'POST',
          body: fd
        })
        .then(response => response.json())
        .then(result => {
          if (result.success) {
            showToast(result.message, 'hr');
            showIDPModal(userId, userName, formId);
          } else {
            showToast(result.message || 'Failed to submit to HR.', 'error');
          }
        })
        .catch(() => {
          showToast('An error occurred while submitting to HR.', 'error');
        })
        .finally(() => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = `<i class="ri-send-plane-line mr-2"></i> Submit to HR`;
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
          <h4>No IDP forms found</h4>
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
              <span class="status-submitted">Submitted</span>
              <i class="accordion-arrow ri-arrow-down-s-line transition-transform duration-300"></i>
            </div>
          </button>

          <div class="accordion-content" id="content-${form.id}" style="padding:0 1.25rem;">
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

            <div class="form-section">
              <h4 class="form-section-title">Training/Development Interventions for Long Term Goals (Next Five Years)</h4>
              <div class="table-responsive">
                <table class="modal-table">
                  <thead>
                    <tr><th>Area of Development</th><th>Development Activity</th><th>Target Completion Date</th><th>Completion Stage</th></tr>
                  </thead>
                  <tbody>${buildLongTermRows(longTermGoals)}</tbody>
                </table>
              </div>
            </div>

            <div class="form-section">
              <h4 class="form-section-title">Short Term Development Goals Next Year</h4>
              <div class="table-responsive">
                <table class="modal-table">
                  <thead>
                    <tr><th>Area of Development</th><th>Priority for LDP</th><th>Development Activity</th><th>Target Completion Date</th><th>Who is Responsible</th><th>Completion Stage</th></tr>
                  </thead>
                  <tbody>${buildShortTermRows(shortTermGoals)}</tbody>
                </table>
              </div>
            </div>

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

              <div class="mt-4 p-4 bg-sky-soft rounded-lg border border-sky" style="background:#f0f9ff; border-color:#38bdf8;">
                <p class="text-sm" style="color:#0369a1;">
                  <i class="ri-information-line mr-2"></i>
                  Upload the required signatures below and click <strong>"Submit to HR"</strong> to forward this IDP to the HR department for final processing.
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
                      class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-green file:text-white hover:file:bg-[#4d7c0f] cursor-pointer signature-file-input"
                      data-preview-id="supervisor-preview-${form.id}"
                      data-empty-id="supervisor-empty-${form.id}"
                    >
                    <p class="text-xs mt-2" style="color:var(--muted);">Allowed: PNG, JPG, JPEG, WEBP</p>
                    <div class="signature-preview">
                      <img id="supervisor-preview-${form.id}" src="${certification.supervisor_signature ? escapeHtml(certification.supervisor_signature) : ''}" alt="Supervisor Signature Preview" class="${certification.supervisor_signature ? '' : 'hidden-preview'}">
                      <div id="supervisor-empty-${form.id}" class="${certification.supervisor_signature ? 'hidden-preview' : ''} text-sm italic" style="color:var(--faint);">No supervisor signature uploaded yet</div>
                    </div>
                  </div>

                  <div class="signature-upload-card">
                    <label class="block text-sm font-semibold mb-2" style="color:var(--ink-2);">Upload Director Signature</label>
                    <input
                      type="file"
                      name="director_signature_file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-green file:text-white hover:file:bg-[#4d7c0f] cursor-pointer signature-file-input"
                      data-preview-id="director-preview-${form.id}"
                      data-empty-id="director-empty-${form.id}"
                    >
                    <p class="text-xs mt-2" style="color:var(--muted);">Allowed: PNG, JPG, JPEG, WEBP</p>
                    <div class="signature-preview">
                      <img id="director-preview-${form.id}" src="${certification.director_signature ? escapeHtml(certification.director_signature) : ''}" alt="Director Signature Preview" class="${certification.director_signature ? '' : 'hidden-preview'}">
                      <div id="director-empty-${form.id}" class="${certification.director_signature ? 'hidden-preview' : ''} text-sm italic" style="color:var(--faint);">No director signature uploaded yet</div>
                    </div>
                  </div>
                </div>

                <div class="mt-4 flex items-center justify-between flex-wrap gap-4">
                  <p class="text-sm" style="color:var(--muted);">Employee signature is display-only. Upload the Supervisor and Director signatures above.</p>
                  <button type="submit" class="view-btn save-signatures-btn" style="background:var(--sky); box-shadow:0 8px 18px -10px rgba(2,132,199,0.6);">
                    <i class="ri-send-plane-line mr-2"></i> Submit to HR
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
      if (targetBtn) targetBtn.click();
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
        <div style="width:36px;height:36px;border:3px solid var(--green-soft);border-top-color:var(--green);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
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

    const searchInput = document.getElementById('search-input');
    const formsTableBody = document.getElementById('forms-table-body');
    const viewButtons = document.querySelectorAll('.view-idp-btn');
    const modal = document.getElementById('view-idp-modal');
    const closeButtons = document.querySelectorAll('.close-modal-btn');
    const modalPrintBtn = document.getElementById('print-idp-btn');

    function filterEmployees() {
      const searchTerm = searchInput.value.toLowerCase();
      const rows = formsTableBody.querySelectorAll('.employee-item');
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = !searchTerm || text.includes(searchTerm) ? '' : 'none';
      });
    }

    window.performSearch = filterEmployees;

    searchInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') filterEmployees();
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