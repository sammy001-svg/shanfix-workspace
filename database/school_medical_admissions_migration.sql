-- ============================================================
-- School Module: Medical Records + Admissions Pipeline
-- Run after school_module_migration.sql
-- ============================================================

-- ── Student Medical / Clinic Records ─────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_medical_records (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    visit_date      DATE         NOT NULL,
    complaint       TEXT,
    diagnosis       TEXT,
    treatment       TEXT,
    prescribed_by   VARCHAR(150),
    temperature     DECIMAL(4,1)  DEFAULT NULL COMMENT 'Degrees Celsius',
    blood_pressure  VARCHAR(20)   DEFAULT NULL COMMENT 'e.g. 120/80',
    weight_kg       DECIMAL(5,2)  DEFAULT NULL,
    height_cm       DECIMAL(5,2)  DEFAULT NULL,
    follow_up_date  DATE          DEFAULT NULL,
    follow_up_notes TEXT,
    status          ENUM('open','resolved','referred') NOT NULL DEFAULT 'open',
    created_by      INT UNSIGNED  DEFAULT NULL,
    created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_med_org_student (org_id, student_id),
    INDEX idx_med_visit_date  (visit_date),
    INDEX idx_med_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admissions Pipeline ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_admissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    app_no          VARCHAR(30)  NOT NULL,
    first_name      VARCHAR(80)  NOT NULL,
    last_name       VARCHAR(80)  NOT NULL,
    dob             DATE         DEFAULT NULL,
    gender          ENUM('male','female','other') DEFAULT 'male',
    nationality     VARCHAR(80)  DEFAULT NULL,
    class_applying  VARCHAR(80)  DEFAULT NULL,
    curriculum      ENUM('IB','IGCSE','Cambridge','CBC','AP','Other') DEFAULT 'IB',
    previous_school VARCHAR(150) DEFAULT NULL,
    parent_name     VARCHAR(120) DEFAULT NULL,
    parent_phone    VARCHAR(30)  DEFAULT NULL,
    parent_email    VARCHAR(120) DEFAULT NULL,
    address         TEXT,
    stage           ENUM('inquiry','applied','review','interview','accepted','enrolled','rejected','withdrawn') NOT NULL DEFAULT 'applied',
    applied_date    DATE         NOT NULL,
    interview_date  DATE         DEFAULT NULL,
    interview_notes TEXT,
    offer_sent      TINYINT(1)   NOT NULL DEFAULT 0,
    notes           TEXT,
    reviewed_by     INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_adm_app_no (org_id, app_no),
    INDEX idx_adm_org_stage  (org_id, stage),
    INDEX idx_adm_applied    (applied_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
