-- =========================================================
-- School Module Phase 2: Communication, Subject-Teachers, Alumni
-- Migration: school_communication_alumni_migration.sql
-- Created: 2026-06-13
-- =========================================================

-- School Communications Log
-- Tracks all mass circulars and announcements sent to parents/staff
CREATE TABLE IF NOT EXISTS `sch_communications` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `org_id`            INT          NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `message`           TEXT         NOT NULL,
    `recipients_type`   ENUM('all_parents','class_parents','all_teachers','all_staff','custom')
                                     NOT NULL DEFAULT 'all_parents',
    `class_id`          INT                   DEFAULT NULL,
    `channel`           ENUM('sms','notice','both') NOT NULL DEFAULT 'both',
    `status`            ENUM('draft','sent','scheduled') NOT NULL DEFAULT 'sent',
    `total_recipients`  INT          NOT NULL DEFAULT 0,
    `sent_count`        INT          NOT NULL DEFAULT 0,
    `created_by`        INT                   DEFAULT NULL,
    `sent_at`           DATETIME              DEFAULT NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_org`     (`org_id`),
    KEY `idx_sent_at` (`sent_at`),
    KEY `idx_class`   (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject-Teacher-Class Assignment Matrix
-- Links which teacher teaches which subject in which class (per academic year)
CREATE TABLE IF NOT EXISTS `sch_subject_teachers` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `org_id`           INT NOT NULL,
    `class_id`         INT NOT NULL,
    `subject_id`       INT NOT NULL,
    `teacher_id`       INT          DEFAULT NULL,
    `academic_year_id` INT          DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_class_subject_year` (`org_id`, `class_id`, `subject_id`, `academic_year_id`),
    KEY `idx_org`     (`org_id`),
    KEY `idx_class`   (`class_id`),
    KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alumni extended fields on sch_students
ALTER TABLE `sch_students`
    ADD COLUMN IF NOT EXISTS `graduation_year`  YEAR         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `current_city`     VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `current_employer` VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `alumni_notes`     TEXT         DEFAULT NULL;

-- sch_parents: primary contact flag (used by attendance SMS notifications)
ALTER TABLE `sch_parents`
    ADD COLUMN IF NOT EXISTS `is_primary` TINYINT(1) NOT NULL DEFAULT 0;

-- Confirmation
SELECT 'school_communication_alumni_migration applied successfully.' AS migration_status;
