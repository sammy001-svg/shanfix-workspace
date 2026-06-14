-- ============================================================
-- School Management — Student Portal Migration
-- Adds authentication columns to sch_students
-- Run after schema.sql
-- ============================================================

ALTER TABLE `sch_students`
  ADD COLUMN IF NOT EXISTS `password_hash`   VARCHAR(255) DEFAULT NULL  AFTER `photo`,
  ADD COLUMN IF NOT EXISTS `portal_enabled`  TINYINT(1)   NOT NULL DEFAULT 0 AFTER `password_hash`,
  ADD COLUMN IF NOT EXISTS `last_login`      DATETIME     DEFAULT NULL  AFTER `portal_enabled`;

-- Index for admission-number login lookups
ALTER TABLE `sch_students`
  ADD INDEX IF NOT EXISTS `idx_sch_students_admission` (`admission_no`, `org_id`);

SELECT 'Student portal migration complete.' AS status;
