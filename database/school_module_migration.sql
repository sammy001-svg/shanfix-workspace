-- -- School Module Migration --------------------------------------------------
-- Compatible with MySQL 5.7+ / MariaDB / cPanel.
-- HOW TO RUN:
--   1. Select your database in the phpMyAdmin left sidebar.
--   2. Open the SQL tab.
--   3. Copy and paste ONE statement at a time, then click Go.
--   4. If you get "#1060 - Duplicate column name", that column already exists - SKIP IT and continue.
--   5. Any other error: note it and skip that statement.
-- Do NOT add USE <dbname>; - select your database from the left sidebar first.
-- -----------------------------------------------------------------------------


-- ================================================================
-- SECTION 1: sch_classes (run each line separately)
-- ================================================================
ALTER TABLE sch_classes ADD COLUMN IF NOT EXISTS level VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE sch_classes ADD COLUMN IF NOT EXISTS curriculum VARCHAR(50) NOT NULL DEFAULT 'IB';
ALTER TABLE sch_classes ADD COLUMN IF NOT EXISTS room VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE sch_classes ADD COLUMN IF NOT EXISTS academic_year_id INT NULL DEFAULT NULL;


-- ================================================================
-- SECTION 2: sch_academic_years (create if table is missing)
-- ================================================================
CREATE TABLE IF NOT EXISTS sch_academic_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    org_id     INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    start_date DATE NULL,
    end_date   DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ================================================================
-- SECTION 3: sch_terms
-- ================================================================
CREATE TABLE IF NOT EXISTS sch_terms (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    academic_year_id INT NULL,
    name             VARCHAR(100) NOT NULL,
    term_type        VARCHAR(20) NOT NULL DEFAULT 'term',
    start_date       DATE NULL,
    end_date         DATE NULL,
    is_current       TINYINT(1) NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'upcoming',
    notes            TEXT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If sch_terms already exists, add any missing columns one at a time:
ALTER TABLE sch_terms ADD COLUMN IF NOT EXISTS academic_year_id INT NULL DEFAULT NULL;
ALTER TABLE sch_terms ADD COLUMN IF NOT EXISTS term_type VARCHAR(20) NOT NULL DEFAULT 'term';
ALTER TABLE sch_terms ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'upcoming';
ALTER TABLE sch_terms ADD COLUMN IF NOT EXISTS notes TEXT NULL;


-- ================================================================
-- SECTION 4: sch_subjects (create if table is missing)
-- ================================================================
CREATE TABLE IF NOT EXISTS sch_subjects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    code        VARCHAR(20)  NULL,
    name        VARCHAR(150) NOT NULL,
    department  VARCHAR(100) NULL,
    description TEXT         NULL,
    is_elective TINYINT(1)   NOT NULL DEFAULT 0,
    pass_mark   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    status      VARCHAR(20)  NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ================================================================
-- SECTION 5: sch_class_subjects (create if table is missing)
-- ================================================================
CREATE TABLE IF NOT EXISTS sch_class_subjects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    class_id     INT NOT NULL,
    subject_id   INT NOT NULL,
    staff_id     INT NULL,
    periods_week INT NOT NULL DEFAULT 4,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_subject (class_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ================================================================
-- SECTION 6: sch_students - international & emergency columns
-- (run each line separately; skip any that say Duplicate column)
-- ================================================================
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS nationality VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS passport_no VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS visa_expiry DATE NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS curriculum VARCHAR(50) NOT NULL DEFAULT 'IB';
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS mother_tongue VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS previous_school VARCHAR(200) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS medical_conditions TEXT NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS learning_support TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(150) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS emergency_phone VARCHAR(30) NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS admitted_on DATE NULL DEFAULT NULL;
ALTER TABLE sch_students ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL DEFAULT NULL;


-- ================================================================
-- SECTION 7: sch_parents - portal PIN columns
-- ================================================================
ALTER TABLE sch_parents ADD COLUMN IF NOT EXISTS parent_pin VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE sch_parents ADD COLUMN IF NOT EXISTS portal_enabled TINYINT(1) NOT NULL DEFAULT 0;
