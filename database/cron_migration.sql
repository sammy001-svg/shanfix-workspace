-- ── Cron Deduplication Log Migration ─────────────────────────────
-- Run once. Prevents scheduled emails from firing more than once
-- per event+subscription/invoice per billing period.

CREATE TABLE IF NOT EXISTS scheduled_email_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    event_type   VARCHAR(100) NOT NULL,   -- e.g. 'sub_reminder_7d', 'trial_reminder_3d'
    reference_id INT          NOT NULL,   -- subscription.id or invoice.id
    period_date  DATE         NOT NULL,   -- the relevant date (ends_at, trial_ends_at, due_date)
    sent_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_event_ref_period (event_type, reference_id, period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
