-- ============================================================
--  School Module — Homework Questions & Exam Grading Migration
--  File: school_grading_migration.sql
--  Safe to re-run: all statements use IF NOT EXISTS / IF EXISTS
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 1. sch_homework_questions — per-homework questions with marks
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_homework_questions` (
    `id`            INT            NOT NULL AUTO_INCREMENT,
    `homework_id`   INT            NOT NULL,
    `org_id`        INT            NOT NULL,
    `question_text` TEXT           NOT NULL,
    `marks`         DECIMAL(8,2)   NOT NULL DEFAULT 1,
    `sort_order`    INT            NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hwq_homework` (`homework_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 2. sch_homework_answers — student answers + teacher marks per question
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_homework_answers` (
    `id`            INT            NOT NULL AUTO_INCREMENT,
    `homework_id`   INT            NOT NULL,
    `question_id`   INT            NOT NULL,
    `student_id`    INT            NOT NULL,
    `org_id`        INT            NOT NULL,
    `answer_text`   TEXT                    DEFAULT NULL,
    `marks_awarded` DECIMAL(8,2)            DEFAULT NULL,
    `feedback`      TEXT                    DEFAULT NULL,
    `marked_by`     INT                     DEFAULT NULL,
    `marked_at`     DATETIME                DEFAULT NULL,
    `submitted_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hw_ans` (`question_id`, `student_id`),
    KEY `idx_hwa_homework` (`homework_id`),
    KEY `idx_hwa_student`  (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 3. sch_online_exam_answers — add teacher feedback column
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_online_exam_answers`
    ADD COLUMN IF NOT EXISTS `feedback`    TEXT     DEFAULT NULL AFTER `marks_awarded`,
    ADD COLUMN IF NOT EXISTS `marked_by`   INT      DEFAULT NULL AFTER `feedback`,
    ADD COLUMN IF NOT EXISTS `marked_at`   DATETIME DEFAULT NULL AFTER `marked_by`;

-- ────────────────────────────────────────────────────────────
-- 4. sch_homework_submissions — ensure marked_by column exists
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_homework_submissions`
    ADD COLUMN IF NOT EXISTS `marked_by`  INT      DEFAULT NULL AFTER `marked_at`,
    ADD COLUMN IF NOT EXISTS `feedback`   TEXT     DEFAULT NULL AFTER `marked_by`;

SET FOREIGN_KEY_CHECKS = 1;
