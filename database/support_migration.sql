-- ── Support Tickets ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  org_id          INT NOT NULL,
  user_id         INT NOT NULL,
  ticket_number   VARCHAR(20) NOT NULL UNIQUE,
  subject         VARCHAR(255) NOT NULL,
  category        ENUM('billing','technical','general','feature_request','module_request') DEFAULT 'general',
  priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status          ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
  message         TEXT NOT NULL,
  admin_id        INT DEFAULT NULL,
  rating          TINYINT DEFAULT NULL,
  closed_at       DATETIME DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_org    (org_id),
  INDEX idx_status (status),
  INDEX idx_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Ticket Replies ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ticket_replies (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT NOT NULL,
  user_id     INT NOT NULL,
  is_admin    TINYINT DEFAULT 0,
  message     TEXT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
