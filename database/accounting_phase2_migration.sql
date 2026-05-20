-- ─────────────────────────────────────────────────────────────────────────────
-- Accounting Module Phase 2 — Additional Tables
-- Run via phpMyAdmin or CLI: mysql -u user -p dbname < accounting_phase2_migration.sql
-- Safe to re-run (uses IF NOT EXISTS)
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Payments received against client invoices (Accounts Receivable)
CREATE TABLE IF NOT EXISTS acc_payments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    invoice_id   INT DEFAULT NULL,
    payment_date DATE NOT NULL,
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    method       VARCHAR(50) DEFAULT 'Cash',
    reference    VARCHAR(100) DEFAULT NULL,
    notes        TEXT,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org     (org_id),
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Vendor Bills / Purchase Invoices (Accounts Payable)
CREATE TABLE IF NOT EXISTS acc_bills (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    bill_no      VARCHAR(50) DEFAULT NULL,
    vendor_name  VARCHAR(255) NOT NULL,
    vendor_email VARCHAR(255) DEFAULT NULL,
    bill_date    DATE NOT NULL,
    due_date     DATE NOT NULL,
    subtotal     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount   DECIMAL(15,2) DEFAULT 0.00,
    total        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    paid_amount  DECIMAL(15,2) DEFAULT 0.00,
    balance      DECIMAL(15,2) DEFAULT 0.00,
    status       ENUM('draft','pending','partial','paid','overdue','cancelled') DEFAULT 'pending',
    notes        TEXT,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org    (org_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bill Line Items
CREATE TABLE IF NOT EXISTS acc_bill_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    bill_id     INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity    DECIMAL(10,2) DEFAULT 1.00,
    unit_price  DECIMAL(15,2) DEFAULT 0.00,
    amount      DECIMAL(15,2) DEFAULT 0.00,
    account_id  INT DEFAULT NULL,
    INDEX idx_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tax Rates
CREATE TABLE IF NOT EXISTS acc_taxes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    org_id     INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    rate       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    type       ENUM('exclusive','inclusive') DEFAULT 'exclusive',
    is_default TINYINT(1) DEFAULT 0,
    status     ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Budgets
CREATE TABLE IF NOT EXISTS acc_budgets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    account_id   INT DEFAULT NULL,
    category     VARCHAR(100) DEFAULT NULL,
    budget_year  SMALLINT NOT NULL,
    budget_month TINYINT DEFAULT 0,  -- 0 = full year; 1-12 = specific month
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_budget (org_id, account_id, budget_year, budget_month),
    INDEX idx_org_year (org_id, budget_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default tax rate for new orgs (optional — insert manually per org)
-- INSERT INTO acc_taxes (org_id, name, rate, type, is_default, status)
-- VALUES (1, 'VAT 16%', 16.00, 'exclusive', 1, 'active');
