# OrbitDesk Workspace — Setup & Deployment Guide

## Local Development (XAMPP / WAMP)

1. Copy the entire `OrbitDesk Workspace` folder to your `htdocs` or `www` directory and rename it to `shanfix`
2. Open `config/database.php` and set your credentials:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'shanfix_db');
   define('APP_URL',  'http://localhost/orbitdesk');
   ```

3. Open phpMyAdmin → Create database: `shanfix_db` (Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`)
4. Import `database/schema.sql` into the database
5. Visit `http://localhost/orbitdesk`

---

## cPanel Production Deployment

### Step 1 — Upload Files

- Compress the entire folder to a `.zip`
- Log in to cPanel → File Manager → `public_html` (or a subdomain folder)
- Upload and extract the zip

### Step 2 — Create Database

- cPanel → MySQL Databases
- Create database: `youruser_shanfix`
- Create user with a strong password
- Grant ALL PRIVILEGES to the user on the database
- Import `database/schema.sql` via phpMyAdmin

### Step 3 — Update Config

Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'youruser_dbuser');
define('DB_PASS', 'YourStrongPassword');
define('DB_NAME', 'youruser_shanfix');
define('APP_URL',  'https://yourdomain.com');  // or subdomain
define('APP_ENV',  'production');
```

### Step 4 — Set Permissions

```bash
chmod 755 -R public_html/shanfix
chmod 644 -R public_html/shanfix/*.php
```

### Step 5 — Test

Visit your domain → you should see the landing page.

---

## Default Login Credentials

| Role        | Email             | Password   |
|-------------|-------------------|------------|
| Super Admin | admin@shanfix.com | Admin@2024 |

> **Change the admin password immediately after first login!**

---

## Available Modules (20)

1. Accounting & Bookkeeping
2. CRM
3. Sales Management
4. Meeting Management
5. School Management
6. Health Management
7. Point of Sale (POS)
8. SACCO System
9. Rental Management
10. Church Management
11. Finance Management
12. Hotel Management
13. Salon & Barbershop
14. Retail & Wholesale
15. Tour & Travel
16. Event Management
17. Manufacturing System
18. HRM
19. Car Yard Management
20. Shopping Mall System

---

## PDF Generation (FPDF)

PDF downloads (invoices, payslips) require the FPDF library — a single PHP file, no Composer needed.

1. Download `fpdf.php` from [fpdf.org](http://www.fpdf.org/en/download.php)
2. Create folder: `vendor/fpdf/`
3. Place `fpdf.php` inside: `vendor/fpdf/fpdf.php`

Without FPDF, the system shows an HTML fallback instead of a PDF download. Everything else works normally.

---

## Tech Stack

- **Backend:** PHP 8.1+
- **Database:** MySQL 8.0+ / MariaDB 10.6+
- **Frontend:** Bootstrap 5.3, Font Awesome 6.5, Chart.js 4, DataTables 1.13
- **Payments:** M-Pesa Daraja API (configure in Admin → Settings)
- **Email:** SMTP (configure in Admin → Settings)
- **PDF:** FPDF (see above — optional, graceful fallback)

---

## Support

- Email: support@orbitdesk.co.ke
- WhatsApp: +254 700 000 000
