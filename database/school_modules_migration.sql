-- ============================================================
--  School Module — Comprehensive Gap Migration
--  File: school_modules_migration.sql
--  Run AFTER: schema.sql, school_phase2_migration.sql,
--             school_intl_migration.sql, school_phase3_migration.sql
--  Safe to re-run: all statements use IF NOT EXISTS / IF EXISTS
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 1. sch_fees — ensure all columns referenced by fees.php exist
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_fees`
    ADD COLUMN IF NOT EXISTS `fee_type`       VARCHAR(100)  NOT NULL DEFAULT 'tuition'    AFTER `student_id`,
    ADD COLUMN IF NOT EXISTS `term_id`        INT                    DEFAULT NULL          AFTER `fee_type`,
    ADD COLUMN IF NOT EXISTS `term`           VARCHAR(50)            DEFAULT NULL          AFTER `term_id`,
    ADD COLUMN IF NOT EXISTS `year`           YEAR                   DEFAULT NULL          AFTER `term`,
    ADD COLUMN IF NOT EXISTS `currency`       ENUM('KES','USD','LRD') NOT NULL DEFAULT 'KES' AFTER `balance`,
    ADD COLUMN IF NOT EXISTS `receipt_no`     VARCHAR(80)            DEFAULT NULL          AFTER `currency`,
    ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50)            DEFAULT NULL          AFTER `receipt_no`,
    ADD COLUMN IF NOT EXISTS `paid_by`        VARCHAR(100)           DEFAULT NULL          AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `notes`          TEXT                   DEFAULT NULL          AFTER `paid_by`;

-- ────────────────────────────────────────────────────────────
-- 2. sch_fee_payments — full table (idempotent)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_fee_payments` (
    `id`             INT            NOT NULL AUTO_INCREMENT,
    `org_id`         INT            NOT NULL,
    `fee_id`         INT            NOT NULL,
    `student_id`     INT            NOT NULL,
    `receipt_no`     VARCHAR(80)    NOT NULL,
    `amount`         DECIMAL(14,2)  NOT NULL,
    `amount_paid`    DECIMAL(14,2)           DEFAULT NULL  COMMENT 'Alias kept for legacy queries',
    `currency`       ENUM('KES','USD','LRD') NOT NULL DEFAULT 'KES',
    `exchange_rate`  DECIMAL(10,4)  NOT NULL DEFAULT 1.0000,
    `amount_kes`     DECIMAL(14,2)  NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cash','mpesa','bank-transfer','card','cheque','online','other') NOT NULL DEFAULT 'cash',
    `paid_by`        VARCHAR(150)            DEFAULT NULL,
    `payment_date`   DATE           NOT NULL,
    `notes`          TEXT                    DEFAULT NULL,
    `created_by`     INT                     DEFAULT NULL,
    `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pay_org`     (`org_id`),
    KEY `idx_pay_fee`     (`fee_id`),
    KEY `idx_pay_student` (`student_id`),
    KEY `idx_pay_receipt` (`receipt_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure amount_paid is synced with amount on existing rows
UPDATE `sch_fee_payments` SET `amount_paid` = `amount` WHERE `amount_paid` IS NULL AND `amount` IS NOT NULL;

-- ────────────────────────────────────────────────────────────
-- 3. sch_teachers — ensure portal columns exist
--    (school_teacher_portal_migration.sql adds these, but guard again)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_teachers`
    ADD COLUMN IF NOT EXISTS `password_hash`  VARCHAR(255)  DEFAULT NULL  AFTER `photo`,
    ADD COLUMN IF NOT EXISTS `portal_enabled` TINYINT(1)    NOT NULL DEFAULT 1 AFTER `password_hash`,
    ADD COLUMN IF NOT EXISTS `last_login`     DATETIME      DEFAULT NULL  AFTER `portal_enabled`;

-- ────────────────────────────────────────────────────────────
-- 4. sch_parents — ensure portal columns exist
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_parents`
    ADD COLUMN IF NOT EXISTS `parent_pin`     VARCHAR(255)  DEFAULT NULL  AFTER `status`,
    ADD COLUMN IF NOT EXISTS `portal_enabled` TINYINT(1)    NOT NULL DEFAULT 0 AFTER `parent_pin`,
    ADD COLUMN IF NOT EXISTS `last_login`     DATETIME      DEFAULT NULL  AFTER `portal_enabled`,
    ADD COLUMN IF NOT EXISTS `is_primary`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `last_login`;

-- ────────────────────────────────────────────────────────────
-- 5. sch_students — ensure portal + extra columns exist
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_students`
    ADD COLUMN IF NOT EXISTS `password_hash`  VARCHAR(255)  DEFAULT NULL  AFTER `photo`,
    ADD COLUMN IF NOT EXISTS `portal_enabled` TINYINT(1)    NOT NULL DEFAULT 0 AFTER `password_hash`,
    ADD COLUMN IF NOT EXISTS `last_login`     DATETIME      DEFAULT NULL  AFTER `portal_enabled`,
    ADD COLUMN IF NOT EXISTS `admission_no`   VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `parent_id`      INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `national_id`    VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `blood_group`    VARCHAR(10)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `religion`       VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `medical_notes`  TEXT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nationality`    VARCHAR(80)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `curriculum`     VARCHAR(30)   DEFAULT 'IB',
    ADD COLUMN IF NOT EXISTS `emergency_contact` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `emergency_phone`   VARCHAR(30)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `graduation_year`   YEAR         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `current_city`      VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `current_employer`  VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `alumni_notes`      TEXT         DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 6. sch_student_parents — link table
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_student_parents` (
    `id`         INT       NOT NULL AUTO_INCREMENT,
    `org_id`     INT       NOT NULL,
    `student_id` INT       NOT NULL,
    `parent_id`  INT       NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_stu_par` (`student_id`, `parent_id`),
    KEY `idx_org`    (`org_id`),
    KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 7. sch_terms
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_terms` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `org_id`           INT          NOT NULL,
    `academic_year_id` INT                   DEFAULT NULL,
    `name`             VARCHAR(100) NOT NULL,
    `term_type`        ENUM('term','semester','quarter','trimester') NOT NULL DEFAULT 'term',
    `start_date`       DATE                  DEFAULT NULL,
    `end_date`         DATE                  DEFAULT NULL,
    `is_current`       TINYINT(1)   NOT NULL DEFAULT 0,
    `status`           VARCHAR(20)  NOT NULL DEFAULT 'upcoming',
    `notes`            TEXT                  DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_terms_org`     (`org_id`),
    KEY `idx_terms_current` (`org_id`, `is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 8. sch_class_subjects — teacher-to-class-subject assignment
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_class_subjects` (
    `id`           INT      NOT NULL AUTO_INCREMENT,
    `org_id`       INT      NOT NULL,
    `class_id`     INT      NOT NULL,
    `subject_id`   INT      NOT NULL,
    `staff_id`     INT               DEFAULT NULL,
    `periods_week` INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_cs_org`   (`org_id`),
    KEY `idx_cs_class` (`class_id`),
    KEY `idx_cs_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 9. sch_timetable
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_timetable` (
    `id`          INT      NOT NULL AUTO_INCREMENT,
    `org_id`      INT      NOT NULL,
    `class_id`    INT      NOT NULL,
    `subject_id`  INT               DEFAULT NULL,
    `staff_id`    INT               DEFAULT NULL,
    `day_of_week` TINYINT  NOT NULL COMMENT '1=Mon … 7=Sun',
    `period`      TINYINT  NOT NULL DEFAULT 1,
    `start_time`  TIME     NOT NULL,
    `end_time`    TIME     NOT NULL,
    `room`        VARCHAR(50)       DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tt_org`   (`org_id`),
    KEY `idx_tt_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 10. sch_attendance
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_attendance` (
    `id`          INT      NOT NULL AUTO_INCREMENT,
    `org_id`      INT      NOT NULL,
    `student_id`  INT      NOT NULL,
    `class_id`    INT      NOT NULL,
    `att_date`    DATE     NOT NULL,
    `status`      ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
    `remarks`     VARCHAR(255)      DEFAULT NULL,
    `marked_by`   INT               DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_att` (`org_id`, `student_id`, `att_date`),
    KEY `idx_att_class` (`class_id`),
    KEY `idx_att_date`  (`att_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure marked_by alias column exists (older schemas used recorded_by)
ALTER TABLE `sch_attendance`
    ADD COLUMN IF NOT EXISTS `marked_by` INT DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 11. sch_exams
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_exams` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `org_id`        INT          NOT NULL,
    `name`          VARCHAR(150) NOT NULL,
    `term`          VARCHAR(50)           DEFAULT NULL,
    `academic_year` VARCHAR(20)           DEFAULT NULL,
    `start_date`    DATE                  DEFAULT NULL,
    `end_date`      DATE                  DEFAULT NULL,
    `status`        ENUM('upcoming','ongoing','active','published','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    `description`   TEXT                  DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_exams_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 12. sch_exam_schedule
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_exam_schedule` (
    `id`         INT           NOT NULL AUTO_INCREMENT,
    `org_id`     INT                    DEFAULT NULL,
    `exam_id`    INT           NOT NULL,
    `class_id`   INT           NOT NULL,
    `subject_id` INT           NOT NULL,
    `exam_date`  DATE                   DEFAULT NULL,
    `start_time` TIME                   DEFAULT NULL,
    `end_time`   TIME                   DEFAULT NULL,
    `room`       VARCHAR(50)            DEFAULT NULL,
    `max_marks`  DECIMAL(8,2)  NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_esched_exam`  (`exam_id`),
    KEY `idx_esched_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sch_exam_schedule`
    ADD COLUMN IF NOT EXISTS `org_id` INT DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 13. sch_results
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_results` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `org_id`           INT          NOT NULL,
    `exam_id`          INT          NOT NULL,
    `student_id`       INT          NOT NULL,
    `class_id`         INT          NOT NULL,
    `subject_id`       INT          NOT NULL,
    `marks`            DECIMAL(8,2)          DEFAULT NULL,
    `max_marks`        DECIMAL(8,2) NOT NULL DEFAULT 100,
    `grade`            VARCHAR(10)           DEFAULT NULL,
    `remarks`          VARCHAR(255)          DEFAULT NULL,
    `teacher_comment`  TEXT                  DEFAULT NULL,
    `predicted_grade`  VARCHAR(10)           DEFAULT NULL,
    `created_by`       INT                   DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_result` (`org_id`, `exam_id`, `student_id`, `subject_id`),
    KEY `idx_res_org`     (`org_id`),
    KEY `idx_res_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 14. sch_homework
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_homework` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `org_id`       INT          NOT NULL,
    `class_id`     INT          NOT NULL,
    `subject_id`   INT                   DEFAULT NULL,
    `teacher_id`   INT                   DEFAULT NULL,
    `term_id`      INT                   DEFAULT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT,
    `due_date`     DATE                  DEFAULT NULL,
    `max_marks`    DECIMAL(6,2) NOT NULL DEFAULT 10.00,
    `status`       ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME              DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hw_org`   (`org_id`),
    KEY `idx_hw_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 15. sch_notices — ensure all columns used by portals exist
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_notices` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `org_id`       INT          NOT NULL,
    `title`        VARCHAR(200) NOT NULL,
    `content`      TEXT         NOT NULL,
    `priority`     ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    `audience`     ENUM('all','students','staff','parents','class') NOT NULL DEFAULT 'all',
    `class_id`     INT                   DEFAULT NULL,
    `publish_date` DATE         NOT NULL,
    `expiry_date`  DATE                  DEFAULT NULL,
    `is_pinned`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`   INT                   DEFAULT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notices_org`      (`org_id`),
    KEY `idx_notices_audience` (`org_id`, `audience`),
    KEY `idx_notices_expiry`   (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- body column added for forwards-compat (some queries may use either)
ALTER TABLE `sch_notices`
    ADD COLUMN IF NOT EXISTS `body` TEXT DEFAULT NULL COMMENT 'Alias for content; kept for compatibility';

-- ────────────────────────────────────────────────────────────
-- 16. sch_grades — ensure columns exist
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_grades`
    ADD COLUMN IF NOT EXISTS `curriculum`  VARCHAR(30)   NOT NULL DEFAULT 'General',
    ADD COLUMN IF NOT EXISTS `ib_points`   TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `igcse_grade` VARCHAR(5)    DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 17. sch_discipline (disciplinary records)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_discipline` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `org_id`            INT          NOT NULL,
    `student_id`        INT          NOT NULL,
    `term_id`           INT                   DEFAULT NULL,
    `reported_by`       INT                   DEFAULT NULL,
    `incident_date`     DATE         NOT NULL,
    `category`          ENUM('behaviour','attendance','uniform','bullying','academic-dishonesty',
                             'substance','property-damage','cyberbullying','other')
                                     NOT NULL DEFAULT 'behaviour',
    `severity`          ENUM('minor','moderate','serious','critical') NOT NULL DEFAULT 'minor',
    `description`       TEXT         NOT NULL,
    `witnesses`         VARCHAR(300)          DEFAULT NULL,
    `action_taken`      TEXT                  DEFAULT NULL,
    `suspension_days`   INT          NOT NULL DEFAULT 0,
    `parent_notified`   TINYINT(1)   NOT NULL DEFAULT 0,
    `parent_notified_at` DATETIME             DEFAULT NULL,
    `parent_response`   TEXT                  DEFAULT NULL,
    `follow_up_date`    DATE                  DEFAULT NULL,
    `follow_up_notes`   TEXT                  DEFAULT NULL,
    `status`            ENUM('open','under-review','resolved','appealed','dismissed') NOT NULL DEFAULT 'open',
    `resolved_at`       DATETIME              DEFAULT NULL,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP             DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_disc_org`     (`org_id`),
    KEY `idx_disc_student` (`student_id`),
    KEY `idx_disc_date`    (`incident_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure sch_disciplinary is also available (alternate table name used by some modules)
CREATE TABLE IF NOT EXISTS `sch_disciplinary` LIKE `sch_discipline`;

-- ────────────────────────────────────────────────────────────
-- 18. sch_hostel_rooms & sch_hostel_students
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_hostel_rooms` (
    `id`         INT         NOT NULL AUTO_INCREMENT,
    `org_id`     INT         NOT NULL,
    `room_no`    VARCHAR(20) NOT NULL,
    `room_type`  ENUM('dormitory','private','semi-private') NOT NULL DEFAULT 'dormitory',
    `floor`      VARCHAR(20)          DEFAULT NULL,
    `block`      VARCHAR(50)          DEFAULT NULL,
    `capacity`   INT         NOT NULL DEFAULT 4,
    `occupied`   INT         NOT NULL DEFAULT 0,
    `term_fee`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`     ENUM('available','full','maintenance') NOT NULL DEFAULT 'available',
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hostel_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sch_hostel_students` (
    `id`         INT  NOT NULL AUTO_INCREMENT,
    `org_id`     INT  NOT NULL,
    `room_id`    INT  NOT NULL,
    `student_id` INT  NOT NULL,
    `check_in`   DATE NOT NULL,
    `check_out`  DATE          DEFAULT NULL,
    `status`     ENUM('active','vacated') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_hs` (`student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 19. sch_transport_routes & sch_transport_students
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_transport_routes` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `org_id`       INT          NOT NULL,
    `route_name`   VARCHAR(100) NOT NULL,
    `vehicle_no`   VARCHAR(30)           DEFAULT NULL,
    `driver_name`  VARCHAR(100)          DEFAULT NULL,
    `driver_phone` VARCHAR(30)           DEFAULT NULL,
    `conductor`    VARCHAR(100)          DEFAULT NULL,
    `capacity`     INT          NOT NULL DEFAULT 40,
    `morning_time` TIME                  DEFAULT NULL,
    `evening_time` TIME                  DEFAULT NULL,
    `stops`        TEXT                  DEFAULT NULL,
    `term_fee`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_routes_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sch_transport_students` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `org_id`      INT          NOT NULL,
    `route_id`    INT          NOT NULL,
    `student_id`  INT          NOT NULL,
    `pickup_stop` VARCHAR(100)          DEFAULT NULL,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ts` (`student_id`, `route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 20. sch_books & sch_book_loans (library)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_books` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `org_id`       INT          NOT NULL,
    `isbn`         VARCHAR(30)           DEFAULT NULL,
    `title`        VARCHAR(200) NOT NULL,
    `author`       VARCHAR(150)          DEFAULT NULL,
    `publisher`    VARCHAR(150)          DEFAULT NULL,
    `category`     VARCHAR(100)          DEFAULT NULL,
    `edition`      VARCHAR(50)           DEFAULT NULL,
    `year`         YEAR                  DEFAULT NULL,
    `total_copies` INT          NOT NULL DEFAULT 1,
    `available`    INT          NOT NULL DEFAULT 1,
    `shelf`        VARCHAR(50)           DEFAULT NULL,
    `status`       ENUM('active','retired') NOT NULL DEFAULT 'active',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_books_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sch_book_loans` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `org_id`        INT          NOT NULL,
    `book_id`       INT          NOT NULL,
    `borrower_type` ENUM('student','staff') NOT NULL DEFAULT 'student',
    `borrower_id`   INT          NOT NULL,
    `borrower_name` VARCHAR(150) NOT NULL,
    `issue_date`    DATE         NOT NULL,
    `due_date`      DATE         NOT NULL,
    `return_date`   DATE                  DEFAULT NULL,
    `fine_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `fine_paid`     TINYINT(1)   NOT NULL DEFAULT 0,
    `status`        ENUM('issued','returned','overdue','lost') NOT NULL DEFAULT 'issued',
    `notes`         TEXT                  DEFAULT NULL,
    `created_by`    INT                   DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_loans_org`  (`org_id`),
    KEY `idx_loans_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 21. sch_events (school calendar events)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_events` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `org_id`      INT          NOT NULL,
    `title`       VARCHAR(200) NOT NULL,
    `description` TEXT                  DEFAULT NULL,
    `event_type`  ENUM('academic','sports','cultural','holiday','meeting','exam','other')
                               NOT NULL DEFAULT 'academic',
    `start_date`  DATE         NOT NULL,
    `end_date`    DATE                  DEFAULT NULL,
    `start_time`  TIME                  DEFAULT NULL,
    `end_time`    TIME                  DEFAULT NULL,
    `venue`       VARCHAR(200)          DEFAULT NULL,
    `audience`    ENUM('all','students','staff','parents') NOT NULL DEFAULT 'all',
    `status`      ENUM('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    `created_by`  INT                   DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_events_org`  (`org_id`),
    KEY `idx_events_date` (`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 22. sch_communications (bulk messaging log)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_communications` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `org_id`            INT          NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `message`           TEXT         NOT NULL,
    `recipients_type`   ENUM('all_parents','class_parents','all_teachers','all_staff','custom')
                                     NOT NULL DEFAULT 'all_parents',
    `class_id`          INT                   DEFAULT NULL,
    `channel`           ENUM('sms','notice','both') NOT NULL DEFAULT 'both',
    `status`            ENUM('draft','sent','scheduled') NOT NULL DEFAULT 'sent',
    `total_recipients`  INT          NOT NULL DEFAULT 0,
    `sent_count`        INT          NOT NULL DEFAULT 0,
    `created_by`        INT                   DEFAULT NULL,
    `sent_at`           DATETIME              DEFAULT NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_comm_org`    (`org_id`),
    KEY `idx_comm_sent`   (`sent_at`),
    KEY `idx_comm_class`  (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 23. sch_budget_items
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_budget_items` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `org_id`            INT           NOT NULL,
    `academic_year_id`  INT                    DEFAULT NULL,
    `term_id`           INT                    DEFAULT NULL,
    `category`          ENUM('income','expense') NOT NULL DEFAULT 'expense',
    `type`              VARCHAR(100)  NOT NULL,
    `description`       VARCHAR(255)  NOT NULL,
    `budgeted_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `actual_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `transaction_date`  DATE          NOT NULL,
    `recorded_by`       INT                    DEFAULT NULL,
    `notes`             TEXT                   DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME               DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_budget_org`      (`org_id`),
    KEY `idx_budget_year`     (`academic_year_id`),
    KEY `idx_budget_category` (`org_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 24. sch_promotions (end-of-year student promotion log)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_promotions` (
    `id`               INT      NOT NULL AUTO_INCREMENT,
    `org_id`           INT      NOT NULL,
    `student_id`       INT      NOT NULL,
    `from_class_id`    INT               DEFAULT NULL,
    `to_class_id`      INT               DEFAULT NULL,
    `academic_year_id` INT               DEFAULT NULL,
    `promotion_type`   ENUM('promoted','graduated','retained','transferred') NOT NULL DEFAULT 'promoted',
    `promoted_by`      INT               DEFAULT NULL,
    `notes`            TEXT,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_year` (`org_id`, `student_id`, `academic_year_id`),
    KEY `idx_promo_org`     (`org_id`),
    KEY `idx_promo_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 25. sch_subject_teachers (alternative mapping table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sch_subject_teachers` (
    `id`               INT      NOT NULL AUTO_INCREMENT,
    `org_id`           INT      NOT NULL,
    `class_id`         INT      NOT NULL,
    `subject_id`       INT      NOT NULL,
    `teacher_id`       INT               DEFAULT NULL,
    `academic_year_id` INT               DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cls_sub_year` (`org_id`, `class_id`, `subject_id`, `academic_year_id`),
    KEY `idx_st_org`     (`org_id`),
    KEY `idx_st_class`   (`class_id`),
    KEY `idx_st_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 26. sch_classes — ensure all columns used across modules exist
-- ────────────────────────────────────────────────────────────
ALTER TABLE `sch_classes`
    ADD COLUMN IF NOT EXISTS `curriculum`       VARCHAR(50)   NOT NULL DEFAULT 'IB',
    ADD COLUMN IF NOT EXISTS `level`            VARCHAR(50)            DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `room`             VARCHAR(50)            DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `class_teacher_id` INT                    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `academic_year_id` INT                    DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 27. organizations — settings columns
-- ────────────────────────────────────────────────────────────
ALTER TABLE `organizations`
    ADD COLUMN IF NOT EXISTS `website`       VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `primary_color` VARCHAR(30)  DEFAULT '#1A8A4E',
    ADD COLUMN IF NOT EXISTS `brand_tagline` VARCHAR(255) DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'school_modules_migration applied successfully.' AS migration_status;
