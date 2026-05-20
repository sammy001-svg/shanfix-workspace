-- ═══════════════════════════════════════════════════════════════
-- Car Yard Module — Phase 2 Migration
-- Run once. All tables use IF NOT EXISTS for safety.
-- ═══════════════════════════════════════════════════════════════

-- ── 1. Customers (centralised buyer registry) ─────────────────
CREATE TABLE IF NOT EXISTS caryard_customers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id      INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    phone       VARCHAR(50),
    email       VARCHAR(255),
    id_number   VARCHAR(100),
    address     TEXT,
    city        VARCHAR(100),
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Finance / Hire Purchase Plans ──────────────────────────
CREATE TABLE IF NOT EXISTS caryard_finance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    sale_id         INT UNSIGNED DEFAULT NULL,
    vehicle_id      INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED DEFAULT NULL,
    customer_name   VARCHAR(255) NOT NULL,
    customer_phone  VARCHAR(50),
    lender          VARCHAR(255),
    principal       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    deposit         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    interest_rate   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    term_months     INT UNSIGNED  NOT NULL DEFAULT 12,
    monthly_payment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_payable   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    start_date      DATE          NOT NULL,
    status          ENUM('active','completed','defaulted','cancelled') NOT NULL DEFAULT 'active',
    notes           TEXT,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org     (org_id),
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Finance Installment Payments ───────────────────────────
CREATE TABLE IF NOT EXISTS caryard_finance_payments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    finance_id   INT UNSIGNED NOT NULL,
    org_id       INT UNSIGNED NOT NULL,
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_date DATE          NOT NULL,
    method       VARCHAR(100)  NOT NULL DEFAULT 'Bank Transfer',
    reference    VARCHAR(255),
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_finance (finance_id),
    INDEX idx_org     (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Valuations / Trade-in Appraisals ───────────────────────
CREATE TABLE IF NOT EXISTS caryard_valuations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    customer_name   VARCHAR(255) NOT NULL,
    customer_phone  VARCHAR(50),
    make            VARCHAR(100) NOT NULL,
    model           VARCHAR(100) NOT NULL,
    year            YEAR         NOT NULL,
    registration    VARCHAR(50),
    mileage         INT UNSIGNED DEFAULT NULL,
    condition_grade VARCHAR(20)  DEFAULT NULL,
    market_value    DECIMAL(15,2) DEFAULT NULL,
    offer_value     DECIMAL(15,2) DEFAULT NULL,
    valuation_date  DATE         NOT NULL,
    valuator        VARCHAR(255),
    status          ENUM('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. Reconditioning / Pre-sale Preparation Costs ────────────
CREATE TABLE IF NOT EXISTS caryard_reconditioning (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id     INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    item       VARCHAR(255) NOT NULL,
    cost       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    supplier   VARCHAR(255),
    work_date  DATE         NOT NULL,
    status     ENUM('pending','done') NOT NULL DEFAULT 'pending',
    notes      TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org     (org_id),
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
