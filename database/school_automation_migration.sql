-- ============================================================
-- School Module: Automation Rules + Message Log
-- Run after school_module_migration.sql
-- ============================================================

-- ── Automation Rules ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_auto_rules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id      INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    event_type  ENUM('fee_due_soon','fee_overdue','attendance_absent','exam_result','birthday','custom')
                NOT NULL DEFAULT 'fee_due_soon',
    channel     ENUM('email','sms','both') NOT NULL DEFAULT 'email',
    recipients  ENUM('parents','students','staff','all_parents','class_parents') NOT NULL DEFAULT 'parents',
    days_offset INT NOT NULL DEFAULT 3
                COMMENT 'Days before(-) or after(+) the trigger date',
    subject     VARCHAR(200) DEFAULT NULL,
    template    TEXT NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    last_run    DATETIME DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auto_rules_org (org_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Message Send Log ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sch_message_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    rule_id         INT UNSIGNED DEFAULT NULL,
    channel         ENUM('email','sms','in_app') NOT NULL DEFAULT 'email',
    recipient_name  VARCHAR(120) DEFAULT NULL,
    recipient_addr  VARCHAR(200) DEFAULT NULL
                    COMMENT 'Email address or phone number',
    subject         VARCHAR(200) DEFAULT NULL,
    message         TEXT NOT NULL,
    status          ENUM('sent','failed','queued') NOT NULL DEFAULT 'queued',
    error_msg       TEXT,
    sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msg_log_org    (org_id),
    INDEX idx_msg_log_rule   (rule_id),
    INDEX idx_msg_log_status (status),
    INDEX idx_msg_log_date   (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
