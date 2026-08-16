-- ═══════════════════════════════════════════════════════════════
-- Landing page hero carousel
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + INSERT IGNORE against
-- a UNIQUE image_path, so re-importing never duplicates slides.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS landing_hero_slides (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    image_path  VARCHAR(255) NOT NULL,              -- relative to project root
    alt_text    VARCHAR(200) NOT NULL DEFAULT '',   -- accessibility / SEO
    caption     VARCHAR(160) NOT NULL DEFAULT '',   -- optional overlay caption
    sort_order  INT          NOT NULL DEFAULT 0,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_image_path (image_path),
    INDEX idx_status_order (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Carousel behaviour settings (super-admin editable) ──────────
INSERT IGNORE INTO system_settings (`key`, `value`) VALUES
  ('hero_carousel_enabled',  '1'),
  ('hero_carousel_interval', '6000'),   -- ms between slides (2000-15000)
  ('hero_carousel_effect',   'fade'),   -- fade | slide
  ('hero_overlay_opacity',   '86');     -- 40-98, darkness of the flat navy wash

-- ── Seed slides ────────────────────────────────────────────────
-- Free Pexels stock photos (Pexels License: commercial use, no attribution
-- required). Replace them any time from Admin → Landing Page.
INSERT IGNORE INTO landing_hero_slides (image_path, alt_text, caption, sort_order, status) VALUES
  ('assets/uploads/hero/hero-team-collaboration.jpg', 'Team collaborating around a laptop', '', 1, 'active'),
  ('assets/uploads/hero/hero-business-meeting.jpg',   'Business meeting in progress',       '', 2, 'active'),
  ('assets/uploads/hero/hero-workspace-laptop.jpg',   'Professional working at a laptop',   '', 3, 'active'),
  ('assets/uploads/hero/hero-office-discussion.jpg',  'Colleagues discussing work',         '', 4, 'active'),
  ('assets/uploads/hero/hero-retail-counter.jpg',     'Retail point-of-sale counter',       '', 5, 'active'),
  ('assets/uploads/hero/hero-planning-board.jpg',     'Planning session with charts',       '', 6, 'active');


-- ═══════════════════════════════════════════════════════════════
-- Per-slide marketing copy + call-to-action buttons
-- ═══════════════════════════════════════════════════════════════

-- Helper: safe ADD COLUMN. MySQL 5.7 has no "ADD COLUMN IF NOT EXISTS",
-- so a short-lived stored procedure keeps this re-runnable.
DROP PROCEDURE IF EXISTS _hero_add_col;

DELIMITER $$
CREATE PROCEDURE _hero_add_col(IN p_table VARCHAR(128),
                               IN p_col   VARCHAR(128),
                               IN p_def   TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM   information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = p_table
          AND  COLUMN_NAME  = p_col
    ) THEN
        SET @_s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE _st FROM @_s;
        EXECUTE _st;
        DEALLOCATE PREPARE _st;
    END IF;
END $$
DELIMITER ;

CALL _hero_add_col('landing_hero_slides', 'eyebrow',     "VARCHAR(80)  NOT NULL DEFAULT '' AFTER caption");
CALL _hero_add_col('landing_hero_slides', 'headline',    "VARCHAR(160) NOT NULL DEFAULT '' AFTER eyebrow");
CALL _hero_add_col('landing_hero_slides', 'subheadline', "VARCHAR(320) NOT NULL DEFAULT '' AFTER headline");
CALL _hero_add_col('landing_hero_slides', 'cta1_label',  "VARCHAR(60)  NOT NULL DEFAULT '' AFTER subheadline");
CALL _hero_add_col('landing_hero_slides', 'cta1_url',    "VARCHAR(255) NOT NULL DEFAULT '' AFTER cta1_label");
CALL _hero_add_col('landing_hero_slides', 'cta2_label',  "VARCHAR(60)  NOT NULL DEFAULT '' AFTER cta1_url");
CALL _hero_add_col('landing_hero_slides', 'cta2_url',    "VARCHAR(255) NOT NULL DEFAULT '' AFTER cta2_label");

DROP PROCEDURE IF EXISTS _hero_add_col;


-- ── Seed marketing copy ────────────────────────────────────────
-- Only fills slides whose headline is still blank, so this never
-- overwrites copy edited in Admin → Landing Page.
UPDATE landing_hero_slides SET
  eyebrow     = "Kenya's #1 Business Management Suite",
  headline    = 'One Platform. 20+ Business Solutions.',
  subheadline = 'OrbitDesk Workspace centralises every part of your business — accounting, HR, POS, hotel, school, SACCO and more — in one cloud platform.',
  cta1_label  = 'Start Free Trial', cta1_url = '/auth/register.php',
  cta2_label  = 'Browse Modules',   cta2_url = '#modules'
WHERE image_path = 'assets/uploads/hero/hero-team-collaboration.jpg' AND headline = '';

UPDATE landing_hero_slides SET
  eyebrow     = 'CRM & Sales',
  headline    = 'Turn Every Lead Into Revenue.',
  subheadline = 'Track leads, quotes and deals in one pipeline — with follow-ups your team actually completes.',
  cta1_label  = 'Explore CRM',  cta1_url = '#modules',
  cta2_label  = 'See Pricing',  cta2_url = '#pricing'
WHERE image_path = 'assets/uploads/hero/hero-business-meeting.jpg' AND headline = '';

UPDATE landing_hero_slides SET
  eyebrow     = 'Accounting & Finance',
  headline    = 'Books That Balance Themselves.',
  subheadline = 'Invoices, expenses, VAT and reconciliation — with M-Pesa payments landing straight in your ledger.',
  cta1_label  = 'Start Free Trial', cta1_url = '/auth/register.php',
  cta2_label  = 'View Modules',     cta2_url = '#modules'
WHERE image_path = 'assets/uploads/hero/hero-workspace-laptop.jpg' AND headline = '';

UPDATE landing_hero_slides SET
  eyebrow     = 'HR & Payroll',
  headline    = 'Payroll Done in Minutes, Not Days.',
  subheadline = 'Employees, leave, attendance and payslips — PAYE and NSSF handled correctly, every month.',
  cta1_label  = 'Start Free Trial', cta1_url = '/auth/register.php',
  cta2_label  = 'How It Works',     cta2_url = '#how'
WHERE image_path = 'assets/uploads/hero/hero-office-discussion.jpg' AND headline = '';

UPDATE landing_hero_slides SET
  eyebrow     = 'Point of Sale',
  headline    = 'Sell Faster at Every Counter.',
  subheadline = 'Barcode checkout, live stock levels, shift reports and M-Pesa — on the shop floor or online.',
  cta1_label  = 'Try POS Free', cta1_url = '/auth/register.php',
  cta2_label  = 'See Pricing',  cta2_url = '#pricing'
WHERE image_path = 'assets/uploads/hero/hero-retail-counter.jpg' AND headline = '';

UPDATE landing_hero_slides SET
  eyebrow     = 'Insights & Reporting',
  headline    = 'Decisions Backed by Real Numbers.',
  subheadline = 'Dashboards across every module, so you know what is working long before the month closes.',
  cta1_label  = 'Get Started Free', cta1_url = '/auth/register.php',
  cta2_label  = 'Talk to Us',       cta2_url = '#contact'
WHERE image_path = 'assets/uploads/hero/hero-planning-board.jpg' AND headline = '';
