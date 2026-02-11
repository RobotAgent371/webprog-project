<?php
include "db.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pc_number = intval($_POST['pc_number']);
    $minutes = intval($_POST['minutes']);
    $username = sanitize($_POST['username']);
    
    $stmt = $conn->prepare("UPDATE pc_stations SET 
        remaining_minutes = ?,
        current_username = ?
        WHERE pc_number = ?");
    $stmt->bind_param("isi", $minutes, $username, $pc_number);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}
?>