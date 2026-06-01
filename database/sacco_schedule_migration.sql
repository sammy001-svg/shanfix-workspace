-- ── SACCO Loan Amortization Schedule Migration ──────────────────────────────
-- Compatible with MySQL 5.7+ and MariaDB / cPanel hosting.
-- Run ONCE after the base SACCO schema has been applied.

CREATE TABLE IF NOT EXISTS sacco_loan_schedule (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    loan_id         INT NOT NULL,
    installment_no  SMALLINT UNSIGNED NOT NULL,
    due_date        DATE NOT NULL,
    amount_due      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    principal       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    interest        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending','paid','partial','overdue') NOT NULL DEFAULT 'pending',
    paid_at         DATE DEFAULT NULL,
    repayment_id    INT DEFAULT NULL COMMENT 'sacco_loan_repayments.id that settled this row',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_loan   (loan_id),
    INDEX idx_status (loan_id, status),
    INDEX idx_due    (org_id, due_date, status),
    UNIQUE KEY uq_installment (loan_id, installment_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
