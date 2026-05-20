-- ═══════════════════════════════════════════════════════════════
-- Church Module — Phase 2 Migration
-- Run once. All tables use IF NOT EXISTS for safety.
-- ═══════════════════════════════════════════════════════════════

-- ── 1. Service Attendance ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS church_attendance (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id       INT UNSIGNED NOT NULL,
    service_date DATE         NOT NULL,
    service_type VARCHAR(100) NOT NULL DEFAULT 'Sunday Service',
    member_id    INT UNSIGNED NOT NULL,
    status       ENUM('present','absent','excused') NOT NULL DEFAULT 'present',
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_att (org_id, member_id, service_date, service_type),
    INDEX idx_org  (org_id),
    INDEX idx_date (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Sermons / Preaching Library ───────────────────────────
CREATE TABLE IF NOT EXISTS church_sermons (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id       INT UNSIGNED NOT NULL,
    title        VARCHAR(255) NOT NULL,
    scripture    VARCHAR(255),
    preacher     VARCHAR(255),
    series       VARCHAR(255),
    service_date DATE         NOT NULL,
    service_type VARCHAR(100) DEFAULT 'Sunday Service',
    media_url    VARCHAR(500),
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org  (org_id),
    INDEX idx_date (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Prayer Requests ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS church_prayers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id       INT UNSIGNED NOT NULL,
    member_id    INT UNSIGNED DEFAULT NULL,
    name         VARCHAR(255) NOT NULL,
    request      TEXT         NOT NULL,
    category     VARCHAR(100) NOT NULL DEFAULT 'General',
    assigned_to  VARCHAR(255),
    status       ENUM('pending','in_prayer','answered','closed') NOT NULL DEFAULT 'pending',
    submitted_at DATE         NOT NULL,
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Pastoral Visits & Counseling ──────────────────────────
CREATE TABLE IF NOT EXISTS church_pastoral (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id         INT UNSIGNED NOT NULL,
    member_id      INT UNSIGNED DEFAULT NULL,
    name           VARCHAR(255) NOT NULL,
    visit_type     VARCHAR(100) NOT NULL DEFAULT 'Home Visit',
    visit_date     DATE         NOT NULL,
    pastor         VARCHAR(255),
    outcome        TEXT,
    follow_up_date DATE         DEFAULT NULL,
    status         ENUM('pending','done','follow_up') NOT NULL DEFAULT 'done',
    notes          TEXT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org  (org_id),
    INDEX idx_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. Church Expenses ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS church_expenses (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id         INT UNSIGNED NOT NULL,
    category       VARCHAR(100) NOT NULL,
    description    VARCHAR(255) NOT NULL,
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    expense_date   DATE          NOT NULL,
    payment_method VARCHAR(50)   NOT NULL DEFAULT 'cash',
    reference      VARCHAR(255),
    approved_by    VARCHAR(255),
    notes          TEXT,
    created_by     INT UNSIGNED DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org  (org_id),
    INDEX idx_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
