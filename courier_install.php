<?php
/**
 * Courier Module Installer
 * Run this file once via browser: http://localhost/courier_install.php
 * DELETE this file after running.
 */
require_once __DIR__ . '/config/database.php';

$results = [];

// ── 1. Register module in modules table ──────────────────────
try {
    $pdo->exec("INSERT IGNORE INTO modules
        (slug, name, description, icon, color, category, monthly_price, annual_price, sort_order, status)
        VALUES
        ('courier', 'Courier Management',
         'Parcel tracking, delivery agents, payments, agreements & route management',
         'fas fa-shipping-fast', '#1565c0', 'Logistics', 3000, 30000, 21, 'active')
    ");
    $affected = $pdo->query("SELECT id, slug, status FROM modules WHERE slug='courier'")->fetch();
    $results[] = ['ok' => true, 'msg' => 'Module registered: id=' . ($affected['id'] ?? '?') . ', status=' . ($affected['status'] ?? '?')];
} catch (Exception $e) {
    $results[] = ['ok' => false, 'msg' => 'Module registration failed: ' . $e->getMessage()];
}

// ── 2. Add module_id column to invoices (billing flow) ───────
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS module_id INT DEFAULT NULL AFTER subscription_id");
    $results[] = ['ok' => true, 'msg' => 'invoices.module_id column ensured'];
} catch (Exception $e) {
    // MySQL < 8 doesn't support IF NOT EXISTS on ALTER COLUMN — try without it
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'module_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN module_id INT DEFAULT NULL AFTER subscription_id");
        }
        $results[] = ['ok' => true, 'msg' => 'invoices.module_id column ensured (fallback)'];
    } catch (Exception $e2) {
        $results[] = ['ok' => false, 'msg' => 'invoices.module_id: ' . $e2->getMessage()];
    }
}

// ── 3. Create courier tables ──────────────────────────────────
$tables = [

"CREATE TABLE IF NOT EXISTS courier_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_courier_setting (org_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_branches (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2) DEFAULT 0.00,
    delivery_days INT DEFAULT 1,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_tracking_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    stage_name VARCHAR(100) NOT NULL,
    stage_code VARCHAR(50) NOT NULL,
    color VARCHAR(20) DEFAULT '#007bff',
    icon VARCHAR(50) DEFAULT 'fas fa-circle',
    sort_order INT DEFAULT 0,
    is_final TINYINT(1) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_agents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS couriers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_tracking_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    courier_id INT NOT NULL,
    stage_code VARCHAR(50),
    stage_name VARCHAR(100),
    location VARCHAR(200),
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS courier_agreements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ── Core billing table for module purchases ──────────────────
"CREATE TABLE IF NOT EXISTS invoice_module_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id  INT NOT NULL,
    module_id   INT NOT NULL,
    amount      DECIMAL(12,2) DEFAULT 0.00,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)  REFERENCES modules(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($tables as $sql) {
    preg_match('/TABLE IF NOT EXISTS (\w+)/', $sql, $m);
    $tname = $m[1] ?? 'unknown';
    try {
        $pdo->exec($sql);
        $results[] = ['ok' => true, 'msg' => "Table created/verified: $tname"];
    } catch (Exception $e) {
        $results[] = ['ok' => false, 'msg' => "Table $tname failed: " . $e->getMessage()];
    }
}

// ── 3. Verify module is now visible ──────────────────────────
try {
    $row = $pdo->query("SELECT id, slug, name, status, sort_order FROM modules WHERE slug='courier'")->fetch();
    if ($row) {
        $results[] = ['ok' => true, 'msg' => "✅ Verified in DB: id={$row['id']}, slug={$row['slug']}, status={$row['status']}, sort_order={$row['sort_order']}"];
    } else {
        $results[] = ['ok' => false, 'msg' => '❌ Module NOT found in modules table after insert — check table structure'];
    }
} catch (Exception $e) {
    $results[] = ['ok' => false, 'msg' => 'Verification error: ' . $e->getMessage()];
}

// ── 4. Show modules table columns (debug) ────────────────────
try {
    $cols = $pdo->query("DESCRIBE modules")->fetchAll();
    $colNames = array_column($cols, 'Field');
    $results[] = ['ok' => true, 'msg' => 'Modules table columns: ' . implode(', ', $colNames)];
} catch (Exception $e) {
    $results[] = ['ok' => false, 'msg' => 'Could not describe modules table: ' . $e->getMessage()];
}

$allOk = !in_array(false, array_column($results, 'ok'));
?>
<!DOCTYPE html>
<html>
<head>
  <title>Courier Module Installer</title>
  <style>
    body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; background: #f5f7fa; }
    h2 { color: #1565c0; }
    .result { padding: 10px 15px; margin: 6px 0; border-radius: 6px; font-size: 14px; }
    .ok { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
    .err { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
    .banner { padding: 16px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 16px; }
    .banner.ok { background: #d4edda; color: #155724; }
    .banner.err { background: #f8d7da; color: #721c24; }
    .warn { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin-top: 20px; font-size: 13px; }
  </style>
</head>
<body>
  <h2>🚚 Courier Module Installer</h2>
  <div class="banner <?= $allOk ? 'ok' : 'err' ?>">
    <?= $allOk ? '✅ Installation complete! Courier module is now live.' : '⚠️ Some steps had errors. Check below.' ?>
  </div>
  <?php foreach ($results as $r): ?>
  <div class="result <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
  <?php endforeach; ?>
  <div class="warn">
    ⚠️ <strong>Security:</strong> Delete this file immediately after installation.<br>
    Path: <code><?= __FILE__ ?></code>
  </div>
</body>
</html>
