-- ── Onboarding Migration ─────────────────────────────────────────
-- Run once. Adds onboarding flag to users table.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_onboarded TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = completed onboarding wizard';

-- Mark all existing users as already onboarded (only new registrations need the wizard)
UPDATE users SET is_onboarded = 1 WHERE is_onboarded = 0;
