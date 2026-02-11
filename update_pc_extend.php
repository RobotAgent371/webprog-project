<?php
include "db.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pc_number = intval($_POST['pc_number']);
    $minutes = intval($_POST['minutes']);
    
    // Get current end time and remaining minutes
    $stmt = $conn->prepare("SELECT end_time, remaining_minutes FROM pc_stations WHERE pc_number = ?");
    $stmt->bind_param("i", $pc_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $pc = $result->fetch_assoc();
    $stmt->close();
    
    if ($pc) {
        $new_remaining = $pc['remaining_minutes'] + $minutes;
        $new_end_time = date('Y-m-d H:i:s', strtotime($pc['end_time'] . " +$minutes minutes"));
        
        $stmt = $conn->prepare("UPDATE pc_stations SET 
            end_time = ?,
            remaining_minutes = ?
            WHERE pc_number = ?");
        $stmt->bind_param("sii", $new_end_time, $new_remaining, $pc_number);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => true]);
}
?>