<?php
include "db.php";
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Handle DELETE request
if (isset($_GET['delete'])) {
    $log_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM pc_logs WHERE log_id = ?");
    $stmt->bind_param("i", $log_id);
    if ($stmt->execute()) {
        $success_message = "Log entry deleted successfully!";
    } else {
        $error_message = "Error deleting log entry: " . $conn->error;
    }
    $stmt->close();
}

// Handle EDIT/UPDATE request
if (isset($_POST['update_log'])) {
    $log_id = intval($_POST['log_id']);
    $pc_name = $_POST['pc_name'];
    $pc_user = $_POST['pc_user'];
    $status = $_POST['status'];
    $action_type = $_POST['action_type'];
    $login_time = $_POST['login_time'];
    $logout_time = !empty($_POST['logout_time']) ? $_POST['logout_time'] : null;
    $minutes_added = intval($_POST['minutes_added']);
    $notes = $_POST['notes'];
    
    // Calculate duration in minutes
    $duration_minutes = 0;
    if ($logout_time) {
        $start = new DateTime($login_time);
        $end = new DateTime($logout_time);
        $interval = $start->diff($end);
        $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }
    
    $stmt = $conn->prepare("UPDATE pc_logs SET 
        pc_name = ?, 
        pc_user = ?, 
        status = ?, 
        action_type = ?, 
        login_time = ?, 
        logout_time = ?, 
        minutes_added = ?,
        duration_minutes = ?,
        notes = ? 
        WHERE log_id = ?");
    
    $stmt->bind_param("ssssssiisi", 
        $pc_name, $pc_user, $status, $action_type, 
        $login_time, $logout_time, $minutes_added, $duration_minutes, 
        $notes, $log_id
    );
    
    if ($stmt->execute()) {
        $success_message = "Log entry updated successfully!";
    } else {
        $error_message = "Error updating log entry: " . $conn->error;
    }
    $stmt->close();
}

// Handle ADD new log entry
if (isset($_POST['add_log'])) {
    $pc_number = intval($_POST['pc_number']);
    $pc_name = $_POST['pc_name'];
    $pc_user = $_POST['pc_user'];
    $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $status = $_POST['status'];
    $action_type = $_POST['action_type'];
    $login_time = $_POST['login_time'];
    $logout_time = !empty($_POST['logout_time']) ? $_POST['logout_time'] : null;
    $minutes_added = intval($_POST['minutes_added']);
    $notes = $_POST['notes'];
    
    // Calculate duration in minutes
    $duration_minutes = 0;
    if ($logout_time) {
        $start = new DateTime($login_time);
        $end = new DateTime($logout_time);
        $interval = $start->diff($end);
        $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }
    
    $stmt = $conn->prepare("INSERT INTO pc_logs 
        (pc_number, pc_name, pc_user, customer_id, status, action_type, login_time, logout_time, minutes_added, duration_minutes, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("ississssiis", 
        $pc_number, $pc_name, $pc_user, $customer_id, $status, $action_type, 
        $login_time, $logout_time, $minutes_added, $duration_minutes, $notes
    );
    
    if ($stmt->execute()) {
        $success_message = "New log entry added successfully!";
    } else {
        $error_message = "Error adding log entry: " . $conn->error;
    }
    $stmt->close();
}

// Get log entry for editing
$edit_log = null;
if (isset($_GET['edit'])) {
    $log_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM pc_logs WHERE log_id = ?");
    $stmt->bind_param("i", $log_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_log = $result->fetch_assoc();
    $stmt->close();
}

// Fetch all logs for display
$result = $conn->query("
    SELECT 
        log_id,
        pc_number,
        pc_name,
        pc_user,
        status,
        action_type,
        login_time,
        logout_time,
        minutes_added,
        duration_minutes,
        notes,
        created_at
    FROM pc_logs 
    ORDER BY login_time DESC
    LIMIT 200
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PC Usage Logs Management</title>
    <link rel="stylesheet" href="PClogs.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PC Usage Logs Management</h1>
            <div class="nav-buttons">
                <button onclick="window.location.href='dashboard.php'" class="btn btn-secondary">Dashboard</button>
                <button onclick="closePage()" class="btn btn-secondary">Close Page</button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Add/Edit Form -->
        <div class="form-container">
            <h2><?php echo $edit_log ? 'Edit Log Entry' : 'Add New Log Entry'; ?></h2>
            <form method="POST" action="PClogs.php" class="log-form">
                <?php if ($edit_log): ?>
                    <input type="hidden" name="log_id" value="<?php echo $edit_log['log_id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="pc_number">PC Number</label>
                        <select name="pc_number" id="pc_number" required>
                            <option value="">Select PC</option>
                            <?php for($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo ($edit_log && $edit_log['pc_number'] == $i) ? 'selected' : ''; ?>>
                                    PC-<?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="pc_name">PC Name</label>
                        <input type="text" name="pc_name" id="pc_name" 
                               value="<?php echo $edit_log ? htmlspecialchars($edit_log['pc_name']) : ''; ?>" 
                               placeholder="e.g., PC-01" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="pc_user">PC User (Customer)</label>
                        <input type="text" name="pc_user" id="pc_user" 
                               value="<?php echo $edit_log ? htmlspecialchars($edit_log['pc_user']) : ''; ?>" 
                               placeholder="Username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_id">Customer ID (Optional)</label>
                        <input type="number" name="customer_id" id="customer_id" 
                               value="<?php echo $edit_log ? htmlspecialchars($edit_log['customer_id']) : ''; ?>" 
                               placeholder="Customer ID">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" required>
                            <option value="active" <?php echo ($edit_log && $edit_log['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo ($edit_log && $edit_log['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="extended" <?php echo ($edit_log && $edit_log['status'] == 'extended') ? 'selected' : ''; ?>>Extended</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="action_type">Action Type</label>
                        <select name="action_type" id="action_type" required>
                            <option value="start" <?php echo ($edit_log && $edit_log['action_type'] == 'start') ? 'selected' : ''; ?>>Start</option>
                            <option value="stop" <?php echo ($edit_log && $edit_log['action_type'] == 'stop') ? 'selected' : ''; ?>>Stop</option>
                            <option value="extend" <?php echo ($edit_log && $edit_log['action_type'] == 'extend') ? 'selected' : ''; ?>>Extend</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="login_time">Login Time</label>
                        <input type="datetime-local" name="login_time" id="login_time" 
                               value="<?php echo $edit_log ? date('Y-m-d\TH:i', strtotime($edit_log['login_time'])) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="logout_time">Logout Time</label>
                        <input type="datetime-local" name="logout_time" id="logout_time" 
                               value="<?php echo $edit_log && $edit_log['logout_time'] ? date('Y-m-d\TH:i', strtotime($edit_log['logout_time'])) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="minutes_added">Minutes Added (for extensions)</label>
                        <input type="number" name="minutes_added" id="minutes_added" 
                               value="<?php echo $edit_log ? $edit_log['minutes_added'] : '0'; ?>" 
                               min="0" step="5">
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <input type="text" name="notes" id="notes" 
                               value="<?php echo $edit_log ? htmlspecialchars($edit_log['notes']) : ''; ?>" 
                               placeholder="Additional notes">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="<?php echo $edit_log ? 'update_log' : 'add_log'; ?>" class="btn btn-primary">
                        <?php echo $edit_log ? 'Update Log Entry' : 'Add Log Entry'; ?>
                    </button>
                    <?php if ($edit_log): ?>
                        <a href="PClogs.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="table-container">
            <h2>PC Usage Logs</h2>
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>PC #</th>
                            <th>PC Name</th>
                            <th>User</th>
                            <th>Customer ID</th>
                            <th>Status</th>
                            <th>Action</th>
                            <th>Login Time</th>
                            <th>Logout Time</th>
                            <th>Minutes Added</th>
                            <th>Duration</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="logTable">
                        <?php
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                // Format duration
                                $duration_formatted = '';
                                if ($row['duration_minutes'] > 0) {
                                    $hours = floor($row['duration_minutes'] / 60);
                                    $minutes = $row['duration_minutes'] % 60;
                                    $duration_formatted = sprintf("%02d:%02d", $hours, $minutes);
                                } else {
                                    $duration_formatted = '--:--';
                                }
                                
                                // Status badge class
                                $status_class = '';
                                switch($row['status']) {
                                    case 'active': $status_class = 'status-active'; break;
                                    case 'completed': $status_class = 'status-completed'; break;
                                    case 'extended': $status_class = 'status-extended'; break;
                                }
                                
                                // Action type badge
                                $action_class = '';
                                switch($row['action_type']) {
                                    case 'start': $action_class = 'action-start'; break;
                                    case 'stop': $action_class = 'action-stop'; break;
                                    case 'extend': $action_class = 'action-extend'; break;
                                }
                                
                                echo "<tr>";
                                echo "<td>" . $row['log_id'] . "</td>";
                                echo "<td>PC-" . str_pad($row['pc_number'], 2, '0', STR_PAD_LEFT) . "</td>";
                                echo "<td>" . htmlspecialchars($row['pc_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['pc_user']) . "</td>";
                                echo "<td>" . ($row['customer_id'] ?? '-') . "</td>";
                                echo "<td><span class='status-badge " . $status_class . "'>" . $row['status'] . "</span></td>";
                                echo "<td><span class='action-badge " . $action_class . "'>" . $row['action_type'] . "</span></td>";
                                echo "<td>" . date('Y-m-d H:i:s', strtotime($row['login_time'])) . "</td>";
                                echo "<td>" . ($row['logout_time'] ? date('Y-m-d H:i:s', strtotime($row['logout_time'])) : '<span class="badge-active">Active</span>') . "</td>";
                                echo "<td>" . ($row['minutes_added'] > 0 ? $row['minutes_added'] . ' min' : '-') . "</td>";
                                echo "<td>" . $duration_formatted . "</td>";
                                echo "<td>" . htmlspecialchars($row['notes'] ?? '-') . "</td>";
                                echo "<td class='action-buttons'>";
                                echo "<a href='PClogs.php?edit=" . $row['log_id'] . "' class='btn-edit'>Edit</a>";
                                echo "<a href='PClogs.php?delete=" . $row['log_id'] . "' class='btn-delete' onclick='return confirm(\"Are you sure you want to delete this log entry?\")'>Delete</a>";
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='13' class='no-records'>No records found in database</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function closePage() {
        window.close();
    }
    
    // Auto-update PC Name based on PC Number selection
    document.getElementById('pc_number').addEventListener('change', function() {
        const pcNameField = document.getElementById('pc_name');
        if (this.value) {
            pcNameField.value = 'PC-' + this.value.padStart(2, '0');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 300);
        });
    }, 5000);
    </script>
</body>
</html>