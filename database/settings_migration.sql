-- ============================================================
--  SHANFIX WORKSPACE — System Settings Migration
--  Run once: adds system_settings key/value store
-- ============================================================

CREATE TABLE IF NOT EXISTS system_settings (
    `key`       VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`     TEXT,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default values (safe to re-run — INSERT IGNORE)
INSERT IGNORE INTO system_settings (`key`, `value`) VALUES
  ('app_name',           'OrbitDesk Workspace'),
  ('support_email',      'support@orbitdesk.co.ke'),
  ('default_currency',   'KES'),
  ('default_timezone',   'Africa/Nairobi'),
  ('trial_days',         '14'),
  ('max_users',          '5'),
  ('smtp_host',          ''),
  ('smtp_port',          '587'),
  ('smtp_user',          ''),
  ('smtp_pass',          ''),
  ('smtp_enc',           'tls'),
  ('mail_from',          'noreply@orbitdesk.co.ke'),
  ('mail_from_name',     'OrbitDesk Workspace'),
  ('mpesa_consumer_key', ''),
  ('mpesa_consumer_secret',''),
  ('mpesa_shortcode',    ''),
  ('mpesa_passkey',      ''),
  ('mpesa_env',          'sandbox'),
  ('session_timeout',    '8'),
  ('max_login_attempts', '5');
