-- ═══════════════════════════════════════════════════════════════════
-- Shanfix International School Module — Phase 3 Migration
-- Currencies: KES, USD, LRD  |  Curricula: IB, IGCSE/Cambridge
-- Run after school_phase2_migration.sql
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────
-- 1. DEDICATED TEACHER PROFILES
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_teachers` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`            INT NOT NULL,
  `employee_id`       VARCHAR(30) DEFAULT NULL,
  `user_id`           INT DEFAULT NULL COMMENT 'Link to system users table',
  `first_name`        VARCHAR(80) NOT NULL,
  `last_name`         VARCHAR(80) NOT NULL,
  `gender`            ENUM('male','female','other') NOT NULL DEFAULT 'male',
  `dob`               DATE DEFAULT NULL,
  `nationality`       VARCHAR(80) DEFAULT NULL,
  `passport_no`       VARCHAR(50) DEFAULT NULL,
  `email`             VARCHAR(150) DEFAULT NULL,
  `phone`             VARCHAR(30) DEFAULT NULL,
  `qualification`     VARCHAR(300) DEFAULT NULL COMMENT 'e.g. B.Ed Mathematics, MSc Education',
  `specialization`    VARCHAR(300) DEFAULT NULL COMMENT 'e.g. IB Mathematics HL, IGCSE Physics',
  `curriculum`        SET('IB','IGCSE','Cambridge','CBC','AP','Other') DEFAULT 'IB',
  `contract_type`     ENUM('permanent','contract','part-time','volunteer','visiting') NOT NULL DEFAULT 'permanent',
  `join_date`         DATE DEFAULT NULL,
  `end_date`          DATE DEFAULT NULL,
  `emergency_contact` VARCHAR(100) DEFAULT NULL,
  `emergency_phone`   VARCHAR(30) DEFAULT NULL,
  `address`           TEXT DEFAULT NULL,
  `photo`             VARCHAR(255) DEFAULT NULL,
  `status`            ENUM('active','on-leave','resigned','terminated') NOT NULL DEFAULT 'active',
  `notes`             TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_sch_teachers_org` (`org_id`),
  KEY `idx_sch_teachers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- 2. ACADEMIC TERMS
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_terms` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`           INT NOT NULL,
  `academic_year_id` INT DEFAULT NULL,
  `name`             VARCHAR(60) NOT NULL COMMENT 'e.g. Term 1 2025, Semester 1 2025/26',
  `term_type`        ENUM('term','semester','quarter','trimester') NOT NULL DEFAULT 'term',
  `start_date`       DATE NOT NULL,
  `end_date`         DATE NOT NULL,
  `is_current`       TINYINT(1) NOT NULL DEFAULT 0,
  `status`           ENUM('upcoming','active','completed') NOT NULL DEFAULT 'upcoming',
  `notes`            VARCHAR(300) DEFAULT NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_terms_org` (`org_id`),
  KEY `idx_sch_terms_current` (`org_id`,`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- 3. DISCIPLINARY RECORDS
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_disciplinary` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`              INT NOT NULL,
  `student_id`          INT NOT NULL,
  `term_id`             INT DEFAULT NULL,
  `reported_by`         INT DEFAULT NULL COMMENT 'user_id',
  `incident_date`       DATE NOT NULL,
  `category`            ENUM('behaviour','attendance','uniform','bullying','academic-dishonesty','substance','property-damage','cyberbullying','other') NOT NULL DEFAULT 'behaviour',
  `severity`            ENUM('minor','moderate','serious','critical') NOT NULL DEFAULT 'minor',
  `description`         TEXT NOT NULL,
  `witnesses`           VARCHAR(300) DEFAULT NULL,
  `action_taken`        TEXT DEFAULT NULL,
  `suspension_days`     INT NOT NULL DEFAULT 0,
  `parent_notified`     TINYINT(1) NOT NULL DEFAULT 0,
  `parent_notified_at`  DATETIME DEFAULT NULL,
  `parent_response`     TEXT DEFAULT NULL,
  `follow_up_date`      DATE DEFAULT NULL,
  `follow_up_notes`     TEXT DEFAULT NULL,
  `status`              ENUM('open','under-review','resolved','appealed','dismissed') NOT NULL DEFAULT 'open',
  `resolved_at`         DATETIME DEFAULT NULL,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_sch_disc_org` (`org_id`),
  KEY `idx_sch_disc_student` (`student_id`),
  KEY `idx_sch_disc_date` (`incident_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- 4. FEE PAYMENT HISTORY (for partial payments & receipts)
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_fee_payments` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`         INT NOT NULL,
  `fee_id`         INT NOT NULL,
  `student_id`     INT NOT NULL,
  `receipt_no`     VARCHAR(50) NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `currency`       ENUM('KES','USD','LRD') NOT NULL DEFAULT 'KES',
  `exchange_rate`  DECIMAL(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Rate to KES at time of payment',
  `amount_kes`     DECIMAL(14,2) NOT NULL COMMENT 'Amount converted to KES',
  `payment_method` ENUM('cash','mpesa','bank-transfer','card','cheque','online','other') NOT NULL DEFAULT 'cash',
  `paid_by`        VARCHAR(100) DEFAULT NULL,
  `payment_date`   DATE NOT NULL,
  `notes`          TEXT DEFAULT NULL,
  `created_by`     INT DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_pay_org` (`org_id`),
  KEY `idx_sch_pay_fee` (`fee_id`),
  KEY `idx_sch_pay_student` (`student_id`),
  KEY `idx_sch_pay_receipt` (`receipt_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- 5. ALTER sch_students — International Fields
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `sch_students`
  ADD COLUMN IF NOT EXISTS `nationality`        VARCHAR(80)  DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `passport_no`        VARCHAR(50)  DEFAULT NULL AFTER `nationality`,
  ADD COLUMN IF NOT EXISTS `visa_expiry`        DATE         DEFAULT NULL AFTER `passport_no`,
  ADD COLUMN IF NOT EXISTS `curriculum`         ENUM('IB','IGCSE','Cambridge','CBC','AP','Other') DEFAULT 'IB' AFTER `visa_expiry`,
  ADD COLUMN IF NOT EXISTS `mother_tongue`      VARCHAR(80)  DEFAULT NULL AFTER `curriculum`,
  ADD COLUMN IF NOT EXISTS `previous_school`    VARCHAR(200) DEFAULT NULL AFTER `mother_tongue`,
  ADD COLUMN IF NOT EXISTS `medical_conditions` TEXT         DEFAULT NULL AFTER `previous_school`,
  ADD COLUMN IF NOT EXISTS `learning_support`   TINYINT(1)  NOT NULL DEFAULT 0 AFTER `medical_conditions`,
  ADD COLUMN IF NOT EXISTS `emergency_contact`  VARCHAR(100) DEFAULT NULL AFTER `learning_support`,
  ADD COLUMN IF NOT EXISTS `emergency_phone`    VARCHAR(30)  DEFAULT NULL AFTER `emergency_contact`,
  ADD COLUMN IF NOT EXISTS `photo`              VARCHAR(255) DEFAULT NULL AFTER `emergency_phone`;

-- ─────────────────────────────────────────────────────────────────
-- 6. ALTER sch_fees — Multi-currency & Fee Types
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `sch_fees`
  ADD COLUMN IF NOT EXISTS `fee_type`       ENUM('tuition','hostel','transport','activity','exam','library','uniform','other') NOT NULL DEFAULT 'tuition' AFTER `student_id`,
  ADD COLUMN IF NOT EXISTS `term_id`        INT          DEFAULT NULL AFTER `fee_type`,
  ADD COLUMN IF NOT EXISTS `currency`       ENUM('KES','USD','LRD') NOT NULL DEFAULT 'KES' AFTER `balance`,
  ADD COLUMN IF NOT EXISTS `due_date`       DATE         DEFAULT NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `receipt_no`     VARCHAR(50)  DEFAULT NULL AFTER `due_date`,
  ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','mpesa','bank-transfer','card','cheque','online','other') DEFAULT NULL AFTER `receipt_no`,
  ADD COLUMN IF NOT EXISTS `paid_by`        VARCHAR(100) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `notes`          TEXT         DEFAULT NULL AFTER `paid_by`;

-- ─────────────────────────────────────────────────────────────────
-- 7. ALTER sch_grades — Curriculum-specific grading
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `sch_grades`
  ADD COLUMN IF NOT EXISTS `curriculum`    ENUM('IB','IGCSE','Cambridge','CBC','AP','General') NOT NULL DEFAULT 'General' AFTER `org_id`,
  ADD COLUMN IF NOT EXISTS `ib_points`     TINYINT UNSIGNED DEFAULT NULL COMMENT 'IB points 1-7',
  ADD COLUMN IF NOT EXISTS `igcse_grade`   VARCHAR(5) DEFAULT NULL COMMENT 'A*,A,B,C,D,E,F,G,U';

-- ─────────────────────────────────────────────────────────────────
-- 8. ALTER sch_results — Add curriculum and teacher comment
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `sch_results`
  ADD COLUMN IF NOT EXISTS `curriculum`        ENUM('IB','IGCSE','Cambridge','CBC','AP','General') DEFAULT 'IB' AFTER `org_id`,
  ADD COLUMN IF NOT EXISTS `teacher_comment`   TEXT DEFAULT NULL AFTER `remarks`,
  ADD COLUMN IF NOT EXISTS `predicted_grade`   VARCHAR(10) DEFAULT NULL AFTER `teacher_comment`;

-- ─────────────────────────────────────────────────────────────────
-- 9. ALTER sch_classes — Add curriculum and academic level
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `sch_classes`
  ADD COLUMN IF NOT EXISTS `curriculum`      ENUM('IB','IGCSE','Cambridge','CBC','AP','Mixed') NOT NULL DEFAULT 'IB' AFTER `org_id`,
  ADD COLUMN IF NOT EXISTS `level`           VARCHAR(50) DEFAULT NULL COMMENT 'e.g. PYP, MYP, DP, IGCSE, AS Level',
  ADD COLUMN IF NOT EXISTS `class_teacher_id` INT DEFAULT NULL AFTER `level`,
  ADD COLUMN IF NOT EXISTS `room`            VARCHAR(50) DEFAULT NULL AFTER `class_teacher_id`;

SET FOREIGN_KEY_CHECKS = 1;

