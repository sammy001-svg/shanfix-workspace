-- ═══════════════════════════════════════════════════════════════
-- Tour & Travel Module — Phase 2 Migration
--   1. Sales pipeline    : quotations → invoices
--   2. Supply chain      : suppliers, supplier bookings, trip expenses
--   3. Inventory         : scheduled departures with seat control
--   4. Traveller portal  : per-booking PIN access + module settings
-- Run once. All statements use IF NOT EXISTS for safety.
-- ═══════════════════════════════════════════════════════════════

-- ── 1. Module settings (per-org key/value) ────────────────────
CREATE TABLE IF NOT EXISTS tour_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tour_setting (org_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Scheduled departures (seat inventory) ──────────────────
CREATE TABLE IF NOT EXISTS tour_departures (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    package_id      INT NOT NULL,
    departure_code  VARCHAR(40) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE DEFAULT NULL,
    seats_total     INT NOT NULL DEFAULT 0,
    min_pax         INT NOT NULL DEFAULT 1,
    price_adult     DECIMAL(12,2) DEFAULT NULL,   -- NULL = inherit package price
    price_child     DECIMAL(12,2) DEFAULT NULL,
    guide_id        INT DEFAULT NULL,
    vehicle_id      INT DEFAULT NULL,
    meeting_point   VARCHAR(255) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('scheduled','guaranteed','full','departed','completed','cancelled') DEFAULT 'scheduled',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    UNIQUE KEY uk_tour_departure (org_id, departure_code),
    INDEX idx_org_package (org_id, package_id),
    INDEX idx_start (org_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Suppliers (hotels, airlines, transport, activities) ────
CREATE TABLE IF NOT EXISTS tour_suppliers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(255) NOT NULL,
    supplier_type   ENUM('hotel','airline','transport','activity','restaurant','insurance','visa','other') DEFAULT 'other',
    contact_person  VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(50)  DEFAULT NULL,
    email           VARCHAR(255) DEFAULT NULL,
    city            VARCHAR(100) DEFAULT NULL,
    country         VARCHAR(100) DEFAULT NULL,
    address         TEXT DEFAULT NULL,
    payment_terms   VARCHAR(150) DEFAULT NULL,
    account_details TEXT DEFAULT NULL,
    rating          TINYINT DEFAULT 0,
    notes           TEXT DEFAULT NULL,
    status          ENUM('active','inactive','blacklisted') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_org_type (org_id, supplier_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Supplier bookings (services bought in for a trip) ──────
CREATE TABLE IF NOT EXISTS tour_supplier_bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    supplier_id     INT NOT NULL,
    booking_id      INT DEFAULT NULL,
    departure_id    INT DEFAULT NULL,
    service_type    ENUM('accommodation','flight','transport','activity','meals','guide','permit','insurance','visa','other') DEFAULT 'other',
    description     VARCHAR(255) NOT NULL,
    service_date    DATE DEFAULT NULL,
    pax             INT NOT NULL DEFAULT 1,
    cost            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    confirmation_no VARCHAR(100) DEFAULT NULL,
    status          ENUM('pending','confirmed','paid','cancelled') DEFAULT 'pending',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_supplier (org_id, supplier_id),
    INDEX idx_booking (booking_id),
    INDEX idx_departure (departure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. Trip expenses (direct costs, for margin reporting) ─────
CREATE TABLE IF NOT EXISTS tour_expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    booking_id      INT DEFAULT NULL,
    departure_id    INT DEFAULT NULL,
    supplier_id     INT DEFAULT NULL,
    category        ENUM('accommodation','transport','fuel','meals','guide_fees','park_fees','permits','insurance','flights','marketing','other') DEFAULT 'other',
    description     VARCHAR(255) NOT NULL,
    expense_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_mode    ENUM('cash','mpesa','bank','card','credit') DEFAULT 'cash',
    reference       VARCHAR(100) DEFAULT NULL,
    recorded_by     INT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_date (org_id, expense_date),
    INDEX idx_booking (booking_id),
    INDEX idx_departure (departure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. Quotations ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_quotations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    quote_no        VARCHAR(40) NOT NULL,
    customer_id     INT DEFAULT NULL,
    customer_name   VARCHAR(255) NOT NULL,
    customer_phone  VARCHAR(50)  DEFAULT NULL,
    customer_email  VARCHAR(255) DEFAULT NULL,
    package_id      INT DEFAULT NULL,
    departure_id    INT DEFAULT NULL,
    travel_date     DATE DEFAULT NULL,
    adults          INT NOT NULL DEFAULT 1,
    children        INT NOT NULL DEFAULT 0,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    valid_until     DATE DEFAULT NULL,
    status          ENUM('draft','sent','accepted','declined','expired','converted') DEFAULT 'draft',
    booking_id      INT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    terms           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    UNIQUE KEY uk_tour_quote (org_id, quote_no),
    INDEX idx_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tour_quotation_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    quotation_id  INT NOT NULL,
    description   VARCHAR(255) NOT NULL,
    quantity      DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sort_order    INT NOT NULL DEFAULT 0,
    INDEX idx_quotation (quotation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. Invoices ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    invoice_no      VARCHAR(40) NOT NULL,
    booking_id      INT DEFAULT NULL,
    quotation_id    INT DEFAULT NULL,
    customer_id     INT DEFAULT NULL,
    customer_name   VARCHAR(255) NOT NULL,
    customer_phone  VARCHAR(50)  DEFAULT NULL,
    customer_email  VARCHAR(255) DEFAULT NULL,
    issue_date      DATE NOT NULL,
    due_date        DATE DEFAULT NULL,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status          ENUM('draft','sent','partial','paid','overdue','cancelled') DEFAULT 'draft',
    notes           TEXT DEFAULT NULL,
    terms           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    UNIQUE KEY uk_tour_invoice (org_id, invoice_no),
    INDEX idx_org_status (org_id, status),
    INDEX idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tour_invoice_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    invoice_id    INT NOT NULL,
    description   VARCHAR(255) NOT NULL,
    quantity      DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sort_order    INT NOT NULL DEFAULT 0,
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 8. Bookings: link to departures, customers & portal ───────
ALTER TABLE tour_bookings
    ADD COLUMN IF NOT EXISTS departure_id      INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS customer_id       INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quotation_id      INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS portal_pin        VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS portal_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS portal_last_login DATETIME DEFAULT NULL;

-- ── 9. Customers: portal identity ─────────────────────────────
ALTER TABLE tour_customers
    ADD COLUMN IF NOT EXISTS portal_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS updated_at     DATETIME DEFAULT NULL;
