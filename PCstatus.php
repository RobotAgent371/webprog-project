<?php
include "db.php";
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Get current PC statuses from database with customer info
$pc_statuses = [];
$stmt = $conn->prepare("
    SELECT 
        ps.pc_id,
        ps.pc_number, 
        ps.status, 
        ps.current_username, 
        ps.current_customer_id,
        ps.remaining_minutes,
        ca.full_name,
        ca.username
    FROM pc_stations ps
    LEFT JOIN customer_accounts ca ON ps.current_customer_id = ca.customer_id
    ORDER BY ps.pc_number
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pc_statuses[$row['pc_number']] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PC Status Monitor - Pancit Cantoners</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="PCstatus.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pc-user-display {
            font-size: 16px;
            color: #000000;
            margin: 10px 0;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 15px;
            border-radius: 25px;
            text-align: center;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .customer-session {
            color: #0066cc;
            font-weight: 700;
        }
        
        .manual-user {
            color: #666;
            font-style: italic;
        }
        
        .customer-info {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 15px;
            padding: 15px;
            margin: 10px 0;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .customer-field {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .customer-label {
            color: #666;
            font-weight: 600;
        }
        
        .customer-value {
            color: #000000;
            font-weight: 700;
        }
        
        .registered-customer {
            background: rgba(46, 213, 115, 0.1);
            border-color: rgba(46, 213, 115, 0.3);
        }
        
        .manual-entry {
            background: rgba(255, 152, 0, 0.1);
            border-color: rgba(255, 152, 0, 0.3);
        }
        
        .manual-input-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed rgba(255, 255, 255, 0.2);
        }
        
        .status-auto-started {
            background: rgba(46, 213, 115, 0.4);
            border-color: rgba(46, 213, 115, 0.8);
            color: #ffffff;
            box-shadow: 0 0 20px rgba(46, 213, 115, 0.4);
            animation: pulseAuto 2s infinite;
        }
        
        .timer.auto-running {
            color: #2ed573;
            font-weight: 800;
            text-shadow: 0 0 10px rgba(46, 213, 115, 0.5);
        }
        
        @keyframes pulseAuto {
            0%, 100% { box-shadow: 0 0 20px rgba(46, 213, 115, 0.4); }
            50% { box-shadow: 0 0 30px rgba(46, 213, 115, 0.6); }
        }
        
        .auto-start-badge {
            background: rgba(46, 213, 115, 0.2);
            border: 1px solid rgba(46, 213, 115, 0.4);
            color: #2ed573;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
    </style>
</head>
<body>

<!-- MENU TOGGLE -->
<div id="tab-toggle">☰</div>

<!-- OVERLAY -->
<div id="overlay"></div>

<!-- SIDE MENU -->
<div id="side-tab">
    <div class="menu-header">
        <span id="back-arrow">←</span>
        <h3>Menu</h3>
    </div>
    <button onclick="logout()">Log Out</button>
    <button onclick="openLogs()">PC Logs</button>
    <button onclick="syncWithDatabase()">Sync with Database</button>
</div>

<h2>Status Monitor</h2>

<div class="pc-container">
<script>
let remainingTime = {};
let timers = {};
let isRunning = {};
let countdownIntervals = {};
let pcUsers = {}; // Store PC users

// Load initial PC statuses from PHP
let pcStatuses = <?php echo json_encode($pc_statuses); ?>;

// ---------- TIME HELPERS ----------
function parseTime(input) {
    let parts = input.split(":").map(Number);
    if (parts.length === 3) {
        return (parts[0]*3600 + parts[1]*60 + parts[2]) * 1000;
    } else if (parts.length === 2) {
        return (parts[0]*60 + parts[1]) * 1000;
    }
    return 0;
}

function formatTime(ms) {
    if (ms <= 0) return "00:00:00";
    let s = Math.floor(ms / 1000);
    let h = String(Math.floor(s / 3600)).padStart(2,"0");
    let m = String(Math.floor((s % 3600) / 60)).padStart(2,"0");
    let sec = String(s % 60).padStart(2,"0");
    return `${h}:${m}:${sec}`;
}

function formatTimeFromMinutes(minutes) {
    if (minutes <= 0) return "00:00:00";
    let h = Math.floor(minutes / 60);
    let m = minutes % 60;
    return `${String(h).padStart(2,"0")}:${String(m).padStart(2,"0")}:00`;
}

// ---------- STATUS ----------
function setStatus(pc, status) {
    const statusDiv = document.getElementById(`status-${pc}`);
    statusDiv.textContent = status;
    
    // Check if this is an auto-started session
    const pcData = pcStatuses[pc];
    const isAutoStarted = pcData && pcData.current_customer_id && status === 'Online';
    
    if (isAutoStarted) {
        statusDiv.className = `status-indicator status-auto-started`;
    } else {
        statusDiv.className = `status-indicator status-${status.toLowerCase()}`;
    }
    
    // Update database
    updatePCStatus(pc, status);
}

// ---------- UPDATE PC STATUS IN DATABASE ----------
function updatePCStatus(pc, status) {
    fetch("update_pc_status.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "pc_number=" + pc + "&status=" + encodeURIComponent(status)
    });
}

// ---------- START / STOP ----------
function start(pc) {
    // Check if PC has a registered customer
    const pcData = pcStatuses[pc];
    
    if (pcData && pcData.current_customer_id) {
        // PC has registered customer - it should already be auto-started
        alert('This PC is already being used by a registered customer. Timer is running automatically.');
        return;
    }
    
    // Manual entry - check if time is set
    if (remainingTime[pc] <= 0) {
        alert("Please set time first!");
        return;
    }
    
    // Get PC user from input field
    const pcUser = document.getElementById(`user-${pc}`).value.trim();
    if (!pcUser) {
        alert("Please enter the PC user's name!");
        return;
    }
    
    startSession(pc, pcUser, false);
}

function startSession(pc, pcUser, isRegistered) {
    if (!isRunning[pc]) {
        setStatus(pc, "Online");
        isRunning[pc] = true;
        pcUsers[pc] = pcUser; // Store the PC user
        
        // Convert remainingTime from ms to minutes for database
        const minutes = Math.floor(remainingTime[pc] / 60000);
        
        // Start countdown
        countdownIntervals[pc] = setInterval(() => {
            remainingTime[pc] -= 1000;
            document.getElementById(`display-${pc}`).textContent = formatTime(remainingTime[pc]);
            
            // Update remaining minutes in database
            const remainingMinutes = Math.floor(remainingTime[pc] / 60000);
            updatePCRemainingTime(pc, remainingMinutes, pcUser);
            
            // Time's up
            if (remainingTime[pc] <= 0) {
                stop(pc);
                alert(`PC${pc} time has expired for ${pcUser}!`);
            }
        }, 1000);
        
        // Send to server with PC user
        fetch("log_start.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "pc=PC" + pc + "&pc_user=" + encodeURIComponent(pcUser)
        });
        
        // Also update pc_stations table
        updatePCStart(pc, pcUser, minutes, isRegistered);
        
        // Disable manual input if registered customer
        if (isRegistered) {
            document.getElementById(`user-${pc}`).disabled = true;
        }
    }
}

function stop(pc) {
    if (isRunning[pc]) {
        setStatus(pc, "Offline");
        isRunning[pc] = false;
        
        // Get PC user
        const pcData = pcStatuses[pc];
        let pcUser = '';
        if (pcData && pcData.current_customer_id) {
            pcUser = pcData.current_username || pcData.username || pcData.full_name || 'Unknown';
        } else {
            pcUser = pcUsers[pc] || document.getElementById(`user-${pc}`).value.trim() || 'Unknown';
        }
        
        // Clear countdown
        if (countdownIntervals[pc]) {
            clearInterval(countdownIntervals[pc]);
            countdownIntervals[pc] = null;
        }
        
        // Send to server with PC user
        fetch("log_stop.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "pc=PC" + pc + "&pc_user=" + encodeURIComponent(pcUser)
        });
        
        // Update pc_stations table
        updatePCStop(pc);
        
        // Clear the user field if time is up
        if (remainingTime[pc] <= 0) {
            document.getElementById(`user-${pc}`).value = "";
            delete pcUsers[pc];
        }
        
        // Re-enable manual input
        document.getElementById(`user-${pc}`).disabled = false;
    }
}

// ---------- ADD TIME ----------
function addTime(pc) {
    let mins = parseInt(document.getElementById(`extend-${pc}`).value);
    if (!mins || mins <= 0) return alert("Enter valid minutes");
    
    remainingTime[pc] += mins * 60000;
    document.getElementById(`display-${pc}`).textContent = formatTime(remainingTime[pc]);
    
    // Get PC user
    const pcData = pcStatuses[pc];
    let pcUser = '';
    if (pcData && pcData.current_customer_id) {
        pcUser = pcData.current_username || pcData.username || pcData.full_name || 'Unknown';
    } else {
        pcUser = document.getElementById(`user-${pc}`).value.trim() || pcUsers[pc] || 'Unknown';
    }
    
    if (pcUser !== 'Unknown') {
        fetch("log_extend.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "pc=PC" + pc + "&pc_user=" + encodeURIComponent(pcUser) + "&minutes_added=" + mins
        });
        
        // Update pc_stations table
        updatePCExtendTime(pc, mins);
    }
    
    // Clear extend input
    document.getElementById(`extend-${pc}`).value = "";
}

// ---------- SET/RESET ----------
function reset(pc) {
    stop(pc);
    
    let timeInput = document.getElementById(`input-${pc}`).value;
    if (!timeInput) {
        alert("Please enter time in HH:MM:SS format!");
        return;
    }
    
    remainingTime[pc] = parseTime(timeInput);
    if (remainingTime[pc] <= 0) {
        alert("Invalid time format! Use HH:MM:SS or MM:SS");
        return;
    }
    
    document.getElementById(`display-${pc}`).textContent = formatTime(remainingTime[pc]);
    
    // Clear user field and re-enable it
    document.getElementById(`user-${pc}`).value = "";
    document.getElementById(`user-${pc}`).disabled = false;
    delete pcUsers[pc];
    
    // Update database
    updatePCRemainingTime(pc, Math.floor(remainingTime[pc] / 60000), '');
}

// ---------- DATABASE UPDATE FUNCTIONS ----------
function updatePCStart(pc, username, minutes, isRegistered) {
    fetch("update_pc_start.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "pc_number=" + pc + 
              "&username=" + encodeURIComponent(username) + 
              "&minutes=" + minutes +
              "&is_registered=" + (isRegistered ? "1" : "0")
    });
}

function updatePCStop(pc) {
    fetch("update_pc_stop.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "pc_number=" + pc
    });
}

function updatePCExtendTime(pc, minutes) {
    fetch("update_pc_extend.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "pc_number=" + pc + "&minutes=" + minutes
    });
}

function updatePCRemainingTime(pc, minutes, username) {
    fetch("update_pc_remaining.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "pc_number=" + pc + 
              "&minutes=" + minutes + 
              "&username=" + encodeURIComponent(username)
    });
}

// ---------- SYNC WITH DATABASE ----------
function syncWithDatabase() {
    fetch("sync_pc_status.php")
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Synced with database successfully!");
                // Reload the page to show updated statuses
                location.reload();
            } else {
                alert("Sync failed: " + data.message);
            }
        });
}

// ---------- CREATE PCS ----------
for (let i = 1; i <= 10; i++) {
    remainingTime[i] = 0;
    isRunning[i] = false;
    
    // Check if we have database status for this PC
    let pcData = pcStatuses[i] || {};
    let initialStatus = pcData.status || 'available';
    let currentCustomerId = pcData.current_customer_id || null;
    let currentUsername = pcData.current_username || '';
    let customerUsername = pcData.username || '';
    let customerFullName = pcData.full_name || '';
    let dbMinutes = pcData.remaining_minutes || 0;
    
    // If PC is occupied in database, set initial time and auto-start
    if (initialStatus === 'occupied' && dbMinutes > 0) {
        remainingTime[i] = dbMinutes * 60000;
        isRunning[i] = true; // Set as running immediately
    }
    
    // Determine display name
    let displayName = '';
    let isRegisteredCustomer = false;
    
    if (currentCustomerId) {
        // Registered customer
        displayName = customerFullName || customerUsername || currentUsername;
        isRegisteredCustomer = true;
    } else if (currentUsername) {
        // Manual entry
        displayName = currentUsername;
    }
    
    // Determine if this is an auto-started session
    const isAutoStarted = initialStatus === 'occupied' && currentCustomerId;
    
    document.write(`
        <div class="pc-box">
            <h3>PC${i} ${isAutoStarted ? '<span class="auto-start-badge">Auto</span>' : ''}</h3>
            
            <div class="customer-info ${isRegisteredCustomer ? 'registered-customer' : 'manual-entry'}">
                <div class="pc-user-display">
                    ${displayName ? 
                        `<span class="customer-session">
                            <i class="fas fa-user"></i> ${displayName}
                            ${isRegisteredCustomer ? '<span style="font-size:12px;color:#4CAF50;"> (Registered)</span>' : ''}
                        </span>` : 
                        '<span class="manual-user"><i class="fas fa-user-clock"></i> Available</span>'
                    }
                </div>
                
                ${displayName && isRegisteredCustomer ? `
                    <div class="customer-field">
                        <span class="customer-label">Username:</span>
                        <span class="customer-value">${customerUsername || 'N/A'}</span>
                    </div>
                    <div class="customer-field">
                        <span class="customer-label">Full Name:</span>
                        <span class="customer-value">${customerFullName || 'N/A'}</span>
                    </div>
                    <div class="customer-field">
                        <span class="customer-label">Customer ID:</span>
                        <span class="customer-value">#${currentCustomerId}</span>
                    </div>
                ` : ''}
            </div>

            ${!isRegisteredCustomer ? `
                <div class="manual-input-section">
                    <label>Manual User Entry</label>
                    <input type="text" id="user-${i}" placeholder="Enter walk-in customer name" 
                           value="${currentUsername || ''}" ${initialStatus === 'occupied' ? 'readonly' : ''}>
                </div>
            ` : ''}

            <label>Status</label>
            <div id="status-${i}" class="status-indicator ${isAutoStarted ? 'status-auto-started' : 'status-' + initialStatus}">
                ${isAutoStarted ? 'Online (Auto)' : initialStatus.charAt(0).toUpperCase() + initialStatus.slice(1)}
            </div>

            <label>Set Time (HH:MM:SS)</label>
            <input type="text" id="input-${i}" placeholder="01:30:00" value="${formatTimeFromMinutes(dbMinutes)}">

            <div class="timer ${isAutoStarted ? 'auto-running' : ''}" id="display-${i}">${formatTime(remainingTime[i])}</div>

            <button onclick="reset(${i})">Set Time</button>
            <button onclick="start(${i})" ${initialStatus === 'occupied' ? 'disabled' : ''}>
                ${isAutoStarted ? 'Already Started' : 'Start'}
            </button>
            <button onclick="stop(${i})" ${initialStatus !== 'occupied' ? 'disabled' : ''}>Stop</button>

            <label>Extend Time (minutes)</label>
            <div class="extend-container">
                <input type="number" id="extend-${i}" placeholder="30" min="1" max="480">
                <button class="add-time" onclick="addTime(${i})">Add</button>
            </div>
            
            <div style="margin-top: 10px; font-size: 12px; color: #666; text-align: center;">
                <i class="fas fa-database"></i> 
                ${dbMinutes > 0 ? 
                    `${dbMinutes} min remaining` : 
                    'No active session'
                }
                ${currentCustomerId ? ' • Registered Customer' : currentUsername ? ' • Manual Entry' : ''}
                ${isAutoStarted ? ' • <span style="color:#2ed573;font-weight:600;">Auto-started</span>' : ''}
            </div>
        </div>
    `);
    
    // If PC is occupied in database (by registered customer), auto-start the timer immediately
    if (initialStatus === 'occupied' && dbMinutes > 0 && currentCustomerId) {
        // Start countdown from database time
        countdownIntervals[i] = setInterval(() => {
            if (remainingTime[i] > 0) {
                remainingTime[i] -= 1000;
                const displayElement = document.getElementById(`display-${i}`);
                if (displayElement) {
                    displayElement.textContent = formatTime(remainingTime[i]);
                }
                
                // Update remaining minutes in database
                const remainingMinutes = Math.floor(remainingTime[i] / 60000);
                updatePCRemainingTime(i, remainingMinutes, displayName);
                
                if (remainingTime[i] <= 0) {
                    stop(i);
                    alert(`PC${i} time has expired for ${displayName}!`);
                }
            }
        }, 1000);
    }
}

// ---------- MENU BEHAVIOR ----------
const tabToggle = document.getElementById("tab-toggle");
const sideTab = document.getElementById("side-tab");
const overlay = document.getElementById("overlay");
const backArrow = document.getElementById("back-arrow");

function openMenu() {
    sideTab.classList.add("active");
    overlay.classList.add("active");
    tabToggle.style.display = "none";
}

function closeMenu() {
    sideTab.classList.remove("active");
    overlay.classList.remove("active");
    tabToggle.style.display = "flex";
}

tabToggle.onclick = openMenu;
backArrow.onclick = closeMenu;
overlay.onclick = closeMenu;

// ---------- NAV ----------
function logout() {
    window.location.href = "logout.php";
}

function openLogs() {
    window.open("PCLogs.php", "_blank");
}
</script>
</div>

<?php
session_start();
include "check_session.php";
check_admin_session(); // Checks for $_SESSION['user_id']
?>

</body>
</html>