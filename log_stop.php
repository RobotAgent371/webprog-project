<?php
include "db.php";

if (isset($_POST['pc']) && isset($_SESSION["user_id"])) {
    $pc_name = $_POST['pc'];
    $pc_user = $_POST['pc_user'] ?? 'Unknown';
    $admin_id = $_SESSION["user_id"];
    $current_time = date("Y-m-d H:i:s");
    
    // Update the most recent Online log for this PC
    $stmt = $conn->prepare(
        "UPDATE pc_logs 
         SET status = 'Offline', logout_time = ?
         WHERE pc_name = ? AND status = 'Online' 
         ORDER BY login_time DESC LIMIT 1"
    );
    $stmt->bind_param("ss", $current_time, $pc_name);
    $stmt->execute();
}
?>