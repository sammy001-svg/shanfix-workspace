-- ── School Parent Portal Migration ─────────────────────────────────────────
-- Compatible with MySQL 5.7+, MariaDB 10.x, and cPanel shared hosting.
-- Run ONCE after the base school schema has been applied.
-- If you get "Duplicate column name" it means the migration already ran — that is OK.

ALTER TABLE sch_parents
  ADD COLUMN parent_pin     VARCHAR(255) NULL DEFAULT NULL COMMENT 'bcrypt-hashed portal PIN',
  ADD COLUMN portal_enabled TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = portal access granted by admin';

ALTER TABLE sch_parents
  ADD INDEX idx_portal (portal_enabled);
