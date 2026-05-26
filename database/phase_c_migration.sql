-- ══════════════════════════════════════════════════════════════
-- OrbitDesk Workspace — Phase C Migration
-- 10 modules × 3 new pages = 30 new feature tables
-- Run AFTER phase_b_migration.sql
-- ══════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ──────────────────────────────────────────────────────────────
-- MANUFACTURING
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mfg_suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    contact_person  VARCHAR(150),
    email           VARCHAR(150),
    phone           VARCHAR(50),
    address         TEXT,
    category        VARCHAR(100),
    lead_time_days  INT DEFAULT 0,
    payment_terms   VARCHAR(100),
    rating          TINYINT DEFAULT 3 COMMENT '1-5',
    status          ENUM('active','inactive') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_inventory (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    material_id     INT UNSIGNED COMMENT 'FK mfg_materials',
    product_id      INT UNSIGNED COMMENT 'FK mfg_products',
    item_type       ENUM('raw_material','finished_good','wip') DEFAULT 'raw_material',
    item_name       VARCHAR(200) NOT NULL,
    sku             VARCHAR(100),
    qty_on_hand     DECIMAL(14,4) DEFAULT 0,
    qty_reserved    DECIMAL(14,4) DEFAULT 0,
    reorder_level   DECIMAL(14,4) DEFAULT 0,
    unit_cost       DECIMAL(14,2) DEFAULT 0,
    warehouse       VARCHAR(100),
    location        VARCHAR(100) COMMENT 'Bin/shelf',
    last_counted_at DATE,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_procurement (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    po_number       VARCHAR(50) NOT NULL,
    supplier_id     INT UNSIGNED,
    order_date      DATE NOT NULL,
    expected_date   DATE,
    received_date   DATE,
    status          ENUM('draft','sent','partial','received','cancelled') DEFAULT 'draft',
    total_amount    DECIMAL(14,2) DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_procurement_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    procurement_id  INT UNSIGNED NOT NULL,
    material_id     INT UNSIGNED,
    item_name       VARCHAR(200),
    qty_ordered     DECIMAL(14,4) DEFAULT 0,
    qty_received    DECIMAL(14,4) DEFAULT 0,
    unit_price      DECIMAL(14,2) DEFAULT 0,
    line_total      DECIMAL(14,2) DEFAULT 0,
    INDEX idx_po (procurement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- SALON
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS salon_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED,
    appointment_id  INT UNSIGNED,
    reference       VARCHAR(100),
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount        DECIMAL(14,2) DEFAULT 0,
    payment_method  ENUM('cash','mpesa','card','bank','other') DEFAULT 'cash',
    payment_date    DATE NOT NULL,
    payment_type    ENUM('appointment','package','deposit','other') DEFAULT 'appointment',
    status          ENUM('pending','paid','refunded') DEFAULT 'paid',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salon_expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    category        VARCHAR(100),
    description     VARCHAR(255) NOT NULL,
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    vendor          VARCHAR(150),
    expense_date    DATE NOT NULL,
    payment_method  ENUM('cash','mpesa','card','bank','other') DEFAULT 'cash',
    reference       VARCHAR(100),
    receipt_url     VARCHAR(500),
    status          ENUM('pending','approved','rejected') DEFAULT 'approved',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salon_promotions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    promo_code      VARCHAR(50),
    discount_type   ENUM('percent','fixed') DEFAULT 'percent',
    discount_value  DECIMAL(10,2) DEFAULT 0,
    min_amount      DECIMAL(14,2) DEFAULT 0,
    applies_to      ENUM('all','services','packages') DEFAULT 'all',
    start_date      DATE,
    end_date        DATE,
    usage_limit     INT DEFAULT 0 COMMENT '0=unlimited',
    usage_count     INT DEFAULT 0,
    status          ENUM('active','inactive','expired') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- RETAIL
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS retail_customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    email           VARCHAR(150),
    phone           VARCHAR(50),
    address         TEXT,
    customer_type   ENUM('retail','wholesale','vip') DEFAULT 'retail',
    credit_limit    DECIMAL(14,2) DEFAULT 0,
    loyalty_points  INT DEFAULT 0,
    total_purchases DECIMAL(14,2) DEFAULT 0,
    status          ENUM('active','inactive','blocked') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS retail_expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    category        VARCHAR(100),
    description     VARCHAR(255) NOT NULL,
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    vendor          VARCHAR(150),
    expense_date    DATE NOT NULL,
    payment_method  ENUM('cash','mpesa','card','bank','other') DEFAULT 'cash',
    reference       VARCHAR(100),
    status          ENUM('pending','approved','rejected') DEFAULT 'approved',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS retail_transfers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    transfer_number VARCHAR(50) NOT NULL,
    product_id      INT UNSIGNED,
    product_name    VARCHAR(200),
    from_location   VARCHAR(100),
    to_location     VARCHAR(100),
    quantity        DECIMAL(14,4) NOT NULL DEFAULT 0,
    unit_cost       DECIMAL(14,2) DEFAULT 0,
    transfer_date   DATE NOT NULL,
    reason          VARCHAR(255),
    status          ENUM('pending','in_transit','completed','cancelled') DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- SALES
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sales_targets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    agent_name      VARCHAR(150),
    period_type     ENUM('monthly','quarterly','annual') DEFAULT 'monthly',
    period          VARCHAR(20) COMMENT 'e.g. 2025-06 or 2025-Q2',
    target_amount   DECIMAL(14,2) DEFAULT 0,
    achieved_amount DECIMAL(14,2) DEFAULT 0,
    target_orders   INT DEFAULT 0,
    achieved_orders INT DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_returns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    return_number   VARCHAR(50) NOT NULL,
    order_id        INT UNSIGNED,
    customer_id     INT UNSIGNED,
    return_date     DATE NOT NULL,
    reason          VARCHAR(255),
    total_amount    DECIMAL(14,2) DEFAULT 0,
    refund_method   ENUM('cash','credit_note','replacement','bank') DEFAULT 'credit_note',
    status          ENUM('pending','approved','processed','rejected') DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_return_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id       INT UNSIGNED NOT NULL,
    product_name    VARCHAR(200),
    qty_returned    DECIMAL(14,4) DEFAULT 1,
    unit_price      DECIMAL(14,2) DEFAULT 0,
    line_total      DECIMAL(14,2) DEFAULT 0,
    condition_note  VARCHAR(255),
    INDEX idx_ret (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED,
    customer_id     INT UNSIGNED,
    reference       VARCHAR(100),
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method  ENUM('cash','mpesa','bank','card','cheque','credit_note') DEFAULT 'cash',
    payment_date    DATE NOT NULL,
    status          ENUM('pending','confirmed','bounced') DEFAULT 'confirmed',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- RENTAL
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rental_utilities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED,
    tenant_id       INT UNSIGNED,
    utility_type    ENUM('water','electricity','gas','internet','service_charge','other') DEFAULT 'electricity',
    billing_period  VARCHAR(20) COMMENT 'YYYY-MM',
    prev_reading    DECIMAL(12,4) DEFAULT 0,
    curr_reading    DECIMAL(12,4) DEFAULT 0,
    consumption     DECIMAL(12,4) DEFAULT 0,
    rate_per_unit   DECIMAL(10,4) DEFAULT 0,
    fixed_charge    DECIMAL(14,2) DEFAULT 0,
    total_amount    DECIMAL(14,2) DEFAULT 0,
    due_date        DATE,
    paid_date       DATE,
    status          ENUM('unpaid','paid','overdue') DEFAULT 'unpaid',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rental_agreements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    agreement_number VARCHAR(50) NOT NULL,
    unit_id         INT UNSIGNED,
    tenant_id       INT UNSIGNED,
    start_date      DATE NOT NULL,
    end_date        DATE,
    monthly_rent    DECIMAL(14,2) DEFAULT 0,
    deposit_amount  DECIMAL(14,2) DEFAULT 0,
    payment_due_day TINYINT DEFAULT 1 COMMENT 'Day of month',
    escalation_pct  DECIMAL(5,2) DEFAULT 0,
    special_terms   TEXT,
    status          ENUM('draft','active','expired','terminated') DEFAULT 'draft',
    signed_date     DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rental_inspections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED,
    tenant_id       INT UNSIGNED,
    inspection_type ENUM('move_in','move_out','routine','maintenance') DEFAULT 'routine',
    inspection_date DATE NOT NULL,
    inspector_name  VARCHAR(150),
    condition_score TINYINT COMMENT '1-10',
    findings        TEXT,
    action_required TEXT,
    follow_up_date  DATE,
    status          ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- SACCO
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sacco_guarantors (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    loan_id         INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED COMMENT 'Guarantor member',
    guarantor_name  VARCHAR(200),
    guarantor_phone VARCHAR(50),
    guarantor_id_no VARCHAR(50),
    amount_guaranteed DECIMAL(14,2) DEFAULT 0,
    status          ENUM('pending','accepted','declined','released') DEFAULT 'pending',
    accepted_at     DATE,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sacco_penalties (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED NOT NULL,
    loan_id         INT UNSIGNED,
    penalty_type    ENUM('late_repayment','nsf','early_exit','other') DEFAULT 'late_repayment',
    penalty_date    DATE NOT NULL,
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(14,2) DEFAULT 0,
    balance         DECIMAL(14,2) DEFAULT 0,
    status          ENUM('unpaid','partial','paid','waived') DEFAULT 'unpaid',
    reason          VARCHAR(255),
    waived_by       VARCHAR(100),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sacco_communications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED COMMENT 'NULL = broadcast',
    channel         ENUM('sms','email','notice','letter') DEFAULT 'sms',
    subject         VARCHAR(255),
    message         TEXT NOT NULL,
    sent_by         VARCHAR(150),
    sent_at         TIMESTAMP,
    status          ENUM('draft','sent','failed') DEFAULT 'draft',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- CHURCH
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS church_pledges (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED,
    member_name     VARCHAR(200),
    pledge_type     ENUM('building','missions','tithe','special','other') DEFAULT 'special',
    pledge_date     DATE NOT NULL,
    amount_pledged  DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_paid     DECIMAL(14,2) DEFAULT 0,
    balance         DECIMAL(14,2) DEFAULT 0,
    due_date        DATE,
    status          ENUM('active','fulfilled','defaulted','cancelled') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS church_projects (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    category        VARCHAR(100) COMMENT 'Building, Outreach, Mission...',
    start_date      DATE,
    end_date        DATE,
    budget          DECIMAL(14,2) DEFAULT 0,
    amount_raised   DECIMAL(14,2) DEFAULT 0,
    amount_spent    DECIMAL(14,2) DEFAULT 0,
    leader          VARCHAR(150),
    priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
    status          ENUM('planning','active','completed','cancelled','on_hold') DEFAULT 'planning',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS church_notices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    content         TEXT NOT NULL,
    audience        ENUM('all','members','leaders','cells') DEFAULT 'all',
    notice_type     ENUM('announcement','event','alert','bulletin','other') DEFAULT 'announcement',
    posted_by       VARCHAR(150),
    publish_date    DATE NOT NULL,
    expiry_date     DATE,
    is_pinned       TINYINT(1) DEFAULT 0,
    status          ENUM('draft','published','archived') DEFAULT 'draft',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- CRM
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crm_contracts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    contract_number VARCHAR(50) NOT NULL,
    contact_id      INT UNSIGNED,
    company_id      INT UNSIGNED,
    deal_id         INT UNSIGNED,
    title           VARCHAR(255) NOT NULL,
    contract_type   ENUM('service','sale','nda','partnership','maintenance','other') DEFAULT 'service',
    start_date      DATE,
    end_date        DATE,
    value           DECIMAL(14,2) DEFAULT 0,
    renewal_notice_days INT DEFAULT 30,
    signed_date     DATE,
    status          ENUM('draft','sent','signed','active','expired','terminated') DEFAULT 'draft',
    document_url    VARCHAR(500),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_tickets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    ticket_number   VARCHAR(50) NOT NULL,
    contact_id      INT UNSIGNED,
    subject         VARCHAR(255) NOT NULL,
    description     TEXT,
    category        VARCHAR(100) COMMENT 'Billing, Technical, General...',
    priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
    assigned_to     VARCHAR(150),
    first_response_at TIMESTAMP NULL,
    resolved_at     TIMESTAMP NULL,
    status          ENUM('open','in_progress','waiting','resolved','closed') DEFAULT 'open',
    satisfaction    TINYINT COMMENT '1-5 CSAT',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_ticket_replies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT UNSIGNED NOT NULL,
    author          VARCHAR(150),
    is_agent        TINYINT(1) DEFAULT 1,
    message         TEXT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_email_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    contact_id      INT UNSIGNED,
    campaign_id     INT UNSIGNED,
    to_email        VARCHAR(255) NOT NULL,
    subject         VARCHAR(255),
    body_preview    TEXT,
    sent_by         VARCHAR(150),
    sent_at         TIMESTAMP NULL,
    opened_at       TIMESTAMP NULL,
    clicked_at      TIMESTAMP NULL,
    status          ENUM('sent','delivered','opened','bounced','failed') DEFAULT 'sent',
    error_msg       VARCHAR(500),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- ACCOUNTING
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS acc_assets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    asset_number    VARCHAR(50) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    category        VARCHAR(100) COMMENT 'Land, Building, Equipment, Vehicle...',
    purchase_date   DATE,
    purchase_cost   DECIMAL(14,2) DEFAULT 0,
    salvage_value   DECIMAL(14,2) DEFAULT 0,
    useful_life_years INT DEFAULT 5,
    depreciation_method ENUM('straight_line','declining_balance','none') DEFAULT 'straight_line',
    accumulated_depreciation DECIMAL(14,2) DEFAULT 0,
    book_value      DECIMAL(14,2) DEFAULT 0,
    location        VARCHAR(200),
    assigned_to     VARCHAR(150),
    condition_rating ENUM('excellent','good','fair','poor','disposed') DEFAULT 'good',
    disposal_date   DATE,
    disposal_value  DECIMAL(14,2) DEFAULT 0,
    status          ENUM('active','disposed','lost','transferred') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acc_payroll_journal (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    period          VARCHAR(20) COMMENT 'YYYY-MM',
    employee_name   VARCHAR(200),
    department      VARCHAR(100),
    gross_salary    DECIMAL(14,2) DEFAULT 0,
    paye_tax        DECIMAL(14,2) DEFAULT 0,
    nssf            DECIMAL(14,2) DEFAULT 0,
    nhif            DECIMAL(14,2) DEFAULT 0,
    other_deductions DECIMAL(14,2) DEFAULT 0,
    net_pay         DECIMAL(14,2) DEFAULT 0,
    allowances      DECIMAL(14,2) DEFAULT 0,
    payment_method  ENUM('bank','mpesa','cash') DEFAULT 'bank',
    payment_date    DATE,
    account_id      INT UNSIGNED COMMENT 'FK acc_accounts',
    status          ENUM('draft','approved','paid') DEFAULT 'draft',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acc_audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    user_name       VARCHAR(150),
    user_role       VARCHAR(50),
    action          VARCHAR(100) NOT NULL,
    module          VARCHAR(100),
    record_id       INT UNSIGNED,
    old_value       TEXT,
    new_value       TEXT,
    ip_address      VARCHAR(45),
    user_agent      VARCHAR(500),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────
-- CARYARD
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS caryard_insurance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    vehicle_id      INT UNSIGNED NOT NULL,
    policy_number   VARCHAR(100),
    insurer         VARCHAR(200),
    insurance_type  ENUM('comprehensive','third_party','fire_theft','other') DEFAULT 'comprehensive',
    start_date      DATE NOT NULL,
    expiry_date     DATE NOT NULL,
    premium_amount  DECIMAL(14,2) DEFAULT 0,
    cover_amount    DECIMAL(14,2) DEFAULT 0,
    payment_freq    ENUM('annual','semi_annual','quarterly','monthly') DEFAULT 'annual',
    agent_name      VARCHAR(150),
    agent_phone     VARCHAR(50),
    status          ENUM('active','expired','cancelled','pending') DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS caryard_parts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    part_number     VARCHAR(100),
    name            VARCHAR(200) NOT NULL,
    category        VARCHAR(100),
    compatible_makes VARCHAR(255) COMMENT 'CSV of makes',
    qty_on_hand     INT DEFAULT 0,
    reorder_level   INT DEFAULT 2,
    unit_cost       DECIMAL(14,2) DEFAULT 0,
    selling_price   DECIMAL(14,2) DEFAULT 0,
    supplier_name   VARCHAR(200),
    location        VARCHAR(100) COMMENT 'Shelf/bin',
    status          ENUM('active','discontinued','out_of_stock') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS caryard_deliveries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    delivery_number VARCHAR(50) NOT NULL,
    sale_id         INT UNSIGNED,
    vehicle_id      INT UNSIGNED,
    customer_name   VARCHAR(200),
    customer_phone  VARCHAR(50),
    delivery_date   DATE NOT NULL,
    delivery_address TEXT,
    delivered_by    VARCHAR(150) COMMENT 'Sales rep / driver',
    odometer_reading INT DEFAULT 0,
    fuel_level      VARCHAR(50) COMMENT 'Full, 3/4, 1/2, 1/4...',
    spare_keys      TINYINT DEFAULT 1,
    service_book    TINYINT(1) DEFAULT 0,
    customer_signature TINYINT(1) DEFAULT 0,
    status          ENUM('scheduled','delivered','cancelled') DEFAULT 'scheduled',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- End of Phase C Migration
