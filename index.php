<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';


if (isLoggedIn()) {
    if (!empty($_SESSION['health_portal_mode'])) {
        header('Location: /modules/health/index.php'); exit;
    }
    redirect(($_SESSION['user_role'] === 'super_admin') ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
}

$stmt    = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order");
$modules = $stmt->fetchAll();

$stmt  = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly");
$plans = $stmt->fetchAll();

// USD exchange rate for pricing display (configurable in admin settings)
$usdRate = max(1, (float)(getSetting('usd_rate', '130') ?: 130));

// ── Company/contact settings — dynamically read from admin settings ────────
$sitePhone   = getSetting('company_phone',   '+254 700 000 000');
$siteEmail   = getSetting('support_email',   'info@orbitdesk.co.ke');
$siteAddress = getSetting('company_address', 'Nairobi, Kenya');
$siteHours   = getSetting('company_hours',   'Mon – Sat, 8AM – 8PM EAT');
$siteWebsite = getSetting('company_website', APP_URL);
$appTagline  = getSetting('app_tagline',     defined('APP_TAGLINE') ? APP_TAGLINE : 'Powering African Businesses');

// ── Module feature lists (shown in popup) ─────────────────────
$moduleFeatures = [
    'accounting'    => ['General ledger & chart of accounts','Invoice & receipt generation','Expense tracking & categorisation','VAT & tax computation reports','Bank reconciliation','Profit & loss and balance sheet'],
    'crm'           => ['Lead & opportunity pipeline','Contact & company management','Activity & follow-up logging','Deal stages & conversion tracking','Customer interaction history','Sales performance analytics'],
    'sales'         => ['Sales order management','Quotation & proposal builder','Customer & pricing management','Product catalogue with variants','Sales rep performance reports','Revenue & margin analytics'],
    'meetings'      => ['Meeting scheduling & invites','Agenda creation & management','Attendee RSVP tracking','Minutes & action item recording','Follow-up task assignments','Calendar & timeline view'],
    'school'        => ['Student enrollment & profiles','Fee collection & receipts','Exam results & grade reports','Attendance tracking','Class timetable management','Teacher & subject allocation'],
    'health'        => ['Patient records & history','Appointment booking & scheduling','Prescription & drug management','Doctor & department management','Billing & insurance claims','Lab results & diagnostics'],
    'pos'           => ['Fast barcode & manual checkout','Real-time inventory deduction','Receipt printing & emailing','Shift & cashier management','Daily sales & Z-report','Multi-payment method support'],
    'sacco'         => ['Member savings accounts','Loan application & processing','Share & dividend management','Repayment schedule tracking','Member statements & passbook','Compliance & audit reports'],
    'rental'        => ['Property & unit management','Tenant onboarding & profiles','Rent invoicing & collection','Maintenance request tracking','Lease agreement management','Vacancy & occupancy reports'],
    'church'        => ['Member registry & profiles','Offering & tithe collection','Cell & small group management','Event & service scheduling','Pastoral care records','SMS & communication tools'],
    'finance'       => ['Budget creation & monitoring','Income & expense categorisation','Multi-account management','Cash flow forecasting','Financial dashboard & KPIs','Exportable financial reports'],
    'hotel'         => ['Room inventory & type setup','Online & walk-in reservations','Check-in & check-out management','Housekeeping task tracking','Guest folios & billing','Occupancy & revenue reports'],
    'salon'         => ['Appointment booking & calendar','Service & pricing catalogue','Stylist & staff scheduling','Client visit history & notes','POS & product sales','Loyalty points & membership'],
    'retail'        => ['Product & category management','Supplier & purchase orders','Stock level alerts & reordering','Customer accounts & credit','Barcode label printing','Profit margin & sales reports'],
    'tour'          => ['Tour package creation & pricing','Booking & itinerary management','Guide & vehicle assignment','Customer billing & receipts','Booking calendar & availability','Revenue & booking reports'],
    'events'        => ['Event creation & scheduling','Ticket tiers & sales management','Attendee registration & check-in','Budget & vendor management','Sponsorship tracking','Post-event analytics & reports'],
    'manufacturing' => ['Production order management','Bill of materials (BOM)','Raw material stock tracking','Quality control & inspections','Production cost analysis','Manufacturing performance reports'],
    'hrm'           => ['Employee profiles & contracts','Payroll computation & payslips','Leave application & approvals','Attendance & time tracking','Performance appraisals','Departments & org chart'],
    'caryard'       => ['Vehicle stock management','Sales & commission tracking','Test drive scheduling','Financing & instalment plans','Customer CRM & follow-ups','Dealer performance reports'],
    'shopping-mall' => ['Shop unit & floor management','Tenant onboarding & leases','Automated rent billing','Maintenance & service requests','Utility billing management','Mall occupancy & revenue analytics'],
    'courier'       => ['Parcel creation & tracking','Delivery agent management','Real-time status updates','Route & branch management','Payment & invoice processing','Delivery performance reports'],
    'driving'       => ['Student enrollment & profiles','Instructor scheduling & assignments','Vehicle fleet management','Lesson booking & progress tracking','Theory & practical test management','Driving licence issuance records'],
];

// Build JS-ready module map
$moduleMap = [];
foreach ($modules as $m) {
    $kesMo  = (float)$m['monthly_price'];
    $kesAnn = (float)$m['annual_price'];
    $moduleMap[$m['slug']] = [
        'name'        => $m['name'],
        'desc'        => $m['description'],
        'icon'        => $m['icon'],
        'color'       => $m['color'],
        'category'    => $m['category'],
        'price'       => $kesMo,                                          // KES monthly (legacy)
        'price_ann'   => $kesAnn,                                         // KES annual
        'price_usd'   => $kesMo  > 0 ? round($kesMo  / $usdRate, 2) : 0, // USD monthly
        'price_ann_usd'=> $kesAnn > 0 ? round($kesAnn / $usdRate, 2) : 0, // USD annual
        'features'    => $moduleFeatures[$m['slug']] ?? [],
    ];
}

$contactSent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $contactSent = true;
}
?>
<?php
// ── Shared public header setup ─────────────────────────────────────────────
$pageTitle    = APP_NAME . ' — ' . $appTagline;
$metaDesc     = 'The all-in-one business management platform for African businesses. Manage accounting, CRM, HRM, POS, hotel, school, SACCO, health clinic and 20+ modules in one place. M-Pesa integrated.';
$canonicalUrl = APP_URL . '/';
$ogImage      = APP_URL . '/assets/images/og-banner-1200.png';
$activeNav    = 'home';
$bodyClass    = 'landing-body';
$_ogTitle     = $pageTitle; // used by JSON-LD below
$_ogDesc      = $metaDesc;  // used by JSON-LD below
ob_start();   // capture page-specific <head> extras for shared header
?>
<meta name="keywords" content="business management software Kenya, ERP Kenya, accounting software, CRM Kenya, school management, SACCO software, hotel management, M-Pesa integration, OrbitDesk">
<link rel="sitemap" type="application/xml" title="Sitemap" href="<?= APP_URL ?>/sitemap.xml">
<meta property="og:image:width"  content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt"    content="<?= e(APP_NAME) ?> — Business Management Platform">
<meta property="og:locale"       content="en_KE">
<meta name="twitter:image:alt"   content="<?= e(APP_NAME) ?> preview">

<!-- ═══════════════════════════════════════════════════════
     JSON-LD STRUCTURED DATA (Schema.org)
     Enables rich results in Google: sitelinks, FAQ,
     software ratings, and knowledge graph cards.
 ══════════════════════════════════════════════════════ -->
<?php
// Build plan offers for PriceSpecification
$_planOffers = [];
foreach ($plans as $_p) {
    $_planOffers[] = [
        '@type'         => 'Offer',
        'name'          => $_p['name'],
        'price'         => (string)(float)$_p['price_monthly'],
        'priceCurrency' => 'KES',
        'priceSpecification' => [
            '@type'        => 'UnitPriceSpecification',
            'price'        => (string)(float)$_p['price_monthly'],
            'priceCurrency'=> 'KES',
            'unitCode'     => 'MON',
        ],
        'eligibleCustomerType' => 'Business',
        'availability'  => 'https://schema.org/InStock',
        'url'           => APP_URL . '/auth/register.php',
    ];
}

// Module list for ItemList
$_modItems = [];
foreach ($modules as $_i => $_m) {
    $_modItems[] = [
        '@type'    => 'ListItem',
        'position' => $_i + 1,
        'name'     => $_m['name'],
        'url'      => APP_URL . '/module/' . $_m['slug'],
        'description' => $_m['description'] ?? '',
    ];
}

// FAQ entries
$_faqs = [
    ['q' => 'What is ' . APP_NAME . '?',
     'a' => APP_NAME . ' is an all-in-one business management platform for African businesses. It includes 22+ integrated modules — accounting, CRM, HRM, POS, school management, SACCO, hotel, health clinic, and more — all in one place with M-Pesa payment integration.'],
    ['q' => 'Is M-Pesa payment integration included?',
     'a' => 'Yes. ' . APP_NAME . ' includes native M-Pesa Daraja API integration (STK push via KopoKopo) for accepting payments, generating invoices, and automating billing — all without needing a third-party service.'],
    ['q' => 'How many modules does ' . APP_NAME . ' include?',
     'a' => APP_NAME . ' includes 22 business modules: Accounting, CRM, Sales, HRM, POS, School Management, Health/Clinic, SACCO, Rental Properties, Church, Finance, Hotel, Salon, Retail, Tour & Travel, Events, Manufacturing, Car Yard, Shopping Mall, Courier, and Driving School.'],
    ['q' => 'Is there a free trial?',
     'a' => 'Yes. Every new organisation gets a 14-day free trial with full access to all selected modules. No credit card is required to start.'],
    ['q' => 'How much does ' . APP_NAME . ' cost?',
     'a' => 'Plans start at KES 4,999 per month (Starter), KES 12,999/mo (Professional), and KES 29,999/mo (Enterprise). Annual billing saves up to 17%. All prices include M-Pesa integration, local support, and full feature access.'],
    ['q' => 'Can I use ' . APP_NAME . ' for multiple businesses?',
     'a' => 'Yes. ' . APP_NAME . ' is a multi-tenant platform. Each organisation has a completely separate workspace, user base, and data. You can manage multiple client organisations from the super-admin panel.'],
    ['q' => 'Does ' . APP_NAME . ' work on mobile phones?',
     'a' => 'Yes. ' . APP_NAME . ' is fully mobile-responsive and installable as a Progressive Web App (PWA) on Android and iOS. It is optimised for low-bandwidth networks common in Kenya and East Africa.'],
    ['q' => 'Where is my data stored?',
     'a' => 'Your data is stored on a cPanel-hosted MySQL database. ' . APP_NAME . ' uses AES-256 encryption for sensitive fields, enforces HTTPS, and includes role-based access control so only authorised users can access your data.'],
];

$_jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        // 1. Organization
        [
            '@type'       => 'Organization',
            '@id'         => APP_URL . '/#organization',
            'name'        => APP_NAME,
            'url'         => APP_URL,
            'logo'        => [
                '@type'   => 'ImageObject',
                'url'     => APP_URL . '/assets/images/favicon.svg',
                'width'   => 512,
                'height'  => 512,
            ],
            'description' => APP_TAGLINE . '. All-in-one business management for African businesses.',
            'email'       => $siteEmail,
            'telephone'   => $sitePhone,
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $siteAddress,
                'addressLocality' => 'Nairobi',
                'addressCountry'  => 'KE',
            ],
            'areaServed'  => ['KE', 'UG', 'TZ', 'RW', 'ET', 'NG', 'GH', 'ZA'],
            'knowsAbout'  => ['ERP Software', 'Business Management', 'M-Pesa Integration', 'SaaS Africa'],
            'sameAs'      => [],
        ],
        // 2. WebSite (enables Google Sitelinks Search Box)
        [
            '@type'           => 'WebSite',
            '@id'             => APP_URL . '/#website',
            'url'             => APP_URL,
            'name'            => APP_NAME,
            'description'     => APP_TAGLINE,
            'publisher'       => ['@id' => APP_URL . '/#organization'],
            'inLanguage'      => 'en-KE',
        ],
        // 3. SoftwareApplication
        [
            '@type'                  => 'SoftwareApplication',
            '@id'                    => APP_URL . '/#software',
            'name'                   => APP_NAME,
            'alternateName'          => 'OrbitDesk',
            'description'            => 'All-in-one business management platform with 22 modules including accounting, CRM, HRM, POS, SACCO, school management, hotel, and health clinic. Built for African businesses with M-Pesa integration.',
            'applicationCategory'    => 'BusinessApplication',
            'applicationSubCategory' => 'ERP, CRM, Accounting, HRM, POS',
            'operatingSystem'        => 'Web Browser, Android (PWA), iOS (PWA)',
            'url'                    => APP_URL,
            'screenshot'             => APP_URL . '/assets/images/og-banner-1200.png',
            'inLanguage'             => 'en-KE',
            'isAccessibleForFree'    => true,
            'offers'                 => ['@type' => 'AggregateOffer', 'offerCount' => count($plans), 'lowPrice' => !empty($plans) ? (float)$plans[0]['price_monthly'] : 0, 'highPrice' => !empty($plans) ? (float)end($plans)['price_monthly'] : 0, 'priceCurrency' => 'KES'],
            'publisher'              => ['@id' => APP_URL . '/#organization'],
            'featureList'            => 'Accounting & Bookkeeping, CRM, HRM & Payroll, Point of Sale (POS), School Management, SACCO System, Hotel Management, Health Clinic, Rental Properties, Church Management, Finance & Budgeting, Salon & Barbershop, Retail & Wholesale, Tour & Travel, Events Management, Manufacturing, Car Yard, Shopping Mall, Courier Management, Driving School',
        ],
        // 4. WebPage
        [
            '@type'           => 'WebPage',
            '@id'             => APP_URL . '/#webpage',
            'url'             => APP_URL,
            'name'            => $_ogTitle,
            'description'     => $_ogDesc,
            'isPartOf'        => ['@id' => APP_URL . '/#website'],
            'about'           => ['@id' => APP_URL . '/#software'],
            'inLanguage'      => 'en-KE',
            'datePublished'   => '2024-01-01',
            'dateModified'    => date('Y-m-d'),
            'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => APP_URL . '/assets/images/og-banner-1200.png'],
        ],
        // 5. ItemList — all modules
        [
            '@type'           => 'ItemList',
            'name'            => APP_NAME . ' — All Business Modules',
            'description'     => '22 integrated business management modules for African businesses',
            'url'             => APP_URL . '/#modules',
            'numberOfItems'   => count($_modItems),
            'itemListElement' => $_modItems,
        ],
        // 6. FAQPage — rich snippet in Google
        [
            '@type'       => 'FAQPage',
            '@id'         => APP_URL . '/#faq',
            'mainEntity'  => array_map(fn($f) => [
                '@type'          => 'Question',
                'name'           => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $_faqs),
        ],
    ],
];
echo '<script type="application/ld+json">' . json_encode($_jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
// Clean up vars
unset($_planOffers, $_modItems, $_faqs, $_jsonLd, $_ogTitle, $_ogDesc, $_ogImg, $_ogUrl);
?>
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/landing.css?v=<?= @filemtime(__DIR__ . '/assets/css/landing.css') ?: time() ?>" rel="stylesheet">
<?php
$extraHeadHtml = ob_get_clean();
require_once __DIR__ . '/includes/header-public.php';
?>

<!-- ══════════════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════════════ -->
<section class="od-hero" id="hero">
  <!-- Overlay layers -->
  <div class="hero-mesh"></div>
  <div class="hero-bottom-shimmer"></div>
  <!-- Flat outlined shapes (replaces the old glow orbs — no gradients) -->
  <div class="shape shape-ring" aria-hidden="true"></div>
  <div class="shape shape-ring-2" aria-hidden="true"></div>
  <div class="shape shape-square" aria-hidden="true"></div>
  <div class="container position-relative" style="z-index:4">
    <div class="row align-items-center g-5">
      <!-- Left: Copy -->
      <div class="col-lg-6">
        <div class="hero-eyebrow">
          <span class="dot"></span>
          Kenya's #1 Business Management Suite
        </div>
        <h1 class="hero-headline">
          <span class="hero-word" style="--w:0">One</span>
          <span class="hero-word" style="--w:1">Platform.</span><br>
          <span class="hero-word grad-text" style="--w:2">20+</span>
          <span class="hero-word grad-text" style="--w:3">Business</span><br>
          <span class="hero-word" style="--w:4">Solutions.</span>
        </h1>
        <p class="hero-sub">
          OrbitDesk Workspace centralises every aspect of your business — accounting, HR, POS, hotel, school, SACCO, and more — in a single, powerful, cloud-based platform.
        </p>
        <div class="hero-actions">
          <a href="<?= APP_URL ?>/auth/register.php" class="btn-od-primary">
            Start Free Trial <i class="fas fa-arrow-right"></i>
          </a>
          <a href="#modules" class="btn-od-ghost">
            <i class="fas fa-th-large"></i> Browse Modules
          </a>
        </div>
        <div class="hero-trust">
          <div class="trust-item"><i class="fas fa-check-circle"></i> No credit card</div>
          <div class="trust-item"><i class="fas fa-check-circle"></i> 14-day free trial</div>
          <div class="trust-item"><i class="fas fa-check-circle"></i> Cancel anytime</div>
          <div class="trust-item"><i class="fas fa-check-circle"></i> M-Pesa ready</div>
        </div>
      </div>

      <!-- Right: Dashboard Mockup -->
      <div class="col-lg-6 d-none d-lg-block">
        <div class="od-dashboard-wrap">
          <!-- Floating badges -->
          <div class="float-badge float-badge-1">
            <div class="fb-icon" style="background:#e6f5ee;color:#1A8A4E"><i class="fas fa-chart-line"></i></div>
            <div>
              <div style="font-size:.7rem;font-weight:800;color:#0B2D4E">Revenue Up</div>
              <div style="font-size:.65rem;color:#1A8A4E">+24% this month</div>
            </div>
          </div>
          <div class="float-badge float-badge-2">
            <div class="fb-icon" style="background:#fff3cd;color:#f59e0b"><i class="fas fa-bell"></i></div>
            <div>
              <div style="font-size:.7rem;font-weight:800;color:#0B2D4E">New Booking</div>
              <div style="font-size:.65rem;color:#64748b">Room 204 — checked in</div>
            </div>
          </div>

          <!-- Dashboard -->
          <div class="od-dashboard">
            <div class="dash-chrome">
              <div class="dot" style="background:#ef4444"></div>
              <div class="dot" style="background:#f59e0b"></div>
              <div class="dot" style="background:#22c55e"></div>
              <div class="dash-url-bar">app.orbitdesk.co.ke/dashboard</div>
            </div>
            <div class="dash-body">
              <div class="dash-header-row">
                <div class="dash-title" id="dashTitle">Business Overview</div>
                <div class="dash-period">This Month</div>
              </div>

              <!-- Interactive: switches the KPI set + chart below -->
              <div class="dash-tabs" role="tablist" aria-label="Dashboard preview">
                <button class="dash-tab active" role="tab" aria-selected="true"  data-dash="overview">Overview</button>
                <button class="dash-tab"        role="tab" aria-selected="false" data-dash="sales">Sales</button>
                <button class="dash-tab"        role="tab" aria-selected="false" data-dash="hr">HR</button>
                <button class="dash-tab"        role="tab" aria-selected="false" data-dash="pos">POS</button>
              </div>

              <!-- Server-rendered default ("overview"); JS swaps it per tab -->
              <div class="dash-kpis" id="dashKpis">
                <div class="dash-kpi">
                  <div class="kv green">KES 2.4M</div>
                  <div class="kl">Revenue</div>
                  <div class="kt up"><i class="fas fa-arrow-up" style="font-size:.55rem"></i> 24%</div>
                </div>
                <div class="dash-kpi">
                  <div class="kv" style="color:#38bdf8">1,284</div>
                  <div class="kl">Customers</div>
                  <div class="kt up"><i class="fas fa-arrow-up" style="font-size:.55rem"></i> 12%</div>
                </div>
                <div class="dash-kpi">
                  <div class="kv amber">48</div>
                  <div class="kl">Pending</div>
                  <div class="kt down"><i class="fas fa-arrow-down" style="font-size:.55rem"></i> 3%</div>
                </div>
                <div class="dash-kpi">
                  <div class="kv" style="color:#a78bfa">99.9%</div>
                  <div class="kl">Uptime</div>
                  <div class="kt up">Stable</div>
                </div>
              </div>

              <div class="dash-chart-section">
                <div class="dash-chart-label" id="dashChartLabel">Monthly Revenue Trend</div>
                <div class="dash-bars" id="dashBars">
                  <?php $heights=[30,45,28,58,40,52,65,72,50,68,80,100]; foreach($heights as $i=>$h): ?>
                  <div class="dash-bar<?= $i>=9?' hi':'' ?>" data-h="<?=$h?>" style="height:<?=$h?>%"></div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="dash-modules">
                <?php foreach(array_slice($modules,0,6) as $m): ?>
                <div class="dash-mod">
                  <div class="dash-mod-icon" style="background:<?=e($m['color'])?>22;color:<?=e($m['color'])?>">
                    <i class="<?=e($m['icon'])?>"></i>
                  </div>
                  <div class="dash-mod-name"><?=e(explode(' ',$m['name'])[0])?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     TRUSTED BY
══════════════════════════════════════════════════════════ -->
<section class="trusted-strip">
  <div class="container">
    <div class="d-flex flex-column flex-sm-row align-items-center gap-3">
      <span class="trusted-label text-nowrap">Trusted by</span>
      <?php
      $industries = [
        ['Schools',       'fas fa-school'],
        ['Hotels',        'fas fa-hotel'],
        ['SACCOs',        'fas fa-piggy-bank'],
        ['Hospitals',     'fas fa-hospital'],
        ['Salons',        'fas fa-cut'],
        ['Retail Shops',  'fas fa-store'],
        ['Churches',      'fas fa-church'],
        ['NGOs',          'fas fa-hands-helping'],
        ['Car Yards',       'fas fa-car'],
        ['Driving Schools', 'fas fa-steering-wheel'],
        ['Manufacturing',   'fas fa-industry'],
      ];
      ?>
      <!-- Marquee: the list is duplicated so the loop is seamless at -50% -->
      <div class="marquee flex-grow-1 w-100">
        <div class="marquee-track">
          <?php for ($pass = 0; $pass < 2; $pass++): ?>
            <?php foreach($industries as $ind): ?>
            <span class="industry-pill" <?= $pass ? 'aria-hidden="true"' : '' ?>>
              <i class="<?=$ind[1]?>" style="color:#1A8A4E"></i> <?=$ind[0]?>
            </span>
            <?php endforeach; ?>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     IMPACT STATS
══════════════════════════════════════════════════════════ -->
<section class="impact-section">
  <div class="container">
    <div class="row g-4 justify-content-center">
      <?php
      $stats = [
        ['500+', '', 'Businesses Served',    'Across Kenya & East Africa'],
        ['20',   '+', 'Business Modules',    'Cover every industry vertical'],
        ['99.9', '%', 'Platform Uptime',     'Enterprise-grade reliability'],
        ['24',   '/7', 'Expert Support',     'Phone, WhatsApp & email'],
      ];
      foreach($stats as $i=>$s): ?>
      <div class="col-6 col-lg-3 <?=$i>0?'border-start border-secondary border-opacity-25':''?>">
        <div class="impact-stat reveal" data-delay="<?=$i?>">
          <div class="i-num" data-counter data-target="<?=preg_replace('/\D/','',$s[0])?>">0<span><?=$s[1]?></span></div>
          <div class="i-label fw-bold text-white mb-1" style="font-size:1rem"><?=$s[2]?></div>
          <div class="i-label"><?=$s[3]?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     FEATURES
══════════════════════════════════════════════════════════ -->
<section id="features" style="padding:6rem 0;background:white">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <span class="od-section-eyebrow">Why OrbitDesk?</span>
      <h2 class="od-section-title">Built for Real Business<br>Growth in Africa</h2>
      <p class="od-section-sub">Enterprise features — local pricing. Everything your team needs to run operations seamlessly.</p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['icon'=>'fas fa-puzzle-piece',  'bg'=>'#e6f5ee', 'ic'=>'#1A8A4E', 'title'=>'Fully Modular',             'desc'=>'Subscribe only to the modules your business needs. Add or remove at any time with zero setup required.'],
        ['icon'=>'fas fa-shield-halved', 'bg'=>'#eff6ff', 'ic'=>'#3b82f6', 'title'=>'Enterprise Security',        'desc'=>'Role-based access control, encrypted data storage, CSRF protection, and full activity audit logs.'],
        ['icon'=>'fas fa-mobile-screen', 'bg'=>'#faf5ff', 'ic'=>'#8b5cf6', 'title'=>'Mobile First Design',        'desc'=>'Optimised for every device. Your team can manage operations from the field, office, or anywhere.'],
        ['icon'=>'fas fa-chart-mixed',   'bg'=>'#fff7ed', 'ic'=>'#f59e0b', 'title'=>'Real-time Analytics',        'desc'=>'Live dashboards with KPIs, charts, and exportable reports to power every business decision.'],
        ['icon'=>'fas fa-users-gear',    'bg'=>'#fef2f2', 'ic'=>'#ef4444', 'title'=>'Multi-user & Roles',         'desc'=>'Invite unlimited staff and assign precise module-level permissions to keep your data controlled.'],
        ['icon'=>'fas fa-headset',       'bg'=>'#ecfdf5', 'ic'=>'#10b981', 'title'=>'Local 24/7 Support',         'desc'=>'Our Nairobi-based support team is available via phone, WhatsApp, and email around the clock.'],
        ['icon'=>'fas fa-mobile-alt',    'bg'=>'#fef9c3', 'ic'=>'#ca8a04', 'title'=>'M-Pesa Integration',         'desc'=>'Native Daraja API integration. Accept M-Pesa STK push payments across all billing modules.'],
        ['icon'=>'fas fa-server',        'bg'=>'#f0fdfa', 'ic'=>'#14b8a6', 'title'=>'Cloud or On-Premise',        'desc'=>'Hosted on our secure cloud or deploy to your own cPanel. You own your data, always.'],
      ];
      foreach($features as $i=>$f): ?>
      <div class="col-md-6 col-lg-3 reveal delay-<?=$i%4?>">
        <div class="feature-card">
          <div class="feat-icon-wrap" style="background:<?=$f['bg']?>;color:<?=$f['ic']?>">
            <i class="<?=$f['icon']?>"></i>
          </div>
          <h6><?=$f['title']?></h6>
          <p><?=$f['desc']?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     MODULES
══════════════════════════════════════════════════════════ -->
<section id="modules" class="od-modules-bg" style="padding:6rem 0">
  <div class="container">
    <div class="text-center mb-4 reveal">
      <span class="od-section-eyebrow"><?=count($modules)?> Modules Available</span>
      <h2 class="od-section-title">Choose the Right Modules<br>for Your Business</h2>
      <p class="od-section-sub" id="modulesSub">Each module is a complete, production-ready solution. Combine multiple for a full ERP experience.</p>

      <!-- Currency toggle — synced with the pricing section toggle -->
      <div class="d-flex align-items-center justify-content-center gap-2 mt-3">
        <span style="font-size:.8rem;color:#94a3b8;font-weight:600">Prices in:</span>
        <div style="display:inline-flex;background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:999px;overflow:hidden">
          <button id="modBtnUSD" class="mod-cur-btn active" onclick="setCurrency('USD')" style="border:none;background:transparent;padding:.28rem .9rem;font-size:.78rem;font-weight:700;color:#64748b;cursor:pointer;transition:all .18s;border-radius:999px">
            $ USD
          </button>
          <button id="modBtnKES" class="mod-cur-btn" onclick="setCurrency('KES')" style="border:none;background:transparent;padding:.28rem .9rem;font-size:.78rem;font-weight:700;color:#64748b;cursor:pointer;transition:all .18s;border-radius:999px">
            KES
          </button>
        </div>
      </div>
    </div>

    <?php
    // Distinct categories for the filter tabs
    $modCats = [];
    foreach ($modules as $m) {
        $c = trim((string)($m['category'] ?? ''));
        if ($c !== '') $modCats[$c] = ($modCats[$c] ?? 0) + 1;
    }
    ksort($modCats);
    ?>
    <!-- Interactive filter + live search -->
    <div class="mod-toolbar reveal">
      <div class="mod-search-wrap">
        <i class="fas fa-search"></i>
        <label class="visually-hidden" for="modSearch">Search modules</label>
        <input type="search" id="modSearch" class="mod-search" autocomplete="off"
               placeholder="Search modules — e.g. payroll, hotel, POS…">
        <button type="button" class="mod-search-clear" id="modSearchClear" aria-label="Clear search">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="mod-filter-tabs" role="group" aria-label="Filter modules by category">
        <button class="mod-filter-tab active" data-cat="all" aria-pressed="true">
          All <span class="cnt"><?= count($modules) ?></span>
        </button>
        <?php foreach ($modCats as $cat => $cnt): ?>
        <button class="mod-filter-tab" data-cat="<?= e(strtolower($cat)) ?>" aria-pressed="false">
          <?= e($cat) ?> <span class="cnt"><?= (int)$cnt ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="row g-3" id="modGrid">
      <?php foreach($modules as $i => $m):
        $kesMo = (float)$m['monthly_price'];
        $usdMo = $kesMo > 0 ? round($kesMo / $usdRate, 2) : 0;
      ?>
      <div class="col-6 col-md-4 col-lg-3 mod-col reveal-scale"
           style="--i:<?= $i % 8 ?>"
           data-cat="<?= e(strtolower(trim((string)($m['category'] ?? '')))) ?>"
           data-name="<?= e(strtolower($m['name'] . ' ' . $m['slug'] . ' ' . ($m['description'] ?? ''))) ?>">
        <div class="mod-tile" role="button" tabindex="0"
             onclick="openModuleModal('<?=e($m['slug'])?>')"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModuleModal('<?=e($m['slug'])?>')}"
             style="cursor:pointer">
          <div class="mod-tile-icon" style="background:<?=e($m['color'])?>1a;color:<?=e($m['color'])?>">
            <i class="<?=e($m['icon'])?>"></i>
          </div>
          <h6><?=e($m['name'])?></h6>
          <p><?=e(mb_substr($m['description'],0,68))?>…</p>
          <span class="price-pill mod-price-pill"
                data-usd="<?= number_format($usdMo, 2) ?>"
                data-kes="<?= number_format($kesMo, 0, '.', ',') ?>">
            From $ <?= number_format($usdMo, 2) ?>/mo
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="mod-empty" id="modEmpty">
      <i class="fas fa-search fa-2x mb-3 d-block" style="opacity:.35"></i>
      <p class="mb-0">No modules match <strong id="modEmptyTerm"></strong>. Try a different search.</p>
    </div>

    <div class="text-center mt-5 reveal">
      <a href="<?= APP_URL ?>/auth/register.php" class="btn-od-primary" style="font-size:1rem;padding:.95rem 2.25rem">
        Get Started — Pick Your Modules <i class="fas fa-arrow-right"></i>
      </a>
      <p class="text-muted small mt-3">14-day free trial. No credit card needed.</p>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════════════════════ -->
<section id="how" class="od-how-bg" style="padding:6rem 0">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <span class="od-section-eyebrow">Simple Onboarding</span>
      <h2 class="od-section-title">Up & Running in<br>Under 10 Minutes</h2>
    </div>
    <div class="row g-4 justify-content-center process-row">
      <div class="process-connector d-none d-md-block"></div>
      <?php
      $steps = [
        ['num'=>'1','icon'=>'fas fa-building','title'=>'Register Your Business','desc'=>'Create your organisation account with your business name and email. No credit card required to get started.'],
        ['num'=>'2','icon'=>'fas fa-th-large','title'=>'Select Your Modules','desc'=>'Browse the module catalogue and subscribe only to what you need — one module or the full 20-module suite.'],
        ['num'=>'3','icon'=>'fas fa-rocket','title'=>'Go Live Immediately','desc'=>'Your workspace is provisioned instantly. Invite your team, configure settings, and start managing operations.'],
      ];
      foreach($steps as $i=>$s): ?>
      <div class="col-md-4 reveal delay-<?=$i?>">
        <div class="process-step">
          <div class="process-num"><?=$s['num']?></div>
          <h5><?=$s['title']?></h5>
          <p><?=$s['desc']?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     TESTIMONIALS
══════════════════════════════════════════════════════════ -->
<section id="about" class="od-testimonials-bg" style="padding:6rem 0">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <span class="od-section-eyebrow">Client Success</span>
      <h2 class="od-section-title">Trusted by Leaders<br>Across Every Industry</h2>
    </div>
    <div class="row g-4">
      <?php
      $testis = [
        ['q'=>'OrbitDesk transformed how we manage our school. Fee collection, exam results, attendance — everything is now paperless, accurate, and instant.','name'=>'Mr. James Mwangi','role'=>'Principal, Sunrise Academy','init'=>'JM','bg'=>'#0B2D4E'],
        ['q'=>'Our SACCO has grown 3x since adopting OrbitDesk. Loan management, savings tracking, and member statements — all handled professionally.','name'=>'Mrs. Grace Otieno','role'=>'CEO, Umoja SACCO','init'=>'GO','bg'=>'#1A8A4E'],
        ['q'=>'Managing a hotel is complex but OrbitDesk simplified everything. Bookings, housekeeping, billing — one platform, complete visibility.','name'=>'Mr. David Kamau','role'=>'Manager, Savanna Hotel','init'=>'DK','bg'=>'#7c3aed'],
        ['q'=>'The POS and Accounting module combination is perfect for my shop. Inventory updates in real-time and my books close themselves.','name'=>'Ms. Amina Hassan','role'=>'Owner, Fashion Hub Nairobi','init'=>'AH','bg'=>'#b45309'],
        ['q'=>'Church member management and tithe tracking is now seamless. Our pastoral team focuses on ministry while OrbitDesk handles admin.','name'=>'Pastor John Mutua','role'=>'Senior Pastor, Life Church','init'=>'JM','bg'=>'#0e7490'],
        ['q'=>'Payroll that took 3 days now runs in 30 minutes. Every staff member is paid accurately and on time. The HRM module is a game-changer.','name'=>'Ms. Sarah Njeri','role'=>'HR Director, TechCorp Kenya','init'=>'SN','bg'=>'#be185d'],
      ];
      foreach($testis as $i=>$t): ?>
      <div class="col-md-6 col-lg-4 reveal delay-<?=$i%3?>">
        <div class="testi-card">
          <div class="testi-stars"><?php for($j=0;$j<5;$j++):?><i class="fas fa-star"></i><?php endfor;?></div>
          <p class="testi-quote"><?=e($t['q'])?></p>
          <div class="testi-author">
            <div class="testi-avatar" style="background:<?=$t['bg']?>"><?=$t['init']?></div>
            <div>
              <div class="testi-name"><?=$t['name']?></div>
              <div class="testi-role"><?=$t['role']?></div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     PRICING
══════════════════════════════════════════════════════════ -->
<section id="pricing" class="od-pricing-bg" style="padding:6rem 0">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <span class="od-section-eyebrow">Transparent Pricing</span>
      <h2 class="od-section-title">Plans Built for<br>Every Business Size</h2>
      <p class="od-section-sub" id="pricingSubtitle">All prices in USD. Start free, scale as you grow. No hidden fees.</p>
    </div>

    <!-- Billing cycle + currency controls -->
    <div class="text-center mb-4 d-flex flex-wrap align-items-center justify-content-center gap-3">

      <!-- Billing cycle toggle -->
      <div class="billing-toggle-wrap" style="margin-bottom:0">
        <span id="lblMonthly" class="active">Monthly</span>
        <div class="form-check form-switch mb-0" style="padding-left:2.5em">
          <input class="form-check-input" type="checkbox" id="billingToggle" style="width:44px;height:22px;cursor:pointer">
        </div>
        <span id="lblAnnual">Annual &nbsp;<span class="badge" style="background:#dcfce7;color:#16a34a;font-size:.7rem;font-weight:700">Save 20%</span></span>
      </div>

      <!-- Currency pill -->
      <div class="currency-pill" role="group" aria-label="Currency">
        <button id="btnUSD" class="active" onclick="setCurrency('USD')" aria-pressed="true">
          $ USD
        </button>
        <button id="btnKES" onclick="setCurrency('KES')" aria-pressed="false">
          KES
        </button>
      </div>
    </div>

    <!-- Plan cards -->
    <div class="row g-4 justify-content-center align-items-start">
      <?php foreach ($plans as $plan):
        $pop       = (bool)$plan['is_popular'];
        $kesMo     = (float)$plan['price_monthly'];
        $kesAnnMo  = $plan['price_annual'] > 0 ? round($plan['price_annual'] / 12, 2) : 0;
        $kesAnnTot = (float)$plan['price_annual'];
        $usdMo     = $kesMo    > 0 ? round($kesMo    / $usdRate, 2) : 0;
        $usdAnnMo  = $kesAnnMo > 0 ? round($kesAnnMo / $usdRate, 2) : 0;
        $usdAnnTot = $kesAnnTot> 0 ? round($kesAnnTot/ $usdRate, 2) : 0;
        $savePct   = ($kesMo > 0 && $kesAnnMo > 0) ? max(0, round((1 - $kesAnnMo / $kesMo) * 100)) : 0;
      ?>
      <div class="col-md-6 col-lg-4 reveal">
        <div class="od-plan-card <?= $pop ? 'popular' : '' ?>">
          <?php if ($pop): ?>
          <div class="pop-label"><i class="fas fa-fire me-1"></i> Most Popular</div>
          <?php endif; ?>

          <div class="plan-name"><?= e($plan['name']) ?></div>
          <p class="text-muted small mb-3" style="font-size:.82rem"><?= e($plan['description']) ?></p>

          <!-- Price display — data-* attrs hold all four values; JS picks the right one -->
          <div class="d-flex align-items-end gap-1 mb-1">
            <div class="plan-price">
              <sup class="plan-cur">$</sup><!-- JS updates to $ or KES -->
              <span class="plan-price-val"
                    data-usd-mo="<?= number_format($usdMo, 2) ?>"
                    data-usd-ann-mo="<?= number_format($usdAnnMo, 2) ?>"
                    data-kes-mo="<?= number_format($kesMo, 0, '.', ',') ?>"
                    data-kes-ann-mo="<?= number_format($kesAnnMo, 0, '.', ',') ?>">
                <?= number_format($usdMo, 2) ?>
              </span>
              <span class="per">/mo</span>
            </div>
          </div>

          <!-- Billing note — JS fills text depending on cycle + currency -->
          <p class="plan-note text-muted mb-0" style="font-size:.78rem;min-height:1.4rem"
             data-usd-ann-total="<?= number_format($usdAnnTot, 2) ?>"
             data-kes-ann-total="<?= number_format($kesAnnTot, 0, '.', ',') ?>"
             data-save-pct="<?= $savePct ?>">
          </p>

          <ul class="plan-features">
            <li><i class="fas fa-check"></i> Up to <strong><?= $plan['max_users'] ?> users</strong></li>
            <li><i class="fas fa-check"></i> <strong><?= $plan['max_modules'] ?> modules</strong> included</li>
            <li><i class="fas fa-check"></i> Real-time analytics &amp; reports</li>
            <li><i class="fas fa-check"></i> M-Pesa payment integration</li>
            <li><i class="fas fa-check"></i> 14-day free trial included</li>
            <li><i class="fas fa-check"></i> Email &amp; WhatsApp support</li>
            <?php if ($plan['max_users'] >= 25): ?>
            <li><i class="fas fa-check"></i> Priority support queue</li>
            <li><i class="fas fa-check"></i> Custom branding &amp; logo</li>
            <?php endif; ?>
            <?php if ($plan['max_users'] >= 100): ?>
            <li><i class="fas fa-check"></i> Dedicated account manager</li>
            <li><i class="fas fa-check"></i> API access &amp; webhooks</li>
            <li><i class="fas fa-check"></i> On-premise deployment option</li>
            <?php endif; ?>
          </ul>

          <a href="<?= APP_URL ?>/auth/register.php?plan=<?= $plan['id'] ?>"
             class="<?= $pop ? 'btn-plan-primary' : 'btn-plan-outline' ?>">
            Start Free Trial <i class="fas fa-arrow-right ms-1" style="font-size:.8rem"></i>
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4 reveal">
      <p class="text-muted small">
        Need a custom enterprise plan?
        <a href="#contact" class="fw-700" style="color:var(--od-green)">Talk to our sales team</a>
        — we'll build a package for your exact needs.
      </p>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     FAQ
══════════════════════════════════════════════════════════ -->
<section id="faq" class="od-faq-bg" style="padding:6rem 0">
  <div class="container">
    <div class="row g-5 align-items-start">
      <div class="col-lg-4 reveal">
        <span class="od-section-eyebrow">FAQ</span>
        <h2 class="od-section-title text-start" style="font-size:2rem">Common<br>Questions</h2>
        <p class="text-muted mt-3" style="font-size:.9rem">Can't find your answer? <a href="#contact" style="color:var(--od-green);font-weight:700">Chat with our team</a> — we respond within minutes.</p>
        <div class="mt-4 p-4 rounded-3" style="background:#e6f5ee;border-left:4px solid #1A8A4E">
          <div class="fw-700 text-navy mb-1" style="font-size:.9rem"><i class="fas fa-headset me-2" style="color:#1A8A4E"></i>Need a live demo?</div>
          <p class="text-muted small mb-2">We'll walk you through the platform and answer every question.</p>
          <a href="#contact" class="btn-od-primary" style="font-size:.82rem;padding:.55rem 1.1rem">Book a Demo</a>
        </div>
      </div>
      <div class="col-lg-8 reveal delay-1">
        <div class="accordion od-accordion" id="faqAcc">
          <?php
          $faqs = [
            ['q'=>'Can I subscribe to just one module?','a'=>'Yes! You can subscribe to as few as one module. OrbitDesk is fully modular — pay only for what you use. Add or remove modules any time as your business evolves.'],
            ['q'=>'Is there a free trial available?','a'=>'Every new account gets a 14-day free trial with full access to your selected modules. No credit card is required to start — just sign up and explore.'],
            ['q'=>'Can multiple users access the system at once?','a'=>'Absolutely. You can invite your entire team and assign them roles with module-specific permissions. User limits depend on your subscription plan.'],
            ['q'=>'Is my business data secure?','a'=>'Your data is encrypted at rest and in transit, backed up daily, and protected with role-based access control and CSRF tokens on every action. We follow industry-standard security practices.'],
            ['q'=>'Can I deploy OrbitDesk on my own server?','a'=>'Yes! OrbitDesk is designed to run on standard cPanel hosting — no special server required. Our team will assist with deployment and configuration at no extra cost.'],
            ['q'=>'How does M-Pesa integration work?','a'=>'OrbitDesk integrates directly with Safaricom\'s Daraja API. Customers can pay via M-Pesa STK push across POS, billing, SACCO, school fees, rental, and all other payment modules.'],
            ['q'=>'What happens when my subscription expires?','a'=>'You\'ll receive email reminders 7 days before expiry. After expiry, your data is safely retained for 30 days before archiving. Renew at any point to instantly regain full access.'],
            ['q'=>'Can you build a custom module for my business?','a'=>'Yes! We offer custom module development for unique business requirements. Contact us with your needs and we\'ll scope the project and provide a quote within 48 hours.'],
          ];
          foreach($faqs as $i=>$faq): ?>
          <div class="accordion-item">
            <h2 class="accordion-header">
              <button class="accordion-button <?=$i>0?'collapsed':''?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?=$i?>">
                <?=$faq['q']?>
              </button>
            </h2>
            <div id="faq<?=$i?>" class="accordion-collapse collapse <?=$i===0?'show':''?>" data-bs-parent="#faqAcc">
              <div class="accordion-body"><?=$faq['a']?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     CTA BANNER
══════════════════════════════════════════════════════════ -->
<section class="od-cta-section text-center">
  <div class="cta-glow"></div>
  <div class="container position-relative" style="z-index:2">
    <div class="reveal">
      <span class="od-section-eyebrow" style="background:rgba(26,138,78,.2);color:#4ade93;border:1px solid rgba(26,138,78,.3)">Ready to Transform Your Business?</span>
    </div>
    <h2 class="reveal delay-1">Join 500+ Businesses<br>Running Smarter with OrbitDesk</h2>
    <p class="reveal delay-2">Start your free 14-day trial today. No setup fees, no credit card, no commitment.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap reveal delay-3">
      <a href="<?= APP_URL ?>/auth/register.php" class="btn-od-primary" style="font-size:1rem;padding:.95rem 2.25rem">
        Start Free Trial <i class="fas fa-arrow-right"></i>
      </a>
      <a href="#contact" class="btn-od-ghost" style="font-size:1rem;padding:.95rem 2.25rem">
        <i class="fas fa-calendar-alt"></i> Book a Demo
      </a>
    </div>
    <div class="cta-trust-row reveal delay-4">
      <div class="cta-trust-item"><i class="fas fa-lock"></i> Enterprise Security</div>
      <div class="cta-trust-item"><i class="fas fa-bolt"></i> Instant Setup</div>
      <div class="cta-trust-item"><i class="fas fa-mobile-alt"></i> M-Pesa Ready</div>
      <div class="cta-trust-item"><i class="fas fa-headset"></i> 24/7 Support</div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     CONTACT
══════════════════════════════════════════════════════════ -->
<section id="contact" class="od-contact-bg" style="padding:6rem 0">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-5 reveal">
        <span class="od-section-eyebrow">Get in Touch</span>
        <h2 class="od-section-title text-start" style="font-size:2.2rem">We'd Love to<br>Hear From You</h2>
        <p class="text-muted mt-3" style="font-size:.95rem">Whether you have questions about a module, need a custom quote, or want a live demo — our team is ready.</p>

        <div class="mt-4">
          <div class="contact-info-card">
            <div class="ci-icon"><i class="fas fa-phone"></i></div>
            <div>
              <div class="ci-label">Phone / WhatsApp</div>
              <div class="ci-value"><?= htmlspecialchars($sitePhone, ENT_QUOTES) ?></div>
            </div>
          </div>
          <div class="contact-info-card">
            <div class="ci-icon"><i class="fas fa-envelope"></i></div>
            <div>
              <div class="ci-label">Email</div>
              <div class="ci-value">
                <a href="mailto:<?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>" style="color:inherit;text-decoration:none">
                  <?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>
                </a>
              </div>
            </div>
          </div>
          <div class="contact-info-card">
            <div class="ci-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div>
              <div class="ci-label">Head Office</div>
              <div class="ci-value"><?= nl2br(htmlspecialchars($siteAddress, ENT_QUOTES)) ?></div>
            </div>
          </div>
          <div class="contact-info-card">
            <div class="ci-icon"><i class="fas fa-clock"></i></div>
            <div>
              <div class="ci-label">Business Hours</div>
              <div class="ci-value"><?= htmlspecialchars($siteHours, ENT_QUOTES) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-7 reveal delay-2">
        <div class="od-contact-form">
          <h5 class="fw-800 mb-4" style="color:#0B2D4E"><i class="fas fa-paper-plane me-2" style="color:#1A8A4E"></i>Send Us a Message</h5>
          <?php if($contactSent): ?>
          <div class="alert border-0 rounded-3" style="background:#e6f5ee;color:#157a42">
            <i class="fas fa-check-circle me-2"></i> <strong>Message received!</strong> We'll get back to you within 24 hours.
          </div>
          <?php else: ?>
          <form method="POST">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Full Name *</label>
                <input type="text" name="name" class="form-control" placeholder="Your full name" required>
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
                  <option value="">Select industry…</option>
                  <option>School / College</option><option>Hospital / Clinic</option><option>Hotel / Hospitality</option>
                  <option>SACCO / Microfinance</option><option>Retail / Wholesale</option><option>Church / Religious</option>
                  <option>Manufacturing</option><option>Car Yard</option><option>Driving School</option><option>NGO / Non-Profit</option><option>Other</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Modules of Interest</label>
                <div class="row g-2">
                  <?php foreach(array_slice($modules,0,10) as $m): ?>
                  <div class="col-6 col-md-4">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="interests[]" value="<?=e($m['slug'])?>" id="int_<?=e($m['slug'])?>">
                      <label class="form-check-label" style="font-size:.82rem" for="int_<?=e($m['slug'])?>"><?=e($m['name'])?></label>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label">Your Message</label>
                <textarea name="message" class="form-control" rows="3" placeholder="Tell us about your business and what you're looking for…"></textarea>
              </div>
              <div class="col-12">
                <button type="submit" name="contact_submit" class="btn-od-primary w-100" style="justify-content:center;padding:.9rem">
                  <i class="fas fa-paper-plane"></i> Send Message
                </button>
              </div>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     FOOTER
══════════════════════════════════════════════════════════ -->
<!-- ══ Module Detail Modal ══════════════════════════════════════ -->
<div class="modal fade" id="modDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:580px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:20px;overflow:hidden">

      <!-- Coloured header -->
      <div id="mmHeader" style="padding:1.75rem 2rem 1.5rem;position:relative">
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
        <div class="d-flex align-items-center gap-3">
          <div id="mmIconWrap" style="width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:white;flex-shrink:0">
            <i id="mmIcon"></i>
          </div>
          <div>
            <div id="mmCat" style="color:rgba(255,255,255,.72);font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:3px"></div>
            <h4 id="mmName" class="fw-800 text-white mb-0" style="font-size:1.25rem;line-height:1.2"></h4>
          </div>
        </div>
      </div>

      <!-- Body -->
      <div class="modal-body px-4 pt-4 pb-3">
        <p id="mmDesc" class="text-muted mb-4" style="font-size:.9rem;line-height:1.75"></p>

        <div class="d-flex align-items-center gap-2 mb-3">
          <span style="width:22px;height:22px;border-radius:50%;background:#f59e0b;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-star" style="color:white;font-size:.58rem"></i>
          </span>
          <h6 class="fw-700 text-dark mb-0" style="font-size:.9rem">Key Features</h6>
        </div>

        <div class="row g-2" id="mmFeatures"></div>
      </div>

      <!-- Footer -->
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 px-4 py-3"
           style="background:#f8fafc;border-top:1px solid #e9ecef">
        <div>
          <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;font-weight:600">Starting from</div>
          <div class="fw-800 text-dark" id="mmPrice" style="font-size:1.15rem"></div>
          <div class="text-muted" style="font-size:.7rem">per month + VAT</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          <a href="<?= APP_URL ?>/auth/register.php" class="btn fw-700 text-white px-4"
             style="border-radius:50px;background:#1A8A4E">
            <i class="fas fa-rocket me-2"></i>Start Free Trial
          </a>
        </div>
      </div>

    </div>
  </div>
</div>
<!-- ══ /Module Detail Modal ══════════════════════════════════════ -->

<button type="button" class="back-to-top" id="backToTop" aria-label="Back to top">
  <i class="fas fa-arrow-up"></i>
</button>

<?php
ob_start();
?>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
(function () {
  'use strict';

  var REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Scroll reveal (all variants, with stagger) ───────────── */
  var revealSel = '.reveal, .reveal-left, .reveal-right, .reveal-scale';
  var revealEls = document.querySelectorAll(revealSel);

  if (REDUCED) {
    revealEls.forEach(function (el) { el.classList.add('visible'); });
  } else {
    var revealObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('visible');
        revealObs.unobserve(e.target);
      });
    }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { revealObs.observe(el); });
  }

  /* ── Animated counters ────────────────────────────────────── */
  function animateCounter(el) {
    var target = +el.dataset.target || 0;
    var span   = el.querySelector('span');
    var suffix = span ? span.textContent : '';
    if (REDUCED) {
      el.textContent = target.toLocaleString();
      if (span) el.appendChild(span);
      return;
    }
    var start = 0, duration = 1600;
    function step(ts) {
      if (!start) start = ts;
      var p    = Math.min((ts - start) / duration, 1);
      var ease = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(ease * target).toLocaleString();
      if (span) el.appendChild(span);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var counterObs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      animateCounter(e.target);
      counterObs.unobserve(e.target);
    });
  }, { threshold: .5 });
  document.querySelectorAll('[data-counter]').forEach(function (el) { counterObs.observe(el); });

  /* ── Interactive dashboard mockup ─────────────────────────── */
  var DASH = {
    overview: {
      title: 'Business Overview',
      label: 'Monthly Revenue Trend',
      bars:  [30,45,28,58,40,52,65,72,50,68,80,100],
      kpis:  [
        { v: 'KES 2.4M', c: 'green',            l: 'Revenue',   t: '24%', d: 'up'   },
        { v: '1,284',    c: '', s: '#38bdf8',   l: 'Customers', t: '12%', d: 'up'   },
        { v: '48',       c: 'amber',            l: 'Pending',   t: '3%',  d: 'down' },
        { v: '99.9%',    c: '', s: '#a78bfa',   l: 'Uptime',    t: 'Stable', d: 'up' }
      ]
    },
    sales: {
      title: 'Sales Performance',
      label: 'Orders Closed Per Month',
      bars:  [22,38,55,41,62,48,70,85,66,78,92,100],
      kpis:  [
        { v: 'KES 940K', c: 'green',          l: 'Pipeline', t: '18%', d: 'up'   },
        { v: '312',      c: '', s: '#38bdf8', l: 'Orders',   t: '9%',  d: 'up'   },
        { v: '27',       c: 'amber',          l: 'Quotes',   t: '5%',  d: 'down' },
        { v: '68%',      c: '', s: '#a78bfa', l: 'Win Rate', t: '4%',  d: 'up'   }
      ]
    },
    hr: {
      title: 'HR & Payroll',
      label: 'Headcount Growth',
      bars:  [40,42,45,44,50,55,58,60,64,70,74,80],
      kpis:  [
        { v: '184',      c: '', s: '#38bdf8', l: 'Employees', t: '6%',  d: 'up'   },
        { v: 'KES 3.1M', c: 'green',          l: 'Payroll',   t: '2%',  d: 'up'   },
        { v: '12',       c: 'amber',          l: 'On Leave',  t: '1%',  d: 'down' },
        { v: '96%',      c: '', s: '#a78bfa', l: 'Attendance', t: 'Stable', d: 'up' }
      ]
    },
    pos: {
      title: 'Point of Sale',
      label: 'Daily Transactions',
      bars:  [55,62,48,70,66,80,74,88,92,84,96,100],
      kpis:  [
        { v: 'KES 412K', c: 'green',          l: 'Today',     t: '31%', d: 'up'   },
        { v: '1,047',    c: '', s: '#38bdf8', l: 'Receipts',  t: '14%', d: 'up'   },
        { v: '6',        c: 'amber',          l: 'Low Stock', t: '2%',  d: 'down' },
        { v: '3',        c: '', s: '#a78bfa', l: 'Shifts',    t: 'Open', d: 'up'  }
      ]
    }
  };

  var dashKpis  = document.getElementById('dashKpis');
  var dashBars  = document.getElementById('dashBars');
  var dashTitle = document.getElementById('dashTitle');
  var dashLabel = document.getElementById('dashChartLabel');

  function renderDash(key, animate) {
    var d = DASH[key];
    if (!d || !dashKpis || !dashBars) return;

    if (dashTitle) dashTitle.textContent = d.title;
    if (dashLabel) dashLabel.textContent = d.label;

    dashKpis.innerHTML = d.kpis.map(function (k) {
      var style = k.s ? ' style="color:' + k.s + '"' : '';
      var arrow = k.d === 'up'
        ? '<i class="fas fa-arrow-up" style="font-size:.55rem"></i> '
        : '<i class="fas fa-arrow-down" style="font-size:.55rem"></i> ';
      var showArrow = /%$/.test(k.t) ? arrow : '';
      return '<div class="dash-kpi">' +
               '<div class="kv ' + (k.c || '') + '"' + style + '>' + k.v + '</div>' +
               '<div class="kl">' + k.l + '</div>' +
               '<div class="kt ' + k.d + '">' + showArrow + k.t + '</div>' +
             '</div>';
    }).join('');

    dashBars.innerHTML = d.bars.map(function (h, i) {
      return '<div class="dash-bar' + (i >= 9 ? ' hi' : '') + '" data-h="' + h + '"></div>';
    }).join('');

    var bars = dashBars.querySelectorAll('.dash-bar');
    if (REDUCED || !animate) {
      bars.forEach(function (b) { b.style.height = b.dataset.h + '%'; });
    } else {
      requestAnimationFrame(function () {
        bars.forEach(function (b, i) {
          setTimeout(function () { b.style.height = b.dataset.h + '%'; }, i * 45);
        });
      });
    }
  }

  if (dashKpis) {
    // The "overview" state is already server-rendered, so we don't paint it
    // again on load — we only re-render (with animation) on scroll-in / tab click.
    var dashObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        renderDash(document.querySelector('.dash-tab.active').dataset.dash, true);
        dashObs.disconnect();
      });
    }, { threshold: .3 });
    dashObs.observe(dashKpis);

    document.querySelectorAll('.dash-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.dash-tab').forEach(function (t) {
          t.classList.remove('active');
          t.setAttribute('aria-selected', 'false');
        });
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        renderDash(tab.dataset.dash, true);
      });
    });
  }

  /* ── Module filter + live search ──────────────────────────── */
  var modCols   = Array.prototype.slice.call(document.querySelectorAll('.mod-col'));
  var modSearch = document.getElementById('modSearch');
  var modClear  = document.getElementById('modSearchClear');
  var modEmpty  = document.getElementById('modEmpty');
  var modTerm   = document.getElementById('modEmptyTerm');
  var activeCat = 'all';

  function applyModFilter() {
    var q = (modSearch && modSearch.value || '').trim().toLowerCase();
    var shown = 0;

    modCols.forEach(function (col) {
      var okCat  = (activeCat === 'all') || (col.dataset.cat === activeCat);
      var okText = !q || (col.dataset.name || '').indexOf(q) !== -1;
      var show   = okCat && okText;
      col.classList.toggle('filtered-out', !show);
      if (show) shown++;
    });

    if (modClear) modClear.classList.toggle('show', q.length > 0);
    if (modEmpty) {
      modEmpty.classList.toggle('show', shown === 0);
      if (shown === 0 && modTerm) modTerm.textContent = q ? '"' + q + '"' : 'that filter';
    }
  }

  document.querySelectorAll('.mod-filter-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.mod-filter-tab').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('active');
      btn.setAttribute('aria-pressed', 'true');
      activeCat = btn.dataset.cat;
      applyModFilter();
    });
  });

  if (modSearch) {
    var t;
    modSearch.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(applyModFilter, 120);
    });
    // Let Esc clear the field
    modSearch.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { modSearch.value = ''; applyModFilter(); }
    });
  }
  if (modClear) {
    modClear.addEventListener('click', function () {
      modSearch.value = '';
      modSearch.focus();
      applyModFilter();
    });
  }

  /* ── Pricing toggle ───────────────────────────────────────── */
  var USD_RATE  = <?= (float)$usdRate ?>;
  var activeCur = localStorage.getItem('landingCurrency') || 'USD';

  function updatePricing() {
    var toggle = document.getElementById('billingToggle');
    if (!toggle) return;
    var annual = toggle.checked;
    var isUSD  = (activeCur === 'USD');
    var curSym = isUSD ? '$' : 'KES ';

    var sub = document.getElementById('pricingSubtitle');
    if (sub) {
      sub.textContent = isUSD
        ? 'All prices in USD. Start free, scale as you grow. No hidden fees.'
        : 'All prices in KES. Start free, scale as you grow. No hidden fees.';
    }

    var lblM = document.getElementById('lblMonthly');
    var lblA = document.getElementById('lblAnnual');
    if (lblM) lblM.className = annual ? '' : 'active';
    if (lblA) lblA.className = annual ? 'active' : '';

    ['btnUSD', 'btnKES'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var on = (id === 'btnUSD') === isUSD;
      el.classList.toggle('active', on);
      el.setAttribute('aria-pressed', String(on));
    });

    document.querySelectorAll('.plan-cur').forEach(function (el) { el.textContent = curSym; });

    document.querySelectorAll('.plan-price-val').forEach(function (el) {
      var val = annual ? (isUSD ? el.dataset.usdAnnMo : el.dataset.kesAnnMo)
                       : (isUSD ? el.dataset.usdMo    : el.dataset.kesMo);
      if (REDUCED) { el.textContent = val || '0'; return; }
      el.classList.add('flip');
      setTimeout(function () {
        el.textContent = val || '0';
        el.classList.remove('flip');
      }, 160);
    });

    document.querySelectorAll('.plan-note').forEach(function (el) {
      var annTot = isUSD ? el.dataset.usdAnnTotal : el.dataset.kesAnnTotal;
      var save   = el.dataset.savePct;
      var cur    = isUSD ? 'USD' : 'KES';
      el.textContent = annual
        ? 'Billed annually — ' + cur + ' ' + annTot + '/yr' + (save > 0 ? ' · Save ' + save + '%' : '')
        : 'No long-term commitment';
    });

    document.querySelectorAll('.mod-price-pill').forEach(function (el) {
      el.textContent = isUSD ? 'From $ ' + el.dataset.usd + '/mo'
                             : 'From KES ' + el.dataset.kes + '/mo';
    });

    var modUSD = document.getElementById('modBtnUSD');
    var modKES = document.getElementById('modBtnKES');
    if (modUSD && modKES) {
      var base = ';border:none;padding:.28rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s';
      var on   = 'background:#0B2D4E;color:#fff;border-radius:999px';
      var off  = 'background:transparent;color:#64748b;border-radius:999px';
      modUSD.style.cssText = (isUSD  ? on : off) + base;
      modKES.style.cssText = (!isUSD ? on : off) + base;
    }
  }

  window.setCurrency = function (cur) {
    activeCur = cur;
    localStorage.setItem('landingCurrency', cur);
    updatePricing();
  };

  var billingToggle = document.getElementById('billingToggle');
  if (billingToggle) billingToggle.addEventListener('change', updatePricing);
  updatePricing();

  /* ── Module detail modal ──────────────────────────────────── */
  var MOD_INFO = <?= json_encode($moduleMap, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

  window.openModuleModal = function (slug) {
    var m = MOD_INFO[slug];
    if (!m) return;

    // Flat solid header — no gradient
    var header = document.getElementById('mmHeader');
    if (header) header.style.background = m.color;

    var set = function (id, prop, val) {
      var el = document.getElementById(id);
      if (el) el[prop] = val;
    };
    set('mmIcon', 'className', m.icon);
    set('mmCat',  'textContent', m.category);
    set('mmName', 'textContent', m.name);
    set('mmDesc', 'textContent', m.desc);

    var isUSD     = (activeCur === 'USD');
    var primary   = isUSD ? '$ ' + Number(m.price_usd).toFixed(2) + '/mo'
                          : 'KES ' + Number(m.price).toLocaleString('en-KE') + '/mo';
    var secondary = isUSD ? '≈ KES ' + Number(m.price).toLocaleString('en-KE') + '/mo'
                          : '≈ $ ' + Number(m.price_usd).toFixed(2) + '/mo';
    var annLine = (m.price_ann > 0)
      ? (isUSD ? ' · $ ' + Number(m.price_ann_usd).toFixed(2) + '/yr (≈ KES ' + Number(m.price_ann).toLocaleString('en-KE') + ')'
               : ' · KES ' + Number(m.price_ann).toLocaleString('en-KE') + '/yr (≈ $ ' + Number(m.price_ann_usd).toFixed(2) + ')')
      : '';

    set('mmPrice', 'innerHTML',
      '<span class="fw-bold">' + primary + '</span>' +
      '<span class="text-muted small ms-2">' + secondary + '</span>' +
      (annLine ? '<div class="text-muted small mt-1" style="font-size:.75rem">' + annLine + '</div>' : ''));

    set('mmFeatures', 'innerHTML', (m.features || []).map(function (f) {
      return '<div class="col-sm-6">' +
               '<div class="d-flex align-items-start gap-2 px-2 py-2 rounded-2" style="background:#f0fdf4">' +
                 '<i class="fas fa-check-circle flex-shrink-0 mt-1" style="color:#1A8A4E;font-size:.72rem"></i>' +
                 '<span style="font-size:.8rem;color:#1e293b;line-height:1.45">' + f + '</span>' +
               '</div>' +
             '</div>';
    }).join(''));

    var modalEl = document.getElementById('modDetailModal');
    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
  };

  /* ── Active nav highlight (observer, not scroll handler) ──── */
  var navLinks = document.querySelectorAll('.od-nav-links a');
  var sections = document.querySelectorAll('section[id]');
  if (sections.length && navLinks.length) {
    var navObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        navLinks.forEach(function (a) {
          a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id);
        });
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach(function (s) { navObs.observe(s); });
  }

  /* ── Back to top ──────────────────────────────────────────────
     The scroll-progress bar and navbar .scrolled state are owned by
     includes/footer-public.php — deliberately not duplicated here. */
  var toTop   = document.getElementById('backToTop');
  var ticking = false;

  function onScroll() {
    var st = window.pageYOffset || document.documentElement.scrollTop;
    if (toTop) toTop.classList.toggle('show', st > 600);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(onScroll);
  }, { passive: true });
  onScroll();

  if (toTop) {
    toTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: REDUCED ? 'auto' : 'smooth' });
    });
  }
})();
</script>
<?php
$extraBodyJs = ob_get_clean();
require_once __DIR__ . '/includes/footer-public.php';
?>
