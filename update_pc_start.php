<?php
include "db.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pc_number = intval($_POST['pc_number']);
    $username = sanitize($_POST['username']);
    $minutes = intval($_POST['minutes']);
    $is_registered = isset($_POST['is_registered']) ? intval($_POST['is_registered']) : 0;
    
    // Calculate end time
    $end_time = date('Y-m-d H:i:s', strtotime("+$minutes minutes"));
    
    if ($is_registered) {
        // For registered customers, get customer_id from username
        $customer_stmt = $conn->prepare("SELECT customer_id FROM customer_accounts WHERE username = ?");
        $customer_stmt->bind_param("s", $username);
        $customer_stmt->execute();
        $customer_result = $customer_stmt->get_result();
        
        if ($customer_result->num_rows > 0) {
            $customer = $customer_result->fetch_assoc();
            $customer_id = $customer['customer_id'];
        } else {
            $customer_id = null;
        }
        $customer_stmt->close();
    } else {
        $customer_id = null;
    }
    
    $stmt = $conn->prepare("UPDATE pc_stations SET 
        status = 'occupied', 
        current_customer_id = ?,
        current_username = ?, 
        start_time = NOW(), 
        end_time = ?,
        remaining_minutes = ?
        WHERE pc_number = ?");
    $stmt->bind_param("issii", $customer_id, $username, $end_time, $minutes, $pc_number);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}
?>