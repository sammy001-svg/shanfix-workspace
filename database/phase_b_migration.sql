-- ══════════════════════════════════════════════════════════════════
-- OrbitDesk — Phase B Migration
-- Adds new tables for Hotel, HRM, Driving, Events, Meetings, Tour
-- Also patches courier_routes column names to match PHP
-- Run once. Safe — all use IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
-- ══════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────
-- PATCH: courier_routes — add columns our PHP code expects
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE courier_routes
    ADD COLUMN IF NOT EXISTS route_name  VARCHAR(150) DEFAULT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS origin      VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS destination VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS waypoints   TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agent_id    INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS frequency   VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS base_fare   DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS estimated_km DECIMAL(8,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS notes       TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at  DATETIME DEFAULT NULL;

-- courier_tracking_events (used by delivery.php status updates)
CREATE TABLE IF NOT EXISTS courier_tracking_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    courier_id  INT NOT NULL,
    status      VARCHAR(60) NOT NULL,
    note        TEXT DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_courier (courier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HOTEL — Housekeeping
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hotel_housekeeping (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    room_id       INT NOT NULL,
    task_date     DATE NOT NULL,
    task_type     ENUM('daily_clean','deep_clean','turndown','inspection','maintenance') DEFAULT 'daily_clean',
    assigned_to   VARCHAR(150) DEFAULT NULL,
    priority      ENUM('low','normal','high','urgent') DEFAULT 'normal',
    status        ENUM('pending','in_progress','done','skipped') DEFAULT 'pending',
    notes         TEXT DEFAULT NULL,
    completed_at  DATETIME DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL,
    INDEX idx_org_date (org_id, task_date),
    INDEX idx_room (room_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HOTEL — Restaurant / In-room Dining Orders
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hotel_restaurant_orders (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    order_no      VARCHAR(30) NOT NULL,
    room_id       INT DEFAULT NULL COMMENT 'NULL = walk-in / dine-in',
    guest_id      INT DEFAULT NULL,
    order_type    ENUM('in_room','dine_in','takeaway') DEFAULT 'dine_in',
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount    DECIMAL(10,2) DEFAULT 0,
    discount      DECIMAL(10,2) DEFAULT 0,
    grand_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_mode  ENUM('cash','room_charge','mpesa','card') DEFAULT 'cash',
    status        ENUM('pending','preparing','served','paid','cancelled') DEFAULT 'pending',
    notes         TEXT DEFAULT NULL,
    ordered_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    served_at     DATETIME DEFAULT NULL,
    updated_at    DATETIME DEFAULT NULL,
    INDEX idx_org_date (org_id, ordered_at),
    INDEX idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_restaurant_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT NOT NULL,
    item_name     VARCHAR(200) NOT NULL,
    qty           INT NOT NULL DEFAULT 1,
    unit_price    DECIMAL(10,2) NOT NULL DEFAULT 0,
    total         DECIMAL(12,2) NOT NULL DEFAULT 0,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HOTEL — Guest Invoices
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hotel_invoices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    invoice_no    VARCHAR(30) NOT NULL,
    guest_id      INT NOT NULL,
    booking_id    INT DEFAULT NULL,
    room_charges  DECIMAL(12,2) DEFAULT 0,
    restaurant_charges DECIMAL(12,2) DEFAULT 0,
    service_charges    DECIMAL(12,2) DEFAULT 0,
    other_charges      DECIMAL(12,2) DEFAULT 0,
    tax_amount    DECIMAL(10,2) DEFAULT 0,
    discount      DECIMAL(10,2) DEFAULT 0,
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount   DECIMAL(12,2) DEFAULT 0,
    payment_mode  ENUM('cash','mpesa','card','bank_transfer','room_account') DEFAULT 'cash',
    status        ENUM('draft','issued','paid','partial','cancelled') DEFAULT 'draft',
    issued_date   DATE DEFAULT NULL,
    due_date      DATE DEFAULT NULL,
    notes         TEXT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_invoice (org_id, invoice_no),
    INDEX idx_org_guest (org_id, guest_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HRM — Job Openings & Recruitment
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_job_openings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    department_id   INT DEFAULT NULL,
    job_type        ENUM('full_time','part_time','contract','internship','volunteer') DEFAULT 'full_time',
    location        VARCHAR(150) DEFAULT NULL,
    vacancies       INT DEFAULT 1,
    salary_min      DECIMAL(12,2) DEFAULT NULL,
    salary_max      DECIMAL(12,2) DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    requirements    TEXT DEFAULT NULL,
    closing_date    DATE DEFAULT NULL,
    status          ENUM('open','closed','on_hold','filled') DEFAULT 'open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org (org_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hrm_applications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    opening_id      INT NOT NULL,
    applicant_name  VARCHAR(200) NOT NULL,
    email           VARCHAR(200) DEFAULT NULL,
    phone           VARCHAR(30) DEFAULT NULL,
    current_employer VARCHAR(200) DEFAULT NULL,
    experience_years INT DEFAULT 0,
    cover_note      TEXT DEFAULT NULL,
    cv_url          VARCHAR(500) DEFAULT NULL,
    stage           ENUM('applied','shortlisted','interview','offer','hired','rejected') DEFAULT 'applied',
    interview_date  DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    applied_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_opening (org_id, opening_id),
    INDEX idx_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HRM — Performance Reviews
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_performance_reviews (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    review_period   VARCHAR(30) NOT NULL COMMENT 'e.g. 2025-Q1, 2025-H1',
    reviewer_name   VARCHAR(150) DEFAULT NULL,
    kpi_score       DECIMAL(5,2) DEFAULT 0 COMMENT 'out of 100',
    attendance_score DECIMAL(5,2) DEFAULT 0,
    teamwork_score  DECIMAL(5,2) DEFAULT 0,
    initiative_score DECIMAL(5,2) DEFAULT 0,
    overall_score   DECIMAL(5,2) DEFAULT 0,
    rating          ENUM('exceptional','exceeds','meets','below','unsatisfactory') DEFAULT 'meets',
    strengths       TEXT DEFAULT NULL,
    improvements    TEXT DEFAULT NULL,
    goals_next      TEXT DEFAULT NULL,
    status          ENUM('draft','submitted','approved') DEFAULT 'draft',
    reviewed_at     DATE DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_employee (org_id, employee_id),
    INDEX idx_period (review_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- HRM — Training Sessions
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_training_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    category        VARCHAR(100) DEFAULT NULL,
    trainer         VARCHAR(200) DEFAULT NULL,
    training_type   ENUM('internal','external','online','workshop','conference') DEFAULT 'internal',
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    location        VARCHAR(200) DEFAULT NULL,
    max_participants INT DEFAULT NULL,
    cost            DECIMAL(12,2) DEFAULT 0,
    description     TEXT DEFAULT NULL,
    status          ENUM('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org (org_id),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hrm_training_attendance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT NOT NULL,
    employee_id     INT NOT NULL,
    org_id          INT NOT NULL,
    status          ENUM('enrolled','attended','absent','completed','dropped') DEFAULT 'enrolled',
    score           DECIMAL(5,2) DEFAULT NULL,
    certificate_no  VARCHAR(50) DEFAULT NULL,
    enrolled_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_enrollment (session_id, employee_id),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- DRIVING — Schedule / Timetable
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS driving_schedule (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    student_id      INT DEFAULT NULL,
    instructor_id   INT DEFAULT NULL,
    vehicle_id      INT DEFAULT NULL,
    session_type    ENUM('theory','practical','test') DEFAULT 'practical',
    session_date    DATE NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    location        VARCHAR(200) DEFAULT NULL,
    status          ENUM('scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_date (org_id, session_date),
    INDEX idx_instructor (instructor_id),
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- DRIVING — Payments / Fees
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS driving_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    receipt_no      VARCHAR(30) NOT NULL,
    student_id      INT NOT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_type    ENUM('registration','tuition','test_fee','license_fee','other') DEFAULT 'tuition',
    payment_mode    ENUM('cash','mpesa','bank','card') DEFAULT 'cash',
    reference       VARCHAR(100) DEFAULT NULL,
    payment_date    DATE NOT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_student (org_id, student_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- DRIVING — Certificates
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS driving_certificates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    certificate_no  VARCHAR(50) NOT NULL,
    student_id      INT NOT NULL,
    cert_type       ENUM('completion','test_pass','license_ready') DEFAULT 'completion',
    issue_date      DATE NOT NULL,
    expiry_date     DATE DEFAULT NULL,
    issued_by       VARCHAR(150) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('issued','revoked') DEFAULT 'issued',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cert (org_id, certificate_no),
    INDEX idx_org_student (org_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- EVENTS — Vendors
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS event_vendors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    event_id        INT DEFAULT NULL,
    vendor_name     VARCHAR(200) NOT NULL,
    category        VARCHAR(100) DEFAULT NULL,
    contact_person  VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(30) DEFAULT NULL,
    email           VARCHAR(200) DEFAULT NULL,
    service_desc    TEXT DEFAULT NULL,
    contract_amount DECIMAL(12,2) DEFAULT 0,
    paid_amount     DECIMAL(12,2) DEFAULT 0,
    payment_status  ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
    status          ENUM('confirmed','pending','cancelled') DEFAULT 'pending',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_event (org_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- EVENTS — Sponsors
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS event_sponsors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    event_id        INT DEFAULT NULL,
    sponsor_name    VARCHAR(200) NOT NULL,
    sponsor_type    ENUM('title','gold','silver','bronze','media','inkind','other') DEFAULT 'other',
    contact_person  VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(30) DEFAULT NULL,
    email           VARCHAR(200) DEFAULT NULL,
    pledge_amount   DECIMAL(12,2) DEFAULT 0,
    received_amount DECIMAL(12,2) DEFAULT 0,
    benefits        TEXT DEFAULT NULL,
    status          ENUM('prospect','confirmed','received','cancelled') DEFAULT 'prospect',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_event (org_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- EVENTS — Tasks / Checklist
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS event_tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    event_id        INT DEFAULT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    assigned_to     VARCHAR(150) DEFAULT NULL,
    due_date        DATE DEFAULT NULL,
    priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    status          ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending',
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org_event (org_id, event_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- MEETINGS — Agenda Items
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meeting_agenda_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    meeting_id      INT NOT NULL,
    sort_order      INT DEFAULT 0,
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    presenter       VARCHAR(150) DEFAULT NULL,
    duration_min    INT DEFAULT NULL,
    status          ENUM('pending','discussed','deferred','skipped') DEFAULT 'pending',
    outcome         TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_meeting (org_id, meeting_id),
    INDEX idx_sort (meeting_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- MEETINGS — Recordings
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meeting_recordings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    meeting_id      INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    platform        ENUM('zoom','teams','google_meet','webex','youtube','local','other') DEFAULT 'other',
    recording_url   VARCHAR(1000) DEFAULT NULL,
    duration_min    INT DEFAULT NULL,
    file_size_mb    DECIMAL(8,2) DEFAULT NULL,
    access_level    ENUM('all','participants_only','admins_only') DEFAULT 'participants_only',
    expires_at      DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    uploaded_by     INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_meeting (org_id, meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- MEETINGS — Documents
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meeting_documents (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    meeting_id      INT DEFAULT NULL COMMENT 'NULL = general/unlinked doc',
    title           VARCHAR(255) NOT NULL,
    doc_type        ENUM('agenda','minutes','presentation','report','contract','policy','other') DEFAULT 'other',
    file_url        VARCHAR(1000) DEFAULT NULL,
    file_size_kb    INT DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    access_level    ENUM('all','participants_only','admins_only') DEFAULT 'all',
    uploaded_by     INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_meeting (org_id, meeting_id),
    INDEX idx_doc_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- TOUR — Itineraries
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_itineraries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    package_id      INT DEFAULT NULL,
    booking_id      INT DEFAULT NULL,
    day_number      INT NOT NULL DEFAULT 1,
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    location        VARCHAR(200) DEFAULT NULL,
    activities      TEXT DEFAULT NULL,
    meals_included  SET('breakfast','lunch','dinner') DEFAULT NULL,
    accommodation   VARCHAR(200) DEFAULT NULL,
    transport       VARCHAR(150) DEFAULT NULL,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_package (org_id, package_id),
    INDEX idx_booking (booking_id),
    INDEX idx_day (package_id, day_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- TOUR — Vehicles / Fleet
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    reg_no          VARCHAR(30) DEFAULT NULL,
    vehicle_type    ENUM('sedan','suv','minibus','bus','van','4x4','boat','other') DEFAULT 'suv',
    capacity        INT DEFAULT 4,
    fuel_type       ENUM('petrol','diesel','electric','hybrid') DEFAULT 'diesel',
    year            INT DEFAULT NULL,
    color           VARCHAR(50) DEFAULT NULL,
    driver_name     VARCHAR(150) DEFAULT NULL,
    driver_phone    VARCHAR(30) DEFAULT NULL,
    status          ENUM('available','booked','maintenance','retired') DEFAULT 'available',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL,
    INDEX idx_org (org_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- TOUR — Booking Payments
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tour_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    receipt_no      VARCHAR(30) NOT NULL,
    booking_id      INT NOT NULL,
    customer_id     INT DEFAULT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_type    ENUM('deposit','installment','full','refund','commission') DEFAULT 'installment',
    payment_mode    ENUM('cash','mpesa','bank','card','online') DEFAULT 'cash',
    reference       VARCHAR(100) DEFAULT NULL,
    payment_date    DATE NOT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_receipt (org_id, receipt_no),
    INDEX idx_org_booking (org_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- Add updated_at to tour_bookings if missing
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE tour_bookings
    ADD COLUMN IF NOT EXISTS paid_amount   DECIMAL(12,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS vehicle_id    INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at    DATETIME DEFAULT NULL;

-- ─────────────────────────────────────────────────────────────────
-- Add route_id to couriers table if missing
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE couriers
    ADD COLUMN IF NOT EXISTS route_id      INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agent_id      INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS recipient_phone VARCHAR(30) DEFAULT NULL;
