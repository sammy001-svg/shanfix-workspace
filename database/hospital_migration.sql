-- ══════════════════════════════════════════════════════════════════
-- OrbitDesk — Complete Hospital Management System Migration
-- Run once. Extends the existing health module tables.
-- ══════════════════════════════════════════════════════════════════

-- ── Vital Signs ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_vitals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    patient_id    INT NOT NULL,
    appointment_id INT DEFAULT NULL,
    recorded_by   INT DEFAULT NULL          COMMENT 'user_id of recorder',
    bp_systolic   SMALLINT DEFAULT NULL     COMMENT 'mmHg',
    bp_diastolic  SMALLINT DEFAULT NULL     COMMENT 'mmHg',
    pulse         SMALLINT DEFAULT NULL     COMMENT 'bpm',
    temperature   DECIMAL(5,2) DEFAULT NULL COMMENT 'Celsius',
    weight        DECIMAL(6,2) DEFAULT NULL COMMENT 'kg',
    height        DECIMAL(6,2) DEFAULT NULL COMMENT 'cm',
    bmi           DECIMAL(5,2) DEFAULT NULL COMMENT 'auto-calculated',
    spo2          TINYINT DEFAULT NULL      COMMENT '% oxygen saturation',
    resp_rate     TINYINT DEFAULT NULL      COMMENT 'breaths/min',
    pain_scale    TINYINT DEFAULT NULL      COMMENT '0-10',
    notes         TEXT DEFAULT NULL,
    recorded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_appointment (appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lab Test Catalog ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_lab_tests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    name         VARCHAR(150) NOT NULL,
    category     VARCHAR(100) DEFAULT NULL COMMENT 'Haematology, Chemistry, Microbiology…',
    normal_range VARCHAR(100) DEFAULT NULL,
    unit         VARCHAR(50)  DEFAULT NULL,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    turnaround   TINYINT DEFAULT 1         COMMENT 'Expected hours to result',
    status       ENUM('active','inactive') DEFAULT 'active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lab Orders ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_lab_orders (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    org_id         INT NOT NULL,
    order_no       VARCHAR(30)  NOT NULL,
    patient_id     INT NOT NULL,
    doctor_id      INT DEFAULT NULL,
    appointment_id INT DEFAULT NULL,
    admission_id   INT DEFAULT NULL,
    test_id        INT NOT NULL,
    priority       ENUM('routine','urgent','stat') DEFAULT 'routine',
    status         ENUM('ordered','collected','processing','resulted','cancelled') DEFAULT 'ordered',
    sample_type    VARCHAR(80)  DEFAULT NULL,
    collected_at   DATETIME     DEFAULT NULL,
    result_value   TEXT         DEFAULT NULL,
    result_notes   TEXT         DEFAULT NULL,
    result_flag    ENUM('normal','low','high','critical') DEFAULT NULL,
    resulted_at    DATETIME     DEFAULT NULL,
    resulted_by    INT          DEFAULT NULL,
    ordered_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_test (test_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Medicine Inventory ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_medicines (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    org_id         INT NOT NULL,
    name           VARCHAR(150) NOT NULL,
    generic_name   VARCHAR(150) DEFAULT NULL,
    category       VARCHAR(100) DEFAULT NULL COMMENT 'Antibiotic, Analgesic, Antihypertensive…',
    form           VARCHAR(60)  DEFAULT NULL COMMENT 'Tablet, Capsule, Syrup, Injection…',
    strength       VARCHAR(60)  DEFAULT NULL COMMENT 'e.g. 500mg, 250mg/5ml',
    unit           VARCHAR(30)  DEFAULT 'Units',
    unit_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_qty      INT NOT NULL DEFAULT 0,
    reorder_level  INT NOT NULL DEFAULT 10,
    expiry_date    DATE         DEFAULT NULL,
    supplier       VARCHAR(150) DEFAULT NULL,
    status         ENUM('active','inactive') DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Medicine Dispensing ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_dispensing (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    dispense_no   VARCHAR(30) NOT NULL,
    patient_id    INT NOT NULL,
    record_id     INT DEFAULT NULL   COMMENT 'health_records.id — prescription source',
    admission_id  INT DEFAULT NULL,
    medicine_id   INT NOT NULL,
    quantity      INT NOT NULL DEFAULT 1,
    unit_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    dosage_instructions TEXT DEFAULT NULL,
    dispensed_by  INT DEFAULT NULL,
    dispensed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes         TEXT DEFAULT NULL,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_medicine (medicine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Wards ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_wards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    ward_type   ENUM('general','private','icu','maternity','paediatric','surgical','emergency','other') DEFAULT 'general',
    floor       VARCHAR(30) DEFAULT NULL,
    capacity    INT NOT NULL DEFAULT 0,
    status      ENUM('active','inactive') DEFAULT 'active',
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Beds ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_beds (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    org_id     INT NOT NULL,
    ward_id    INT NOT NULL,
    bed_no     VARCHAR(20) NOT NULL,
    bed_type   ENUM('standard','icu','isolation','maternity','cot','other') DEFAULT 'standard',
    status     ENUM('available','occupied','maintenance','cleaning') DEFAULT 'available',
    notes      TEXT DEFAULT NULL,
    INDEX idx_org_ward (org_id, ward_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admissions (IPD) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_admissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    admission_no    VARCHAR(30) NOT NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT DEFAULT NULL,
    ward_id         INT DEFAULT NULL,
    bed_id          INT DEFAULT NULL,
    reason          TEXT DEFAULT NULL,
    diagnosis       TEXT DEFAULT NULL,
    admission_type  ENUM('emergency','elective','maternity','referral','other') DEFAULT 'elective',
    status          ENUM('admitted','transferred','discharged') DEFAULT 'admitted',
    admitted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    discharged_at   DATETIME DEFAULT NULL,
    discharge_notes TEXT DEFAULT NULL,
    discharge_type  ENUM('recovered','referred','absconded','death','other') DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Nursing Notes ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_nursing_notes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    patient_id   INT NOT NULL,
    admission_id INT DEFAULT NULL,
    nurse_id     INT DEFAULT NULL COMMENT 'user_id',
    note_type    ENUM('general','shift_handover','care_plan','observation','incident') DEFAULT 'general',
    shift        ENUM('morning','afternoon','night') DEFAULT NULL,
    note_text    TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_admission (admission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Medication Administration Records (MAR) ───────────────────────
CREATE TABLE IF NOT EXISTS health_mar (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    admission_id    INT NOT NULL,
    medicine_id     INT NOT NULL,
    medicine_name   VARCHAR(150) DEFAULT NULL COMMENT 'Snapshot at time of order',
    dose            VARCHAR(80)  DEFAULT NULL,
    route           ENUM('oral','iv','im','sc','topical','inhaled','other') DEFAULT 'oral',
    frequency       VARCHAR(80)  DEFAULT NULL COMMENT 'e.g. BD, TDS, QID, PRN',
    start_date      DATE NOT NULL,
    end_date        DATE DEFAULT NULL,
    status          ENUM('active','completed','discontinued') DEFAULT 'active',
    ordered_by      INT DEFAULT NULL,
    administered_by INT DEFAULT NULL,
    administered_at DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_admission (org_id, admission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Emergency / Triage ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_triage (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    org_id         INT NOT NULL,
    triage_no      VARCHAR(30) NOT NULL,
    patient_id     INT DEFAULT NULL  COMMENT 'NULL for unregistered walk-ins',
    patient_name   VARCHAR(200) DEFAULT NULL COMMENT 'Walk-in patient name',
    patient_phone  VARCHAR(25)  DEFAULT NULL,
    age            TINYINT DEFAULT NULL,
    gender         ENUM('male','female','other') DEFAULT NULL,
    triage_level   ENUM('1_immediate','2_emergent','3_urgent','4_semi_urgent','5_non_urgent') DEFAULT '3_urgent',
    chief_complaint TEXT DEFAULT NULL,
    bp_systolic    SMALLINT DEFAULT NULL,
    bp_diastolic   SMALLINT DEFAULT NULL,
    pulse          SMALLINT DEFAULT NULL,
    temperature    DECIMAL(5,2) DEFAULT NULL,
    spo2           TINYINT DEFAULT NULL,
    gcs            TINYINT DEFAULT NULL COMMENT 'Glasgow Coma Scale 3-15',
    status         ENUM('waiting','in_progress','admitted','discharged','referred','left_without_seen') DEFAULT 'waiting',
    triaged_by     INT DEFAULT NULL,
    triaged_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    doctor_id      INT DEFAULT NULL,
    seen_at        DATETIME DEFAULT NULL,
    disposition    ENUM('admit','discharge','refer','observation','died') DEFAULT NULL,
    disposition_notes TEXT DEFAULT NULL,
    INDEX idx_org (org_id),
    INDEX idx_status (status),
    INDEX idx_level (triage_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hospital Service Charge Catalog ──────────────────────────────
CREATE TABLE IF NOT EXISTS health_services (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(150) NOT NULL,
    category    ENUM('consultation','procedure','lab','radiology','pharmacy','nursing','room','other') DEFAULT 'other',
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hospital Bills ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_bills (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    bill_no      VARCHAR(30) NOT NULL,
    patient_id   INT NOT NULL,
    admission_id INT DEFAULT NULL,
    bill_type    ENUM('opd','ipd','emergency','other') DEFAULT 'opd',
    subtotal     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status       ENUM('draft','sent','partial','paid','cancelled') DEFAULT 'draft',
    payment_method VARCHAR(50) DEFAULT NULL,
    insurance_provider VARCHAR(150) DEFAULT NULL,
    insurance_no VARCHAR(100) DEFAULT NULL,
    notes        TEXT DEFAULT NULL,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at      DATETIME DEFAULT NULL,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bill Line Items ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_bill_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    bill_id      INT NOT NULL,
    description  VARCHAR(255) NOT NULL,
    category     VARCHAR(80)  DEFAULT NULL,
    quantity     INT NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    INDEX idx_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hospital Staff (non-doctor) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS health_staff (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    user_id      INT DEFAULT NULL COMMENT 'links to users table',
    staff_no     VARCHAR(30) DEFAULT NULL,
    first_name   VARCHAR(100) NOT NULL,
    last_name    VARCHAR(100) NOT NULL,
    role         ENUM('nurse','lab_technician','pharmacist','radiologist','receptionist','admin','other') DEFAULT 'nurse',
    department   VARCHAR(100) DEFAULT NULL,
    phone        VARCHAR(25) DEFAULT NULL,
    email        VARCHAR(255) DEFAULT NULL,
    status       ENUM('active','inactive','on_leave') DEFAULT 'active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed default services for new installs ────────────────────────
-- (org_id 0 = system defaults, used as template — production should insert per org)
