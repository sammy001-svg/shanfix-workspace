-- ============================================================
-- Driving School Management Module — Migration
-- Run ONCE. Safe: all statements use IF NOT EXISTS.
-- ============================================================

-- Register module
INSERT IGNORE INTO modules
    (slug, name, description, icon, color, category, monthly_price, annual_price, sort_order, status)
VALUES
    ('driving', 'Driving School',
     'Manage students, instructors, vehicles, lessons, tests and licenses for a driving school',
     'fas fa-car', '#1a237e', 'Education', 4500, 45000, 22, 'active');

-- 1. Instructors
CREATE TABLE IF NOT EXISTS driving_instructors (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    org_id            INT NOT NULL,
    name              VARCHAR(150) NOT NULL,
    email             VARCHAR(100),
    phone             VARCHAR(50),
    license_number    VARCHAR(50),
    specialization    VARCHAR(100),
    photo             VARCHAR(255),
    status            ENUM('active','inactive') DEFAULT 'active',
    notes             TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_di_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Vehicles
CREATE TABLE IF NOT EXISTS driving_vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    number_plate    VARCHAR(50) NOT NULL,
    make            VARCHAR(100),
    model           VARCHAR(100),
    year            YEAR,
    type            ENUM('car','motorcycle','truck','bus','other') DEFAULT 'car',
    transmission    ENUM('manual','automatic') DEFAULT 'manual',
    instructor_id   INT DEFAULT NULL,
    status          ENUM('active','inactive','maintenance') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dv_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Students
CREATE TABLE IF NOT EXISTS driving_students (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    last_name           VARCHAR(100),
    email               VARCHAR(100),
    phone               VARCHAR(50),
    id_number           VARCHAR(50),
    date_of_birth       DATE DEFAULT NULL,
    address             TEXT,
    emergency_contact   VARCHAR(100),
    emergency_phone     VARCHAR(50),
    instructor_id       INT DEFAULT NULL,
    enrollment_date     DATE DEFAULT NULL,
    license_category    VARCHAR(20) DEFAULT 'B',
    status              ENUM('active','inactive','completed','suspended') DEFAULT 'active',
    photo               VARCHAR(255),
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ds_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Classes
CREATE TABLE IF NOT EXISTS driving_classes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    training_type   ENUM('theory','practical','both') DEFAULT 'practical',
    vehicle_type    ENUM('car','motorcycle','truck','bus','other') DEFAULT 'car',
    instructor_id   INT DEFAULT NULL,
    schedule_day    VARCHAR(50),
    schedule_time   TIME DEFAULT NULL,
    duration_hours  DECIMAL(4,1) DEFAULT 1.0,
    max_capacity    INT DEFAULT 10,
    current_enrolled INT DEFAULT 0,
    fee             DECIMAL(10,2) DEFAULT 0.00,
    status          ENUM('active','inactive') DEFAULT 'active',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dc_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Lessons
CREATE TABLE IF NOT EXISTS driving_lessons (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    student_id      INT NOT NULL,
    instructor_id   INT DEFAULT NULL,
    vehicle_id      INT DEFAULT NULL,
    class_id        INT DEFAULT NULL,
    lesson_number   INT DEFAULT 1,
    lesson_date     DATE NOT NULL,
    start_time      TIME DEFAULT NULL,
    end_time        TIME DEFAULT NULL,
    duration_hours  DECIMAL(4,1) DEFAULT 1.0,
    topic           VARCHAR(255),
    status          ENUM('draft','started','completed','cancelled') DEFAULT 'draft',
    instructor_notes TEXT,
    feedback        TEXT,
    score           DECIMAL(5,2) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_org (org_id),
    INDEX idx_dl_student (student_id),
    INDEX idx_dl_date (lesson_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Tests
CREATE TABLE IF NOT EXISTS driving_tests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    student_id      INT NOT NULL,
    instructor_id   INT DEFAULT NULL,
    vehicle_id      INT DEFAULT NULL,
    test_date       DATE NOT NULL,
    test_time       TIME DEFAULT NULL,
    test_type       ENUM('theory','practical','both') DEFAULT 'practical',
    status          ENUM('scheduled','passed','failed','cancelled') DEFAULT 'scheduled',
    score           DECIMAL(5,2) DEFAULT NULL,
    pass_mark       DECIMAL(5,2) DEFAULT 70.00,
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dt_org (org_id),
    INDEX idx_dt_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Licenses
CREATE TABLE IF NOT EXISTS driving_licenses (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    student_id          INT NOT NULL,
    license_number      VARCHAR(50),
    license_class       VARCHAR(20) DEFAULT 'B',
    issue_date          DATE DEFAULT NULL,
    expiry_date         DATE DEFAULT NULL,
    issuing_authority   VARCHAR(150),
    status              ENUM('pending','approved','rejected','expired') DEFAULT 'pending',
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dlc_org (org_id),
    INDEX idx_dlc_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
