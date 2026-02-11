<?php
include "db.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pc_number = intval($_POST['pc_number']);
    $status = sanitize($_POST['status']);
    
    $stmt = $conn->prepare("UPDATE pc_stations SET status = ? WHERE pc_number = ?");
    $stmt->bind_param("si", $status, $pc_number);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}
?>