-- ══════════════════════════════════════════════════════════════════
-- OrbitDesk — Phase A Migration
-- Completes the 9 minimal modules with new feature tables
-- Run once. Safe — all use IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
-- ══════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────
-- SACCO — Shares & Dividends
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sacco_shares (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    member_id       INT NOT NULL,
    transaction_type ENUM('purchase','transfer_in','transfer_out','bonus','redemption') DEFAULT 'purchase',
    shares          INT NOT NULL DEFAULT 0,
    share_value     DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance_shares  INT NOT NULL DEFAULT 0,
    certificate_no  VARCHAR(30) DEFAULT NULL,
    reference       VARCHAR(100) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    recorded_by     INT DEFAULT NULL,
    transaction_date DATE NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_member (org_id, member_id),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sacco_dividends (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    period_label    VARCHAR(50) NOT NULL COMMENT 'e.g. FY 2025',
    period_from     DATE NOT NULL,
    period_to       DATE NOT NULL,
    total_pool      DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'Total dividend pool',
    per_share_rate  DECIMAL(10,4) NOT NULL DEFAULT 0,
    interest_rate   DECIMAL(5,2) DEFAULT NULL COMMENT '% on savings',
    status          ENUM('draft','declared','paid','cancelled') DEFAULT 'draft',
    declared_at     DATE DEFAULT NULL,
    paid_at         DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sacco_dividend_payouts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dividend_id     INT NOT NULL,
    member_id       INT NOT NULL,
    shares_at_decl  INT DEFAULT 0,
    savings_at_decl DECIMAL(14,2) DEFAULT 0,
    dividend_amount DECIMAL(14,2) DEFAULT 0,
    interest_amount DECIMAL(14,2) DEFAULT 0,
    total_payout    DECIMAL(14,2) DEFAULT 0,
    status          ENUM('pending','paid','waived') DEFAULT 'pending',
    paid_at         DATETIME DEFAULT NULL,
    INDEX idx_dividend (dividend_id),
    INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sacco_members ADD COLUMN IF NOT EXISTS total_shares INT NOT NULL DEFAULT 0;

-- ─────────────────────────────────────────────────────────────────
-- RENTAL — Leases, Maintenance, Invoices
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rental_leases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    lease_no        VARCHAR(30) NOT NULL,
    unit_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    monthly_rent    DECIMAL(12,2) NOT NULL,
    deposit         DECIMAL(12,2) DEFAULT 0,
    payment_day     TINYINT DEFAULT 1 COMMENT 'Day of month rent is due',
    late_fee_pct    DECIMAL(5,2) DEFAULT 0 COMMENT 'Late fee %',
    late_fee_days   TINYINT DEFAULT 5 COMMENT 'Grace period before late fee',
    terms           TEXT DEFAULT NULL,
    status          ENUM('active','expired','terminated','pending') DEFAULT 'active',
    terminated_at   DATE DEFAULT NULL,
    termination_reason TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_unit (org_id, unit_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_maintenance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    request_no      VARCHAR(30) NOT NULL,
    unit_id         INT NOT NULL,
    tenant_id       INT DEFAULT NULL,
    category        ENUM('plumbing','electrical','hvac','structural','appliance','painting','cleaning','other') DEFAULT 'other',
    priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    assigned_to     VARCHAR(150) DEFAULT NULL COMMENT 'Contractor/worker name',
    estimated_cost  DECIMAL(12,2) DEFAULT NULL,
    actual_cost     DECIMAL(12,2) DEFAULT NULL,
    status          ENUM('open','assigned','in_progress','completed','cancelled') DEFAULT 'open',
    reported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    INDEX idx_org_unit (org_id, unit_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    invoice_no      VARCHAR(30) NOT NULL,
    lease_id        INT DEFAULT NULL,
    unit_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    period_from     DATE NOT NULL,
    period_to       DATE NOT NULL,
    due_date        DATE NOT NULL,
    rent_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    late_fee        DECIMAL(12,2) DEFAULT 0,
    other_charges   DECIMAL(12,2) DEFAULT 0,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(12,2) DEFAULT 0,
    status          ENUM('unpaid','partial','paid','overdue','cancelled') DEFAULT 'unpaid',
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_tenant (org_id, tenant_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- FINANCE — Journal Entries & Reconciliation
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fin_journal_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    journal_no      VARCHAR(30) NOT NULL,
    entry_date      DATE NOT NULL,
    description     VARCHAR(500) NOT NULL,
    reference       VARCHAR(100) DEFAULT NULL,
    entry_type      ENUM('manual','adjustment','opening','closing','recurring') DEFAULT 'manual',
    status          ENUM('draft','posted','voided') DEFAULT 'draft',
    total_debit     DECIMAL(16,2) DEFAULT 0,
    total_credit    DECIMAL(16,2) DEFAULT 0,
    created_by      INT DEFAULT NULL,
    posted_by       INT DEFAULT NULL,
    posted_at       DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_date (entry_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_journal_lines (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    journal_id      INT NOT NULL,
    account_id      INT NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    debit           DECIMAL(16,2) DEFAULT 0,
    credit          DECIMAL(16,2) DEFAULT 0,
    INDEX idx_journal (journal_id),
    INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_reconciliations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    account_id      INT NOT NULL,
    period_label    VARCHAR(50) NOT NULL,
    statement_date  DATE NOT NULL,
    statement_balance DECIMAL(16,2) NOT NULL DEFAULT 0,
    book_balance    DECIMAL(16,2) NOT NULL DEFAULT 0,
    difference      DECIMAL(16,2) GENERATED ALWAYS AS (statement_balance - book_balance) STORED,
    status          ENUM('in_progress','completed','reopened') DEFAULT 'in_progress',
    notes           TEXT DEFAULT NULL,
    completed_by    INT DEFAULT NULL,
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_account (org_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fin_accounts ADD COLUMN IF NOT EXISTS account_type ENUM('asset','liability','equity','income','expense') DEFAULT 'asset';
ALTER TABLE fin_accounts ADD COLUMN IF NOT EXISTS account_code VARCHAR(20) DEFAULT NULL;
ALTER TABLE fin_accounts ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL;
ALTER TABLE fin_accounts ADD COLUMN IF NOT EXISTS is_reconcilable TINYINT(1) DEFAULT 0;

-- ─────────────────────────────────────────────────────────────────
-- MANUFACTURING — Work Orders, Quality, Machines
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mfg_work_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    wo_no           VARCHAR(30) NOT NULL,
    production_id   INT DEFAULT NULL,
    product_id      INT NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
    machine_id      INT DEFAULT NULL,
    assigned_to     VARCHAR(150) DEFAULT NULL,
    priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    scheduled_start DATETIME DEFAULT NULL,
    scheduled_end   DATETIME DEFAULT NULL,
    actual_start    DATETIME DEFAULT NULL,
    actual_end      DATETIME DEFAULT NULL,
    setup_time_mins INT DEFAULT 0,
    run_time_mins   INT DEFAULT 0,
    status          ENUM('draft','scheduled','in_progress','completed','on_hold','cancelled') DEFAULT 'draft',
    instructions    TEXT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_product (product_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfg_quality_checks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    work_order_id   INT DEFAULT NULL,
    production_id   INT DEFAULT NULL,
    product_id      INT NOT NULL,
    check_type      ENUM('incoming','in_process','final','random') DEFAULT 'final',
    batch_no        VARCHAR(50) DEFAULT NULL,
    quantity_checked INT DEFAULT 0,
    quantity_passed  INT DEFAULT 0,
    quantity_failed  INT DEFAULT 0,
    defect_type     VARCHAR(255) DEFAULT NULL,
    verdict         ENUM('pass','fail','conditional_pass','rework') DEFAULT 'pass',
    checked_by      VARCHAR(150) DEFAULT NULL,
    check_date      DATE NOT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_work_order (work_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfg_machines (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    code            VARCHAR(50) DEFAULT NULL,
    machine_type    VARCHAR(100) DEFAULT NULL,
    manufacturer    VARCHAR(150) DEFAULT NULL,
    model           VARCHAR(100) DEFAULT NULL,
    serial_no       VARCHAR(100) DEFAULT NULL,
    location        VARCHAR(150) DEFAULT NULL,
    capacity_per_hr DECIMAL(12,3) DEFAULT NULL,
    status          ENUM('active','maintenance','offline','decommissioned') DEFAULT 'active',
    last_service    DATE DEFAULT NULL,
    next_service    DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- RETAIL — Sales, Stock Adjustments, Pricing Rules
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS retail_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    sale_no         VARCHAR(30) NOT NULL,
    customer_name   VARCHAR(150) DEFAULT NULL,
    customer_phone  VARCHAR(30) DEFAULT NULL,
    subtotal        DECIMAL(14,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    tax_amount      DECIMAL(12,2) DEFAULT 0,
    total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(14,2) DEFAULT 0,
    payment_method  ENUM('cash','mpesa','card','credit','other') DEFAULT 'cash',
    status          ENUM('completed','refunded','voided') DEFAULT 'completed',
    notes           TEXT DEFAULT NULL,
    served_by       INT DEFAULT NULL,
    sale_date       DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_sale_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sale_id         INT NOT NULL,
    product_id      INT NOT NULL,
    product_name    VARCHAR(150) NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL,
    unit_price      DECIMAL(12,2) NOT NULL,
    discount_pct    DECIMAL(5,2) DEFAULT 0,
    line_total      DECIMAL(14,2) NOT NULL,
    INDEX idx_sale (sale_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_stock_adjustments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    product_id      INT NOT NULL,
    adj_type        ENUM('addition','reduction','write_off','count_correction','return') DEFAULT 'count_correction',
    quantity        DECIMAL(12,3) NOT NULL,
    stock_before    DECIMAL(12,3) DEFAULT 0,
    stock_after     DECIMAL(12,3) DEFAULT 0,
    reason          VARCHAR(255) DEFAULT NULL,
    reference       VARCHAR(100) DEFAULT NULL,
    adjusted_by     INT DEFAULT NULL,
    adj_date        DATE NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_product (org_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_pricing_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    rule_type       ENUM('percentage_discount','fixed_discount','markup','tiered') DEFAULT 'percentage_discount',
    applies_to      ENUM('all_products','category','product') DEFAULT 'all_products',
    category_id     INT DEFAULT NULL,
    product_id      INT DEFAULT NULL,
    value           DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '% or fixed amount',
    min_quantity    INT DEFAULT NULL,
    min_amount      DECIMAL(12,2) DEFAULT NULL,
    valid_from      DATE DEFAULT NULL,
    valid_until     DATE DEFAULT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- SALES — Invoices, Fulfillment, Commissions
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sales_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    invoice_no      VARCHAR(30) NOT NULL,
    order_id        INT DEFAULT NULL,
    quote_id        INT DEFAULT NULL,
    customer_id     INT NOT NULL,
    issue_date      DATE NOT NULL,
    due_date        DATE NOT NULL,
    subtotal        DECIMAL(14,2) DEFAULT 0,
    tax_amount      DECIMAL(12,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(14,2) DEFAULT 0,
    currency        VARCHAR(5) DEFAULT 'KES',
    payment_terms   VARCHAR(100) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('draft','sent','partial','paid','overdue','voided') DEFAULT 'draft',
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_customer (org_id, customer_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_fulfillments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    fulfillment_no  VARCHAR(30) NOT NULL,
    order_id        INT NOT NULL,
    customer_id     INT NOT NULL,
    stage           ENUM('confirmed','picking','packed','shipped','delivered','returned') DEFAULT 'confirmed',
    carrier         VARCHAR(100) DEFAULT NULL,
    tracking_no     VARCHAR(100) DEFAULT NULL,
    shipping_method ENUM('delivery','pickup','courier','post') DEFAULT 'delivery',
    shipping_cost   DECIMAL(10,2) DEFAULT 0,
    ship_to_address TEXT DEFAULT NULL,
    shipped_at      DATETIME DEFAULT NULL,
    estimated_delivery DATE DEFAULT NULL,
    delivered_at    DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    handled_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_order (org_id, order_id),
    INDEX idx_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_commissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    agent_id        INT NOT NULL COMMENT 'user_id',
    agent_name      VARCHAR(150) NOT NULL,
    order_id        INT DEFAULT NULL,
    invoice_id      INT DEFAULT NULL,
    sale_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
    commission_pct  DECIMAL(5,2) DEFAULT 0,
    commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    period_month    CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    status          ENUM('pending','approved','paid') DEFAULT 'pending',
    paid_at         DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_agent (org_id, agent_id),
    INDEX idx_period (period_month),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- SALON — Packages, Inventory, Loyalty
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS salon_packages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     TEXT DEFAULT NULL,
    package_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
    validity_days   INT DEFAULT 30 COMMENT 'Days package remains valid after purchase',
    sessions        INT DEFAULT 1 COMMENT 'Number of sessions included',
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salon_package_services (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    package_id      INT NOT NULL,
    service_id      INT NOT NULL,
    sessions        INT DEFAULT 1,
    INDEX idx_package (package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salon_client_packages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    client_id       INT NOT NULL,
    package_id      INT NOT NULL,
    purchase_date   DATE NOT NULL,
    expiry_date     DATE NOT NULL,
    sessions_total  INT DEFAULT 1,
    sessions_used   INT DEFAULT 0,
    amount_paid     DECIMAL(12,2) DEFAULT 0,
    status          ENUM('active','expired','exhausted') DEFAULT 'active',
    INDEX idx_org_client (org_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salon_inventory (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(100) DEFAULT NULL,
    supplier        VARCHAR(150) DEFAULT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    cost_price      DECIMAL(12,2) DEFAULT 0,
    stock           DECIMAL(12,3) DEFAULT 0,
    reorder_level   DECIMAL(12,3) DEFAULT 0,
    status          ENUM('active','inactive') DEFAULT 'active',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salon_inventory_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    item_id         INT NOT NULL,
    movement_type   ENUM('restock','usage','adjustment','write_off') DEFAULT 'usage',
    quantity        DECIMAL(12,3) NOT NULL,
    stock_before    DECIMAL(12,3) DEFAULT 0,
    stock_after     DECIMAL(12,3) DEFAULT 0,
    reference       VARCHAR(100) DEFAULT NULL,
    notes           VARCHAR(255) DEFAULT NULL,
    recorded_by     INT DEFAULT NULL,
    recorded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salon_loyalty (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    client_id       INT NOT NULL,
    transaction_type ENUM('earn','redeem','expire','adjust') DEFAULT 'earn',
    points          INT NOT NULL DEFAULT 0,
    balance_after   INT DEFAULT 0,
    reason          VARCHAR(255) DEFAULT NULL,
    reference_type  VARCHAR(50) DEFAULT NULL,
    reference_id    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_client (org_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE salon_clients ADD COLUMN IF NOT EXISTS loyalty_points INT NOT NULL DEFAULT 0;

-- ─────────────────────────────────────────────────────────────────
-- SHOPPING MALL — Leases, Maintenance, Utilities
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mall_leases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    lease_no        VARCHAR(30) NOT NULL,
    shop_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    monthly_rent    DECIMAL(12,2) NOT NULL,
    deposit         DECIMAL(12,2) DEFAULT 0,
    service_charge  DECIMAL(12,2) DEFAULT 0 COMMENT 'Monthly service charge',
    payment_day     TINYINT DEFAULT 1,
    late_fee_pct    DECIMAL(5,2) DEFAULT 0,
    terms           TEXT DEFAULT NULL,
    status          ENUM('active','expired','terminated','pending') DEFAULT 'active',
    terminated_at   DATE DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_shop (org_id, shop_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mall_maintenance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    request_no      VARCHAR(30) NOT NULL,
    shop_id         INT DEFAULT NULL,
    location        VARCHAR(150) DEFAULT NULL COMMENT 'Common area description',
    category        ENUM('electrical','plumbing','hvac','structural','cleaning','security','lift','other') DEFAULT 'other',
    priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    reported_by     VARCHAR(100) DEFAULT NULL,
    assigned_to     VARCHAR(150) DEFAULT NULL,
    estimated_cost  DECIMAL(12,2) DEFAULT NULL,
    actual_cost     DECIMAL(12,2) DEFAULT NULL,
    charge_to_tenant TINYINT(1) DEFAULT 0,
    status          ENUM('open','assigned','in_progress','completed','cancelled') DEFAULT 'open',
    reported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    INDEX idx_org (org_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mall_utilities (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    bill_no         VARCHAR(30) NOT NULL,
    shop_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    utility_type    ENUM('electricity','water','internet','gas','waste','other') DEFAULT 'electricity',
    period_from     DATE NOT NULL,
    period_to       DATE NOT NULL,
    reading_open    DECIMAL(12,3) DEFAULT NULL,
    reading_close   DECIMAL(12,3) DEFAULT NULL,
    units_consumed  DECIMAL(12,3) DEFAULT NULL,
    rate_per_unit   DECIMAL(10,4) DEFAULT NULL,
    base_charge     DECIMAL(10,2) DEFAULT 0,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(12,2) DEFAULT 0,
    due_date        DATE DEFAULT NULL,
    status          ENUM('unpaid','paid','partial','waived') DEFAULT 'unpaid',
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_shop (org_id, shop_id),
    INDEX idx_status (status),
    INDEX idx_period (period_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- COURIER — Manifests, Proof of Delivery, Routes
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courier_manifests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    manifest_no     VARCHAR(30) NOT NULL,
    agent_id        INT DEFAULT NULL,
    route_id        INT DEFAULT NULL,
    dispatch_date   DATE NOT NULL,
    vehicle_no      VARCHAR(30) DEFAULT NULL,
    driver_name     VARCHAR(100) DEFAULT NULL,
    total_parcels   INT DEFAULT 0,
    delivered_count INT DEFAULT 0,
    failed_count    INT DEFAULT 0,
    status          ENUM('draft','dispatched','in_transit','completed','cancelled') DEFAULT 'draft',
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_dispatch (dispatch_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courier_manifest_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    manifest_id     INT NOT NULL,
    courier_id      INT NOT NULL COMMENT 'parcel/shipment id',
    sort_order      INT DEFAULT 0,
    delivery_status ENUM('pending','delivered','failed','returned') DEFAULT 'pending',
    delivered_at    DATETIME DEFAULT NULL,
    failure_reason  VARCHAR(255) DEFAULT NULL,
    INDEX idx_manifest (manifest_id),
    INDEX idx_courier (courier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courier_deliveries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    courier_id      INT NOT NULL,
    manifest_id     INT DEFAULT NULL,
    delivery_type   ENUM('delivered','failed','partial','returned') DEFAULT 'delivered',
    recipient_name  VARCHAR(150) DEFAULT NULL,
    recipient_phone VARCHAR(30) DEFAULT NULL,
    delivery_address TEXT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    signature_data  TEXT DEFAULT NULL COMMENT 'Base64 signature SVG',
    photo_url       VARCHAR(500) DEFAULT NULL,
    gps_lat         DECIMAL(10,7) DEFAULT NULL,
    gps_lng         DECIMAL(10,7) DEFAULT NULL,
    delivered_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    delivered_by    INT DEFAULT NULL,
    INDEX idx_org_courier (org_id, courier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courier_routes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    code            VARCHAR(30) DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    area_covered    TEXT DEFAULT NULL,
    distance_km     DECIMAL(8,2) DEFAULT NULL,
    estimated_time  VARCHAR(50) DEFAULT NULL,
    base_charge     DECIMAL(10,2) DEFAULT 0,
    per_km_charge   DECIMAL(8,4) DEFAULT 0,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
