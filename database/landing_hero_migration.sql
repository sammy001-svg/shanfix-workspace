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
