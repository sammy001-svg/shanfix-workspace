-- ================================================================
-- OrbitDesk Workspace — Security Layer Migration
-- Run once against shanfix_db
-- ================================================================

-- ── Extend users table ──────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS failed_logins      INT       DEFAULT 0      AFTER last_login,
  ADD COLUMN IF NOT EXISTS locked_until       DATETIME  DEFAULT NULL   AFTER failed_logins,
  ADD COLUMN IF NOT EXISTS totp_secret        VARCHAR(32) DEFAULT NULL AFTER locked_until,
  ADD COLUMN IF NOT EXISTS totp_enabled       TINYINT   DEFAULT 0      AFTER totp_secret,
  ADD COLUMN IF NOT EXISTS last_password_change DATE    DEFAULT NULL   AFTER totp_enabled,
  ADD COLUMN IF NOT EXISTS force_password_change TINYINT DEFAULT 0    AFTER last_password_change,
  ADD COLUMN IF NOT EXISTS phone              VARCHAR(20) DEFAULT NULL;

-- ── Login attempts log ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(255) NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  user_agent  VARCHAR(500),
  success     TINYINT      DEFAULT 0,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_time (email, created_at),
  INDEX idx_ip_time    (ip, created_at)
) ENGINE=InnoDB;

-- ── Password reset tokens ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(255) NOT NULL,
  token       VARCHAR(64)  NOT NULL UNIQUE,
  expires_at  DATETIME     NOT NULL,
  used        TINYINT      DEFAULT 0,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token (token),
  INDEX idx_email (email)
) ENGINE=InnoDB;

-- ── Cleanup old login attempts (older than 30 days) automatically ──
-- (Optionally set up an EVENT or rely on cron/cleanup.php)
