-- ============================================================
-- POS Phase 2 Migration — Additional Features
-- Run after the original POS migration
-- ============================================================

-- Customers
CREATE TABLE IF NOT EXISTS `pos_customers` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `name`            VARCHAR(150) NOT NULL,
  `phone`           VARCHAR(30) DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `address`         TEXT DEFAULT NULL,
  `loyalty_points`  INT NOT NULL DEFAULT 0,
  `credit_limit`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `credit_balance`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes`           TEXT DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_customers_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Suppliers
CREATE TABLE IF NOT EXISTS `pos_suppliers` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `name`            VARCHAR(150) NOT NULL,
  `contact_person`  VARCHAR(100) DEFAULT NULL,
  `phone`           VARCHAR(30) DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `address`         TEXT DEFAULT NULL,
  `tax_pin`         VARCHAR(50) DEFAULT NULL,
  `payment_terms`   VARCHAR(100) DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_suppliers_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock Adjustments
CREATE TABLE IF NOT EXISTS `pos_stock_adjustments` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `product_id`      INT NOT NULL,
  `product_name`    VARCHAR(150) NOT NULL,
  `adjustment_type` ENUM('in','out','damage','loss','correction','return') NOT NULL DEFAULT 'in',
  `quantity`        DECIMAL(12,3) NOT NULL,
  `quantity_before` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `quantity_after`  DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unit_cost`       DECIMAL(12,2) DEFAULT NULL,
  `reference`       VARCHAR(100) DEFAULT NULL,
  `reason`          VARCHAR(255) DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_stock_adj_org` (`org_id`),
  KEY `idx_pos_stock_adj_prod` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Orders
CREATE TABLE IF NOT EXISTS `pos_purchases` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `supplier_id`     INT DEFAULT NULL,
  `po_number`       VARCHAR(50) NOT NULL,
  `order_date`      DATE NOT NULL,
  `expected_date`   DATE DEFAULT NULL,
  `received_date`   DATE DEFAULT NULL,
  `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`  VARCHAR(50) DEFAULT 'cash',
  `status`          ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_purchases_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order Items
CREATE TABLE IF NOT EXISTS `pos_purchase_items` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id`     INT NOT NULL,
  `product_id`      INT DEFAULT NULL,
  `product_name`    VARCHAR(150) NOT NULL,
  `quantity`        DECIMAL(12,3) NOT NULL,
  `quantity_received` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unit_cost`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  KEY `idx_pos_pur_items_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Returns & Refunds
CREATE TABLE IF NOT EXISTS `pos_returns` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `sale_id`         INT DEFAULT NULL,
  `return_number`   VARCHAR(50) NOT NULL,
  `return_date`     DATE NOT NULL,
  `customer_name`   VARCHAR(150) DEFAULT NULL,
  `return_reason`   VARCHAR(255) DEFAULT NULL,
  `refund_method`   ENUM('cash','mpesa','card','credit','exchange') NOT NULL DEFAULT 'cash',
  `refund_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `restock`         TINYINT(1) NOT NULL DEFAULT 1,
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_returns_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Return Items
CREATE TABLE IF NOT EXISTS `pos_return_items` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `return_id`       INT NOT NULL,
  `product_id`      INT DEFAULT NULL,
  `product_name`    VARCHAR(150) NOT NULL,
  `quantity`        DECIMAL(12,3) NOT NULL,
  `unit_price`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  KEY `idx_pos_ret_items_return` (`return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cashier Shifts
CREATE TABLE IF NOT EXISTS `pos_shifts` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `cashier_id`      INT NOT NULL,
  `cashier_name`    VARCHAR(150) NOT NULL,
  `shift_date`      DATE NOT NULL,
  `start_time`      DATETIME NOT NULL,
  `end_time`        DATETIME DEFAULT NULL,
  `opening_float`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `closing_float`   DECIMAL(12,2) DEFAULT NULL,
  `total_sales`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_cash`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_mpesa`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_card`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_expenses`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_returns`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `transactions`    INT NOT NULL DEFAULT 0,
  `status`          ENUM('open','closed') NOT NULL DEFAULT 'open',
  `notes`           TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_shifts_org` (`org_id`),
  KEY `idx_pos_shifts_cashier` (`cashier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses
CREATE TABLE IF NOT EXISTS `pos_expenses` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `shift_id`        INT DEFAULT NULL,
  `category`        VARCHAR(100) NOT NULL DEFAULT 'General',
  `description`     VARCHAR(255) NOT NULL,
  `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`  VARCHAR(50) DEFAULT 'cash',
  `receipt_no`      VARCHAR(100) DEFAULT NULL,
  `expense_date`    DATE NOT NULL,
  `created_by`      INT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_expenses_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Discount Codes / Promotions
CREATE TABLE IF NOT EXISTS `pos_discounts` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `code`            VARCHAR(50) NOT NULL,
  `name`            VARCHAR(150) NOT NULL,
  `type`            ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `value`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `min_purchase`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `max_uses`        INT DEFAULT NULL,
  `uses_count`      INT NOT NULL DEFAULT 0,
  `valid_from`      DATE DEFAULT NULL,
  `valid_to`        DATE DEFAULT NULL,
  `applies_to`      ENUM('all','category','product') NOT NULL DEFAULT 'all',
  `applies_id`      INT DEFAULT NULL,
  `status`          ENUM('active','inactive','expired') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pos_discounts_org` (`org_id`),
  UNIQUE KEY `uniq_discount_code` (`org_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Alter existing tables ────────────────────────────────────────
ALTER TABLE `pos_sales`
  ADD COLUMN IF NOT EXISTS `customer_id`  INT DEFAULT NULL AFTER `org_id`,
  ADD COLUMN IF NOT EXISTS `shift_id`     INT DEFAULT NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `discount_code` VARCHAR(50) DEFAULT NULL AFTER `discount`,
  ADD COLUMN IF NOT EXISTS `receipt_no`   VARCHAR(50) DEFAULT NULL AFTER `discount_code`;

ALTER TABLE `pos_products`
  ADD COLUMN IF NOT EXISTS `supplier_id`  INT DEFAULT NULL AFTER `category_id`,
  ADD COLUMN IF NOT EXISTS `cost_price`   DECIMAL(12,2) DEFAULT 0.00 AFTER `price`;
