-- ============================================================
-- School Management Phase 2 Migration
-- Run after the original school migration
-- ============================================================

-- Subjects / Courses
CREATE TABLE IF NOT EXISTS `sch_subjects` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `code`        VARCHAR(30) DEFAULT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `department`  VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `is_elective` TINYINT(1) NOT NULL DEFAULT 0,
  `pass_mark`   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_subjects_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subject assignments to classes (with teacher)
CREATE TABLE IF NOT EXISTS `sch_class_subjects` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`       INT NOT NULL,
  `class_id`     INT NOT NULL,
  `subject_id`   INT NOT NULL,
  `staff_id`     INT DEFAULT NULL,
  `periods_week` INT NOT NULL DEFAULT 1,
  KEY `idx_sch_cs_org` (`org_id`),
  KEY `idx_sch_cs_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Timetable slots
CREATE TABLE IF NOT EXISTS `sch_timetable` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `class_id`    INT NOT NULL,
  `subject_id`  INT DEFAULT NULL,
  `staff_id`    INT DEFAULT NULL,
  `day_of_week` TINYINT NOT NULL COMMENT '1=Mon,2=Tue,...,5=Fri',
  `period`      TINYINT NOT NULL COMMENT '1-10',
  `start_time`  TIME NOT NULL,
  `end_time`    TIME NOT NULL,
  `room`        VARCHAR(50) DEFAULT NULL,
  KEY `idx_sch_tt_org` (`org_id`),
  KEY `idx_sch_tt_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance (per student per day)
CREATE TABLE IF NOT EXISTS `sch_attendance` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `student_id`  INT NOT NULL,
  `class_id`    INT NOT NULL,
  `att_date`    DATE NOT NULL,
  `status`      ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `remarks`     VARCHAR(255) DEFAULT NULL,
  `recorded_by` INT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_att` (`org_id`,`student_id`,`att_date`),
  KEY `idx_sch_att_class` (`class_id`),
  KEY `idx_sch_att_date`  (`att_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exams
CREATE TABLE IF NOT EXISTS `sch_exams` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `term`        VARCHAR(50) DEFAULT NULL,
  `academic_year` VARCHAR(20) DEFAULT NULL,
  `start_date`  DATE DEFAULT NULL,
  `end_date`    DATE DEFAULT NULL,
  `status`      ENUM('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `description` TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_exams_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exam schedule (exam + class + subject slots)
CREATE TABLE IF NOT EXISTS `sch_exam_schedule` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `exam_id`    INT NOT NULL,
  `class_id`   INT NOT NULL,
  `subject_id` INT NOT NULL,
  `exam_date`  DATE DEFAULT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time`   TIME DEFAULT NULL,
  `room`       VARCHAR(50) DEFAULT NULL,
  `max_marks`  DECIMAL(8,2) NOT NULL DEFAULT 100,
  KEY `idx_sch_esched_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exam Results
CREATE TABLE IF NOT EXISTS `sch_results` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `exam_id`     INT NOT NULL,
  `student_id`  INT NOT NULL,
  `class_id`    INT NOT NULL,
  `subject_id`  INT NOT NULL,
  `marks`       DECIMAL(8,2) DEFAULT NULL,
  `max_marks`   DECIMAL(8,2) NOT NULL DEFAULT 100,
  `grade`       VARCHAR(10) DEFAULT NULL,
  `remarks`     VARCHAR(255) DEFAULT NULL,
  `created_by`  INT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_result` (`org_id`,`exam_id`,`student_id`,`subject_id`),
  KEY `idx_sch_res_org`     (`org_id`),
  KEY `idx_sch_res_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parents / Guardians
CREATE TABLE IF NOT EXISTS `sch_parents` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`          INT NOT NULL,
  `first_name`      VARCHAR(80) NOT NULL,
  `last_name`       VARCHAR(80) NOT NULL,
  `relationship`    ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'guardian',
  `phone`           VARCHAR(30) DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `national_id`     VARCHAR(50) DEFAULT NULL,
  `occupation`      VARCHAR(100) DEFAULT NULL,
  `address`         TEXT DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_parents_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student ↔ Parent link
CREATE TABLE IF NOT EXISTS `sch_student_parents` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `parent_id`  INT NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_sp` (`student_id`,`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Library Books
CREATE TABLE IF NOT EXISTS `sch_books` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`       INT NOT NULL,
  `isbn`         VARCHAR(30) DEFAULT NULL,
  `title`        VARCHAR(200) NOT NULL,
  `author`       VARCHAR(150) DEFAULT NULL,
  `publisher`    VARCHAR(150) DEFAULT NULL,
  `category`     VARCHAR(100) DEFAULT NULL,
  `edition`      VARCHAR(50) DEFAULT NULL,
  `year`         YEAR DEFAULT NULL,
  `total_copies` INT NOT NULL DEFAULT 1,
  `available`    INT NOT NULL DEFAULT 1,
  `shelf`        VARCHAR(50) DEFAULT NULL,
  `status`       ENUM('active','retired') NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_books_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Book Loans
CREATE TABLE IF NOT EXISTS `sch_book_loans` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`       INT NOT NULL,
  `book_id`      INT NOT NULL,
  `borrower_type` ENUM('student','staff') NOT NULL DEFAULT 'student',
  `borrower_id`  INT NOT NULL,
  `borrower_name` VARCHAR(150) NOT NULL,
  `issue_date`   DATE NOT NULL,
  `due_date`     DATE NOT NULL,
  `return_date`  DATE DEFAULT NULL,
  `fine_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `fine_paid`    TINYINT(1) NOT NULL DEFAULT 0,
  `status`       ENUM('issued','returned','overdue','lost') NOT NULL DEFAULT 'issued',
  `notes`        TEXT DEFAULT NULL,
  `created_by`   INT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_loans_org`  (`org_id`),
  KEY `idx_sch_loans_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transport Routes
CREATE TABLE IF NOT EXISTS `sch_transport_routes` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`       INT NOT NULL,
  `route_name`   VARCHAR(100) NOT NULL,
  `vehicle_no`   VARCHAR(30) DEFAULT NULL,
  `driver_name`  VARCHAR(100) DEFAULT NULL,
  `driver_phone` VARCHAR(30) DEFAULT NULL,
  `conductor`    VARCHAR(100) DEFAULT NULL,
  `capacity`     INT NOT NULL DEFAULT 40,
  `morning_time` TIME DEFAULT NULL,
  `evening_time` TIME DEFAULT NULL,
  `stops`        TEXT DEFAULT NULL COMMENT 'Comma-separated stops',
  `term_fee`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_routes_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student Transport Assignments
CREATE TABLE IF NOT EXISTS `sch_transport_students` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `route_id`    INT NOT NULL,
  `student_id`  INT NOT NULL,
  `pickup_stop` VARCHAR(100) DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY `uniq_ts` (`student_id`,`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- School Events / Calendar
CREATE TABLE IF NOT EXISTS `sch_events` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `event_type`  ENUM('academic','sports','cultural','holiday','meeting','exam','other') NOT NULL DEFAULT 'academic',
  `start_date`  DATE NOT NULL,
  `end_date`    DATE DEFAULT NULL,
  `start_time`  TIME DEFAULT NULL,
  `end_time`    TIME DEFAULT NULL,
  `venue`       VARCHAR(200) DEFAULT NULL,
  `audience`    ENUM('all','students','staff','parents') NOT NULL DEFAULT 'all',
  `status`      ENUM('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `created_by`  INT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_events_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notices / Announcements
CREATE TABLE IF NOT EXISTS `sch_notices` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `content`     TEXT NOT NULL,
  `priority`    ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
  `audience`    ENUM('all','students','staff','parents','class') NOT NULL DEFAULT 'all',
  `class_id`    INT DEFAULT NULL,
  `publish_date` DATE NOT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `is_pinned`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  INT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_notices_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hostel / Dormitory Rooms
CREATE TABLE IF NOT EXISTS `sch_hostel_rooms` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `room_no`     VARCHAR(20) NOT NULL,
  `room_type`   ENUM('dormitory','private','semi-private') NOT NULL DEFAULT 'dormitory',
  `floor`       VARCHAR(20) DEFAULT NULL,
  `block`       VARCHAR(50) DEFAULT NULL,
  `capacity`    INT NOT NULL DEFAULT 4,
  `occupied`    INT NOT NULL DEFAULT 0,
  `term_fee`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`      ENUM('available','full','maintenance') NOT NULL DEFAULT 'available',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sch_hostel_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student Hostel Assignments
CREATE TABLE IF NOT EXISTS `sch_hostel_students` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `org_id`      INT NOT NULL,
  `room_id`     INT NOT NULL,
  `student_id`  INT NOT NULL,
  `check_in`    DATE NOT NULL,
  `check_out`   DATE DEFAULT NULL,
  `status`      ENUM('active','vacated') NOT NULL DEFAULT 'active',
  UNIQUE KEY `uniq_hs` (`student_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alter existing sch_students: add parent & hostel links
ALTER TABLE `sch_students`
  ADD COLUMN IF NOT EXISTS `parent_id`    INT DEFAULT NULL AFTER `org_id`,
  ADD COLUMN IF NOT EXISTS `national_id`  VARCHAR(50) DEFAULT NULL AFTER `dob`,
  ADD COLUMN IF NOT EXISTS `blood_group`  VARCHAR(10) DEFAULT NULL AFTER `national_id`,
  ADD COLUMN IF NOT EXISTS `religion`     VARCHAR(50) DEFAULT NULL AFTER `blood_group`,
  ADD COLUMN IF NOT EXISTS `medical_notes` TEXT DEFAULT NULL AFTER `religion`;
