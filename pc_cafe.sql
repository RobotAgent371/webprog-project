-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 11, 2026 at 11:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pc_cafe`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `ExtendSessionTime` (IN `p_customer_id` INT, IN `p_pc_id` INT, IN `p_credit_type` ENUM('I','II','III'), IN `p_quantity` INT, IN `p_payment_method` ENUM('cash','gcash','paymaya','card'))   BEGIN
    DECLARE v_credit_id INT;
    DECLARE v_duration_minutes INT;
    DECLARE v_cost_per_unit DECIMAL(10,2);
    DECLARE v_total_amount DECIMAL(10,2);
    DECLARE v_minutes_added INT;
    DECLARE v_transaction_id INT;
    DECLARE v_current_end_time TIMESTAMP;
    DECLARE v_current_remaining INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Get credit details
    SELECT credit_id, duration_minutes, cost_php 
    INTO v_credit_id, v_duration_minutes, v_cost_per_unit
    FROM time_credits 
    WHERE credit_type = p_credit_type AND is_active = TRUE;
    
    IF v_credit_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid credit type';
    END IF;
    
    -- Check if customer is using this PC
    IF NOT EXISTS (
        SELECT 1 FROM pc_stations 
        WHERE pc_id = p_pc_id 
        AND current_customer_id = p_customer_id
        AND status = 'occupied'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer is not using this PC';
    END IF;
    
    -- Calculate totals
    SET v_total_amount = v_cost_per_unit * p_quantity;
    SET v_minutes_added = v_duration_minutes * p_quantity;
    
    -- Get current end time and remaining minutes
    SELECT end_time, remaining_minutes 
    INTO v_current_end_time, v_current_remaining
    FROM pc_stations 
    WHERE pc_id = p_pc_id;
    
    -- Insert extension transaction
    INSERT INTO transactions (
        customer_id, 
        pc_id, 
        credit_id, 
        quantity, 
        amount, 
        transaction_type, 
        payment_method, 
        transaction_status
    ) VALUES (
        p_customer_id,
        p_pc_id,
        v_credit_id,
        p_quantity,
        v_total_amount,
        'time_extension',
        p_payment_method,
        'completed'
    );
    
    SET v_transaction_id = LAST_INSERT_ID();
    
    -- Add time credits to customer
    INSERT INTO customer_time_credits (
        customer_id,
        credit_id,
        quantity,
        total_amount,
        remaining_minutes,
        expires_at
    ) VALUES (
        p_customer_id,
        v_credit_id,
        p_quantity,
        v_total_amount,
        v_minutes_added,
        DATE_ADD(NOW(), INTERVAL 30 DAY)
    );
    
    -- Extend PC end time and update remaining minutes
    UPDATE pc_stations 
    SET 
        end_time = DATE_ADD(
            COALESCE(v_current_end_time, NOW()), 
            INTERVAL v_minutes_added MINUTE
        ),
        remaining_minutes = COALESCE(v_current_remaining, 0) + v_minutes_added
    WHERE pc_id = p_pc_id;
    
    -- Update usage session
    UPDATE pc_usage_sessions 
    SET time_credits_used = time_credits_used + p_quantity
    WHERE customer_id = p_customer_id 
    AND pc_id = p_pc_id 
    AND status = 'active';
    
    COMMIT;
    
    -- Return success
    SELECT 
        v_transaction_id AS transaction_id,
        'Time extended successfully' AS message,
        v_total_amount AS total_amount,
        v_minutes_added AS minutes_added;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProcessTransaction` (IN `p_customer_id` INT, IN `p_pc_id` INT, IN `p_credit_type` ENUM('I','II','III'), IN `p_quantity` INT, IN `p_payment_method` ENUM('cash','gcash','paymaya','card'))   BEGIN
    DECLARE v_credit_id INT;
    DECLARE v_duration_minutes INT;
    DECLARE v_cost_per_unit DECIMAL(10,2);
    DECLARE v_total_amount DECIMAL(10,2);
    DECLARE v_total_minutes INT;
    DECLARE v_username VARCHAR(100);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Get customer username
    SELECT username INTO v_username
    FROM customer_accounts 
    WHERE customer_id = p_customer_id;
    
    -- Get credit details
    SELECT credit_id, duration_minutes, cost_php 
    INTO v_credit_id, v_duration_minutes, v_cost_per_unit
    FROM time_credits 
    WHERE credit_type = p_credit_type AND is_active = TRUE;
    
    IF v_credit_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid credit type';
    END IF;
    
    -- Check PC availability
    IF EXISTS (
        SELECT 1 FROM pc_stations 
        WHERE pc_id = p_pc_id 
        AND status = 'occupied'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PC is already occupied';
    END IF;
    
    -- Calculate totals
    SET v_total_amount = v_cost_per_unit * p_quantity;
    SET v_total_minutes = v_duration_minutes * p_quantity;
    
    -- Insert transaction
    INSERT INTO transactions (
        customer_id, 
        pc_id, 
        credit_id, 
        quantity, 
        amount, 
        transaction_type, 
        payment_method, 
        transaction_status
    ) VALUES (
        p_customer_id,
        p_pc_id,
        v_credit_id,
        p_quantity,
        v_total_amount,
        'purchase',
        p_payment_method,
        'completed'
    );
    
    -- Update PC status with username and start time immediately
    UPDATE pc_stations 
    SET 
        status = 'occupied',
        current_customer_id = p_customer_id,
        current_username = v_username,
        start_time = NOW(),
        end_time = DATE_ADD(NOW(), INTERVAL v_total_minutes MINUTE),
        remaining_minutes = v_total_minutes
    WHERE pc_id = p_pc_id;
    
    -- Start PC usage session
    INSERT INTO pc_usage_sessions (
        customer_id,
        pc_id,
        start_time,
        time_credits_used,
        status
    ) VALUES (
        p_customer_id,
        p_pc_id,
        NOW(),
        p_quantity,
        'active'
    );
    
    -- Add time credits to customer
    INSERT INTO customer_time_credits (
        customer_id,
        credit_id,
        quantity,
        total_amount,
        remaining_minutes,
        expires_at
    ) VALUES (
        p_customer_id,
        v_credit_id,
        p_quantity,
        v_total_amount,
        v_total_minutes,
        DATE_ADD(NOW(), INTERVAL 30 DAY)
    );
    
    COMMIT;
    
    -- Return success
    SELECT 
        LAST_INSERT_ID() AS transaction_id,
        'Transaction completed successfully' AS message,
        v_total_amount AS total_amount,
        v_total_minutes AS total_minutes_purchased;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_accounts`
--

CREATE TABLE `customer_accounts` (
  `customer_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_login_sessions`
--

CREATE TABLE `customer_login_sessions` (
  `session_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_time_credits`
--

CREATE TABLE `customer_time_credits` (
  `purchase_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `credit_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `remaining_minutes` int(11) NOT NULL,
  `purchase_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `transaction_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_sessions`
--

CREATE TABLE `login_sessions` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pc_logs`
--

CREATE TABLE `pc_logs` (
  `log_id` int(11) NOT NULL,
  `pc_number` int(11) NOT NULL,
  `pc_name` varchar(50) DEFAULT NULL,
  `pc_user` varchar(100) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `status` enum('active','completed','extended') DEFAULT 'completed',
  `action_type` enum('start','stop','extend') NOT NULL,
  `login_time` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `minutes_added` int(11) DEFAULT 0,
  `duration_minutes` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pc_stations`
--

CREATE TABLE `pc_stations` (
  `pc_id` int(11) NOT NULL,
  `pc_number` int(11) NOT NULL,
  `pc_name` varchar(50) DEFAULT NULL,
  `status` enum('available','occupied','maintenance','offline') DEFAULT 'available',
  `current_customer_id` int(11) DEFAULT NULL,
  `current_username` varchar(100) DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `remaining_minutes` int(11) DEFAULT 0,
  `last_maintenance` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pc_stations`
--

INSERT INTO `pc_stations` (`pc_id`, `pc_number`, `pc_name`, `status`, `current_customer_id`, `current_username`, `start_time`, `end_time`, `remaining_minutes`, `last_maintenance`) VALUES
(1, 1, NULL, 'available', NULL, 'Test', NULL, NULL, 178, NULL),
(2, 2, NULL, 'available', NULL, 'Testing', NULL, NULL, 118, NULL),
(3, 3, NULL, 'available', NULL, 'Test', NULL, NULL, 58, NULL),
(4, 4, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(5, 5, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(6, 6, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(7, 7, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(8, 8, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(9, 9, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL),
(10, 10, NULL, 'available', NULL, NULL, NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pc_usage_sessions`
--

CREATE TABLE `pc_usage_sessions` (
  `usage_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `pc_id` int(11) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `start_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_time` timestamp NULL DEFAULT NULL,
  `total_minutes_used` int(11) DEFAULT 0,
  `time_credits_used` int(11) DEFAULT 0,
  `status` enum('active','completed','cancelled') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `time_credits`
--

CREATE TABLE `time_credits` (
  `credit_id` int(11) NOT NULL,
  `credit_name` varchar(50) NOT NULL,
  `credit_type` enum('I','II','III') NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `cost_php` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `time_credits`
--

INSERT INTO `time_credits` (`credit_id`, `credit_name`, `credit_type`, `duration_minutes`, `cost_php`, `is_active`) VALUES
(1, 'Time Credit I', 'I', 15, 8.00, 1),
(2, 'Time Credit II', 'II', 30, 15.00, 1),
(3, 'Time Credit III', 'III', 60, 25.00, 1);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `pc_id` int(11) DEFAULT NULL,
  `credit_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_type` enum('purchase','time_extension','refund') NOT NULL,
  `payment_method` enum('cash','gcash','paymaya','card') DEFAULT 'cash',
  `transaction_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `customer_accounts`
--
ALTER TABLE `customer_accounts`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `customer_login_sessions`
--
ALTER TABLE `customer_login_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_customer_active` (`customer_id`,`is_active`);

--
-- Indexes for table `customer_time_credits`
--
ALTER TABLE `customer_time_credits`
  ADD PRIMARY KEY (`purchase_id`),
  ADD UNIQUE KEY `unique_customer_credit` (`customer_id`,`credit_id`),
  ADD KEY `credit_id` (`credit_id`),
  ADD KEY `idx_customer_active` (`customer_id`,`is_active`),
  ADD KEY `transaction_id` (`transaction_id`);

--
-- Indexes for table `login_sessions`
--
ALTER TABLE `login_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pc_logs`
--
ALTER TABLE `pc_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_pc_number` (`pc_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_login_time` (`login_time`);

--
-- Indexes for table `pc_stations`
--
ALTER TABLE `pc_stations`
  ADD PRIMARY KEY (`pc_id`),
  ADD UNIQUE KEY `pc_number` (`pc_number`),
  ADD KEY `current_customer_id` (`current_customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_pc_number` (`pc_number`);

--
-- Indexes for table `pc_usage_sessions`
--
ALTER TABLE `pc_usage_sessions`
  ADD PRIMARY KEY (`usage_id`),
  ADD KEY `idx_active_session` (`customer_id`,`pc_id`,`status`),
  ADD KEY `idx_pc_status` (`pc_id`,`status`);

--
-- Indexes for table `time_credits`
--
ALTER TABLE `time_credits`
  ADD PRIMARY KEY (`credit_id`),
  ADD UNIQUE KEY `unique_credit_type` (`credit_type`),
  ADD KEY `idx_credit_type` (`credit_type`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `pc_id` (`pc_id`),
  ADD KEY `credit_id` (`credit_id`),
  ADD KEY `idx_customer_date` (`customer_id`,`transaction_date`),
  ADD KEY `idx_status` (`transaction_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_accounts`
--
ALTER TABLE `customer_accounts`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `customer_login_sessions`
--
ALTER TABLE `customer_login_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_time_credits`
--
ALTER TABLE `customer_time_credits`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_sessions`
--
ALTER TABLE `login_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pc_logs`
--
ALTER TABLE `pc_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pc_stations`
--
ALTER TABLE `pc_stations`
  MODIFY `pc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `pc_usage_sessions`
--
ALTER TABLE `pc_usage_sessions`
  MODIFY `usage_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `time_credits`
--
ALTER TABLE `time_credits`
  MODIFY `credit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`customer_id`);

--
-- Constraints for table `customer_login_sessions`
--
ALTER TABLE `customer_login_sessions`
  ADD CONSTRAINT `customer_login_sessions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`customer_id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_time_credits`
--
ALTER TABLE `customer_time_credits`
  ADD CONSTRAINT `customer_time_credits_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_time_credits_ibfk_2` FOREIGN KEY (`credit_id`) REFERENCES `time_credits` (`credit_id`),
  ADD CONSTRAINT `customer_time_credits_ibfk_3` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`);

--
-- Constraints for table `login_sessions`
--
ALTER TABLE `login_sessions`
  ADD CONSTRAINT `login_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pc_stations`
--
ALTER TABLE `pc_stations`
  ADD CONSTRAINT `pc_stations_ibfk_1` FOREIGN KEY (`current_customer_id`) REFERENCES `customer_accounts` (`customer_id`) ON DELETE SET NULL;

--
-- Constraints for table `pc_usage_sessions`
--
ALTER TABLE `pc_usage_sessions`
  ADD CONSTRAINT `pc_usage_sessions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pc_usage_sessions_ibfk_2` FOREIGN KEY (`pc_id`) REFERENCES `pc_stations` (`pc_id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`pc_id`) REFERENCES `pc_stations` (`pc_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`credit_id`) REFERENCES `time_credits` (`credit_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
