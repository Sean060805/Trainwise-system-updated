<?php
session_start();
require_once 'config.php';

// Helper function
function clean_input($data) {
    return trim($data ?? '');
}

// Department mapping para sa College Deans
$departmentRoles = [
    'CA' => 'admin_ca',
    'CAS' => 'admin_cas',
    'CBAA' => 'admin_cbaa',
    'CCS' => 'admin_ccs',
    'CCJE' => 'admin_ccje',
    'COE' => 'admin_coe',
    'CIT' => 'admin_cit',
    'CFND' => 'admin_cfnd',
    'COF' => 'admin_cof',
    'CIHTM' => 'admin_cihtm',
    'CTE' => 'admin_cte',
    'CONAH' => 'admin_conah',
    'COL' => 'admin_col'
];

// Department full names
$departmentFullNames = [
    'CA' => 'College of Agriculture',
    'CAS' => 'College of Arts and Sciences',
    'CBAA' => 'College of Business, Administration and Accountancy',
    'CCS' => 'College of Computer Studies',
    'CCJE' => 'College of Criminal Justice Education',
    'COE' => 'College of Engineering',
    'CIT' => 'College of Industrial Technology',
    'CFND' => 'College of Food, Nutrition and Dietetics',
    'COF' => 'College of Fisheries',
    'CIHTM' => 'College of International Hospitality and Tourism Management',
    'CTE' => 'College of Teacher Education',
    'CONAH' => 'College of Nursing and Allied Health',
    'COL' => 'College of Law'
];

// =========================
// HANDLE REGISTRATION
// =========================
if (isset($_POST['register_request'])) {
    ensureUserNamePartsColumns($con);
    // 2026-09-09 - name is now collected as separate first/middle-
    // initial/last fields (see config.php's buildFullName() comment for
    // the full reasoning) instead of one free-text "full name" input.
    $first_name = clean_input($_POST['first_name'] ?? '');
    $middle_initial = clean_input($_POST['middle_initial'] ?? '');
    $last_name = clean_input($_POST['last_name'] ?? '');
    $name = buildFullName($first_name, $middle_initial, $last_name);
    $emailRaw = clean_input($_POST['email'] ?? '');
    $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);

    $role_type = clean_input($_POST['role'] ?? 'user');
    $department_input = clean_input($_POST['department'] ?? '');
    $position_input = clean_input($_POST['position'] ?? '');
    // 2026-09-03 - Faculty Member registration didn't collect this at all
    // before (designation/position always defaulted to null) - see
    // index.php's $facultyRanks comment for the real feedback that led
    // to this.
    $designation_input = clean_input($_POST['designation'] ?? '');

    if (empty($first_name) || empty($last_name) || !$email || empty($role_type)) {
        $_SESSION['register_error'] = 'Please fill in all required fields correctly.';
        $_SESSION['active_form'] = 'register';
        header("Location: index.php");
        exit();
    }

    // Role-specific validation
    switch ($role_type) {
        case 'user': // Faculty Member
            if (empty($department_input)) {
                $_SESSION['register_error'] = 'Department is required for Faculty Member.';
                $_SESSION['active_form'] = 'register';
                header("Location: index.php");
                exit();
            }
            if (empty($designation_input)) {
                $_SESSION['register_error'] = 'Designation/Position is required for Faculty Member.';
                $_SESSION['active_form'] = 'register';
                header("Location: index.php");
                exit();
            }
            break;

        case 'non_teaching': // Non-Teaching Personnel form identifier lang ito
            if (empty($department_input) || empty($position_input)) {
                $_SESSION['register_error'] = 'Office/Department and Position are required for Non-Teaching Personnel.';
                $_SESSION['active_form'] = 'register';
                header("Location: index.php");
                exit();
            }
            break;

        case 'admin':
            if (empty($position_input)) {
                $_SESSION['register_error'] = 'Position is required for Human Resource Administrator.';
                $_SESSION['active_form'] = 'register';
                header("Location: index.php");
                exit();
            }
            break;

        case 'dean':
            if (empty($department_input)) {
                $_SESSION['register_error'] = 'College/Department is required for College Dean.';
                $_SESSION['active_form'] = 'register';
                header("Location: index.php");
                exit();
            }
            break;

        default:
            $_SESSION['register_error'] = 'Invalid role selected.';
            $_SESSION['active_form'] = 'register';
            header("Location: index.php");
            exit();
    }

    // Check email
    try {
        $stmt = $con->prepare("SELECT id FROM users WHERE email = ?");
        if (!$stmt) {
            throw new Exception("Database error: Unable to prepare email check.");
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $stmt->close();
            $_SESSION['register_error'] = 'Email already registered!';
            $_SESSION['active_form'] = 'register';
            header("Location: index.php");
            exit();
        }

        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['register_error'] = 'Database error: ' . $e->getMessage();
        $_SESSION['active_form'] = 'register';
        header("Location: index.php");
        exit();
    }

    // Generate random password
    try {
        $randomPassword = bin2hex(random_bytes(8));
    } catch (Exception $e) {
        $randomPassword = substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
    }

    $password = password_hash($randomPassword, PASSWORD_DEFAULT);
    $status = 'pending';

    // Final values
    $final_role = 'user';
    $final_department = null;
    $final_position = null;
    $final_teaching_status = null;
    $final_designation = null;

    switch ($role_type) {
        case 'user': // Faculty Member
            $final_role = 'user';
            $final_department = $departmentFullNames[$department_input] ?? $department_input;
            $final_position = null;
            $final_teaching_status = 'Teaching';
            $final_designation = $designation_input;
            break;

        case 'non_teaching': // Non-Teaching Personnel
            $final_role = 'user'; // SAME ROLE AS FACULTY
            $final_department = $department_input;
            $final_position = $position_input;
            // 2026-09-02 fix - was 'Non Teaching' (a space), which can never
            // SQL-match the 'Non-teaching' (hyphen) convention used by
            // admin_page.php's stats queries - see CLAUDE.md/profile.php.
            $final_teaching_status = 'Non-teaching';
            // 2026-09-07 fix - this case never set $final_designation before,
            // so it silently stayed NULL (the default set above the switch)
            // even though the form now collects a real designation/position.
            // profile.php only reads/edits users.designation, not
            // users.position - without this, every non-teaching employee
            // would reach profile.php with the field blank and have to
            // answer the same question a second time.
            $final_designation = $designation_input;
            break;

        case 'admin': // System Administrator
            $final_role = 'admin';
            $final_department = null;
            $final_position = $position_input;
            $final_teaching_status = null;
            break;

        case 'dean': // College Dean
            $final_role = $departmentRoles[$department_input] ?? 'admin';
            $final_department = $departmentFullNames[$department_input] ?? $department_input;
            $final_position = null;
            $final_teaching_status = null;
            break;
    }

    // Insert user
    try {
        $stmt = $con->prepare("
            INSERT INTO users (
                name, first_name, middle_initial, last_name, email, password, role, status, department, position, teaching_status, designation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception("Database error: " . $con->error);
        }

        $stmt->bind_param(
            "ssssssssssss",
            $name,
            $first_name,
            $middle_initial,
            $last_name,
            $email,
            $password,
            $final_role,
            $status,
            $final_department,
            $final_position,
            $final_teaching_status,
            $final_designation
        );

        if ($stmt->execute()) {
            $_SESSION['new_user_password'] = $randomPassword;
            $_SESSION['register_success'] = 'Registration successful! Awaiting approval.';
            $_SESSION['active_form'] = 'login';
        } else {
            throw new Exception("Registration failed: " . $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['register_error'] = 'Registration failed. Please try again. Error: ' . $e->getMessage();
        $_SESSION['active_form'] = 'register';
    }

    header("Location: index.php");
    exit();
}

// =========================
// HANDLE LOGIN
// =========================
if (isset($_POST['login'])) {
    $emailRaw = clean_input($_POST['email'] ?? '');
    $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (!$email || empty($password)) {
        $_SESSION['login_error'] = 'Please enter email and password.';
        $_SESSION['active_form'] = 'login';
        header("Location: index.php");
        exit();
    }

    try {
        $stmt = $con->prepare("
            SELECT id, name, email, password, role, status, department, position, teaching_status
            FROM users
            WHERE email = ?
        ");

        if (!$stmt) {
            throw new Exception("Database error: Unable to prepare login query.");
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                if ($user['status'] === 'accepted') {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department'] = $user['department'];
                    $_SESSION['position'] = $user['position'];
                    $_SESSION['teaching_status'] = $user['teaching_status'];

                    if ($user['role'] === 'admin') {
                        header("Location: admin_page.php");
                        exit();
                    } elseif (strpos($user['role'], 'admin_') === 0) {
                        $dept_code = strtoupper(str_replace('admin_', '', $user['role']));

                        // 2026-09-09 fix - this map previously used bare
                        // filenames with no *_admin/ folder prefix (every
                        // department page actually lives in its own
                        // *_admin/ folder, not project root) and had
                        // 'CIHTM' => 'CIHTM.php', a file that doesn't
                        // exist anywhere (the real file is
                        // CIHTM_admin/CHMT.php). Any login that reached
                        // this branch would have 404'd. Confirmed this
                        // branch isn't reachable through the current
                        // login UI (login_process.php handles real
                        // logins), but fixing it to match the same
                        // folder+file mapping login_process.php already
                        // uses correctly rather than leaving broken code
                        // live in an auth-adjacent file.
                        $departmentFolders = [
                            'CA' => 'CA_admin', 'CAS' => 'CAS_admin', 'CBAA' => 'CBAA_admin',
                            'CCS' => 'CCS_admin', 'CCJE' => 'CCJE_admin', 'COE' => 'COE_admin',
                            'CIT' => 'CIT_admin', 'CFND' => 'CFND_admin', 'COF' => 'COF_admin',
                            'CIHTM' => 'CIHTM_admin', 'CHMT' => 'CIHTM_admin',
                            'CTE' => 'CTE_admin', 'CONAH' => 'CONAH_admin', 'COL' => 'COL_admin'
                        ];
                        $departmentFiles = [
                            'CA' => 'CA.php', 'CAS' => 'CAS.php', 'CBAA' => 'CBAA.php',
                            'CCS' => 'CCS.php', 'CCJE' => 'CCJE.php', 'COE' => 'COE.php',
                            'CIT' => 'CIT.php', 'CFND' => 'CFND.php', 'COF' => 'COF.php',
                            'CIHTM' => 'CHMT.php', 'CHMT' => 'CHMT.php',
                            'CTE' => 'CTE.php', 'CONAH' => 'CONAH.php', 'COL' => 'COL.php'
                        ];

                        if (isset($departmentFolders[$dept_code]) && isset($departmentFiles[$dept_code])) {
                            header("Location: " . $departmentFolders[$dept_code] . '/' . $departmentFiles[$dept_code]);
                        } else {
                            header("Location: user_page.php");
                        }
                        exit();
                    } else {
                        // Faculty Member and Non-Teaching Personnel parehong dito
                        header("Location: user_page.php");
                        exit();
                    }
                } elseif ($user['status'] === 'pending') {
                    $_SESSION['login_error'] = "Account pending approval.";
                } elseif ($user['status'] === 'declined') {
                    $_SESSION['login_error'] = "Account declined. Contact admin.";
                } else {
                    $_SESSION['login_error'] = "Invalid account status.";
                }
            } else {
                $_SESSION['login_error'] = "Incorrect email or password.";
            }
        } else {
            $_SESSION['login_error'] = "Incorrect email or password.";
        }

        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['login_error'] = "Login failed. Try again.";
    }

    $_SESSION['active_form'] = 'login';
    header("Location: index.php");
    exit();
}

// =========================
// HANDLE LOGOUT
// =========================
if (isset($_GET['logout'])) {
    session_destroy();
    session_start();
    $_SESSION['logout_success'] = "You have been logged out.";
    header("Location: index.php");
    exit();
}
?>