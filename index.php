<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect logged-in users
if (isLoggedIn()) {
    redirect(($_SESSION['user_role'] === 'super_admin') ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
}

// Fetch modules for the modules section
$stmt = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order");
$modules = $stmt->fetchAll();

// Fetch plans
$stmt = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly");
$plans = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Shanfix Workspace — The all-in-one business management platform. Manage accounting, CRM, HRM, POS, hotel, school, SACCO, and 20+ business modules in one place.">
<title><?= APP_NAME ?> — <?= APP_TAGLINE ?></title>
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="landing-body">

<!-- ═══════════════ NAVBAR ═══════════════════════════════════════ -->
<nav class="landing-nav" id="mainNav">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <a href="#hero" class="nav-brand text-decoration-none">
        <div class="logo-icon"><i class="fas fa-cubes text-white"></i></div>
        <span><?= APP_NAME ?></span>
      </a>
      <div class="d-none d-lg-flex align-items-center gap-3">
        <a href="#features"  class="nav-link">Features</a>
        <a href="#modules"   class="nav-link">Modules</a>
        <a href="#pricing"   class="nav-link">Pricing</a>
        <a href="#about"     class="nav-link">About</a>
        <a href="#contact"   class="nav-link">Contact</a>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="<?= APP_URL ?>/auth/login.php"    class="btn-login">Login</a>
        <a href="<?= APP_URL ?>/auth/register.php" class="btn-getstarted">Get Started Free</a>
        <button class="d-lg-none btn-icon text-white" data-bs-toggle="collapse" data-bs-target="#mobileMenu">
          <i class="fas fa-bars"></i>
        </button>
      </div>
    </div>
    <!-- Mobile menu -->
    <div class="collapse mt-3 pb-2" id="mobileMenu">
      <div class="d-flex flex-column gap-1">
        <a href="#features" class="nav-link">Features</a>
        <a href="#modules"  class="nav-link">Modules</a>
        <a href="#pricing"  class="nav-link">Pricing</a>
        <a href="#contact"  class="nav-link">Contact</a>
      </div>
    </div>
  </div>
</nav>

<!-- ═══════════════ HERO ══════════════════════════════════════════ -->
<section class="hero" id="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 hero-content">
        <div class="hero-badge animate-in">
          <i class="fas fa-star"></i> Kenya's #1 Business Management Platform
        </div>
        <h1 class="animate-in delay-1">
          One Platform.<br>
          <span class="highlight">20+ Business</span><br>
          Solutions.
        </h1>
        <p class="animate-in delay-2">
          Shanfix Workspace brings all your business operations into a single, powerful platform.
          From accounting to hotel management, HRM to SACCO — subscribe to only what you need.
        </p>
        <div class="hero-cta animate-in delay-3">
          <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-hero-primary">
            Start Free Trial <i class="fas fa-arrow-right ms-2"></i>
          </a>
          <a href="#modules" class="btn btn-hero-outline">
            <i class="fas fa-play-circle me-2"></i> Explore Modules
          </a>
        </div>
        <div class="hero-stats animate-in delay-3">
          <div class="hero-stat">
            <div class="num" data-counter data-target="500">0</div>
            <div class="lbl">Businesses</div>
          </div>
          <div class="hero-stat">
            <div class="num" data-counter data-target="20">0</div>
            <div class="lbl">Modules</div>
          </div>
          <div class="hero-stat">
            <div class="num" data-counter data-target="99">0</div>
            <div class="lbl">% Uptime</div>
          </div>
          <div class="hero-stat">
            <div class="num" data-counter data-target="24">0</div>
            <div class="lbl">/7 Support</div>
          </div>
        </div>
      </div>

      <!-- Dashboard mockup -->
      <div class="col-lg-6 hero-visual d-none d-lg-block animate-in delay-2">
        <div class="hero-dashboard">
          <div class="hd-header">
            <div class="hd-dot" style="background:#ef4444"></div>
            <div class="hd-dot" style="background:#f59e0b"></div>
            <div class="hd-dot" style="background:#22c55e"></div>
            <span class="ms-2 text-muted small">Shanfix Dashboard</span>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-3"><div class="hd-mini-stat"><div class="val text-success">KES 2.4M</div><div class="lbl">Revenue</div></div></div>
            <div class="col-3"><div class="hd-mini-stat"><div class="val" style="color:var(--navy)">1,284</div><div class="lbl">Customers</div></div></div>
            <div class="col-3"><div class="hd-mini-stat"><div class="val text-warning">48</div><div class="lbl">Orders</div></div></div>
            <div class="col-3"><div class="hd-mini-stat"><div class="val text-danger">12</div><div class="lbl">Pending</div></div></div>
          </div>
          <div class="mb-3 p-2 bg-light rounded">
            <div class="small fw-bold mb-2 text-muted">Monthly Revenue</div>
            <div class="hd-chart-bar">
              <?php $bars = [40,55,35,70,50,65,80,90,60,75,85,100]; foreach($bars as $i => $h): ?>
              <div class="hd-bar <?= $i >= 9 ? 'active' : '' ?>" style="height:<?= $h ?>%"></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="row g-2">
            <?php $mods = array_slice($modules, 0, 6); foreach($mods as $m): ?>
            <div class="col-4">
              <div class="d-flex align-items-center gap-1 p-2 bg-light rounded">
                <div style="width:24px;height:24px;border-radius:6px;background:<?= e($m['color']) ?>22;display:flex;align-items:center;justify-content:center;color:<?= e($m['color']) ?>;font-size:.6rem;">
                  <i class="<?= e($m['icon']) ?>"></i>
                </div>
                <span style="font-size:.65rem;font-weight:600;color:var(--navy)"><?= e(explode(' ', $m['name'])[0]) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════ TRUSTED BY ════════════════════════════════════ -->
<section class="py-4 bg-white border-bottom">
  <div class="container text-center">
    <p class="text-muted small text-uppercase letter-spacing-2 mb-3">Trusted by businesses across Kenya</p>
    <div class="d-flex justify-content-center align-items-center gap-4 flex-wrap">
      <?php $types = ['Schools','Hotels','SACCOs','Hospitals','Salons','Retailers','NGOs','Churches']; ?>
      <?php foreach($types as $t): ?>
      <span class="px-3 py-2 rounded-pill border text-muted" style="font-size:.8rem;font-weight:600"><?= $t ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ═══════════════ FEATURES ══════════════════════════════════════ -->
<section class="py-6 bg-white" id="features" style="padding:5rem 0">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">Why Shanfix?</div>
      <h2 class="section-title">Everything Your Business Needs</h2>
      <p class="section-sub">Built for the African market with features that matter to your business growth</p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['icon'=>'fas fa-puzzle-piece',   'color'=>'#1A8A4E', 'title'=>'Modular & Flexible',          'desc'=>'Subscribe only to the modules you need. Add or remove anytime as your business grows.'],
        ['icon'=>'fas fa-shield-alt',     'color'=>'#0B2D4E', 'title'=>'Enterprise Security',         'desc'=>'Role-based access control, encrypted data, and activity logs keep your data safe.'],
        ['icon'=>'fas fa-mobile-alt',     'color'=>'#8b5cf6', 'title'=>'Mobile Responsive',           'desc'=>'Works perfectly on any device — desktop, tablet, or smartphone.'],
        ['icon'=>'fas fa-chart-bar',      'color'=>'#f59e0b', 'title'=>'Real-time Analytics',         'desc'=>'Dashboards with live data, charts, and KPIs to drive informed decisions.'],
        ['icon'=>'fas fa-users',          'color'=>'#ef4444', 'title'=>'Multi-user & Roles',          'desc'=>'Add unlimited staff with custom roles and permissions per module.'],
        ['icon'=>'fas fa-headset',        'color'=>'#1A8A4E', 'title'=>'24/7 Local Support',          'desc'=>'Dedicated Kenyan support team via phone, WhatsApp, and email at all times.'],
        ['icon'=>'fas fa-plug',           'color'=>'#0B2D4E', 'title'=>'M-Pesa Integration',          'desc'=>'Native M-Pesa STK push for payments across all billing modules.'],
        ['icon'=>'fas fa-cloud',          'color'=>'#14b8a6', 'title'=>'Cloud & On-Premise',          'desc'=>'Hosted on our secure servers or deploy on your own cPanel hosting.'],
      ];
      foreach($features as $f): ?>
      <div class="col-md-6 col-lg-3 fade-in">
        <div class="p-4 h-100" style="border-radius:12px;border:1px solid var(--gray-200);transition:all .2s" onmouseover="this.style.boxShadow='var(--shadow-md)';this.style.borderColor='var(--green)'" onmouseout="this.style.boxShadow='none';this.style.borderColor='var(--gray-200)'">
          <div class="feature-icon" style="background:<?= $f['color'] ?>1a;color:<?= $f['color'] ?>">
            <i class="<?= $f['icon'] ?>"></i>
          </div>
          <h6 class="fw-700 text-navy mb-2"><?= $f['title'] ?></h6>
          <p class="text-muted small mb-0" style="line-height:1.6"><?= $f['desc'] ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ═══════════════ MODULES ═══════════════════════════════════════ -->
<section class="modules-section py-6" id="modules" style="padding:5rem 0">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">20 Powerful Modules</div>
      <h2 class="section-title">Choose the Modules You Need</h2>
      <p class="section-sub">Each module is a complete solution. Subscribe to one or combine many for a full ERP experience.</p>
    </div>
    <div class="row g-3">
      <?php foreach($modules as $m): ?>
      <div class="col-6 col-md-4 col-lg-3 fade-in">
        <a href="<?= APP_URL ?>/auth/register.php" class="text-decoration-none">
          <div class="module-tile">
            <div class="tile-icon" style="background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>">
              <i class="<?= e($m['icon']) ?>"></i>
            </div>
            <h6><?= e($m['name']) ?></h6>
            <p><?= e(substr($m['description'], 0, 70)) ?>...</p>
            <div class="price-tag">From <?= formatCurrency((float)$m['monthly_price']) ?>/mo</div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-5">
      <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-lg" style="background:var(--navy);color:white;border-radius:50px;padding:.85rem 2.5rem;font-weight:700">
        Get Started — Choose Your Modules <i class="fas fa-arrow-right ms-2"></i>
      </a>
    </div>
  </div>
</section>

<!-- ═══════════════ HOW IT WORKS ═════════════════════════════════ -->
<section class="py-6 bg-white" id="how" style="padding:5rem 0">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">Simple Process</div>
      <h2 class="section-title">Up & Running in 3 Steps</h2>
    </div>
    <div class="row g-4 text-center">
      <div class="col-md-4 fade-in">
        <div class="step-circle">1</div>
        <h5 class="fw-bold text-navy">Register Your Business</h5>
        <p class="text-muted">Sign up with your business email. No credit card required for the 14-day free trial.</p>
        <div class="d-none d-md-block" style="height:2px;background:linear-gradient(to right,var(--green),var(--navy));margin-top:1rem;opacity:.3"></div>
      </div>
      <div class="col-md-4 fade-in delay-1">
        <div class="step-circle">2</div>
        <h5 class="fw-bold text-navy">Select Your Modules</h5>
        <p class="text-muted">Pick the modules that fit your business. Accounting, HRM, POS, Hotel, School — or all 20!</p>
        <div class="d-none d-md-block" style="height:2px;background:linear-gradient(to right,var(--navy),var(--green));margin-top:1rem;opacity:.3"></div>
      </div>
      <div class="col-md-4 fade-in delay-2">
        <div class="step-circle">3</div>
        <h5 class="fw-bold text-navy">Start Managing</h5>
        <p class="text-muted">Your workspace is ready instantly. Invite your team, import data, and go live immediately.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════ PRICING ═══════════════════════════════════════ -->
<section class="py-6 bg-light" id="pricing" style="padding:5rem 0">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">Simple Pricing</div>
      <h2 class="section-title">Plans for Every Business Size</h2>
      <p class="section-sub">All prices in Kenyan Shillings. Cancel anytime. 14-day free trial on all plans.</p>
    </div>

    <!-- Billing toggle -->
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center gap-3 bg-white border rounded-pill px-4 py-2">
        <span class="fw-600" id="lblMonthly">Monthly</span>
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="billingToggle" style="width:44px;height:22px;cursor:pointer">
        </div>
        <span id="lblAnnual">Annual <span class="badge bg-success small">Save 20%</span></span>
      </div>
    </div>

    <div class="row g-4 align-items-start justify-content-center">
      <?php foreach($plans as $plan): ?>
      <div class="col-md-6 col-lg-4 fade-in">
        <div class="pricing-card <?= $plan['is_popular'] ? 'popular' : '' ?>">
          <?php if($plan['is_popular']): ?><div class="popular-badge">Most Popular</div><?php endif; ?>
          <div class="pricing-name mb-2"><?= e($plan['name']) ?></div>
          <p class="text-muted small mb-3"><?= e($plan['description']) ?></p>
          <div class="pricing-price mb-1">
            <sup>KES</sup>
            <span class="price-monthly"><?= number_format((float)$plan['price_monthly']) ?></span>
            <span class="price-annual d-none"><?= number_format((float)$plan['price_annual'] / 12) ?></span>
            <span class="period">/mo</span>
          </div>
          <p class="text-muted small mb-4">
            <span class="annual-note d-none">Billed annually — KES <?= number_format((float)$plan['price_annual']) ?>/yr</span>
            <span class="monthly-note">No long-term commitment</span>
          </p>
          <ul class="pricing-features">
            <li><i class="fas fa-check"></i> Up to <?= $plan['max_users'] ?> users</li>
            <li><i class="fas fa-check"></i> <?= $plan['max_modules'] ?> modules included</li>
            <li><i class="fas fa-check"></i> Real-time analytics & reports</li>
            <li><i class="fas fa-check"></i> M-Pesa payment integration</li>
            <li><i class="fas fa-check"></i> Email & WhatsApp support</li>
            <?php if ($plan['max_users'] >= 25): ?>
            <li><i class="fas fa-check"></i> Priority support</li>
            <li><i class="fas fa-check"></i> Custom branding</li>
            <?php endif; ?>
            <?php if ($plan['max_users'] >= 100): ?>
            <li><i class="fas fa-check"></i> Dedicated account manager</li>
            <li><i class="fas fa-check"></i> API access & integrations</li>
            <li><i class="fas fa-check"></i> On-premise option available</li>
            <?php endif; ?>
          </ul>
          <a href="<?= APP_URL ?>/auth/register.php?plan=<?= $plan['id'] ?>"
             class="btn btn-pricing <?= $plan['is_popular'] ? 'btn-primary' : 'btn-outline-primary' ?>">
            Start Free Trial
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
      <p class="text-muted">Need a custom plan? <a href="#contact" class="text-green fw-600">Contact us</a> for enterprise pricing.</p>
    </div>
  </div>
</section>

<!-- ═══════════════ TESTIMONIALS ═════════════════════════════════ -->
<section class="py-6 bg-white" id="about" style="padding:5rem 0">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">Success Stories</div>
      <h2 class="section-title">Trusted by Businesses Across Kenya</h2>
    </div>
    <div class="row g-4">
      <?php
      $testimonials = [
        ['text'=>'Shanfix completely transformed how we manage our school. From fee collection to grade sheets — everything is now paperless and accurate.', 'name'=>'Mr. James Mwangi', 'role'=>'Principal, Sunrise Academy', 'initials'=>'JM'],
        ['text'=>'Our SACCO has grown 3x since we started using Shanfix. The loan management and savings tracking has made our operations very professional.', 'name'=>'Mrs. Grace Otieno', 'role'=>'CEO, Umoja SACCO', 'initials'=>'GO'],
        ['text'=>'Running a hotel is complex but Shanfix made it simple. Room bookings, housekeeping, and billing all in one place. Highly recommended!', 'name'=>'Mr. David Kamau', 'role'=>'Manager, Savanna Hotel', 'initials'=>'DK'],
        ['text'=>'I subscribed to POS and Accounting modules for my retail shop. The combination is perfect — my books are always balanced automatically.', 'name'=>'Ms. Amina Hassan', 'role'=>'Owner, Fashion Hub Nairobi', 'initials'=>'AH'],
        ['text'=>'Church member management and offering tracking is now seamless. Our pastoral team can focus on ministry while the system handles admin.', 'name'=>'Pastor John Mutua', 'role'=>'Senior Pastor, Life Church', 'initials'=>'JM'],
        ['text'=>'The HRM module saved us so much time. Payroll that used to take 3 days now takes 30 minutes. Our staff are paid accurately and on time.', 'name'=>'Ms. Sarah Njeri', 'role'=>'HR Director, TechCorp Kenya', 'initials'=>'SN'],
      ];
      foreach($testimonials as $t): ?>
      <div class="col-md-6 col-lg-4 fade-in">
        <div class="testimonial-card h-100">
          <div class="d-flex gap-1 mb-3">
            <?php for($i=0;$i<5;$i++): ?><i class="fas fa-star text-warning small"></i><?php endfor; ?>
          </div>
          <p class="testimonial-text">"<?= $t['text'] ?>"</p>
          <div class="testimonial-author">
            <div class="testimonial-avatar"><?= $t['initials'] ?></div>
            <div>
              <div class="testimonial-name"><?= $t['name'] ?></div>
              <div class="testimonial-role"><?= $t['role'] ?></div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ═══════════════ FAQ ═══════════════════════════════════════════ -->
<section class="py-6 bg-light" style="padding:5rem 0" id="faq">
  <div class="container">
    <div class="text-center mb-5">
      <div class="section-badge">FAQ</div>
      <h2 class="section-title">Frequently Asked Questions</h2>
    </div>
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="accordion" id="faqAccordion">
          <?php
          $faqs = [
            ['q'=>'Can I subscribe to just one module?', 'a'=>'Yes! You can subscribe to as few as one module. Our platform is fully modular — pay only for what you use. Add more modules anytime as your needs grow.'],
            ['q'=>'Is there a free trial available?', 'a'=>'Absolutely! Every new account gets a 14-day free trial with full access to selected modules. No credit card required to start.'],
            ['q'=>'Can multiple users access the system?', 'a'=>'Yes. You can invite your team members and assign them specific roles and permissions. The number of users depends on your subscription plan.'],
            ['q'=>'Is my data secure?', 'a'=>'Your data is encrypted, backed up daily, and protected with role-based access control. We follow industry-standard security practices.'],
            ['q'=>'Can the system be deployed on my own server/cPanel?', 'a'=>'Yes! Shanfix Workspace is designed to run on any cPanel hosting. Our team can assist with deployment and configuration.'],
            ['q'=>'Do you support M-Pesa payments?', 'a'=>'Yes. M-Pesa STK push is integrated across billing, POS, SACCO, rental, and other payment modules for seamless local transactions.'],
            ['q'=>'What happens when my subscription expires?', 'a'=>'You will receive email reminders 7 days before expiry. After expiry, your data is retained for 30 days before archiving. Renew anytime to regain access.'],
            ['q'=>'Can I get a customized module for my specific needs?', 'a'=>'Yes! We offer custom module development. Contact our team to discuss your specific business requirements and get a quote.'],
          ];
          foreach($faqs as $i => $faq): ?>
          <div class="accordion-item border mb-2 rounded overflow-hidden">
            <h2 class="accordion-header">
              <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?> fw-600" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>">
                <?= $faq['q'] ?>
              </button>
            </h2>
            <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted"><?= $faq['a'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════ CTA BANNER ════════════════════════════════════ -->
<section class="cta-section py-6 text-center" style="padding:5rem 0">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <h2 class="fw-800 mb-3">Ready to Transform Your Business?</h2>
        <p class="text-white-50 mb-4 fs-5">Join 500+ businesses already running smarter with Shanfix Workspace.</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
          <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-hero-primary btn-lg">
            Start Free Trial <i class="fas fa-arrow-right ms-2"></i>
          </a>
          <a href="#contact" class="btn btn-hero-outline btn-lg">
            Talk to Sales
          </a>
        </div>
        <p class="mt-3 text-white-50 small">No credit card required • 14-day free trial • Cancel anytime</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════ CONTACT ═══════════════════════════════════════ -->
<section class="py-6 bg-white" id="contact" style="padding:5rem 0">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-5">
        <div class="section-badge">Get In Touch</div>
        <h2 class="section-title text-start">We'd Love to Hear From You</h2>
        <p class="text-muted mt-3">Have questions? Our team is ready to help you find the perfect plan for your business.</p>
        <div class="mt-4 d-flex flex-column gap-3">
          <div class="d-flex gap-3 align-items-start">
            <div style="width:40px;height:40px;background:var(--green-pale);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--green);flex-shrink:0"><i class="fas fa-phone"></i></div>
            <div><div class="fw-600 text-navy">Phone / WhatsApp</div><div class="text-muted">+254 700 000 000</div></div>
          </div>
          <div class="d-flex gap-3 align-items-start">
            <div style="width:40px;height:40px;background:var(--green-pale);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--green);flex-shrink:0"><i class="fas fa-envelope"></i></div>
            <div><div class="fw-600 text-navy">Email</div><div class="text-muted">info@shanfix.co.ke</div></div>
          </div>
          <div class="d-flex gap-3 align-items-start">
            <div style="width:40px;height:40px;background:var(--green-pale);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--green);flex-shrink:0"><i class="fas fa-map-marker-alt"></i></div>
            <div><div class="fw-600 text-navy">Office</div><div class="text-muted">Nairobi, Kenya</div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card shadow-sm border-0 rounded-xl">
          <div class="card-body p-4">
            <?php
            $contactSent = false;
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
                // Simple contact form processing
                $contactSent = true;
            }
            if ($contactSent): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> Thank you! We'll get back to you within 24 hours.</div>
            <?php else: ?>
            <form method="POST">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Full Name *</label>
                  <input type="text" name="name" class="form-control" placeholder="Your name" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email Address *</label>
                  <input type="email" name="email" class="form-control" placeholder="you@company.com" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone Number</label>
                  <input type="tel" name="phone" class="form-control" placeholder="+254 700 000 000">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Business Type</label>
                  <select name="business_type" class="form-select">
                    <option>Select type</option>
                    <option>School</option><option>Hospital/Clinic</option><option>Hotel</option>
                    <option>SACCO</option><option>Retail/Wholesale</option><option>Church</option>
                    <option>Manufacturing</option><option>Other</option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Modules Interested In</label>
                  <div class="row g-2">
                    <?php foreach(array_chunk($modules, 5)[0] ?? [] as $m): ?>
                    <div class="col-6 col-md-4">
                      <div class="form-check"><input class="form-check-input" type="checkbox" name="interests[]" value="<?= e($m['slug']) ?>" id="int_<?= e($m['slug']) ?>"><label class="form-check-label small" for="int_<?= e($m['slug']) ?>"><?= e($m['name']) ?></label></div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label">Message</label>
                  <textarea name="message" class="form-control" rows="3" placeholder="Tell us about your business needs..."></textarea>
                </div>
                <div class="col-12">
                  <button type="submit" name="contact_submit" class="btn btn-primary px-4 py-2">
                    <i class="fas fa-paper-plane me-2"></i> Send Message
                  </button>
                </div>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════ FOOTER ════════════════════════════════════════ -->
<footer class="site-footer py-5">
  <div class="container">
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="footer-brand d-flex align-items-center gap-2 mb-3">
          <div style="width:36px;height:36px;background:var(--green);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white"><i class="fas fa-cubes"></i></div>
          <?= APP_NAME ?>
        </div>
        <p style="font-size:.875rem;line-height:1.7"><?= APP_TAGLINE ?>. Built for Kenyan businesses, ready for Africa.</p>
        <div class="mt-3">
          <a href="#" class="social-btn"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="social-btn"><i class="fab fa-twitter"></i></a>
          <a href="#" class="social-btn"><i class="fab fa-linkedin-in"></i></a>
          <a href="#" class="social-btn"><i class="fab fa-whatsapp"></i></a>
          <a href="#" class="social-btn"><i class="fab fa-youtube"></i></a>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Modules</h6>
        <?php foreach(array_slice($modules, 0, 8) as $m): ?>
        <a href="<?= APP_URL ?>/auth/register.php" class="footer-link"><?= e($m['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="col-6 col-lg-2">
        <h6>More Modules</h6>
        <?php foreach(array_slice($modules, 8) as $m): ?>
        <a href="<?= APP_URL ?>/auth/register.php" class="footer-link"><?= e($m['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Company</h6>
        <a href="#about"    class="footer-link">About Us</a>
        <a href="#"         class="footer-link">Careers</a>
        <a href="#"         class="footer-link">Blog</a>
        <a href="#contact"  class="footer-link">Contact</a>
        <a href="#"         class="footer-link">Partners</a>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Legal</h6>
        <a href="#" class="footer-link">Privacy Policy</a>
        <a href="#" class="footer-link">Terms of Use</a>
        <a href="#" class="footer-link">Cookie Policy</a>
        <a href="#" class="footer-link">Security</a>
      </div>
    </div>
    <div class="border-top pt-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="border-color:rgba(255,255,255,.08)!important">
      <span style="font-size:.8rem">&copy; <?= APP_YEAR ?> <?= APP_NAME ?>. All rights reserved.</span>
      <span style="font-size:.8rem">Made with <i class="fas fa-heart text-danger small"></i> in Kenya</span>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
// Billing toggle
const toggle = document.getElementById('billingToggle');
if (toggle) {
  toggle.addEventListener('change', () => {
    const isAnnual = toggle.checked;
    document.querySelectorAll('.price-monthly').forEach(el => el.classList.toggle('d-none', isAnnual));
    document.querySelectorAll('.price-annual').forEach(el => el.classList.toggle('d-none', !isAnnual));
    document.querySelectorAll('.annual-note').forEach(el => el.classList.toggle('d-none', !isAnnual));
    document.querySelectorAll('.monthly-note').forEach(el => el.classList.toggle('d-none', isAnnual));
    document.getElementById('lblMonthly').style.opacity = isAnnual ? '.5' : '1';
    document.getElementById('lblAnnual').style.opacity  = isAnnual ? '1'  : '.5';
  });
}
</script>
</body>
</html>
