-- ============================================================
-- CRM Phase 2 Migration
-- New tables: companies, products, quotes, quote_items,
--             tasks, campaigns, campaign_contacts
-- Also: adds company_id FK column to crm_contacts & crm_deals
-- Run ONCE on a deployed database (safe — uses IF NOT EXISTS)
-- ============================================================

-- 1. Companies / Accounts
CREATE TABLE IF NOT EXISTS crm_companies (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(255) NOT NULL,
    industry        VARCHAR(100),
    website         VARCHAR(255),
    phone           VARCHAR(50),
    email           VARCHAR(100),
    address         TEXT,
    city            VARCHAR(100),
    country         VARCHAR(100),
    employees       INT DEFAULT NULL,
    annual_revenue  DECIMAL(15,2) DEFAULT NULL,
    notes           TEXT,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crm_companies_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add company_id to crm_contacts (ignore error if already exists)
ALTER TABLE crm_contacts ADD COLUMN IF NOT EXISTS company_id INT DEFAULT NULL AFTER org_id;

-- 3. Add company_id to crm_deals (ignore error if already exists)
ALTER TABLE crm_deals ADD COLUMN IF NOT EXISTS company_id INT DEFAULT NULL AFTER contact_id;

-- 4. Products / Services catalog
CREATE TABLE IF NOT EXISTS crm_products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255) NOT NULL,
    sku         VARCHAR(100),
    description TEXT,
    unit_price  DECIMAL(12,2) DEFAULT 0.00,
    unit        VARCHAR(50) DEFAULT 'unit',
    category    VARCHAR(100),
    tax_rate    DECIMAL(5,2) DEFAULT 0.00,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_products_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Quotes / Proposals
CREATE TABLE IF NOT EXISTS crm_quotes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    quote_number  VARCHAR(50) NOT NULL,
    contact_id    INT DEFAULT NULL,
    deal_id       INT DEFAULT NULL,
    company_id    INT DEFAULT NULL,
    title         VARCHAR(255) NOT NULL,
    subtotal      DECIMAL(12,2) DEFAULT 0.00,
    tax_rate      DECIMAL(5,2) DEFAULT 16.00,
    tax_amount    DECIMAL(12,2) DEFAULT 0.00,
    discount      DECIMAL(12,2) DEFAULT 0.00,
    total         DECIMAL(12,2) DEFAULT 0.00,
    status        ENUM('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
    valid_until   DATE DEFAULT NULL,
    notes         TEXT,
    terms         TEXT,
    created_by    INT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crm_quotes_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Quote Line Items
CREATE TABLE IF NOT EXISTS crm_quote_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    quote_id    INT NOT NULL,
    product_id  INT DEFAULT NULL,
    description VARCHAR(500) NOT NULL,
    qty         DECIMAL(10,2) DEFAULT 1.00,
    unit_price  DECIMAL(12,2) DEFAULT 0.00,
    discount    DECIMAL(5,2) DEFAULT 0.00,
    total       DECIMAL(12,2) DEFAULT 0.00,
    sort_order  INT DEFAULT 0,
    FOREIGN KEY (quote_id) REFERENCES crm_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Tasks (dedicated, separate from activities)
CREATE TABLE IF NOT EXISTS crm_tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    contact_id  INT DEFAULT NULL,
    deal_id     INT DEFAULT NULL,
    company_id  INT DEFAULT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    priority    ENUM('low','medium','high','urgent') DEFAULT 'medium',
    due_date    DATE DEFAULT NULL,
    due_time    TIME DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    status      ENUM('todo','in_progress','done','cancelled') DEFAULT 'todo',
    created_by  INT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crm_tasks_org (org_id),
    INDEX idx_crm_tasks_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Campaigns
CREATE TABLE IF NOT EXISTS crm_campaigns (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    name         VARCHAR(255) NOT NULL,
    type         ENUM('email','sms','social','event','other') DEFAULT 'email',
    status       ENUM('draft','active','paused','completed','cancelled') DEFAULT 'draft',
    target_type  ENUM('all','customers','leads','partners','vendors') DEFAULT 'all',
    subject      VARCHAR(255),
    content      LONGTEXT,
    start_date   DATE DEFAULT NULL,
    end_date     DATE DEFAULT NULL,
    budget       DECIMAL(12,2) DEFAULT 0.00,
    sent_count   INT DEFAULT 0,
    open_count   INT DEFAULT 0,
    click_count  INT DEFAULT 0,
    notes        TEXT,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crm_campaigns_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
