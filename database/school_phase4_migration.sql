-- ============================================================
-- School Management Phase 4 Migration
-- Parent Portal + Report Card + Dashboard Enhancements
-- Run after school_module_migration.sql and school_phase2_migration.sql
-- All statements use IF NOT EXISTS for safety.
-- ============================================================

-- 1. sch_results: add teacher feedback and curriculum columns
ALTER TABLE `sch_results`
  ADD COLUMN IF NOT EXISTS `teacher_comment`  TEXT         DEFAULT NULL AFTER `remarks`,
  ADD COLUMN IF NOT EXISTS `predicted_grade`  VARCHAR(10)  DEFAULT NULL AFTER `teacher_comment`,
  ADD COLUMN IF NOT EXISTS `curriculum`       VARCHAR(50)  DEFAULT NULL AFTER `org_id`;

-- 2. sch_classes: ensure curriculum column exists
ALTER TABLE `sch_classes`
  ADD COLUMN IF NOT EXISTS `curriculum` VARCHAR(50) NOT NULL DEFAULT 'General' AFTER `org_id`;

-- 3. sch_students: ensure curriculum column exists
ALTER TABLE `sch_students`
  ADD COLUMN IF NOT EXISTS `curriculum` VARCHAR(50) DEFAULT NULL;

-- 4. sch_notices: ensure is_pinned exists (may be missing on older installs)
ALTER TABLE `sch_notices`
  ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) NOT NULL DEFAULT 0;

-- 5. sch_attendance: ensure att_date index for parent portal queries
ALTER TABLE `sch_attendance`
  ADD INDEX IF NOT EXISTS `idx_sch_att_org_date` (`org_id`, `att_date`);

-- 6. sch_results: ensure composite index for class-position queries
ALTER TABLE `sch_results`
  ADD INDEX IF NOT EXISTS `idx_sch_res_exam_class` (`exam_id`, `class_id`, `org_id`);

-- 7. sch_fees: ensure currency column exists (used in M-Pesa pay flow)
ALTER TABLE `sch_fees`
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(5) NOT NULL DEFAULT 'KES' AFTER `balance`;

-- 8. sch_fee_payments: ensure payment_date and receipt_no columns exist
ALTER TABLE `sch_fee_payments`
  ADD COLUMN IF NOT EXISTS `payment_date` DATE          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `receipt_no`   VARCHAR(50)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) DEFAULT NULL;

-- Done
SELECT 'Phase 4 migration complete.' AS status;
