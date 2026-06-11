-- ============================================================
-- Courier Management Module — Database Migration
-- ============================================================

CREATE TABLE IF NOT EXISTS courier_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_courier_setting (org_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    address TEXT,
    city VARCHAR(100),
    phone VARCHAR(50),
    email VARCHAR(100),
    manager VARCHAR(100),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2) DEFAULT 0.00,
    delivery_days INT DEFAULT 1,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_tracking_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    stage_name VARCHAR(100) NOT NULL,
    stage_code VARCHAR(50) NOT NULL,
    color VARCHAR(20) DEFAULT '#007bff',
    icon VARCHAR(50) DEFAULT 'fas fa-circle',
    sort_order INT DEFAULT 0,
    is_final TINYINT(1) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(50),
    branch_id INT DEFAULT NULL,
    service_area TEXT,
    photo VARCHAR(255),
    bio TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS couriers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    tracking_id VARCHAR(50) NOT NULL,
    sender_name VARCHAR(150) NOT NULL,
    sender_email VARCHAR(100),
    sender_phone VARCHAR(50),
    sender_address TEXT,
    receiver_name VARCHAR(150) NOT NULL,
    receiver_email VARCHAR(100),
    receiver_phone VARCHAR(50),
    receiver_address TEXT,
    category_id INT DEFAULT NULL,
    service_type_id INT DEFAULT NULL,
    branch_id INT DEFAULT NULL,
    agent_id INT DEFAULT NULL,
    weight DECIMAL(8,2) DEFAULT NULL,
    length_cm DECIMAL(8,2) DEFAULT NULL,
    width_cm DECIMAL(8,2) DEFAULT NULL,
    height_cm DECIMAL(8,2) DEFAULT NULL,
    description TEXT,
    declared_value DECIMAL(10,2) DEFAULT 0.00,
    price DECIMAL(10,2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT 'pending',
    approval_status ENUM('pending','approved','rejected') DEFAULT 'approved',
    source ENUM('admin','customer') DEFAULT 'admin',
    notes TEXT,
    pickup_date DATE DEFAULT NULL,
    expected_delivery DATE DEFAULT NULL,
    actual_delivery DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tracking_id (tracking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_tracking_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    courier_id INT NOT NULL,
    stage_code VARCHAR(50),
    stage_name VARCHAR(100),
    location VARCHAR(200),
    notes TEXT,
    lat DECIMAL(9,6) DEFAULT NULL,
    lng DECIMAL(9,6) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    courier_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    method ENUM('cash','mobile_money','bank_transfer','card','cheque','other') DEFAULT 'cash',
    reference VARCHAR(100),
    description TEXT,
    receipt_file VARCHAR(255),
    status ENUM('pending','cleared','failed','refunded') DEFAULT 'pending',
    recorded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courier_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    client_email VARCHAR(100),
    client_phone VARCHAR(50),
    client_company VARCHAR(150),
    service_level VARCHAR(100),
    start_date DATE,
    end_date DATE,
    delivery_timeframe VARCHAR(100),
    quality_standards TEXT,
    contract_details LONGTEXT,
    status ENUM('active','expired','terminated','draft') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
