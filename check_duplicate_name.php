<?php
// 2026-09-09 - registration only ever checked email uniqueness (see
// process_registration.php's "Email already registered!" check), never
// name. Found live during pre-testing QA: registering a second account
// under an already-registered real employee's exact name (different
// email) succeeded silently - no error, no warning, nothing. A hard
// block would be wrong (two different real LSPU staff can genuinely
// share a name - this system already has legitimate examples), so this
// is a soft, pre-submit warning only: index.php's registration forms
// call this before submitting, and let the person confirm/cancel
// themselves rather than being blocked outright. Public/unauthenticated
// on purpose - this runs from the registration page before anyone has
// an account yet, and only ever reveals a yes/no existence check, no
// email or other personal data.
//
// Matches on first_name + last_name (not the combined name string, and
// deliberately ignoring middle_initial) since name is now collected as
// separate fields (see config.php's buildFullName() comment) - someone
// who types their middle initial differently or leaves it off one time
// shouldn't defeat this check.
session_start();
require_once 'config.php';

header('Content-Type: application/json');

ensureUserNamePartsColumns($con);

$firstName = trim($_GET['first_name'] ?? $_POST['first_name'] ?? '');
$lastName = trim($_GET['last_name'] ?? $_POST['last_name'] ?? '');
if ($firstName === '' || $lastName === '') {
    echo json_encode(['exists' => false]);
    exit();
}

$stmt = $con->prepare("SELECT department FROM users WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?)) LIMIT 1");
$stmt->bind_param("ss", $firstName, $lastName);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'exists' => (bool)$row,
    'department' => $row['department'] ?? null,
]);
