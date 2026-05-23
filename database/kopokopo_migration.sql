-- ── KopoKopo M-Pesa Migration ─────────────────────────────────────
-- Run once. Extends mpesa_pending for KopoKopo payment IDs and
-- adds a payment_callbacks audit table.

-- Extend mpesa_pending to hold KopoKopo-specific columns
ALTER TABLE mpesa_pending
    ADD COLUMN IF NOT EXISTS status         VARCHAR(20)  NOT NULL DEFAULT 'pending'
        COMMENT 'pending | completed | failed',
    ADD COLUMN IF NOT EXISTS kopokopo_id    VARCHAR(255) DEFAULT NULL
        COMMENT 'KopoKopo payment_id from Location header',
    ADD COLUMN IF NOT EXISTS mpesa_receipt  VARCHAR(100) DEFAULT NULL
        COMMENT 'M-Pesa receipt number from webhook';

-- KopoKopo callback audit log (one row per webhook received)
CREATE TABLE IF NOT EXISTS payment_callbacks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    provider      VARCHAR(20)   NOT NULL DEFAULT 'kopokopo',
    event_type    VARCHAR(100)  DEFAULT NULL,
    checkout_id   VARCHAR(255)  DEFAULT NULL   COMMENT 'KopoKopo payment_id',
    invoice_id    INT           DEFAULT NULL,
    org_id        INT           DEFAULT NULL,
    amount        DECIMAL(12,2) DEFAULT NULL,
    currency      VARCHAR(10)   NOT NULL DEFAULT 'KES',
    phone         VARCHAR(30)   DEFAULT NULL,
    mpesa_receipt VARCHAR(100)  DEFAULT NULL,
    status        VARCHAR(50)   DEFAULT NULL,
    raw_payload   MEDIUMTEXT    DEFAULT NULL,
    processed_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_checkout (checkout_id(100)),
    INDEX idx_invoice  (invoice_id),
    INDEX idx_org      (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
