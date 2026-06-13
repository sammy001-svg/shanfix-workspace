<?php
/**
 * Shared public-facing header: <head> + navbar.
 *
 * Set these variables BEFORE require_once-ing this file:
 *   $pageTitle      (string)  Full <title> text
 *   $metaDesc       (string)  Meta description
 *   $canonicalUrl   (string)  Canonical URL (defaults to APP_URL)
 *   $ogImage        (string)  OG image URL
 *   $activeNav      (string)  'home' | 'pricing' | 'contact'  (marks active link)
 *   $bodyClass      (string)  Extra class(es) on <body> (optional)
 *   $extraHeadHtml  (string)  Raw HTML injected just before </head> (page CSS, JSON-LD, etc.)
 */
$_appName  = defined('APP_NAME') ? APP_NAME : 'OrbitDesk';
$_appUrl   = defined('APP_URL')  ? APP_URL  : '';
$_activeNav     = $activeNav     ?? '';
$_canonicalUrl  = $canonicalUrl  ?? $_appUrl;
$_ogImage       = $ogImage       ?? ($_appUrl . '/assets/images/og-banner-1200.png');
$_pageTitle     = $pageTitle     ?? $_appName;
$_metaDesc      = $metaDesc      ?? 'The all-in-one business management platform for African businesses.';
$_bodyClass     = $bodyClass     ?? '';
$_extraHeadHtml = $extraHeadHtml ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($_pageTitle, ENT_QUOTES) ?></title>
<meta name="description" content="<?= htmlspecialchars($_metaDesc, ENT_QUOTES) ?>">
<meta name="author"      content="<?= htmlspecialchars($_appName, ENT_QUOTES) ?>">
<?php if ($_canonicalUrl): ?>
<link rel="canonical" href="<?= htmlspecialchars($_canonicalUrl, ENT_QUOTES) ?>">
<?php endif; ?>
<!-- Open Graph -->
<meta property="og:type"        content="website">
<meta property="og:site_name"   content="<?= htmlspecialchars($_appName, ENT_QUOTES) ?>">
<meta property="og:title"       content="<?= htmlspecialchars($_pageTitle, ENT_QUOTES) ?>">
<meta property="og:description" content="<?= htmlspecialchars($_metaDesc, ENT_QUOTES) ?>">
<meta property="og:image"       content="<?= htmlspecialchars($_ogImage, ENT_QUOTES) ?>">
<meta property="og:url"         content="<?= htmlspecialchars($_canonicalUrl, ENT_QUOTES) ?>">
<!-- Twitter Card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= htmlspecialchars($_pageTitle, ENT_QUOTES) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($_metaDesc, ENT_QUOTES) ?>">
<meta name="twitter:image"       content="<?= htmlspecialchars($_ogImage, ENT_QUOTES) ?>">
<!-- Favicon & PWA -->
<link rel="icon" type="image/svg+xml" href="<?= $_appUrl ?>/assets/images/favicon.svg">
<link rel="manifest" href="<?= $_appUrl ?>/manifest.php">
<meta name="theme-color" content="#1A8A4E">
<!-- Dependencies -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── CSS Variables ────────────────────────────────────────── */
:root {
  --od-navy:  #0B2D4E;
  --od-green: #1A8A4E;
  --od-glow:  rgba(26,138,78,.25);
}

/* ── Base ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body.pub-body { font-family: 'Inter', system-ui, sans-serif; background: #fff; overflow-x: hidden; margin: 0; }
a { text-decoration: none; }

/* ── Scroll Progress Bar ──────────────────────────────────── */
#od-scroll-progress {
  position: fixed; top: 0; left: 0; height: 3px;
  background: linear-gradient(90deg, var(--od-green), #22d3a5);
  z-index: 99999; width: 0%; transition: width .1s linear;
  pointer-events: none;
}

/* ── Navbar ───────────────────────────────────────────────── */
.od-nav {
  position: fixed; top: 0; left: 0; right: 0;
  padding: .9rem 0; z-index: 9000;
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
  display: flex; align-items: center; gap: .6rem; text-decoration: none;
}
.od-nav .logo-mark {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, var(--od-green), #22c27a);
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; color: white; font-size: .8rem; letter-spacing: -.5px;
  box-shadow: 0 4px 12px rgba(26,138,78,.4); flex-shrink: 0;
}
.od-nav .logo-name { font-size: 1.1rem; font-weight: 800; color: white; letter-spacing: -.3px; }
.od-nav .logo-name span { color: #4ade80; }

.od-nav-links { display: flex; align-items: center; gap: .15rem; list-style: none; margin: 0; padding: 0; }
.od-nav-links a {
  color: rgba(255,255,255,.75); font-size: .875rem; font-weight: 500;
  padding: .45rem .9rem; border-radius: 8px; transition: all .2s; text-decoration: none;
}
.od-nav-links a:hover { color: white; background: rgba(255,255,255,.08); }
.od-nav-links a.active { color: #4ade80; background: rgba(74,222,128,.08); }

.nav-cta-login {
  font-size: .85rem; font-weight: 600; padding: .42rem 1.1rem;
  border-radius: 8px; color: rgba(255,255,255,.85);
  border: 1.5px solid rgba(255,255,255,.2);
  background: transparent; transition: all .2s; white-space: nowrap; text-decoration: none;
}
.nav-cta-login:hover { border-color: rgba(255,255,255,.5); color: white; }
.nav-cta-start {
  font-size: .85rem; font-weight: 700; padding: .44rem 1.2rem;
  border-radius: 8px; background: var(--od-green); color: white;
  transition: all .2s; white-space: nowrap; text-decoration: none;
}
.nav-cta-start:hover { background: #157a42; color: white; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(26,138,78,.4); }

/* Mobile menu */
.od-mobile-menu {
  background: rgba(7,25,44,.98);
  border-top: 1px solid rgba(255,255,255,.08);
  padding: .75rem 0 1rem;
}
.od-mobile-menu a {
  display: block; color: rgba(255,255,255,.75); padding: .65rem .75rem;
  border-radius: 8px; font-weight: 500; font-size: .9rem; text-decoration: none; margin-bottom: .2rem;
}
.od-mobile-menu a:hover { background: rgba(255,255,255,.08); color: white; }
.od-mobile-menu .mob-divider { border-color: rgba(255,255,255,.1); margin: .5rem 0; }

/* ── Footer ───────────────────────────────────────────────── */
.od-footer { background: #050f1f; color: rgba(255,255,255,.55); }
.od-footer .foot-logo-name { font-size: 1.15rem; font-weight: 900; color: white; }
.od-footer .foot-logo-name span { color: #4ade93; }
.od-footer .foot-desc { font-size: .85rem; line-height: 1.7; color: rgba(255,255,255,.45); max-width: 280px; }
.od-footer h6 { font-size: .78rem; font-weight: 800; color: rgba(255,255,255,.9); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 1.1rem; }
.od-footer .foot-link { display: block; color: rgba(255,255,255,.45); font-size: .85rem; margin-bottom: .5rem; text-decoration: none; transition: color .2s; }
.od-footer .foot-link:hover { color: #4ade93; }
.od-footer .social-links { display: flex; gap: .5rem; margin-top: 1.25rem; }
.od-footer .soc-btn {
  width: 34px; height: 34px; border-radius: 8px;
  background: rgba(255,255,255,.06); color: rgba(255,255,255,.55);
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; transition: all .2s; border: 1px solid rgba(255,255,255,.08); text-decoration: none;
}
.od-footer .soc-btn:hover { background: var(--od-green); color: white; border-color: var(--od-green); }
.od-footer .foot-bottom { border-top: 1px solid rgba(255,255,255,.07); padding: 1.5rem 0; }
.od-footer .foot-bottom p { font-size: .8rem; color: rgba(255,255,255,.35); margin: 0; }
.od-footer .foot-badges { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
.od-footer .foot-badge {
  display: flex; align-items: center; gap: .35rem; font-size: .72rem;
  color: rgba(255,255,255,.35); background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07); border-radius: 6px; padding: .3rem .65rem;
}
.od-footer .foot-badge i { color: #4ade93; font-size: .65rem; }

/* ── Shared section helpers ───────────────────────────────── */
.section-eyebrow {
  display: inline-block; font-size: .72rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: 1.2px;
  color: var(--od-green); background: rgba(26,138,78,.1);
  padding: .3rem .85rem; border-radius: 100px; margin-bottom: .85rem;
}
.section-title {
  font-size: clamp(1.8rem, 4vw, 2.75rem); font-weight: 900;
  color: var(--od-navy); letter-spacing: -.5px; line-height: 1.15; margin-bottom: .6rem;
}
.section-sub { font-size: 1rem; color: #64748b; max-width: 560px; margin: 0 auto; line-height: 1.65; }
</style>
<?= $_extraHeadHtml ?>
</head>
<body class="pub-body <?= htmlspecialchars($_bodyClass, ENT_QUOTES) ?>">
<div id="od-scroll-progress"></div>

<!-- ══════════════════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════════════════ -->
<nav class="od-nav" id="odNav">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">

      <!-- Logo -->
      <a href="<?= htmlspecialchars($_activeNav === 'home' ? '#hero' : ($_appUrl . '/'), ENT_QUOTES) ?>" class="nav-logo">
        <div class="logo-mark">OD</div>
        <div class="logo-name">Orbit<span>Desk</span></div>
      </a>

      <!-- Desktop links -->
      <ul class="od-nav-links d-none d-lg-flex">
        <li><a href="<?= htmlspecialchars($_appUrl . '/', ENT_QUOTES) ?>"
               class="<?= $_activeNav === 'home' ? 'active' : '' ?>">Home</a></li>
        <li><a href="<?= htmlspecialchars($_appUrl . '/index.php#features', ENT_QUOTES) ?>">Features</a></li>
        <li><a href="<?= htmlspecialchars($_appUrl . '/index.php#modules', ENT_QUOTES) ?>">Modules</a></li>
        <li><a href="<?= htmlspecialchars($_appUrl . '/pricing.php', ENT_QUOTES) ?>"
               class="<?= $_activeNav === 'pricing' ? 'active' : '' ?>">Pricing</a></li>
        <li><a href="<?= htmlspecialchars($_appUrl . '/contact.php', ENT_QUOTES) ?>"
               class="<?= $_activeNav === 'contact' ? 'active' : '' ?>">Contact</a></li>
      </ul>

      <!-- Desktop CTAs -->
      <div class="d-none d-lg-flex align-items-center gap-2">
        <a href="<?= htmlspecialchars($_appUrl . '/auth/login.php', ENT_QUOTES) ?>" class="nav-cta-login">Login</a>
        <a href="<?= htmlspecialchars($_appUrl . '/auth/register.php', ENT_QUOTES) ?>" class="nav-cta-start">
          <i class="fas fa-rocket me-1" style="font-size:.75rem"></i>Start Free Trial
        </a>
      </div>

      <!-- Mobile hamburger -->
      <button class="d-lg-none btn p-0 border-0" style="background:rgba(255,255,255,.1);border-radius:8px;padding:.4rem .65rem!important;color:white"
              data-bs-toggle="collapse" data-bs-target="#odMobileNav" aria-expanded="false" aria-label="Toggle navigation">
        <i class="fas fa-bars"></i>
      </button>
    </div>

    <!-- Mobile menu -->
    <div class="collapse" id="odMobileNav">
      <div class="od-mobile-menu">
        <a href="<?= htmlspecialchars($_appUrl . '/', ENT_QUOTES) ?>"><i class="fas fa-home me-2"></i>Home</a>
        <a href="<?= htmlspecialchars($_appUrl . '/index.php#features', ENT_QUOTES) ?>"><i class="fas fa-bolt me-2"></i>Features</a>
        <a href="<?= htmlspecialchars($_appUrl . '/index.php#modules', ENT_QUOTES) ?>"><i class="fas fa-th me-2"></i>Modules</a>
        <a href="<?= htmlspecialchars($_appUrl . '/pricing.php', ENT_QUOTES) ?>"><i class="fas fa-tags me-2"></i>Pricing</a>
        <a href="<?= htmlspecialchars($_appUrl . '/contact.php', ENT_QUOTES) ?>"><i class="fas fa-envelope me-2"></i>Contact</a>
        <hr class="mob-divider">
        <a href="<?= htmlspecialchars($_appUrl . '/auth/login.php', ENT_QUOTES) ?>"><i class="fas fa-sign-in-alt me-2"></i>Login</a>
        <a href="<?= htmlspecialchars($_appUrl . '/auth/register.php', ENT_QUOTES) ?>"
           style="background:var(--od-green);color:white;text-align:center;font-weight:700">
          <i class="fas fa-rocket me-2"></i>Start Free Trial
        </a>
      </div>
    </div>
  </div>
</nav>
