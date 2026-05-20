-- Notifications System Migration
-- Run once against shanfix_db

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
