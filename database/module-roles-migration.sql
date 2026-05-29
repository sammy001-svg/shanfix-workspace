-- ============================================================
-- OrbitDesk — Module-Level RBAC Migration
-- Per-module role assignments for staff users
-- Run ONCE against shanfix_db
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── user_module_roles ─────────────────────────────────────────
-- Stores which role a staff user has within each module.
-- One row per user per module. Combined with user_module_access
-- to provide full per-module permission control.
CREATE TABLE IF NOT EXISTS user_module_roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    org_id          INT UNSIGNED NOT NULL,
    module_slug     VARCHAR(100) NOT NULL,
    role_key        VARCHAR(100) NOT NULL,
    granted_by      INT UNSIGNED,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module_slug),
    INDEX idx_org   (org_id),
    INDEX idx_slug  (module_slug),
    INDEX idx_role  (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-module role assignments for staff users';

-- ── Add module_role column to user_module_access (optional) ──
ALTER TABLE user_module_access
    ADD COLUMN IF NOT EXISTS module_role VARCHAR(100) DEFAULT NULL
        COMMENT 'Mirrors user_module_roles.role_key for quick joins';

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Module roles migration complete.' AS status;
