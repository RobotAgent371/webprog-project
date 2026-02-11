<?php
include "db.php";
session_start();

// Check if customer is logged in
if (!isset($_SESSION['customer_id'])) {
    header("Location: customer_login.php");
    exit();
}

// Get customer's active session with STRONG VERIFICATION
$customer_id = $_SESSION['customer_id'];
$active_session = null;

// FIXED SQL QUERY - Removed JOIN on transactions table since transaction_id column doesn't exist
$stmt = $conn->prepare("
    SELECT 
        ps.*,
        us.*,
        ca.username,
        ca.full_name,
        tc.credit_name,
        tc.duration_minutes,
        tc.cost_php,
        ctc.remaining_minutes as credit_remaining
    FROM pc_usage_sessions us
    JOIN pc_stations ps ON us.pc_id = ps.pc_id
    JOIN customer_accounts ca ON us.customer_id = ca.customer_id
    LEFT JOIN customer_time_credits ctc ON us.customer_id = ctc.customer_id AND ctc.is_active = TRUE
    LEFT JOIN time_credits tc ON ctc.credit_id = tc.credit_id
    WHERE us.customer_id = ? 
    AND us.status = 'active'
    AND ps.current_customer_id = ?
    ORDER BY us.start_time DESC 
    LIMIT 1
");

// FIXED: Changed from 3 parameters to 2 parameters
$stmt->bind_param("ii", $customer_id, $customer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $active_session = $result->fetch_assoc();
} else {
    // Log security issue
    error_log("SECURITY: Customer $customer_id tried to access session not belonging to them");
    header("Location: client_index.php");
    exit();
}
$stmt->close();

// DEBUG: Check what data we're getting
// echo "<pre>"; print_r($active_session); echo "</pre>";

// Calculate remaining time - FIXED VERSION
$current_time = time();

// Method 1: Use pc_stations end_time if available
if (!empty($active_session['end_time'])) {
    $end_time = strtotime($active_session['end_time']);
    $remaining_seconds = max(0, $end_time - $current_time);
    
    // Calculate start time from end time and remaining minutes
    if (!empty($active_session['remaining_minutes'])) {
        $total_minutes = $active_session['remaining_minutes'];
        $start_time = $end_time - ($total_minutes * 60);
    } else {
        $start_time = strtotime($active_session['start_time']);
    }
} 
// Method 2: Use start_time and calculate from time credits
elseif (!empty($active_session['start_time']) && !empty($active_session['duration_minutes'])) {
    $start_time = strtotime($active_session['start_time']);
    $total_minutes = $active_session['duration_minutes'] * $active_session['time_credits_used'];
    $end_time = $start_time + ($total_minutes * 60);
    $remaining_seconds = max(0, $end_time - $current_time);
}
// Method 3: Use remaining minutes from customer_time_credits
elseif (!empty($active_session['credit_remaining'])) {
    $remaining_seconds = $active_session['credit_remaining'] * 60;
    $start_time = time() - 60; // Assume started 1 minute ago if we don't have start time
    $end_time = $start_time + $remaining_seconds;
}
// Default fallback
else {
    // Default to 1 hour if no data found
    $start_time = time() - 60; // Started 1 minute ago
    $end_time = $start_time + 3600; // 1 hour total
    $remaining_seconds = 3540; // 59 minutes remaining
}

$total_seconds = $end_time - $start_time;
$percentage = ($remaining_seconds / $total_seconds) * 100;

// Ensure remaining_seconds is not negative
$remaining_seconds = max(0, $remaining_seconds);

// Calculate formatted times
$total_minutes = floor($total_seconds / 60);
$hours = floor($total_minutes / 60);
$minutes = $total_minutes % 60;

// Get customer's remaining time credits
$remaining_credits_stmt = $conn->prepare("
    SELECT SUM(remaining_minutes) as total_remaining
    FROM customer_time_credits 
    WHERE customer_id = ? AND is_active = TRUE AND remaining_minutes > 0
");
$remaining_credits_stmt->bind_param("i", $customer_id);
$remaining_credits_stmt->execute();
$remaining_credits_result = $remaining_credits_stmt->get_result();
$remaining_credits = $remaining_credits_result->fetch_assoc();
$remaining_credits_stmt->close();
$total_remaining_minutes = $remaining_credits['total_remaining'] ?? 0;

// Handle time extension request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['extend_request'])) {
    // Store extension request
    $_SESSION['extension_requested'] = true;
    
    // Get selected time and price from POST if available
    if (isset($_POST['extension_minutes']) && isset($_POST['extension_price'])) {
        $_SESSION['extension_minutes'] = intval($_POST['extension_minutes']);
        $_SESSION['extension_price'] = floatval($_POST['extension_price']);
    }
    
    $extension_message = "Time extension request sent to admin. Please wait for approval.";
}

// Handle logout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['logout'])) {
    // End the session
    $update_stmt = $conn->prepare("
        UPDATE pc_usage_sessions 
        SET status = 'completed', end_time = NOW() 
        WHERE usage_id = ?
    ");
    $update_stmt->bind_param("i", $active_session['usage_id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Update PC status
    $pc_stmt = $conn->prepare("
        UPDATE pc_stations 
        SET status = 'available', 
            current_customer_id = NULL,
            current_username = NULL,
            start_time = NULL,
            end_time = NULL,
            remaining_minutes = 0
        WHERE pc_id = ?
    ");
    $pc_stmt->bind_param("i", $active_session['pc_id']);
    $pc_stmt->execute();
    $pc_stmt->close();
    
    // Update customer_time_credits to mark as used
    if (!empty($active_session['credit_remaining'])) {
        $credit_stmt = $conn->prepare("
            UPDATE customer_time_credits 
            SET remaining_minutes = 0, is_active = FALSE 
            WHERE customer_id = ? AND is_active = TRUE
        ");
        $credit_stmt->bind_param("i", $customer_id);
        $credit_stmt->execute();
        $credit_stmt->close();
    }
    
    // Clear session data
    if (isset($_SESSION['extension_requested'])) {
        unset($_SESSION['extension_requested']);
    }
    if (isset($_SESSION['extension_minutes'])) {
        unset($_SESSION['extension_minutes']);
    }
    if (isset($_SESSION['extension_price'])) {
        unset($_SESSION['extension_price']);
    }
    
    // Redirect to client index
    header("Location: client_index.php");
    exit();
}

// Determine session type based on time credits used
$session_type = "Regular Session";
if (!empty($active_session['credit_name'])) {
    $session_type = $active_session['credit_name'] . " (" . $active_session['duration_minutes'] . " mins)";
}

// DEBUG: Output time calculations for troubleshooting
// echo "<script>console.log('PHP Debug - remaining_seconds: " . $remaining_seconds . "');</script>";
// echo "<script>console.log('PHP Debug - total_seconds: " . $total_seconds . "');</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Session - Pancit Cantoners</title>
    <link rel="stylesheet" href="Client.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .auto-started-badge {
            background: linear-gradient(135deg, #2ed573, #0066cc);
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        .time-credit-info {
            background: rgba(46, 213, 115, 0.1);
            border: 2px solid rgba(46, 213, 115, 0.3);
            border-radius: 15px;
            padding: 15px;
            margin-top: 15px;
            text-align: center;
        }
        
        .credit-summary {
            display: flex;
            justify-content: space-around;
            margin-top: 10px;
        }
        
        .credit-item {
            text-align: center;
        }
        
        .credit-label {
            font-size: 12px;
            color: #666;
            font-weight: 600;
        }
        
        .credit-value {
            font-size: 18px;
            font-weight: 700;
            color: #2ed573;
        }
        
        .timer-auto-status {
            font-size: 14px;
            color: #2ed573;
            margin-top: 5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .extension-notification {
            background: rgba(241, 196, 15, 0.15);
            border: 2px solid rgba(241, 196, 15, 0.3);
            border-radius: 15px;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .debug-info {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            display: none; /* Set to 'block' to debug */
        }
    </style>
</head>
<body>
    <!-- Debug information -->
    <div class="debug-info" id="debugInfo">
        Remaining: <span id="debugRemaining"><?php echo $remaining_seconds; ?></span>s<br>
        Total: <span id="debugTotal"><?php echo $total_seconds; ?></span>s<br>
        Start: <?php echo date('H:i:s', $start_time); ?><br>
        End: <?php echo date('H:i:s', $end_time); ?>
    </div>

    <div class="client-container">
        <!-- Header -->
        <div class="header">
            <div class="shop-name">
                <i class="fas fa-desktop"></i> Pancit Cantoners
                <span class="session-badge">Active Session <span class="auto-started-badge">Auto-started</span></span>
            </div>
            <div class="pc-info">
                <div class="pc-number">
                    <i class="fas fa-computer"></i> PC <span id="pcNumber"><?php echo htmlspecialchars($active_session['pc_number']); ?></span>
                </div>
                <div class="current-time" id="currentDateTime"></div>
            </div>
        </div>

        <!-- Purchase Info -->
        <?php if (!empty($active_session['credit_name'])): ?>
        <div class="time-credit-info">
            <div style="font-weight: 600; color: #000; margin-bottom: 5px;">
                <i class="fas fa-shopping-cart"></i> Purchased: <?php echo htmlspecialchars($active_session['credit_name']); ?>
            </div>
            <div class="credit-summary">
                <div class="credit-item">
                    <div class="credit-label">Total Time</div>
                    <div class="credit-value"><?php echo $hours > 0 ? $hours . 'h ' : ''; ?><?php echo $minutes; ?>m</div>
                </div>
                <div class="credit-item">
                    <div class="credit-label">Price</div>
                    <div class="credit-value">
                        <?php 
                        $total_cost = !empty($active_session['cost_php']) ? 
                            $active_session['cost_php'] * $active_session['time_credits_used'] : 
                            '0.00';
                        echo '₱' . number_format($total_cost, 2); 
                        ?>
                    </div>
                </div>
                <div class="credit-item">
                    <div class="credit-label">Remaining</div>
                    <div class="credit-value"><?php echo $total_remaining_minutes; ?>m</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Information -->
        <div class="user-section">
            <div class="user-info">
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user"></i> Username</div>
                    <div class="info-value" id="username"><?php echo htmlspecialchars($active_session['username']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-clock"></i> Session Type</div>
                    <div class="info-value" id="sessionType"><?php echo htmlspecialchars($session_type); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-sign-in-alt"></i> Start Time</div>
                    <div class="info-value" id="loginTime"><?php echo date('h:i A', $start_time); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-sign-out-alt"></i> End Time</div>
                    <div class="info-value" id="endTime"><?php echo date('h:i A', $end_time); ?></div>
                </div>
            </div>
            
            <!-- Session Stats -->
            <div class="session-stats">
                <div class="stat-item">
                    <div class="stat-label">Credits Used</div>
                    <div class="stat-value"><?php echo htmlspecialchars($active_session['time_credits_used'] ?? 0); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Time Used</div>
                    <div class="stat-value" id="timeUsed">00:00</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">PC Status</div>
                    <div class="stat-value status-online"><i class="fas fa-circle"></i> Online</div>
                </div>
            </div>
        </div>

        <!-- Timer Section -->
        <div class="timer-section">
            <div class="timer-label">Remaining Time</div>
            <div class="timer-display" id="timerDisplay">
                <?php 
                // Display initial time
                $init_hours = floor($remaining_seconds / 3600);
                $init_minutes = floor(($remaining_seconds % 3600) / 60);
                $init_seconds = $remaining_seconds % 60;
                echo sprintf("%02d:%02d:%02d", $init_hours, $init_minutes, $init_seconds);
                ?>
            </div>
            <div class="timer-auto-status">
                <i class="fas fa-bolt"></i> Timer started automatically after purchase
            </div>
            <div class="status-bar">
                <div class="status-fill" id="statusFill" style="width: <?php echo $percentage; ?>%"></div>
            </div>
            <div class="time-progress">
                <span>0%</span>
                <span>50%</span>
                <span>100%</span>
            </div>
        </div>

        <!-- Extension Notification -->
        <?php if (isset($_SESSION['extension_requested']) && isset($_SESSION['extension_minutes'])): ?>
        <div class="extension-notification">
            <i class="fas fa-clock"></i> 
            <strong>Extension Requested:</strong> 
            <?php echo $_SESSION['extension_minutes']; ?> minutes (₱<?php echo number_format($_SESSION['extension_price'], 2); ?>)
            <br>
            <small>Waiting for admin approval. Your current session continues.</small>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <button class="quick-btn" onclick="requestExtension()">
                <i class="fas fa-plus-circle"></i> Add Time
            </button>
            <button class="quick-btn" onclick="pauseSession()">
                <i class="fas fa-pause"></i> Pause
            </button>
            <button class="quick-btn" onclick="lockScreen()">
                <i class="fas fa-lock"></i> Lock
            </button>
            <button class="quick-btn" onclick="showHelp()">
                <i class="fas fa-question-circle"></i> Help
            </button>
        </div>

        <!-- Request Status -->
        <?php if (isset($_SESSION['extension_requested']) && !isset($_SESSION['extension_minutes'])): ?>
        <div class="request-status show" id="requestStatus">
            <i class="fas fa-clock"></i> Time extension request sent. Waiting for admin approval...
            <button class="dismiss-btn" onclick="dismissNotification()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="actions">
            <form method="POST" action="" class="action-form" id="extensionForm">
                <input type="hidden" name="extension_minutes" id="extensionMinutes">
                <input type="hidden" name="extension_price" id="extensionPrice">
                <button type="submit" name="extend_request" class="btn btn-extend" id="btnExtend">
                    <i class="fas fa-clock"></i> Request Time Extension
                </button>
            </form>
            
            <!-- FIXED: Added logout form that works with PHP handler -->
            <form method="POST" action="" class="action-form" onsubmit="return confirmLogout()">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn btn-logout" id="btnLogout">
                    <i class="fas fa-sign-out-alt"></i> End Session & Logout
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="shop-rules">
                <i class="fas fa-info-circle"></i> <strong>Shop Rules:</strong>
                <ul>
                    <li>Keep your area clean and tidy</li>
                    <li>No food or drinks near the PC</li>
                    <li>Report any issues to staff immediately</li>
                    <li>Respect other customers' privacy</li>
                </ul>
            </div>
            <div class="emergency-contact">
                <i class="fas fa-phone-alt"></i> Need help? Call: <strong>(02) 1234-5678</strong>
            </div>
        </div>
    </div>

    <!-- Warning Modal -->
    <div class="modal" id="warningModal">
        <div class="modal-content">
            <div class="modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="modal-title">Time Running Low!</div>
            <div class="modal-message" id="warningMessage">
                You have less than 5 minutes remaining.
            </div>
            <button class="modal-btn" onclick="closeModal()">OK</button>
        </div>
    </div>

    <!-- Time's Up Modal -->
    <div class="modal" id="timeUpModal">
        <div class="modal-content">
            <div class="modal-icon"><i class="fas fa-clock"></i></div>
            <div class="modal-title">Session Ended</div>
            <div class="modal-message">
                Your time has expired. Please approach the counter for assistance or end your session.
            </div>
            <button class="modal-btn" onclick="handleLogout()">End Session</button>
        </div>
    </div>

    <!-- Extension Modal -->
    <div class="modal" id="extensionModal">
        <div class="modal-content">
            <div class="modal-icon"><i class="fas fa-plus-circle"></i></div>
            <div class="modal-title">Add More Time</div>
            <div class="modal-message">
                <p>Select additional time to purchase:</p>
                <div class="time-options">
                    <button class="time-option" onclick="selectTime(15, 8)">
                        <div>15 min</div>
                        <div class="price">₱8.00</div>
                    </button>
                    <button class="time-option" onclick="selectTime(30, 15)">
                        <div>30 min</div>
                        <div class="price">₱15.00</div>
                    </button>
                    <button class="time-option" onclick="selectTime(60, 25)">
                        <div>1 hour</div>
                        <div class="price">₱25.00</div>
                    </button>
                </div>
                <p style="margin-top: 15px; font-size: 14px; color: #666;">
                    <i class="fas fa-info-circle"></i> Extension requests require admin approval.
                </p>
            </div>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="closeExtensionModal()">Cancel</button>
                <button class="modal-btn" onclick="confirmExtension()">Request Extension</button>
            </div>
        </div>
    </div>

    <script>
        // Session Data from PHP
        let sessionData = {
            pcNumber: <?php echo json_encode($active_session['pc_number']); ?>,
            username: <?php echo json_encode($active_session['username']); ?>,
            sessionType: <?php echo json_encode($session_type); ?>,
            totalTime: <?php echo $total_seconds; ?>,
            remainingTime: <?php echo $remaining_seconds; ?>,
            startTime: <?php echo $start_time; ?>,
            endTime: <?php echo $end_time; ?>,
            requestPending: <?php echo isset($_SESSION['extension_requested']) ? 'true' : 'false'; ?>,
            remainingCredits: <?php echo $total_remaining_minutes; ?>,
            // Debug info
            debug: {
                remaining: <?php echo $remaining_seconds; ?>,
                total: <?php echo $total_seconds; ?>,
                start: <?php echo $start_time; ?>,
                end: <?php echo $end_time; ?>
            }
        };

        console.log('Session Data Loaded:', sessionData);
        
        let timerInterval;
        let warningShown = false;
        let timeUsedInterval;
        let selectedExtension = null;
        let selectedPrice = null;

        // Initialize
        function init() {
            loadSessionData();
            startTimer();
            startTimeUsedTracker();
            updateDateTime();
            setInterval(updateDateTime, 1000);
            
            // Check if time is already low
            checkWarnings();
            
            // Auto-refresh every 30 seconds to sync with database
            setInterval(syncWithServer, 30000);
            
            // Show welcome message for auto-started session
            setTimeout(() => {
                if (sessionData.remainingTime > 60) {
                    showAutoStartNotification();
                }
            }, 1000);
            
            // Update debug info
            updateDebugInfo();
        }

        // Update debug information
        function updateDebugInfo() {
            document.getElementById('debugRemaining').textContent = sessionData.remainingTime;
            document.getElementById('debugTotal').textContent = sessionData.totalTime;
        }

        // Show auto-start notification
        function showAutoStartNotification() {
            const hours = Math.floor(sessionData.totalTime / 3600);
            const minutes = Math.floor((sessionData.totalTime % 3600) / 60);
            
            let timeStr = '';
            if (hours > 0) {
                timeStr += hours + ' hour' + (hours > 1 ? 's' : '');
                if (minutes > 0) timeStr += ' ';
            }
            if (minutes > 0) {
                timeStr += minutes + ' minute' + (minutes > 1 ? 's' : '');
            }
            
            console.log('Session auto-started! You have ' + timeStr + ' of PC time.');
        }

        // Load session data
        function loadSessionData() {
            document.getElementById('pcNumber').textContent = sessionData.pcNumber;
            document.getElementById('username').textContent = sessionData.username;
            document.getElementById('sessionType').textContent = sessionData.sessionType;
            
            const loginTime = new Date(sessionData.startTime * 1000);
            document.getElementById('loginTime').textContent = formatTime(loginTime);
            
            const endTime = new Date(sessionData.endTime * 1000);
            document.getElementById('endTime').textContent = formatTime(endTime);
            
            // Update timer display initially
            updateTimerDisplay();
            updateStatusBar();
        }

        // Start countdown timer - FIXED
        function startTimer() {
            console.log('Starting timer with remainingTime:', sessionData.remainingTime);
            
            timerInterval = setInterval(() => {
                if (sessionData.remainingTime > 0) {
                    sessionData.remainingTime--;
                    updateTimerDisplay();
                    updateStatusBar();
                    checkWarnings();
                    updateDebugInfo();
                } else {
                    clearInterval(timerInterval);
                    showTimeUpModal();
                }
            }, 1000);
        }

        // Track time used
        function startTimeUsedTracker() {
            let startTime = sessionData.startTime * 1000;
            
            timeUsedInterval = setInterval(() => {
                let currentTime = new Date().getTime();
                let timeDiff = currentTime - startTime;
                
                // Convert to minutes and seconds
                let minutes = Math.floor(timeDiff / 60000);
                let seconds = Math.floor((timeDiff % 60000) / 1000);
                
                document.getElementById('timeUsed').textContent = 
                    `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }, 1000);
        }

        // Update timer display
        function updateTimerDisplay() {
            const hours = Math.floor(sessionData.remainingTime / 3600);
            const minutes = Math.floor((sessionData.remainingTime % 3600) / 60);
            const seconds = sessionData.remainingTime % 60;

            const display = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
            document.getElementById('timerDisplay').textContent = display;

            // Update color based on time remaining
            const timerElement = document.getElementById('timerDisplay');
            const statusFill = document.getElementById('statusFill');
            
            if (sessionData.remainingTime <= 60) { // 1 minute
                timerElement.className = 'timer-display danger';
                statusFill.className = 'status-fill danger';
            } else if (sessionData.remainingTime <= 300) { // 5 minutes
                timerElement.className = 'timer-display warning';
                statusFill.className = 'status-fill warning';
            } else {
                timerElement.className = 'timer-display';
                statusFill.className = 'status-fill';
            }
        }

        // Update status bar
        function updateStatusBar() {
            const percentage = (sessionData.remainingTime / sessionData.totalTime) * 100;
            document.getElementById('statusFill').style.width = percentage + '%';
        }

        // Check for warnings
        function checkWarnings() {
            if (sessionData.remainingTime <= 300 && !warningShown) { // 5 minutes
                showWarningModal();
                warningShown = true;
            }
        }

        // Update current date/time
        function updateDateTime() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { 
                weekday: 'short', 
                month: 'short', 
                day: 'numeric',
                year: 'numeric'
            });
            const timeStr = formatTime(now);
            document.getElementById('currentDateTime').textContent = `${dateStr} ${timeStr}`;
        }

        // Format time
        function formatTime(date) {
            return date.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            });
        }

        // Pad numbers
        function pad(num) {
            return num.toString().padStart(2, '0');
        }

        // Request time extension
        function requestExtension() {
            if (!sessionData.requestPending) {
                document.getElementById('extensionModal').classList.add('show');
            } else {
                alert('You already have a pending extension request.');
            }
        }

        function selectTime(minutes, price) {
            selectedExtension = minutes;
            selectedPrice = price;
            
            // Highlight selected option
            document.querySelectorAll('.time-option').forEach(option => {
                option.classList.remove('selected');
            });
            event.target.closest('.time-option').classList.add('selected');
        }

        function confirmExtension() {
            if (selectedExtension) {
                // Set hidden form values
                document.getElementById('extensionMinutes').value = selectedExtension;
                document.getElementById('extensionPrice').value = selectedPrice;
                
                // Submit the form
                document.getElementById('extensionForm').submit();
            } else {
                alert('Please select a time option');
            }
        }

        function closeExtensionModal() {
            document.getElementById('extensionModal').classList.remove('show');
            selectedExtension = null;
            selectedPrice = null;
        }

        // Dismiss notification
        function dismissNotification() {
            document.getElementById('requestStatus').classList.remove('show');
        }

        // Pause session (simulated)
        function pauseSession() {
            if (confirm('Pause your session? Time will continue counting down.')) {
                alert('Session paused. Please see staff for assistance.');
            }
        }

        // Lock screen (simulated)
        function lockScreen() {
            if (confirm('Lock screen? You\'ll need to enter your password to unlock.')) {
                alert('Screen locked. Redirecting to lock screen...');
            }
        }

        // Show help
        function showHelp() {
            alert('For assistance:\n1. Press the Help button\n2. Call staff at (02) 1234-5678\n3. Visit the counter\n\nYour session is automatically started after purchase.');
        }

        // Confirm logout
        function confirmLogout() {
            if (sessionData.remainingTime > 60) {
                return confirm('You still have ' + Math.ceil(sessionData.remainingTime/60) + ' minutes remaining. Are you sure you want to end your session and logout?');
            }
            return true;
        }

        // Handle logout
        function handleLogout() {
            if (confirmLogout()) {
                // Find and submit the logout form
                document.querySelector('input[name="logout"]').closest('form').submit();
            }
        }

        // Sync with server
        function syncWithServer() {
            fetch('sync_session.php')
                .then(response => response.json())
                .then(data => {
                    if (data.remainingTime !== undefined) {
                        sessionData.remainingTime = data.remainingTime;
                        updateTimerDisplay();
                        updateStatusBar();
                        updateDebugInfo();
                    }
                    if (data.remainingCredits !== undefined) {
                        sessionData.remainingCredits = data.remainingCredits;
                    }
                })
                .catch(error => console.error('Sync error:', error));
        }

        // Show warning modal
        function showWarningModal() {
            document.getElementById('warningModal').classList.add('show');
        }

        // Show time's up modal
        function showTimeUpModal() {
            document.getElementById('timeUpModal').classList.add('show');
            clearInterval(timeUsedInterval);
            
            // Auto-logout after 1 minute if user doesn't respond
            setTimeout(() => {
                if (document.getElementById('timeUpModal').classList.contains('show')) {
                    handleLogout();
                }
            }, 60000);
        }

        // Close modal
        function closeModal() {
            document.getElementById('warningModal').classList.remove('show');
        }

        // Initialize on load
        window.addEventListener('load', init);

        // Security features
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            // Prevent certain shortcuts
            if (e.ctrlKey && (e.key === 'w' || e.key === 't' || e.key === 'n' || e.key === 'u')) {
                e.preventDefault();
            }
            // Prevent F12, Ctrl+Shift+I, etc.
            if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
                e.preventDefault();
            }
        });

        // Prevent leaving without confirmation
        window.addEventListener('beforeunload', function(e) {
            if (sessionData.remainingTime > 60) {
                e.preventDefault();
                e.returnValue = 'You have active session time remaining. Are you sure you want to leave?';
            }
        });
    </script>
</body>
</html>

<?php
// Clear extension request flag if not in a POST request
if (!isset($_POST['extend_request']) && isset($_SESSION['extension_requested'])) {
    unset($_SESSION['extension_requested']);
    if (isset($_SESSION['extension_minutes'])) {
        unset($_SESSION['extension_minutes']);
    }
    if (isset($_SESSION['extension_price'])) {
        unset($_SESSION['extension_price']);
    }
}
?>