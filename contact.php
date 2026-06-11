<?php
/**
 * OrbitDesk — Public Contact Page
 * Standalone, no authentication required.
 */
session_start();
require_once __DIR__ . '/config/database.php';

// ── Idempotent: create contact_inquiries table ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_inquiries (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        ref_no        VARCHAR(30)  NOT NULL,
        full_name     VARCHAR(150) NOT NULL,
        email         VARCHAR(150) NOT NULL,
        phone         VARCHAR(30)  DEFAULT NULL,
        company       VARCHAR(150) DEFAULT NULL,
        subject       VARCHAR(100) NOT NULL,
        message       TEXT         NOT NULL,
        status        ENUM('new','read','replied','closed') DEFAULT 'new',
        submitted_ip  VARCHAR(45)  DEFAULT NULL,
        submitted_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        admin_notes   TEXT         DEFAULT NULL,
        INDEX idx_email  (email),
        INDEX idx_status (status),
        INDEX idx_ip     (submitted_ip, submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$appName      = defined('APP_NAME')          ? APP_NAME          : 'OrbitDesk';
$appTagline   = defined('APP_TAGLINE')        ? APP_TAGLINE        : 'All-in-One Business Management Platform';
$supportEmail = defined('APP_SUPPORT_EMAIL') ? APP_SUPPORT_EMAIL : 'support@orbitdesk.net';
$appUrl       = defined('APP_URL')           ? APP_URL           : '';

$result = '';   // 'success' | 'error' | 'ratelimit'
$refNo  = '';
$errors = [];
$old    = ['full_name'=>'','email'=>'','phone'=>'','company'=>'','subject'=>'','message'=>''];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $honeypot = $_POST['website'] ?? '';          // Spam trap
    $name     = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES);
    $email    = trim($_POST['email'] ?? '');
    $phone    = htmlspecialchars(trim($_POST['phone']   ?? ''), ENT_QUOTES);
    $company  = htmlspecialchars(trim($_POST['company'] ?? ''), ENT_QUOTES);
    $subject  = htmlspecialchars(trim($_POST['subject'] ?? ''), ENT_QUOTES);
    $message  = htmlspecialchars(trim($_POST['message'] ?? ''), ENT_QUOTES);
    $ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip       = trim(explode(',', $ip)[0]);

    $old = compact('name', 'email', 'phone', 'company', 'subject', 'message');

    if ($honeypot !== '') {
        $result = 'success';
        $refNo  = 'INQ-SPAM-TRAP';
    } else {
        if (strlen($name) < 2)                                       $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))              $errors[] = 'A valid email address is required.';
        if (!$subject)                                               $errors[] = 'Please select a subject.';
        if (strlen(strip_tags($message)) < 20)                      $errors[] = 'Message must be at least 20 characters.';
        if (strlen(strip_tags($message)) > 3000)                    $errors[] = 'Message is too long (max 3000 characters).';

        if (empty($errors)) {
            // Rate limiting: 3 submissions per IP per hour
            try {
                $rc = $pdo->prepare("SELECT COUNT(*) FROM contact_inquiries WHERE submitted_ip=? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $rc->execute([$ip]);
                if ((int)$rc->fetchColumn() >= 3) $result = 'ratelimit';
            } catch (Throwable $e) {}

            if ($result !== 'ratelimit') {
                try {
                    $seq   = (int)$pdo->query("SELECT COUNT(*)+1 FROM contact_inquiries")->fetchColumn();
                    $refNo = 'INQ-' . date('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                    $pdo->prepare(
                        "INSERT INTO contact_inquiries (ref_no, full_name, email, phone, company, subject, message, submitted_ip)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([$refNo, $name, $email, $phone ?: null, $company ?: null, $subject, $message, $ip]);

                    // Email notification (best-effort)
                    $mailSubject = "[{$refNo}] Contact: {$subject}";
                    $mailBody    = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#222'>
                        <h2 style='color:#1565c0'>New Contact Inquiry</h2>
                        <table style='border-collapse:collapse;width:100%;max-width:560px'>
                          <tr><td style='padding:8px;color:#555;width:130px'><strong>Reference</strong></td><td style='padding:8px'>{$refNo}</td></tr>
                          <tr style='background:#f8f9fa'><td style='padding:8px'><strong>Name</strong></td><td style='padding:8px'>{$name}</td></tr>
                          <tr><td style='padding:8px'><strong>Email</strong></td><td style='padding:8px'><a href='mailto:{$email}'>{$email}</a></td></tr>
                          " . ($phone   ? "<tr style='background:#f8f9fa'><td style='padding:8px'><strong>Phone</strong></td><td style='padding:8px'>{$phone}</td></tr>"     : '') . "
                          " . ($company ? "<tr><td style='padding:8px'><strong>Company</strong></td><td style='padding:8px'>{$company}</td></tr>"                             : '') . "
                          <tr style='background:#f8f9fa'><td style='padding:8px'><strong>Subject</strong></td><td style='padding:8px'>{$subject}</td></tr>
                          <tr><td style='padding:8px;vertical-align:top'><strong>Message</strong></td><td style='padding:8px'>" . nl2br($message) . "</td></tr>
                        </table>
                        <p style='color:#999;font-size:.8em;margin-top:24px'>{$appName} &mdash; Contact Inquiry System</p>
                        </body></html>";

                    $mailHeaders = implode("\r\n", [
                        "From: {$appName} <noreply@orbitdesk.net>",
                        "Reply-To: {$name} <{$email}>",
                        "Content-Type: text/html; charset=utf-8",
                        "MIME-Version: 1.0",
                    ]);
                    @mail($supportEmail, $mailSubject, $mailBody, $mailHeaders);

                    $result = 'success';
                } catch (Throwable $e) {
                    $errors[] = 'Submission failed. Please try again or email us directly at ' . $supportEmail;
                }
            }
        }
    }
}

$subjects = [
    'General Inquiry'    => 'General Inquiry',
    'Sales & Pricing'    => 'Sales & Pricing',
    'Technical Support'  => 'Technical Support',
    'Module / Feature Request' => 'Module / Feature Request',
    'Partnership'        => 'Partnership',
    'Demo Request'       => 'Demo Request',
    'Other'              => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Contact <?= htmlspecialchars($appName, ENT_QUOTES) ?> — Get in touch with our team for sales, support, or partnership enquiries.">
  <title>Contact Us — <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    /* ── Base ─────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #f0f4fb;
      color: #1a1a2e;
      margin: 0;
    }

    /* ── Navbar ───────────────────────────────────────────── */
    .site-nav {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: rgba(255,255,255,.95);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(0,0,0,.07);
      padding: .75rem 0;
      transition: box-shadow .2s;
    }
    .site-nav.scrolled { box-shadow: 0 2px 20px rgba(0,0,0,.1); }
    .nav-brand {
      font-size: 1.35rem;
      font-weight: 800;
      color: #0d47a1;
      text-decoration: none;
      letter-spacing: -.3px;
    }
    .nav-brand span { color: #e53935; }
    .nav-links { display: flex; align-items: center; gap: 1.5rem; list-style: none; margin: 0; padding: 0; }
    .nav-links a { font-size: .88rem; font-weight: 500; color: #444; text-decoration: none; transition: color .15s; }
    .nav-links a:hover, .nav-links a.active { color: #0d47a1; }
    .btn-nav-login {
      font-size: .85rem; font-weight: 600;
      padding: .45rem 1.1rem;
      border-radius: 8px;
      background: #0d47a1;
      color: #fff !important;
      border: none;
      transition: background .15s, transform .1s;
    }
    .btn-nav-login:hover { background: #1565c0; transform: translateY(-1px); }

    /* ── Hero ─────────────────────────────────────────────── */
    .hero {
      background: linear-gradient(135deg, #0d47a1 0%, #1565c0 50%, #1976d2 100%);
      color: #fff;
      padding: 5rem 1rem 4rem;
      position: relative;
      overflow: hidden;
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .hero .hero-badge {
      display: inline-flex; align-items: center; gap: .4rem;
      background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.25);
      border-radius: 100px;
      padding: .3rem .9rem;
      font-size: .78rem; font-weight: 600; letter-spacing: .4px;
      margin-bottom: 1.2rem;
    }
    .hero h1 {
      font-size: clamp(2rem, 5vw, 3.2rem);
      font-weight: 800;
      line-height: 1.15;
      margin-bottom: .8rem;
      letter-spacing: -.5px;
    }
    .hero p.lead {
      font-size: 1.05rem;
      opacity: .85;
      max-width: 520px;
      margin: 0 auto;
    }
    .hero-orb {
      position: absolute;
      border-radius: 50%;
      background: rgba(255,255,255,.05);
    }
    .hero-orb-1 { width: 400px; height: 400px; right: -100px; top: -100px; }
    .hero-orb-2 { width: 250px; height: 250px; right: 100px; bottom: -80px; }
    .hero-orb-3 { width: 150px; height: 150px; left: 5%; top: 20%; }

    /* ── Main Content ─────────────────────────────────────── */
    .contact-section { padding: 3.5rem 0 4rem; }

    /* ── Info Cards ───────────────────────────────────────── */
    .info-card {
      background: #fff;
      border-radius: 16px;
      padding: 1.4rem 1.5rem;
      display: flex; align-items: flex-start; gap: 1rem;
      box-shadow: 0 2px 16px rgba(0,0,0,.06);
      border: 1px solid rgba(0,0,0,.05);
      transition: transform .2s, box-shadow .2s;
      text-decoration: none; color: inherit;
    }
    .info-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(13,71,161,.13); }
    .info-card-icon {
      width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; color: #fff;
    }
    .info-card-body .label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #999; margin-bottom: .15rem; }
    .info-card-body .value { font-size: .92rem; font-weight: 600; color: #1a1a2e; }
    .info-card-body .sub   { font-size: .8rem; color: #777; margin-top: .1rem; }

    /* ── Hours Card ───────────────────────────────────────── */
    .hours-card {
      background: linear-gradient(135deg, #e3f2fd, #bbdefb);
      border-radius: 16px;
      padding: 1.4rem 1.5rem;
      border: 1px solid #90caf9;
    }
    .hours-card h6 { font-weight: 700; font-size: .82rem; text-transform: uppercase; letter-spacing: .7px; color: #0d47a1; margin-bottom: .9rem; }
    .hours-row { display: flex; justify-content: space-between; font-size: .85rem; padding: .3rem 0; border-bottom: 1px dashed rgba(13,71,161,.1); }
    .hours-row:last-child { border-bottom: none; }
    .hours-row .day { font-weight: 600; color: #1a1a2e; }
    .hours-row .time { color: #0d47a1; font-weight: 500; }

    /* ── Social Links ─────────────────────────────────────── */
    .social-links { display: flex; gap: .6rem; flex-wrap: wrap; }
    .social-link {
      width: 38px; height: 38px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem; color: #fff; text-decoration: none;
      transition: transform .15s, opacity .15s;
    }
    .social-link:hover { transform: translateY(-2px); opacity: .9; }

    /* ── Contact Form Card ────────────────────────────────── */
    .form-card {
      background: #fff;
      border-radius: 20px;
      padding: 2.5rem;
      box-shadow: 0 4px 32px rgba(0,0,0,.1);
      border: 1px solid rgba(0,0,0,.05);
    }
    .form-card h4 { font-size: 1.35rem; font-weight: 800; color: #0d47a1; margin-bottom: .3rem; }
    .form-card p.subtitle { font-size: .88rem; color: #777; margin-bottom: 1.75rem; }

    .form-label { font-size: .83rem; font-weight: 600; color: #444; margin-bottom: .35rem; }
    .form-control, .form-select {
      border-radius: 10px;
      border: 1.5px solid #e0e0e0;
      padding: .7rem 1rem;
      font-size: .9rem;
      transition: border-color .15s, box-shadow .15s;
    }
    .form-control:focus, .form-select:focus {
      border-color: #1565c0;
      box-shadow: 0 0 0 3px rgba(21,101,192,.12);
      outline: none;
    }
    .form-control.is-invalid, .form-select.is-invalid { border-color: #e53935; }
    .char-counter { font-size: .75rem; color: #aaa; text-align: right; margin-top: .25rem; }
    .char-counter.warn { color: #f57c00; }
    .char-counter.over { color: #e53935; font-weight: 700; }

    .btn-send {
      background: linear-gradient(135deg, #0d47a1, #1565c0);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: .85rem 2rem;
      font-weight: 700;
      font-size: .95rem;
      width: 100%;
      transition: background .2s, transform .1s, box-shadow .2s;
      cursor: pointer;
    }
    .btn-send:hover:not(:disabled) {
      background: linear-gradient(135deg, #1565c0, #1976d2);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(13,71,161,.35);
    }
    .btn-send:disabled { opacity: .7; cursor: not-allowed; }
    .btn-send .spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Success Panel ────────────────────────────────────── */
    .success-panel {
      background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
      border: 1px solid #a5d6a7;
      border-radius: 16px;
      padding: 2.5rem;
      text-align: center;
    }
    .success-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: linear-gradient(135deg, #43a047, #66bb6a);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.2rem;
      box-shadow: 0 4px 16px rgba(67,160,71,.3);
    }
    .success-icon i { font-size: 2rem; color: #fff; }
    .success-ref {
      display: inline-block;
      background: rgba(67,160,71,.12);
      border: 1px solid rgba(67,160,71,.3);
      border-radius: 8px;
      padding: .35rem .9rem;
      font-family: monospace;
      font-size: .9rem;
      font-weight: 700;
      color: #2e7d32;
      margin: .5rem 0 1rem;
    }

    /* ── Error Panel ─────────────────────────────────────── */
    .error-list { list-style: none; padding: 0; margin: 0; }
    .error-list li::before { content: '⚠ '; }
    .error-list li { font-size: .85rem; padding: .2rem 0; }

    /* ── Support Banner ───────────────────────────────────── */
    .support-banner {
      background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
      border-top: 1px solid #e0e0e0;
      padding: 2.5rem 1rem;
    }

    /* ── Footer ───────────────────────────────────────────── */
    .site-footer {
      background: #0d1b35;
      color: #90a4c0;
      padding: 2.5rem 0 1.5rem;
    }
    .footer-brand { font-size: 1.2rem; font-weight: 800; color: #fff; margin-bottom: .4rem; }
    .footer-brand span { color: #ef5350; }
    .footer-tagline { font-size: .8rem; color: #607d9f; margin-bottom: 1rem; }
    .footer-links { list-style: none; padding: 0; margin: 0; }
    .footer-links li { margin-bottom: .4rem; }
    .footer-links a { color: #90a4c0; text-decoration: none; font-size: .82rem; transition: color .15s; }
    .footer-links a:hover { color: #fff; }
    .footer-divider { border-color: rgba(255,255,255,.08); margin: 1.5rem 0 1rem; }
    .footer-copy { font-size: .78rem; color: #607d9f; }

    /* ── Responsive ───────────────────────────────────────── */
    @media (max-width: 991px) {
      .form-card { padding: 1.75rem; }
      .nav-links { display: none; }
    }
    @media (max-width: 576px) {
      .hero { padding: 3.5rem 1rem 3rem; }
      .form-card { padding: 1.5rem; }
    }
  </style>
</head>
<body>

<!-- ── Sticky Navbar ──────────────────────────────────────────────────────── -->
<nav class="site-nav" id="siteNav">
  <div class="container d-flex align-items-center justify-content-between">
    <a href="<?= htmlspecialchars($appUrl ?: '/', ENT_QUOTES) ?>" class="nav-brand">
      Orbit<span>Desk</span>
    </a>
    <ul class="nav-links d-none d-lg-flex">
      <li><a href="<?= htmlspecialchars($appUrl ?: '/', ENT_QUOTES) ?>">Home</a></li>
      <li><a href="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/track.php">Parcel Tracking</a></li>
      <li><a href="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/mall-tenant-portal.php">Tenant Portal</a></li>
      <li><a href="contact.php" class="active">Contact</a></li>
    </ul>
    <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>" class="btn-nav-login">
      <i class="fas fa-sign-in-alt me-1"></i>Login
    </a>
  </div>
</nav>

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="hero text-center">
  <div class="hero-orb hero-orb-1"></div>
  <div class="hero-orb hero-orb-2"></div>
  <div class="hero-orb hero-orb-3"></div>
  <div class="container position-relative">
    <div class="hero-badge">
      <i class="fas fa-headset"></i>
      We typically respond within 2 business hours
    </div>
    <h1>Get in Touch With Us</h1>
    <p class="lead mx-auto">
      Have a question about <?= htmlspecialchars($appName, ENT_QUOTES) ?>?
      Whether it's sales, support, or a partnership — we're here to help.
    </p>
  </div>
</section>

<!-- ── Main Contact Section ──────────────────────────────────────────────── -->
<section class="contact-section">
  <div class="container">
    <div class="row g-4 align-items-start">

      <!-- ── Left Column: Contact Info ─────────────────────────────────── -->
      <div class="col-lg-5">

        <h2 class="fw-bold mb-1" style="font-size:1.5rem;color:#0d47a1">Contact Information</h2>
        <p class="text-muted mb-4" style="font-size:.9rem">Reach us through any of the channels below, or fill in the form.</p>

        <!-- Info cards -->
        <div class="d-flex flex-column gap-3 mb-4">

          <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES) ?>" class="info-card">
            <div class="info-card-icon" style="background:linear-gradient(135deg,#1565c0,#0d47a1)">
              <i class="fas fa-envelope"></i>
            </div>
            <div class="info-card-body">
              <div class="label">Email us</div>
              <div class="value"><?= htmlspecialchars($supportEmail, ENT_QUOTES) ?></div>
              <div class="sub">We reply to every message</div>
            </div>
          </a>

          <a href="tel:+254700000000" class="info-card">
            <div class="info-card-icon" style="background:linear-gradient(135deg,#00897b,#00695c)">
              <i class="fas fa-phone-alt"></i>
            </div>
            <div class="info-card-body">
              <div class="label">Call us</div>
              <div class="value">+254 700 000 000</div>
              <div class="sub">Mon – Fri, 8 AM – 6 PM EAT</div>
            </div>
          </a>

          <div class="info-card" style="cursor:default">
            <div class="info-card-icon" style="background:linear-gradient(135deg,#e53935,#c62828)">
              <i class="fas fa-map-marker-alt"></i>
            </div>
            <div class="info-card-body">
              <div class="label">Offices</div>
              <div class="value">Nairobi, Kenya</div>
              <div class="sub">Westlands Business District</div>
            </div>
          </div>

          <div class="info-card" style="cursor:default">
            <div class="info-card-icon" style="background:linear-gradient(135deg,#7b1fa2,#6a1b9a)">
              <i class="fas fa-comments"></i>
            </div>
            <div class="info-card-body">
              <div class="label">Live Chat</div>
              <div class="value">Available inside your account</div>
              <div class="sub">Log in and click the chat bubble</div>
            </div>
          </div>

        </div>

        <!-- Business Hours -->
        <div class="hours-card mb-4">
          <h6><i class="fas fa-clock me-2"></i>Business Hours</h6>
          <div class="hours-row"><span class="day">Monday – Friday</span><span class="time">8:00 AM – 6:00 PM</span></div>
          <div class="hours-row"><span class="day">Saturday</span><span class="time">9:00 AM – 1:00 PM</span></div>
          <div class="hours-row"><span class="day">Sunday &amp; Holidays</span><span class="time">Closed</span></div>
          <div class="mt-2 small" style="color:#1565c0;font-weight:500">
            <i class="fas fa-info-circle me-1"></i>
            All times in East Africa Time (EAT, UTC+3)
          </div>
        </div>

        <!-- Social links -->
        <div>
          <p class="text-muted small fw-semibold mb-2">FOLLOW US</p>
          <div class="social-links">
            <a href="#" class="social-link" style="background:#1877f2" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="social-link" style="background:#1da1f2" title="Twitter / X"><i class="fab fa-twitter"></i></a>
            <a href="#" class="social-link" style="background:#0a66c2" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" class="social-link" style="background:#25d366" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
            <a href="#" class="social-link" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)" title="Instagram"><i class="fab fa-instagram"></i></a>
          </div>
        </div>
      </div>

      <!-- ── Right Column: Contact Form ────────────────────────────────── -->
      <div class="col-lg-7">
        <div class="form-card">

          <?php if ($result === 'success'): ?>
          <!-- ── Success State ─────────────────────────────── -->
          <div class="success-panel">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <h4 class="fw-bold mb-1" style="color:#2e7d32">Message Received!</h4>
            <p class="text-muted mb-2" style="font-size:.9rem">
              Thank you for reaching out. Our team will review your message and get back to you as soon as possible.
            </p>
            <?php if ($refNo && !str_starts_with($refNo, 'INQ-SPAM')): ?>
            <div class="success-ref"><i class="fas fa-hashtag me-1"></i><?= htmlspecialchars($refNo, ENT_QUOTES) ?></div>
            <p class="text-muted small mb-0">Keep this reference number for follow-up enquiries.</p>
            <?php endif; ?>
            <div class="d-flex gap-2 justify-content-center mt-3 flex-wrap">
              <a href="contact.php" class="btn btn-outline-success btn-sm">
                <i class="fas fa-plus me-1"></i>Send Another
              </a>
              <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>" class="btn btn-success btn-sm">
                <i class="fas fa-sign-in-alt me-1"></i>Go to Login
              </a>
            </div>
          </div>

          <?php elseif ($result === 'ratelimit'): ?>
          <!-- ── Rate Limit ─────────────────────────────────── -->
          <div class="alert alert-warning d-flex align-items-start gap-3" style="border-radius:12px">
            <i class="fas fa-hourglass-half fa-lg mt-1 text-warning"></i>
            <div>
              <div class="fw-bold">Too many submissions</div>
              <div class="small mt-1">You've sent several messages recently. Please wait an hour before submitting again, or email us directly at <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES) ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES) ?></a>.</div>
            </div>
          </div>

          <?php else: ?>
          <!-- ── The Form ───────────────────────────────────── -->
          <h4><i class="fas fa-paper-plane me-2"></i>Send Us a Message</h4>
          <p class="subtitle">All fields marked <span class="text-danger">*</span> are required.</p>

          <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2 mb-3" style="border-radius:10px;border-left:4px solid #e53935">
            <ul class="error-list mb-0">
              <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err, ENT_QUOTES) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form id="contactForm" method="POST" action="contact.php" novalidate>
            <!-- Honeypot — hidden from humans, bots fill it -->
            <div style="position:absolute;left:-9999px;opacity:0;pointer-events:none" aria-hidden="true">
              <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="f_name">Full Name <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text" style="border-radius:10px 0 0 10px;border:1.5px solid #e0e0e0;border-right:none;background:#fafafa">
                    <i class="fas fa-user text-muted" style="font-size:.8rem"></i>
                  </span>
                  <input type="text" id="f_name" name="full_name" class="form-control <?= !empty($errors) && !$old['name'] ? 'is-invalid' : '' ?>"
                         style="border-left:none;border-radius:0 10px 10px 0"
                         value="<?= htmlspecialchars($old['full_name'] ?? $old['name'] ?? '', ENT_QUOTES) ?>"
                         placeholder="e.g. Jane Mwangi" required autocomplete="name">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_email">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text" style="border-radius:10px 0 0 10px;border:1.5px solid #e0e0e0;border-right:none;background:#fafafa">
                    <i class="fas fa-at text-muted" style="font-size:.8rem"></i>
                  </span>
                  <input type="email" id="f_email" name="email" class="form-control <?= !empty($errors) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL) ? 'is-invalid' : '' ?>"
                         style="border-left:none;border-radius:0 10px 10px 0"
                         value="<?= htmlspecialchars($old['email'], ENT_QUOTES) ?>"
                         placeholder="jane@company.co.ke" required autocomplete="email">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_phone">Phone Number <span class="text-muted fw-normal">(optional)</span></label>
                <div class="input-group">
                  <span class="input-group-text" style="border-radius:10px 0 0 10px;border:1.5px solid #e0e0e0;border-right:none;background:#fafafa">
                    <i class="fas fa-phone text-muted" style="font-size:.8rem"></i>
                  </span>
                  <input type="tel" id="f_phone" name="phone" class="form-control"
                         style="border-left:none;border-radius:0 10px 10px 0"
                         value="<?= htmlspecialchars($old['phone'], ENT_QUOTES) ?>"
                         placeholder="+254 7xx xxx xxx" autocomplete="tel">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_company">Company / Organisation <span class="text-muted fw-normal">(optional)</span></label>
                <div class="input-group">
                  <span class="input-group-text" style="border-radius:10px 0 0 10px;border:1.5px solid #e0e0e0;border-right:none;background:#fafafa">
                    <i class="fas fa-building text-muted" style="font-size:.8rem"></i>
                  </span>
                  <input type="text" id="f_company" name="company" class="form-control"
                         style="border-left:none;border-radius:0 10px 10px 0"
                         value="<?= htmlspecialchars($old['company'], ENT_QUOTES) ?>"
                         placeholder="Your business name" autocomplete="organization">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="f_subject">Subject <span class="text-danger">*</span></label>
                <select id="f_subject" name="subject" class="form-select <?= !empty($errors) && !$old['subject'] ? 'is-invalid' : '' ?>" required>
                  <option value="" disabled <?= !$old['subject'] ? 'selected' : '' ?>>— Select a topic —</option>
                  <?php foreach ($subjects as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= $old['subject'] === $val ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label" for="f_message">Message <span class="text-danger">*</span></label>
                <textarea id="f_message" name="message" class="form-control <?= !empty($errors) && strlen(strip_tags($old['message'])) < 20 ? 'is-invalid' : '' ?>"
                          rows="6" required minlength="20" maxlength="3000"
                          placeholder="Tell us how we can help. Please include as much detail as possible…"><?= htmlspecialchars($old['message'], ENT_QUOTES) ?></textarea>
                <div class="char-counter" id="charCounter">0 / 3000</div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn-send d-flex align-items-center justify-content-center gap-2" id="sendBtn">
                  <div class="spinner" id="sendSpinner"></div>
                  <i class="fas fa-paper-plane me-1" id="sendIcon"></i>
                  <span id="sendLabel">Send Message</span>
                </button>
              </div>
              <div class="col-12">
                <p class="text-muted small text-center mb-0" style="font-size:.76rem">
                  <i class="fas fa-lock me-1"></i>
                  Your information is kept strictly confidential and will never be shared with third parties.
                </p>
              </div>
            </div>
          </form>
          <?php endif; ?>

        </div><!-- /form-card -->
      </div>

    </div><!-- /row -->
  </div><!-- /container -->
</section>

<!-- ── Support Banner ─────────────────────────────────────────────────────── -->
<section class="support-banner">
  <div class="container">
    <div class="row align-items-center g-3">
      <div class="col-md-7">
        <div class="d-flex align-items-center gap-3">
          <div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1565c0,#0d47a1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-life-ring fa-lg text-white"></i>
          </div>
          <div>
            <div class="fw-bold" style="font-size:1rem;color:#0d47a1">Already using <?= htmlspecialchars($appName, ENT_QUOTES) ?>?</div>
            <div class="text-muted small">Log in to your account to raise a support ticket or use live chat for faster assistance.</div>
          </div>
        </div>
      </div>
      <div class="col-md-5 text-md-end">
        <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>" class="btn fw-bold px-4 py-2 text-white me-2"
           style="background:#0d47a1;border-radius:10px;font-size:.88rem">
          <i class="fas fa-sign-in-alt me-1"></i>Login to Account
        </a>
      </div>
    </div>
  </div>
</section>

<!-- ── Footer ─────────────────────────────────────────────────────────────── -->
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="footer-brand">Orbit<span>Desk</span></div>
        <div class="footer-tagline"><?= htmlspecialchars($appTagline, ENT_QUOTES) ?></div>
        <p style="font-size:.8rem;color:#607d9f;max-width:260px">
          Helping businesses across East Africa manage operations smarter — from one unified platform.
        </p>
      </div>
      <div class="col-md-2 col-6">
        <div class="fw-semibold mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.8px;color:#607d9f">Platform</div>
        <ul class="footer-links">
          <li><a href="#">Features</a></li>
          <li><a href="#">Pricing</a></li>
          <li><a href="#">Modules</a></li>
          <li><a href="#">Integrations</a></li>
          <li><a href="#">API Docs</a></li>
        </ul>
      </div>
      <div class="col-md-2 col-6">
        <div class="fw-semibold mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.8px;color:#607d9f">Company</div>
        <ul class="footer-links">
          <li><a href="#">About Us</a></li>
          <li><a href="#">Blog</a></li>
          <li><a href="#">Careers</a></li>
          <li><a href="#">Partners</a></li>
          <li><a href="contact.php">Contact</a></li>
        </ul>
      </div>
      <div class="col-md-2 col-6">
        <div class="fw-semibold mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.8px;color:#607d9f">Support</div>
        <ul class="footer-links">
          <li><a href="#">Help Centre</a></li>
          <li><a href="#">Documentation</a></li>
          <li><a href="#">System Status</a></li>
          <li><a href="<?= htmlspecialchars($appUrl . '/track.php', ENT_QUOTES) ?>">Parcel Tracking</a></li>
          <li><a href="<?= htmlspecialchars($appUrl . '/mall-tenant-portal.php', ENT_QUOTES) ?>">Tenant Portal</a></li>
        </ul>
      </div>
      <div class="col-md-2 col-6">
        <div class="fw-semibold mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.8px;color:#607d9f">Legal</div>
        <ul class="footer-links">
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
          <li><a href="#">Cookie Policy</a></li>
          <li><a href="#">Data Processing</a></li>
        </ul>
      </div>
    </div>
    <hr class="footer-divider">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="footer-copy">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($appName, ENT_QUOTES) ?>. All rights reserved.
      </div>
      <div class="d-flex gap-2">
        <a href="#" class="social-link" style="background:rgba(255,255,255,.08);width:30px;height:30px;border-radius:8px" title="Facebook"><i class="fab fa-facebook-f" style="font-size:.7rem"></i></a>
        <a href="#" class="social-link" style="background:rgba(255,255,255,.08);width:30px;height:30px;border-radius:8px" title="Twitter"><i class="fab fa-twitter" style="font-size:.7rem"></i></a>
        <a href="#" class="social-link" style="background:rgba(255,255,255,.08);width:30px;height:30px;border-radius:8px" title="LinkedIn"><i class="fab fa-linkedin-in" style="font-size:.7rem"></i></a>
        <a href="#" class="social-link" style="background:rgba(255,255,255,.08);width:30px;height:30px;border-radius:8px" title="WhatsApp"><i class="fab fa-whatsapp" style="font-size:.7rem"></i></a>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sticky nav shadow on scroll ──────────────────────────────────────────────
window.addEventListener('scroll', () => {
  document.getElementById('siteNav').classList.toggle('scrolled', window.scrollY > 10);
}, { passive: true });

// ── Character counter ────────────────────────────────────────────────────────
const msgEl      = document.getElementById('f_message');
const counterEl  = document.getElementById('charCounter');
const MAX        = 3000;
const WARN       = 2500;

function updateCounter() {
  if (!msgEl || !counterEl) return;
  const len = msgEl.value.length;
  counterEl.textContent = len.toLocaleString() + ' / ' + MAX.toLocaleString();
  counterEl.className = 'char-counter' + (len > MAX ? ' over' : len > WARN ? ' warn' : '');
}
if (msgEl) {
  msgEl.addEventListener('input', updateCounter);
  updateCounter();
}

// ── Form submit: show spinner ────────────────────────────────────────────────
const form      = document.getElementById('contactForm');
const sendBtn   = document.getElementById('sendBtn');
const spinner   = document.getElementById('sendSpinner');
const sendIcon  = document.getElementById('sendIcon');
const sendLabel = document.getElementById('sendLabel');

if (form) {
  form.addEventListener('submit', (e) => {
    const name    = document.getElementById('f_name');
    const email   = document.getElementById('f_email');
    const subject = document.getElementById('f_subject');
    const msg     = document.getElementById('f_message');
    let ok = true;

    [name, email, subject, msg].forEach(el => {
      if (!el) return;
      if (!el.value.trim() || (el.type === 'email' && !el.value.includes('@'))) {
        el.classList.add('is-invalid');
        ok = false;
      } else {
        el.classList.remove('is-invalid');
      }
    });

    if (msg && msg.value.trim().length < 20) {
      msg.classList.add('is-invalid');
      ok = false;
    }

    if (!ok) { e.preventDefault(); return; }

    if (sendBtn) {
      sendBtn.disabled = true;
      if (spinner)   { spinner.style.display   = 'block'; }
      if (sendIcon)  { sendIcon.style.display  = 'none'; }
      if (sendLabel) { sendLabel.textContent   = 'Sending…'; }
    }
  });

  // Remove invalid state on input
  form.querySelectorAll('input,select,textarea').forEach(el => {
    el.addEventListener('input', () => el.classList.remove('is-invalid'));
    el.addEventListener('change', () => el.classList.remove('is-invalid'));
  });
}
</script>
</body>
</html>
