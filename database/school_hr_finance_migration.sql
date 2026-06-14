-- ============================================================
--  School Module — HR & Finance Migration
--  File: school_hr_finance_migration.sql
--  Safe to re-run: all statements use IF NOT EXISTS / IF EXISTS
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 1. Leave Types
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_leave_types` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `org_id`            INT           NOT NULL,
    `name`              VARCHAR(100)  NOT NULL,
    `days_per_year`     INT           NOT NULL DEFAULT 21,
    `carry_forward`     TINYINT(1)    NOT NULL DEFAULT 0,
    `requires_approval` TINYINT(1)    NOT NULL DEFAULT 1,
    `paid_leave`        TINYINT(1)    NOT NULL DEFAULT 1,
    `status`            VARCHAR(20)   NOT NULL DEFAULT 'active',
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lt_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 2. Leave Requests
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_leave_requests` (
    `id`              INT           NOT NULL AUTO_INCREMENT,
    `org_id`          INT           NOT NULL,
    `staff_id`        INT           NOT NULL,
    `staff_type`      ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',
    `leave_type_id`   INT           NOT NULL,
    `start_date`      DATE          NOT NULL,
    `end_date`        DATE          NOT NULL,
    `days`            INT           NOT NULL DEFAULT 1,
    `reason`          TEXT,
    `status`          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `approved_by`     INT                    DEFAULT NULL,
    `approved_at`     DATETIME               DEFAULT NULL,
    `admin_notes`     TEXT,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lr_staff`  (`staff_id`,`staff_type`),
    KEY `idx_lr_status` (`org_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 3. Leave Balances
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_leave_balances` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `org_id`         INT NOT NULL,
    `staff_id`       INT NOT NULL,
    `staff_type`     ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',
    `leave_type_id`  INT NOT NULL,
    `year`           INT NOT NULL,
    `allocated_days` INT NOT NULL DEFAULT 0,
    `used_days`      INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lb` (`org_id`,`staff_id`,`staff_type`,`leave_type_id`,`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 4. Salary Grades
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_salary_grades` (
    `id`                  INT           NOT NULL AUTO_INCREMENT,
    `org_id`              INT           NOT NULL,
    `grade_name`          VARCHAR(100)  NOT NULL,
    `basic_salary`        DECIMAL(14,2) NOT NULL DEFAULT 0,
    `house_allowance`     DECIMAL(14,2) NOT NULL DEFAULT 0,
    `transport_allowance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `medical_allowance`   DECIMAL(14,2) NOT NULL DEFAULT 0,
    `other_allowances`    DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paye_rate`           DECIMAL(5,2)  NOT NULL DEFAULT 0  COMMENT 'Percentage 0-100',
    `nhif_amount`         DECIMAL(10,2) NOT NULL DEFAULT 0,
    `nssf_amount`         DECIMAL(10,2) NOT NULL DEFAULT 0,
    `other_deductions`    DECIMAL(14,2) NOT NULL DEFAULT 0,
    `currency`            VARCHAR(10)   NOT NULL DEFAULT 'KES',
    `status`              VARCHAR(20)   NOT NULL DEFAULT 'active',
    `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sg_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salary grade column on teachers & staff tables
ALTER TABLE `sch_teachers`
    ADD COLUMN IF NOT EXISTS `salary_grade_id` INT DEFAULT NULL AFTER `status`;

-- ────────────────────────────────────────────────────────────
-- 5. Payroll Runs (one per month per org)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_payroll_runs` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `org_id`           INT           NOT NULL,
    `period_month`     TINYINT       NOT NULL COMMENT '1-12',
    `period_year`      INT           NOT NULL,
    `status`           ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
    `total_gross`      DECIMAL(16,2) NOT NULL DEFAULT 0,
    `total_deductions` DECIMAL(16,2) NOT NULL DEFAULT 0,
    `total_net`        DECIMAL(16,2) NOT NULL DEFAULT 0,
    `currency`         VARCHAR(10)   NOT NULL DEFAULT 'KES',
    `notes`            TEXT,
    `processed_by`     INT                    DEFAULT NULL,
    `processed_at`     DATETIME               DEFAULT NULL,
    `approved_by`      INT                    DEFAULT NULL,
    `approved_at`      DATETIME               DEFAULT NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pr` (`org_id`,`period_month`,`period_year`),
    KEY `idx_pr_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 6. Payslips (one per employee per payroll run)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_payslips` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `org_id`           INT           NOT NULL,
    `payroll_run_id`   INT           NOT NULL,
    `staff_id`         INT           NOT NULL,
    `staff_type`       ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',
    `staff_name`       VARCHAR(200)  NOT NULL,
    `employee_id`      VARCHAR(50)            DEFAULT NULL,
    `grade_name`       VARCHAR(100)           DEFAULT NULL,
    `basic_salary`     DECIMAL(14,2) NOT NULL DEFAULT 0,
    `house_allowance`  DECIMAL(14,2) NOT NULL DEFAULT 0,
    `transport_allow`  DECIMAL(14,2) NOT NULL DEFAULT 0,
    `medical_allow`    DECIMAL(14,2) NOT NULL DEFAULT 0,
    `other_allowances` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `gross_salary`     DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paye`             DECIMAL(14,2) NOT NULL DEFAULT 0,
    `nhif`             DECIMAL(14,2) NOT NULL DEFAULT 0,
    `nssf`             DECIMAL(14,2) NOT NULL DEFAULT 0,
    `other_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `net_salary`       DECIMAL(14,2) NOT NULL DEFAULT 0,
    `currency`         VARCHAR(10)   NOT NULL DEFAULT 'KES',
    `status`           ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
    `notes`            TEXT,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ps` (`payroll_run_id`,`staff_id`,`staff_type`),
    KEY `idx_ps_staff`  (`staff_id`,`staff_type`),
    KEY `idx_ps_org`    (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 7. Expenses
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_expenses` (
    `id`             INT           NOT NULL AUTO_INCREMENT,
    `org_id`         INT           NOT NULL,
    `category`       VARCHAR(100)  NOT NULL,
    `description`    TEXT          NOT NULL,
    `amount`         DECIMAL(14,2) NOT NULL DEFAULT 0,
    `currency`       VARCHAR(10)   NOT NULL DEFAULT 'KES',
    `expense_date`   DATE          NOT NULL,
    `vendor`         VARCHAR(200)           DEFAULT NULL,
    `receipt_no`     VARCHAR(100)           DEFAULT NULL,
    `payment_method` VARCHAR(50)            DEFAULT NULL,
    `status`         ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    `approved_by`    INT                    DEFAULT NULL,
    `approved_at`    DATETIME               DEFAULT NULL,
    `notes`          TEXT,
    `created_by`     INT           NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_exp_org`  (`org_id`),
    KEY `idx_exp_date` (`org_id`,`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 8. Staff Attendance (separate from student attendance)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_staff_attendance` (
    `id`         INT           NOT NULL AUTO_INCREMENT,
    `org_id`     INT           NOT NULL,
    `staff_id`   INT           NOT NULL,
    `staff_type` ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',
    `att_date`   DATE          NOT NULL,
    `status`     ENUM('present','absent','late','on_leave','half_day') NOT NULL DEFAULT 'present',
    `check_in`   TIME                   DEFAULT NULL,
    `check_out`  TIME                   DEFAULT NULL,
    `notes`      VARCHAR(255)           DEFAULT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sa` (`org_id`,`staff_id`,`staff_type`,`att_date`),
    KEY `idx_sa_date` (`org_id`,`att_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
