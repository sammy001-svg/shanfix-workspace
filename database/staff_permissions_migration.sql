-- ── Staff Permissions Migration ────────────────────────────────
-- Adds per-user module access control for staff members.
-- client_admin role retains full access to all org modules.
-- staff role only accesses modules explicitly granted here.
-- Run this AFTER schema.sql

-- Also ensures users table has security columns (idempotent)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_logins  INT            DEFAULT 0    AFTER status,
    ADD COLUMN IF NOT EXISTS locked_until   TIMESTAMP NULL DEFAULT NULL  AFTER failed_logins,
    ADD COLUMN IF NOT EXISTS totp_enabled   TINYINT(1)     DEFAULT 0    AFTER locked_until,
    ADD COLUMN IF NOT EXISTS totp_secret    VARCHAR(64)    DEFAULT NULL  AFTER totp_enabled;

-- Per-staff module access grants
CREATE TABLE IF NOT EXISTS user_module_access (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         NOT NULL,
    org_id      INT         NOT NULL,
    module_slug VARCHAR(50) NOT NULL,
    granted_by  INT         NULL COMMENT 'user_id of the client_admin who granted access',
    created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_module (user_id, module_slug),
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (org_id)     REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
