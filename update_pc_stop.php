<?php
include "db.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pc_number = intval($_POST['pc_number']);
    
    $stmt = $conn->prepare("UPDATE pc_stations SET 
        status = 'available', 
        current_customer_id = NULL,
        current_username = NULL, 
        start_time = NULL, 
        end_time = NULL,
        remaining_minutes = 0
        WHERE pc_number = ?");
    $stmt->bind_param("i", $pc_number);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}
?>