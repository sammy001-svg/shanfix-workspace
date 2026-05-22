# 🌟 OrbitDesk Workspace — Premium All-in-One Business Management Suite

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg?style=flat&logo=php)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%208.0%2B-4479A1.svg?style=flat&logo=mysql)](https://www.mysql.com/)
[![Frontend](https://img.shields.io/badge/Frontend-Bootstrap%205.3-7952B3.svg?style=flat&logo=bootstrap)](https://getbootstrap.com/)

**OrbitDesk Workspace** is a premium, feature-rich, and highly integrated multi-tenant business and institutional management suite designed to modernize operations across various industries. Built with a powerful PHP backend and modern responsive Bootstrap frontend, it combines robust logic with beautiful, intuitive, and interactive interfaces.

---

## 🚀 Key Modules (20 Core Business Suites)

The suite is modularly engineered to provide tailored management controls for twenty diverse industries and departments:

1. **📊 Accounting & Bookkeeping** — Full ledger, accounts payable/receivable, balance sheets, and tax reports.
2. **🤝 CRM (Customer Relationship Management)** — Leads tracking, client portals, customer pipelines, and communications.
3. **💼 Sales Management** — Invoicing, quotations, order workflows, and performance tracking.
4. **📅 Meeting Management** — Schedule meetings, record minutes, send invites, and manage corporate calendars.
5. **🏫 School Management** — Student admissions, fee structures, academic reporting, and parent portals.
6. **🏥 Health Management** — Patient records, clinical notes, appointment scheduling, and pharmacy stock.
7. **🛒 Point of Sale (POS)** — Super-fast checkout, barcode scanning, thermal printing support, and daily register logs.
8. **🏦 SACCO System** — Member registration, savings accounts, shares ledger, and dividend distribution.
9. **🔑 Rental Management** — Tenant directories, lease tracking, utility billing, and automated rent reminders.
10. **⛪ Church Management** — Member database, tithes & offerings ledger, event calendars, and ministry groups.
11. **💸 Finance Management** — Expense tracking, loan management, cash flow statements, and budget planners.
12. **🏨 Hotel Management** — Room bookings, check-in/check-out logs, housekeeping checklists, and billing.
13. **💇 Salon & Barbershop** — Stylist bookings, walk-in checkouts, service catalogs, and loyalty points.
14. **📦 Retail & Wholesale** — Multi-warehouse inventory, stock transfers, supplier logs, and reorder alerts.
15. **✈️ Tour & Travel** — Package building, itinerary planners, booking management, and transport schedules.
16. **🎪 Event Management** — Ticket booking, venue booking, vendor management, and event timelines.
17. **🏭 Manufacturing System** — Bill of Materials (BOM), production tracking, work orders, and raw material inventory.
18. **👥 HRM (Human Resource Management)** — Employee profiles, payroll logs, leave management, and appraisal systems.
19. **🚗 Car Yard Management** — Vehicle inventory, test drive bookings, sales contracts, and broker commission logs.
20. **🏢 Shopping Mall System** — Tenant leases, foot traffic logs, maintenance requests, and shared service billing.

---

## 🛠️ Technology Stack

* **Backend:** PHP 8.1+ (Object-Oriented, PDO-driven for superior security against SQL injection)
* **Database:** MySQL 8.0+ / MariaDB 10.6+
* **Frontend:** Bootstrap 5.3, Font Awesome 6.5, Chart.js 4 (interactive graphs), and DataTables 1.13 (responsive pagination/search)
* **Payments:** Integrated M-Pesa Daraja API (configured directly in the settings panel)
* **Email Suite:** Advanced SMTP Support (with configurable profiles for notifications)
* **PDF Engine:** Optional FPDF library Integration with graceful HTML fallback for printing invoices and reports.

---

## 💻 Local Setup (XAMPP / WAMP)

Follow these steps to deploy **OrbitDesk Workspace** on your local machine:

1. **Clone the repository** to your local web server root directory (e.g., `C:/xampp/htdocs` or `/var/www/html`) and rename the directory to `shanfix`:
   ```bash
   git clone https://github.com/sammy001-svg/orbitdesk-workspace.git shanfix
   ```

2. **Configure the Database Credentials**:
   Open `config/database.php` and specify your local setup settings:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'shanfix_db');
   define('APP_URL',  'http://localhost/orbitdesk');
   ```

3. **Create MySQL Database**:
   * Open **phpMyAdmin** and create a new database named `shanfix_db`.
   * Set the collation to `utf8mb4_unicode_ci` for comprehensive character support.

4. **Import Database Schema**:
   * Select the `shanfix_db` database.
   * Go to the **Import** tab and select the schema file located at `database/schema.sql`.
   * Click **Go / Import** to complete the database structures.

5. **Access Application**:
   * Start Apache and MySQL services in your XAMPP/WAMP panel.
   * Open your browser and navigate to `http://localhost/orbitdesk`.

---

## 🌐 cPanel / Production Deployment

Deploy to live web hosting in minutes:

### Step 1 — Upload files
1. Compress your project directory into a `.zip` file (excluding developer temp files or IDE folders).
2. Log into cPanel → **File Manager** → go to `public_html` (or your subdomain directory).
3. Upload the `.zip` archive and **Extract** it.

### Step 2 — Set Up Database
1. Go to cPanel → **MySQL Database Wizard**.
2. Create a database named `youruser_shanfix` and a secure database user.
3. Grant **ALL PRIVILEGES** to the user on that database.
4. Import `database/schema.sql` via **phpMyAdmin** under cPanel.

### Step 3 — Update Configuration
Edit `config/database.php` on the live server:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'youruser_dbuser');
define('DB_PASS', 'YourSuperSecurePassword');
define('DB_NAME', 'youruser_shanfix');
define('APP_URL',  'https://yourdomain.com');  // Replace with your actual domain
define('APP_ENV',  'production');
```

### Step 4 — Set Secure Directory Permissions
Set recommended file permissions via File Manager or SSH:
```bash
chmod 755 -R public_html/shanfix
chmod 644 -R public_html/shanfix/*.php
```

---

## 🔑 Default Login Credentials

Use the following super admin credentials for the initial login:

| Role | Default Email | Default Password |
| :--- | :--- | :--- |
| **Super Admin** | `admin@shanfix.com` | `Admin@2024` |

> [!WARNING]
> For security reasons, please change the default administrator credentials immediately after your first successful login.

---

## 📄 PDF Generation (FPDF Library)

The PDF feature runs dynamically using the high-performance FPDF library, eliminating complex composer dependencies.
1. Download the latest `fpdf.php` from [fpdf.org](http://www.fpdf.org/en/download.php).
2. Create the directory path `vendor/fpdf/`.
3. Save the main class as `vendor/fpdf/fpdf.php`.

*Note: If FPDF is not installed, the platform gracefully switches to native styled HTML pages for printouts and receipt screens.*

---

## 📞 Support & Feedback

For technical support, custom module developments, or bug reports:
* **Email:** support@orbitdesk.co.ke
* **WhatsApp:** +254 700 000 000

---
*Developed with ❤️ to empower businesses globally.*
