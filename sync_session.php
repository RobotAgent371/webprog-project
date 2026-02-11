<?php
session_start();
include "db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['customer_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$customer_id = $_SESSION['customer_id'];

try {
    // Get active session with proper ownership check
    $stmt = $conn->prepare("
        SELECT 
            us.usage_id,
            ps.end_time,
            ps.remaining_minutes,
            ctc.remaining_minutes as credit_minutes
        FROM pc_usage_sessions us
        JOIN pc_stations ps ON us.pc_id = ps.pc_id
        JOIN transactions t ON us.transaction_id = t.transaction_id
        LEFT JOIN customer_time_credits ctc ON t.transaction_id = ctc.transaction_id
        WHERE us.customer_id = ? 
        AND us.status = 'active'
        AND t.customer_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $customer_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $session = $result->fetch_assoc();
        
        // Calculate remaining time
        $remaining_seconds = 0;
        if (!empty($session['end_time'])) {
            $end_time = strtotime($session['end_time']);
            $remaining_seconds = max(0, $end_time - time());
        } elseif (!empty($session['remaining_minutes'])) {
            $remaining_seconds = $session['remaining_minutes'] * 60;
        } elseif (!empty($session['credit_minutes'])) {
            $remaining_seconds = $session['credit_minutes'] * 60;
        }
        
        // Get total remaining credits for this user ONLY
        $credit_stmt = $conn->prepare("
            SELECT SUM(ctc.remaining_minutes) as total_remaining
            FROM customer_time_credits ctc
            JOIN transactions t ON ctc.transaction_id = t.transaction_id
            WHERE t.customer_id = ? 
            AND ctc.is_active = TRUE 
            AND ctc.remaining_minutes > 0
        ");
        $credit_stmt->bind_param("i", $customer_id);
        $credit_stmt->execute();
        $credit_result = $credit_stmt->get_result();
        $credit_data = $credit_result->fetch_assoc();
        $credit_stmt->close();
        
        echo json_encode([
            'success' => true,
            'remainingTime' => $remaining_seconds,
            'remainingCredits' => $credit_data['total_remaining'] ?? 0,
            'sessionActive' => true
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'sessionActive' => false,
            'redirect' => 'client_index.php'
        ]);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>