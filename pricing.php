<?php
/**
 * OrbitDesk — Standalone Pricing Page
 * Pulls live plans and modules from the database.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$appName    = defined('APP_NAME')    ? APP_NAME    : 'OrbitDesk';
$appTagline = defined('APP_TAGLINE') ? APP_TAGLINE : 'All-in-One Business Management Platform';
$appUrl     = defined('APP_URL')     ? APP_URL     : '';

// Load settings
$usdRate    = max(1, (float)(getSetting('usd_rate', '130') ?: 130));
$sitePhone  = getSetting('company_phone',   '+254 700 000 000');
$siteEmail  = getSetting('support_email',   'info@orbitdesk.co.ke');

// Load subscription plans
$plans = [];
try {
    $s = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly ASC");
    $plans = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Load active modules with prices
$allModules = [];
try {
    $s = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY category, sort_order, name");
    $allModules = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Build module categories
$modulesByCategory = [];
foreach ($allModules as $m) {
    $cat = $m['category'] ?: 'Other';
    $modulesByCategory[$cat][] = $m;
}
$modCategories = array_keys($modulesByCategory);
sort($modCategories);

// Compute plan USD values
foreach ($plans as &$p) {
    $mo  = (float)$p['price_monthly'];
    $ann = (float)($p['price_annual'] ?? 0);
    $annMo = $ann > 0 ? round($ann / 12, 2) : 0;
    $p['_kes_mo']       = $mo;
    $p['_kes_ann_mo']   = $annMo;
    $p['_kes_ann_tot']  = $ann;
    $p['_usd_mo']       = $mo  > 0 ? round($mo    / $usdRate, 2) : 0;
    $p['_usd_ann_mo']   = $annMo > 0 ? round($annMo / $usdRate, 2) : 0;
    $p['_usd_ann_tot']  = $ann > 0 ? round($ann    / $usdRate, 2) : 0;
    $p['_save_pct']     = ($mo > 0 && $annMo > 0) ? max(0, round((1 - $annMo / $mo) * 100)) : 20;
}
unset($p);

// Comparison table rows — feature, [tier: 'all'|'mid'|'top'|text|bool]
$compareFeatures = [
    'Platform'   => [
        ['label' => 'Team members (users)',       'vals' => ['users']],
        ['label' => 'Active modules',             'vals' => ['modules']],
        ['label' => 'Cloud storage',              'vals' => ['5 GB', '25 GB', '100 GB']],
        ['label' => 'Multi-branch support',       'vals' => [false, true, true]],
    ],
    'Core Features' => [
        ['label' => 'Real-time analytics & reports',   'vals' => [true, true, true]],
        ['label' => 'M-Pesa STK push payments',        'vals' => [true, true, true]],
        ['label' => 'Invoice & receipt generation',    'vals' => [true, true, true]],
        ['label' => 'Mobile-responsive interface',     'vals' => [true, true, true]],
        ['label' => 'CSV / PDF / Excel export',        'vals' => [true, true, true]],
        ['label' => 'Role-based access control',       'vals' => [true, true, true]],
        ['label' => 'Daily automated backups',         'vals' => [true, true, true]],
        ['label' => 'Hourly backups',                  'vals' => [false, false, true]],
    ],
    'Support & SLA' => [
        ['label' => 'Email & WhatsApp support',    'vals' => [true, true, true]],
        ['label' => 'Priority support queue',      'vals' => [false, true, true]],
        ['label' => 'SLA response time',           'vals' => ['48 hrs', '24 hrs', '4 hrs']],
        ['label' => 'Dedicated account manager',   'vals' => [false, false, true]],
        ['label' => 'Live onboarding session',     'vals' => [false, true, true]],
    ],
    'Customisation' => [
        ['label' => 'Custom branding & logo',      'vals' => [false, true, true]],
        ['label' => 'Custom email domain',         'vals' => [false, true, true]],
        ['label' => 'API access & webhooks',       'vals' => [false, false, true]],
        ['label' => 'Custom module development',   'vals' => [false, false, true]],
        ['label' => 'On-premise deployment',       'vals' => [false, false, true]],
        ['label' => 'White-label option',          'vals' => [false, false, true]],
    ],
];

$planCount = count($plans);
?>
<?php
$pageTitle = 'Pricing — ' . APP_NAME;
$metaDesc  = APP_NAME . ' pricing — transparent, modular plans built for African businesses. Start free for 14 days.';
$activeNav = 'pricing';
ob_start();
?>
  <style>
    /* ── Variables ─────────────────────────────────────────── */
    :root {
      --green:    #1A8A4E;
      --green-lt: #dcfce7;
      --navy:     #0B2D4E;
      --navy-dk:  #050F1F;
      --muted:    #64748b;
      --border:   #e2e8f0;
      --bg:       #f8faff;
    }

    /* ── Base ─────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--navy); margin: 0; overflow-x: hidden; }
    a { text-decoration: none; }

    /* ── Sticky Navbar ────────────────────────────────────── */
    .site-nav {
      position: sticky; top: 0; z-index: 1000;
      background: rgba(255,255,255,.96);
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
    .btn-nav { font-size: .85rem; font-weight: 700; padding: .48rem 1.2rem; border-radius: 8px; border: none; transition: .15s; }
    .btn-nav-outline { border: 1.5px solid var(--border); color: var(--navy); background: transparent; }
    .btn-nav-outline:hover { border-color: var(--green); color: var(--green); }
    .btn-nav-solid { background: var(--green); color: #fff; }
    .btn-nav-solid:hover { background: #157a42; color: #fff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,138,78,.35); }

    /* ── Section helpers ──────────────────────────────────── */
    .section-eyebrow { display: inline-block; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--green); background: var(--green-lt); padding: .3rem .85rem; border-radius: 100px; margin-bottom: .85rem; }
    .section-title { font-size: clamp(1.9rem, 4vw, 2.75rem); font-weight: 900; color: var(--navy); letter-spacing: -.5px; line-height: 1.15; margin-bottom: .6rem; }
    .section-sub { font-size: 1rem; color: var(--muted); max-width: 560px; margin: 0 auto; line-height: 1.65; }

    /* ── Hero ─────────────────────────────────────────────── */
    .pricing-hero {
      background: linear-gradient(135deg, var(--navy-dk) 0%, var(--navy) 55%, #0d3d1e 100%);
      padding: 5.5rem 0 4.5rem;
      position: relative;
      overflow: hidden;
      color: #fff;
      text-align: center;
    }
    .pricing-hero::before {
      content: '';
      position: absolute; inset: 0;
      background-image: radial-gradient(rgba(26,138,78,.12) 1px, transparent 1px);
      background-size: 36px 36px;
    }
    .pricing-hero .hero-glow {
      position: absolute; border-radius: 50%;
      filter: blur(80px); pointer-events: none;
    }
    .pricing-hero .glow-1 { width: 500px; height: 500px; background: rgba(26,138,78,.18); right: -100px; top: -100px; }
    .pricing-hero .glow-2 { width: 320px; height: 320px; background: rgba(11,45,78,.5);  left:  -50px; bottom: -80px; }
    .pricing-hero h1 { font-size: clamp(2.2rem, 5vw, 3.4rem); font-weight: 900; letter-spacing: -.6px; line-height: 1.1; margin-bottom: .8rem; }
    .pricing-hero p { font-size: 1.05rem; opacity: .8; max-width: 540px; margin: 0 auto 2rem; line-height: 1.65; }
    .trust-bar { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem 1.5rem; }
    .trust-item { font-size: .82rem; font-weight: 600; opacity: .85; display: flex; align-items: center; gap: .4rem; }
    .trust-item i { color: #4ade80; font-size: .85rem; }

    /* ── Billing Controls ─────────────────────────────────── */
    .controls-bar { padding: 2.5rem 0 0; text-align: center; }
    .cycle-toggle {
      display: inline-flex; align-items: center; gap: .75rem;
      background: #fff; border: 1.5px solid var(--border); border-radius: 100px;
      padding: .45rem 1.1rem; box-shadow: 0 2px 12px rgba(0,0,0,.06);
    }
    .cycle-toggle span { font-size: .85rem; font-weight: 600; color: var(--muted); transition: color .15s; }
    .cycle-toggle span.active { color: var(--navy); }
    .form-check-input:checked { background-color: var(--green); border-color: var(--green); }
    .save-badge { font-size: .68rem; font-weight: 800; background: var(--green-lt); color: var(--green); padding: .2rem .55rem; border-radius: 100px; vertical-align: middle; }
    .currency-switch {
      display: inline-flex; border: 1.5px solid var(--border); border-radius: 100px;
      overflow: hidden; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.06);
    }
    .currency-switch button {
      font-size: .82rem; font-weight: 700; padding: .4rem 1rem;
      border: none; background: transparent; color: var(--muted); cursor: pointer; transition: .15s;
    }
    .currency-switch button.active { background: var(--navy); color: #fff; }

    /* ── Plan Cards ───────────────────────────────────────── */
    .plans-section { padding: 2rem 0 3.5rem; }
    .plan-card {
      background: #fff;
      border: 1.5px solid var(--border);
      border-radius: 20px;
      padding: 2rem;
      position: relative;
      transition: transform .25s, box-shadow .25s, border-color .25s;
      height: 100%;
      display: flex; flex-direction: column;
    }
    .plan-card:hover { transform: translateY(-6px); box-shadow: 0 20px 56px rgba(11,45,78,.1); }
    .plan-card.popular {
      border-color: var(--green);
      box-shadow: 0 0 0 2px var(--green), 0 12px 40px rgba(26,138,78,.18);
    }
    .plan-card.popular:hover { transform: translateY(-8px); box-shadow: 0 0 0 2px var(--green), 0 24px 60px rgba(26,138,78,.22); }
    .pop-ribbon {
      position: absolute; top: -1px; left: 50%; transform: translateX(-50%);
      background: var(--green); color: #fff;
      font-size: .7rem; font-weight: 800; letter-spacing: .6px;
      padding: .28rem 1.1rem; border-radius: 0 0 10px 10px;
      white-space: nowrap;
    }
    .plan-name { font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); margin-bottom: .4rem; }
    .plan-desc { font-size: .82rem; color: var(--muted); margin-bottom: 1.25rem; min-height: 2.6rem; }
    .plan-price-block { margin-bottom: .3rem; }
    .plan-price {
      font-size: 3.2rem; font-weight: 900; color: var(--navy);
      line-height: 1; letter-spacing: -2px;
      display: flex; align-items: flex-start; gap: 2px;
    }
    .plan-price sup { font-size: 1.1rem; font-weight: 700; color: var(--muted); margin-top: .55rem; letter-spacing: 0; }
    .plan-price .per { font-size: .88rem; font-weight: 500; color: var(--muted); align-self: flex-end; margin-bottom: .25rem; letter-spacing: 0; }
    .plan-billed { font-size: .76rem; color: var(--muted); min-height: 1.3rem; margin-bottom: 1.5rem; }
    .plan-billed .savings { color: var(--green); font-weight: 700; }
    .plan-features { list-style: none; padding: 0; margin: 0 0 1.75rem; flex: 1; }
    .plan-features li { display: flex; align-items: flex-start; gap: .6rem; padding: .4rem 0; font-size: .855rem; color: #475569; border-bottom: 1px solid #f1f5f9; }
    .plan-features li:last-child { border-bottom: none; }
    .plan-features li .fi { width: 16px; flex-shrink: 0; margin-top: .15rem; }
    .fi-yes  { color: var(--green); }
    .fi-no   { color: #cbd5e1; }
    .btn-plan-primary {
      display: block; width: 100%; padding: .88rem; border-radius: 10px;
      font-weight: 700; font-size: .9rem; text-align: center;
      background: var(--green); color: #fff; border: none;
      transition: background .15s, transform .1s, box-shadow .15s;
      cursor: pointer;
    }
    .btn-plan-primary:hover { background: #157a42; color: #fff; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,138,78,.35); }
    .btn-plan-outline-plan {
      display: block; width: 100%; padding: .88rem; border-radius: 10px;
      font-weight: 700; font-size: .9rem; text-align: center;
      background: transparent; color: var(--navy);
      border: 1.5px solid var(--border);
      transition: all .15s;
    }
    .btn-plan-outline-plan:hover { border-color: var(--green); color: var(--green); }

    /* ── Trust bar ────────────────────────────────────────── */
    .trust-strip { padding: 1.5rem 0 3rem; }
    .trust-badge {
      display: flex; align-items: center; gap: .65rem;
      font-size: .82rem; color: var(--muted);
    }
    .trust-badge-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: .85rem; flex-shrink: 0; }

    /* ── Compare Table ────────────────────────────────────── */
    .compare-section { padding: 4rem 0; background: #fff; }
    .compare-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 16px; border: 1.5px solid var(--border); box-shadow: 0 4px 24px rgba(0,0,0,.05); }
    .compare-table {
      width: 100%; border-collapse: collapse;
      min-width: 680px;
    }
    .compare-table thead th {
      padding: 1.2rem 1.4rem;
      font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .8px;
      text-align: center; border-bottom: 2px solid var(--border);
      background: #fafbfc;
      position: sticky; top: 0; z-index: 2;
    }
    .compare-table thead th:first-child { text-align: left; min-width: 220px; }
    .compare-table thead th.col-popular { background: #f0fdf4; color: var(--green); }
    .compare-table .group-header td {
      padding: .7rem 1.4rem;
      font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
      color: var(--green); background: #f0fdf4; border-top: 1px solid #bbf7d0;
    }
    .compare-table tbody tr:not(.group-header) td {
      padding: .85rem 1.4rem; border-bottom: 1px solid #f1f5f9;
      font-size: .855rem; vertical-align: middle;
    }
    .compare-table tbody tr:not(.group-header) td:first-child { color: #334155; font-weight: 500; }
    .compare-table tbody tr:not(.group-header) td:not(:first-child) { text-align: center; }
    .compare-table tbody tr:not(.group-header) td.col-popular { background: #fafffe; }
    .compare-table tbody tr:not(.group-header):last-child td { border-bottom: none; }
    .compare-table tbody tr:hover td { background: #fafbfc; }
    .compare-table tbody tr:hover td.col-popular { background: #f5fff8; }
    .chk-yes { color: var(--green); font-size: 1rem; }
    .chk-no  { color: #cbd5e1; font-size: .9rem; }
    .chk-val { font-size: .82rem; font-weight: 600; color: var(--navy); }

    /* ── Module Add-ons ───────────────────────────────────── */
    .modules-section { padding: 4.5rem 0; background: var(--bg); }
    .cat-tabs { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; margin-bottom: 2.5rem; }
    .cat-tab {
      font-size: .8rem; font-weight: 600; padding: .4rem 1.1rem;
      border-radius: 100px; border: 1.5px solid var(--border);
      background: #fff; color: var(--muted); cursor: pointer; transition: .15s;
    }
    .cat-tab:hover { border-color: var(--green); color: var(--green); }
    .cat-tab.active { background: var(--navy); border-color: var(--navy); color: #fff; }
    .mod-card {
      background: #fff; border: 1.5px solid var(--border);
      border-radius: 14px; padding: 1.2rem 1.1rem;
      display: flex; align-items: flex-start; gap: .9rem;
      transition: transform .2s, box-shadow .2s, border-color .2s;
      cursor: default;
    }
    .mod-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.08); border-color: #c7d2fe; }
    .mod-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #fff; flex-shrink: 0; }
    .mod-name { font-size: .88rem; font-weight: 700; color: var(--navy); margin-bottom: .15rem; }
    .mod-price { font-size: .8rem; color: var(--muted); }
    .mod-price strong { color: var(--green); font-weight: 700; font-size: .88rem; }

    /* ── FAQ ──────────────────────────────────────────────── */
    .faq-section { padding: 5rem 0; background: #fff; }
    .faq-accordion .accordion-button { font-size: .9rem; font-weight: 600; color: var(--navy); background: #fff; }
    .faq-accordion .accordion-button:not(.collapsed) { color: var(--green); background: #f0fdf4; box-shadow: none; }
    .faq-accordion .accordion-button::after { filter: hue-rotate(110deg); }
    .faq-accordion .accordion-item { border: 1px solid var(--border); border-radius: 12px !important; margin-bottom: .6rem; overflow: hidden; }
    .faq-accordion .accordion-body { font-size: .875rem; color: #475569; line-height: 1.7; }

    /* ── Enterprise CTA ───────────────────────────────────── */
    .enterprise-cta {
      background: linear-gradient(135deg, var(--navy-dk), var(--navy), #0d3d1e);
      padding: 5rem 0;
      color: #fff;
      text-align: center;
      position: relative; overflow: hidden;
    }
    .enterprise-cta::before {
      content: '';
      position: absolute; inset: 0;
      background-image: radial-gradient(rgba(255,255,255,.04) 1px, transparent 1px);
      background-size: 32px 32px;
    }
    .enterprise-cta h2 { font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 900; letter-spacing: -.4px; margin-bottom: .7rem; }
    .enterprise-cta p { opacity: .75; font-size: .95rem; max-width: 480px; margin: 0 auto 2rem; line-height: 1.65; }
    .cta-stat { font-size: .8rem; opacity: .6; margin-top: 1.5rem; }

    /* ── Footer ───────────────────────────────────────────── */
    .site-footer { background: #040f1e; color: #90a4c0; padding: 2.8rem 0 1.5rem; }
    .footer-brand { font-size: 1.2rem; font-weight: 900; color: #fff; }
    .footer-brand span { color: var(--green); }
    .footer-divider { border-color: rgba(255,255,255,.07); margin: 1.5rem 0 1rem; }
    .footer-links { list-style: none; padding: 0; margin: 0; }
    .footer-links li { margin-bottom: .4rem; }
    .footer-links a { color: #90a4c0; font-size: .82rem; transition: color .15s; }
    .footer-links a:hover { color: #fff; }
    .footer-copy { font-size: .78rem; color: #4a6080; }
    .footer-col-title { font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; color: #4a6080; margin-bottom: .85rem; }

    /* ── Responsive ───────────────────────────────────────── */
    @media (max-width: 991px) { .nav-links { display: none; } }
    @media (max-width: 767px) {
      .plan-card.popular { margin-top: .5rem; }
      .compare-table thead th, .compare-table tbody td { padding: .7rem .9rem; }
    }
<?php
$extraHeadHtml = ob_get_clean();
require_once __DIR__ . '/includes/header-public.php';
?>

<!-- ══════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════ -->
<section class="pricing-hero">
  <div class="hero-glow glow-1"></div>
  <div class="hero-glow glow-2"></div>
  <div class="container position-relative">
    <span class="section-eyebrow" style="color:#4ade80;background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25)">
      Transparent Pricing
    </span>
    <h1>Simple Plans for<br>Every Business Size</h1>
    <p>Start free for 14 days — no credit card needed. Scale up as your team grows.<br>Only pay for the modules you actually use.</p>
    <div class="trust-bar">
      <div class="trust-item"><i class="fas fa-check-circle"></i> 14-day free trial</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> No credit card required</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> Cancel anytime</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> M-Pesa integrated</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> Local EAT support</div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     BILLING CONTROLS
════════════════════════════════════════════════════════════ -->
<div class="controls-bar">
  <div class="d-flex flex-wrap align-items-center justify-content-center gap-3">
    <!-- Billing cycle toggle -->
    <div class="cycle-toggle">
      <span id="lblMonthly" class="active">Monthly</span>
      <div class="form-check form-switch mb-0" style="padding-left:2.5em">
        <input class="form-check-input" type="checkbox" id="billingToggle" style="width:44px;height:22px;cursor:pointer" role="switch" aria-checked="false">
      </div>
      <span id="lblAnnual">Annual &nbsp;<span class="save-badge" id="saveBadge">Save 20%</span></span>
    </div>
    <!-- Currency selector -->
    <div class="currency-switch" role="group">
      <button id="btnUSD" class="active" onclick="setCurrency('USD')" aria-pressed="true">$ USD</button>
      <button id="btnKES" onclick="setCurrency('KES')" aria-pressed="false">KES</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PLAN CARDS
════════════════════════════════════════════════════════════ -->
<section class="plans-section">
  <div class="container">

    <?php if (empty($plans)): ?>
    <div class="alert alert-info text-center">Plans loading — please check back shortly or contact us for pricing.</div>
    <?php else: ?>

    <div class="row g-4 justify-content-center align-items-start">
      <?php foreach ($plans as $plan):
        $pop = (bool)($plan['is_popular'] ?? false);
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="plan-card <?= $pop ? 'popular' : '' ?>">
          <?php if ($pop): ?>
          <div class="pop-ribbon"><i class="fas fa-fire me-1"></i>Most Popular</div>
          <?php endif; ?>

          <div class="plan-name"><?= htmlspecialchars($plan['name'], ENT_QUOTES) ?></div>
          <p class="plan-desc"><?= htmlspecialchars($plan['description'] ?? '', ENT_QUOTES) ?></p>

          <!-- Price block -->
          <div class="plan-price-block">
            <div class="plan-price">
              <sup class="plan-cur">$</sup>
              <span class="plan-price-val"
                    data-usd-mo="<?= number_format($plan['_usd_mo'], 2) ?>"
                    data-usd-ann-mo="<?= number_format($plan['_usd_ann_mo'], 2) ?>"
                    data-kes-mo="<?= number_format($plan['_kes_mo'], 0, '.', ',') ?>"
                    data-kes-ann-mo="<?= number_format($plan['_kes_ann_mo'], 0, '.', ',') ?>">
                <?= number_format($plan['_usd_mo'], 2) ?>
              </span>
              <span class="per">/mo</span>
            </div>
            <p class="plan-billed"
               data-usd-ann-total="<?= number_format($plan['_usd_ann_tot'], 2) ?>"
               data-kes-ann-total="<?= number_format($plan['_kes_ann_tot'], 0, '.', ',') ?>"
               data-save-pct="<?= $plan['_save_pct'] ?>">
            </p>
          </div>

          <!-- Feature list -->
          <ul class="plan-features">
            <li>
              <i class="fas fa-users fi fi-yes"></i>
              Up to <strong><?= (int)($plan['max_users'] ?? 0) ?> team members</strong>
            </li>
            <li>
              <i class="fas fa-cubes fi fi-yes"></i>
              <strong><?= (int)($plan['max_modules'] ?? 0) ?> modules</strong> included
            </li>
            <li><i class="fas fa-chart-line fi fi-yes"></i> Real-time analytics &amp; reports</li>
            <li><i class="fas fa-mobile-alt fi fi-yes"></i> M-Pesa payment integration</li>
            <li><i class="fas fa-shield-alt fi fi-yes"></i> Daily backups &amp; SSL encryption</li>
            <li><i class="fas fa-headset fi fi-yes"></i> Email &amp; WhatsApp support</li>
            <?php if (($plan['max_users'] ?? 0) >= 25): ?>
            <li><i class="fas fa-bolt fi fi-yes"></i> Priority support queue</li>
            <li><i class="fas fa-paint-brush fi fi-yes"></i> Custom branding &amp; logo</li>
            <li><i class="fas fa-chalkboard-teacher fi fi-yes"></i> Live onboarding session</li>
            <?php else: ?>
            <li><i class="fas fa-bolt fi fi-no"></i> <span style="color:#94a3b8">Priority support</span></li>
            <li><i class="fas fa-paint-brush fi fi-no"></i> <span style="color:#94a3b8">Custom branding</span></li>
            <?php endif; ?>
            <?php if (($plan['max_users'] ?? 0) >= 100): ?>
            <li><i class="fas fa-user-tie fi fi-yes"></i> Dedicated account manager</li>
            <li><i class="fas fa-code fi fi-yes"></i> API access &amp; webhooks</li>
            <li><i class="fas fa-server fi fi-yes"></i> On-premise deployment</li>
            <?php else: ?>
            <li><i class="fas fa-user-tie fi fi-no"></i> <span style="color:#94a3b8">Dedicated manager</span></li>
            <?php if (($plan['max_users'] ?? 0) < 25): ?>
            <li><i class="fas fa-code fi fi-no"></i> <span style="color:#94a3b8">API access</span></li>
            <?php endif; ?>
            <?php endif; ?>
          </ul>

          <a href="<?= htmlspecialchars($appUrl . '/auth/register.php?plan=' . $plan['id'], ENT_QUOTES) ?>"
             class="<?= $pop ? 'btn-plan-primary' : 'btn-plan-outline-plan' ?>">
            Start Free Trial &nbsp;<i class="fas fa-arrow-right" style="font-size:.75rem"></i>
          </a>
          <p class="text-center mt-2 mb-0" style="font-size:.73rem;color:#94a3b8">No credit card · Cancel anytime</p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Custom plan row -->
    <div class="mt-4 pt-3 border-top text-center">
      <div class="d-inline-flex align-items-center gap-4 flex-wrap justify-content-center">
        <div>
          <span class="fw-700" style="color:var(--navy);font-size:.9rem">Need a custom enterprise plan?</span>
          <span class="text-muted ms-2" style="font-size:.85rem">Unlimited users, SLA guarantee &amp; dedicated infra.</span>
        </div>
        <a href="contact.php" class="btn btn-nav btn-nav-solid" style="white-space:nowrap">
          <i class="fas fa-comments me-1"></i>Talk to Sales
        </a>
      </div>
    </div>

    <?php endif; ?>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     TRUST BADGES
════════════════════════════════════════════════════════════ -->
<div class="trust-strip">
  <div class="container">
    <div class="row g-3 justify-content-center">
      <?php
      $badges = [
        ['fas fa-lock',          '#0d47a1', '#e3f2fd', 'Bank-Grade Security',       'AES-256 encryption + CSRF protection'],
        ['fas fa-undo',          '#6a1b9a', '#f3e5f5', '30-Day Money-Back',         'Not satisfied? Full refund, no questions'],
        ['fas fa-calendar-plus', '#1A8A4E', '#dcfce7', '14-Day Free Trial',         'Full access, no credit card required'],
        ['fas fa-headset',       '#e53935', '#ffebee', 'Local EAT Support',         'Mon–Fri 8 AM–6 PM East Africa Time'],
      ];
      foreach ($badges as [$icon, $color, $bg, $title, $sub]): ?>
      <div class="col-6 col-md-3">
        <div class="trust-badge">
          <div class="trust-badge-icon" style="background:<?= $bg ?>"><i class="<?= $icon ?>" style="color:<?= $color ?>"></i></div>
          <div>
            <div style="font-size:.82rem;font-weight:700;color:var(--navy)"><?= $title ?></div>
            <div style="font-size:.74rem;color:var(--muted)"><?= $sub ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     FEATURE COMPARISON TABLE
════════════════════════════════════════════════════════════ -->
<section class="compare-section" id="compare">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-eyebrow">Compare Plans</span>
      <h2 class="section-title">Everything Included — Side by Side</h2>
      <p class="section-sub">Pick the plan that matches your team. All plans include a 14-day free trial.</p>
    </div>

    <div class="compare-table-wrap">
      <table class="compare-table">
        <thead>
          <tr>
            <th style="text-align:left;color:var(--muted)">Feature</th>
            <?php foreach ($plans as $i => $plan): $pop = (bool)($plan['is_popular'] ?? false); ?>
            <th class="<?= $pop ? 'col-popular' : '' ?>">
              <?= htmlspecialchars($plan['name'], ENT_QUOTES) ?>
              <?php if ($pop): ?><br><span style="font-size:.62rem;font-weight:600;color:var(--green);background:var(--green-lt);padding:.1rem .5rem;border-radius:100px">★ Most Popular</span><?php endif; ?>
            </th>
            <?php endforeach; ?>
            <?php if ($planCount < 3): ?><th style="color:var(--muted)">Enterprise</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($compareFeatures as $groupName => $rows): ?>
          <tr class="group-header"><td colspan="<?= $planCount + 1 ?>"><?= htmlspecialchars($groupName, ENT_QUOTES) ?></td></tr>
          <?php foreach ($rows as $row):
            $vals = $row['vals'];
            // Pad to match plan count
            while (count($vals) < max(3, $planCount)) $vals[] = end($vals);
          ?>
          <tr>
            <td><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></td>
            <?php foreach ($plans as $i => $plan):
              $pop = (bool)($plan['is_popular'] ?? false);
              $val = $vals[$i] ?? $vals[count($vals)-1];
              if ($val === 'users')   $val = ($plan['max_users'] ?? '?') . ' users';
              if ($val === 'modules') $val = ($plan['max_modules'] ?? '?') . ' modules';
            ?>
            <td class="<?= $pop ? 'col-popular' : '' ?>">
              <?php if ($val === true): ?>
                <i class="fas fa-check-circle chk-yes"></i>
              <?php elseif ($val === false): ?>
                <i class="fas fa-minus chk-no"></i>
              <?php else: ?>
                <span class="chk-val"><?= htmlspecialchars((string)$val, ENT_QUOTES) ?></span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <?php if ($planCount < 3):
              $lastVal = $vals[2] ?? $vals[count($vals)-1];
            ?>
            <td>
              <?php if ($lastVal === true): ?>
                <i class="fas fa-check-circle chk-yes"></i>
              <?php elseif ($lastVal === false): ?>
                <i class="fas fa-minus chk-no"></i>
              <?php else: ?>
                <span class="chk-val"><?= htmlspecialchars((string)$lastVal, ENT_QUOTES) ?></span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     MODULE ADD-ONS
════════════════════════════════════════════════════════════ -->
<?php if (!empty($allModules)): ?>
<section class="modules-section" id="modules">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-eyebrow">Module Pricing</span>
      <h2 class="section-title">Pick Only What You Need</h2>
      <p class="section-sub">Every module is available as an add-on. Mix and match to build the perfect stack for your business.</p>
    </div>

    <!-- Category filter tabs -->
    <?php if (count($modCategories) > 1): ?>
    <div class="cat-tabs" id="catTabs">
      <button class="cat-tab active" onclick="filterMods('all', this)">All Modules</button>
      <?php foreach ($modCategories as $cat): ?>
      <button class="cat-tab" onclick="filterMods(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>, this)">
        <?= htmlspecialchars(ucwords($cat), ENT_QUOTES) ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Module grid -->
    <div class="row g-3" id="modGrid">
      <?php foreach ($allModules as $m):
        $mo  = (float)($m['monthly_price'] ?? 0);
        $ann = (float)($m['annual_price']  ?? 0);
        $annMo = $ann > 0 ? round($ann / 12, 2) : 0;
        $usdMo = $mo > 0 ? round($mo / $usdRate, 2) : 0;
        $usdAnnMo = $annMo > 0 ? round($annMo / $usdRate, 2) : 0;
        $color = $m['color'] ?: '#1A8A4E';
      ?>
      <div class="col-sm-6 col-lg-4 mod-item" data-cat="<?= htmlspecialchars($m['category'] ?: 'Other', ENT_QUOTES) ?>">
        <div class="mod-card">
          <div class="mod-icon" style="background:<?= htmlspecialchars($color, ENT_QUOTES) ?>">
            <i class="<?= htmlspecialchars($m['icon'] ?: 'fas fa-cube', ENT_QUOTES) ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div class="mod-name"><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></div>
            <div class="mod-price">
              <?php if ($mo > 0): ?>
              <span class="mod-price-display"
                    data-usd-mo="$<?= number_format($usdMo, 2) ?>"
                    data-usd-ann-mo="$<?= number_format($usdAnnMo, 2) ?>"
                    data-kes-mo="KES <?= number_format($mo, 0, '.', ',') ?>"
                    data-kes-ann-mo="KES <?= number_format($annMo, 0, '.', ',') ?>">
                <strong>$<?= number_format($usdMo, 2) ?></strong>/mo
              </span>
              <?php else: ?>
              <span style="color:var(--green);font-weight:700;font-size:.82rem">Included in plan</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="text-center text-muted mt-4 mb-0" style="font-size:.82rem">
      <i class="fas fa-info-circle me-1"></i>
      Module prices shown are per-month add-ons. Annual billing saves up to 20%.
      <a href="contact.php" style="color:var(--green);font-weight:600">Need a custom bundle?</a>
    </p>
  </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     FAQ
════════════════════════════════════════════════════════════ -->
<section class="faq-section" id="faq">
  <div class="container">
    <div class="row g-5 align-items-start">
      <div class="col-lg-4">
        <span class="section-eyebrow">FAQ</span>
        <h2 class="section-title text-start" style="font-size:2rem">Pricing<br>Questions</h2>
        <p class="text-muted mt-2 mb-4" style="font-size:.88rem;line-height:1.7">
          Can't find your answer? Our team is happy to help — we respond within minutes.
        </p>
        <div class="d-flex flex-column gap-3">
          <a href="contact.php" class="btn btn-nav btn-nav-solid d-inline-flex align-items-center gap-2" style="justify-content:center">
            <i class="fas fa-comments"></i>Chat with Sales
          </a>
          <a href="mailto:<?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>" class="btn btn-nav btn-nav-outline d-inline-flex align-items-center gap-2" style="justify-content:center">
            <i class="fas fa-envelope"></i><?= htmlspecialchars($siteEmail, ENT_QUOTES) ?>
          </a>
          <a href="tel:<?= preg_replace('/\s+/', '', $sitePhone) ?>" class="btn btn-nav btn-nav-outline d-inline-flex align-items-center gap-2" style="justify-content:center">
            <i class="fas fa-phone"></i><?= htmlspecialchars($sitePhone, ENT_QUOTES) ?>
          </a>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="accordion faq-accordion" id="faqAccordion">
          <?php
          $faqs = [
            ['q' => 'Can I subscribe to just one module?',
             'a' => 'Yes! OrbitDesk is fully modular — you can subscribe to as few as one module. Your base plan includes a set number of modules, and you can add more at individual module pricing. Only pay for what your business actually uses.'],
            ['q' => 'What happens after the 14-day free trial?',
             'a' => 'After your trial ends, you can choose a paid plan to continue. Your data is retained securely for 30 days even if you don\'t upgrade immediately, giving you time to decide. No automatic charges — you choose when to upgrade.'],
            ['q' => 'Can I switch plans at any time?',
             'a' => 'Absolutely. You can upgrade or downgrade your plan at any time from your account settings. Upgrades are effective immediately. Downgrades take effect at the start of your next billing cycle.'],
            ['q' => 'How does annual billing work?',
             'a' => 'Annual billing charges you once a year (a single payment) at a discounted rate — typically 15–20% cheaper than paying month-to-month. You can see the exact savings on each plan card above by toggling to Annual.'],
            ['q' => 'Is my data safe? What security measures are in place?',
             'a' => 'Your data is encrypted at rest (AES-256) and in transit (TLS). Every page uses CSRF protection. We perform daily automated backups with 30-day retention. Enterprise plans include hourly backups and a dedicated SLA.'],
            ['q' => 'How does the M-Pesa integration work?',
             'a' => 'All plans include native Safaricom Daraja API integration for M-Pesa STK push payments. Customers can pay via M-Pesa across billing, POS, SACCO, school fees, rental, and all other payment-enabled modules — no third-party service required.'],
            ['q' => 'Can I deploy OrbitDesk on my own server?',
             'a' => 'Yes — Enterprise plans include an on-premise deployment option on any standard cPanel-compatible server. Our team handles setup, configuration, and ongoing support at no extra cost for Enterprise subscribers.'],
            ['q' => 'Do you offer custom module development?',
             'a' => 'Yes. We build custom modules for unique business requirements. Contact our team with your specifications and we\'ll scope the project and provide a fixed-price quote within 48 business hours.'],
          ];
          foreach ($faqs as $i => $faq): ?>
          <div class="accordion-item">
            <h2 class="accordion-header">
              <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?>"
                      type="button" data-bs-toggle="collapse"
                      data-bs-target="#faq<?= $i ?>">
                <?= htmlspecialchars($faq['q'], ENT_QUOTES) ?>
              </button>
            </h2>
            <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#faqAccordion">
              <div class="accordion-body"><?= htmlspecialchars($faq['a'], ENT_QUOTES) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     ENTERPRISE / CTA BANNER
════════════════════════════════════════════════════════════ -->
<section class="enterprise-cta">
  <div class="container position-relative">
    <span class="section-eyebrow" style="color:#4ade80;background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25)">
      Custom Enterprise
    </span>
    <h2>Your Business, Your Rules</h2>
    <p>Need unlimited users, a private server, white-label branding, or a custom SLA?<br>Let's build a plan around exactly what you need.</p>
    <div class="d-flex flex-wrap gap-3 justify-content-center">
      <a href="contact.php" class="btn fw-700 px-4 py-3 text-white" style="background:var(--green);border-radius:10px;font-size:.9rem">
        <i class="fas fa-calendar-check me-2"></i>Book a Demo
      </a>
      <a href="<?= htmlspecialchars($appUrl . '/auth/register.php', ENT_QUOTES) ?>" class="btn fw-700 px-4 py-3" style="background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.25);color:#fff;border-radius:10px;font-size:.9rem">
        <i class="fas fa-rocket me-2"></i>Start Free Trial
      </a>
    </div>
    <p class="cta-stat">
      <i class="fas fa-building me-2"></i>Trusted by 500+ businesses across East Africa
    </p>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════ -->

<?php
ob_start();
?>
<script>
let _cycle    = 'monthly';
let _currency = 'USD';

function setCurrency(cur) {
  _currency = cur;
  document.getElementById('btnUSD').classList.toggle('active', cur === 'USD');
  document.getElementById('btnKES').classList.toggle('active', cur === 'KES');
  document.getElementById('btnUSD').setAttribute('aria-pressed', cur === 'USD');
  document.getElementById('btnKES').setAttribute('aria-pressed', cur === 'KES');
  refreshPrices();
}

document.getElementById('billingToggle').addEventListener('change', function () {
  _cycle = this.checked ? 'annual' : 'monthly';
  const isAnn = _cycle === 'annual';
  document.getElementById('lblMonthly').classList.toggle('active', !isAnn);
  document.getElementById('lblAnnual').classList.toggle('active',   isAnn);
  this.setAttribute('aria-checked', isAnn);
  refreshPrices();
});

function refreshPrices() {
  const isAnn = _cycle === 'annual';
  const isKES = _currency === 'KES';
  const sym   = isKES ? 'KES ' : '$';

  document.querySelectorAll('.plan-price-val').forEach(el => {
    const raw = isKES ? (isAnn ? el.dataset.kesAnnMo : el.dataset.kesMo)
                      : (isAnn ? el.dataset.usdAnnMo : el.dataset.usdMo);
    if (raw !== undefined) el.textContent = raw;
  });
  document.querySelectorAll('.plan-cur').forEach(el => {
    el.textContent = isKES ? 'KES ' : '$';
    el.style.fontSize  = isKES ? '.7rem' : '';
    el.style.marginTop = isKES ? '.8rem' : '';
  });
  document.querySelectorAll('.plan-billed').forEach(el => {
    const annTot  = isKES ? el.dataset.kesAnnTotal : el.dataset.usdAnnTotal;
    const savePct = el.dataset.savePct || 20;
    el.innerHTML = (isAnn && annTot)
      ? `Billed <strong>${sym}${annTot}</strong> / year &nbsp;<span class="savings">Save ${savePct}%</span>`
      : 'Billed monthly &mdash; upgrade anytime';
  });
  document.querySelectorAll('.mod-price-display').forEach(el => {
    const raw = isKES ? (isAnn ? el.dataset.kesAnnMo : el.dataset.kesMo)
                      : (isAnn ? el.dataset.usdAnnMo : el.dataset.usdMo);
    if (raw) el.innerHTML = `<strong>${raw}</strong>/mo`;
  });
  const firstCard = document.querySelector('.plan-price-val');
  if (firstCard) {
    const billed = firstCard.closest('.plan-card')?.querySelector('.plan-billed');
    const pct    = billed?.dataset.savePct || 20;
    document.getElementById('saveBadge').textContent = `Save ${pct}%`;
  }
}

function filterMods(cat, btn) {
  document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.mod-item').forEach(el => {
    el.style.display = (cat === 'all' || el.dataset.cat === cat) ? '' : 'none';
  });
}

refreshPrices();
</script>
<?php
$extraBodyJs = ob_get_clean();
require_once __DIR__ . '/includes/footer-public.php';
?>