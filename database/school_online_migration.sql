-- ============================================================
--  School Module — Online Classes & Online Exams Migration
--  File: school_online_migration.sql
--  Safe to re-run: all statements use IF NOT EXISTS
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 1. sch_online_classes — virtual class sessions
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_classes` (
    `id`            INT            NOT NULL AUTO_INCREMENT,
    `org_id`        INT            NOT NULL,
    `class_id`      INT                     DEFAULT NULL,
    `subject_id`    INT                     DEFAULT NULL,
    `teacher_id`    INT                     DEFAULT NULL,
    `title`         VARCHAR(255)   NOT NULL,
    `description`   TEXT                    DEFAULT NULL,
    `platform`      ENUM('zoom','meet','teams','webex','other') NOT NULL DEFAULT 'meet',
    `meeting_url`   VARCHAR(500)            DEFAULT NULL,
    `meeting_id`    VARCHAR(100)            DEFAULT NULL,
    `meeting_pass`  VARCHAR(100)            DEFAULT NULL,
    `scheduled_at`  DATETIME       NOT NULL,
    `duration_mins` INT            NOT NULL DEFAULT 60,
    `recorded_url`  VARCHAR(500)            DEFAULT NULL,
    `status`        ENUM('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `notes`         TEXT                    DEFAULT NULL,
    `created_by`    INT                     DEFAULT NULL,
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_olc_org`     (`org_id`),
    KEY `idx_olc_class`   (`class_id`),
    KEY `idx_olc_teacher` (`teacher_id`),
    KEY `idx_olc_date`    (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 2. sch_online_exams — online exam definitions
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_exams` (
    `id`                       INT            NOT NULL AUTO_INCREMENT,
    `org_id`                   INT            NOT NULL,
    `title`                    VARCHAR(255)   NOT NULL,
    `description`              TEXT                    DEFAULT NULL,
    `instructions`             TEXT                    DEFAULT NULL,
    `class_id`                 INT                     DEFAULT NULL,
    `subject_id`               INT                     DEFAULT NULL,
    `teacher_id`               INT                     DEFAULT NULL,
    `exam_id`                  INT                     DEFAULT NULL COMMENT 'Optional link to sch_exams',
    `start_datetime`           DATETIME                DEFAULT NULL,
    `end_datetime`             DATETIME                DEFAULT NULL,
    `duration_mins`            INT            NOT NULL DEFAULT 60,
    `total_marks`              DECIMAL(10,2)  NOT NULL DEFAULT 100,
    `pass_marks`               DECIMAL(10,2)           DEFAULT NULL,
    `shuffle_questions`        TINYINT(1)     NOT NULL DEFAULT 0,
    `show_results_immediately` TINYINT(1)     NOT NULL DEFAULT 1,
    `allow_review`             TINYINT(1)     NOT NULL DEFAULT 1,
    `max_attempts`             INT            NOT NULL DEFAULT 1,
    `status`                   ENUM('draft','published','active','closed') NOT NULL DEFAULT 'draft',
    `created_by`               INT                     DEFAULT NULL,
    `created_at`               TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oe_org`   (`org_id`),
    KEY `idx_oe_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 3. sch_online_exam_questions — questions per exam
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_exam_questions` (
    `id`            INT            NOT NULL AUTO_INCREMENT,
    `exam_id`       INT            NOT NULL,
    `org_id`        INT            NOT NULL,
    `question_text` TEXT           NOT NULL,
    `question_type` ENUM('mcq','true_false','short_answer') NOT NULL DEFAULT 'mcq',
    `marks`         DECIMAL(8,2)   NOT NULL DEFAULT 1,
    `sort_order`    INT            NOT NULL DEFAULT 0,
    `explanation`   TEXT                    DEFAULT NULL,
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oeq_exam`   (`exam_id`),
    KEY `idx_oeq_order`  (`exam_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 4. sch_online_exam_options — MCQ / True-False options
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_exam_options` (
    `id`           INT            NOT NULL AUTO_INCREMENT,
    `question_id`  INT            NOT NULL,
    `option_text`  TEXT           NOT NULL,
    `is_correct`   TINYINT(1)     NOT NULL DEFAULT 0,
    `sort_order`   INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_oeo_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 5. sch_online_exam_attempts — one row per student per exam
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_exam_attempts` (
    `id`              INT            NOT NULL AUTO_INCREMENT,
    `exam_id`         INT            NOT NULL,
    `student_id`      INT            NOT NULL,
    `org_id`          INT            NOT NULL,
    `started_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `submitted_at`    DATETIME                DEFAULT NULL,
    `time_taken_mins` INT                     DEFAULT NULL,
    `score`           DECIMAL(10,2)           DEFAULT NULL,
    `max_score`       DECIMAL(10,2)           DEFAULT NULL,
    `percentage`      DECIMAL(5,2)            DEFAULT NULL,
    `passed`          TINYINT(1)              DEFAULT NULL,
    `status`          ENUM('in_progress','submitted','graded','timed_out') NOT NULL DEFAULT 'in_progress',
    `graded_by`       INT                     DEFAULT NULL,
    `graded_at`       DATETIME                DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attempt` (`exam_id`, `student_id`),
    KEY `idx_oea_exam`    (`exam_id`),
    KEY `idx_oea_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 6. sch_online_exam_answers — student answer per question
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_online_exam_answers` (
    `id`                 INT            NOT NULL AUTO_INCREMENT,
    `attempt_id`         INT            NOT NULL,
    `question_id`        INT            NOT NULL,
    `selected_option_id` INT                     DEFAULT NULL,
    `text_answer`        TEXT                    DEFAULT NULL,
    `is_correct`         TINYINT(1)              DEFAULT NULL,
    `marks_awarded`      DECIMAL(8,2)            DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_answer` (`attempt_id`, `question_id`),
    KEY `idx_oeans_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
