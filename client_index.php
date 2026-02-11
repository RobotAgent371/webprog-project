<?php
// Start session at the VERY TOP
session_start();

include "db.php";

// Initialize variables
$error = '';
$success = '';
$active_session = null;
$time_credits = [];
$all_pcs = [];

// Check if user is logged in (basic check)
if (!isset($_SESSION['customer_id']) || !isset($_SESSION['username'])) {
    // Redirect to login if not logged in
    header("Location: login.php");
    exit();
}

// Helper function for safe prepare statements
function prepareStatement($conn, $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error . " | Query: " . $sql);
    }
    return $stmt;
}

// Get available PCs
$available_pcs = [];
$stmt = prepareStatement($conn, "SELECT pc_id, pc_number, status FROM pc_stations WHERE status = 'available' ORDER BY pc_number");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_pcs[] = $row;
}
$stmt->close();

// Get time credits
$time_credits = [];
$stmt = prepareStatement($conn, "SELECT credit_id, credit_name, credit_type, duration_minutes, cost_php FROM time_credits WHERE is_active = TRUE ORDER BY duration_minutes");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $time_credits[] = $row;
}
$stmt->close();

// Get customer's active session
$active_session = null;
$stmt = prepareStatement($conn, "SELECT * FROM pc_usage_sessions WHERE customer_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $_SESSION['customer_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $active_session = $result->fetch_assoc();
}
$stmt->close();

// Handle purchase request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['purchase'])) {
    $pc_id = intval($_POST['pc_id']);
    $credit_type = sanitize($_POST['credit_type']);
    $quantity = intval($_POST['quantity']);
    $payment_method = sanitize($_POST['payment_method']);
    
    try {
        // Begin transaction
        $conn->begin_transaction();
        
        // Get credit details with locking to prevent race conditions
        $credit_stmt = prepareStatement($conn, "SELECT credit_id, duration_minutes, cost_php FROM time_credits WHERE credit_type = ? FOR UPDATE");
        $credit_stmt->bind_param("s", $credit_type);
        $credit_stmt->execute();
        $credit_result = $credit_stmt->get_result();
        $credit = $credit_result->fetch_assoc();
        $credit_stmt->close();
        
        if (!$credit) {
            throw new Exception("Invalid credit type: " . htmlspecialchars($credit_type));
        }
        
        $total_amount = $credit['cost_php'] * $quantity;
        $total_minutes = $credit['duration_minutes'] * $quantity;
        $current_time = date('Y-m-d H:i:s');
        
        // CHECK: Verify the PC is available
        $pc_check = prepareStatement($conn, "SELECT status, current_customer_id FROM pc_stations WHERE pc_id = ?");
        $pc_check->bind_param("i", $pc_id);
        $pc_check->execute();
        $pc_result = $pc_check->get_result();
        $pc_data = $pc_result->fetch_assoc();
        $pc_check->close();
        
        if (!$pc_data || $pc_data['status'] !== 'available') {
            throw new Exception("PC is not available");
        }
        
        // Insert transaction
        $trans_stmt = prepareStatement($conn, "INSERT INTO transactions (customer_id, pc_id, credit_id, quantity, amount, transaction_type, payment_method, transaction_status) VALUES (?, ?, ?, ?, ?, 'purchase', ?, 'completed')");
        $trans_stmt->bind_param("iiiids", $_SESSION['customer_id'], $pc_id, $credit['credit_id'], $quantity, $total_amount, $payment_method);
        $trans_stmt->execute();
        $transaction_id = $conn->insert_id;
        $trans_stmt->close();
        
        // Update PC status
        $pc_stmt = prepareStatement($conn, "UPDATE pc_stations SET 
            status = 'occupied', 
            current_customer_id = ?, 
            current_username = ?,
            start_time = ?,
            end_time = DATE_ADD(?, INTERVAL ? MINUTE),
            remaining_minutes = ?
            WHERE pc_id = ? AND status = 'available'");
        $pc_stmt->bind_param("issiiii", $_SESSION['customer_id'], $_SESSION['username'], $current_time, $current_time, $total_minutes, $total_minutes, $pc_id);
        $pc_stmt->execute();
        
        if ($pc_stmt->affected_rows === 0) {
            throw new Exception("Failed to reserve PC - it may have been taken by another user");
        }
        $pc_stmt->close();
        
        // Create usage session
        $usage_stmt = prepareStatement($conn, "INSERT INTO pc_usage_sessions (customer_id, pc_id, transaction_id, time_credits_used) VALUES (?, ?, ?, ?)");
        $usage_stmt->bind_param("iiii", $_SESSION['customer_id'], $pc_id, $transaction_id, $quantity);
        $usage_stmt->execute();
        $usage_session_id = $conn->insert_id;
        $usage_stmt->close();
        
        // Add time credits to customer
        $credit_stmt = prepareStatement($conn, "INSERT INTO customer_time_credits (customer_id, credit_id, transaction_id, quantity, total_amount, remaining_minutes, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $credit_stmt->bind_param("iiiidi", $_SESSION['customer_id'], $credit['credit_id'], $transaction_id, $quantity, $total_amount, $total_minutes);
        $credit_stmt->execute();
        $credit_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $success = "Purchase successful! Transaction ID: $transaction_id. You can now use PC $pc_id for $total_minutes minutes.";
        header("refresh:2;url=client_index.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Transaction failed: " . $e->getMessage();
    }
}

// Handle time extension
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['extend'])) {
    $pc_id = intval($_POST['extend_pc_id']);
    $credit_type = sanitize($_POST['extend_credit_type']);
    $quantity = intval($_POST['extend_quantity']);
    $payment_method = sanitize($_POST['extend_payment_method']);
    
    try {
        $conn->begin_transaction();
        
        // VERIFY: Check that the PC belongs to this user
        $verify_stmt = prepareStatement($conn, "SELECT pc_id FROM pc_stations WHERE pc_id = ? AND current_customer_id = ?");
        $verify_stmt->bind_param("ii", $pc_id, $_SESSION['customer_id']);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            throw new Exception("You don't have an active session on this PC");
        }
        $verify_stmt->close();
        
        // Get credit details
        $credit_stmt = prepareStatement($conn, "SELECT credit_id, duration_minutes, cost_php FROM time_credits WHERE credit_type = ?");
        $credit_stmt->bind_param("s", $credit_type);
        $credit_stmt->execute();
        $credit_result = $credit_stmt->get_result();
        $credit = $credit_result->fetch_assoc();
        $credit_stmt->close();
        
        if (!$credit) {
            throw new Exception("Invalid credit type: " . htmlspecialchars($credit_type));
        }
        
        $total_amount = $credit['cost_php'] * $quantity;
        $minutes_added = $credit['duration_minutes'] * $quantity;
        
        // Insert transaction
        $trans_stmt = prepareStatement($conn, "INSERT INTO transactions (customer_id, pc_id, credit_id, quantity, amount, transaction_type, payment_method, transaction_status) VALUES (?, ?, ?, ?, ?, 'time_extension', ?, 'completed')");
        $trans_stmt->bind_param("iiiids", $_SESSION['customer_id'], $pc_id, $credit['credit_id'], $quantity, $total_amount, $payment_method);
        $trans_stmt->execute();
        $transaction_id = $conn->insert_id;
        $trans_stmt->close();
        
        // Update PC end time
        $update_pc = prepareStatement($conn, "UPDATE pc_stations SET end_time = DATE_ADD(end_time, INTERVAL ? MINUTE), remaining_minutes = remaining_minutes + ? WHERE pc_id = ? AND current_customer_id = ?");
        $update_pc->bind_param("iiii", $minutes_added, $minutes_added, $pc_id, $_SESSION['customer_id']);
        $update_pc->execute();
        
        if ($update_pc->affected_rows === 0) {
            throw new Exception("Failed to extend time - session may have ended");
        }
        $update_pc->close();
        
        // Add time credits to customer
        $credit_stmt = prepareStatement($conn, "INSERT INTO customer_time_credits (customer_id, credit_id, transaction_id, quantity, total_amount, remaining_minutes, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $credit_stmt->bind_param("iiiidi", $_SESSION['customer_id'], $credit['credit_id'], $transaction_id, $quantity, $total_amount, $minutes_added);
        $credit_stmt->execute();
        $credit_stmt->close();
        
        // Update usage session
        $usage_stmt = prepareStatement($conn, "UPDATE pc_usage_sessions SET time_credits_used = time_credits_used + ? WHERE customer_id = ? AND pc_id = ? AND status = 'active'");
        $usage_stmt->bind_param("iii", $quantity, $_SESSION['customer_id'], $pc_id);
        $usage_stmt->execute();
        $usage_stmt->close();
        
        $conn->commit();
        
        $success = "Time extended successfully! Added $minutes_added minutes.";
        header("refresh:2;url=client_index.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Time extension failed: " . $e->getMessage();
    }
}

// Sanitize function (if not already defined in db.php)
if (!function_exists('sanitize')) {
    function sanitize($data) {
        global $conn;
        return mysqli_real_escape_string($conn, htmlspecialchars(strip_tags(trim($data))));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Pancit Cantoners</title>
    <link rel="stylesheet" href="customer_style.css">
    <link rel="stylesheet" href="client_index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <a href="logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
    
    <div class="dashboard-wrapper">
        <div class="user-info">
            <div class="user-details">
                <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
                <p>Username: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
            </div>
            <div class="balance">
                <i class="fas fa-wallet"></i> ₱<?php echo isset($_SESSION['balance']) ? number_format($_SESSION['balance'], 2) : '0.00'; ?>
            </div>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($active_session): ?>
            <!-- Active Session Display -->
            <div class="active-session">
                <h3 class="section-title"><i class="fas fa-desktop"></i> Active Session</h3>
                <div class="session-info">
                    <?php
                    // Get PC info
                    $stmt = prepareStatement($conn, "SELECT pc_number FROM pc_stations WHERE pc_id = ?");
                    $stmt->bind_param("i", $active_session['pc_id']);
                    $stmt->execute();
                    $pc_result = $stmt->get_result();
                    $pc_info = $pc_result->fetch_assoc();
                    $stmt->close();
                    ?>
                    <div class="info-item">
                        <div class="info-label">PC Number</div>
                        <div class="info-value">PC <?php echo htmlspecialchars($pc_info['pc_number']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Start Time</div>
                        <div class="info-value"><?php echo date('h:i A', strtotime($active_session['start_time'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Time Used</div>
                        <div class="info-value"><?php echo htmlspecialchars($active_session['total_minutes_used']); ?> minutes</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Credits Used</div>
                        <div class="info-value"><?php echo htmlspecialchars($active_session['time_credits_used']); ?> credits</div>
                    </div>
                </div>
                <button class="btn" onclick="window.location.href='client.php'">
                    <i class="fas fa-play-circle"></i> Go to Session
                </button>
            </div>
        <?php else: ?>
            <!-- PC Selection -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-desktop"></i> Select PC</h3>
                <div class="pc-grid">
                    <?php
                    // Get all PCs with status
                    $stmt = prepareStatement($conn, "SELECT pc_id, pc_number, status FROM pc_stations ORDER BY pc_number");
                    $stmt->execute();
                    $all_pcs = $stmt->get_result();
                    
                    while ($pc = $all_pcs->fetch_assoc()):
                    ?>
                    <div class="pc-card <?php echo $pc['status'] !== 'available' ? 'unavailable' : ''; ?>" 
                         onclick="selectPC(<?php echo $pc['pc_id']; ?>, '<?php echo $pc['status']; ?>')" 
                         id="pc-<?php echo $pc['pc_id']; ?>">
                        <div class="pc-number">PC <?php echo htmlspecialchars($pc['pc_number']); ?></div>
                        <div class="pc-status status-<?php echo htmlspecialchars($pc['status']); ?>">
                            <?php echo ucfirst($pc['status']); ?>
                        </div>
                    </div>
                    <?php endwhile; 
                    $stmt->close();
                    ?>
                </div>
            </div>
            
            <!-- Time Credit Selection -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-clock"></i> Select Time Credit</h3>
                <div class="credit-options">
                    <?php foreach ($time_credits as $credit): ?>
                    <div class="credit-card" onclick="selectCredit('<?php echo htmlspecialchars($credit['credit_type']); ?>', <?php echo $credit['cost_php']; ?>, <?php echo $credit['duration_minutes']; ?>)"
                         id="credit-<?php echo htmlspecialchars($credit['credit_type']); ?>">
                        <div class="credit-name"><?php echo htmlspecialchars($credit['credit_name']); ?></div>
                        <div class="credit-duration"><?php echo htmlspecialchars($credit['duration_minutes']); ?> minutes</div>
                        <div class="credit-price">₱<?php echo number_format($credit['cost_php'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Quantity Selection -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-calculator"></i> Quantity</h3>
                <div class="quantity-selector">
                    <button type="button" class="quantity-btn" onclick="changeQuantity(-1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <div class="quantity-display" id="quantityDisplay">1</div>
                    <button type="button" class="quantity-btn" onclick="changeQuantity(1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            
            <!-- Payment Method -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-credit-card"></i> Payment Method</h3>
                <div class="payment-methods">
                    <div class="payment-method" onclick="selectPayment('cash')" id="payment-cash">
                        <i class="fas fa-money-bill-wave"></i><br>
                        Cash
                    </div>
                    <div class="payment-method" onclick="selectPayment('gcash')" id="payment-gcash">
                        <i class="fas fa-mobile-alt"></i><br>
                        GCash
                    </div>
                    <div class="payment-method" onclick="selectPayment('paymaya')" id="payment-paymaya">
                        <i class="fas fa-credit-card"></i><br>
                        PayMaya
                    </div>
                    <div class="payment-method" onclick="selectPayment('card')" id="payment-card">
                        <i class="fas fa-credit-card"></i><br>
                        Card
                    </div>
                </div>
            </div>
            
            <!-- Total Amount -->
            <div class="total-amount" id="totalAmount">
                Total: ₱0.00
            </div>
            
            <!-- Purchase Form -->
            <form id="purchaseForm" method="POST" action="">
                <input type="hidden" name="purchase" value="1">
                <input type="hidden" name="pc_id" id="selectedPC" value="">
                <input type="hidden" name="credit_type" id="selectedCredit" value="">
                <input type="hidden" name="quantity" id="selectedQuantity" value="1">
                <input type="hidden" name="payment_method" id="selectedPayment" value="">
                
                <button type="submit" class="btn" id="purchaseBtn" disabled>
                    <i class="fas fa-shopping-cart"></i> Purchase & Start Session
                </button>
            </form>
        <?php endif; ?>
        
        <!-- Time Extension Form (if needed) -->
        <?php if ($active_session): ?>
        <div class="section" style="margin-top: 30px;">
            <h3 class="section-title"><i class="fas fa-plus-circle"></i> Extend Session</h3>
            <form method="POST" action="">
                <input type="hidden" name="extend" value="1">
                <input type="hidden" name="extend_pc_id" value="<?php echo htmlspecialchars($active_session['pc_id']); ?>">
                
                <div class="credit-options">
                    <?php foreach ($time_credits as $credit): ?>
                    <div class="credit-card" onclick="selectExtendCredit('<?php echo htmlspecialchars($credit['credit_type']); ?>', <?php echo $credit['cost_php']; ?>)" 
                         id="extend-credit-<?php echo htmlspecialchars($credit['credit_type']); ?>">
                        <div class="credit-name"><?php echo htmlspecialchars($credit['credit_name']); ?></div>
                        <div class="credit-duration"><?php echo htmlspecialchars($credit['duration_minutes']); ?> minutes</div>
                        <div class="credit-price">₱<?php echo number_format($credit['cost_php'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="quantity-selector">
                    <button type="button" class="quantity-btn" onclick="changeExtendQuantity(-1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <div class="quantity-display" id="extendQuantityDisplay">1</div>
                    <button type="button" class="quantity-btn" onclick="changeExtendQuantity(1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                
                <input type="hidden" name="extend_credit_type" id="selectedExtendCredit" value="">
                <input type="hidden" name="extend_quantity" id="selectedExtendQuantity" value="1">
                <input type="hidden" name="extend_payment_method" value="cash">
                
                <div class="total-amount" id="extendTotalAmount">
                    Total: ₱0.00
                </div>
                
                <button type="submit" class="btn" id="extendBtn" disabled>
                    <i class="fas fa-clock"></i> Extend Time
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let selectedPC = null;
        let selectedCredit = null;
        let selectedPayment = null;
        let quantity = 1;
        let creditPrice = 0;
        let creditDuration = 0;
        
        let extendSelectedCredit = null;
        let extendQuantity = 1;
        let extendCreditPrice = 0;
        
        function selectPC(pcId, status) {
            if (status !== 'available') {
                alert('This PC is not available');
                return;
            }
            
            // Remove selection from all PCs
            document.querySelectorAll('.pc-card').forEach(pc => {
                pc.classList.remove('selected');
            });
            
            // Add selection to clicked PC
            const pcElement = document.getElementById('pc-' + pcId);
            if (pcElement) {
                pcElement.classList.add('selected');
                selectedPC = pcId;
                document.getElementById('selectedPC').value = pcId;
                checkPurchaseReady();
            }
        }
        
        function selectCredit(creditType, price, duration) {
            // Remove selection from all credits
            document.querySelectorAll('.credit-card').forEach(credit => {
                credit.classList.remove('selected');
            });
            
            // Add selection to clicked credit
            const creditElement = document.getElementById('credit-' + creditType);
            if (creditElement) {
                creditElement.classList.add('selected');
                selectedCredit = creditType;
                creditPrice = price;
                creditDuration = duration;
                document.getElementById('selectedCredit').value = creditType;
                updateTotalAmount();
                checkPurchaseReady();
            }
        }
        
        function selectPayment(method) {
            // Remove selection from all payment methods
            document.querySelectorAll('.payment-method').forEach(payment => {
                payment.classList.remove('selected');
            });
            
            // Add selection to clicked payment method
            const paymentElement = document.getElementById('payment-' + method);
            if (paymentElement) {
                paymentElement.classList.add('selected');
                selectedPayment = method;
                document.getElementById('selectedPayment').value = method;
                checkPurchaseReady();
            }
        }
        
        function changeQuantity(change) {
            const newQuantity = quantity + change;
            if (newQuantity >= 1 && newQuantity <= 10) {
                quantity = newQuantity;
                document.getElementById('quantityDisplay').textContent = quantity;
                document.getElementById('selectedQuantity').value = quantity;
                updateTotalAmount();
            }
        }
        
        function updateTotalAmount() {
            if (selectedCredit && creditPrice > 0) {
                const total = creditPrice * quantity;
                const totalElement = document.getElementById('totalAmount');
                if (totalElement) {
                    totalElement.innerHTML = 
                        `Total: ₱${total.toFixed(2)}<br><small>(${creditDuration * quantity} minutes)</small>`;
                }
            }
        }
        
        function checkPurchaseReady() {
            const purchaseBtn = document.getElementById('purchaseBtn');
            if (purchaseBtn) {
                purchaseBtn.disabled = !(selectedPC && selectedCredit && selectedPayment);
            }
        }
        
        // Extend session functions
        function selectExtendCredit(creditType, price) {
            // Remove selection from all extend credits
            document.querySelectorAll('.credit-card').forEach(credit => {
                credit.classList.remove('selected');
            });
            
            // Add selection to clicked credit
            const creditElement = document.getElementById('extend-credit-' + creditType);
            if (creditElement) {
                creditElement.classList.add('selected');
                extendSelectedCredit = creditType;
                extendCreditPrice = price;
                document.getElementById('selectedExtendCredit').value = creditType;
                updateExtendTotalAmount();
                checkExtendReady();
            }
        }
        
        function changeExtendQuantity(change) {
            const newQuantity = extendQuantity + change;
            if (newQuantity >= 1 && newQuantity <= 10) {
                extendQuantity = newQuantity;
                const quantityDisplay = document.getElementById('extendQuantityDisplay');
                const selectedQuantity = document.getElementById('selectedExtendQuantity');
                if (quantityDisplay && selectedQuantity) {
                    quantityDisplay.textContent = extendQuantity;
                    selectedQuantity.value = extendQuantity;
                    updateExtendTotalAmount();
                }
            }
        }
        
        function updateExtendTotalAmount() {
            if (extendSelectedCredit && extendCreditPrice > 0) {
                const total = extendCreditPrice * extendQuantity;
                const totalElement = document.getElementById('extendTotalAmount');
                if (totalElement) {
                    totalElement.textContent = `Total: ₱${total.toFixed(2)}`;
                }
            }
        }
        
        function checkExtendReady() {
            const extendBtn = document.getElementById('extendBtn');
            if (extendBtn) {
                extendBtn.disabled = !extendSelectedCredit;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            checkPurchaseReady();
            checkExtendReady();
        });
    </script>
</body>
</html>