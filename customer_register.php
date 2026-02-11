<?php
include "db.php";

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = sanitize($_POST['email']);
    $full_name = sanitize($_POST['full_name']);
    $phone = sanitize($_POST['phone']);
    
    // Validation
    if (empty($username) || empty($password) || empty($email) || empty($full_name)) {
        $error = "All required fields must be filled!";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long!";
    } else {
        // Check if username or email already exists
        $stmt = $conn->prepare("SELECT customer_id FROM customer_accounts WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $error = "Username or email already exists!";
        } else {
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new customer
            $stmt = $conn->prepare("INSERT INTO customer_accounts (username, password_hash, email, full_name, phone_number) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $username, $password_hash, $email, $full_name, $phone);
            
            if ($stmt->execute()) {
                $success = "Registration successful! You can now login.";
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Register - Computer Shop</title>
    <link rel="stylesheet" href="customer_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="wrapper">
        <h1>Customer Register</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
                <script>
                    setTimeout(function() {
                        window.location.href = 'customer_login.php';
                    }, 3000);
                </script>
            </div>
        <?php endif; ?>
        
        <form id="registerForm" method="POST" action="">
            <div class="input-box">
                <input type="text" id="username" name="username" required 
                       placeholder="Username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                <i class="fas fa-user"></i>
            </div>
            
            <div class="input-box">
                <input type="email" id="email" name="email" required 
                       placeholder="Email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                <i class="fas fa-envelope"></i>
            </div>
            
            <div class="input-box">
                <input type="text" id="full_name" name="full_name" required 
                       placeholder="Full Name" value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                <i class="fas fa-id-card"></i>
            </div>
            
            <div class="input-box">
                <input type="tel" id="phone" name="phone" 
                       placeholder="Phone Number" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                <i class="fas fa-phone"></i>
            </div>
            
            <div class="input-box">
                <input type="password" id="password" name="password" required 
                       placeholder="Password (min. 6 characters)">
                <i class="fas fa-lock"></i>
                <div class="password-strength">
                    <div class="strength-meter" id="strengthMeter"></div>
                </div>
            </div>
            
            <div class="input-box">
                <input type="password" id="confirm_password" name="confirm_password" required 
                       placeholder="Confirm Password">
                <i class="fas fa-lock"></i>
            </div>
            
            <button type="submit" class="btn">Register</button>
            
            <div class="register-link">
                <p>Already have an account? <a href="customer_login.php">Login here</a></p>
            </div>
        </form>
    </div>

    <script>
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            let valid = true;
            
            // Validate username
            const username = document.getElementById('username').value.trim();
            if (username.length < 3) {
                alert('Username must be at least 3 characters long');
                valid = false;
            }
            
            // Validate email
            const email = document.getElementById('email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alert('Please enter a valid email address');
                valid = false;
            }
            
            // Validate password
            const password = document.getElementById('password').value;
            if (password.length < 6) {
                alert('Password must be at least 6 characters long');
                valid = false;
            }
            
            // Validate password confirmation
            const confirmPassword = document.getElementById('confirm_password').value;
            if (password !== confirmPassword) {
                alert('Passwords do not match');
                valid = false;
            }
            
            if (!valid) {
                e.preventDefault();
            }
        });
        
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const meter = document.getElementById('strengthMeter');
            let strength = 0;
            
            if (password.length >= 6) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            if (/[^A-Za-z0-9]/.test(password)) strength += 25;
            
            meter.style.width = strength + '%';
            
            // Set color based on strength
            if (strength <= 25) {
                meter.style.background = '#f44336';
            } else if (strength <= 50) {
                meter.style.background = '#ff9800';
            } else if (strength <= 75) {
                meter.style.background = '#4CAF50';
            } else {
                meter.style.background = '#4CAF50';
            }
        });
    </script>
</body>
</html>