<?php
// A script to debug user permissions
header('Content-Type: text/plain'); // Set header to display as plain text

require_once 'config.php';
session_start(); // Ensure session is active

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("ERROR: You are not logged in. Please log in to the application first and then access this page.");
}

echo "=========================================\n";
echo "ATS PERMISSION DEBUGGER\n";
echo "=========================================\n\n";

// 1. Display Basic Session Information
echo "---[ 1. SESSION INFO ]---\n";
echo "User ID:   " . htmlspecialchars($_SESSION['user_id']) . "\n";
echo "Full Name: " . htmlspecialchars($_SESSION['full_name']) . "\n";
echo "User Role: " . htmlspecialchars($_SESSION['role']) . "\n\n";

// 2. Display the contents of the permissions array in the session
echo "---[ 2. PERMISSIONS LOADED IN SESSION ]---\n";
if (empty($_SESSION['permissions'])) {
    echo "The \$_SESSION['permissions'] variable is EMPTY.\n";
    echo "This is the root cause of the problem. It means loadPermissions() was not successful during login.\n\n";
} else {
    print_r($_SESSION['permissions']);
    echo "\n";
}

// 3. Directly test the hasPermission() function from auth.php
require_once 'auth.php';
echo "---[ 3. hasPermission() FUNCTION TEST ]---\n";
$has_permission_result = hasPermission('attendance', 'view');
echo "Result of hasPermission('attendance', 'view'): " . ($has_permission_result ? 'TRUE' : 'FALSE') . "\n\n";

// 4. Directly query the database to verify the data exists
echo "---[ 4. DIRECT DATABASE QUERY ]---\n";
$debug_role = strtolower($_SESSION['role']);
echo "Querying 'role_permissions' table for role = '" . htmlspecialchars($debug_role) . "' and resource = 'attendance'...\n";

$stmt = $conn->prepare("SELECT * FROM role_permissions WHERE role = ? AND resource = 'attendance'");
$stmt->bind_param("s", $debug_role);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "SUCCESS: Found a matching record in the database.\n";
    echo "Data: ";
    print_r($result->fetch_assoc());
} else {
    echo "FAILURE: Did not find a matching record for this role and resource in the database.\n";
    echo "This means the INSERT or UPDATE SQL commands did not work as expected.\n";
}
$stmt->close();
echo "\n";

echo "=========================================\n";
echo "END OF DEBUG\n";
echo "=========================================\n";

?>