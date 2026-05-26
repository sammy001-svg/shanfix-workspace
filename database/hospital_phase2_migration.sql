-- ══════════════════════════════════════════════════════════════════
-- OrbitDesk — Hospital Phase 2 Migration
-- Telemedicine, Patient CRM & Predictive Analytics
-- Run once. Safe — all use IF NOT EXISTS.
-- ══════════════════════════════════════════════════════════════════

-- ── Telemedicine Consultations ────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_teleconsults (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    consult_no      VARCHAR(30) NOT NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT DEFAULT NULL,
    appointment_id  INT DEFAULT NULL,
    platform        ENUM('jitsi','zoom','teams','whatsapp','phone','other') DEFAULT 'jitsi',
    meeting_link    VARCHAR(500) DEFAULT NULL,
    meeting_id      VARCHAR(150) DEFAULT NULL,
    scheduled_at    DATETIME NOT NULL,
    duration_mins   SMALLINT DEFAULT 30,
    status          ENUM('scheduled','in_waiting_room','in_progress','completed','cancelled','no_show') DEFAULT 'scheduled',
    chief_complaint TEXT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    started_at      DATETIME DEFAULT NULL,
    ended_at        DATETIME DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ePrescriptions ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_eprescriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    rx_no           VARCHAR(30) NOT NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT DEFAULT NULL,
    teleconsult_id  INT DEFAULT NULL,
    appointment_id  INT DEFAULT NULL,
    diagnosis       TEXT DEFAULT NULL,
    instructions    TEXT DEFAULT NULL COMMENT 'General instructions / notes to patient',
    qr_token        VARCHAR(64) DEFAULT NULL COMMENT 'Unique token for QR code validation',
    status          ENUM('active','dispensed','expired','cancelled') DEFAULT 'active',
    valid_until     DATE DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_qr_token (qr_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ePrescription Line Items ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_eprescription_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    medicine_id     INT DEFAULT NULL,
    medicine_name   VARCHAR(150) NOT NULL,
    dose            VARCHAR(80) DEFAULT NULL,
    route           ENUM('oral','iv','im','sc','topical','inhaled','other') DEFAULT 'oral',
    frequency       VARCHAR(80) DEFAULT NULL,
    duration        VARCHAR(80) DEFAULT NULL COMMENT 'e.g. 7 days, 2 weeks',
    quantity        INT DEFAULT 1,
    instructions    TEXT DEFAULT NULL,
    INDEX idx_prescription (prescription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Virtual Waiting Room ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_virtual_queue (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    teleconsult_id  INT NOT NULL,
    patient_id      INT NOT NULL,
    joined_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    notified_at     DATETIME DEFAULT NULL,
    called_at       DATETIME DEFAULT NULL,
    left_at         DATETIME DEFAULT NULL,
    status          ENUM('waiting','called','in_session','left') DEFAULT 'waiting',
    INDEX idx_org (org_id),
    INDEX idx_teleconsult (teleconsult_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Patient Feedback / Satisfaction Surveys ───────────────────────
CREATE TABLE IF NOT EXISTS health_patient_feedback (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    appointment_id  INT DEFAULT NULL,
    teleconsult_id  INT DEFAULT NULL,
    doctor_id       INT DEFAULT NULL,
    overall_rating  TINYINT DEFAULT NULL COMMENT '1-5 stars',
    doctor_rating   TINYINT DEFAULT NULL COMMENT '1-5 stars',
    wait_rating     TINYINT DEFAULT NULL COMMENT '1-5 stars',
    facility_rating TINYINT DEFAULT NULL COMMENT '1-5 stars',
    would_recommend TINYINT(1) DEFAULT NULL COMMENT '0/1',
    comments        TEXT DEFAULT NULL,
    source          ENUM('post_visit','post_teleconsult','manual','sms_survey','email_survey') DEFAULT 'post_visit',
    status          ENUM('pending','completed','archived') DEFAULT 'completed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_doctor (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Patient Follow-up Reminders ───────────────────────────────────
CREATE TABLE IF NOT EXISTS health_followups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    doctor_id       INT DEFAULT NULL,
    assigned_to     INT DEFAULT NULL COMMENT 'user_id responsible',
    appointment_id  INT DEFAULT NULL,
    admission_id    INT DEFAULT NULL,
    followup_type   ENUM('call','sms','email','appointment','lab_check','medication_review','other') DEFAULT 'call',
    due_date        DATE NOT NULL,
    priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    reason          TEXT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('pending','completed','missed','cancelled') DEFAULT 'pending',
    completed_at    DATETIME DEFAULT NULL,
    completed_by    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Patient Engagement Campaigns ─────────────────────────────────
CREATE TABLE IF NOT EXISTS health_patient_campaigns (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(255) NOT NULL,
    campaign_type   ENUM('sms','email','whatsapp','other') DEFAULT 'sms',
    target_group    ENUM('all_patients','by_diagnosis','by_doctor','chronic_conditions','due_for_followup','no_visit_90d','custom') DEFAULT 'all_patients',
    target_filter   JSON DEFAULT NULL COMMENT 'JSON criteria for target_group',
    subject         VARCHAR(255) DEFAULT NULL,
    message         TEXT DEFAULT NULL,
    status          ENUM('draft','scheduled','sent','cancelled') DEFAULT 'draft',
    scheduled_at    DATETIME DEFAULT NULL,
    sent_at         DATETIME DEFAULT NULL,
    recipients_count INT DEFAULT 0,
    sent_count      INT DEFAULT 0,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Patient Loyalty Program ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS health_loyalty_points (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    transaction_type ENUM('earn','redeem','expire','adjust') DEFAULT 'earn',
    points          INT NOT NULL DEFAULT 0,
    balance_after   INT NOT NULL DEFAULT 0,
    reason          VARCHAR(255) DEFAULT NULL COMMENT 'e.g. Visit, Lab test, Referral',
    reference_type  VARCHAR(50) DEFAULT NULL COMMENT 'appointment, lab_order, etc.',
    reference_id    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add loyalty_points column to patients
ALTER TABLE health_patients ADD COLUMN IF NOT EXISTS loyalty_points INT NOT NULL DEFAULT 0;

-- ── Clinical Alerts (rule-based AI) ──────────────────────────────
CREATE TABLE IF NOT EXISTS health_clinical_alerts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    alert_type      ENUM('sepsis_risk','readmission_risk','abnormal_vitals','critical_lab','medication_interaction','fall_risk','pressure_ulcer_risk','other') DEFAULT 'other',
    severity        ENUM('info','warning','critical') DEFAULT 'warning',
    title           VARCHAR(255) NOT NULL,
    message         TEXT NOT NULL,
    source_type     VARCHAR(50) DEFAULT NULL COMMENT 'vitals, lab, admission, etc.',
    source_id       INT DEFAULT NULL,
    risk_score      TINYINT DEFAULT NULL COMMENT '0-100 risk percentage',
    status          ENUM('active','acknowledged','resolved','dismissed') DEFAULT 'active',
    acknowledged_by INT DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    auto_generated  TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_patient (org_id, patient_id),
    INDEX idx_status (status),
    INDEX idx_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
