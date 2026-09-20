<?php
// Auto-detect environment
// A request is "local" if it's literally localhost, OR if it reached this
// server via a private/LAN IP address (e.g. testing from a phone on the
// same WiFi at 192.168.x.x) - both cases mean this is the XAMPP dev copy,
// not the real InfinityFree deployment. Also covers IPv6 loopback (::1) and
// unique-local (fc00::/7, e.g. fd00::...) - Apache resolves "localhost" to
// ::1 first on this machine, which a local reverse proxy / tunnel (e.g.
// cloudflared/ngrok hitting http://localhost) connects through, so SERVER_ADDR
// shows up as ::1 rather than an IPv4 loopback/LAN address. Missing this
// made the app think a tunneled local request was production and try to
// reach the remote InfinityFree DB, failing with "Service Temporarily
// Unavailable" even though the request never left this machine.
$server_addr = $_SERVER['SERVER_ADDR'] ?? '';
$is_private_ip = (bool) preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $server_addr)
    || $server_addr === '::1'
    || (bool) preg_match('/^f[cd][0-9a-f]{2}:/i', $server_addr);

$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' ||
             $server_addr === '127.0.0.1' ||
             strpos($_SERVER['SERVER_NAME'], 'localhost') !== false ||
             $is_private_ip);

// Database configuration based on environment
// ISO 25010 Security audit (2026-09-06): actual credential VALUES now live
// in db_credentials.php (gitignored) - see db_credentials.example.php for
// the template. This file only decides WHICH set to use.
require_once __DIR__ . '/db_credentials.php';

// SerpApi key for the dean's "Search for Providers" research-assist
// feature (search_training_providers.php) - same local/prod value either
// way, unlike the DB credentials above, so this is defined once here
// rather than duplicated in both branches below.
define('SERPAPI_KEY', $serpapi_key ?? '');

if ($is_local) {
    $host     = $local_host;
    $user     = $local_user;
    $password = $local_password;
    $database = $local_database;

    // trainwise-ml FastAPI service (uvicorn app.main:app --port 8000)
    define('ML_API_BASE_URL', 'http://127.0.0.1:8000');
} else {
    $host     = $prod_host;
    $user     = $prod_user;
    $password = $prod_password;
    $database = $prod_database;
}

// Outbound email switch (2026-09-21). notifyUser()/sendNotificationEmail()
// (notification_email.php) and admin_page.php's deadline/approval emails each
// open a synchronous SMTP connection to Gmail per recipient, so any action
// that notifies people (Approve and Notify Requesters, forwarding a demand,
// setting a deadline...) froze the page for seconds while the browser
// waited - it looked like the button had broken during testing.
// false = in-app (bell) notifications only, which are unaffected. Flip to
// true to bring email back; none of the sending code was removed.
define('EMAIL_NOTIFICATIONS_ENABLED', false);

error_reporting(E_ALL);

// Development mode - show errors
// Production mode - hide errors
if ($is_local) {
    ini_set('display_errors', 1);  // Show errors in development
    ini_set('log_errors', 1);
} else {
    ini_set('display_errors', 0);  // Hide errors in production
    ini_set('log_errors', 1);
}

// Error log location - InfinityFree specific
$error_log_path = $_SERVER['DOCUMENT_ROOT'] . '/php-errors.log';
ini_set('error_log', $error_log_path);

// Database Connection
try {
    // Create connection with error reporting
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    
    // Create connection with options for InfinityFree
    $con = new mysqli($host, $user, $password, $database);
    
    if ($con->connect_error) {
        throw new Exception("Database connection failed: " . $con->connect_error);
    }
    
    // Set character encoding
    if (!$con->set_charset("utf8mb4")) {
        throw new Exception("Error loading character set utf8mb4: " . $con->error);
    }
    
    // Set timezone for Philippines
    date_default_timezone_set('Asia/Manila');
    
    // Set SQL mode (optional, InfinityFree might have strict mode)
    $con->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    
} catch (Exception $e) {
    // Log error
    error_log(date('[Y-m-d H:i:s] ') . $e->getMessage());
    
    // User-friendly error message
    if (ini_get('display_errors')) {
        die("<h1>Database Connection Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>");
    } else {
        die("<h1>Service Temporarily Unavailable</h1><p>We're experiencing technical difficulties. Please try again later.</p>");
    }
}

/**
 * ISO 25010 Security audit (2026-09-06): shared CSRF helpers.
 *
 * assessment_form_partial.php already rolled its own version of this
 * (its own $_SESSION['csrf_token'] init + a manual !== compare) - these
 * use the exact same session key so that form keeps working unchanged,
 * this just gives every other endpoint a ready-made, hardened
 * (hash_equals, timing-safe) version to call instead of hand-rolling it
 * again per file. The primary CSRF fix this session is the SameSite=Lax
 * session cookie in .htaccess (verified live - it stops the actual
 * cross-site attack regardless of whether a given endpoint calls these);
 * these functions are for endpoints that want a token as well, in depth.
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify($submittedToken) {
    return isset($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/**
 * 2026-09-09 - name-field normalization. Registration and profile.php
 * used to collect/store a single free-text "full name" field, a known
 * bad-DB-design anti-pattern (can't sort by last name, no clean way to
 * split first/last for reports, etc.) - real LSPU names already follow a
 * "First MI. Last" convention (e.g. "Dexter C. Cosme"), which is exactly
 * what this splits into. Deliberately scoped narrow: only the two real
 * write sites (process_registration.php, profile.php) collect/store the
 * parts as real columns now; every one of the ~100+ places elsewhere in
 * the app that just displays a name keeps reading users.name unchanged -
 * buildFullName() is what keeps that column in sync with the parts on
 * every write, so nothing else needs to change or can regress.
 */
function ensureUserNamePartsColumns($con) {
    $result = $con->query("SHOW COLUMNS FROM users LIKE 'first_name'");
    if ($result && $result->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER name");
        $con->query("ALTER TABLE users ADD COLUMN middle_initial VARCHAR(5) NULL AFTER first_name");
        $con->query("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_initial");
    }
}

/**
 * Combines first/middle-initial/last into the single display string
 * users.name still holds, e.g. ('Dexter', 'C', 'Cosme') -> 'Dexter C.
 * Cosme', or ('Dexter', '', 'Cosme') -> 'Dexter Cosme' when no middle
 * initial was given. Strips a trailing period the person may have typed
 * themselves ("C." vs "C") so it's never doubled - exactly one period is
 * always added here.
 */
function buildFullName($first, $middleInitial, $last) {
    $first = trim($first ?? '');
    $mi = rtrim(trim($middleInitial ?? ''), '.');
    $last = trim($last ?? '');
    $parts = [$first];
    if ($mi !== '') {
        $parts[] = $mi . '.';
    }
    if ($last !== '') {
        $parts[] = $last;
    }
    return trim(implode(' ', $parts));
}

/**
 * Sanitize input for database
 */
function sanitize_input($data) {
    global $con;
    
    if (empty($data) && $data !== '0') {
        return '';
    }
    
    // Trim whitespace
    $data = trim($data);
    
    // Remove slashes if magic quotes are on
    if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
        $data = stripslashes($data);
    }
    
    // Escape for SQL
    $data = mysqli_real_escape_string($con, $data);
    
    return $data;
}

/**
 * Sanitize output for HTML display
 */
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Formats one training_history entry's date as a human-readable string
 * - a single date ("July 3, 2026") or a range ("July 3-5, 2026" /
 * "July 30 - August 2, 2026") when a distinct end_date is present.
 * Returns '' if there's no date at all. Caller is still responsible for
 * htmlspecialchars() on the result, same as it was on the raw date
 * string before - this only changes what string gets formatted, not
 * the escaping contract.
 *
 * 2026-09-03 - training_history entries gained an optional 'end_date'
 * key (real TAM feedback from a live respondent: trainings aren't
 * always one day). Centralized here, in the one file every assessment
 * view already requires, instead of duplicating this same range-
 * formatting logic across the dozen+ dean/PDF views that render this
 * same JSON shape - see assessment_form_partial.php/save_assessment.php
 * for where end_date is actually collected and validated. Fully
 * backward compatible: an entry saved before this existed simply has no
 * end_date key, which falls straight through to the original
 * single-date formatting.
 */
function format_training_date_range($entry) {
    $date = trim($entry['date'] ?? '');
    $endDate = trim($entry['end_date'] ?? '');
    if ($date === '') {
        return '';
    }
    if ($endDate !== '' && $endDate !== $date) {
        $startTs = strtotime($date);
        $endTs = strtotime($endDate);
        if ($startTs !== false && $endTs !== false) {
            if (date('Y-m', $startTs) === date('Y-m', $endTs)) {
                return date('F j', $startTs) . '-' . date('j, Y', $endTs);
            }
            return date('F j', $startTs) . ' - ' . date('F j, Y', $endTs);
        }
    }
    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

/**
 * Execute parameterized query
 */
function execute_query($sql, $params = []) {
    global $con;
    
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $con->error);
    }
    
    if (!empty($params)) {
        $types = str_repeat('s', count($params)); // Default all to string for safety
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    return $stmt;
}

/**
 * Close database connection
 */
function close_db_connection() {
    global $con;
    if (isset($con) && $con instanceof mysqli) {
        $con->close();
        $con = null;
    }
}

// Register shutdown function
register_shutdown_function('close_db_connection');

/**
 * Check if user is logged in (common function)
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect to login if not authenticated
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: login_register.php");
        exit();
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>