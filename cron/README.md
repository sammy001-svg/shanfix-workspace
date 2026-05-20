# Shanfix Workspace — Cron Job Setup

## cPanel Configuration

Log in to cPanel → Cron Jobs → Add New Cron Job

| Script | Schedule | Command |
|--------|----------|---------|
| Subscription Renewal & Auto-suspend | Daily 7:00 AM | `0 7 * * * php /home/USERNAME/public_html/shanfix/cron/check-subscriptions.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1` |
| Trial Expiry Reminders | Daily 8:00 AM | `0 8 * * * php /home/USERNAME/public_html/shanfix/cron/trial-expiry.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1` |
| Invoice Reminders | Daily 9:00 AM | `0 9 * * * php /home/USERNAME/public_html/shanfix/cron/invoice-reminders.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1` |

Replace `USERNAME` with your cPanel username and adjust the path to match your installation.

## Local Testing

Run manually from the project root:

```bash
php cron/trial-expiry.php
php cron/invoice-reminders.php
```

## Log File

Output is appended to `/home/USERNAME/logs/shanfix-cron.log`. Create the logs directory first:

```bash
mkdir -p /home/USERNAME/logs
```

## Email Triggers

### trial-expiry.php
- Queries `subscriptions` where `status = 'trial'` and `trial_ends_at` falls in exactly **1**, **3**, or **7** days from today.
- Sends `Mailer::sendTrialExpiry()` to the `client_admin` user of each matching organization.
- Logs each attempt to `activity_log` (module: `billing`, action: `cron_email`).

### invoice-reminders.php
- **Due-soon**: Invoices with `status IN ('sent','pending')` and `due_date = today + 3 days`.
  - Subject: `Reminder: Invoice {number} due in 3 days`
- **Overdue**: Invoices with `status IN ('sent','pending')` and `due_date < today`.
  - Subject: `OVERDUE: Invoice {number} — Action Required`
- Sends a branded HTML email to the `client_admin` of each matching organization.
- Logs each attempt to `activity_log` (module: `billing`, action: `cron_email`).

## SMTP Configuration

Set these PHP constants in `config/database.php` (or a separate `config/mail.php`):

```php
define('SMTP_HOST', 'mail.yourdomain.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'no-reply@yourdomain.com');
define('SMTP_PASS', 'your-smtp-password');
define('SMTP_ENC',  'tls');   // 'tls' | 'ssl' | 'none'
define('MAIL_FROM', 'no-reply@yourdomain.com');
```

If these constants are not defined the mailer falls back to `localhost:587` and PHP's built-in `mail()`.
