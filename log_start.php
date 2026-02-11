<?php
include "db.php";

if (isset($_POST['pc']) && isset($_SESSION["user_id"])) {
    $pc_name = $_POST['pc'];
    $pc_user = $_POST['pc_user'] ?? 'Unknown';
    $admin_id = $_SESSION["user_id"];
    $current_time = date("Y-m-d H:i:s");
    
    // Insert log with PC user (customer)
    $stmt = $conn->prepare(
        "INSERT INTO pc_logs (user_id, pc_user, pc_name, status, login_time) 
         VALUES (?, ?, ?, 'Online', ?)"
    );
    $stmt->bind_param("isss", $admin_id, $pc_user, $pc_name, $current_time);
    $stmt->execute();
}
?>