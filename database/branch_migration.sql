-- ── Multi-Branch Migration ───────────────────────────────────────────────────
-- Compatible with MySQL 5.7+ / MariaDB / cPanel.
-- If you get "Duplicate column name" on any ALTER TABLE, that section already ran — skip it.
-- Run SECTION 1 always. Run SECTION 2-6 only for the modules your org has subscribed to.
-- ─────────────────────────────────────────────────────────────────────────────

-- ════════════════════════════════════════════════════════════════
-- SECTION 1: Branches master table + users assignment (ALWAYS RUN)
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS org_branches (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    org_id     INT NOT NULL,
    name       VARCHAR(150) NOT NULL,
    code       VARCHAR(20)  NULL,
    address    TEXT         NULL,
    city       VARCHAR(100) NULL,
    phone      VARCHAR(30)  NULL,
    email      VARCHAR(150) NULL,
    manager    VARCHAR(150) NULL,
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN branch_id INT NULL DEFAULT NULL;


-- ════════════════════════════════════════════════════════════════
-- SECTION 2: Hotel module  (skip if Hotel not subscribed)
-- ════════════════════════════════════════════════════════════════

ALTER TABLE hotel_rooms    ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE hotel_bookings ADD COLUMN branch_id INT NULL DEFAULT NULL;


-- ════════════════════════════════════════════════════════════════
-- SECTION 3: Health / Clinic module  (skip if Health not subscribed)
-- ════════════════════════════════════════════════════════════════

ALTER TABLE health_patients     ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE health_appointments ADD COLUMN branch_id INT NULL DEFAULT NULL;


-- ════════════════════════════════════════════════════════════════
-- SECTION 4: POS module  (skip if POS not subscribed)
-- ════════════════════════════════════════════════════════════════

ALTER TABLE pos_products ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE pos_sales    ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE pos_shifts   ADD COLUMN branch_id INT NULL DEFAULT NULL;


-- ════════════════════════════════════════════════════════════════
-- SECTION 5: SACCO module  (skip if SACCO not subscribed)
-- ════════════════════════════════════════════════════════════════

ALTER TABLE sacco_members  ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE sacco_loans    ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE sacco_savings  ADD COLUMN branch_id INT NULL DEFAULT NULL;
