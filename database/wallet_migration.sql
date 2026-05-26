-- ── Wallet Migration ─────────────────────────────────────────────
-- Run once. Adds wallet balance to organizations and a ledger table.

-- Wallet balance on each org (source of truth = SUM of wallet_transactions)
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00
        COMMENT 'Pre-loaded KES balance, auto-decremented when invoices are paid from wallet';

-- Add rating column to support_tickets (for P2 satisfaction rating)
ALTER TABLE support_tickets
    ADD COLUMN IF NOT EXISTS rating TINYINT(1) DEFAULT NULL
        COMMENT '1-5 star satisfaction rating given when closing ticket';

-- Wallet transaction ledger
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    type          ENUM('topup','deduction','refund') NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00  COMMENT 'Wallet balance after this transaction',
    description   VARCHAR(255)  DEFAULT NULL,
    invoice_id    INT           DEFAULT NULL            COMMENT 'Set for deductions linked to an invoice',
    checkout_id   VARCHAR(255)  DEFAULT NULL            COMMENT 'KopoKopo payment_id for top-ups',
    mpesa_receipt VARCHAR(100)  DEFAULT NULL,
    status        ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_org    (org_id),
    INDEX idx_co     (checkout_id(100)),
    INDEX idx_inv    (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
