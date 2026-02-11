<?php
// logout.php
session_start();
include "db.php";

// Check if it's a customer or admin logout
if (isset($_SESSION['customer_id'])) {
    // Customer logout - has customer_id session variable
    
    $customer_id = $_SESSION['customer_id'];
    
    try {
        // End any active session
        $stmt = $conn->prepare("
            UPDATE pc_usage_sessions 
            SET status = 'completed', end_time = NOW() 
            WHERE customer_id = ? AND status = 'active'
        ");
        if ($stmt) {
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Update PC stations if customer has an active PC
        $pc_stmt = $conn->prepare("
            UPDATE pc_stations 
            SET status = 'available', 
                current_customer_id = NULL,
                current_username = NULL,
                start_time = NULL,
                end_time = NULL,
                remaining_minutes = 0
            WHERE current_customer_id = ?
        ");
        if ($pc_stmt) {
            $pc_stmt->bind_param("i", $customer_id);
            $pc_stmt->execute();
            $pc_stmt->close();
        }
        
        // Deactivate customer's remaining time credits
        $credit_stmt = $conn->prepare("
            UPDATE customer_time_credits 
            SET is_active = FALSE 
            WHERE customer_id = ? AND is_active = TRUE
        ");
        if ($credit_stmt) {
            $credit_stmt->bind_param("i", $customer_id);
            $credit_stmt->execute();
            $credit_stmt->close();
        }
        
        // Update customer login session
        if (isset($_SESSION['customer_session_id'])) {
            $session_stmt = $conn->prepare("
                UPDATE customer_login_sessions 
                SET logout_time = NOW(), is_active = FALSE 
                WHERE session_id = ?
            ");
            if ($session_stmt) {
                $session_stmt->bind_param("i", $_SESSION['customer_session_id']);
                $session_stmt->execute();
                $session_stmt->close();
            }
        }
    } catch (Exception $e) {
        // Continue with logout even if DB updates fail
        error_log("Customer logout DB error: " . $e->getMessage());
    }
    
    // Clear all session variables
    session_unset();
    
    // Destroy the session
    session_destroy();
    
    // Redirect to customer login
    header("Location: customer_login.php");
    exit();
    
} elseif (isset($_SESSION['user_id'])) {
    // Admin logout - has user_id session variable (from users table)
    
    // Update admin login session in login_sessions table
    if (isset($_SESSION['session_id'])) {
        try {
            $session_stmt = $conn->prepare("
                UPDATE login_sessions 
                SET logout_time = NOW() 
                WHERE session_id = ?
            ");
            
            if ($session_stmt) {
                $session_stmt->bind_param("i", $_SESSION['session_id']);
                $session_stmt->execute();
                $session_stmt->close();
            }
        } catch (Exception $e) {
            error_log("Admin logout update error: " . $e->getMessage());
        }
    }
    
    // Clear all session variables
    session_unset();
    
    // Destroy the session
    session_destroy();
    
    header("Location: login.php"); // Redirect to admin login page
    exit();
    
} else {
    // No session, redirect to customer login
    header("Location: customer_login.php");
    exit();
}
?>