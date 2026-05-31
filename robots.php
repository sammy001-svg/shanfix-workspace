<?php
/**
 * robots.php — served as /robots.txt via .htaccess rewrite.
 * Tells search engines what to crawl and where the sitemap is.
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=86400'); // Cache 24 h

require_once __DIR__ . '/config/database.php';
?>
User-agent: *

# ── Allow public-facing pages ─────────────────────────────────────
Allow: /$
Allow: /auth/register.php
Allow: /auth/login.php
Allow: /auth/forgot-password.php
Allow: /module/

# ── Block private / admin areas ───────────────────────────────────
Disallow: /admin/
Disallow: /client/
Disallow: /modules/
Disallow: /api/
Disallow: /cron/
Disallow: /database/
Disallow: /config/
Disallow: /includes/
Disallow: /vendor/
Disallow: /test_shift.php
Disallow: /courier_install.php
Disallow: /auth/reset-password.php
Disallow: /auth/2fa-setup.php
Disallow: /auth/2fa-verify.php
Disallow: /auth/org-login.php
Disallow: /assets/uploads/
Disallow: /*?export=
Disallow: /*?action=
Disallow: /*?tab=

# ── Crawl delay (be polite to the server) ─────────────────────────
Crawl-delay: 2

# ── Sitemap location ──────────────────────────────────────────────
Sitemap: <?= APP_URL ?>/sitemap.xml
