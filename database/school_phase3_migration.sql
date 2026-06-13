-- =========================================================
-- School Module Phase 3: Budget, M-Pesa Fee Payments, Homework Submissions
-- Migration: school_phase3_migration.sql
-- Created: 2026-06-13
-- =========================================================

-- -- School Budget & Expenses --------------------------------------------------
CREATE TABLE IF NOT EXISTS `sch_budget_items` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `org_id`            INT           NOT NULL,
    `academic_year_id`  INT                    DEFAULT NULL,
    `term_id`           INT                    DEFAULT NULL,
    `category`          ENUM('income','expense') NOT NULL DEFAULT 'expense',
    `type`              VARCHAR(100)  NOT NULL,
    `description`       VARCHAR(255)  NOT NULL,
    `budgeted_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `actual_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `transaction_date`  DATE          NOT NULL,
    `recorded_by`       INT                    DEFAULT NULL,
    `notes`             TEXT                   DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME               DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org`      (`org_id`),
    KEY `idx_year`     (`academic_year_id`),
    KEY `idx_term`     (`term_id`),
    KEY `idx_date`     (`transaction_date`),
    KEY `idx_category` (`org_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- sch_fee_payments: add amount_paid column if missing (used by parent portal) --
-- Some installations may have `amount` instead of `amount_paid`
ALTER TABLE `sch_fee_payments`
    ADD COLUMN IF NOT EXISTS `amount_paid`     DECIMAL(12,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_method`  VARCHAR(50)   DEFAULT 'cash',
    ADD COLUMN IF NOT EXISTS `receipt_no`      VARCHAR(80)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `paid_by`         VARCHAR(150)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_date`    DATE          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `notes`           TEXT          DEFAULT NULL;

-- Backfill amount_paid from amount where null
UPDATE `sch_fee_payments`
SET `amount_paid` = `amount`
WHERE `amount_paid` IS NULL AND `amount` IS NOT NULL;

-- -- sch_parents: ensure is_primary exists (from Phase 2 migration) ------------
ALTER TABLE `sch_parents`
    ADD COLUMN IF NOT EXISTS `is_primary` TINYINT(1) NOT NULL DEFAULT 0;

-- -- sch_student_parents: link table for student <-> parent (multi-child support) -
CREATE TABLE IF NOT EXISTS `sch_student_parents` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `org_id`     INT NOT NULL,
    `student_id` INT NOT NULL,
    `parent_id`  INT NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_stu_par` (`student_id`, `parent_id`),
    KEY `idx_org`     (`org_id`),
    KEY `idx_student` (`student_id`),
    KEY `idx_parent`  (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- sch_homework: ensure all columns exist -------------------------------------
CREATE TABLE IF NOT EXISTS `sch_homework` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `org_id`      INT           NOT NULL,
    `class_id`    INT           NOT NULL,
    `subject_id`  INT                    DEFAULT NULL,
    `teacher_id`  INT                    DEFAULT NULL,
    `term_id`     INT                    DEFAULT NULL,
    `title`       VARCHAR(255)  NOT NULL,
    `description` TEXT                   DEFAULT NULL,
    `due_date`    DATE                   DEFAULT NULL,
    `max_marks`   DECIMAL(6,2)           DEFAULT 10.00,
    `status`      ENUM('active','closed') NOT NULL DEFAULT 'active',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org`   (`org_id`),
    KEY `idx_class` (`class_id`),
    KEY `idx_due`   (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- sch_homework_submissions ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `sch_homework_submissions` (
    `id`             INT           NOT NULL AUTO_INCREMENT,
    `homework_id`    INT           NOT NULL,
    `student_id`     INT           NOT NULL,
    `org_id`         INT           NOT NULL,
    `status`         ENUM('pending','submitted','late','missing') NOT NULL DEFAULT 'pending',
    `marks_obtained` DECIMAL(6,2)           DEFAULT NULL,
    `submitted_at`   DATETIME               DEFAULT NULL,
    `marked_at`      DATETIME               DEFAULT NULL,
    `notes`          TEXT                   DEFAULT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hw_stu` (`homework_id`, `student_id`),
    KEY `idx_org`      (`org_id`),
    KEY `idx_homework` (`homework_id`),
    KEY `idx_student`  (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- sch_academic_years: ensure is_current exists ------------------------------
CREATE TABLE IF NOT EXISTS `sch_academic_years` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `org_id`     INT          NOT NULL,
    `name`       VARCHAR(100) NOT NULL,
    `start_date` DATE                  DEFAULT NULL,
    `end_date`   DATE                  DEFAULT NULL,
    `is_current` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- sch_terms -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sch_terms` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `org_id`           INT          NOT NULL,
    `academic_year_id` INT                   DEFAULT NULL,
    `name`             VARCHAR(100) NOT NULL,
    `term_type`        ENUM('term','semester','quarter','trimester') NOT NULL DEFAULT 'term',
    `start_date`       DATE                  DEFAULT NULL,
    `end_date`         DATE                  DEFAULT NULL,
    `is_current`       TINYINT(1)   NOT NULL DEFAULT 0,
    `status`           ENUM('upcoming','active','completed') NOT NULL DEFAULT 'upcoming',
    `notes`            TEXT                  DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org`  (`org_id`),
    KEY `idx_year` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Confirmation
SELECT 'school_phase3_migration applied successfully.' AS migration_status;
