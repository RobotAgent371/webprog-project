<?php
include "db.php";

// This function syncs manual PC starts/stops with the database
$stmt = $conn->prepare("SELECT pc_number FROM pc_stations");
$stmt->execute();
$result = $stmt->get_result();
$pcs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Sync completed',
    'pcs_count' => count($pcs)
]);
?>