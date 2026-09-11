<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $_SESSION['login_error'] = "Please enter a valid email.";
        header("Location: index.php");
        exit();
    }

    // Check if user exists
    $stmt = $con->prepare("SELECT id, name, email, role, department, status FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $_SESSION['login_error'] = "Email not found. Please register first.";
        header("Location: index.php");
        exit();
    }

    $user = $result->fetch_assoc();

    // Check account status
    if ($user['status'] === 'pending') {
        $_SESSION['pending_message'] = "Your account is pending approval. Please wait for admin confirmation.";
        header("Location: index.php");
        exit();
    } elseif ($user['status'] === 'declined') {
        $_SESSION['declined_message'] = "Your registration request was declined. Please contact admin.";
        header("Location: index.php");
        exit();
    } elseif ($user['status'] === 'disabled') {
        // 2026-09-06 - distinct from 'declined' on purpose (see
        // ensureUserDisabledStatus() in admin_page.php): this account WAS
        // accepted and working, an admin turned it off later. Telling this
        // person their "registration was declined" would be wrong and
        // confusing - they never got rejected, they were disabled.
        $_SESSION['disabled_message'] = "Your account has been disabled by the administrator. Please contact admin for assistance.";
        header("Location: index.php");
        exit();
    } elseif ($user['status'] === 'accepted') {
        // Start completely fresh: if this browser already had a session
        // for a DIFFERENT account (e.g. testing multiple accounts without
        // logging out first, or a stale tab from an earlier login), wipe
        // it before writing the new identity in. Without this, any leftover
        // key from the old session could still be read by a page that
        // doesn't strictly re-derive everything from user_id.
        $_SESSION = [];
        session_regenerate_id(true);

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_department'] = $user['department'];
        
        // Department to folder mapping (for department admins only)
        $departmentFolders = [
            'CA' => 'CA_admin',
            'CAS' => 'CAS_admin',
            'CBAA' => 'CBAA_admin',
            'CCS' => 'CCS_admin',
            'CCJE' => 'CCJE_admin',
            'COE' => 'COE_admin',
            'CIT' => 'CIT_admin',
            'CFND' => 'CFND_admin',
            'COF' => 'COF_admin',
            'CIHTM' => 'CIHTM_admin',
            // 2026-09-03 fix - this dean's actual role is 'admin_chmt'
            // (confirmed in the users table), which derives dept_code
            // 'CHMT' - but this map only ever had a 'CIHTM' key, so the
            // lookup always missed and silently fell back to
            // user_page.php (the EMPLOYEE dashboard) instead of this
            // dean's real page. Found live, during actual TAM testing,
            // via a screenshot showing an employee "Complete Your
            // Profile" modal instead of the CHMT dean dashboard.
            // Keeping 'CIHTM' too in case anything else ever keys on it -
            // this only adds the missing entry, doesn't remove the old one.
            'CHMT' => 'CIHTM_admin',
            'CTE' => 'CTE_admin',
            'CONAH' => 'CONAH_admin',
            'COL' => 'COL_admin'
        ];

        // Department to page file mapping (for department admins only)
        $departmentFiles = [
            'CA' => 'CA.php',
            'CAS' => 'CAS.php',
            'CBAA' => 'CBAA.php',
            'CCS' => 'CCS.php',
            'CCJE' => 'CCJE.php',
            'COE' => 'COE.php',
            'CIT' => 'CIT.php',
            'CFND' => 'CFND.php',
            'COF' => 'COF.php',
            'CIHTM' => 'CHMT.php',
            'CHMT' => 'CHMT.php',
            'CTE' => 'CTE.php',
            'CONAH' => 'CONAH.php',
            'COL' => 'COL.php'
        ];
        
        // Helper function to get department page path
        function getDepartmentPath($dept_code, $departmentFolders, $departmentFiles) {
            if (isset($departmentFolders[$dept_code]) && isset($departmentFiles[$dept_code])) {
                $folder = $departmentFolders[$dept_code];
                $file = $departmentFiles[$dept_code];
                $path = $folder . '/' . $file;
                
                // Check if file exists in the folder
                if (file_exists($path)) {
                    return $path;
                } else {
                    // Fallback: check if file exists in root
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
            return null;
        }
        
        // REDIRECT BASED ON USER ROLE ONLY
        
        // 1. System Administrator
        if ($user['role'] === 'admin') {
            header("Location: admin_page.php");
            exit();
        } 
        
        // 2. Department Admins (College Deans) - based on role
        elseif (strpos($user['role'], 'admin_') === 0) {
            // Kunin ang department code mula sa role (e.g., 'admin_ccs' -> 'CCS')
            $dept_code = strtoupper(str_replace('admin_', '', $user['role']));
            
            // Get department page path
            $path = getDepartmentPath($dept_code, $departmentFolders, $departmentFiles);
            
            if ($path !== null) {
                header("Location: " . $path);
            } else {
                // Fallback kung hindi mahanap ang department page
                header("Location: user_page.php");
            }
            exit();
        }
        
        // 3. Teaching Personnel (role = 'user')
        elseif ($user['role'] === 'user') {
            // Redirect to teaching page
            header("Location: user_page.php");
            exit();
        }
        
        // 4. Non-Teaching Personnel (role = 'non_teaching')
        elseif ($user['role'] === 'non_teaching') {
            header("Location: user_page.php");
            exit();
        }
        
        // 5. Default fallback for any other role
        else {
            header("Location: user_page.php");
            exit();
        }
        
    } else {
        $_SESSION['login_error'] = "Invalid account status. Please contact admin.";
        header("Location: index.php");
        exit();
    }
    
    $stmt->close();
}
?>