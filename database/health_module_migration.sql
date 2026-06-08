-- ── Health Module Migration ───────────────────────────────────────────────────
-- Compatible with MySQL 5.7+ / MariaDB / cPanel.
-- HOW TO RUN:
--   1. Select your database in the phpMyAdmin left sidebar.
--   2. Open the SQL tab.
--   3. Copy and paste ONE statement at a time (or one section at a time), click Go.
--   4. If you get "#1060 - Duplicate column name" or "Table already exists", skip it.
--   5. Do NOT add USE <dbname>; — select your database from the left sidebar first.
-- ─────────────────────────────────────────────────────────────────────────────


-- ════════════════════════════════════════════════════════════════
-- SECTION 1: health_patients — add loyalty_points column
-- ════════════════════════════════════════════════════════════════
ALTER TABLE health_patients ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0;


-- ════════════════════════════════════════════════════════════════
-- SECTION 2: health_staff — expand role ENUM to include new roles
-- ════════════════════════════════════════════════════════════════
ALTER TABLE health_staff MODIFY COLUMN role ENUM('lab_technician','pharmacist','receptionist','cashier','radiologist','admin','nurse','triage_nurse','anesthesia_nurse','other') NOT NULL DEFAULT 'other';


-- ════════════════════════════════════════════════════════════════
-- SECTION 3: health_followups (Patient CRM)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_followups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT NULL,
    assigned_to     INT NULL,
    followup_type   ENUM('call','sms','email','appointment','lab_check','medication_review','other') NOT NULL DEFAULT 'call',
    due_date        DATE NOT NULL,
    priority        ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    reason          TEXT NULL,
    notes           TEXT NULL,
    status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at    DATETIME NULL,
    completed_by    INT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hfu_org    (org_id),
    INDEX idx_hfu_pat    (patient_id),
    INDEX idx_hfu_due    (due_date),
    INDEX idx_hfu_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 4: health_patient_feedback (Patient CRM)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_patient_feedback (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    patient_id       INT NOT NULL,
    appointment_id   INT NULL,
    doctor_id        INT NULL,
    overall_rating   TINYINT NULL,
    doctor_rating    TINYINT NULL,
    wait_rating      TINYINT NULL,
    facility_rating  TINYINT NULL,
    would_recommend  TINYINT(1) NULL,
    comments         TEXT NULL,
    source           VARCHAR(30) NOT NULL DEFAULT 'manual',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hpf_org (org_id),
    INDEX idx_hpf_pat (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 5: health_patient_campaigns (Patient CRM)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_patient_campaigns (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    name          VARCHAR(200) NOT NULL,
    campaign_type ENUM('sms','email','whatsapp','other') NOT NULL DEFAULT 'sms',
    target_group  VARCHAR(50) NOT NULL DEFAULT 'all_patients',
    subject       VARCHAR(255) NULL,
    message       TEXT NOT NULL,
    status        ENUM('draft','scheduled','sent','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at  DATETIME NULL,
    sent_at       DATETIME NULL,
    sent_count    INT NOT NULL DEFAULT 0,
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hpc_org    (org_id),
    INDEX idx_hpc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 6: health_loyalty_points (Patient CRM loyalty ledger)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_loyalty_points (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    patient_id       INT NOT NULL,
    transaction_type ENUM('earn','redeem','adjustment') NOT NULL DEFAULT 'earn',
    points           INT NOT NULL DEFAULT 0,
    balance_after    INT NOT NULL DEFAULT 0,
    reason           TEXT NULL,
    created_by       INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hlp_org (org_id),
    INDEX idx_hlp_pat (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 7: health_teleconsults (Telemedicine)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_teleconsults (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    consult_no      VARCHAR(30) NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT NULL,
    platform        ENUM('jitsi','zoom','teams','whatsapp','phone','other') NOT NULL DEFAULT 'jitsi',
    scheduled_at    DATETIME NOT NULL,
    duration_mins   INT NOT NULL DEFAULT 30,
    chief_complaint TEXT NULL,
    meeting_link    VARCHAR(500) NULL,
    status          ENUM('scheduled','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    notes           TEXT NULL,
    diagnosis       TEXT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_htc_org  (org_id),
    INDEX idx_htc_pat  (patient_id),
    INDEX idx_htc_sch  (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 8: health_eprescriptions (Telemedicine ePrescriptions)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_eprescriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    rx_no           VARCHAR(30) NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT NULL,
    teleconsult_id  INT NULL,
    status          ENUM('draft','issued','dispensed','cancelled') NOT NULL DEFAULT 'draft',
    notes           TEXT NULL,
    issued_at       DATETIME NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hep_org (org_id),
    INDEX idx_hep_pat (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 9: health_eprescription_items
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_eprescription_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    medicine_name   VARCHAR(200) NOT NULL,
    dose            VARCHAR(100) NULL,
    frequency       VARCHAR(100) NULL,
    duration        VARCHAR(100) NULL,
    quantity        INT NULL,
    notes           TEXT NULL,
    INDEX idx_hepi_rx (prescription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 10: health_lab_tests (Laboratory test catalogue)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_lab_tests (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    name             VARCHAR(200) NOT NULL,
    code             VARCHAR(30) NULL,
    category         VARCHAR(100) NULL,
    normal_range     VARCHAR(200) NULL,
    unit             VARCHAR(50) NULL,
    price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    turnaround_hours INT NOT NULL DEFAULT 24,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hlt_org  (org_id),
    INDEX idx_hlt_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════════════════
-- SECTION 11: health_lab_orders (Laboratory test orders)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS health_lab_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    order_no        VARCHAR(30) NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT NULL,
    admission_id    INT NULL,
    test_id         INT NULL,
    test_name       VARCHAR(200) NOT NULL,
    priority        ENUM('routine','urgent','stat') NOT NULL DEFAULT 'routine',
    status          ENUM('ordered','collected','processing','resulted','cancelled') NOT NULL DEFAULT 'ordered',
    result_value    TEXT NULL,
    result_unit     VARCHAR(50) NULL,
    reference_range VARCHAR(200) NULL,
    result_flag     ENUM('normal','low','high','critical') NULL,
    result_notes    TEXT NULL,
    sample_type     VARCHAR(100) NULL,
    collected_at    DATETIME NULL,
    resulted_at     DATETIME NULL,
    ordered_by      INT NULL,
    resulted_by     INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hlo_org  (org_id),
    INDEX idx_hlo_pat  (patient_id),
    INDEX idx_hlo_stat (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
