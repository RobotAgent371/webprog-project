<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pancit Cantoners - Welcome</title>
    <link rel="stylesheet" href="index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="logo">
                <i class="fas fa-desktop"></i>
                <h1>Pancit<span>Cantoners</span></h1>
            </div>
            <p class="tagline">Professional Internet Cafe Management System</p>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Hero Section -->
            <section class="hero">
                <div class="hero-content">
                    <h2>Welcome to Pancit Cantoners</h2>
                    <p class="hero-description">
                        Your ultimate solution for managing internet cafe operations efficiently.
                        Choose your role below to continue.
                    </p>
                </div>
                <div class="hero-image">
                    <i class="fas fa-network-wired"></i>
                </div>
            </section>

            <!-- Role Selection -->
            <section class="role-selection">
                <h3>Select Your Role</h3>
                <p class="selection-subtitle">Choose how you want to access the system</p>
                
                <div class="role-cards">
                    <!-- Customer Card -->
                    <div class="role-card customer-card">
                        <div class="card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-content">
                            <h4>Customer / Client</h4>
                            <p class="card-description">
                                Purchase PC time, manage your sessions, and enjoy our services.
                                Perfect for regular customers and visitors.
                            </p>
                            <ul class="card-features">
                                <li><i class="fas fa-check-circle"></i> Purchase time credits</li>
                                <li><i class="fas fa-check-circle"></i> Track session time</li>
                                <li><i class="fas fa-check-circle"></i> Request extensions</li>
                                <li><i class="fas fa-check-circle"></i> View history</li>
                            </ul>
                        </div>
                        <a href="customer_login.php" class="role-btn customer-btn">
                            <i class="fas fa-sign-in-alt"></i> Customer Login
                        </a>
                    </div>

                    <!-- Admin Card -->
                    <div class="role-card admin-card">
                        <div class="card-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="card-content">
                            <h4>Administrator</h4>
                            <p class="card-description">
                                Manage cafe operations, monitor sessions, and handle customer requests.
                                Staff and management access only.
                            </p>
                            <ul class="card-features">
                                <li><i class="fas fa-check-circle"></i> Manage PC stations</li>
                                <li><i class="fas fa-check-circle"></i> View real-time reports</li>
                                <li><i class="fas fa-check-circle"></i> Handle extensions</li>
                                <li><i class="fas fa-check-circle"></i> System configuration</li>
                            </ul>
                        </div>
                        <a href="login.php" class="role-btn admin-btn">
                            <i class="fas fa-lock"></i> Admin Login
                        </a>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section class="features">
                <h3>Why Choose Pancit Cantoners?</h3>
                <div class="feature-grid">
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h4>Fast & Efficient</h4>
                        <p>Quick session setup and seamless management</p>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Real-time Tracking</h4>
                        <p>Monitor all PC stations in real-time</p>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4>Secure & Reliable</h4>
                        <p>Protected access for both customers and admins</p>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h4>Time Management</h4>
                        <p>Accurate time tracking and billing</p>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h5><i class="fas fa-info-circle"></i> About Pancit Cantoners</h5>
                    <p>A comprehensive internet cafe management system designed for modern cyber cafes.</p>
                </div>
                <div class="footer-section">
                    <h5><i class="fas fa-clock"></i> Operating Hours</h5>
                    <p>Open 24/7<br>Staff available: 8:00 AM - 12:00 AM</p>
                </div>
                <div class="footer-section">
                    <h5><i class="fas fa-phone-alt"></i> Contact</h5>
                    <p>Support: (02) 1234-5678<br>Email: support@netcafepro.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 Pancit Cantoners. All rights reserved.</p>
                <p class="version">v2.0.1</p>
            </div>
        </footer>
    </div>

    <script>
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Button hover effects
            const roleBtns = document.querySelectorAll('.role-btn');
            roleBtns.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Card hover effects
            const roleCards = document.querySelectorAll('.role-card');
            roleCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Animate elements on load
            setTimeout(() => {
                document.querySelector('.hero').style.opacity = '1';
                document.querySelector('.hero').style.transform = 'translateY(0)';
            }, 100);

            setTimeout(() => {
                document.querySelector('.role-selection').style.opacity = '1';
                document.querySelector('.role-selection').style.transform = 'translateY(0)';
            }, 300);
        });
    </script>
</body>
</html>