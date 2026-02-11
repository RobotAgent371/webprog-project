<?php
// customer_login.php
session_start();
include "db.php";

// If already logged in as customer, redirect
if (isset($_SESSION['customer_id'])) {
    header("Location: client_index.php");
    exit();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["username"];
    $password = $_POST["password"];

    // Get IP address
    $ip_address = $_SERVER["REMOTE_ADDR"];

    // 1. Get customer - CORRECTED: using password_hash column
    $stmt = $conn->prepare("SELECT customer_id, username, password_hash, full_name FROM customer_accounts WHERE username = ?");
    
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $customer = $result->fetch_assoc();
            
            // Verify password using password_hash column
            if (password_verify($password, $customer["password_hash"])) {
                
                // 2. Insert customer login session record (if table exists)
                $check_table = $conn->query("SHOW TABLES LIKE 'customer_login_sessions'");
                if ($check_table->num_rows > 0) {
                    $session_stmt = $conn->prepare(
                        "INSERT INTO customer_login_sessions (customer_id, login_time, ip_address)
                         VALUES (?, NOW(), ?)"
                    );
                    
                    if ($session_stmt) {
                        $session_stmt->bind_param("is", $customer["customer_id"], $ip_address);
                        $session_stmt->execute();
                        $session_id = $conn->insert_id;
                        $session_stmt->close();
                        
                        $_SESSION["customer_session_id"] = $session_id;
                    }
                }
                
                // 3. Store session data
                $_SESSION["customer_id"] = $customer["customer_id"];
                $_SESSION["username"] = $customer["username"];
                $_SESSION["full_name"] = $customer["full_name"];
                $_SESSION["login_time"] = date("Y-m-d H:i:s");
                
                $stmt->close();
                
                header("Location: client_index.php");
                exit;
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
        }
        
        $stmt->close();
    } else {
        $error = "Database error. Please try again. Error: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login - Computer Shop</title>
    <link rel="stylesheet" href="customer_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="wrapper">
        <h1>Customer Login</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="input-box">
                <input type="text" name="username" required 
                       placeholder="Username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                <i class="fas fa-user"></i>
            </div>
            
            <div class="input-box">
                <input type="password" name="password" required 
                       placeholder="Password">
                <i class="fas fa-lock"></i>
            </div>
            
            <div class="remember-forgot">
                <label>
                    <input type="checkbox" name="remember"> Remember me
                </label>
                <a href="forgot_password.php">Forgot password?</a>
            </div>
            
            <button type="submit" class="btn">Login</button>
            
            <div class="register-link">
                <p>Don't have an account? <a href="customer_register.php">Register here</a></p>
            </div>
        </form>
    </div>
</body>
</html>