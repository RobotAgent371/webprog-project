<?php
include "db.php";
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["logUser"];
    $password = $_POST["logPass"];

    // Get IP address
    $ip_address = $_SERVER["REMOTE_ADDR"];

    // 1. Get user
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user["password"])) {

        // 2. Insert login session record
        $stmt = $conn->prepare(
            "INSERT INTO login_sessions (user_id, login_time, ip_address)
             VALUES (?, NOW(), ?)"
        );
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param("is", $user["id"], $ip_address);
        $stmt->execute();

        // 3. Store session data
        $_SESSION["user_id"] = $user["id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["session_id"] = $conn->insert_id;
        $_SESSION["login_time"] = date("Y-m-d H:i:s");

        header("Location: PCstatus.php");
        exit;
    } else {
        $error = "Invalid username or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
<div class="wrapper">
    <form method="POST">
        <h1>Login</h1>
        <p>log-in your Shop account</p>

        <?php if (!empty($error)) echo "<p style='color:red'>$error</p>"; ?>

        <div class="input-box">
            <input type="text" name="logUser" placeholder="Username" required>
        </div>
        <div class="input-box">
            <input type="password" name="logPass" placeholder="Password" required>
        </div>

        <button type="submit" class="btn">Login</button>

        <div class="register-link">
            <p>Don't have an account?
                <a href="register.php">Register</a>
            </p>
        </div>
    </form>
</div>
</body>
</html>