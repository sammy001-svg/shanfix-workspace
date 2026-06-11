<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
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
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$_ogTitle = APP_NAME . ' — ' . $appTagline;
$_ogDesc  = 'The all-in-one business management platform for African businesses. Manage accounting, CRM, HRM, POS, hotel, school, SACCO, health clinic and 20+ modules in one place. M-Pesa integrated.';
$_ogImg   = APP_URL . '/assets/images/og-banner-1200.png';
$_ogUrl   = APP_URL . '/';
?>
<title><?= e($_ogTitle) ?></title>
<meta name="description"       content="<?= e($_ogDesc) ?>">
<meta name="keywords"          content="business management software Kenya, ERP Kenya, accounting software, CRM Kenya, school management, SACCO software, hotel management, M-Pesa integration, OrbitDesk">
<meta name="author"            content="<?= APP_NAME ?>">
<link rel="canonical"          href="<?= e($_ogUrl) ?>">
<!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
<meta property="og:type"        content="website">
<meta property="og:site_name"   content="<?= e(APP_NAME) ?>">
<meta property="og:title"       content="<?= e($_ogTitle) ?>">
<meta property="og:description" content="<?= e($_ogDesc) ?>">
<meta property="og:image"       content="<?= e($_ogImg) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height"content="630">
<meta property="og:image:alt"   content="<?= e(APP_NAME) ?> — Business Management Platform">
<meta property="og:url"         content="<?= e($_ogUrl) ?>">
<meta property="og:locale"      content="en_KE">
<!-- Twitter Card -->
<meta name="twitter:card"       content="summary_large_image">
<meta name="twitter:title"      content="<?= e($_ogTitle) ?>">
<meta name="twitter:description"content="<?= e($_ogDesc) ?>">
<meta name="twitter:image"      content="<?= e($_ogImg) ?>">
<meta name="twitter:image:alt"  content="<?= e(APP_NAME) ?> preview">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<!-- PWA & Sitemap discovery -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.php">
<link rel="sitemap" type="application/xml" title="Sitemap" href="<?= APP_URL ?>/sitemap.xml">
<meta name="theme-color" content="#1A8A4E">

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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   ORBITDESK LANDING PAGE — V2 PROFESSIONAL REDESIGN
   ═══════════════════════════════════════════════════════════ */
:root {
  --od-navy:   #0B2D4E;
  --od-green:  #1A8A4E;
  --od-glow:   rgba(26,138,78,.25);
  --od-mesh:   rgba(255,255,255,.04);
}
html { scroll-behavior: smooth; }
body.landing-body { font-family: 'Inter', system-ui, sans-serif; background: #fff; overflow-x: hidden; }

/* ─── Scroll Progress Bar ────────────────────────────────── */
#scroll-progress {
  position: fixed; top: 0; left: 0; height: 3px;
  background: linear-gradient(90deg, var(--od-green), #22d3a5);
  z-index: 99999; width: 0%; transition: width .1s linear;
}

/* ─── Navbar ─────────────────────────────────────────────── */
.od-nav {
  position: fixed; top: 0; left: 0; right: 0;
  padding: .9rem 0;
  z-index: 9000;
  transition: all .3s ease;
}
.od-nav.scrolled {
  background: rgba(7,25,44,.97);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  padding: .65rem 0;
  box-shadow: 0 1px 0 rgba(255,255,255,.06);
}
.od-nav .nav-logo {
  display: flex; align-items: center; gap: .6rem;
  text-decoration: none;
}
.od-nav .logo-mark {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--od-green), #22c27a);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; color: white; font-size: .85rem; letter-spacing: -.5px;
  box-shadow: 0 4px 12px rgba(26,138,78,.4);
}
.od-nav .logo-name { font-size: 1.1rem; font-weight: 800; color: white; }
.od-nav .logo-name span { color: var(--od-green); }
.od-nav-links { display: flex; align-items: center; gap: .25rem; }
.od-nav-links a {
  color: rgba(255,255,255,.75); font-size: .875rem; font-weight: 500;
  padding: .45rem .9rem; border-radius: 8px; transition: all .2s;
  text-decoration: none;
}
.od-nav-links a:hover { color: white; background: rgba(255,255,255,.08); }
.od-nav .nav-cta-login {
  color: rgba(255,255,255,.8); font-size: .875rem; font-weight: 500;
  padding: .45rem 1.1rem; border: 1px solid rgba(255,255,255,.2);
  border-radius: 8px; text-decoration: none; transition: all .2s;
}
.od-nav .nav-cta-login:hover { background: rgba(255,255,255,.1); color: white; }
.od-nav .nav-cta-start {
  background: var(--od-green); color: white; font-size: .875rem; font-weight: 600;
  padding: .5rem 1.25rem; border-radius: 8px; text-decoration: none;
  transition: all .2s; white-space: nowrap;
}
.od-nav .nav-cta-start:hover { background: #157a42; color: white; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(26,138,78,.4); }

/* ─── Hero ───────────────────────────────────────────────── */
.od-hero {
  position: relative;
  min-height: 100vh;
  /* Photo background */
  background-image: url('assets/images/Gemini_Generated_Image_dt5jpmdt5jpmdt5j.png');
  background-size: cover;
  background-position: center 30%;
  background-attachment: fixed; /* subtle parallax on scroll */
  display: flex; align-items: center;
  padding: 120px 0 80px;
  overflow: hidden;
}

/* Layer 1 – deep navy colour wash over the photo */
.od-hero::before {
  content: '';
  position: absolute; inset: 0; z-index: 0;
  background: linear-gradient(
    120deg,
    rgba(5,15,31,.88)  0%,
    rgba(7,25,52,.82)  40%,
    rgba(5,15,31,.70)  100%
  );
}

/* Layer 2 – green accent gradient on right side */
.od-hero::after {
  content: '';
  position: absolute; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 55% 70% at 90% 50%, rgba(26,138,78,.22) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 10% 10%, rgba(11,45,78,.5) 0%, transparent 70%);
  pointer-events: none;
}

/* Mesh grid overlay (sits above colour layers) */
.od-hero .hero-mesh {
  position: absolute; inset: 0; z-index: 1; pointer-events: none;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 60px 60px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 0%, black 40%, transparent 100%);
  -webkit-mask-image: radial-gradient(ellipse 80% 80% at 50% 0%, black 40%, transparent 100%);
}

/* Frosted shimmer line at the very bottom */
.od-hero .hero-bottom-shimmer {
  position: absolute; bottom: 0; left: 0; right: 0; height: 1px; z-index: 3;
  background: linear-gradient(90deg, transparent, rgba(74,222,147,.35), rgba(56,189,248,.2), transparent);
}

/* Glow orbs */
.od-hero .orb-1 {
  position: absolute; top: -80px; right: -80px; z-index: 2;
  width: 600px; height: 600px; border-radius: 50%;
  background: radial-gradient(circle, rgba(26,138,78,.18) 0%, transparent 70%);
  pointer-events: none;
}
.od-hero .orb-2 {
  position: absolute; bottom: -120px; left: -60px; z-index: 2;
  width: 500px; height: 500px; border-radius: 50%;
  background: radial-gradient(circle, rgba(11,45,78,.35) 0%, transparent 70%);
  pointer-events: none;
}
.od-hero .orb-3 {
  position: absolute; top: 40%; left: 40%; z-index: 2;
  width: 300px; height: 300px; border-radius: 50%;
  background: radial-gradient(circle, rgba(26,138,78,.09) 0%, transparent 70%);
  animation: orb-float 8s ease-in-out infinite;
  pointer-events: none;
}
@keyframes orb-float {
  0%,100% { transform: translate(0,0); }
  33%      { transform: translate(30px,-20px); }
  66%      { transform: translate(-20px,30px); }
}

.hero-eyebrow {
  display: inline-flex; align-items: center; gap: .5rem;
  background: rgba(26,138,78,.15); border: 1px solid rgba(26,138,78,.35);
  color: #4ade93; border-radius: 50px; padding: .35rem 1rem .35rem .65rem;
  font-size: .8rem; font-weight: 600; letter-spacing: .3px; margin-bottom: 1.5rem;
}
.hero-eyebrow .dot {
  width: 6px; height: 6px; background: #4ade93; border-radius: 50%;
  animation: blink 2s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.3;} }

.od-hero h1 {
  font-size: clamp(2.4rem, 5.5vw, 4rem);
  font-weight: 900; line-height: 1.08; color: white;
  letter-spacing: -1.5px; margin-bottom: 1.5rem;
}
.od-hero h1 .grad-text {
  background: linear-gradient(135deg, #4ade93 0%, #22d3a5 50%, #38bdf8 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}
.od-hero .hero-sub {
  font-size: 1.1rem; color: rgba(255,255,255,.6); line-height: 1.8;
  max-width: 520px; margin-bottom: 2.25rem; font-weight: 400;
}
.od-hero .hero-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 3rem; }
.btn-od-primary {
  background: var(--od-green); color: white; border: none;
  padding: .85rem 1.75rem; border-radius: 10px; font-weight: 700;
  font-size: .95rem; transition: all .25s; text-decoration: none;
  display: inline-flex; align-items: center; gap: .5rem;
}
.btn-od-primary:hover { background: #157a42; color: white; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(26,138,78,.4); }
.btn-od-ghost {
  background: rgba(255,255,255,.07); color: rgba(255,255,255,.85);
  border: 1px solid rgba(255,255,255,.15); padding: .85rem 1.75rem;
  border-radius: 10px; font-weight: 600; font-size: .95rem;
  transition: all .25s; text-decoration: none;
  display: inline-flex; align-items: center; gap: .5rem;
}
.btn-od-ghost:hover { background: rgba(255,255,255,.12); color: white; border-color: rgba(255,255,255,.3); }

/* Trust badges under CTA */
.hero-trust {
  display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
}
.hero-trust .trust-item {
  display: flex; align-items: center; gap: .4rem;
  color: rgba(255,255,255,.5); font-size: .8rem;
}
.hero-trust .trust-item i { color: var(--od-green); font-size: .75rem; }

/* ─── Dashboard Mockup ──────────────────────────────────── */
.od-dashboard-wrap {
  position: relative; z-index: 2;
}
.od-dashboard {
  background: #0f1f33;
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 18px;
  overflow: hidden;
  box-shadow:
    0 0 0 1px rgba(255,255,255,.05),
    0 40px 80px rgba(0,0,0,.6),
    0 0 80px rgba(26,138,78,.1);
  animation: float-dash 6s ease-in-out infinite;
}
@keyframes float-dash {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(-10px); }
}
.dash-chrome {
  background: #0a1625;
  padding: .7rem 1rem;
  display: flex; align-items: center; gap: .5rem;
  border-bottom: 1px solid rgba(255,255,255,.07);
}
.dash-chrome .dot { width: 10px; height: 10px; border-radius: 50%; }
.dash-url-bar {
  flex: 1; background: rgba(255,255,255,.06); border-radius: 6px;
  padding: .25rem .75rem; font-size: .72rem; color: rgba(255,255,255,.4);
  margin: 0 .75rem;
}
.dash-body { padding: 1.25rem; }
.dash-header-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1rem;
}
.dash-title { color: white; font-weight: 700; font-size: .9rem; }
.dash-period {
  background: rgba(26,138,78,.2); color: #4ade93;
  border-radius: 6px; padding: .2rem .65rem; font-size: .7rem; font-weight: 600;
}
.dash-kpis { display: grid; grid-template-columns: repeat(4,1fr); gap: .6rem; margin-bottom: 1rem; }
.dash-kpi {
  background: rgba(255,255,255,.05); border-radius: 10px; padding: .75rem;
  border: 1px solid rgba(255,255,255,.07);
}
.dash-kpi .kv { font-size: 1rem; font-weight: 800; color: white; }
.dash-kpi .kv.green { color: #4ade93; }
.dash-kpi .kv.amber { color: #fbbf24; }
.dash-kpi .kv.red   { color: #f87171; }
.dash-kpi .kl { font-size: .62rem; color: rgba(255,255,255,.4); margin-top: .15rem; }
.dash-kpi .kt { font-size: .62rem; margin-top: .25rem; }
.dash-kpi .kt.up   { color: #4ade93; }
.dash-kpi .kt.down { color: #f87171; }

.dash-chart-section { background: rgba(255,255,255,.04); border-radius: 10px; padding: .9rem; margin-bottom: 1rem; border: 1px solid rgba(255,255,255,.06); }
.dash-chart-label { font-size: .7rem; color: rgba(255,255,255,.4); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: .75rem; }
.dash-bars { display: flex; align-items: flex-end; gap: 4px; height: 70px; }
.dash-bar { flex: 1; border-radius: 4px 4px 0 0; background: rgba(26,138,78,.25); transition: height .5s ease; }
.dash-bar.hi { background: linear-gradient(180deg, #4ade93 0%, var(--od-green) 100%); }

.dash-modules { display: grid; grid-template-columns: repeat(3,1fr); gap: .5rem; }
.dash-mod {
  background: rgba(255,255,255,.05); border-radius: 8px; padding: .6rem .75rem;
  display: flex; align-items: center; gap: .5rem;
  border: 1px solid rgba(255,255,255,.06);
}
.dash-mod-icon { width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: .6rem; flex-shrink: 0; }
.dash-mod-name { font-size: .65rem; font-weight: 600; color: rgba(255,255,255,.75); }

/* Floating badges */
.float-badge {
  position: absolute; background: white;
  border-radius: 12px; padding: .6rem .9rem;
  box-shadow: 0 8px 32px rgba(0,0,0,.3);
  display: flex; align-items: center; gap: .5rem;
  font-size: .75rem; font-weight: 700; white-space: nowrap;
  animation: badge-float 5s ease-in-out infinite;
}
.float-badge-1 { top: -20px; right: -30px; animation-delay: 0s; }
.float-badge-2 { bottom: 40px; left: -40px; animation-delay: 2.5s; }
@keyframes badge-float {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}
.float-badge .fb-icon { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .8rem; }

/* ─── Trusted By Strip ───────────────────────────────────── */
.trusted-strip {
  background: #fff; border-top: 1px solid #f0f4f8; border-bottom: 1px solid #f0f4f8;
  padding: 1.75rem 0;
}
.trusted-label { font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
.industry-pill {
  display: inline-flex; align-items: center; gap: .4rem;
  background: #f8fafc; border: 1px solid #e2e8f0;
  border-radius: 50px; padding: .4rem 1rem;
  font-size: .8rem; font-weight: 600; color: #475569;
  transition: all .2s;
}
.industry-pill i { font-size: .75rem; }
.industry-pill:hover { background: #e6f5ee; border-color: #1A8A4E; color: #1A8A4E; }

/* ─── Impact Stats ───────────────────────────────────────── */
.impact-section {
  background: linear-gradient(135deg, #050f1f 0%, #0B2D4E 60%, #0d3b1e 100%);
  padding: 5rem 0; position: relative; overflow: hidden;
}
.impact-section::before {
  content: '';
  position: absolute; inset: 0;
  background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 40px 40px;
}
.impact-stat { text-align: center; position: relative; z-index: 1; }
.impact-stat .i-num {
  font-size: clamp(2.5rem, 5vw, 3.8rem); font-weight: 900; color: white;
  line-height: 1; letter-spacing: -2px;
}
.impact-stat .i-num span { color: #4ade93; }
.impact-stat .i-label { font-size: .85rem; color: rgba(255,255,255,.5); margin-top: .4rem; font-weight: 500; }
.impact-divider { width: 1px; background: rgba(255,255,255,.1); }

/* ─── Features ───────────────────────────────────────────── */
.od-section-eyebrow {
  display: inline-block; background: #e6f5ee; color: #157a42;
  font-size: .72rem; font-weight: 800; padding: .3rem .9rem;
  border-radius: 50px; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 1rem;
}
.od-section-title {
  font-size: clamp(1.8rem, 3.5vw, 2.6rem); font-weight: 900;
  color: #0B2D4E; letter-spacing: -1px; line-height: 1.15;
}
.od-section-sub { color: #64748b; font-size: 1rem; line-height: 1.7; max-width: 540px; margin: .75rem auto 0; }

.feature-card {
  background: white; border-radius: 16px; padding: 2rem 1.75rem;
  border: 1px solid #f0f4f8; height: 100%;
  transition: all .3s cubic-bezier(.4,0,.2,1);
  position: relative; overflow: hidden;
}
.feature-card::before {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--od-green), #22d3a5);
  transform: scaleX(0); transition: transform .3s ease;
  transform-origin: left;
}
.feature-card:hover { transform: translateY(-6px); box-shadow: 0 20px 50px rgba(11,45,78,.1); border-color: #e6f5ee; }
.feature-card:hover::before { transform: scaleX(1); }
.feat-icon-wrap {
  width: 52px; height: 52px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; margin-bottom: 1.25rem;
}
.feature-card h6 { font-size: .95rem; font-weight: 800; color: #0B2D4E; margin-bottom: .6rem; }
.feature-card p  { font-size: .85rem; color: #64748b; line-height: 1.65; margin: 0; }

/* ─── Modules ─────────────────────────────────────────────── */
.od-modules-bg { background: #f8fafc; }
.mod-filter-tabs { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: center; margin-bottom: 2.5rem; }
.mod-filter-tab {
  padding: .45rem 1.1rem; border-radius: 8px; font-size: .82rem; font-weight: 600;
  border: 1px solid #e2e8f0; background: white; color: #64748b; cursor: pointer;
  transition: all .2s;
}
.mod-filter-tab.active, .mod-filter-tab:hover { background: #0B2D4E; color: white; border-color: #0B2D4E; }

.mod-tile {
  background: white; border-radius: 14px; padding: 1.5rem 1.25rem;
  text-align: center; border: 2px solid transparent;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  transition: all .25s; height: 100%;
  text-decoration: none; display: block;
}
.mod-tile:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(11,45,78,.1); border-color: var(--od-green); }
.mod-tile-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin: 0 auto .85rem; }
.mod-tile h6   { font-size: .875rem; font-weight: 800; color: #0B2D4E; margin-bottom: .3rem; }
.mod-tile p    { font-size: .75rem; color: #94a3b8; margin: 0; line-height: 1.5; }
.mod-tile .price-pill {
  display: inline-block; margin-top: .65rem; background: #e6f5ee; color: #157a42;
  font-size: .72rem; font-weight: 700; padding: .2rem .65rem; border-radius: 50px;
}

/* ─── How It Works ────────────────────────────────────────── */
.od-how-bg { background: white; }
.process-row { position: relative; }
.process-connector {
  position: absolute; top: 32px; left: calc(16.666% + 30px);
  right: calc(16.666% + 30px); height: 2px;
  background: linear-gradient(90deg, var(--od-green), #38bdf8);
  opacity: .2;
}
.process-step { text-align: center; position: relative; z-index: 1; }
.process-num {
  width: 64px; height: 64px; border-radius: 50%;
  background: linear-gradient(135deg, var(--od-green), #0B2D4E);
  color: white; font-size: 1.5rem; font-weight: 900;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 1.25rem;
  box-shadow: 0 6px 20px rgba(26,138,78,.35);
  position: relative;
}
.process-num::after {
  content: ''; position: absolute; inset: -4px; border-radius: 50%;
  border: 2px dashed rgba(26,138,78,.3); animation: spin 12s linear infinite;
}
@keyframes spin { from{transform:rotate(0)} to{transform:rotate(360deg)} }
.process-step h5 { font-size: 1rem; font-weight: 800; color: #0B2D4E; margin-bottom: .5rem; }
.process-step p  { font-size: .875rem; color: #64748b; line-height: 1.65; max-width: 240px; margin: 0 auto; }

/* ─── Testimonials ────────────────────────────────────────── */
.od-testimonials-bg { background: #f8fafc; }
.testi-card {
  background: white; border-radius: 16px; padding: 2rem;
  box-shadow: 0 2px 16px rgba(11,45,78,.07);
  border: 1px solid #f0f4f8; height: 100%;
  transition: all .25s;
}
.testi-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(11,45,78,.1); }
.testi-stars { display: flex; gap: 3px; margin-bottom: 1rem; }
.testi-stars i { color: #fbbf24; font-size: .8rem; }
.testi-quote {
  font-size: .9rem; color: #475569; line-height: 1.75; margin-bottom: 1.5rem;
  font-style: italic; position: relative;
}
.testi-quote::before { content: '\201C'; font-size: 3rem; color: #e2e8f0; line-height: 0; vertical-align: -1rem; margin-right: .2rem; font-style: normal; }
.testi-author { display: flex; align-items: center; gap: .75rem; }
.testi-avatar {
  width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: .8rem; color: white; flex-shrink: 0;
}
.testi-name { font-size: .875rem; font-weight: 700; color: #0B2D4E; }
.testi-role { font-size: .75rem; color: #94a3b8; }

/* ─── Pricing ─────────────────────────────────────────────── */
.od-pricing-bg { background: white; }
.billing-toggle-wrap {
  display: inline-flex; align-items: center; gap: .75rem;
  background: #f8fafc; border: 1px solid #e2e8f0;
  border-radius: 12px; padding: .5rem 1.25rem; margin-bottom: 3rem;
}
.billing-toggle-wrap span { font-size: .875rem; font-weight: 600; color: #64748b; }
.billing-toggle-wrap span.active { color: #0B2D4E; }
.currency-pill {
  display: inline-flex; align-items: center; gap: 0;
  background: #f1f5f9; border: 1.5px solid #e2e8f0;
  border-radius: 999px; overflow: hidden; margin-left: .5rem;
}
.currency-pill button {
  border: none; background: transparent; padding: .32rem .9rem;
  font-size: .8rem; font-weight: 700; color: #64748b;
  cursor: pointer; transition: background .18s, color .18s; line-height: 1;
}
.currency-pill button.active {
  background: var(--od-green, #00d084); color: #fff; border-radius: 999px;
}

.od-plan-card {
  background: white; border-radius: 20px; padding: 2.25rem 2rem;
  border: 2px solid #f0f4f8; position: relative;
  transition: all .3s; height: 100%;
}
.od-plan-card:hover { transform: translateY(-6px); box-shadow: 0 24px 60px rgba(11,45,78,.12); }
.od-plan-card.popular {
  border-color: var(--od-green);
  box-shadow: 0 12px 40px rgba(26,138,78,.15);
  background: linear-gradient(180deg, #e6f5ee 0%, #ffffff 25%);
}
.od-plan-card .pop-label {
  position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
  background: var(--od-green); color: white; font-size: .72rem; font-weight: 800;
  padding: .3rem 1rem; border-radius: 50px; white-space: nowrap;
  text-transform: uppercase; letter-spacing: .5px;
}
.od-plan-card .plan-name { font-size: .85rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: .5rem; }
.od-plan-card .plan-price { font-size: 3rem; font-weight: 900; color: #0B2D4E; line-height: 1; letter-spacing: -2px; }
.od-plan-card .plan-price sup { font-size: 1rem; font-weight: 600; color: #94a3b8; vertical-align: top; margin-top: .6rem; letter-spacing: 0; }
.od-plan-card .plan-price .per { font-size: .9rem; font-weight: 500; color: #94a3b8; letter-spacing: 0; }
.plan-features { list-style: none; padding: 0; margin: 1.75rem 0; }
.plan-features li { display: flex; align-items: flex-start; gap: .65rem; padding: .45rem 0; font-size: .875rem; color: #475569; border-bottom: 1px solid #f8fafc; }
.plan-features li:last-child { border-bottom: none; }
.plan-features li i { color: var(--od-green); font-size: .75rem; margin-top: .2rem; flex-shrink: 0; }
.btn-plan-primary { display: block; width: 100%; padding: .85rem; border-radius: 10px; font-weight: 700; font-size: .9rem; text-align: center; background: var(--od-green); color: white; text-decoration: none; transition: all .2s; border: none; }
.btn-plan-primary:hover { background: #157a42; color: white; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,138,78,.35); }
.btn-plan-outline { display: block; width: 100%; padding: .85rem; border-radius: 10px; font-weight: 700; font-size: .9rem; text-align: center; background: transparent; color: #0B2D4E; border: 2px solid #e2e8f0; text-decoration: none; transition: all .2s; }
.btn-plan-outline:hover { border-color: var(--od-green); color: var(--od-green); }

/* ─── FAQ ─────────────────────────────────────────────────── */
.od-faq-bg { background: #f8fafc; }
.od-accordion .accordion-item { background: white; border: 1px solid #f0f4f8; border-radius: 12px !important; margin-bottom: .6rem; overflow: hidden; }
.od-accordion .accordion-button {
  background: white; color: #0B2D4E; font-weight: 700; font-size: .9rem;
  border-radius: 12px !important; padding: 1.1rem 1.5rem;
  box-shadow: none !important;
}
.od-accordion .accordion-button:not(.collapsed) { color: var(--od-green); background: #e6f5ee; }
.od-accordion .accordion-button::after { filter: none; }
.od-accordion .accordion-button:not(.collapsed)::after { filter: hue-rotate(120deg) saturate(2); }
.od-accordion .accordion-body { color: #64748b; font-size: .875rem; line-height: 1.75; padding: .5rem 1.5rem 1.25rem; }

/* ─── CTA Section ─────────────────────────────────────────── */
.od-cta-section {
  background: linear-gradient(135deg, #050f1f 0%, #0B2D4E 50%, #0d3b1e 100%);
  padding: 6rem 0; position: relative; overflow: hidden;
}
.od-cta-section::before {
  content: ''; position: absolute; inset: 0;
  background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 40px 40px;
}
.od-cta-section .cta-glow {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  width: 600px; height: 300px;
  background: radial-gradient(ellipse, rgba(26,138,78,.2) 0%, transparent 70%);
}
.od-cta-section h2 { font-size: clamp(2rem, 4vw, 3rem); font-weight: 900; color: white; letter-spacing: -1px; }
.od-cta-section p  { color: rgba(255,255,255,.6); font-size: 1.05rem; max-width: 520px; margin: 0 auto 2.5rem; }
.cta-trust-row { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; margin-top: 2.5rem; }
.cta-trust-item { display: flex; align-items: center; gap: .4rem; color: rgba(255,255,255,.45); font-size: .8rem; }
.cta-trust-item i { color: #4ade93; }

/* ─── Contact ─────────────────────────────────────────────── */
.od-contact-bg { background: white; }
.contact-info-card {
  display: flex; align-items: flex-start; gap: 1rem;
  background: #f8fafc; border: 1px solid #f0f4f8;
  border-radius: 14px; padding: 1.25rem 1.5rem;
  margin-bottom: 1rem; transition: all .2s;
}
.contact-info-card:hover { border-color: #c7e8d8; background: #e6f5ee; }
.contact-info-card .ci-icon {
  width: 44px; height: 44px; border-radius: 12px; background: #e6f5ee;
  color: var(--od-green); display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.contact-info-card .ci-label { font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: .2rem; }
.contact-info-card .ci-value { font-size: .9rem; font-weight: 600; color: #0B2D4E; }

.od-contact-form { background: #f8fafc; border-radius: 20px; padding: 2.5rem; border: 1px solid #f0f4f8; }
.od-contact-form .form-control, .od-contact-form .form-select {
  background: white; border-color: #e2e8f0; border-radius: 10px;
  padding: .7rem 1rem; font-size: .875rem;
}
.od-contact-form .form-control:focus, .od-contact-form .form-select:focus {
  border-color: var(--od-green); box-shadow: 0 0 0 3px rgba(26,138,78,.1);
}
.od-contact-form .form-label { font-size: .82rem; font-weight: 700; color: #475569; margin-bottom: .4rem; }

/* ─── Footer ──────────────────────────────────────────────── */
.od-footer { background: #050f1f; color: rgba(255,255,255,.55); }
.od-footer .foot-logo-name { font-size: 1.15rem; font-weight: 900; color: white; }
.od-footer .foot-logo-name span { color: #4ade93; }
.od-footer .foot-desc { font-size: .85rem; line-height: 1.7; color: rgba(255,255,255,.45); max-width: 280px; }
.od-footer h6 { font-size: .78rem; font-weight: 800; color: rgba(255,255,255,.9); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 1.1rem; }
.od-footer .foot-link { display: block; color: rgba(255,255,255,.45); font-size: .85rem; margin-bottom: .5rem; text-decoration: none; transition: color .2s; }
.od-footer .foot-link:hover { color: #4ade93; }
.od-footer .social-links { display: flex; gap: .5rem; margin-top: 1.25rem; }
.od-footer .soc-btn {
  width: 36px; height: 36px; border-radius: 9px;
  background: rgba(255,255,255,.07); color: rgba(255,255,255,.6);
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; transition: all .2s; text-decoration: none;
}
.od-footer .soc-btn:hover { background: var(--od-green); color: white; }
.od-footer .foot-bottom { border-top: 1px solid rgba(255,255,255,.07); padding: 1.5rem 0; }
.od-footer .foot-bottom p { font-size: .8rem; color: rgba(255,255,255,.35); margin: 0; }
.od-footer .foot-badges { display: flex; gap: .75rem; align-items: center; }
.od-footer .foot-badge {
  display: inline-flex; align-items: center; gap: .35rem;
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
  border-radius: 6px; padding: .25rem .65rem; font-size: .7rem; color: rgba(255,255,255,.45);
}
.od-footer .foot-badge i { color: #4ade93; font-size: .65rem; }

/* ─── Scroll-reveal ───────────────────────────────────────── */
.reveal { opacity: 0; transform: translateY(28px); transition: opacity .6s ease, transform .6s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }
.reveal.delay-1 { transition-delay: .1s; }
.reveal.delay-2 { transition-delay: .2s; }
.reveal.delay-3 { transition-delay: .3s; }
.reveal.delay-4 { transition-delay: .4s; }

/* ─── Mobile Nav ──────────────────────────────────────────── */
.od-mobile-menu {
  background: rgba(5,15,31,.98); border-top: 1px solid rgba(255,255,255,.08);
  padding: 1rem; margin-top: .5rem;
}
.od-mobile-menu a { display: block; color: rgba(255,255,255,.75); padding: .65rem .75rem; border-radius: 8px; font-weight: 500; font-size: .9rem; text-decoration: none; margin-bottom: .2rem; }
.od-mobile-menu a:hover { background: rgba(255,255,255,.08); color: white; }
.od-mobile-menu .mob-divider { border-color: rgba(255,255,255,.1); margin: .5rem 0; }

</style>
</head>
<body class="landing-body">

<!-- Scroll progress -->
<div id="scroll-progress"></div>

<!-- ══════════════════════════════════════════════════════════
     NAVBAR
══════════════════════════════════════════════════════════ -->
<nav class="od-nav" id="odNav">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <!-- Logo -->
      <a href="#hero" class="nav-logo">
        <div class="logo-mark">OD</div>
        <div class="logo-name">Orbit<span>Desk</span></div>
      </a>
      <!-- Desktop links -->
      <div class="od-nav-links d-none d-lg-flex">
        <a href="#features">Features</a>
        <a href="#modules">Modules</a>
        <a href="#how">How it Works</a>
        <a href="#pricing">Pricing</a>
        <a href="#contact">Contact</a>
      </div>
      <!-- CTAs -->
      <div class="d-flex align-items-center gap-2">
        <a href="<?= APP_URL ?>/auth/login.php" class="nav-cta-login d-none d-sm-inline">Login</a>
        <a href="<?= APP_URL ?>/auth/register.php" class="nav-cta-start">Get Started <i class="fas fa-arrow-right ms-1" style="font-size:.75rem"></i></a>
        <button class="d-lg-none btn-icon text-white ms-1" style="background:none;border:none;font-size:1.1rem;cursor:pointer" data-bs-toggle="collapse" data-bs-target="#mobileNav">
          <i class="fas fa-bars"></i>
        </button>
      </div>
    </div>
    <!-- Mobile nav -->
    <div class="collapse" id="mobileNav">
      <div class="od-mobile-menu">
        <a href="#features"><i class="fas fa-bolt me-2"></i>Features</a>
        <a href="#modules"><i class="fas fa-th me-2"></i>Modules</a>
        <a href="#how"><i class="fas fa-route me-2"></i>How it Works</a>
        <a href="#pricing"><i class="fas fa-tags me-2"></i>Pricing</a>
        <a href="#contact"><i class="fas fa-envelope me-2"></i>Contact</a>
        <hr class="mob-divider">
        <a href="<?= APP_URL ?>/auth/login.php"><i class="fas fa-sign-in-alt me-2"></i>Login</a>
        <a href="<?= APP_URL ?>/auth/register.php" style="background:var(--od-green);color:white;text-align:center;font-weight:700">Start Free Trial</a>
      </div>
    </div>
  </div>
</nav>

<!-- ══════════════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════════════ -->
<section class="od-hero" id="hero">
  <!-- Overlay layers -->
  <div class="hero-mesh"></div>
  <div class="hero-bottom-shimmer"></div>
  <!-- Glow orbs -->
  <div class="orb-1"></div>
  <div class="orb-2"></div>
  <div class="orb-3"></div>
  <div class="container position-relative" style="z-index:4">
    <div class="row align-items-center g-5">
      <!-- Left: Copy -->
      <div class="col-lg-6">
        <div class="hero-eyebrow">
          <span class="dot"></span>
          Kenya's #1 Business Management Suite
        </div>
        <h1>
          One Platform.<br>
          <span class="grad-text">20+ Business</span><br>
          Solutions.
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
                <div class="dash-title">Business Overview</div>
                <div class="dash-period">This Month</div>
              </div>
              <div class="dash-kpis">
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
                <div class="dash-chart-label">Monthly Revenue Trend</div>
                <div class="dash-bars">
                  <?php $heights=[30,45,28,58,40,52,65,72,50,68,80,100]; foreach($heights as $i=>$h): ?>
                  <div class="dash-bar <?= $i>=9?'hi':'' ?>" style="height:<?=$h?>%"></div>
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
      <div class="d-flex gap-2 flex-wrap">
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
        foreach($industries as $ind): ?>
        <span class="industry-pill">
          <i class="<?=$ind[1]?>" style="color:#1A8A4E"></i> <?=$ind[0]?>
        </span>
        <?php endforeach; ?>
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

    <div class="row g-3">
      <?php foreach($modules as $m):
        $kesMo = (float)$m['monthly_price'];
        $usdMo = $kesMo > 0 ? round($kesMo / $usdRate, 2) : 0;
      ?>
      <div class="col-6 col-md-4 col-lg-3 reveal">
        <div class="mod-tile" role="button" tabindex="0"
             onclick="openModuleModal('<?=e($m['slug'])?>')"
             onkeydown="if(event.key==='Enter')openModuleModal('<?=e($m['slug'])?>') "
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

<footer class="od-footer">
  <div class="container" style="padding-top:4rem;padding-bottom:2rem">
    <div class="row g-4 mb-5">
      <!-- Brand col -->
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <div style="width:38px;height:38px;background:linear-gradient(135deg,#1A8A4E,#22c27a);border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:900;color:white;font-size:.85rem">OD</div>
          <div class="foot-logo-name">Orbit<span>Desk</span></div>
        </div>
        <p class="foot-desc"><?= htmlspecialchars($appTagline, ENT_QUOTES) ?>. Built for African businesses, trusted across Kenya and East Africa.</p>
        <div class="social-links">
          <a href="#" class="soc-btn"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="soc-btn"><i class="fab fa-twitter"></i></a>
          <a href="#" class="soc-btn"><i class="fab fa-linkedin-in"></i></a>
          <a href="#" class="soc-btn"><i class="fab fa-whatsapp"></i></a>
          <a href="#" class="soc-btn"><i class="fab fa-youtube"></i></a>
        </div>
      </div>
      <!-- Modules 1 -->
      <div class="col-6 col-lg-2">
        <h6>Modules</h6>
        <?php foreach(array_slice($modules,0,8) as $m): ?>
        <a href="<?=APP_URL?>/auth/register.php" class="foot-link"><?=e($m['name'])?></a>
        <?php endforeach; ?>
      </div>
      <!-- Modules 2 -->
      <div class="col-6 col-lg-2">
        <h6>More Modules</h6>
        <?php foreach(array_slice($modules,8) as $m): ?>
        <a href="<?=APP_URL?>/auth/register.php" class="foot-link"><?=e($m['name'])?></a>
        <?php endforeach; ?>
      </div>
      <!-- Company -->
      <div class="col-6 col-lg-2">
        <h6>Company</h6>
        <a href="#about"   class="foot-link">About Us</a>
        <a href="#"        class="foot-link">Careers</a>
        <a href="#"        class="foot-link">Blog</a>
        <a href="#contact" class="foot-link">Contact</a>
        <a href="#"        class="foot-link">Partners</a>
      </div>
      <!-- Legal -->
      <div class="col-6 col-lg-2">
        <h6>Legal</h6>
        <a href="#" class="foot-link">Privacy Policy</a>
        <a href="#" class="foot-link">Terms of Service</a>
        <a href="#" class="foot-link">Cookie Policy</a>
        <a href="#" class="foot-link">Security</a>
        <a href="#" class="foot-link">Compliance</a>
      </div>
    </div>
    <div class="foot-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
      <p>&copy; <?=APP_YEAR?> <?=APP_NAME?>. All rights reserved. Made with <i class="fas fa-heart" style="color:#ef4444;font-size:.75rem"></i> in Kenya.</p>
      <div class="foot-badges">
        <span class="foot-badge"><i class="fas fa-shield-halved"></i> SSL Secured</span>
        <span class="foot-badge"><i class="fas fa-lock"></i> Data Encrypted</span>
        <span class="foot-badge"><i class="fas fa-server"></i> 99.9% Uptime</span>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
// ── Scroll progress bar ─────────────────────────────────────
const progressBar = document.getElementById('scroll-progress');
window.addEventListener('scroll', () => {
  const s = document.documentElement;
  const pct = (s.scrollTop / (s.scrollHeight - s.clientHeight)) * 100;
  progressBar.style.width = pct + '%';
}, { passive: true });

// ── Sticky navbar ─────────────────────────────────────────────
const nav = document.getElementById('odNav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 30);
}, { passive: true });

// ── Scroll-reveal (Intersection Observer) ────────────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); } });
}, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// ── Animated counters ─────────────────────────────────────────
function animateCounter(el) {
  const target = +el.dataset.target;
  const suffix = el.querySelector('span')?.textContent || '';
  let start = 0;
  const duration = 1600;
  const step = timestamp => {
    if (!start) start = timestamp;
    const progress = Math.min((timestamp - start) / duration, 1);
    const ease = 1 - Math.pow(1 - progress, 3);
    const val = Math.round(ease * target);
    const span = el.querySelector('span');
    el.textContent = val.toLocaleString();
    if (span) el.appendChild(span);
    if (progress < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      animateCounter(e.target);
      counterObserver.unobserve(e.target);
    }
  });
}, { threshold: .5 });
document.querySelectorAll('[data-counter]').forEach(el => counterObserver.observe(el));

// ── Pricing: billing cycle + currency toggle ──────────────────
const USD_RATE = <?= (float)$usdRate ?>;
let activeCur  = localStorage.getItem('landingCurrency') || 'USD';

function updatePricing() {
  const annual   = document.getElementById('billingToggle').checked;
  const isUSD    = (activeCur === 'USD');
  const curSym   = isUSD ? '$' : 'KES ';
  const subtitle = isUSD
    ? 'All prices in USD. Start free, scale as you grow. No hidden fees.'
    : 'All prices in KES. Start free, scale as you grow. No hidden fees.';

  document.getElementById('pricingSubtitle').textContent = subtitle;

  // Billing cycle labels
  document.getElementById('lblMonthly').className = annual ? '' : 'active';
  document.getElementById('lblAnnual').className  = annual ? 'active' : '';

  // Currency pill buttons
  document.getElementById('btnUSD').classList.toggle('active', isUSD);
  document.getElementById('btnKES').classList.toggle('active', !isUSD);
  document.getElementById('btnUSD').setAttribute('aria-pressed', isUSD);
  document.getElementById('btnKES').setAttribute('aria-pressed', !isUSD);

  // Update currency symbol on each card
  document.querySelectorAll('.plan-cur').forEach(function(el) {
    el.textContent = curSym;
  });

  // Update each plan card's price and note
  document.querySelectorAll('.plan-price-val').forEach(function(el) {
    var val = annual
      ? (isUSD ? el.dataset.usdAnnMo : el.dataset.kesAnnMo)
      : (isUSD ? el.dataset.usdMo    : el.dataset.kesMo);
    el.textContent = val || '0';
  });

  document.querySelectorAll('.plan-note').forEach(function(el) {
    var annTot  = isUSD ? el.dataset.usdAnnTotal : el.dataset.kesAnnTotal;
    var save    = el.dataset.savePct;
    var curFull = isUSD ? 'USD' : 'KES';
    el.textContent = annual
      ? 'Billed annually — ' + curFull + ' ' + annTot + '/yr' + (save > 0 ? ' · Save ' + save + '%' : '')
      : 'No long-term commitment';
  });

  // ── Module tiles: update price pills ──────────────────────────
  document.querySelectorAll('.mod-price-pill').forEach(function(el) {
    el.textContent = isUSD
      ? 'From $ '   + el.dataset.usd + '/mo'
      : 'From KES ' + el.dataset.kes + '/mo';
  });

  // Keep both currency toggles in sync (modules section + plans section)
  var modUSD = document.getElementById('modBtnUSD');
  var modKES = document.getElementById('modBtnKES');
  if (modUSD && modKES) {
    var activeStyle   = 'background:#0B2D4E;color:#fff;border-radius:999px';
    var inactiveStyle = 'background:transparent;color:#64748b;border-radius:999px';
    modUSD.style.cssText = (isUSD  ? activeStyle : inactiveStyle) + ';border:none;padding:.28rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s';
    modKES.style.cssText = (!isUSD ? activeStyle : inactiveStyle) + ';border:none;padding:.28rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s';
  }
}

function setCurrency(cur) {
  activeCur = cur;
  localStorage.setItem('landingCurrency', cur);
  updatePricing();
}

// Wire billing toggle
document.getElementById('billingToggle').addEventListener('change', updatePricing);

// Apply saved/default preference on page load
updatePricing();

// ── Module detail modal ────────────────────────────────────────
const MOD_INFO = <?= json_encode($moduleMap, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

function openModuleModal(slug) {
  const m = MOD_INFO[slug];
  if (!m) return;

  // Header colour (gradient from the module's brand colour)
  document.getElementById('mmHeader').style.background =
    `linear-gradient(135deg, ${m.color}f0 0%, ${m.color}b0 100%)`;

  document.getElementById('mmIcon').className = m.icon;
  document.getElementById('mmCat').textContent  = m.category;
  document.getElementById('mmName').textContent = m.name;
  document.getElementById('mmDesc').textContent = m.desc;
  // Show primary price in active currency, secondary below
  var isUSD    = (activeCur === 'USD');
  var primary  = isUSD
    ? '$ '   + Number(m.price_usd).toFixed(2)    + '/mo'
    : 'KES ' + Number(m.price).toLocaleString('en-KE') + '/mo';
  var secondary = isUSD
    ? '≈ KES ' + Number(m.price).toLocaleString('en-KE') + '/mo'
    : '≈ $ '  + Number(m.price_usd).toFixed(2) + '/mo';
  var annLine = (m.price_ann > 0)
    ? (isUSD
        ? ' · $ ' + Number(m.price_ann_usd).toFixed(2) + '/yr (≈ KES ' + Number(m.price_ann).toLocaleString('en-KE') + ')'
        : ' · KES ' + Number(m.price_ann).toLocaleString('en-KE') + '/yr (≈ $ ' + Number(m.price_ann_usd).toFixed(2) + ')')
    : '';
  document.getElementById('mmPrice').innerHTML =
    '<span class="fw-bold">' + primary + '</span>' +
    '<span class="text-muted small ms-2">' + secondary + '</span>' +
    (annLine ? '<div class="text-muted small mt-1" style="font-size:.75rem">' + annLine + '</div>' : '');

  // Feature list — 2-column grid
  document.getElementById('mmFeatures').innerHTML = (m.features || []).map(f => `
    <div class="col-sm-6">
      <div class="d-flex align-items-start gap-2 px-2 py-2 rounded-2" style="background:#f0fdf4">
        <i class="fas fa-check-circle flex-shrink-0 mt-1" style="color:#1A8A4E;font-size:.72rem"></i>
        <span style="font-size:.8rem;color:#1e293b;line-height:1.45">${f}</span>
      </div>
    </div>`).join('');

  bootstrap.Modal.getOrCreateInstance(document.getElementById('modDetailModal')).show();
}

// ── Active nav link on scroll ──────────────────────────────────
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('.od-nav-links a');
window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(s => { if (window.scrollY >= s.offsetTop - 100) current = s.id; });
  navLinks.forEach(a => {
    a.style.color = a.getAttribute('href') === '#' + current ? 'white' : '';
    a.style.background = a.getAttribute('href') === '#' + current ? 'rgba(255,255,255,.1)' : '';
  });
}, { passive: true });
</script>
</body>
</html>
