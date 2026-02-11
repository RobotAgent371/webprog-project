<?php

$conn = new mysqli("localhost", "root", "", "pc_cafe");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to sanitize input
function sanitize($input) {
    global $conn;
    return mysqli_real_escape_string($conn, htmlspecialchars(trim($input)));
}

// Check if customer is logged in
function isCustomerLoggedIn() {
    return isset($_SESSION['customer_id']) && isset($_SESSION['username']);
}

// Check if admin is logged in
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_username']);
}

// Redirect if customer not logged in
function requireCustomerLogin() {
    if (!isCustomerLoggedIn()) {
        header("Location: customer_login.php");
        exit();
    }
}

// Redirect if admin not logged in
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header("Location: admin_login.php");
        exit();
    }
}

// Logout function for customer
function customerLogout() {
    if (isset($_SESSION['session_id'])) {
        global $conn;
        $session_id = $_SESSION['session_id'];
        $stmt = $conn->prepare("UPDATE customer_login_sessions SET logout_time = NOW(), is_active = FALSE WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $stmt->close();
    }
    
    session_unset();
    session_destroy();
    header("Location: customer_login.php");
    exit();
}

// Logout function for admin
function adminLogout() {
    session_unset();
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// Add this function to db.php or create separate audit.php
function log_audit($customer_id, $action, $details, $conn) {
    $stmt = $conn->prepare("INSERT INTO audit_logs (customer_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt->bind_param("issss", $customer_id, $action, $details, $ip, $agent);
    $stmt->execute();
    $stmt->close();
}
?>

