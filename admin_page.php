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
require_once 'ml_recommendations.php'; // canonicalTnaCollegeCode() - see the department-filter dropdown below

/* -----------------------------------------------------------------------------
   DEBUG — turn OFF in production (leaks stack traces to the browser otherwise).
   ----------------------------------------------------------------------------- */
error_reporting(E_ALL);
// ISO 25010 Security audit (2026-09-06) - this was hardcoded to 1
// unconditionally, overriding config.php's environment-aware setting
// right below the comment saying to turn it off in production. Deferring
// to config.php's own $is_local detection instead of a second, separately-
// maintained copy of the same decision.
ini_set('display_errors', $is_local ? 1 : 0);

/* -----------------------------------------------------------------------------
   USER STATUS — self-heal 'disabled' onto the enum (2026-09-06).
   Previously only pending/accepted/declined existed, so User Management's
   "disable an already-accepted account" action had to reuse 'declined' -
   the exact same status a brand-new registration gets when HR rejects it.
   That conflated two different real situations (a signup that was never
   approved vs. a working account HR is turning off) into one bucket and
   one login-page message ("your registration was declined"), which reads
   wrong for someone who'd been using the system for months. Adding a
   distinct 'disabled' value costs nothing for existing rows - MODIFYing
   an ENUM's allowed values doesn't touch any row's current value.
   ----------------------------------------------------------------------------- */
function ensureUserDisabledStatus($con) {
    $col = $con->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch_assoc();
    if ($col && strpos($col['Type'], "'disabled'") === false) {
        $con->query("ALTER TABLE users MODIFY status ENUM('pending','accepted','declined','disabled') DEFAULT 'pending'");
    }
}
ensureUserDisabledStatus($con);

/* -----------------------------------------------------------------------------
   EMAIL CONFIG
   ISO 25010 Security audit (2026-09-06) - this used to hardcode a live
   Gmail App Password directly in this tracked file (with its own
   "ACTION REQUIRED - this has been exposed, rotate it" comment that had
   been sitting here unactioned). The actual values now live in
   db_credentials.php (gitignored, already loaded by config.php above) -
   this just maps them to the names the PHPMailer calls below already use.
   Still true regardless of where the value lives: rotate this app
   password in Google Account -> Security -> App Passwords, since it was
   exposed in source for a while before this move.
   ----------------------------------------------------------------------------- */
$SMTP_HOST      = $smtp_host;
$SMTP_USER      = $smtp_user;
$SMTP_PASS      = $smtp_pass;
$SMTP_FROM_NAME = $smtp_from_name;
$SMTP_PORT      = $smtp_port;
$SMTP_SECURE    = $smtp_secure;

/* -----------------------------------------------------------------------------
   DEPARTMENTS — canonical code → full-name map used across stats + tables.
   ----------------------------------------------------------------------------- */
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
    'CTE'   => 'College of Teacher Education',
    // Not a college - the central non-teaching office bucket (2026-09-02
    // fix). Without this, every non-teaching employee whose department is
    // 'ADMIN' was silently invisible from the per-department breakdown,
    // the chart, and the Submission Status totals below - a real gap, not
    // a deliberate scope limit, since ADMIN staff still submit TNA forms
    // like everyone else. Matches the 'ADMIN' bucket already used by
    // canonicalTnaCollegeCode() in ml_recommendations.php.
    'ADMIN' => 'Main Administrative Office'
];

// 2026-09-02 - the profile/registration forms now offer non-teaching staff
// a real dropdown of specific offices (previously free text) instead of
// forcing everyone into a blended "ADMIN" value. The literal office name
// is what's actually stored in users.department (better data quality/UX),
// but for THIS dashboard's stats it should still roll up into the single
// "Main Administrative Office" row above - same bucket as any legacy
// literal 'ADMIN' account - rather than each office silently vanishing
// from every query below the way a brand-new unrecognized department
// value would (the exact bug the 'ADMIN' key above was added to fix).
// Kept in sync with the alias list in ml_recommendations.php's
// TNA_COLLEGE_CODE_MAP and trainwise-ml's features.py DEPARTMENT_CODE_MAP.
// 2026-09-07 - the original 5 were a placeholder list; real 13 given
// directly by HR (Dr. Imee Prescilla P. Sanchez, HRMO) added below. The
// original 5 are kept, not removed - any account registered before this
// date still has one of those exact values stored in users.department,
// and dropping them here would silently reclassify those real accounts
// out of the ADMIN bucket everywhere this array is used.
$nonTeachingOfficeAliases = [
    "Registrar's Office",
    "Accounting/Budget Office",
    "Human Resource Management Office (HRMO)",
    "Supply/Property Office",
    "Library",
    "Office of the Campus Director",
    "Guidance Counselor",
    "Disbursing and Cashiering",
    "Records Office",
    "General Services Unit",
    "Library Services",
    "Supply",
    "Admission and Registrarship",
    "Accounting Office",
    "Budget and Finance",
    "Human Resource Management",
    "Medical and Dental Services",
    "Procurement",
];

/* =============================================================================
   ACCESS CONTROL
   ============================================================================= */

// Must be logged in.
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Account must be in 'accepted' status, otherwise destroy session + bounce.
$checkStatus = $con->prepare("SELECT status FROM users WHERE id = ?");
$checkStatus->bind_param("i", $_SESSION['user_id']);
$checkStatus->execute();
$statusResult = $checkStatus->get_result();
$userData = $statusResult->fetch_assoc();
$checkStatus->close();

if (!$userData || $userData['status'] !== 'accepted') {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Only the pure 'admin' role may view this page. Department admins (admin_*)
// and regular users are routed to their own dashboards.
$userRole = $_SESSION['user_role'] ?? '';

if ($userRole !== 'admin') {
    // Both branches used to point at files that never existed
    // (department_admin_page.php / user_dashboard.php) - anyone landing
    // here via a direct URL/bookmark hit a raw 404 instead of being sent
    // back to a real page. Route through index.php's own role-based
    // dispatch (login_process.php) instead of duplicating that logic here.
    header("Location: index.php");
    exit();
}

/* =============================================================================
   MAILER BOOTSTRAP
   ============================================================================= */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}

/* =============================================================================
   POST HANDLERS
   ============================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------------------------------------------------------------------
       1) CREATE / UPDATE DEADLINE  (+ fan out notifications)
       --------------------------------------------------------------------- */
    if (isset($_POST['update_deadline'])) {
        $newDeadline = $_POST['deadline'];
        $title       = $_POST['title'] ?? 'Training Needs Assessment Deadline';
        $description = $_POST['description'] ?? '';
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($newDeadline)) {
            $_SESSION['deadline_message'] = "Please enter a valid deadline.";
            $_SESSION['message_type'] = "error";
            header("Location: admin_page.php");
            exit();
        }

        $formattedDeadline = date('Y-m-d H:i:s', strtotime($newDeadline));

        // Only one deadline can be active at a time.
        if ($isActive) {
            $con->query("UPDATE settings SET is_active = 0");
        }

        $stmt = $con->prepare("INSERT INTO settings (submission_deadline, title, description, is_active, created_at, updated_at, allow_submissions) 
                              VALUES (?, ?, ?, ?, NOW(), NOW(), 1)");
        $stmt->bind_param("sssi", $formattedDeadline, $title, $description, $isActive);

        if ($stmt->execute()) {
            $deadlineId = $stmt->insert_id;
            $_SESSION['deadline_message'] = "New deadline added successfully!";
            $_SESSION['message_type'] = "success";

            $notificationMessage = "A new submission deadline has been set for the Training Needs Assessment form. Please complete your assessment before " .
                                 date('F j, Y g:i A', strtotime($formattedDeadline)) . ".";

            $usersQuery = $con->query("SELECT id, email, name FROM users WHERE status = 'accepted'");

            $emailsSent     = 0;
            $dbNotifications = 0;
            $errors         = [];

            // Preferred path: PHPMailer is available → email + DB notification.
            if (class_exists('PHPMailer')) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $SMTP_USER;
                    $mail->Password   = $SMTP_PASS;
                    $mail->SMTPSecure = $SMTP_SECURE;
                    $mail->Port       = $SMTP_PORT;

                    $mail->setFrom($SMTP_USER, $SMTP_FROM_NAME);
                    $mail->Subject = "New Training Needs Assessment Deadline";
                    $mail->isHTML(true);
                    $mail->Body    = $notificationMessage;
                    $mail->AltBody = strip_tags($notificationMessage);

                    while ($user = $usersQuery->fetch_assoc()) {
                        try {
                            $mail->clearAddresses();
                            $mail->addAddress($user['email'], $user['name']);

                            if ($mail->send()) {
                                $emailsSent++;
                            }

                            $notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type) 
                                                        VALUES (?, ?, ?, 'deadline')");
                            $notifStmt->bind_param("isi", $user['id'], $notificationMessage, $deadlineId);
                            if ($notifStmt->execute()) {
                                $dbNotifications++;

                                $updateStmt = $con->prepare("UPDATE users SET has_notification = TRUE WHERE id = ?");
                                $updateStmt->bind_param("i", $user['id']);
                                $updateStmt->execute();
                                $updateStmt->close();
                            }
                            $notifStmt->close();

                        } catch (Exception $e) {
                            $errors[] = "Error sending to {$user['email']}: " . $e->getMessage();
                        }
                    }

                    $_SESSION['deadline_message'] .= " Notifications sent to $dbNotifications users.";
                } catch (Exception $e) {
                    $_SESSION['deadline_message'] .= " Error setting up mailer: " . $e->getMessage();
                    $_SESSION['message_type'] = "warning";
                }
            } else {
                // Fallback path: no mailer → DB notification only.
                while ($user = $usersQuery->fetch_assoc()) {
                    $notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type) 
                                                VALUES (?, ?, ?, 'deadline')");
                    $notifStmt->bind_param("isi", $user['id'], $notificationMessage, $deadlineId);
                    if ($notifStmt->execute()) {
                        $dbNotifications++;

                        $updateStmt = $con->prepare("UPDATE users SET has_notification = TRUE WHERE id = ?");
                        $updateStmt->bind_param("i", $user['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                    $notifStmt->close();
                }
                $_SESSION['deadline_message'] .= " Notifications created for $dbNotifications users.";
            }
        } else {
            $_SESSION['deadline_message'] = "Failed to add new deadline.";
            $_SESSION['message_type'] = "error";
        }

        $stmt->close();
        header("Location: admin_page.php?updated=" . time());
        exit();
    }

    /* ---------------------------------------------------------------------
       2) SET A DEADLINE AS ACTIVE
       --------------------------------------------------------------------- */
    elseif (isset($_POST['set_active'])) {
        $deadlineId = intval($_POST['deadline_id']);

        $con->query("UPDATE settings SET is_active = 0");

        $stmt = $con->prepare("UPDATE settings SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $deadlineId);

        if ($stmt->execute()) {
            $_SESSION['deadline_message'] = "Active deadline updated successfully!";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['deadline_message'] = "Failed to update active deadline.";
            $_SESSION['message_type'] = "error";
        }

        $stmt->close();
        header("Location: admin_page.php");
        exit();
    }

    /* ---------------------------------------------------------------------
       3) OPEN / CLOSE SUBMISSIONS FOR A DEADLINE
       --------------------------------------------------------------------- */
    elseif (isset($_POST['toggle_submissions'])) {
        $deadlineId    = intval($_POST['deadline_id']);
        $currentStatus = intval($_POST['current_status']);
        $newStatus     = $currentStatus ? 0 : 1;

        $stmt = $con->prepare("UPDATE settings SET allow_submissions = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $deadlineId);

        if ($stmt->execute()) {
            $_SESSION['deadline_message'] = "Submissions are now " . ($newStatus ? "OPEN" : "CLOSED") . " for this deadline.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['deadline_message'] = "Failed to update submission status.";
            $_SESSION['message_type'] = "error";
        }

        $stmt->close();
        header("Location: admin_page.php");
        exit();
    }

    /* ---------------------------------------------------------------------
       4) USER ACTIONS — accept / decline / delete
       --------------------------------------------------------------------- */
    elseif (isset($_POST['user_id'], $_POST['action'])) {
        $user_id = intval($_POST['user_id']);
        $action  = $_POST['action'];

        $stmt = $con->prepare("SELECT name, email, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $_SESSION['userActionMessage'] = "User not found.";
            $_SESSION['userActionType'] = "error";
            header("Location: admin_page.php");
            exit();
        }

        // Guard: never let an admin delete their own account.
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['userActionMessage'] = "You cannot delete your own account!";
            $_SESSION['userActionType'] = "error";
            header("Location: admin_page.php");
            exit();
        }

        if ($action === 'accept') {
            $stmt = $con->prepare("UPDATE users SET status = 'accepted' WHERE id = ?");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $_SESSION['userActionMessage'] = "User " . htmlspecialchars($user['name']) . " has been accepted successfully.";
                $_SESSION['userActionType'] = "success";

                // Best-effort approval email (failures are swallowed silently).
                if (class_exists('PHPMailer')) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host       = $SMTP_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $SMTP_USER;
                        $mail->Password   = $SMTP_PASS;
                        $mail->SMTPSecure = $SMTP_SECURE;
                        $mail->Port       = $SMTP_PORT;

                        $mail->setFrom($SMTP_USER, $SMTP_FROM_NAME);
                        $mail->addAddress($user['email'], $user['name']);

                        $mail->isHTML(true);
                        $mail->Subject = "LSPU Registration Approved";
                        $mail->Body    = "Hello " . htmlspecialchars($user['name']) . ",<br><br>Your registration has been approved. You can now access the Training Needs Assessment system using your email address.<br><br>Thank you,<br>LSPU Admin";

                        $mail->send();
                        $_SESSION['userActionMessage'] .= " Email sent to {$user['email']}.";
                    } catch (Exception $e) {}
                }
            } else {
                $_SESSION['userActionMessage'] = "Failed to accept user: " . $con->error;
                $_SESSION['userActionType'] = "error";
            }
            $stmt->close();

        } elseif ($action === 'decline') {
            $stmt = $con->prepare("UPDATE users SET status = 'declined' WHERE id = ?");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $_SESSION['userActionMessage'] = "User " . htmlspecialchars($user['name']) . " has been declined.";
                $_SESSION['userActionType'] = "success";
            } else {
                $_SESSION['userActionMessage'] = "Failed to decline user: " . $con->error;
                $_SESSION['userActionType'] = "error";
            }
            $stmt->close();

        } elseif ($action === 'disable') {
            // Distinct from 'decline' (see ensureUserDisabledStatus() above) -
            // this is for turning off an account that was ALREADY accepted
            // and working, not rejecting a brand-new registration. Kept as
            // its own status so the login page can tell someone the true
            // reason ("your account was disabled") instead of the
            // registration-rejection wording, which would be wrong here.
            $stmt = $con->prepare("UPDATE users SET status = 'disabled' WHERE id = ?");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $_SESSION['userActionMessage'] = "User " . htmlspecialchars($user['name']) . " has been disabled.";
                $_SESSION['userActionType'] = "success";
            } else {
                $_SESSION['userActionMessage'] = "Failed to disable user: " . $con->error;
                $_SESSION['userActionType'] = "error";
            }
            $stmt->close();

        } elseif ($action === 'delete') {
            // Permanent delete.
            $stmt = $con->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $_SESSION['userActionMessage'] = "User " . htmlspecialchars($user['name']) . " has been permanently deleted.";
                $_SESSION['userActionType'] = "success";
            } else {
                $_SESSION['userActionMessage'] = "Failed to delete user: " . $con->error;
                $_SESSION['userActionType'] = "error";
            }
            $stmt->close();
        }

        header("Location: admin_page.php");
        exit();
    }
}

/* =============================================================================
   DATA FETCH — everything the view renders below
   ============================================================================= */

// Active deadline + derived display fields.
$activeDeadline     = $con->query("SELECT * FROM settings WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$submissionDeadline = $activeDeadline['submission_deadline'] ?? null;
$deadlineTitle      = $activeDeadline['title'] ?? 'Training Needs Assessment Deadline';
$deadlineDescription = $activeDeadline['description'] ?? '';
$allowSubmissions   = (int)($activeDeadline['allow_submissions'] ?? 0);
$submissionStatus   = $allowSubmissions ? 'OPEN' : 'CLOSED';

// Lists.
$deadlines         = $con->query("SELECT * FROM settings ORDER BY submission_deadline DESC");
$pendingUsers      = $con->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC");
// Was a raw string-concatenated $_SESSION['user_id'] - not actually
// exploitable (that value is never attacker-controlled, only ever set
// server-side at login), but parameterized for consistency with every
// other query in this file, found during the 2026-09-06 audit.
$acceptedStmt = $con->prepare("SELECT * FROM users WHERE status = 'accepted' AND id != ? ORDER BY created_at DESC");
$acceptedStmt->bind_param("i", $_SESSION['user_id']);
$acceptedStmt->execute();
$acceptedUsersList = $acceptedStmt->get_result();
$declinedUsersList = $con->query("SELECT * FROM users WHERE status = 'declined' ORDER BY created_at DESC");
$disabledUsersList = $con->query("SELECT * FROM users WHERE status = 'disabled' ORDER BY created_at DESC");

// Counts.
$acceptedUsers = $con->query("SELECT COUNT(*) as count FROM users WHERE status = 'accepted'")->fetch_assoc();
$totalAccepted = $acceptedUsers['count'] ?? 0;

$declinedUsers = $con->query("SELECT COUNT(*) as count FROM users WHERE status = 'declined'")->fetch_assoc();
$totalDeclined = $declinedUsers['count'] ?? 0;

$disabledUsers = $con->query("SELECT COUNT(*) as count FROM users WHERE status = 'disabled'")->fetch_assoc();
$totalDisabled = $disabledUsers['count'] ?? 0;

// Department filter dropdown (2026-09-06) - grouped Teaching/Non-Teaching.
// 2026-09-06 fix - the first version of this used its own hand-typed
// non-teaching-office list, separate from canonicalTnaCollegeCode()'s
// (the one real classification this app already maintains). Real values
// like "Main Admin"/"Main Administrative Office" (System Admin accounts'
// department field, not a college at all) weren't in that hand-typed
// list, so they silently fell into "Teaching" by default - wrong, and
// exactly the kind of drift reusing the existing function avoids.
// Anything canonicalTnaCollegeCode() genuinely doesn't recognize goes in
// its own "Other" group instead of being force-guessed into either side.
$teachingDeptOptions = [];
$nonTeachingDeptOptions = [];
$otherDeptOptions = [];
$deptFilterResult = $con->query("SELECT DISTINCT department FROM users WHERE status IN ('accepted','declined','disabled') AND department IS NOT NULL AND department != ''");
if ($deptFilterResult) {
    while ($row = $deptFilterResult->fetch_assoc()) {
        $label = deptLabel($row['department'], $departments);
        $code = canonicalTnaCollegeCode($row['department']);
        if ($code === null) {
            $otherDeptOptions[$label] = true;
        } elseif ($code === 'ADMIN') {
            $nonTeachingDeptOptions[$label] = true;
        } else {
            $teachingDeptOptions[$label] = true;
        }
    }
}
$teachingDeptOptions = array_keys($teachingDeptOptions);
$nonTeachingDeptOptions = array_keys($nonTeachingDeptOptions);
$otherDeptOptions = array_keys($otherDeptOptions);
sort($teachingDeptOptions);
sort($nonTeachingDeptOptions);
sort($otherDeptOptions);

$totalUsersQuery = $con->query("SELECT COUNT(*) AS total FROM users WHERE status = 'accepted'");
$totalUsersRow   = $totalUsersQuery->fetch_assoc();
$totalUsers      = $totalUsersRow['total'] ?? 0;

$pendingCount = $pendingUsers ? $pendingUsers->num_rows : 0;
if ($pendingUsers) {
    $pendingUsers->data_seek(0);
}

// Teaching vs non-teaching headcount.
// 2026-09-02 fix - two real bugs found during a deep audit: (1) this
// compared against 'non teaching' (a space) when the actual stored value
// is 'Non-teaching' (a hyphen) - a literal string that could never match,
// so Non-Teaching Staff always read 0 no matter how many real accounts
// existed. (2) no `role = 'user'` filter, so any admin/dean account that
// happened to have a stray teaching_status value (found: 2 real accounts)
// got counted as employee headcount. Both fixed; case-insensitive
// collation was already saving the 'Teaching' match, so that half looked
// fine even though it was written the same fragile way.
$teachingStats = $con->query("SELECT
    SUM(CASE WHEN teaching_status = 'Teaching' THEN 1 ELSE 0 END) as teaching_total,
    SUM(CASE WHEN teaching_status = 'Non-teaching' THEN 1 ELSE 0 END) as non_teaching_total
    FROM users WHERE status = 'accepted' AND role = 'user'")->fetch_assoc();
$teachingTotal    = $teachingStats['teaching_total'] ?? 0;
$nonTeachingTotal = $teachingStats['non_teaching_total'] ?? 0;

/* -----------------------------------------------------------------------------
   PER-DEPARTMENT SUBMISSION BREAKDOWN (on-time / late / no-submission)
   ----------------------------------------------------------------------------- */
$onTime           = array_fill_keys(array_keys($departments), 0);
$late             = array_fill_keys(array_keys($departments), 0);
$noSubmission     = array_fill_keys(array_keys($departments), 0);
$departmentTotals = array_fill_keys(array_keys($departments), 0);

if ($submissionDeadline && isset($activeDeadline['id'])) {
    // These two queries build their IN(...) list by raw string
    // interpolation (pre-existing convention in this file - college codes
    // never needed escaping). $nonTeachingOfficeAliases includes
    // "Registrar's Office", whose apostrophe WOULD break that string
    // interpolation unescaped, so escape the merged list once here.
    $deptStatsInList = array_map(
        fn($d) => $con->real_escape_string($d),
        array_merge(array_keys($departments), $nonTeachingOfficeAliases)
    );

    // Total accepted users per department.
    // role = 'user' added 2026-09-02 - without it, any dean/admin account
    // whose department happens to exactly match a college code (short-code
    // departments aren't unique to employees) got silently counted as
    // TNA-tracked headcount, and - worse - as a "No Submission" defaulter,
    // since deans/admins never submit a TNA form in the first place.
    $deptTotalQuery = $con->query("
        SELECT department, COUNT(*) as total
        FROM users
        WHERE status = 'accepted'
        AND role = 'user'
        AND department IN ('" . implode("','", $deptStatsInList) . "')
        GROUP BY department
    ");

    while ($row = $deptTotalQuery->fetch_assoc()) {
        // Roll a specific non-teaching office up into the ADMIN bucket -
        // see $nonTeachingOfficeAliases above.
        $dept = in_array($row['department'], $nonTeachingOfficeAliases, true) ? 'ADMIN' : $row['department'];
        if (array_key_exists($dept, $departments)) {
            $departmentTotals[$dept] += (int)$row['total'];
        }
    }

    // Submission status per user, bucketed by department.
    $sql = "
        SELECT 
            u.department,
            CASE 
                WHEN a.id IS NULL THEN 'No Submission'
                WHEN a.submission_date <= ? THEN 'On Time'
                ELSE 'Late'
            END AS submission_status,
            COUNT(DISTINCT u.id) AS count
        FROM 
            users u
        LEFT JOIN 
            assessments a ON u.id = a.user_id AND a.deadline_id = ?
        WHERE u.department IN ('" . implode("','", $deptStatsInList) . "')
        AND u.status = 'accepted'
        AND u.role = 'user'
        GROUP BY
            u.department, submission_status
    ";

    $stmt = $con->prepare($sql);
    $stmt->bind_param("si", $submissionDeadline, $activeDeadline['id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Roll a specific non-teaching office up into the ADMIN bucket -
            // see $nonTeachingOfficeAliases above. +=, not =: with the office
            // aliases folded in, more than one raw department value can now
            // land on the same bucket for a given submission_status.
            $dept = in_array($row['department'], $nonTeachingOfficeAliases, true) ? 'ADMIN' : $row['department'];
            if (array_key_exists($dept, $departments)) {
                switch ($row['submission_status']) {
                    case 'On Time':      $onTime[$dept]       += (int)$row['count']; break;
                    case 'Late':         $late[$dept]         += (int)$row['count']; break;
                    case 'No Submission':$noSubmission[$dept] += (int)$row['count']; break;
                }
            }
        }
    }
    $stmt->close();

    $onTimeCount       = array_sum($onTime);
    $lateCount         = array_sum($late);
    $noSubmissionCount = array_sum($noSubmission);
} else {
    $onTimeCount       = 0;
    $lateCount         = 0;
    $noSubmissionCount = 0;
}

/* -----------------------------------------------------------------------------
   TEACHING / NON-TEACHING SUBMISSION SPLIT
   ----------------------------------------------------------------------------- */
$teachingOnTime = $teachingLate = $teachingNoSubmission = 0;
$nonTeachingOnTime = $nonTeachingLate = $nonTeachingNoSubmission = 0;

if ($submissionDeadline && isset($activeDeadline['id'])) {

    // --- Teaching ---
    // 2026-09-02 fix: this block recomputes $teachingTotal/$nonTeachingTotal,
    // silently overwriting the (now-fixed) values from the combined query
    // above with its own copy of the exact same two bugs - 'non teaching'
    // (space) could never match the real stored 'Non-teaching' (hyphen),
    // and no role='user' filter let admin/dean accounts with a stray
    // teaching_status value count as employee headcount. Fixed here too,
    // and added role='user' to every sub-query below for the same reason.
    $teachingTotal = $con->query("SELECT COUNT(*) AS total FROM users WHERE teaching_status = 'Teaching' AND status = 'accepted' AND role = 'user'")->fetch_assoc()['total'] ?? 0;

    $stmt = $con->prepare("SELECT COUNT(DISTINCT u.id) AS count
                           FROM assessments a
                           JOIN users u ON a.user_id = u.id
                           WHERE u.teaching_status = 'Teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND a.submission_date <= ?
                           AND a.deadline_id = ?");
    $stmt->bind_param("si", $submissionDeadline, $activeDeadline['id']);
    $stmt->execute();
    $teachingOnTime = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $stmt = $con->prepare("SELECT COUNT(DISTINCT u.id) AS count
                           FROM assessments a
                           JOIN users u ON a.user_id = u.id
                           WHERE u.teaching_status = 'Teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND a.submission_date > ?
                           AND a.deadline_id = ?");
    $stmt->bind_param("si", $submissionDeadline, $activeDeadline['id']);
    $stmt->execute();
    $teachingLate = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $stmt = $con->prepare("SELECT COUNT(*) AS count
                           FROM users u
                           WHERE u.teaching_status = 'Teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND NOT EXISTS (
                               SELECT 1 FROM assessments a WHERE a.user_id = u.id AND a.deadline_id = ?
                           )");
    $stmt->bind_param("i", $activeDeadline['id']);
    $stmt->execute();
    $teachingNoSubmission = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // --- Non-teaching ---
    $nonTeachingTotal = $con->query("SELECT COUNT(*) AS total FROM users WHERE teaching_status = 'Non-teaching' AND status = 'accepted' AND role = 'user'")->fetch_assoc()['total'] ?? 0;

    $stmt = $con->prepare("SELECT COUNT(DISTINCT u.id) AS count
                           FROM assessments a
                           JOIN users u ON a.user_id = u.id
                           WHERE u.teaching_status = 'Non-teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND a.submission_date <= ?
                           AND a.deadline_id = ?");
    $stmt->bind_param("si", $submissionDeadline, $activeDeadline['id']);
    $stmt->execute();
    $nonTeachingOnTime = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $stmt = $con->prepare("SELECT COUNT(DISTINCT u.id) AS count
                           FROM assessments a
                           JOIN users u ON a.user_id = u.id
                           WHERE u.teaching_status = 'Non-teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND a.submission_date > ?
                           AND a.deadline_id = ?");
    $stmt->bind_param("si", $submissionDeadline, $activeDeadline['id']);
    $stmt->execute();
    $nonTeachingLate = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $stmt = $con->prepare("SELECT COUNT(*) AS count
                           FROM users u
                           WHERE u.teaching_status = 'Non-teaching'
                           AND u.status = 'accepted'
                           AND u.role = 'user'
                           AND NOT EXISTS (
                               SELECT 1 FROM assessments a WHERE a.user_id = u.id AND a.deadline_id = ?
                           )");
    $stmt->bind_param("i", $activeDeadline['id']);
    $stmt->execute();
    $nonTeachingNoSubmission = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Recent accepted users for the activity feed.
$recentUsers = $con->query("SELECT name, role, created_at FROM users WHERE status = 'accepted' AND id != " . $_SESSION['user_id'] . " ORDER BY created_at DESC LIMIT 5");

/* -----------------------------------------------------------------------------
   VIEW HELPERS — small presentation-only utilities (no DB side effects).
   ----------------------------------------------------------------------------- */

/**
 * Map a raw role string to a human-readable label.
 *
 * 2026-09-06 fix - this used to return "Faculty Member" for every
 * role='user' account with no exceptions, which is only half true: 'user'
 * covers BOTH teaching staff AND non-teaching staff (see
 * process_registration.php - non-teaching registrants get role='user'
 * too, just with teaching_status='Non-teaching'). There was no way to
 * tell them apart in User Management at all. $teachingStatus is optional
 * so every existing call site keeps working unchanged; pass it wherever
 * it's available to get the real distinction.
 */
function roleLabel(string $role, ?string $teachingStatus = null): string {
    if ($role === 'user') {
        if ($teachingStatus === null) return 'Employee';
        return strtolower($teachingStatus) === 'non-teaching' ? 'Non-Teaching Staff' : 'Faculty Member';
    }
    if ($role === 'admin') return 'Human Resource Administrator';
    if (strpos($role, 'admin_') === 0) return 'College Dean';
    return $role;
}

/** Resolve a department code to its full name (falls back to the raw value). */
function deptLabel(?string $code, array $map): string {
    if ($code !== null && isset($map[$code])) return $map[$code];
    return $code !== null && $code !== '' ? $code : 'N/A';
}

/** Percentage helper used by the submission progress bars. */
function pct(int $part, int $whole): float {
    return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard · LSPU TNA</title>

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
    /* 2026-09-06 fix - User Management's row actions used to be two full
       text buttons ("To Declined" + "Delete") crammed into one narrow
       Actions cell, and Delete would get squeezed almost entirely off the
       edge of the modal with no visible label. Icon-only + a fixed square
       size means it never grows past what it needs, regardless of how
       long the primary action's label is. */
    .btn-icon-only { padding: 0.5rem; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .btn-icon-only i { margin: 0; }
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
      <a href="admin_page.php" class="rail-link active">
        <i class="ri-dashboard-line"></i><span>Dashboard</span>
        <i class="ri-arrow-right-s-line chev"></i>
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
                  <button class="btn btn-ghost btn-sm" style="margin-top:0.55rem;"
                          onclick="closeAllDropdowns(); openPendingModal();">
                    Review now
                  </button>
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
            <h2>Main Admin Dashboard</h2>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>. Here is the current state of submissions.</p>
          </div>
          <div class="date-chip">
            <i class="ri-calendar-2-line"></i>
            <span><?= date('l, F j, Y') ?></span>
          </div>
        </div>

        <!-- ===========================================================
             STAT CARDS — accepted / pending / accepted / declined
             =========================================================== -->
        <section class="grid-stats">
          <div class="stat a-indigo">
            <div class="stat-body">
              <div>
                <p class="stat-label">Total Accepted Users</p>
                <h3 class="stat-value num" style="color:var(--ink);"><?= $totalUsers ?></h3>
              </div>
              <div class="stat-ico ico-indigo"><i class="fas fa-users"></i></div>
            </div>
          </div>

          <div class="stat a-amber">
            <div class="stat-body">
              <div>
                <p class="stat-label">Pending</p>
                <h3 class="stat-value num" style="color:var(--warn);"><?= $pendingCount ?></h3>
              </div>
              <div class="stat-ico ico-amber"><i class="ri-hourglass-line"></i></div>
            </div>
          </div>

          <div class="stat a-emerald">
            <div class="stat-body">
              <div>
                <p class="stat-label">Accepted</p>
                <h3 class="stat-value num" style="color:var(--ok);"><?= $totalAccepted ?></h3>
              </div>
              <div class="stat-ico ico-emerald"><i class="ri-checkbox-circle-line"></i></div>
            </div>
          </div>

          <div class="stat a-rose">
            <div class="stat-body">
              <div>
                <p class="stat-label">Declined</p>
                <h3 class="stat-value num" style="color:var(--bad);"><?= $totalDeclined ?></h3>
              </div>
              <div class="stat-ico ico-rose"><i class="ri-close-circle-line"></i></div>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             QUICK ACTIONS
             =========================================================== -->
        <section class="grid-actions">
          <button class="btn btn-primary btn-lg" onclick="openPendingModal()">
            <i class="ri-user-add-line"></i> Pending Registrations (<?= $pendingCount ?>)
          </button>
          <button class="btn btn-success btn-lg" onclick="openUserManagementModal()">
            <i class="ri-user-settings-line"></i> Manage Users
          </button>
          <button class="btn btn-ghost btn-lg" onclick="openDeadlineModal()">
            <i class="ri-calendar-event-line"></i> Set Deadline
          </button>
        </section>

        <!-- ===========================================================
             TEACHING / NON-TEACHING SPLIT
             =========================================================== -->
        <section class="grid-teaching">
          <!-- Teaching -->
          <div class="card hoverable">
            <div class="card-body">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
                <div>
                  <p class="stat-label">Teaching Staff</p>
                  <h3 class="stat-value num" style="color:var(--ink);"><?= $teachingTotal ?></h3>
                </div>
                <div class="stat-ico ico-indigo"><i class="fas fa-chalkboard-teacher"></i></div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;">
                <span class="badge badge-on-time" style="justify-content:center;">On Time: <span class="num"><?= $teachingOnTime ?></span></span>
                <span class="badge badge-late" style="justify-content:center;">Late: <span class="num"><?= $teachingLate ?></span></span>
                <span class="badge badge-no-sub" style="justify-content:center;">No Sub: <span class="num"><?= $teachingNoSubmission ?></span></span>
              </div>
            </div>
          </div>

          <!-- Non-teaching -->
          <div class="card hoverable">
            <div class="card-body">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
                <div>
                  <p class="stat-label">Non-Teaching Staff</p>
                  <h3 class="stat-value num" style="color:var(--ink);"><?= $nonTeachingTotal ?></h3>
                </div>
                <div class="stat-ico ico-violet"><i class="fas fa-user-tie"></i></div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;">
                <span class="badge badge-on-time" style="justify-content:center;">On Time: <span class="num"><?= $nonTeachingOnTime ?></span></span>
                <span class="badge badge-late" style="justify-content:center;">Late: <span class="num"><?= $nonTeachingLate ?></span></span>
                <span class="badge badge-no-sub" style="justify-content:center;">No Sub: <span class="num"><?= $nonTeachingNoSubmission ?></span></span>
              </div>
            </div>
          </div>
        </section>

        <!-- ===========================================================
             MAIN GRID — left (status column) / right (chart + history)
             =========================================================== -->
        <section class="grid-main">

          <!-- ===== LEFT COLUMN ===== -->
          <div class="stack">

            <!-- Current deadline -->
            <div class="card hoverable">
              <div class="card-head">
                <h3><i class="ri-timer-line"></i> Current Deadline</h3>
                <div class="card-head-icon"><i class="ri-alarm-warning-line"></i></div>
              </div>
              <div class="card-body">
                <h4 style="font-size:1.05rem;font-weight:700;margin-bottom:0.7rem;"><?= htmlspecialchars($deadlineTitle) ?></h4>
                <?php if ($submissionDeadline): ?>
                  <p style="color:var(--accent);font-weight:600;font-size:0.92rem;margin-bottom:0.75rem;">
                    <i class="ri-calendar-event-line"></i>
                    <?= date('F j, Y · g:i A', strtotime($submissionDeadline)) ?>
                  </p>
                  <span class="pill <?= $allowSubmissions ? 'pill-open' : 'pill-closed' ?>" style="margin-bottom:0.75rem;">
                    <i class="ri-<?= $allowSubmissions ? 'lock-unlock-line' : 'lock-line' ?>"></i>
                    Submissions: <?= $submissionStatus ?>
                  </span>
                  <?php if (!empty($deadlineDescription)): ?>
                    <p style="font-size:0.85rem;color:var(--muted);background:var(--surface-2);padding:0.9rem;border-radius:10px;border:1px solid var(--line-soft);margin-top:0.5rem;">
                      <?= htmlspecialchars($deadlineDescription) ?>
                    </p>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="empty" style="padding:1.5rem 1rem;">
                    <i class="ri-calendar-close-line"></i>
                    <h4>No active deadline</h4>
                    <p>Set one to start collecting submissions.</p>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Submission status summary -->
            <div class="card hoverable">
              <div class="card-head">
                <h3><i class="ri-progress-4-line" style="color:var(--ok);"></i> Submission Status</h3>
                <div class="card-head-icon" style="background:var(--ok-soft);color:var(--ok);"><i class="ri-checkbox-circle-line"></i></div>
              </div>
              <div class="card-body stack" style="gap:1.25rem;">
                <div>
                  <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.5rem;">
                    <span style="font-weight:500;color:var(--ink-2);">On Time</span>
                    <span class="num" style="font-weight:700;color:var(--ok);"><?= $onTimeCount ?></span>
                  </div>
                  <div class="progress"><span class="pf-ok" style="width:<?= pct($onTimeCount, $totalUsers) ?>%"></span></div>
                </div>
                <div>
                  <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.5rem;">
                    <span style="font-weight:500;color:var(--ink-2);">Late</span>
                    <span class="num" style="font-weight:700;color:var(--warn);"><?= $lateCount ?></span>
                  </div>
                  <div class="progress"><span class="pf-warn" style="width:<?= pct($lateCount, $totalUsers) ?>%"></span></div>
                </div>
                <div>
                  <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.5rem;">
                    <span style="font-weight:500;color:var(--ink-2);">No Submission</span>
                    <span class="num" style="font-weight:700;color:var(--bad);"><?= $noSubmissionCount ?></span>
                  </div>
                  <div class="progress"><span class="pf-bad" style="width:<?= pct($noSubmissionCount, $totalUsers) ?>%"></span></div>
                </div>
              </div>
            </div>

            <!-- Recent accepted users -->
            <div class="card hoverable">
              <div class="card-head">
                <h3><i class="ri-history-line"></i> Recent Accepted Users</h3>
              </div>
              <div class="card-body">
                <?php if ($recentUsers && $recentUsers->num_rows > 0): ?>
                  <div class="stack" style="gap:0.7rem;">
                    <?php while ($user = $recentUsers->fetch_assoc()): ?>
                      <div style="display:flex;align-items:center;justify-content:space-between;padding:0.8rem 0.9rem;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:11px;">
                        <div>
                          <p style="font-size:0.85rem;font-weight:600;color:var(--ink);"><?= htmlspecialchars($user['name']) ?></p>
                          <p style="font-size:0.72rem;color:var(--muted);margin-top:2px;"><?= date('M d, Y', strtotime($user['created_at'])) ?></p>
                        </div>
                        <span class="badge badge-accepted">Accepted</span>
                      </div>
                    <?php endwhile; ?>
                  </div>
                <?php else: ?>
                  <div class="empty">
                    <i class="ri-user-line"></i>
                    <h4>No recent activity</h4>
                    <p>Newly accepted users will appear here.</p>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- ===== RIGHT COLUMN ===== -->
          <div class="stack">

            <!-- Department chart -->
            <div class="card">
              <div class="card-head">
                <h3><i class="ri-bar-chart-grouped-line"></i> TNA Submissions by Department</h3>
                <div class="card-head-icon"><i class="ri-bar-chart-2-line"></i></div>
              </div>
              <div class="card-body">
                <div class="chart-box"><canvas id="departmentChart"></canvas></div>
                <div class="legend">
                  <div class="legend-item"><span class="legend-swatch" style="background:var(--ok);"></span> On Time</div>
                  <div class="legend-item"><span class="legend-swatch" style="background:var(--warn);"></span> Late</div>
                  <div class="legend-item"><span class="legend-swatch" style="background:var(--bad);"></span> No Submission</div>
                </div>
                <p style="font-size:0.74rem;color:var(--faint);text-align:center;margin-top:0.9rem;">
                  Select any bar to see its detailed breakdown.
                </p>
              </div>
            </div>

            <!-- Deadline history -->
            <div class="card">
              <div class="card-head">
                <h3><i class="ri-history-line"></i> Deadline History</h3>
                <div class="card-head-icon"><i class="ri-time-line"></i></div>
              </div>
              <div class="card-body">
                <div class="stack" id="deadlineHistory" style="gap:0.7rem;max-height:430px;overflow-y:auto;padding-right:0.25rem;">
                  <?php if ($deadlines && $deadlines->num_rows > 0): ?>
                    <?php while ($deadline = $deadlines->fetch_assoc()):
                      // Count submissions for this deadline.
                      $submissionsQuery = $con->prepare("SELECT COUNT(*) AS count FROM assessments WHERE deadline_id = ?");
                      $submissionsQuery->bind_param("i", $deadline['id']);
                      $submissionsQuery->execute();
                      $submissionsCount = $submissionsQuery->get_result()->fetch_assoc()['count'];
                      $submissionsQuery->close();
                    ?>
                      <div class="deadline-row" data-title="<?= htmlspecialchars(strtolower($deadline['title'])) ?>"
                           style="border:1px solid var(--line);<?= $deadline['is_active'] ? 'border-left:4px solid var(--accent);background:linear-gradient(120deg,#fff,#f3f4ff);' : '' ?>border-radius:13px;padding:1rem 1.1rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                          <div style="flex:1 1 240px;min-width:0;">
                            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.4rem;flex-wrap:wrap;">
                              <h4 style="font-size:0.95rem;font-weight:700;"><?= htmlspecialchars($deadline['title']) ?></h4>
                              <?php if ($deadline['is_active']): ?><span class="badge badge-accepted">Active</span><?php endif; ?>
                            </div>
                            <p style="font-size:0.82rem;color:var(--muted);margin-bottom:0.5rem;">
                              <i class="ri-calendar-event-line"></i>
                              <?= date('F j, Y · g:i A', strtotime($deadline['submission_deadline'])) ?>
                            </p>
                            <div style="display:flex;align-items:center;gap:1.1rem;font-size:0.82rem;color:var(--muted);">
                              <span><i class="ri-file-list-line"></i> <span class="num"><?= $submissionsCount ?></span> submissions</span>
                              <button onclick="showSubmissions(<?= $deadline['id'] ?>)"
                                      style="color:var(--accent);font-weight:600;display:inline-flex;align-items:center;gap:0.3rem;background:none;border:none;cursor:pointer;">
                                <i class="ri-eye-line"></i> View
                              </button>
                            </div>
                            <?php if (!empty($deadline['description'])): ?>
                              <p style="font-size:0.8rem;color:var(--faint);margin-top:0.5rem;"><?= htmlspecialchars($deadline['description']) ?></p>
                            <?php endif; ?>
                          </div>
                          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-start;">
                            <?php if (!$deadline['is_active']): ?>
                              <form method="POST" action="admin_page.php">
                                <input type="hidden" name="deadline_id" value="<?= $deadline['id'] ?>">
                                <button type="submit" name="set_active" class="btn btn-primary btn-sm">
                                  <i class="ri-check-line"></i> Set Active
                                </button>
                              </form>
                            <?php else: ?>
                              <span class="btn btn-success btn-sm" style="cursor:default;">
                                <i class="ri-checkbox-circle-line"></i> Active
                              </span>
                            <?php endif; ?>
                            <form method="POST" action="admin_page.php">
                              <input type="hidden" name="deadline_id" value="<?= $deadline['id'] ?>">
                              <input type="hidden" name="current_status" value="<?= $deadline['allow_submissions'] ?>">
                              <button type="submit" name="toggle_submissions"
                                      class="btn btn-sm <?= $deadline['allow_submissions'] ? 'btn-danger' : 'btn-success' ?>">
                                <i class="ri-<?= $deadline['allow_submissions'] ? 'lock-line' : 'lock-unlock-line' ?>"></i>
                                <?= $deadline['allow_submissions'] ? 'Close' : 'Open' ?>
                              </button>
                            </form>
                          </div>
                        </div>
                      </div>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <div class="empty">
                      <i class="ri-inbox-line"></i>
                      <h4>No deadlines yet</h4>
                      <p>Create your first deadline to get started.</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </section>

      </div>
    </div>
  </div>
</div>

<!-- =============================================================
     AUDIT LOG MODAL
     ============================================================= -->
<!-- =============================================================
     TOAST STACK — flash messages render here (see JS bootstrap)
     ============================================================= -->
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<!-- =============================================================
     MODAL · PENDING REGISTRATIONS
     ============================================================= -->
<div class="modal" id="pendingModal" role="dialog" aria-modal="true" aria-labelledby="pendingTitle">
  <div class="modal-box">
    <div class="modal-head">
      <h3 id="pendingTitle">
        <i class="ri-user-add-line"></i> Pending Registrations
        <?php if ($pendingCount > 0): ?>
          <span class="badge badge-pending num"><?= $pendingCount ?> pending</span>
        <?php endif; ?>
      </h3>
      <button class="modal-x" onclick="closeModal('pendingModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>

    <div class="modal-body">
      <!-- Search -->
      <div class="search" style="margin-bottom:1.2rem;">
        <i class="ri-search-line"></i>
        <input type="text" id="pendingSearch" placeholder="Search by name, email, role, or department…">
      </div>

      <?php if ($pendingUsers && $pendingUsers->num_rows > 0): ?>
        <div class="table-wrap">
          <table class="data" id="pendingTable">
            <thead>
              <tr>
                <th>Name</th><th>Email</th><th>Role</th><th>Department</th><th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($user = $pendingUsers->fetch_assoc()): ?>
                <tr class="pending-row">
                  <td class="strong search-name"><?= htmlspecialchars($user['name'] ?: 'Not provided') ?></td>
                  <td class="search-email"><?= htmlspecialchars($user['email']) ?></td>
                  <td><span class="badge badge-pending search-role"><?= roleLabel($user['role'], $user['teaching_status'] ?? null) ?></span></td>
                  <td class="search-dept"><?= htmlspecialchars(deptLabel($user['department'] ?? null, $departments)) ?></td>
                  <td style="text-align:center;">
                    <form method="POST" action="admin_page.php" style="display:flex;gap:0.5rem;justify-content:center;" onsubmit="return confirmAction(this)">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <button type="submit" name="action" value="accept" class="btn btn-success btn-sm"><i class="ri-check-line"></i> Accept</button>
                      <button type="submit" name="action" value="decline" class="btn btn-danger btn-sm"><i class="ri-close-line"></i> Decline</button>
                    </form>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <!-- Empty state revealed by the search filter. -->
        <div class="empty" id="pendingSearchEmpty" style="display:none;">
          <i class="ri-search-eye-line"></i><h4>No matching users</h4><p>Adjust your search terms.</p>
        </div>
        <!-- 2026-09-06 - pagination (see the same fix on User Management
             below for why: scrolling a long, unpaginated list isn't
             friendly for whoever's actually using this day to day). -->
        <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
          <button class="btn btn-ghost btn-sm" onclick="changeUserPage('pending', -1)"><i class="ri-arrow-left-line"></i> Prev</button>
          <span id="pendingPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
          <button class="btn btn-ghost btn-sm" onclick="changeUserPage('pending', 1)">Next <i class="ri-arrow-right-line"></i></button>
        </div>
      <?php else: ?>
        <div class="empty">
          <i class="ri-inbox-line"></i><h4>No pending registrations</h4><p>You're all caught up.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('pendingModal')">Close</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · USER MANAGEMENT (accepted / declined tabs)
     ============================================================= -->
<div class="modal" id="userManagementModal" role="dialog" aria-modal="true" aria-labelledby="userMgmtTitle">
  <div class="modal-box" style="max-width:1120px;">
    <div class="modal-head">
      <h3 id="userMgmtTitle"><i class="ri-user-settings-line"></i> User Management</h3>
      <button class="modal-x" onclick="closeModal('userManagementModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>

    <div class="modal-body">
      <!-- Search + department filter -->
      <div style="display:flex;gap:0.7rem;margin-bottom:1.2rem;flex-wrap:wrap;">
        <div class="search" style="flex:1;min-width:220px;margin-bottom:0;">
          <i class="ri-search-line"></i>
          <input type="text" id="userSearch" placeholder="Search by name, email, role, or department…">
        </div>
        <select id="userDeptFilter" onchange="userPageState.accepted=1;userPageState.declined=1;userPageState.disabled=1;const t=['accepted','declined','disabled'].find(x=>!document.getElementById(x+'-users-tab').classList.contains('hidden'));if(t)applyUserTableView(t);"
                style="padding:0.6rem 0.9rem;border:1px solid var(--line);border-radius:10px;font-size:0.85rem;color:var(--ink-2);background:var(--surface);">
          <option value="">All Departments</option>
          <?php if (!empty($teachingDeptOptions)): ?>
            <optgroup label="Teaching">
              <?php foreach ($teachingDeptOptions as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if (!empty($nonTeachingDeptOptions)): ?>
            <optgroup label="Non-Teaching">
              <?php foreach ($nonTeachingDeptOptions as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if (!empty($otherDeptOptions)): ?>
            <optgroup label="Other">
              <?php foreach ($otherDeptOptions as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <div class="tab active" id="tab-accepted" onclick="switchUserTab('accepted')">Accepted (<?= $totalAccepted ?>)</div>
        <div class="tab" id="tab-declined" onclick="switchUserTab('declined')">Declined (<?= $totalDeclined ?>)</div>
        <div class="tab" id="tab-disabled" onclick="switchUserTab('disabled')">Disabled (<?= $totalDisabled ?>)</div>
      </div>

      <!-- Accepted tab -->
      <div id="accepted-users-tab" class="tab-panel">
        <?php if ($acceptedUsersList && $acceptedUsersList->num_rows > 0): ?>
          <div class="table-wrap">
            <table class="data" id="acceptedTable">
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
              </thead>
              <tbody>
                <?php
                $acceptedUsersList->data_seek(0);
                while ($user = $acceptedUsersList->fetch_assoc()):
                ?>
                  <tr class="accepted-row">
                    <td class="strong search-name"><?= htmlspecialchars($user['name'] ?: 'Not provided') ?></td>
                    <td class="search-email"><?= htmlspecialchars($user['email']) ?></td>
                    <td class="search-role"><?= roleLabel($user['role'], $user['teaching_status'] ?? null) ?></td>
                    <td class="search-dept"><?= htmlspecialchars(deptLabel($user['department'] ?? null, $departments)) ?></td>
                    <td><span class="badge badge-accepted">Accepted</span></td>
                    <td style="text-align:center;">
                      <form method="POST" action="admin_page.php" style="display:flex;gap:0.5rem;justify-content:center;align-items:center;" onsubmit="return confirmDelete(this)">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <!-- 2026-09-06 - was "To Declined" reusing the same
                             status a rejected NEW registration gets. This
                             account is already active/working - "Disable"
                             says what's actually happening, and now sets a
                             distinct status so the person sees the right
                             message if they try to log in. -->
                        <button type="submit" name="action" value="disable" class="btn btn-warning btn-sm"><i class="ri-forbid-line"></i> Disable</button>
                        <button type="submit" name="action" value="delete" class="btn btn-delete btn-sm btn-icon-only" title="Delete permanently" aria-label="Delete user"><i class="ri-delete-bin-line"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <div class="empty" id="acceptedNoMatches" style="display:none;">
            <i class="ri-filter-off-line"></i><h4>No matches</h4><p>No accounts on this page match your search.</p>
          </div>
          <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('accepted', -1)"><i class="ri-arrow-left-line"></i> Prev</button>
            <span id="acceptedPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('accepted', 1)">Next <i class="ri-arrow-right-line"></i></button>
          </div>
        <?php else: ?>
          <div class="empty"><i class="ri-user-smile-line"></i><h4>No accepted users</h4><p>Accepted users will be listed here.</p></div>
        <?php endif; ?>
      </div>

      <!-- Declined tab -->
      <div id="declined-users-tab" class="tab-panel hidden">
        <?php if ($declinedUsersList && $declinedUsersList->num_rows > 0): ?>
          <div class="table-wrap">
            <table class="data" id="declinedTable">
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
              </thead>
              <tbody>
                <?php
                $declinedUsersList->data_seek(0);
                while ($user = $declinedUsersList->fetch_assoc()):
                ?>
                  <tr class="declined-row">
                    <td class="strong search-name"><?= htmlspecialchars($user['name'] ?: 'Not provided') ?></td>
                    <td class="search-email"><?= htmlspecialchars($user['email']) ?></td>
                    <td class="search-role"><?= roleLabel($user['role'], $user['teaching_status'] ?? null) ?></td>
                    <td class="search-dept"><?= htmlspecialchars(deptLabel($user['department'] ?? null, $departments)) ?></td>
                    <td><span class="badge badge-declined">Declined</span></td>
                    <td style="text-align:center;">
                      <form method="POST" action="admin_page.php" style="display:flex;gap:0.5rem;justify-content:center;align-items:center;" onsubmit="return confirmDelete(this)">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <button type="submit" name="action" value="accept" class="btn btn-success btn-sm"><i class="ri-arrow-up-line"></i> Enable</button>
                        <button type="submit" name="action" value="delete" class="btn btn-delete btn-sm btn-icon-only" title="Delete permanently" aria-label="Delete user"><i class="ri-delete-bin-line"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <div class="empty" id="declinedNoMatches" style="display:none;">
            <i class="ri-filter-off-line"></i><h4>No matches</h4><p>No accounts on this page match your search.</p>
          </div>
          <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('declined', -1)"><i class="ri-arrow-left-line"></i> Prev</button>
            <span id="declinedPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('declined', 1)">Next <i class="ri-arrow-right-line"></i></button>
          </div>
        <?php else: ?>
          <div class="empty"><i class="ri-user-unfollow-line"></i><h4>No declined users</h4><p>Declined users will be listed here.</p></div>
        <?php endif; ?>
      </div>

      <!-- Disabled tab (2026-09-06 - see ensureUserDisabledStatus() near the
           top of this file for why this is separate from Declined). -->
      <div id="disabled-users-tab" class="tab-panel hidden">
        <?php if ($disabledUsersList && $disabledUsersList->num_rows > 0): ?>
          <div class="table-wrap">
            <table class="data" id="disabledTable">
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
              </thead>
              <tbody>
                <?php
                $disabledUsersList->data_seek(0);
                while ($user = $disabledUsersList->fetch_assoc()):
                ?>
                  <tr class="disabled-row">
                    <td class="strong search-name"><?= htmlspecialchars($user['name'] ?: 'Not provided') ?></td>
                    <td class="search-email"><?= htmlspecialchars($user['email']) ?></td>
                    <td class="search-role"><?= roleLabel($user['role'], $user['teaching_status'] ?? null) ?></td>
                    <td class="search-dept"><?= htmlspecialchars(deptLabel($user['department'] ?? null, $departments)) ?></td>
                    <td><span class="badge badge-declined">Disabled</span></td>
                    <td style="text-align:center;">
                      <form method="POST" action="admin_page.php" style="display:flex;gap:0.5rem;justify-content:center;align-items:center;" onsubmit="return confirmDelete(this)">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <button type="submit" name="action" value="accept" class="btn btn-success btn-sm"><i class="ri-arrow-up-line"></i> Enable</button>
                        <button type="submit" name="action" value="delete" class="btn btn-delete btn-sm btn-icon-only" title="Delete permanently" aria-label="Delete user"><i class="ri-delete-bin-line"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <div class="empty" id="disabledNoMatches" style="display:none;">
            <i class="ri-filter-off-line"></i><h4>No matches</h4><p>No accounts on this page match your search.</p>
          </div>
          <div style="display:flex;justify-content:center;align-items:center;gap:0.6rem;margin-top:1rem;">
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('disabled', -1)"><i class="ri-arrow-left-line"></i> Prev</button>
            <span id="disabledPageIndicator" style="font-size:0.82rem;color:var(--muted);">Page 1</span>
            <button class="btn btn-ghost btn-sm" onclick="changeUserPage('disabled', 1)">Next <i class="ri-arrow-right-line"></i></button>
          </div>
        <?php else: ?>
          <div class="empty"><i class="ri-forbid-line"></i><h4>No disabled accounts</h4><p>Accounts you disable will be listed here.</p></div>
        <?php endif; ?>
      </div>

      <!-- Shared "no search results" empty state (all tabs empty at once). -->
      <div class="empty" id="userSearchEmpty" style="display:none;">
        <i class="ri-search-eye-line"></i><h4>No matching users</h4><p>Adjust your search terms.</p>
      </div>
    </div>

    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('userManagementModal')">Close</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · SET NEW DEADLINE
     ============================================================= -->
<div class="modal" id="deadlineModal" role="dialog" aria-modal="true" aria-labelledby="deadlineModalTitle">
  <div class="modal-box" style="max-width:500px;">
    <form id="deadlineForm" method="POST" action="admin_page.php" style="display:flex;flex-direction:column;max-height:90vh;">
      <div class="modal-head">
        <h3 id="deadlineModalTitle"><i class="ri-calendar-event-line"></i> Set New Deadline</h3>
        <button type="button" class="modal-x" onclick="closeModal('deadlineModal')" aria-label="Close"><i class="ri-close-line"></i></button>
      </div>

      <div class="modal-body">
        <!-- Title -->
        <div class="field">
          <label for="title">Title</label>
          <input type="text" id="title" name="title" class="input" value="Training Needs Assessment Deadline">
          <p class="field-help">Shown to faculty in their notification and dashboard.</p>
        </div>

        <!-- Date/time -->
        <div class="field">
          <label for="newDeadline">Deadline date &amp; time <span class="req">*</span></label>
          <input type="datetime-local" id="newDeadline" name="deadline" class="input" required>
          <p class="field-error" id="deadlineError"></p>
          <p class="field-help">Must be a future date. Faculty can submit up to this moment.</p>
        </div>

        <!-- Description -->
        <div class="field">
          <label for="description">Description (optional)</label>
          <textarea id="description" name="description" class="textarea" placeholder="Add notes or instructions for this cycle…"></textarea>
        </div>

        <!-- Active toggle -->
        <div class="field" style="background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:1rem;margin-bottom:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <label for="is_active" style="margin:0;display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
              <i class="ri-checkbox-circle-line" style="color:var(--accent);font-size:1.15rem;"></i> Set as active deadline
            </label>
            <span class="switch">
              <input type="checkbox" id="is_active" name="is_active" checked>
              <span class="track"></span>
            </span>
          </div>
          <p class="field-help" style="margin-top:0.6rem;">When on, this becomes the current deadline and notifies all accepted users.</p>
        </div>

        <!-- Live preview -->
        <div id="deadlinePreview" style="display:none;margin-top:1.1rem;background:var(--accent-soft);border:1px solid #c7d2fe;border-radius:12px;padding:0.9rem 1rem;">
          <p style="font-size:0.78rem;font-weight:700;color:var(--accent-ink);margin-bottom:0.25rem;">Preview</p>
          <p style="font-size:0.85rem;color:var(--accent-ink);">Deadline will be set to <span id="previewDate" style="font-weight:700;"></span></p>
        </div>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('deadlineModal')">Cancel</button>
        <button type="submit" name="update_deadline" id="submitDeadlineBtn" class="btn btn-primary">Set Deadline</button>
      </div>
    </form>
  </div>
</div>

<!-- =============================================================
     MODAL · SUBMISSIONS (per deadline, async + paginated)
     ============================================================= -->
<div class="modal" id="submissionsModal" role="dialog" aria-modal="true" aria-labelledby="submissionsTitle">
  <div class="modal-box" style="max-width:920px;">
    <div class="modal-head">
      <h3 id="submissionsTitle"><i class="ri-file-list-3-line"></i> Submissions <span style="font-size:0.78rem;font-weight:600;color:var(--muted);">&middot;</span> <span id="modalDeadlineTitle" style="font-size:0.78rem;font-weight:600;color:var(--accent);"></span></h3>
      <button class="modal-x" onclick="closeModal('submissionsModal')" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>

    <div class="modal-body">
      <!-- Meta + pagination -->
      <div style="display:flex;flex-wrap:wrap;gap:0.8rem;justify-content:space-between;align-items:center;background:var(--surface-2);border:1px solid var(--line-soft);border-radius:12px;padding:0.9rem 1.1rem;margin-bottom:1.2rem;">
        <div style="display:flex;flex-direction:column;gap:0.2rem;">
          <p style="font-size:0.82rem;color:var(--muted);"><i class="ri-calendar-event-line"></i> Deadline: <span id="modalDeadlineDate" style="font-weight:600;color:var(--ink-2);"></span></p>
          <p style="font-size:0.82rem;color:var(--muted);"><i class="ri-file-list-line"></i> Total: <span id="modalTotalSubmissions" class="num" style="font-weight:600;color:var(--ink-2);"></span></p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
          <button class="btn btn-ghost btn-sm" onclick="changePage(-1)"><i class="ri-arrow-left-line"></i> Prev</button>
          <span id="currentPage" class="btn btn-primary btn-sm num" style="cursor:default;">1</span>
          <button class="btn btn-ghost btn-sm" onclick="changePage(1)">Next <i class="ri-arrow-right-line"></i></button>
        </div>
      </div>

      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Name</th><th>Department</th><th>Status</th><th>Submission Date</th></tr>
          </thead>
          <tbody id="submissionsTableBody"><!-- async rows --></tbody>
        </table>
      </div>
    </div>

    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal('submissionsModal')">Close</button>
    </div>
  </div>
</div>

<!-- =============================================================
     MODAL · TRAINING DEMAND DETAIL
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

/* Open helpers that also reset the relevant search field. */
function openPendingModal() {
  const s = document.getElementById('pendingSearch'); if (s) s.value = '';
  userPageState.pending = 1;
  applyUserTableView('pending');
  openModal('pendingModal');
}
function openUserManagementModal() {
  const s = document.getElementById('userSearch'); if (s) s.value = '';
  const d = document.getElementById('userDeptFilter'); if (d) d.value = '';
  userPageState.accepted = 1;
  userPageState.declined = 1;
  userPageState.disabled = 1;
  document.getElementById('userSearchEmpty').style.display = 'none';
  switchUserTab('accepted');
  openModal('userManagementModal');
}
function openDeadlineModal() { openModal('deadlineModal'); }

/* ============================================================
   F. TABS (user management)
   ============================================================ */
function switchUserTab(tab) {
  document.getElementById('tab-accepted').classList.toggle('active', tab === 'accepted');
  document.getElementById('tab-declined').classList.toggle('active', tab === 'declined');
  document.getElementById('tab-disabled').classList.toggle('active', tab === 'disabled');
  document.getElementById('accepted-users-tab').classList.toggle('hidden', tab !== 'accepted');
  document.getElementById('declined-users-tab').classList.toggle('hidden', tab !== 'declined');
  document.getElementById('disabled-users-tab').classList.toggle('hidden', tab !== 'disabled');
  applyUserTableView(tab);
}

/* ============================================================
   G. SEARCH + PAGINATION
   -----------------------------------------------------------
   2026-09-06 - with 285+ real accounts loaded, an unpaginated list was
   genuinely unusable (an admin would have to scroll through all of them
   to find one). One combined filter-then-paginate function per table,
   same shape as Training Pipeline's Training Demand list
   (applyDemandFilterAndPagination) - filtering has to run BEFORE
   slicing to a page, or "page 2" would just show more filtered-out rows.
   ============================================================ */
const USER_PER_PAGE = 10;
const userPageState = { pending: 1, accepted: 1, declined: 1, disabled: 1 };

// tableKey -> { rowSelector, searchInputId, indicatorId, noMatchesId }
const USER_TABLE_CONFIG = {
  pending:  { rowSel: '#pendingTable .pending-row',   searchId: 'pendingSearch', indicatorId: 'pendingPageIndicator',  noMatchesId: 'pendingSearchEmpty' },
  accepted: { rowSel: '#acceptedTable .accepted-row', searchId: 'userSearch',    indicatorId: 'acceptedPageIndicator', noMatchesId: 'acceptedNoMatches' },
  declined: { rowSel: '#declinedTable .declined-row', searchId: 'userSearch',    indicatorId: 'declinedPageIndicator', noMatchesId: 'declinedNoMatches' },
  disabled: { rowSel: '#disabledTable .disabled-row', searchId: 'userSearch', indicatorId: 'disabledPageIndicator', noMatchesId: 'disabledNoMatches' },
};

function rowMatchesSearch(row, term) {
  if (!term) return true;
  const hay = ['search-name', 'search-email', 'search-role', 'search-dept']
    .map(c => (row.querySelector('.' + c)?.textContent || '').toLowerCase())
    .join(' ');
  return hay.includes(term);
}

/** Filters + paginates ONE table. Safe to call even if that table has 0 rows. */
function applyUserTableView(tableKey) {
  const cfg = USER_TABLE_CONFIG[tableKey];
  if (!cfg) return;
  const rows = Array.from(document.querySelectorAll(cfg.rowSel));
  if (rows.length === 0) return; // "no accounts at all" empty state already shown server-side

  const searchInput = document.getElementById(cfg.searchId);
  const term = (searchInput?.value || '').toLowerCase();
  // Department dropdown only exists on the Accepted/Declined/Disabled tabs,
  // not Pending - guard so 'pending' just skips this filter entirely.
  const deptFilterEl = tableKey !== 'pending' ? document.getElementById('userDeptFilter') : null;
  const deptFilter = deptFilterEl ? deptFilterEl.value : '';
  const matching = rows.filter(r =>
    rowMatchesSearch(r, term) &&
    (!deptFilter || (r.querySelector('.search-dept')?.textContent || '').trim() === deptFilter)
  );

  const totalPages = Math.max(1, Math.ceil(matching.length / USER_PER_PAGE));
  if (userPageState[tableKey] > totalPages) userPageState[tableKey] = totalPages;
  if (userPageState[tableKey] < 1) userPageState[tableKey] = 1;
  const start = (userPageState[tableKey] - 1) * USER_PER_PAGE;
  const end = start + USER_PER_PAGE;

  rows.forEach(row => row.style.display = 'none');
  matching.forEach((row, i) => { row.style.display = (i >= start && i < end) ? '' : 'none'; });

  const indicator = document.getElementById(cfg.indicatorId);
  if (indicator) indicator.textContent = `Page ${userPageState[tableKey]} of ${totalPages}`;
  const noMatches = document.getElementById(cfg.noMatchesId);
  if (noMatches) noMatches.style.display = matching.length === 0 ? '' : 'none';
  const wrap = document.querySelector(cfg.rowSel.split(' ')[0])?.closest('.table-wrap');
  if (wrap) wrap.style.display = matching.length === 0 ? 'none' : '';

  // The shared "no matching users at all" empty state under User Management
  // only reflects whichever tab is actually visible right now.
  if (tableKey === 'accepted' || tableKey === 'declined' || tableKey === 'disabled') {
    const activeTab = ['accepted', 'declined', 'disabled'].find(
      t => !document.getElementById(t + '-users-tab').classList.contains('hidden')
    );
    if (activeTab === tableKey) {
      document.getElementById('userSearchEmpty').style.display = matching.length === 0 ? '' : 'none';
    }
  }
}

function changeUserPage(tableKey, delta) {
  userPageState[tableKey] += delta;
  applyUserTableView(tableKey);
}

function initSearch() {
  const pendingSearch = document.getElementById('pendingSearch');
  if (pendingSearch) {
    pendingSearch.addEventListener('keyup', function () {
      userPageState.pending = 1;
      applyUserTableView('pending');
    });
  }

  // User management (searches whichever tab is visible).
  const userSearch = document.getElementById('userSearch');
  if (userSearch) {
    userSearch.addEventListener('keyup', function () {
      userPageState.accepted = 1;
      userPageState.declined = 1;
      userPageState.disabled = 1;
      const activeTab = ['accepted', 'declined', 'disabled'].find(
        t => !document.getElementById(t + '-users-tab').classList.contains('hidden')
      );
      if (activeTab) applyUserTableView(activeTab);
    });
  }
}

/* ============================================================
   H. CONFIRM GUARDS — figure out which submit button was pressed.
   ============================================================ */
function pressedValue(form) {
  // document.activeElement is the button that triggered submit.
  const btn = form.querySelector('button[type="submit"]:focus') || document.activeElement;
  return (btn && btn.name === 'action') ? btn.value : null;
}
function rowName(form) {
  const row = form.closest('tr');
  return row ? (row.querySelector('td')?.textContent.trim() || 'this user') : 'this user';
}
function confirmAction(form) {
  const action = pressedValue(form); if (!action) return true;
  const name = rowName(form);
  if (action === 'accept')  return confirm(`Accept ${name}?`);
  if (action === 'decline') return confirm(`Decline ${name}?`);
  return true;
}
function confirmDelete(form) {
  const action = pressedValue(form); if (!action) return true;
  const name = rowName(form);
  if (action === 'delete')  return confirm(`Permanently DELETE ${name}? This cannot be undone.`);
  if (action === 'disable') return confirm(`Disable ${name}? They will not be able to log in until re-enabled.`);
  if (action === 'accept' || action === 'decline') return confirm(`Move ${name}?`);
  return true;
}

/* ============================================================
   I. DEADLINE FORM — future-date validation + live preview
   ============================================================ */
function initDeadlineForm() {
  const input   = document.getElementById('newDeadline');
  const error   = document.getElementById('deadlineError');
  const preview = document.getElementById('deadlinePreview');
  const pdate   = document.getElementById('previewDate');
  const submit  = document.getElementById('submitDeadlineBtn');
  if (!input) return;

  input.addEventListener('change', () => {
    const picked = new Date(input.value);
    const now = new Date();

    if (!input.value || picked <= now) {
      error.textContent = 'Deadline must be in the future.';
      error.classList.add('show');
      input.classList.add('has-error');
      preview.style.display = 'none';
      submit.disabled = true;
      return;
    }
    error.classList.remove('show');
    input.classList.remove('has-error');
    submit.disabled = false;

    pdate.textContent = picked.toLocaleString('en-US', {
      weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    preview.style.display = 'block';
  });
}

/* ============================================================
   J. DEPARTMENT CHART (stacked bars) + detail popup
   ============================================================ */
const DEPT_CODES = <?php echo json_encode(array_keys($departments)); ?>;
const DEPT_NAMES = <?php echo json_encode(array_values($departments)); ?>;
const D_ONTIME   = <?php echo json_encode(array_values($onTime)); ?>;
const D_LATE     = <?php echo json_encode(array_values($late)); ?>;
const D_NOSUB    = <?php echo json_encode(array_values($noSubmission)); ?>;

function initDepartmentChart() {
  const canvas = document.getElementById('departmentChart');
  if (!canvas || typeof Chart === 'undefined') return;
  const ctx = canvas.getContext('2d');

  const base = {
    borderWidth: 1, borderRadius: 6, borderSkipped: false,
    barPercentage: 0.72, categoryPercentage: 0.9, stack: 'total'
  };

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: DEPT_CODES,
      datasets: [
        { label: 'On Time',       data: D_ONTIME, backgroundColor: '#059669', borderColor: '#047857', ...base },
        { label: 'Late',          data: D_LATE,   backgroundColor: '#d97706', borderColor: '#b45309', ...base },
        { label: 'No Submission', data: D_NOSUB,  backgroundColor: '#e11d48', borderColor: '#be123c', ...base },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f172a', padding: 12, cornerRadius: 10,
          titleFont: { family: 'Inter', weight: '700' }, bodyFont: { family: 'Inter' },
          callbacks: {
            label(c) {
              const i = c.dataIndex;
              const total = (D_ONTIME[i] || 0) + (D_LATE[i] || 0) + (D_NOSUB[i] || 0);
              const val = c.raw || 0;
              const p = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
              return ` ${c.dataset.label}: ${val} (${p}%)`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { family: 'JetBrains Mono', size: 11, weight: '600' }, maxRotation: 45, minRotation: 45 },
          title: { display: true, text: 'Department Code', font: { family: 'Inter', size: 12, weight: '700' }, padding: { top: 8 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: '#eef1f5' },
          ticks: { stepSize: 1, font: { family: 'JetBrains Mono', size: 11 }, callback: v => Math.floor(v) },
          title: { display: true, text: 'Number of Users', font: { family: 'Inter', size: 12, weight: '700' } }
        }
      },
      onClick(evt, items) {
        if (items && items.length) showDeptDetail(items[0].dataIndex);
      }
    }
  });
}

/* Build a small on-brand detail modal (dependency-free) for a clicked bar. */
function showDeptDetail(i) {
  const onTime = D_ONTIME[i], late = D_LATE[i], noSub = D_NOSUB[i];
  const total = onTime + late + noSub;

  // Reuse an existing detail modal if present, else create one.
  let modal = document.getElementById('deptDetailModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'deptDetailModal';
    modal.className = 'modal';
    modal.innerHTML = `
      <div class="modal-box" style="max-width:440px;">
        <div class="modal-head">
          <h3><i class="ri-pie-chart-line"></i> <span id="deptDetailName"></span></h3>
          <button class="modal-x" onclick="closeModal('deptDetailModal')" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body" id="deptDetailBody"></div>
        <div class="modal-foot"><button class="btn btn-ghost" onclick="closeModal('deptDetailModal')">Close</button></div>
      </div>`;
    document.body.appendChild(modal);
  }

  document.getElementById('deptDetailName').textContent = DEPT_NAMES[i];

  const row = (cls, ico, label, val, color) => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:0.8rem 1rem;border-radius:11px;background:${cls};margin-bottom:0.6rem;">
      <span style="font-weight:600;display:flex;align-items:center;gap:0.5rem;color:var(--ink-2);"><i class="${ico}" style="color:${color};"></i> ${label}</span>
      <span class="num" style="font-weight:700;color:${color};">${val}</span>
    </div>`;

  document.getElementById('deptDetailBody').innerHTML =
    row('var(--ok-soft)',   'ri-checkbox-circle-line', 'On Time',       onTime, 'var(--ok)') +
    row('var(--warn-soft)', 'ri-time-line',            'Late',          late,   'var(--warn)') +
    row('var(--bad-soft)',  'ri-close-circle-line',    'No Submission', noSub,  'var(--bad)') +
    `<div style="display:flex;align-items:center;justify-content:space-between;padding:0.8rem 1rem;border-radius:11px;background:var(--surface-3);border:1px solid var(--line);">
       <span style="font-weight:700;color:var(--ink);"><i class="ri-group-line"></i> Total</span>
       <span class="num" style="font-weight:700;color:var(--ink);">${total}</span>
     </div>`;

  openModal('deptDetailModal');
}

/* ============================================================
   K. SUBMISSIONS MODAL — async load, skeletons, pagination
   ============================================================ */
let currentDeadlineId = 0;
let currentPage = 1;
const itemsPerPage = 10;

/** Render N skeleton rows while data is fetched. */
function submissionSkeletonRows(n = 6) {
  let html = '';
  for (let i = 0; i < n; i++) {
    html += `
      <tr>
        <td><div class="skel skel-line" style="width:62%;"></div></td>
        <td><div class="skel skel-line" style="width:48%;"></div></td>
        <td><div class="skel skel-pill"></div></td>
        <td><div class="skel skel-line" style="width:55%;"></div></td>
      </tr>`;
  }
  return html;
}

function showSubmissions(deadlineId) {
  currentDeadlineId = deadlineId;
  currentPage = 1;

  document.getElementById('submissionsTableBody').innerHTML = submissionSkeletonRows();
  document.getElementById('modalDeadlineTitle').textContent = 'Loading…';
  document.getElementById('modalDeadlineDate').textContent = '—';
  document.getElementById('modalTotalSubmissions').textContent = '—';
  openModal('submissionsModal');

  fetch(`get_deadline_info.php?id=${deadlineId}`)
    .then(r => r.json())
    .then(data => {
      document.getElementById('modalDeadlineTitle').textContent = data.title;
      document.getElementById('modalDeadlineDate').textContent = data.formatted_date;
      document.getElementById('modalTotalSubmissions').textContent = data.total_submissions;
    })
    .catch(() => {
      document.getElementById('modalDeadlineTitle').textContent = 'Deadline';
      showToast('err', 'Load failed', 'Could not load deadline details.');
    });

  loadSubmissions();
}

function loadSubmissions() {
  const tbody = document.getElementById('submissionsTableBody');
  tbody.innerHTML = submissionSkeletonRows();

  fetch(`get_submissions.php?deadline_id=${currentDeadlineId}&page=${currentPage}&per_page=${itemsPerPage}`)
    .then(r => r.json())
    .then(data => {
      tbody.innerHTML = '';

      if (!data.submissions || data.submissions.length === 0) {
        tbody.innerHTML = `
          <tr><td colspan="4">
            <div class="empty"><i class="ri-inbox-line"></i><h4>No submissions</h4><p>Nothing recorded for this page.</p></div>
          </td></tr>`;
        return;
      }

      data.submissions.forEach(s => {
        const tr = document.createElement('tr');
        const badge = s.status === 'On Time' ? 'badge-on-time' : 'badge-late';
        tr.innerHTML = `
          <td class="strong">${s.name}</td>
          <td>${s.department}</td>
          <td><span class="badge ${badge}">${s.status}</span></td>
          <td>${s.formatted_date}</td>`;
        tbody.appendChild(tr);
      });

      document.getElementById('currentPage').textContent = currentPage;
    })
    .catch(() => {
      tbody.innerHTML = `
        <tr><td colspan="4">
          <div class="empty"><i class="ri-error-warning-line"></i><h4>Couldn't load submissions</h4><p>Please try again.</p></div>
        </td></tr>`;
    });
}

function changePage(delta) {
  const next = currentPage + delta;
  if (next < 1) return;
  currentPage = next;
  loadSubmissions();
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
  const allRows = Array.from(tbody.querySelectorAll('tr'));

  // "All Statuses (active)" deliberately excludes both terminal states -
  // Training Completed and Closed are archived out of the default view,
  // not deleted; pick the "(archived)" option to see them (2026-08-28,
  // Closed added 2026-08-29 once close_training_demand.php could set it).
  const archivedStatuses = ['Training Completed', 'Closed'];
  const matching = allRows.filter(row => filter ? row.dataset.status === filter : !archivedStatuses.includes(row.dataset.status));
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

document.addEventListener('DOMContentLoaded', applyDemandFilterAndPagination);

/* ============================================================
   L. BOOT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  initSearch();
  // Apply pagination immediately on load - otherwise every row PHP
  // rendered would show at once until the admin first types a search
  // term or switches tabs, defeating the point.
  ['pending', 'accepted', 'declined', 'disabled'].forEach(applyUserTableView);
  initDeadlineForm();
  initDepartmentChart();
  syncCollapseButton();

  // Close dropdowns when clicking outside any dropdown.
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) closeAllDropdowns();
  });

  // Close modals on backdrop click. confirmActionModal is handled specially
  // below (it has a pending Promise waiting on an answer, not a plain close).
  const NO_BACKDROP_CLOSE = [];
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

  /* ---- B. FLASH BOOTSTRAP (PHP session messages to toasts) ----
     2026-09-03 fix - this used to call mapType() directly from PHP
     output, but mapType() only ever existed as the client-side JS
     function defined above (around line 2305) - there was never a
     server-side equivalent. Every accept/decline/delete/deadline
     action that set one of these two session flags then threw a PHP
     Fatal error the next time this page rendered, partway through
     this very script block, right where display_errors dumped the
     error text into the still-open element - which broke the WHOLE
     block as one syntax unit, so every modal-opening function defined
     earlier in the same tag (openModal, openPendingModal,
     openUserManagementModal, openDeadlineModal) never got defined
     either, even though they're textually before the crash. And
     because it's a fatal, the unset() below it never ran, so the
     stuck flag re-triggered the same fatal on every reload until
     something wiped the session (logout) - exactly the "works again
     after sign-out/sign-in" behavior reported. Fixed by not calling
     mapType() from PHP at all: pass the raw session value through as
     a JSON string and call the real, client-side mapType() at the JS
     call site instead. */
  <?php if (isset($_SESSION['deadline_message'])): ?>
    showToast(mapType(<?= json_encode($_SESSION['message_type'] ?? 'info') ?>),
              'Deadline',
              <?= json_encode($_SESSION['deadline_message']) ?>);
    <?php unset($_SESSION['deadline_message'], $_SESSION['message_type']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['userActionMessage'])): ?>
    showToast(mapType(<?= json_encode($_SESSION['userActionType'] ?? 'info') ?>),
              'User Management',
              <?= json_encode($_SESSION['userActionMessage']) ?>);
    <?php unset($_SESSION['userActionMessage'], $_SESSION['userActionType']); ?>
  <?php endif; ?>
});
</script>

</body>
</html>