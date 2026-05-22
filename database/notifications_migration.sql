-- Notifications System Migration
-- Run once against shanfix_db
-- Updated: OrbitDesk Workspace v2 — adds notification_preferences table

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS checkout_id VARCHAR(100) DEFAULT NULL AFTER status;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS mpesa_receipt VARCHAR(100) DEFAULT NULL AFTER checkout_id;

CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  org_id     INT NOT NULL,
  user_id    INT,
  title      VARCHAR(255) NOT NULL,
  message    TEXT,
  type       ENUM('info','success','warning','danger') DEFAULT 'info',
  link       VARCHAR(500),
  is_read    TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_read (user_id, is_read),
  INDEX idx_org (org_id)
) ENGINE=InnoDB;

-- User notification preferences (email + in-app toggles per type)
CREATE TABLE IF NOT EXISTS notification_preferences (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  org_id         INT NOT NULL,
  email_info     TINYINT DEFAULT 1,
  email_success  TINYINT DEFAULT 1,
  email_warning  TINYINT DEFAULT 1,
  email_danger   TINYINT DEFAULT 1,
  inapp_info     TINYINT DEFAULT 1,
  inapp_success  TINYINT DEFAULT 1,
  inapp_warning  TINYINT DEFAULT 1,
  inapp_danger   TINYINT DEFAULT 1,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user (user_id),
  INDEX idx_org (org_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mpesa_pending (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  org_id      INT NOT NULL,
  invoice_id  INT,
  checkout_id VARCHAR(100) NOT NULL UNIQUE,
  amount      DECIMAL(12,2),
  phone       VARCHAR(20),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_checkout (checkout_id)
) ENGINE=InnoDB;
