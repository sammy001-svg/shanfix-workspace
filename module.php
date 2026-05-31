<?php
/**
 * module.php — Individual SEO landing page for each of the 22 modules.
 * Accessible at: /module/{slug}  (via .htaccess rewrite)
 *
 * Each page is a self-contained, keyword-rich landing page targeting:
 *  "{module name} software Kenya"
 *  "{module name} system Africa"
 *  "{module name} management system"
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['slug'] ?? ''));
if (!$slug) {
    http_response_code(404);
    header('Location: ' . APP_URL . '/');
    exit;
}

// Load module from DB
$stmt = $pdo->prepare("SELECT * FROM modules WHERE slug=? AND status='active' LIMIT 1");
$stmt->execute([$slug]);
$module = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    http_response_code(404);
    header('Location: ' . APP_URL . '/');
    exit;
}

// Load plans for pricing section
$plans = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll(PDO::FETCH_ASSOC);
$usdRate = max(1, (float)(getSetting('usd_rate', '130') ?: 130));

// Features from DB (JSON) or fallback
$features = [];
if (!empty($module['features'])) {
    $features = json_decode($module['features'], true) ?: [];
}

// Company settings
$sitePhone   = getSetting('company_phone',   '+254 700 000 000');
$siteEmail   = getSetting('support_email',   'support@orbitdesk.co.ke');
$siteAddress = getSetting('company_address', 'Nairobi, Kenya');

// ── SEO strings ───────────────────────────────────────────────────────────────
$modName    = $module['name'];
$modCat     = $module['category'] ?? '';
$modDesc    = $module['description'] ?? '';
$modColor   = $module['color'] ?? '#1A8A4E';
$modIcon    = $module['icon'] ?? 'fas fa-puzzle-piece';
$modPriceMo = (float)$module['monthly_price'];
$modPriceAn = (float)$module['annual_price'];

$pageTitle   = $modName . ' Software — ' . $modCat . ' Management System | ' . APP_NAME;
$metaDesc    = rtrim($modDesc, '.') . '. Trusted by businesses across Kenya and East Africa. M-Pesa integrated. Start free for 14 days.';
$canonUrl    = APP_URL . '/module/' . $slug;
$ogImage     = APP_URL . '/assets/images/og-banner-1200.png';

// Related modules (same category, different slug)
$related = [];
try {
    $rs = $pdo->prepare("SELECT slug, name, icon, color FROM modules WHERE category=? AND slug!=? AND status='active' LIMIT 4");
    $rs->execute([$modCat, $slug]);
    $related = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── JSON-LD ───────────────────────────────────────────────────────────────────
$jsonLd = json_encode([
    "@context"    => "https://schema.org",
    "@graph" => [
        [
            "@type"             => "SoftwareApplication",
            "name"              => $modName . ' — ' . APP_NAME,
            "description"       => $modDesc,
            "applicationCategory" => "BusinessApplication",
            "applicationSubCategory" => $modCat,
            "operatingSystem"   => "Web Browser",
            "url"               => $canonUrl,
            "screenshot"        => $ogImage,
            "featureList"       => implode(', ', $features),
            "offers" => [
                "@type"         => "Offer",
                "price"         => $modPriceMo > 0 ? (string)$modPriceMo : "0",
                "priceCurrency" => "KES",
                "priceSpecification" => [
                    "@type"          => "UnitPriceSpecification",
                    "price"          => $modPriceMo > 0 ? (string)$modPriceMo : "0",
                    "priceCurrency"  => "KES",
                    "unitCode"       => "MON",
                ],
            ],
            "publisher" => [
                "@type" => "Organization",
                "name"  => APP_NAME,
                "url"   => APP_URL,
            ],
        ],
        [
            "@type"      => "BreadcrumbList",
            "itemListElement" => [
                ["@type"=>"ListItem","position"=>1,"name"=>"Home","item"=>APP_URL."/"],
                ["@type"=>"ListItem","position"=>2,"name"=>"Modules","item"=>APP_URL."/#modules"],
                ["@type"=>"ListItem","position"=>3,"name"=>$modName,"item"=>$canonUrl],
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ── Category keyword map for richer copy ─────────────────────────────────────
$catKeywords = [
    'Finance'       => 'financial management, budget tracking, accounting',
    'Business'      => 'business management, operations, workflow',
    'Productivity'  => 'productivity tools, scheduling, collaboration',
    'Education'     => 'school management, education administration',
    'Healthcare'    => 'healthcare management, medical records, clinic',
    'Retail'        => 'retail management, point of sale, inventory',
    'HR'            => 'human resource management, payroll, employees',
    'Real Estate'   => 'property management, tenant management, rent collection',
    'Faith'         => 'church management, congregation, offerings',
    'Hospitality'   => 'hotel management, reservations, hospitality',
    'Services'      => 'service business management, appointments',
    'Tourism'       => 'travel management, tour packages, bookings',
    'Events'        => 'event management, ticketing, attendees',
    'Manufacturing' => 'manufacturing management, production, inventory',
    'Automotive'    => 'car yard management, vehicle sales, automotive',
    'Logistics'     => 'courier management, parcel tracking, delivery',
];
$catKw = $catKeywords[$modCat] ?? 'business management software';
?>
<!DOCTYPE html>
<html lang="en-KE">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="description"  content="<?= e($metaDesc) ?>">
<meta name="keywords"     content="<?= e($modName) ?> software Kenya, <?= e($modName) ?> system Africa, <?= e(strtolower($modCat)) ?> management software, <?= e($catKw) ?>, OrbitDesk, ERP Kenya, M-Pesa integrated">
<meta name="author"       content="<?= e(APP_NAME) ?>">
<meta name="robots"       content="index, follow, max-snippet:-1, max-image-preview:large">
<link rel="canonical"     href="<?= e($canonUrl) ?>">
<!-- Open Graph -->
<meta property="og:type"        content="product">
<meta property="og:site_name"   content="<?= e(APP_NAME) ?>">
<meta property="og:title"       content="<?= e($modName . ' Software — ' . APP_NAME) ?>">
<meta property="og:description" content="<?= e($metaDesc) ?>">
<meta property="og:image"       content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height"content="630">
<meta property="og:url"         content="<?= e($canonUrl) ?>">
<meta property="og:locale"      content="en_KE">
<!-- Twitter Card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= e($modName . ' — ' . APP_NAME) ?>">
<meta name="twitter:description" content="<?= e($metaDesc) ?>">
<meta name="twitter:image"       content="<?= e($ogImage) ?>">
<!-- PWA -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.php">
<meta name="theme-color" content="<?= e($modColor) ?>">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link rel="sitemap" type="application/xml" href="<?= APP_URL ?>/sitemap.xml">
<!-- Breadcrumb + SoftwareApplication JSON-LD -->
<script type="application/ld+json"><?= $jsonLd ?></script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root { --accent: <?= e($modColor) ?>; }
*    { box-sizing: border-box; }
body { font-family: Inter, 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 0; }

/* ── Nav ── */
.mod-nav { background: #0B2D4E; padding: 14px 0; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 12px rgba(0,0,0,.25); }
.mod-nav-inner { max-width: 1100px; margin: 0 auto; padding: 0 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.mod-nav-brand { color: white; font-size: 1.1rem; font-weight: 800; text-decoration: none; display: flex; align-items: center; gap: 8px; }
.mod-nav-brand span { font-weight: 400; opacity: .7; }
.mod-nav-links { display: flex; align-items: center; gap: 8px; }
.mod-nav-links a { color: rgba(255,255,255,.75); text-decoration: none; font-size: .85rem; padding: 6px 14px; border-radius: 6px; transition: all .2s; }
.mod-nav-links a:hover { background: rgba(255,255,255,.1); color: white; }
.btn-trial { background: var(--accent) !important; color: white !important; font-weight: 700 !important; border-radius: 50px !important; padding: 8px 22px !important; }

/* ── Hero ── */
.mod-hero { background: linear-gradient(135deg, #0B2D4E 0%, #1a3a5c 60%, <?= e($modColor) ?>33 100%); color: white; padding: 80px 20px 60px; }
.mod-hero-inner { max-width: 1000px; margin: 0 auto; text-align: center; }
.mod-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); border-radius: 50px; padding: 5px 14px; font-size: .78rem; font-weight: 600; margin-bottom: 20px; }
.mod-hero-icon { width: 72px; height: 72px; background: var(--accent); border-radius: 18px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.8rem; color: white; box-shadow: 0 8px 32px rgba(0,0,0,.3); }
.mod-hero h1 { font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 900; line-height: 1.15; margin: 0 0 16px; }
.mod-hero p  { font-size: 1.05rem; opacity: .8; max-width: 640px; margin: 0 auto 32px; line-height: 1.7; }
.hero-btns  { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-hero-primary { background: var(--accent); color: white; padding: 14px 32px; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 1rem; transition: all .2s; display: inline-flex; align-items: center; gap: 8px; }
.btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.3); color: white; }
.btn-hero-outline { background: transparent; color: white; padding: 14px 32px; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 1rem; border: 2px solid rgba(255,255,255,.5); transition: all .2s; display: inline-flex; align-items: center; gap: 8px; }
.btn-hero-outline:hover { background: rgba(255,255,255,.1); border-color: white; color: white; }
.mod-stats { display: flex; gap: 32px; justify-content: center; flex-wrap: wrap; margin-top: 40px; }
.mod-stat  { text-align: center; }
.mod-stat .num { font-size: 1.8rem; font-weight: 900; color: var(--accent); }
.mod-stat .lbl { font-size: .78rem; opacity: .65; }

/* ── Sections ── */
section { padding: 72px 20px; }
.section-inner { max-width: 1100px; margin: 0 auto; }
.section-label { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--accent); margin-bottom: 10px; }
.section-title { font-size: clamp(1.5rem, 3vw, 2.2rem); font-weight: 900; color: #0B2D4E; margin-bottom: 12px; }
.section-sub   { color: #64748b; font-size: 1rem; max-width: 580px; line-height: 1.7; }
section.alt { background: #f8fafc; }

/* ── Features grid ── */
.feat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 40px; }
.feat-card { background: white; border-radius: 14px; padding: 22px; border: 1px solid #e2e8f0; transition: all .2s; }
.feat-card:hover { border-color: var(--accent); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.07); }
.feat-card-icon { width: 42px; height: 42px; background: color-mix(in srgb, var(--accent) 12%, white); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--accent); font-size: .95rem; margin-bottom: 12px; }
.feat-card h4 { font-size: .9rem; font-weight: 700; color: #0B2D4E; margin-bottom: 4px; }
.feat-card p  { font-size: .82rem; color: #64748b; line-height: 1.6; margin: 0; }

/* ── Pricing ── */
.plan-card { background: white; border: 2px solid #e2e8f0; border-radius: 16px; padding: 28px; text-align: center; transition: all .2s; }
.plan-card:hover, .plan-card.popular { border-color: var(--accent); box-shadow: 0 8px 32px rgba(0,0,0,.1); }
.plan-card.popular { transform: scale(1.03); }
.plan-badge { background: var(--accent); color: white; font-size: .68rem; font-weight: 700; padding: 3px 12px; border-radius: 20px; display: inline-block; margin-bottom: 12px; }
.plan-price { font-size: 2.4rem; font-weight: 900; color: #0B2D4E; }
.plan-price sup { font-size: 1rem; vertical-align: top; margin-top: 8px; display: inline-block; }
.plan-price small { font-size: .8rem; font-weight: 400; color: #64748b; }
.plan-features { list-style: none; padding: 0; margin: 20px 0; text-align: left; }
.plan-features li { font-size: .85rem; padding: 5px 0; color: #374151; display: flex; align-items: center; gap: 8px; }
.plan-features li::before { content: "✓"; color: var(--accent); font-weight: 700; flex-shrink: 0; }

/* ── CTA ── */
.cta-section { background: linear-gradient(135deg, #0B2D4E, <?= e($modColor) ?>); color: white; text-align: center; padding: 72px 20px; }
.cta-title { font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 900; margin-bottom: 12px; }
.cta-sub   { opacity: .8; font-size: 1rem; max-width: 480px; margin: 0 auto 32px; }

/* ── Related modules ── */
.related-card { display: flex; align-items: center; gap: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; text-decoration: none; color: #1e293b; transition: all .2s; }
.related-card:hover { border-color: var(--accent); box-shadow: 0 4px 16px rgba(0,0,0,.08); color: #1e293b; }
.related-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: .8rem; flex-shrink: 0; }

/* ── Breadcrumb ── */
.breadcrumb-bar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 20px; }
.breadcrumb-inner { max-width: 1100px; margin: 0 auto; display: flex; align-items: center; gap: 6px; font-size: .78rem; color: #64748b; flex-wrap: wrap; }
.breadcrumb-inner a { color: var(--accent); text-decoration: none; }
.breadcrumb-inner .sep { color: #cbd5e1; }

/* ── Footer ── */
.mod-footer { background: #0B2D4E; color: rgba(255,255,255,.55); text-align: center; padding: 28px 20px; font-size: .82rem; }
.mod-footer a { color: rgba(255,255,255,.7); text-decoration: none; }
</style>
</head>
<body>

<!-- Navigation -->
<nav class="mod-nav" aria-label="Main navigation">
  <div class="mod-nav-inner">
    <a href="<?= APP_URL ?>/" class="mod-nav-brand">
      <?= e(APP_NAME) ?>
      <span>/ <?= e($modName) ?></span>
    </a>
    <div class="mod-nav-links">
      <a href="<?= APP_URL ?>/#modules">All Modules</a>
      <a href="<?= APP_URL ?>/#pricing">Pricing</a>
      <a href="<?= APP_URL ?>/auth/login.php">Login</a>
      <a href="<?= APP_URL ?>/auth/register.php" class="btn-trial">Start Free Trial</a>
    </div>
  </div>
</nav>

<!-- Breadcrumb -->
<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="<?= APP_URL ?>/">Home</a>
    <span class="sep">›</span>
    <a href="<?= APP_URL ?>/#modules">Modules</a>
    <span class="sep">›</span>
    <span><?= e($modCat) ?></span>
    <span class="sep">›</span>
    <strong style="color:#1e293b"><?= e($modName) ?></strong>
  </div>
</div>

<!-- Hero -->
<section class="mod-hero" aria-label="<?= e($modName) ?> overview">
  <div class="mod-hero-inner">
    <div class="mod-badge">
      <i class="fas fa-layer-group" style="color:<?= e($modColor) ?>"></i>
      <?= e($modCat) ?> Module
    </div>
    <div class="mod-hero-icon" aria-hidden="true">
      <i class="<?= e($modIcon) ?>"></i>
    </div>
    <h1><?= e($modName) ?> Software<br>for African Businesses</h1>
    <p><?= e($modDesc) ?></p>

    <div class="hero-btns">
      <a href="<?= APP_URL ?>/auth/register.php" class="btn-hero-primary">
        <i class="fas fa-rocket"></i> Start 14-Day Free Trial
      </a>
      <a href="<?= APP_URL ?>/#pricing" class="btn-hero-outline">
        <i class="fas fa-tag"></i> View Pricing
      </a>
    </div>

    <div class="mod-stats">
      <div class="mod-stat">
        <div class="num">22+</div>
        <div class="lbl">Integrated Modules</div>
      </div>
      <div class="mod-stat">
        <div class="num">M-Pesa</div>
        <div class="lbl">Payment Integration</div>
      </div>
      <div class="mod-stat">
        <div class="num">14 Days</div>
        <div class="lbl">Free Trial</div>
      </div>
      <?php if ($modPriceMo > 0): ?>
      <div class="mod-stat">
        <div class="num">KES <?= number_format($modPriceMo, 0) ?></div>
        <div class="lbl">Per Month</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Features -->
<?php if (!empty($features)): ?>
<section aria-labelledby="features-heading">
  <div class="section-inner">
    <div class="section-label">What's Included</div>
    <h2 id="features-heading" class="section-title"><?= e($modName) ?> Features</h2>
    <p class="section-sub">Everything your team needs to manage <?= e(strtolower($modName)) ?> operations — built for African businesses.</p>
    <div class="feat-grid" role="list">
      <?php foreach ($features as $i => $feat):
        $icons = ['fa-check-circle','fa-chart-bar','fa-file-alt','fa-users','fa-cog','fa-database','fa-bell','fa-shield-alt','fa-mobile-alt','fa-cloud'];
        $fi = $icons[$i % count($icons)];
      ?>
      <div class="feat-card" role="listitem">
        <div class="feat-card-icon" aria-hidden="true"><i class="fas <?= $fi ?>"></i></div>
        <h4><?= e($feat) ?></h4>
        <p>Streamline your <?= e(strtolower($modCat)) ?> workflows with powerful, easy-to-use tools built for African businesses.</p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Why OrbitDesk -->
<section class="alt" aria-labelledby="why-heading">
  <div class="section-inner">
    <div class="section-label">Why Choose Us</div>
    <h2 id="why-heading" class="section-title">Built for Africa. Ready for Growth.</h2>
    <p class="section-sub">OrbitDesk <?= e($modName) ?> is part of an all-in-one platform connecting your entire business.</p>

    <div class="row g-4 mt-2">
      <?php
      $whyCards = [
          ['fas fa-mobile-alt',  'M-Pesa Integrated',       'Accept payments and trigger workflows directly via M-Pesa Paybill and STK push — no third-party tools needed.'],
          ['fas fa-wifi-slash',  'Works on Any Connection',  'Optimised for low-bandwidth environments. Fast loading even on 2G networks common in Kenya and East Africa.'],
          ['fas fa-layer-group', '22 Integrated Modules',    'All modules share one database. Switch between HRM, Accounting, POS, and 19 more without re-entering data.'],
          ['fas fa-shield-alt',  'Enterprise Security',      'Role-based access control, 2FA login, AES-256 data encryption, and full audit trails for every action.'],
          ['fas fa-headset',     'Local Support',            'Dedicated support team based in Kenya. Fast response via email, WhatsApp, and live chat.'],
          ['fas fa-server',      'cPanel Hosting Ready',     'Deploy to any cPanel host. No Docker, no cloud dependencies. Pure PHP + MySQL — affordable and reliable.'],
      ];
      foreach ($whyCards as [$ic, $title, $desc]):
      ?>
      <div class="col-md-4">
        <div class="feat-card h-100">
          <div class="feat-card-icon" aria-hidden="true"><i class="fas <?= substr($ic, 5) ?>"></i></div>
          <h4><?= e($title) ?></h4>
          <p><?= e($desc) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Pricing -->
<?php if (!empty($plans)): ?>
<section aria-labelledby="pricing-heading">
  <div class="section-inner">
    <div class="section-label">Transparent Pricing</div>
    <h2 id="pricing-heading" class="section-title">Simple, Affordable Plans</h2>
    <p class="section-sub">
      <?= e($modName) ?> is included in all plans. Choose the plan that fits your business size.
      All prices in KES. VAT (16%) added at checkout.
    </p>

    <div class="row g-4 justify-content-center mt-3">
      <?php foreach ($plans as $p):
        $kesMo  = (float)$p['price_monthly'];
        $kesAnn = (float)$p['price_annual'];
        $savePct= $kesMo > 0 && $kesAnn > 0 ? max(0, round((1 - $kesAnn / (12 * $kesMo)) * 100)) : 0;
      ?>
      <div class="col-md-4">
        <div class="plan-card <?= $p['is_popular'] ? 'popular' : '' ?>">
          <?php if ($p['is_popular']): ?>
          <div class="plan-badge">Most Popular</div>
          <?php endif; ?>
          <h3 style="font-size:1.2rem;font-weight:800;color:#0B2D4E;margin-bottom:4px"><?= e($p['name']) ?></h3>
          <p style="color:#64748b;font-size:.82rem;margin-bottom:16px"><?= e($p['description']) ?></p>
          <div class="plan-price">
            <sup>KES</sup> <?= number_format($kesMo, 0) ?>
            <small>/mo</small>
          </div>
          <?php if ($savePct > 0): ?>
          <div style="font-size:.78rem;color:#1A8A4E;margin-top:4px">
            Annual: KES <?= number_format($kesAnn, 0) ?>/yr (Save <?= $savePct ?>%)
          </div>
          <?php endif; ?>
          <ul class="plan-features">
            <li>Up to <?= $p['max_users'] ?> team members</li>
            <li>Up to <?= $p['max_modules'] ?> modules active</li>
            <li><?= e($modName) ?> included</li>
            <li>M-Pesa payment integration</li>
            <li>14-day free trial</li>
            <?php if ($p['is_popular']): ?>
            <li>Priority support</li>
            <li>Advanced analytics</li>
            <?php endif; ?>
          </ul>
          <a href="<?= APP_URL ?>/auth/register.php?plan=<?= $p['id'] ?>"
             style="display:block;background:<?= $p['is_popular'] ? 'var(--accent)' : '#0B2D4E' ?>;color:white;padding:12px;border-radius:50px;text-decoration:none;font-weight:700;font-size:.9rem">
            Get Started Free
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <p style="text-align:center;color:#64748b;font-size:.82rem;margin-top:24px">
      <i class="fas fa-shield-alt" style="color:#1A8A4E"></i>
      No credit card required for your free trial.
      <a href="<?= APP_URL ?>/#pricing" style="color:var(--accent);font-weight:600"> Compare all plans →</a>
    </p>
  </div>
</section>
<?php endif; ?>

<!-- Related Modules -->
<?php if (!empty($related)): ?>
<section class="alt" aria-labelledby="related-heading">
  <div class="section-inner">
    <div class="section-label">Explore More</div>
    <h2 id="related-heading" class="section-title">Related Modules</h2>
    <p class="section-sub">All modules are deeply integrated — your data flows seamlessly between them.</p>

    <div class="row g-3 mt-3">
      <?php foreach ($related as $r): ?>
      <div class="col-sm-6 col-md-3">
        <a href="<?= APP_URL ?>/module/<?= e($r['slug']) ?>" class="related-card">
          <div class="related-icon" style="background:<?= e($r['color']) ?>">
            <i class="<?= e($r['icon']) ?>"></i>
          </div>
          <div>
            <div style="font-weight:700;font-size:.85rem"><?= e($r['name']) ?></div>
            <div style="font-size:.72rem;color:#64748b">View module →</div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
      <div class="col-sm-6 col-md-3">
        <a href="<?= APP_URL ?>/#modules" class="related-card" style="border-style:dashed">
          <div class="related-icon" style="background:var(--accent)">
            <i class="fas fa-th"></i>
          </div>
          <div>
            <div style="font-weight:700;font-size:.85rem">All 22 Modules</div>
            <div style="font-size:.72rem;color:#64748b">Browse full catalogue →</div>
          </div>
        </a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="cta-section" aria-labelledby="cta-heading">
  <div class="section-inner">
    <h2 id="cta-heading" class="cta-title">
      Ready to Transform Your <?= e($modCat) ?> Operations?
    </h2>
    <p class="cta-sub">
      Join businesses across Kenya and East Africa using <?= e(APP_NAME) ?> to manage
      <?= e(strtolower($modName)) ?> — and 21 other modules — in one place.
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/auth/register.php" class="btn-hero-primary">
        <i class="fas fa-rocket"></i> Start Free — No Credit Card
      </a>
      <a href="<?= APP_URL ?>/#contact" class="btn-hero-outline">
        <i class="fas fa-phone"></i> Talk to Sales
      </a>
    </div>
    <p style="margin-top:20px;font-size:.78rem;opacity:.6">
      <i class="fas fa-shield-alt me-1"></i>
      14-day free trial · M-Pesa payment integration · Local support · Deploy to cPanel
    </p>
  </div>
</section>

<!-- Footer -->
<footer class="mod-footer">
  <p style="margin-bottom:6px">
    <a href="<?= APP_URL ?>/"><?= e(APP_NAME) ?></a> &bull;
    <a href="<?= APP_URL ?>/#modules">Modules</a> &bull;
    <a href="<?= APP_URL ?>/#pricing">Pricing</a> &bull;
    <a href="<?= APP_URL ?>/auth/register.php">Free Trial</a> &bull;
    <a href="<?= APP_URL ?>/auth/login.php">Login</a> &bull;
    <a href="<?= APP_URL ?>/#contact">Contact</a>
  </p>
  <p><?= e($sitePhone) ?> &bull; <a href="mailto:<?= e($siteEmail) ?>"><?= e($siteEmail) ?></a> &bull; <?= e($siteAddress) ?></p>
  <p style="margin-top:10px">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
</footer>

</body>
</html>
