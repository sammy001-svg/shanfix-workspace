-- ============================================================
-- School Management — Teacher Portal Migration
-- Adds authentication columns to sch_teachers
-- Run after school_intl_migration.sql
-- ============================================================

ALTER TABLE `sch_teachers`
  ADD COLUMN IF NOT EXISTS `password_hash` VARCHAR(255) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `last_login`    DATETIME    DEFAULT NULL AFTER `password_hash`,
  ADD COLUMN IF NOT EXISTS `portal_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `last_login`;

-- Index for email-based login lookups
ALTER TABLE `sch_teachers`
  ADD INDEX IF NOT EXISTS `idx_sch_teachers_email` (`email`);

-- Done
SELECT 'Teacher portal migration complete.' AS status;
