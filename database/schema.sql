-- ============================================================
--  SHANFIX WORKSPACE — Complete Database Schema
--  Database: MySQL  |  Charset: utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- CORE TABLES
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS organizations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255)    NOT NULL,
    email         VARCHAR(255),
    phone         VARCHAR(25),
    address       TEXT,
    city          VARCHAR(100),
    country       VARCHAR(100)    DEFAULT 'Kenya',
    logo          VARCHAR(500),
    slug          VARCHAR(150)    UNIQUE,
    status        ENUM('active','inactive','suspended') DEFAULT 'active',
    created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT,
    name                VARCHAR(255)    NOT NULL,
    email               VARCHAR(255)    UNIQUE NOT NULL,
    password            VARCHAR(255)    NOT NULL,
    phone               VARCHAR(25),
    avatar              VARCHAR(500),
    role                ENUM('super_admin','client_admin','staff') DEFAULT 'staff',
    status              ENUM('active','inactive','suspended')      DEFAULT 'active',
    email_verified_at   TIMESTAMP NULL,
    remember_token      VARCHAR(128),
    last_login          TIMESTAMP NULL,
    created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS modules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(100)    UNIQUE NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    description     TEXT,
    icon            VARCHAR(100),
    color           VARCHAR(20)     DEFAULT '#1A8A4E',
    category        VARCHAR(100),
    monthly_price   DECIMAL(12,2)   DEFAULT 0.00,
    annual_price    DECIMAL(12,2)   DEFAULT 0.00,
    status          ENUM('active','inactive') DEFAULT 'active',
    sort_order      INT             DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_plans (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)    NOT NULL,
    description     TEXT,
    max_users       INT             DEFAULT 5,
    max_modules     INT             DEFAULT 3,
    price_monthly   DECIMAL(12,2),
    price_annual    DECIMAL(12,2),
    features        JSON,
    is_popular      TINYINT(1)      DEFAULT 0,
    status          ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT             NOT NULL,
    plan_id         INT,
    billing_cycle   ENUM('monthly','annual') DEFAULT 'monthly',
    amount          DECIMAL(12,2),
    status          ENUM('active','trial','expired','cancelled') DEFAULT 'trial',
    trial_ends_at   TIMESTAMP NULL,
    starts_at       TIMESTAMP NULL,
    ends_at         TIMESTAMP NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id)   REFERENCES organizations(id)      ON DELETE CASCADE,
    FOREIGN KEY (plan_id)  REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_modules (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id     INT NOT NULL,
    module_id           INT NOT NULL,
    status              ENUM('active','inactive') DEFAULT 'active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)       REFERENCES modules(id)       ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT             NOT NULL,
    subscription_id INT,
    module_id       INT             DEFAULT NULL,
    invoice_number  VARCHAR(60)     UNIQUE,
    amount          DECIMAL(12,2),
    tax             DECIMAL(12,2)   DEFAULT 0.00,
    total           DECIMAL(12,2),
    status          ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
    due_date        DATE,
    paid_at         TIMESTAMP NULL,
    notes           TEXT,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_module_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id  INT NOT NULL,
    module_id   INT NOT NULL,
    amount      DECIMAL(12,2) DEFAULT 0.00,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)  REFERENCES modules(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT,
    user_id     INT,
    action      VARCHAR(255),
    module      VARCHAR(100),
    description TEXT,
    ip          VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: ACCOUNTING & BOOKKEEPING
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS acc_accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    code        VARCHAR(30),
    name        VARCHAR(255)    NOT NULL,
    type        ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    parent_id   INT,
    balance     DECIMAL(15,2)   DEFAULT 0.00,
    description TEXT,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acc_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    reference       VARCHAR(100),
    date            DATE NOT NULL,
    description     TEXT,
    total_debit     DECIMAL(15,2),
    total_credit    DECIMAL(15,2),
    status          ENUM('draft','posted','voided') DEFAULT 'posted',
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acc_transaction_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT NOT NULL,
    account_id      INT NOT NULL,
    description     VARCHAR(255),
    debit           DECIMAL(15,2) DEFAULT 0.00,
    credit          DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (transaction_id) REFERENCES acc_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acc_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    invoice_no      VARCHAR(50),
    customer_name   VARCHAR(255),
    customer_email  VARCHAR(255),
    issue_date      DATE,
    due_date        DATE,
    subtotal        DECIMAL(12,2),
    tax_rate        DECIMAL(5,2)  DEFAULT 16.00,
    tax_amount      DECIMAL(12,2),
    total           DECIMAL(12,2),
    paid            DECIMAL(12,2) DEFAULT 0.00,
    balance         DECIMAL(12,2),
    status          ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acc_expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    account_id      INT,
    category        VARCHAR(100),
    description     TEXT,
    amount          DECIMAL(12,2),
    date            DATE,
    payment_method  VARCHAR(50),
    reference       VARCHAR(100),
    receipt         VARCHAR(500),
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: CRM
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS crm_contacts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    first_name  VARCHAR(100),
    last_name   VARCHAR(100),
    email       VARCHAR(255),
    phone       VARCHAR(25),
    company     VARCHAR(255),
    position    VARCHAR(100),
    type        ENUM('lead','contact','customer','partner') DEFAULT 'contact',
    source      VARCHAR(100),
    assigned_to INT,
    tags        VARCHAR(500),
    notes       TEXT,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_deals (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    title               VARCHAR(255) NOT NULL,
    contact_id          INT,
    value               DECIMAL(15,2),
    stage               VARCHAR(100),
    probability         INT          DEFAULT 50,
    expected_close      DATE,
    assigned_to         INT,
    description         TEXT,
    status              ENUM('open','won','lost') DEFAULT 'open',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_activities (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    contact_id  INT,
    deal_id     INT,
    type        ENUM('call','email','meeting','note','task') DEFAULT 'note',
    subject     VARCHAR(255),
    description TEXT,
    due_date    DATETIME,
    done        TINYINT(1) DEFAULT 0,
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: SALES
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sales_customers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    email       VARCHAR(255),
    phone       VARCHAR(25),
    address     TEXT,
    type        ENUM('individual','business') DEFAULT 'individual',
    credit_limit DECIMAL(12,2) DEFAULT 0.00,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    sku         VARCHAR(100),
    category    VARCHAR(100),
    unit        VARCHAR(30),
    price       DECIMAL(12,2),
    tax_rate    DECIMAL(5,2) DEFAULT 0.00,
    stock       INT DEFAULT 0,
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_quotes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    quote_no    VARCHAR(50),
    customer_id INT,
    quote_date  DATE,
    valid_until DATE,
    subtotal    DECIMAL(12,2),
    discount    DECIMAL(12,2) DEFAULT 0.00,
    tax         DECIMAL(12,2) DEFAULT 0.00,
    total       DECIMAL(12,2),
    status      ENUM('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    order_no        VARCHAR(50),
    customer_id     INT,
    quote_id        INT,
    order_date      DATE,
    delivery_date   DATE,
    subtotal        DECIMAL(12,2),
    discount        DECIMAL(12,2) DEFAULT 0.00,
    tax             DECIMAL(12,2) DEFAULT 0.00,
    total           DECIMAL(12,2),
    paid            DECIMAL(12,2) DEFAULT 0.00,
    status          ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: MEETINGS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS meetings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    organizer_id    INT,
    location        VARCHAR(255),
    meeting_date    DATE,
    start_time      TIME,
    end_time        TIME,
    type            ENUM('physical','virtual','hybrid') DEFAULT 'physical',
    meeting_link    VARCHAR(500),
    status          ENUM('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS meeting_attendees (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id  INT NOT NULL,
    user_id     INT,
    name        VARCHAR(255),
    email       VARCHAR(255),
    rsvp        ENUM('pending','accepted','declined','tentative') DEFAULT 'pending',
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS meeting_minutes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id      INT NOT NULL,
    content         TEXT,
    action_items    TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: SCHOOL MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sch_academic_years (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(50),
    start_date  DATE,
    end_date    DATE,
    status      ENUM('active','inactive') DEFAULT 'inactive'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sch_classes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(100) NOT NULL,
    level           VARCHAR(50),
    capacity        INT DEFAULT 40,
    class_teacher   INT,
    academic_year_id INT,
    status          ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sch_students (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    admission_no    VARCHAR(50),
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    gender          ENUM('male','female') DEFAULT 'male',
    dob             DATE,
    class_id        INT,
    parent_name     VARCHAR(255),
    parent_phone    VARCHAR(25),
    parent_email    VARCHAR(255),
    address         TEXT,
    photo           VARCHAR(500),
    status          ENUM('active','inactive','graduated','transferred') DEFAULT 'active',
    admitted_on     DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sch_subjects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    code        VARCHAR(30),
    class_id    INT,
    teacher_id  INT,
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sch_fees (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    student_id  INT NOT NULL,
    term        VARCHAR(50),
    year        YEAR,
    fee_type    VARCHAR(100),
    amount      DECIMAL(10,2),
    paid        DECIMAL(10,2) DEFAULT 0.00,
    balance     DECIMAL(10,2),
    due_date    DATE,
    status      ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sch_grades (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    student_id      INT NOT NULL,
    subject_id      INT,
    term            VARCHAR(50),
    year            YEAR,
    cat_score       DECIMAL(5,2),
    exam_score      DECIMAL(5,2),
    total_score     DECIMAL(5,2),
    grade           VARCHAR(5),
    remarks         VARCHAR(255),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: HEALTH MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS health_patients (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    patient_no          VARCHAR(50),
    first_name          VARCHAR(100),
    last_name           VARCHAR(100),
    gender              ENUM('male','female','other') DEFAULT 'male',
    dob                 DATE,
    phone               VARCHAR(25),
    email               VARCHAR(255),
    address             TEXT,
    blood_group         VARCHAR(5),
    allergies           TEXT,
    chronic_conditions  TEXT,
    emergency_contact   VARCHAR(255),
    emergency_phone     VARCHAR(25),
    insurance_provider  VARCHAR(255),
    insurance_no        VARCHAR(100),
    status              ENUM('active','inactive') DEFAULT 'active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_doctors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    user_id         INT,
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    specialization  VARCHAR(255),
    phone           VARCHAR(25),
    email           VARCHAR(255),
    status          ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_appointments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    patient_id  INT NOT NULL,
    doctor_id   INT,
    date        DATE NOT NULL,
    time        TIME,
    type        VARCHAR(100),
    complaint   TEXT,
    status      ENUM('scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    patient_id      INT NOT NULL,
    appointment_id  INT,
    doctor_id       INT,
    date            DATE,
    diagnosis       TEXT,
    treatment       TEXT,
    prescription    TEXT,
    notes           TEXT,
    follow_up_date  DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: POINT OF SALE (POS)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS pos_categories (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    org_id  INT NOT NULL,
    name    VARCHAR(100),
    status  ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    category_id INT,
    barcode     VARCHAR(100),
    name        VARCHAR(255) NOT NULL,
    unit        VARCHAR(30),
    price       DECIMAL(10,2),
    cost        DECIMAL(10,2),
    stock       INT DEFAULT 0,
    reorder_level INT DEFAULT 5,
    image       VARCHAR(500),
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    receipt_no      VARCHAR(50),
    cashier_id      INT,
    customer_name   VARCHAR(255),
    customer_phone  VARCHAR(25),
    subtotal        DECIMAL(10,2),
    discount        DECIMAL(10,2) DEFAULT 0.00,
    tax             DECIMAL(10,2) DEFAULT 0.00,
    total           DECIMAL(10,2),
    paid            DECIMAL(10,2),
    change_amount   DECIMAL(10,2),
    payment_method  ENUM('cash','mpesa','card','bank','credit') DEFAULT 'cash',
    status          ENUM('completed','refunded','voided') DEFAULT 'completed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_sale_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sale_id         INT NOT NULL,
    product_id      INT,
    product_name    VARCHAR(255),
    quantity        DECIMAL(10,3),
    price           DECIMAL(10,2),
    discount        DECIMAL(10,2) DEFAULT 0.00,
    subtotal        DECIMAL(10,2),
    FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: SACCO
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sacco_members (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    member_no       VARCHAR(50),
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    id_number       VARCHAR(50),
    phone           VARCHAR(25),
    email           VARCHAR(255),
    occupation      VARCHAR(100),
    address         TEXT,
    shares          INT DEFAULT 0,
    share_value     DECIMAL(12,2) DEFAULT 0.00,
    total_savings   DECIMAL(15,2) DEFAULT 0.00,
    status          ENUM('active','inactive','suspended') DEFAULT 'active',
    joined_at       DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sacco_savings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    member_id       INT NOT NULL,
    type            ENUM('deposit','withdrawal') DEFAULT 'deposit',
    amount          DECIMAL(15,2),
    balance_after   DECIMAL(15,2),
    reference       VARCHAR(100),
    description     TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sacco_loans (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    member_id       INT NOT NULL,
    loan_no         VARCHAR(50),
    amount          DECIMAL(15,2),
    interest_rate   DECIMAL(5,2),
    term_months     INT,
    monthly_payment DECIMAL(15,2),
    total_paid      DECIMAL(15,2) DEFAULT 0.00,
    balance         DECIMAL(15,2),
    purpose         TEXT,
    guarantor_name  VARCHAR(255),
    guarantor_phone VARCHAR(25),
    status          ENUM('pending','approved','active','completed','defaulted') DEFAULT 'pending',
    approved_by     INT,
    disbursed_at    DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sacco_loan_repayments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    loan_id     INT NOT NULL,
    amount      DECIMAL(15,2),
    principal   DECIMAL(15,2),
    interest    DECIMAL(15,2),
    balance     DECIMAL(15,2),
    payment_date DATE,
    reference   VARCHAR(100),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES sacco_loans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: RENTAL MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rental_properties (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    address     TEXT,
    type        ENUM('residential','commercial','mixed') DEFAULT 'residential',
    total_units INT DEFAULT 1,
    image       VARCHAR(500),
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_units (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    property_id     INT NOT NULL,
    unit_no         VARCHAR(50),
    type            VARCHAR(100),
    bedrooms        INT DEFAULT 1,
    bathrooms       INT DEFAULT 1,
    size_sqm        DECIMAL(8,2),
    floor           INT DEFAULT 0,
    rent            DECIMAL(10,2),
    deposit         DECIMAL(10,2),
    amenities       TEXT,
    status          ENUM('vacant','occupied','maintenance') DEFAULT 'vacant',
    FOREIGN KEY (property_id) REFERENCES rental_properties(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_tenants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    unit_id     INT NOT NULL,
    first_name  VARCHAR(100),
    last_name   VARCHAR(100),
    id_number   VARCHAR(50),
    phone       VARCHAR(25),
    email       VARCHAR(255),
    employer    VARCHAR(255),
    lease_start DATE,
    lease_end   DATE,
    deposit     DECIMAL(10,2),
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    tenant_id       INT NOT NULL,
    unit_id         INT NOT NULL,
    amount          DECIMAL(10,2),
    period          VARCHAR(20),
    payment_date    DATE,
    payment_method  VARCHAR(50),
    reference       VARCHAR(100),
    status          ENUM('paid','pending','partial') DEFAULT 'paid',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: CHURCH MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS church_members (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    member_no   VARCHAR(50),
    first_name  VARCHAR(100),
    last_name   VARCHAR(100),
    gender      ENUM('male','female') DEFAULT 'male',
    dob         DATE,
    phone       VARCHAR(25),
    email       VARCHAR(255),
    address     TEXT,
    marital_status VARCHAR(30),
    cell_group  VARCHAR(100),
    department  VARCHAR(100),
    baptized    TINYINT(1) DEFAULT 0,
    status      ENUM('active','inactive','visitor') DEFAULT 'active',
    joined_at   DATE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS church_offerings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    member_id       INT,
    type            ENUM('tithe','offering','first_fruit','building_fund','mission','welfare','other') DEFAULT 'offering',
    amount          DECIMAL(10,2),
    date            DATE,
    payment_method  VARCHAR(50),
    reference       VARCHAR(100),
    notes           TEXT,
    received_by     INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS church_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    title       VARCHAR(255),
    description TEXT,
    location    VARCHAR(255),
    start_date  DATETIME,
    end_date    DATETIME,
    status      ENUM('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: FINANCE MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS fin_accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    type        ENUM('bank','cash','mobile_money','investment') DEFAULT 'bank',
    account_no  VARCHAR(100),
    balance     DECIMAL(15,2) DEFAULT 0.00,
    currency    VARCHAR(10) DEFAULT 'KES',
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fin_categories (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    org_id  INT NOT NULL,
    name    VARCHAR(100),
    type    ENUM('income','expense') DEFAULT 'expense',
    color   VARCHAR(20)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fin_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    org_id         INT NOT NULL,
    account_id     INT,
    to_account_id  INT DEFAULT NULL,
    category_id    INT,
    type           ENUM('income','expense','transfer') DEFAULT 'income',
    amount         DECIMAL(15,2),
    description    TEXT,
    date           DATE,
    reference      VARCHAR(100),
    attachment     VARCHAR(500),
    created_by     INT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fin_budgets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    category_id INT,
    period      VARCHAR(20),
    amount      DECIMAL(15,2),
    spent       DECIMAL(15,2) DEFAULT 0.00,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: HOTEL MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS hotel_room_types (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(100),
    description     TEXT,
    price_per_night DECIMAL(10,2),
    capacity        INT DEFAULT 2,
    amenities       TEXT,
    image           VARCHAR(500)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotel_rooms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    room_no     VARCHAR(20),
    type_id     INT,
    floor       INT,
    status      ENUM('available','occupied','maintenance','reserved') DEFAULT 'available',
    notes       VARCHAR(500),
    FOREIGN KEY (type_id) REFERENCES hotel_room_types(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotel_guests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    first_name  VARCHAR(100),
    last_name   VARCHAR(100),
    id_number   VARCHAR(50),
    nationality VARCHAR(100),
    phone       VARCHAR(25),
    email       VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotel_bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    booking_no      VARCHAR(50),
    guest_id        INT,
    room_id         INT,
    check_in        DATE,
    check_out       DATE,
    nights          INT,
    adults          INT DEFAULT 1,
    children        INT DEFAULT 0,
    rate_per_night  DECIMAL(10,2),
    total_amount    DECIMAL(10,2),
    paid_amount     DECIMAL(10,2) DEFAULT 0.00,
    extra_charges   DECIMAL(10,2) DEFAULT 0.00,
    special_requests TEXT,
    status          ENUM('confirmed','checked_in','checked_out','cancelled','no_show') DEFAULT 'confirmed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES hotel_rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: SALON & BARBERSHOP
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS salon_services (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    category    VARCHAR(100),
    price       DECIMAL(10,2),
    duration_min INT DEFAULT 30,
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salon_staff (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    user_id     INT,
    first_name  VARCHAR(100),
    last_name   VARCHAR(100),
    phone       VARCHAR(25),
    speciality  VARCHAR(255),
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salon_clients (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    phone       VARCHAR(25),
    email       VARCHAR(255),
    gender      ENUM('male','female','other') DEFAULT 'female',
    dob         DATE,
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salon_appointments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    client_id           INT,
    service_id          INT,
    staff_id            INT,
    appointment_date    DATE,
    appointment_time    TIME,
    status              ENUM('scheduled','in_progress','completed','cancelled','no_show') DEFAULT 'scheduled',
    total_amount        DECIMAL(10,2),
    paid                TINYINT(1) DEFAULT 0,
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: RETAIL & WHOLESALE
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS retail_categories (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    org_id  INT NOT NULL,
    name    VARCHAR(100),
    status  ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS retail_products (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    org_id              INT NOT NULL,
    category_id         INT,
    sku                 VARCHAR(100),
    barcode             VARCHAR(100),
    name                VARCHAR(255),
    unit                VARCHAR(30),
    retail_price        DECIMAL(10,2),
    wholesale_price     DECIMAL(10,2),
    cost_price          DECIMAL(10,2),
    stock               INT DEFAULT 0,
    reorder_level       INT DEFAULT 10,
    status              ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS retail_suppliers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    name            VARCHAR(255),
    contact_person  VARCHAR(255),
    phone           VARCHAR(25),
    email           VARCHAR(255),
    address         TEXT,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS retail_purchase_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    po_no           VARCHAR(50),
    supplier_id     INT,
    order_date      DATE,
    expected_date   DATE,
    total_amount    DECIMAL(15,2),
    status          ENUM('draft','ordered','received','cancelled') DEFAULT 'draft',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: TOUR & TRAVEL
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS tour_destinations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(255),
    country     VARCHAR(100),
    description TEXT,
    image       VARCHAR(500),
    status      ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tour_packages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    destination_id  INT,
    name            VARCHAR(255),
    description     TEXT,
    duration_days   INT,
    price_per_adult DECIMAL(10,2),
    price_per_child DECIMAL(10,2),
    max_pax         INT,
    includes        TEXT,
    excludes        TEXT,
    image           VARCHAR(500),
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tour_bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    booking_no      VARCHAR(50),
    package_id      INT,
    customer_name   VARCHAR(255),
    customer_phone  VARCHAR(25),
    customer_email  VARCHAR(255),
    travel_date     DATE,
    adults          INT DEFAULT 1,
    children        INT DEFAULT 0,
    total_amount    DECIMAL(10,2),
    paid_amount     DECIMAL(10,2) DEFAULT 0.00,
    special_requests TEXT,
    status          ENUM('pending','confirmed','completed','cancelled') DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: EVENTS MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    title           VARCHAR(255),
    description     TEXT,
    venue           VARCHAR(255),
    venue_capacity  INT,
    start_date      DATETIME,
    end_date        DATETIME,
    banner          VARCHAR(500),
    ticket_price    DECIMAL(10,2) DEFAULT 0.00,
    is_free         TINYINT(1) DEFAULT 1,
    status          ENUM('draft','published','ongoing','completed','cancelled') DEFAULT 'draft',
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_tickets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    event_id        INT NOT NULL,
    ticket_type     VARCHAR(100),
    price           DECIMAL(10,2) DEFAULT 0.00,
    quantity        INT,
    sold            INT DEFAULT 0,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_attendees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    event_id        INT NOT NULL,
    ticket_id       INT,
    name            VARCHAR(255),
    email           VARCHAR(255),
    phone           VARCHAR(25),
    ticket_no       VARCHAR(50),
    checked_in      TINYINT(1) DEFAULT 0,
    checked_in_at   TIMESTAMP NULL,
    payment_status  ENUM('paid','pending','free') DEFAULT 'free',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: MANUFACTURING
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS mfg_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    code            VARCHAR(50),
    name            VARCHAR(255),
    unit            VARCHAR(30),
    selling_price   DECIMAL(10,2),
    cost_price      DECIMAL(10,2),
    stock           INT DEFAULT 0,
    status          ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mfg_raw_materials (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    code            VARCHAR(50),
    name            VARCHAR(255),
    unit            VARCHAR(30),
    stock           DECIMAL(12,3) DEFAULT 0.000,
    reorder_level   DECIMAL(12,3) DEFAULT 0.000,
    unit_cost       DECIMAL(10,2),
    supplier        VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mfg_bom (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    product_id      INT NOT NULL,
    material_id     INT NOT NULL,
    quantity_needed DECIMAL(12,3),
    unit            VARCHAR(30),
    FOREIGN KEY (product_id)  REFERENCES mfg_products(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES mfg_raw_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mfg_production_orders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    order_no    VARCHAR(50),
    product_id  INT,
    quantity    INT,
    start_date  DATE,
    end_date    DATE,
    status      ENUM('planned','in_progress','completed','cancelled') DEFAULT 'planned',
    notes       TEXT,
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: HRM
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS hrm_departments (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    org_id  INT NOT NULL,
    name    VARCHAR(255) NOT NULL,
    head_id INT,
    status  ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hrm_employees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    user_id         INT,
    employee_no     VARCHAR(50),
    department_id   INT,
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    email           VARCHAR(255),
    phone           VARCHAR(25),
    id_number       VARCHAR(50),
    gender          ENUM('male','female') DEFAULT 'male',
    dob             DATE,
    position        VARCHAR(100),
    employment_type ENUM('full_time','part_time','contract','intern') DEFAULT 'full_time',
    salary          DECIMAL(15,2),
    bank_name       VARCHAR(100),
    bank_account    VARCHAR(50),
    date_hired      DATE,
    photo           VARCHAR(500),
    status          ENUM('active','inactive','on_leave','terminated') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hrm_leave_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(100),
    days_allowed INT DEFAULT 21,
    is_paid     TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hrm_leave_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    leave_type_id   INT,
    start_date      DATE,
    end_date        DATE,
    days            INT,
    reason          TEXT,
    status          ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by     INT,
    approved_at     TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hrm_payroll (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    period          VARCHAR(20),
    basic_salary    DECIMAL(15,2),
    allowances      DECIMAL(15,2) DEFAULT 0.00,
    overtime        DECIMAL(15,2) DEFAULT 0.00,
    gross_salary    DECIMAL(15,2),
    paye            DECIMAL(15,2) DEFAULT 0.00,
    nhif            DECIMAL(15,2) DEFAULT 0.00,
    nssf            DECIMAL(15,2) DEFAULT 0.00,
    other_deductions DECIMAL(15,2) DEFAULT 0.00,
    total_deductions DECIMAL(15,2),
    net_salary      DECIMAL(15,2),
    status          ENUM('draft','processed','paid') DEFAULT 'draft',
    payment_date    DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hrm_attendance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    date            DATE,
    check_in        TIME,
    check_out       TIME,
    hours_worked    DECIMAL(5,2),
    status          ENUM('present','absent','late','half_day','leave') DEFAULT 'present',
    notes           VARCHAR(255)
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: CAR YARD MANAGEMENT
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS caryard_vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    stock_no        VARCHAR(50),
    make            VARCHAR(100),
    model           VARCHAR(100),
    year            YEAR,
    color           VARCHAR(50),
    body_type       VARCHAR(50),
    mileage         INT,
    engine_cc       INT,
    transmission    ENUM('manual','automatic') DEFAULT 'manual',
    fuel_type       ENUM('petrol','diesel','electric','hybrid') DEFAULT 'petrol',
    drive_type      VARCHAR(20),
    condition_grade VARCHAR(10),
    purchase_price  DECIMAL(15,2),
    selling_price   DECIMAL(15,2),
    images          TEXT,
    status          ENUM('available','reserved','sold') DEFAULT 'available',
    added_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS caryard_test_drives (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    vehicle_id  INT NOT NULL,
    client_name VARCHAR(255),
    client_phone VARCHAR(25),
    client_id_no VARCHAR(50),
    scheduled_at DATETIME,
    status      ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS caryard_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    vehicle_id      INT NOT NULL,
    buyer_name      VARCHAR(255),
    buyer_phone     VARCHAR(25),
    buyer_email     VARCHAR(255),
    id_number       VARCHAR(50),
    sale_price      DECIMAL(15,2),
    payment_method  VARCHAR(100),
    sale_date       DATE,
    financing       TINYINT(1) DEFAULT 0,
    financer        VARCHAR(255),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- MODULE: SHOPPING MALL
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS mall_floors (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    org_id  INT NOT NULL,
    name    VARCHAR(100),
    level   INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mall_shops (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    floor_id    INT,
    shop_no     VARCHAR(50),
    name        VARCHAR(255),
    category    VARCHAR(100),
    size_sqm    DECIMAL(10,2),
    monthly_rent DECIMAL(10,2),
    status      ENUM('vacant','occupied','maintenance') DEFAULT 'vacant',
    FOREIGN KEY (floor_id) REFERENCES mall_floors(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mall_tenants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    shop_id         INT NOT NULL,
    business_name   VARCHAR(255),
    contact_person  VARCHAR(255),
    phone           VARCHAR(25),
    email           VARCHAR(255),
    business_type   VARCHAR(100),
    lease_start     DATE,
    lease_end       DATE,
    deposit         DECIMAL(10,2),
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES mall_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mall_rent_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    shop_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    amount          DECIMAL(10,2),
    period          VARCHAR(20),
    payment_date    DATE,
    payment_method  VARCHAR(50),
    reference       VARCHAR(100),
    status          ENUM('paid','pending','partial') DEFAULT 'paid',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- NOTIFICATIONS & MPESA PENDING
-- ────────────────────────────────────────────────────────────

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS checkout_id   VARCHAR(100) DEFAULT NULL AFTER status;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS mpesa_receipt VARCHAR(100) DEFAULT NULL AFTER checkout_id;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    org_id     INT NOT NULL,
    user_id    INT,
    title      VARCHAR(255) NOT NULL,
    message    TEXT,
    type       ENUM('info','success','warning','danger') DEFAULT 'info',
    link       VARCHAR(500),
    is_read    TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_org       (org_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mpesa_pending (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    invoice_id  INT,
    checkout_id VARCHAR(100) NOT NULL UNIQUE,
    amount      DECIMAL(12,2),
    phone       VARCHAR(20),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_checkout (checkout_id)
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- SEED DATA
-- ────────────────────────────────────────────────────────────

INSERT IGNORE INTO modules (slug, name, description, icon, color, category, monthly_price, annual_price, sort_order) VALUES
('accounting',     'Accounting & Bookkeeping', 'General ledger, invoicing, expenses, financial reports & tax management',    'fas fa-calculator',   '#1A8A4E','Finance',       2500,  25000, 1),
('crm',            'CRM',                     'Lead management, deals pipeline, contacts, activities & customer tracking',  'fas fa-handshake',    '#0B2D4E','Business',      2000,  20000, 2),
('sales',          'Sales Management',         'Quotes, orders, customers, product catalog & sales performance analytics',   'fas fa-chart-line',   '#1A8A4E','Business',      2000,  20000, 3),
('meetings',       'Meeting Management',        'Schedule meetings, invite attendees, record minutes & track action items',   'fas fa-video',        '#0B2D4E','Productivity',  1500,  15000, 4),
('school',         'School Management',         'Students, classes, fees, grades, timetables & school communications',       'fas fa-school',       '#1A8A4E','Education',     3500,  35000, 5),
('health',         'Health Management',         'Patient records, appointments, prescriptions, doctors & billing',           'fas fa-heartbeat',    '#e74c3c','Healthcare',    3500,  35000, 6),
('pos',            'Point of Sale (POS)',        'Fast retail POS with inventory, receipts, shifts & sales reports',          'fas fa-cash-register','#f39c12','Retail',        2500,  25000, 7),
('sacco',          'SACCO System',              'Member savings, loans, shares, dividends & full SACCO reporting',           'fas fa-piggy-bank',   '#8e44ad','Finance',       4000,  40000, 8),
('rental',         'Rental Management',         'Properties, units, tenants, rent collection & maintenance tracking',        'fas fa-building',     '#2980b9','Real Estate',   3000,  30000, 9),
('church',         'Church Management',         'Member registry, offerings, tithes, cells, events & pastoral care',         'fas fa-church',       '#8e44ad','Faith',         2000,  20000,10),
('finance',        'Finance Management',         'Budgets, income & expense tracking, accounts & financial dashboards',       'fas fa-wallet',       '#16a085','Finance',       2500,  25000,11),
('hotel',          'Hotel Management',           'Rooms, bookings, check-in/out, housekeeping, billing & guest portal',       'fas fa-hotel',        '#d35400','Hospitality',   4000,  40000,12),
('salon',          'Salon & Barbershop',         'Appointment booking, services, stylists, POS & client loyalty',             'fas fa-cut',          '#c0392b','Services',      2000,  20000,13),
('retail',         'Retail & Wholesale',         'Inventory, suppliers, purchase orders, customers & margin reports',         'fas fa-store',        '#27ae60','Retail',        3000,  30000,14),
('tour',           'Tour & Travel',              'Tour packages, bookings, itineraries, guides & travel billing',             'fas fa-plane',        '#2980b9','Tourism',       3000,  30000,15),
('events',         'Event Management',           'Plan events, sell tickets, manage attendees, check-in & reports',           'fas fa-calendar-alt', '#8e44ad','Events',        2500,  25000,16),
('manufacturing',  'Manufacturing System',       'Production orders, BOM, raw materials, quality control & costing',          'fas fa-industry',     '#7f8c8d','Manufacturing', 4500,  45000,17),
('hrm',            'HRM System',                 'Employees, payroll, leave, attendance, appraisals & HR analytics',          'fas fa-users-cog',    '#2c3e50','HR',            3500,  35000,18),
('caryard',        'Car Yard Management',        'Vehicle stock, sales, test drives, financing & dealer reports',             'fas fa-car',          '#e67e22','Automotive',    3000,  30000,19),
('shopping-mall',  'Shopping Mall System',       'Shops, tenants, leases, rent billing, maintenance & mall analytics',        'fas fa-store-alt',    '#1abc9c','Real Estate',   5000,  50000,20),
('courier',        'Courier Management',         'Parcel tracking, delivery agents, payments, agreements & route management',  'fas fa-shipping-fast','#1565c0','Logistics',     3000,  30000,21),
('driving',        'Driving School',             'Manage students, instructors, vehicles, lessons, tests and licenses for a driving school', 'fas fa-car-side','#1a237e','Education',     4500,  45000,22);

INSERT IGNORE INTO subscription_plans (name, description, max_users, max_modules, price_monthly, price_annual, is_popular) VALUES
('Starter',      'Perfect for small businesses just getting started',               5,  3, 4999,  49990, 0),
('Professional', 'Ideal for growing businesses with diverse operational needs',     25, 8, 12999, 129990,1),
('Enterprise',   'Full-scale ERP solution for large & multi-branch organizations',  200,20,29999, 299990,0);

-- Super Admin account  (password: Admin@2024)
INSERT IGNORE INTO users (name, email, password, role, status) VALUES
('System Administrator','admin@shanfix.com','$2y$12$LkHdZ5zGJ4o3qwVl7B8nNeU6gvpZnL1kE3rVa7RwTs9Y4mOdIWb0a','super_admin','active');

SET FOREIGN_KEY_CHECKS = 1;
