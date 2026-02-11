<?php
include "db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["regUser"];
    $email = $_POST["regEmail"];
    $password = $_POST["regPass"];
    $confirm = $_POST["regConfirmPass"];

    if ($password !== $confirm) {
        $error = "Passwords do not match";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO users (username,email,password) VALUES (?,?,?)"
        );
        $stmt->bind_param("sss", $username, $email, $hash);
        $stmt->execute();

        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link rel="stylesheet" href="register.css">
</head>
<body>
<div class="wrapper">
    <h1>Sign Up</h1>
    <p>Create your Shop account</p>

    <?php if (!empty($error)) echo "<p style='color:red'>$error</p>"; ?>

    <form method="POST">
        <div class="input-box">
            <input type="text" name="regUser" placeholder="Username" required>
        </div>
        <div class="input-box">
            <input type="email" name="regEmail" placeholder="Email" required>
        </div>
        <div class="input-box">
            <input type="password" name="regPass" placeholder="Password" required>
        </div>
        <div class="input-box">
            <input type="password" name="regConfirmPass" placeholder="Confirm Password" required>
        </div>

        <button type="submit" class="btn">Sign Up</button>
        <div class="register-link">
            <p>Already have an account? <a href="login.php">Login</a></p>
        </div>
    </form>
</div>
</body>
</html>
