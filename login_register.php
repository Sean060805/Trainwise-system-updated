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
    } elseif ($user['status'] === 'accepted') {
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_department'] = $user['department'];
        
        // Department to folder mapping
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
            'CTE' => 'CTE_admin',
            'CONAH' => 'CONAH_admin',
            'COL' => 'COL_admin'
        ];
        
        // Department to page file mapping
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
            'CTE' => 'CTE.php',
            'CONAH' => 'CONAH.php',
            'COL' => 'COL.php'
        ];
        
        // Helper function to get department code from full name
        function getDepartmentCode($dept_name, $departmentFolders) {
            // Check if direct match sa department codes
            if (isset($departmentFolders[$dept_name])) {
                return $dept_name;
            }
            
            // Check if department name contains any of the codes
            foreach ($departmentFolders as $code => $folder) {
                if (strpos($dept_name, $code) !== false) {
                    return $code;
                }
            }
            
            return null;
        }
        
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
        
        // REDIRECT BASED ON ROLE
        
        // 1. System Administrator
        if ($user['role'] === 'admin') {
            header("Location: admin_page.php");
            exit();
        } 
        
        // 2. College Dean
        elseif ($user['role'] === 'dean') {
            // Get department code from user's department
            $dept_code = getDepartmentCode($user['department'], $departmentFolders);
            
            if ($dept_code !== null) {
                $path = getDepartmentPath($dept_code, $departmentFolders, $departmentFiles);
                
                if ($path !== null) {
                    header("Location: " . $path);
                } else {
                    header("Location: user_page.php");
                }
            } else {
                // Fallback if department not found
                header("Location: user_page.php");
            }
            exit();
        }
        
        // 3. Regular Faculty (user)
        elseif ($user['role'] === 'user') {
            // Get department code from user's department
            $dept_code = getDepartmentCode($user['department'], $departmentFolders);
            
            if ($dept_code !== null) {
                $path = getDepartmentPath($dept_code, $departmentFolders, $departmentFiles);
                
                if ($path !== null) {
                    header("Location: " . $path);
                } else {
                    header("Location: user_page.php");
                }
            } else {
                // Default redirect to user page
                header("Location: user_page.php");
            }
            exit();
        }
        
        // 4. Non-Teaching Personnel
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
        // Kung may ibang status (e.g., NULL, empty, etc.)
        $_SESSION['login_error'] = "Invalid account status. Please contact admin.";
        header("Location: index.php");
        exit();
    }
    
    $stmt->close();
}
?>