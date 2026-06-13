<?php
/**
 * OrbitDesk — Public Contact Page
 * Standalone, no authentication required.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// ── Idempotent: create contact_inquiries table ──────────────────────────────
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

$appName      = defined('APP_NAME')    ? APP_NAME    : 'OrbitDesk';
$appTagline   = defined('APP_TAGLINE') ? APP_TAGLINE : 'All-in-One Business Management Platform';
$appUrl       = defined('APP_URL')     ? APP_URL     : '';

// Pull contact details from settings (same pattern as index.php)
$sitePhone   = getSetting('company_phone',   '+254 700 000 000');
$siteEmail   = getSetting('support_email',   'info@orbitdesk.co.ke');
$siteAddress = getSetting('company_address', 'Nairobi, Kenya');
$siteHours   = getSetting('company_hours',   'Mon – Sat, 8 AM – 8 PM EAT');

$result = '';
$refNo  = '';
$errors = [];
$old    = ['full_name'=>'','email'=>'','phone'=>'','company'=>'','subject'=>'','message'=>''];

// ── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $honeypot = $_POST['website'] ?? '';
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
        if (strlen($name) < 2)                   $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        if (!$subject)                           $errors[] = 'Please select a subject.';
        if (strlen(strip_tags($message)) < 20)  $errors[] = 'Message must be at least 20 characters.';
        if (strlen(strip_tags($message)) > 3000) $errors[] = 'Message is too long (max 3,000 characters).';

        if (empty($errors)) {
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

                    $mailSubject = "[{$refNo}] Contact: {$subject}";
                    $mailBody    = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#222'>
                        <h2 style='color:#1A8A4E'>New Contact Inquiry — {$appName}</h2>
                        <table style='border-collapse:collapse;width:100%;max-width:560px'>
                          <tr><td style='padding:8px;color:#555;width:130px'><strong>Reference</strong></td><td style='padding:8px'>{$refNo}</td></tr>
                          <tr style='background:#f8f9fa'><td style='padding:8px'><strong>Name</strong></td><td style='padding:8px'>{$name}</td></tr>
                          <tr><td style='padding:8px'><strong>Email</strong></td><td style='padding:8px'><a href='mailto:{$email}'>{$email}</a></td></tr>
                          " . ($phone   ? "<tr style='background:#f8f9fa'><td style='padding:8px'><strong>Phone</strong></td><td style='padding:8px'>{$phone}</td></tr>" : '') . "
                          " . ($company ? "<tr><td style='padding:8px'><strong>Company</strong></td><td style='padding:8px'>{$company}</td></tr>" : '') . "
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
                    @mail($siteEmail, $mailSubject, $mailBody, $mailHeaders);
                    $result = 'success';
                } catch (Throwable $e) {
                    $errors[] = 'Submission failed. Please try again or email us at ' . $siteEmail;
                }
            }
        }
    }
}

$subjects = [
    'General Inquiry'            => 'General Inquiry',
    'Sales & Pricing'            => 'Sales & Pricing',
    'Technical Support'          => 'Technical Support',
    'Demo Request'               => 'Demo Request',
    'Module / Feature Request'   => 'Module / Feature Request',
    'Partnership'                => 'Partnership',
    'Billing & Payments'         => 'Billing & Payments',
    'Other'                      => 'Other',
];
?>
<?php
$pageTitle = 'Contact Us — ' . APP_NAME;
$metaDesc  = 'Contact ' . APP_NAME . ' — reach our team for sales, support, demo requests or partnership enquiries.';
$activeNav = 'contact';
ob_start();
?>
  <style>
    /* ── Variables ───────────────────────────────────────────── */
    :root {
      --green:     #1A8A4E;
      --green-dk:  #157a42;
      --green-lt:  #dcfce7;
      --navy:      #0B2D4E;
      --navy-dk:   #050F1F;
      --muted:     #64748b;
      --border:    #e2e8f0;
      --bg:        #f8faff;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--navy); margin: 0; overflow-x: hidden; }
    a { text-decoration: none; }

    /* ── Sticky Navbar ───────────────────────────────────────── */
    .site-nav {
      position: sticky; top: 0; z-index: 1000;
      background: rgba(255,255,255,.97);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(0,0,0,.07);
      padding: .75rem 0;
      transition: box-shadow .2s;
    }
    .site-nav.scrolled { box-shadow: 0 2px 20px rgba(0,0,0,.09); }
    .nav-brand { font-size: 1.35rem; font-weight: 900; color: var(--navy); letter-spacing: -.4px; }
    .nav-brand span { color: var(--green); }
    .nav-links { display: flex; align-items: center; gap: 1.6rem; list-style: none; margin: 0; padding: 0; }
    .nav-links a { font-size: .88rem; font-weight: 500; color: #555; transition: color .15s; }
    .nav-links a:hover, .nav-links a.active { color: var(--green); }
    .btn-nav { font-size: .85rem; font-weight: 700; padding: .48rem 1.2rem; border-radius: 8px; border: none; cursor: pointer; transition: .15s; }
    .btn-nav-outline { border: 1.5px solid var(--border); color: var(--navy); background: transparent; }
    .btn-nav-outline:hover { border-color: var(--green); color: var(--green); }
    .btn-nav-solid { background: var(--green); color: #fff; }
    .btn-nav-solid:hover { background: var(--green-dk); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,138,78,.35); }

    /* ── Hero ────────────────────────────────────────────────── */
    .contact-hero {
      background: linear-gradient(135deg, var(--navy-dk) 0%, var(--navy) 55%, #0d3d1e 100%);
      padding: 5rem 0 4.5rem;
      position: relative;
      overflow: hidden;
      color: #fff;
      text-align: center;
    }
    .contact-hero::before {
      content: '';
      position: absolute; inset: 0;
      background-image: radial-gradient(rgba(26,138,78,.12) 1px, transparent 1px);
      background-size: 36px 36px;
    }
    .hero-glow {
      position: absolute; border-radius: 50%;
      filter: blur(80px); pointer-events: none;
    }
    .glow-1 { width: 500px; height: 500px; background: rgba(26,138,78,.18); right: -100px; top: -120px; }
    .glow-2 { width: 320px; height: 320px; background: rgba(11,45,78,.5);  left: -50px; bottom: -80px; }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: .5rem;
      background: rgba(26,138,78,.18); border: 1px solid rgba(26,138,78,.35);
      color: #4ade93; border-radius: 50px;
      padding: .35rem 1rem .35rem .65rem;
      font-size: .8rem; font-weight: 600; letter-spacing: .3px; margin-bottom: 1.4rem;
    }
    .hero-eyebrow .dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; animation: pulse-dot 2s infinite; }
    @keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.7)} }
    .contact-hero h1 {
      font-size: clamp(2.2rem, 5vw, 3.4rem);
      font-weight: 900; letter-spacing: -.6px;
      line-height: 1.1; margin-bottom: .85rem;
    }
    .contact-hero p.lead { font-size: 1.05rem; opacity: .8; max-width: 520px; margin: 0 auto 2rem; line-height: 1.65; }
    .trust-bar { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem 1.4rem; }
    .trust-item { font-size: .82rem; font-weight: 600; opacity: .85; display: flex; align-items: center; gap: .4rem; }
    .trust-item i { color: #4ade80; }

    /* ── Section helpers ─────────────────────────────────────── */
    .section-eyebrow { display: inline-block; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--green); background: var(--green-lt); padding: .3rem .85rem; border-radius: 100px; margin-bottom: .85rem; }

    /* ── Info Cards ──────────────────────────────────────────── */
    .info-card {
      background: #fff;
      border-radius: 16px;
      padding: 1.25rem 1.4rem;
      display: flex; align-items: center; gap: 1rem;
      box-shadow: 0 2px 16px rgba(11,45,78,.07);
      border: 1.5px solid var(--border);
      transition: transform .2s, box-shadow .2s, border-color .2s;
      color: inherit;
    }
    .info-card:hover { transform: translateY(-3px); box-shadow: 0 10px 32px rgba(26,138,78,.13); border-color: rgba(26,138,78,.3); }
    .info-icon {
      width: 48px; height: 48px; border-radius: 13px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; color: #fff;
    }
    .info-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .9px; color: var(--muted); margin-bottom: .1rem; }
    .info-value { font-size: .93rem; font-weight: 700; color: var(--navy); line-height: 1.3; }
    .info-sub   { font-size: .79rem; color: var(--muted); margin-top: .1rem; }

    /* ── Hours Card ──────────────────────────────────────────── */
    .hours-card {
      background: linear-gradient(135deg, var(--green-lt), #f0fdf4);
      border-radius: 16px;
      padding: 1.4rem 1.5rem;
      border: 1.5px solid rgba(26,138,78,.2);
    }
    .hours-card h6 { font-weight: 800; font-size: .78rem; text-transform: uppercase; letter-spacing: .9px; color: var(--green); margin-bottom: .9rem; }
    .hours-row { display: flex; justify-content: space-between; align-items: center; font-size: .84rem; padding: .35rem 0; border-bottom: 1px dashed rgba(26,138,78,.15); }
    .hours-row:last-child { border-bottom: none; }
    .hours-row .day { font-weight: 600; color: var(--navy); }
    .hours-row .time { color: var(--green); font-weight: 600; }

    /* ── Social links ────────────────────────────────────────── */
    .social-links { display: flex; gap: .55rem; flex-wrap: wrap; }
    .social-link {
      width: 38px; height: 38px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: .88rem; color: #fff;
      transition: transform .15s, opacity .15s;
    }
    .social-link:hover { transform: translateY(-2px); opacity: .88; }

    /* ── Form Card ───────────────────────────────────────────── */
    .form-card {
      background: #fff;
      border-radius: 20px;
      padding: 2.5rem;
      box-shadow: 0 4px 40px rgba(11,45,78,.1);
      border: 1.5px solid var(--border);
    }
    .form-card .form-title { font-size: 1.4rem; font-weight: 900; color: var(--navy); margin-bottom: .25rem; letter-spacing: -.3px; }
    .form-card .form-sub   { font-size: .88rem; color: var(--muted); margin-bottom: 1.75rem; }

    .form-label { font-size: .82rem; font-weight: 600; color: var(--navy); margin-bottom: .35rem; }
    .form-control, .form-select {
      border-radius: 10px;
      border: 1.5px solid var(--border);
      padding: .7rem 1rem;
      font-size: .9rem;
      color: var(--navy);
      transition: border-color .15s, box-shadow .15s;
      background: #fff;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--green);
      box-shadow: 0 0 0 3px rgba(26,138,78,.12);
      outline: none;
    }
    .form-control.is-invalid, .form-select.is-invalid { border-color: #dc2626; }
    .input-group-text {
      background: var(--bg);
      border: 1.5px solid var(--border);
      color: var(--muted);
      font-size: .82rem;
    }
    .input-group .form-control {
      border-left: none;
    }
    .input-group > .input-group-text:first-child {
      border-right: none;
      border-radius: 10px 0 0 10px;
    }
    .input-group > .form-control:last-child {
      border-radius: 0 10px 10px 0;
    }
    .char-counter { font-size: .75rem; color: #aaa; text-align: right; margin-top: .25rem; }
    .char-counter.warn { color: #f59e0b; }
    .char-counter.over { color: #dc2626; font-weight: 700; }

    .btn-send {
      background: linear-gradient(135deg, var(--green), #22c27a);
      color: #fff; border: none; border-radius: 10px;
      padding: .88rem 2rem; font-weight: 700; font-size: .95rem;
      width: 100%; cursor: pointer;
      transition: background .2s, transform .15s, box-shadow .2s;
      display: flex; align-items: center; justify-content: center; gap: .6rem;
    }
    .btn-send:hover:not(:disabled) {
      background: linear-gradient(135deg, var(--green-dk), #1ea96a);
      transform: translateY(-1px);
      box-shadow: 0 6px 22px rgba(26,138,78,.38);
    }
    .btn-send:disabled { opacity: .65; cursor: not-allowed; }
    .spin { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; flex-shrink: 0; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Success Panel ───────────────────────────────────────── */
    .success-panel {
      background: linear-gradient(135deg, var(--green-lt), #f0fdf4);
      border: 1.5px solid rgba(26,138,78,.25);
      border-radius: 16px;
      padding: 2.5rem;
      text-align: center;
    }
    .success-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: linear-gradient(135deg, var(--green), #22c27a);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.2rem;
      box-shadow: 0 4px 20px rgba(26,138,78,.3);
    }
    .success-icon i { font-size: 2rem; color: #fff; }
    .success-ref {
      display: inline-block;
      background: rgba(26,138,78,.1);
      border: 1px solid rgba(26,138,78,.3);
      border-radius: 8px;
      padding: .35rem 1rem;
      font-family: 'Courier New', monospace;
      font-size: .9rem; font-weight: 700;
      color: var(--green);
      margin: .5rem 0 1rem;
    }

    /* ── CTA Banner ──────────────────────────────────────────── */
    .cta-banner {
      background: linear-gradient(135deg, var(--navy) 0%, #0d3d1e 100%);
      padding: 3rem 1rem;
      position: relative; overflow: hidden;
    }
    .cta-banner::before {
      content: ''; position: absolute; inset: 0;
      background-image: radial-gradient(rgba(26,138,78,.1) 1px, transparent 1px);
      background-size: 32px 32px;
    }
    .cta-banner .content { position: relative; z-index: 1; }

    /* ── Footer ──────────────────────────────────────────────── */
    .site-footer {
      background: var(--navy-dk);
      color: #90a4c0;
      padding: 3rem 0 1.5rem;
    }
    .footer-brand { font-size: 1.25rem; font-weight: 900; color: #fff; margin-bottom: .35rem; }
    .footer-brand span { color: var(--green); }
    .footer-tagline { font-size: .8rem; color: #607d9f; margin-bottom: 1rem; }
    .footer-heading { font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .9px; color: #607d9f; margin-bottom: .75rem; }
    .footer-links { list-style: none; padding: 0; margin: 0; }
    .footer-links li { margin-bottom: .4rem; }
    .footer-links a { color: #90a4c0; font-size: .82rem; transition: color .15s; }
    .footer-links a:hover { color: #fff; }
    hr.footer-divider { border-color: rgba(255,255,255,.08); margin: 1.75rem 0 1.25rem; }
    .footer-copy { font-size: .78rem; color: #607d9f; }

    /* ── Error List ──────────────────────────────────────────── */
    .error-list { list-style: none; padding: 0; margin: 0; }
    .error-list li { font-size: .85rem; padding: .2rem 0; }
    .error-list li::before { content: '⚠ '; }

    /* ── Responsive ──────────────────────────────────────────── */
    @media (max-width: 991px) {
      .nav-links { display: none; }
      .form-card { padding: 1.75rem; }
    }
    @media (max-width: 576px) {
      .contact-hero { padding: 3.5rem 0 3rem; }
      .form-card { padding: 1.4rem; }
    }
<?php
$extraHeadHtml = ob_get_clean();
require_once __DIR__ . '/includes/header-public.php';
?>

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="contact-hero">
  <div class="hero-glow glow-1"></div>
  <div class="hero-glow glow-2"></div>
  <div class="container position-relative" style="z-index:2">
    <div class="hero-eyebrow">
      <span class="dot"></span>
      Our team typically responds within 2 business hours
    </div>
    <h1>Talk to Us — We're Here to Help</h1>
    <p class="lead mx-auto">
      Questions about pricing, modules, or a custom deployment?
      Reach out and a real person from our team will get back to you.
    </p>
    <div class="trust-bar">
      <span class="trust-item"><i class="fas fa-check-circle"></i> No sales pressure</span>
      <span class="trust-item"><i class="fas fa-check-circle"></i> 14-day free trial</span>
      <span class="trust-item"><i class="fas fa-check-circle"></i> Kenya-based support</span>
      <span class="trust-item"><i class="fas fa-check-circle"></i> M-Pesa integrated</span>
    </div>
  </div>
</section>

<!-- ── Main Content ───────────────────────────────────────────────────────── -->
<section class="py-5">
  <div class="container">
    <div class="row g-5 align-items-start">

      <!-- ── Left: Contact Details ──────────────────────────────────────── -->
      <div class="col-lg-5">

        <span class="section-eyebrow">Contact Information</span>
        <h2 class="fw-black mb-2" style="font-size:1.6rem;letter-spacing:-.3px;color:var(--navy)">
          Reach Us Through Any Channel
        </h2>
        <p class="text-muted mb-4" style="font-size:.9rem;line-height:1.65">
          Choose whichever channel works best for you. We're available Monday through Saturday and aim to resolve all queries on the same day.
        </p>

        <div class="d-flex flex-column gap-3 mb-4">

          <a href="mailto:<?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>" class="info-card">
            <div class="info-icon" style="background:linear-gradient(135deg,var(--green),#22c27a)">
              <i class="fas fa-envelope"></i>
            </div>
            <div>
              <div class="info-label">Email us</div>
              <div class="info-value"><?= htmlspecialchars($siteEmail, ENT_QUOTES) ?></div>
              <div class="info-sub">We reply to every message</div>
            </div>
          </a>

          <a href="tel:<?= preg_replace('/\s/', '', $sitePhone) ?>" class="info-card">
            <div class="info-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490)">
              <i class="fas fa-phone-alt"></i>
            </div>
            <div>
              <div class="info-label">Call or WhatsApp</div>
              <div class="info-value"><?= htmlspecialchars($sitePhone, ENT_QUOTES) ?></div>
              <div class="info-sub">Mon – Sat, 8 AM – 8 PM EAT</div>
            </div>
          </a>

          <div class="info-card" style="cursor:default">
            <div class="info-icon" style="background:linear-gradient(135deg,var(--navy),#1e4d7b)">
              <i class="fas fa-map-marker-alt"></i>
            </div>
            <div>
              <div class="info-label">Office location</div>
              <div class="info-value"><?= htmlspecialchars($siteAddress, ENT_QUOTES) ?></div>
              <div class="info-sub">East Africa Time (EAT, UTC+3)</div>
            </div>
          </div>

          <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>" class="info-card">
            <div class="info-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)">
              <i class="fas fa-headset"></i>
            </div>
            <div>
              <div class="info-label">Live support</div>
              <div class="info-value">Inside your account dashboard</div>
              <div class="info-sub">Log in and click the chat bubble</div>
            </div>
          </a>

        </div>

        <!-- Business Hours -->
        <div class="hours-card mb-4">
          <h6><i class="fas fa-clock me-2"></i>Business Hours (EAT)</h6>
          <div class="hours-row"><span class="day">Monday – Friday</span><span class="time">8:00 AM – 8:00 PM</span></div>
          <div class="hours-row"><span class="day">Saturday</span><span class="time">9:00 AM – 5:00 PM</span></div>
          <div class="hours-row"><span class="day">Sunday &amp; Public Holidays</span><span class="time">Closed</span></div>
        </div>

        <!-- Social links -->
        <div>
          <p class="text-muted small fw-bold mb-2" style="letter-spacing:.6px;font-size:.72rem;text-transform:uppercase">Follow Us</p>
          <div class="social-links">
            <a href="#" class="social-link" style="background:#1877f2" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="social-link" style="background:#1da1f2" title="Twitter / X"><i class="fab fa-twitter"></i></a>
            <a href="#" class="social-link" style="background:#0a66c2" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" class="social-link" style="background:#25d366" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
            <a href="#" class="social-link" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)" title="Instagram"><i class="fab fa-instagram"></i></a>
          </div>
        </div>

      </div>

      <!-- ── Right: Contact Form ────────────────────────────────────────── -->
      <div class="col-lg-7">
        <div class="form-card">

          <?php if ($result === 'success'): ?>
          <!-- Success -->
          <div class="success-panel">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <h4 class="fw-black mb-1" style="color:var(--navy)">Message Received!</h4>
            <p class="text-muted mb-2" style="font-size:.92rem">
              Thank you for reaching out. Our team will review your message and respond within 2 business hours.
            </p>
            <?php if ($refNo && !str_starts_with($refNo, 'INQ-SPAM')): ?>
            <div class="success-ref"><i class="fas fa-hashtag me-1"></i><?= htmlspecialchars($refNo, ENT_QUOTES) ?></div>
            <p class="text-muted small mb-0">Keep this reference number for any follow-ups.</p>
            <?php endif; ?>
            <div class="d-flex gap-2 justify-content-center mt-4 flex-wrap">
              <a href="contact.php" class="btn btn-outline-success btn-sm fw-semibold px-4">
                <i class="fas fa-plus me-1"></i>Send Another Message
              </a>
              <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>" class="btn btn-sm fw-semibold px-4" style="background:var(--green);color:#fff">
                <i class="fas fa-sign-in-alt me-1"></i>Go to Login
              </a>
            </div>
          </div>

          <?php elseif ($result === 'ratelimit'): ?>
          <!-- Rate limit -->
          <div class="alert d-flex align-items-start gap-3 mb-0" style="background:#fef3c7;border:1.5px solid #fbbf24;border-radius:12px">
            <i class="fas fa-hourglass-half fa-lg mt-1" style="color:#f59e0b"></i>
            <div>
              <div class="fw-bold" style="color:#92400e">Too many submissions</div>
              <div class="small mt-1 text-muted">You've sent several messages recently. Please wait an hour or email us directly at <a href="mailto:<?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>" style="color:var(--green)"><?= htmlspecialchars($siteEmail, ENT_QUOTES) ?></a>.</div>
            </div>
          </div>

          <?php else: ?>
          <!-- Form -->
          <div class="form-title"><i class="fas fa-paper-plane me-2" style="color:var(--green)"></i>Send Us a Message</div>
          <p class="form-sub">Fields marked <span class="text-danger fw-bold">*</span> are required. We do not share your details with third parties.</p>

          <?php if (!empty($errors)): ?>
          <div class="alert mb-3" style="border-radius:10px;border:1.5px solid #fca5a5;background:#fff5f5;border-left:4px solid #dc2626">
            <ul class="error-list mb-0">
              <?php foreach ($errors as $err): ?>
              <li style="color:#b91c1c"><?= htmlspecialchars($err, ENT_QUOTES) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form id="contactForm" method="POST" action="contact.php" novalidate>
            <!-- Honeypot -->
            <div style="position:absolute;left:-9999px;opacity:0;pointer-events:none" aria-hidden="true">
              <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="f_name">Full Name <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-user"></i></span>
                  <input type="text" id="f_name" name="full_name" class="form-control <?= !empty($errors) && strlen($old['name'] ?? $old['full_name'] ?? '') < 2 ? 'is-invalid' : '' ?>"
                         value="<?= htmlspecialchars($old['full_name'] ?? $old['name'] ?? '', ENT_QUOTES) ?>"
                         placeholder="Jane Mwangi" required autocomplete="name">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_email">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-at"></i></span>
                  <input type="email" id="f_email" name="email" class="form-control <?= !empty($errors) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL) ? 'is-invalid' : '' ?>"
                         value="<?= htmlspecialchars($old['email'], ENT_QUOTES) ?>"
                         placeholder="jane@company.co.ke" required autocomplete="email">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-phone"></i></span>
                  <input type="tel" id="f_phone" name="phone" class="form-control"
                         value="<?= htmlspecialchars($old['phone'], ENT_QUOTES) ?>"
                         placeholder="+254 7xx xxx xxx" autocomplete="tel">
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="f_company">Company / Organisation <span class="text-muted fw-normal">(optional)</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-building"></i></span>
                  <input type="text" id="f_company" name="company" class="form-control"
                         value="<?= htmlspecialchars($old['company'], ENT_QUOTES) ?>"
                         placeholder="Your business name" autocomplete="organization">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="f_subject">Subject <span class="text-danger">*</span></label>
                <select id="f_subject" name="subject" class="form-select <?= !empty($errors) && !$old['subject'] ? 'is-invalid' : '' ?>" required>
                  <option value="" disabled <?= !$old['subject'] ? 'selected' : '' ?>>— Select a topic —</option>
                  <?php foreach ($subjects as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($old['subject'] ?? '') === $val ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label" for="f_message">Message <span class="text-danger">*</span></label>
                <textarea id="f_message" name="message" class="form-control <?= !empty($errors) && strlen(strip_tags($old['message'])) < 20 ? 'is-invalid' : '' ?>"
                          rows="6" required minlength="20" maxlength="3000"
                          placeholder="Tell us how we can help — include as much detail as possible so we can respond accurately."><?= htmlspecialchars($old['message'], ENT_QUOTES) ?></textarea>
                <div class="char-counter" id="charCounter">0 / 3,000</div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn-send" id="sendBtn">
                  <div class="spin" id="sendSpinner"></div>
                  <i class="fas fa-paper-plane" id="sendIcon"></i>
                  <span id="sendLabel">Send Message</span>
                </button>
              </div>
              <div class="col-12 text-center">
                <p class="text-muted mb-0" style="font-size:.76rem">
                  <i class="fas fa-lock me-1" style="color:var(--green)"></i>
                  Your information is kept strictly confidential and never shared with third parties.
                </p>
              </div>
            </div>
          </form>
          <?php endif; ?>

        </div>
      </div>

    </div>
  </div>
</section>

<!-- ── CTA Banner ─────────────────────────────────────────────────────────── -->
<section class="cta-banner">
  <div class="container content">
    <div class="row align-items-center g-4">
      <div class="col-md-8">
        <div class="d-flex align-items-center gap-4">
          <div style="width:56px;height:56px;border-radius:14px;background:rgba(26,138,78,.25);border:1.5px solid rgba(26,138,78,.35);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-life-ring fa-lg" style="color:#4ade80"></i>
          </div>
          <div>
            <div class="fw-black text-white" style="font-size:1.1rem">Already using <?= htmlspecialchars($appName, ENT_QUOTES) ?>?</div>
            <div class="text-muted small mt-1">Log in to raise a support ticket or start a live chat — you'll get a faster response than the public contact form.</div>
          </div>
        </div>
      </div>
      <div class="col-md-4 text-md-end">
        <a href="<?= htmlspecialchars($appUrl . '/index.php', ENT_QUOTES) ?>"
           class="btn fw-bold px-5 py-2 text-white"
           style="background:var(--green);border-radius:10px;font-size:.9rem">
          <i class="fas fa-sign-in-alt me-2"></i>Login to Account
        </a>
      </div>
    </div>
  </div>
</section>

<!-- ── Footer ─────────────────────────────────────────────────────────────── -->

<?php
ob_start();
?>
<script>
// Character counter
const msgEl     = document.getElementById('f_message');
const counterEl = document.getElementById('charCounter');
const MAX = 3000, WARN = 2500;
function updateCounter() {
  if (!msgEl || !counterEl) return;
  const len = msgEl.value.length;
  counterEl.textContent = len.toLocaleString() + ' / 3,000';
  counterEl.className = 'char-counter' + (len > MAX ? ' over' : len > WARN ? ' warn' : '');
}
if (msgEl) { msgEl.addEventListener('input', updateCounter); updateCounter(); }

// Submit: show spinner, validate
const form      = document.getElementById('contactForm');
const sendBtn   = document.getElementById('sendBtn');
const spinner   = document.getElementById('sendSpinner');
const sendIcon  = document.getElementById('sendIcon');
const sendLabel = document.getElementById('sendLabel');

if (form) {
  form.addEventListener('submit', e => {
    const fields = ['f_name','f_email','f_subject','f_message'];
    let ok = true;
    fields.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      const bad = !el.value.trim() || (el.type === 'email' && !el.value.includes('@'));
      el.classList.toggle('is-invalid', bad);
      if (bad) ok = false;
    });
    const msg = document.getElementById('f_message');
    if (msg && msg.value.trim().length < 20) { msg.classList.add('is-invalid'); ok = false; }
    if (!ok) { e.preventDefault(); return; }
    if (sendBtn) {
      sendBtn.disabled = true;
      if (spinner)   spinner.style.display   = 'inline-block';
      if (sendIcon)  sendIcon.style.display  = 'none';
      if (sendLabel) sendLabel.textContent   = 'Sending…';
    }
  });
  form.querySelectorAll('input,select,textarea').forEach(el => {
    el.addEventListener('input',  () => el.classList.remove('is-invalid'));
    el.addEventListener('change', () => el.classList.remove('is-invalid'));
  });
}
</script>
<?php
$extraBodyJs = ob_get_clean();
require_once __DIR__ . '/includes/footer-public.php';
?>