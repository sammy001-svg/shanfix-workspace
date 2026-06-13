-- =========================================================
-- School Module: Homework, Assignments & Student Promotion
-- Migration: school_homework_promotion_migration.sql
-- Created: 2026-06-13
-- =========================================================

-- Student Promotions Log
-- Tracks end-of-year promotion, graduation, retention, and transfer decisions
CREATE TABLE IF NOT EXISTS `sch_promotions` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `org_id`           INT          NOT NULL,
    `student_id`       INT          NOT NULL,
    `from_class_id`    INT                   DEFAULT NULL,
    `to_class_id`      INT                   DEFAULT NULL,
    `academic_year_id` INT                   DEFAULT NULL,
    `promotion_type`   ENUM('promoted','graduated','retained','transferred') NOT NULL DEFAULT 'promoted',
    `promoted_by`      INT                   DEFAULT NULL,
    `notes`            TEXT,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One decision per student per academic year (upsertable)
    UNIQUE KEY `uk_student_year` (`org_id`, `student_id`, `academic_year_id`),
    KEY `idx_org`     (`org_id`),
    KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Homework / Assignments
-- Teachers assign homework to a class+subject combination
CREATE TABLE IF NOT EXISTS `sch_homework` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `org_id`       INT          NOT NULL,
    `class_id`     INT          NOT NULL,
    `subject_id`   INT                   DEFAULT NULL,
    `teacher_id`   INT                   DEFAULT NULL,
    `term_id`      INT                   DEFAULT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT,
    `instructions` TEXT,
    `due_date`     DATE                  DEFAULT NULL,
    `max_marks`    DECIMAL(6,2) NOT NULL DEFAULT 10.00,
    `status`       ENUM('active','closed','draft') NOT NULL DEFAULT 'active',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org_class` (`org_id`, `class_id`),
    KEY `idx_status`    (`status`),
    KEY `idx_due_date`  (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Homework Submissions
-- Tracks each student's submission status and marks for a homework assignment
CREATE TABLE IF NOT EXISTS `sch_homework_submissions` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `homework_id`    INT          NOT NULL,
    `student_id`     INT          NOT NULL,
    `org_id`         INT          NOT NULL,
    `status`         ENUM('pending','submitted','late','missing') NOT NULL DEFAULT 'pending',
    `marks_obtained` DECIMAL(6,2)          DEFAULT NULL,
    `feedback`       TEXT,
    `submitted_at`   DATETIME              DEFAULT NULL,
    `marked_at`      DATETIME              DEFAULT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One submission record per student per homework (upsertable via ON DUPLICATE KEY)
    UNIQUE KEY `uk_hw_student`   (`homework_id`, `student_id`),
    KEY `idx_homework`   (`homework_id`),
    KEY `idx_student`    (`student_id`),
    KEY `idx_org`        (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sch_students: add emergency_phone column if not present
-- (used by attendance.php SMS parent notification fallback)
ALTER TABLE `sch_students`
    ADD COLUMN IF NOT EXISTS `emergency_phone` VARCHAR(20) DEFAULT NULL AFTER `emergency_contact`;

-- Confirmation
SELECT 'school_homework_promotion_migration applied successfully.' AS migration_status;
