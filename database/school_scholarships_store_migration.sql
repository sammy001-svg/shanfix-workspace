-- ============================================================
-- School Module: Scholarships & Bursaries + School Store
-- Run after school_module_migration.sql
-- ============================================================

-- ── Scholarship / Bursary Schemes ────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_scholarship_schemes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id      INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    type        ENUM('scholarship','bursary','grant','discount') NOT NULL DEFAULT 'scholarship',
    value_type  ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    value       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency    VARCHAR(10) NOT NULL DEFAULT 'KES',
    criteria    TEXT,
    renewable   TINYINT(1) NOT NULL DEFAULT 1,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schemes_org (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Individual Scholarship Awards ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_scholarship_awards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    scheme_id       INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    academic_year   VARCHAR(50) DEFAULT NULL,
    amount_awarded  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    disbursed       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending','active','disbursed','cancelled','expired') NOT NULL DEFAULT 'pending',
    awarded_date    DATE DEFAULT NULL,
    notes           TEXT,
    awarded_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_awards_org    (org_id),
    INDEX idx_awards_student(student_id),
    INDEX idx_awards_scheme (scheme_id),
    INDEX idx_awards_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── School Store: Product Categories ──────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_store_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id     INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_store_cat_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── School Store: Products ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_store_products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    category_id     INT UNSIGNED DEFAULT NULL,
    name            VARCHAR(150) NOT NULL,
    description     TEXT,
    unit            VARCHAR(30) NOT NULL DEFAULT 'piece',
    price           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_qty       INT NOT NULL DEFAULT 0,
    reorder_level   INT NOT NULL DEFAULT 5,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_prod_org (org_id),
    INDEX idx_store_prod_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── School Store: Sales (Header) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_store_sales (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    sale_no         VARCHAR(30)  NOT NULL,
    student_id      INT UNSIGNED DEFAULT NULL,
    customer_name   VARCHAR(120) DEFAULT NULL,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','mpesa','card','credit','scholarship') NOT NULL DEFAULT 'cash',
    notes           TEXT,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_store_sale_no (org_id, sale_no),
    INDEX idx_store_sales_org    (org_id),
    INDEX idx_store_sales_student(student_id),
    INDEX idx_store_sales_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── School Store: Sale Line Items ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_store_sale_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    qty         INT          NOT NULL DEFAULT 1,
    unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    INDEX idx_store_items_sale(sale_id),
    INDEX idx_store_items_prod(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
