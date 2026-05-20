-- ============================================================
--  SHANFIX WORKSPACE — Phase 2A Migration
--  Adds 8 new tables to complete Caryard, Events, Meetings, Tour
-- ============================================================

-- ── CARYARD: Service / Maintenance Records ────────────────────
CREATE TABLE IF NOT EXISTS caryard_services (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    vehicle_id   INT NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    description  TEXT,
    cost         DECIMAL(12,2) DEFAULT 0.00,
    service_date DATE,
    technician   VARCHAR(255),
    status       ENUM('pending','in_progress','completed') DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    FOREIGN KEY (vehicle_id) REFERENCES caryard_vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── CARYARD: Customer Inquiries / Leads ──────────────────────
CREATE TABLE IF NOT EXISTS caryard_inquiries (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    org_id         INT NOT NULL,
    vehicle_id     INT,
    customer_name  VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(25),
    customer_email VARCHAR(255),
    inquiry_date   DATE,
    budget         DECIMAL(12,2),
    notes          TEXT,
    source         VARCHAR(100),
    status         ENUM('new','contacted','qualified','closed') DEFAULT 'new',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB;

-- ── EVENTS: Session / Agenda ──────────────────────────────────
CREATE TABLE IF NOT EXISTS event_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    event_id    INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    speaker     VARCHAR(255),
    location    VARCHAR(255),
    start_time  DATETIME,
    end_time    DATETIME,
    sort_order  INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── EVENTS: Budget / Expenses ─────────────────────────────────
CREATE TABLE IF NOT EXISTS event_budget (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    event_id         INT NOT NULL,
    category         VARCHAR(100),
    description      VARCHAR(255) NOT NULL,
    type             ENUM('income','expense') DEFAULT 'expense',
    estimated_amount DECIMAL(12,2) DEFAULT 0.00,
    actual_amount    DECIMAL(12,2) DEFAULT 0.00,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── MEETINGS: Action Items ────────────────────────────────────
CREATE TABLE IF NOT EXISTS meeting_action_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    meeting_id  INT NOT NULL,
    description TEXT NOT NULL,
    assigned_to VARCHAR(255),
    due_date    DATE,
    priority    ENUM('low','medium','high') DEFAULT 'medium',
    status      ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meeting (meeting_id),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── MEETINGS: External Contacts / Participants ────────────────
CREATE TABLE IF NOT EXISTS meeting_contacts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    name         VARCHAR(255) NOT NULL,
    email        VARCHAR(255),
    phone        VARCHAR(25),
    organization VARCHAR(255),
    role         VARCHAR(100),
    notes        TEXT,
    status       ENUM('active','inactive') DEFAULT 'active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── TOUR: Tour Guides ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_guides (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    name             VARCHAR(255) NOT NULL,
    phone            VARCHAR(25),
    email            VARCHAR(255),
    id_number        VARCHAR(50),
    specialization   VARCHAR(255),
    languages        VARCHAR(255),
    experience_years INT DEFAULT 0,
    daily_rate       DECIMAL(10,2) DEFAULT 0.00,
    status           ENUM('active','inactive','on_assignment') DEFAULT 'active',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── TOUR: Customer Profiles ───────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_customers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255) NOT NULL,
    phone       VARCHAR(25),
    email       VARCHAR(255),
    id_number   VARCHAR(50),
    nationality VARCHAR(100),
    address     TEXT,
    notes       TEXT,
    total_trips INT DEFAULT 0,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
