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
  ('max_login_attempts', '5'),
  -- Company / branding
  ('company_address',    'P.O. Box 00100, Nairobi, Kenya'),
  ('company_website',    ''),
  -- Invoice settings
  ('invoice_prefix',     'INV'),
  ('invoice_tax_rate',   '16'),
  ('invoice_footer',     'Thank you for your business. Please pay within the due date to avoid service interruption.'),
  ('invoice_notes',      'Payment is due within 30 days of invoice date.'),
  -- Payment details (shown on invoices)
  ('mpesa_paybill',      ''),
  ('mpesa_account_ref',  'Invoice Number'),
  ('bank_name',          ''),
  ('bank_account',       ''),
  ('bank_branch',        '');
