<?php
/**
 * Shared public-facing footer + Bootstrap JS + scroll/nav JS.
 *
 * Variables expected in scope (set by including page or use defaults):
 *   $appName    — string
 *   $appUrl     — string
 *   $appTagline — string
 *   $siteEmail  — string
 *   $extraBodyJs — (string) raw HTML injected before </body> (page-specific scripts)
 */
$_fn   = isset($appName)    ? $appName    : (defined('APP_NAME')    ? APP_NAME    : 'OrbitDesk');
$_fu   = isset($appUrl)     ? $appUrl     : (defined('APP_URL')     ? APP_URL     : '');
$_ftag = isset($appTagline) ? $appTagline : (defined('APP_TAGLINE') ? APP_TAGLINE : 'All-in-One Business Management Platform');
$_fem  = isset($siteEmail)  ? $siteEmail  : 'info@orbitdesk.co.ke';
$_extraJs = isset($extraBodyJs) ? $extraBodyJs : '';
?>

<!-- ══════════════════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════════════════ -->
<footer class="od-footer">
  <div class="container" style="padding-top:4rem;padding-bottom:2rem">
    <div class="row g-4 mb-5">

      <!-- Brand -->
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <div style="width:36px;height:36px;background:linear-gradient(135deg,#1A8A4E,#22c27a);border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:900;color:white;font-size:.8rem">OD</div>
          <div class="foot-logo-name">Orbit<span>Desk</span></div>
        </div>
        <p class="foot-desc"><?= htmlspecialchars($_ftag, ENT_QUOTES) ?>. Built for African businesses, trusted across Kenya and East Africa.</p>
        <div class="social-links">
          <a href="#" class="soc-btn" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="soc-btn" aria-label="Twitter / X"><i class="fab fa-twitter"></i></a>
          <a href="#" class="soc-btn" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
          <a href="#" class="soc-btn" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
          <a href="#" class="soc-btn" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
        </div>
      </div>

      <!-- Platform -->
      <div class="col-6 col-lg-2">
        <h6>Platform</h6>
        <a href="<?= htmlspecialchars($_fu . '/index.php#features', ENT_QUOTES) ?>" class="foot-link">Features</a>
        <a href="<?= htmlspecialchars($_fu . '/pricing.php', ENT_QUOTES) ?>" class="foot-link">Pricing</a>
        <a href="<?= htmlspecialchars($_fu . '/auth/register.php', ENT_QUOTES) ?>" class="foot-link">Free Trial</a>
        <a href="<?= htmlspecialchars($_fu . '/index.php#modules', ENT_QUOTES) ?>" class="foot-link">Modules</a>
        <a href="#" class="foot-link">API Docs</a>
      </div>

      <!-- Company -->
      <div class="col-6 col-lg-2">
        <h6>Company</h6>
        <a href="<?= htmlspecialchars($_fu . '/index.php#about', ENT_QUOTES) ?>" class="foot-link">About Us</a>
        <a href="#" class="foot-link">Blog</a>
        <a href="#" class="foot-link">Careers</a>
        <a href="<?= htmlspecialchars($_fu . '/contact.php', ENT_QUOTES) ?>" class="foot-link">Contact</a>
        <a href="#" class="foot-link">Partners</a>
      </div>

      <!-- Support -->
      <div class="col-6 col-lg-2">
        <h6>Support</h6>
        <a href="#" class="foot-link">Help Centre</a>
        <a href="#" class="foot-link">Documentation</a>
        <a href="#" class="foot-link">System Status</a>
        <a href="<?= htmlspecialchars($_fu . '/track.php', ENT_QUOTES) ?>" class="foot-link">Parcel Tracking</a>
        <a href="<?= htmlspecialchars($_fu . '/mall-tenant-portal.php', ENT_QUOTES) ?>" class="foot-link">Tenant Portal</a>
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
      <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($_fn, ENT_QUOTES) ?>. All rights reserved.
        Made with <i class="fas fa-heart" style="color:#ef4444;font-size:.75rem"></i> in Kenya.</p>
      <div class="foot-badges">
        <span class="foot-badge"><i class="fas fa-shield-halved"></i> SSL Secured</span>
        <span class="foot-badge"><i class="fas fa-lock"></i> Data Encrypted</span>
        <span class="foot-badge"><i class="fas fa-server"></i> 99.9% Uptime</span>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  'use strict';

  // ── Scroll progress bar ───────────────────────────────────
  var sp = document.getElementById('od-scroll-progress');
  if (sp) {
    window.addEventListener('scroll', function () {
      var s = document.documentElement;
      sp.style.width = (s.scrollTop / (s.scrollHeight - s.clientHeight) * 100) + '%';
    }, { passive: true });
  }

  // ── Navbar: transparent → dark on scroll ─────────────────
  var nav = document.getElementById('odNav');
  if (nav) {
    window.addEventListener('scroll', function () {
      nav.classList.toggle('scrolled', window.scrollY > 50);
    }, { passive: true });
  }
})();
</script>
<?= $_extraJs ?>
</body>
</html>
