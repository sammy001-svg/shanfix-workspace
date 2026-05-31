<?php
/**
 * sitemap.php — served as /sitemap.xml via .htaccess rewrite.
 *
 * Covers:
 *  • Core public pages (homepage, register, login, forgot-password)
 *  • All 22 module landing pages (/module/{slug})
 *  • Homepage section anchors for rich internal linking signals
 *
 * Google Sitemap Protocol: https://www.sitemaps.org/protocol.html
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600'); // Cache 1 h

// ── Helpers ───────────────────────────────────────────────────────────────────
function sitemapDate(?string $ts = null): string {
    return $ts ? date('c', is_numeric($ts) ? (int)$ts : strtotime($ts)) : date('c');
}

function url(string $loc, float $priority, string $freq, string $lastmod = ''): string {
    $esc = htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES);
    $lm  = $lastmod ?: date('Y-m-d');
    return "  <url>\n    <loc>{$esc}</loc>\n    <lastmod>{$lm}</lastmod>\n    <changefreq>{$freq}</changefreq>\n    <priority>" . number_format($priority, 1) . "</priority>\n  </url>\n";
}

// ── Fetch modules from DB ─────────────────────────────────────────────────────
$modules = [];
try {
    $stmt = $pdo->query("SELECT slug, name, updated_at FROM modules WHERE status='active' ORDER BY sort_order");
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Use module.php file mtime as a sensible lastmod for module pages
$moduleLastMod = file_exists(__DIR__ . '/module.php')
    ? date('Y-m-d', filemtime(__DIR__ . '/module.php'))
    : date('Y-m-d');

$indexLastMod = file_exists(__DIR__ . '/index.php')
    ? date('Y-m-d', filemtime(__DIR__ . '/index.php'))
    : date('Y-m-d');

$base = rtrim(APP_URL, '/');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<?xml-stylesheet type=\"text/xsl\" href=\"{$base}/sitemap.xsl\"?>\n";
?>
<urlset
  xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
                      http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"
  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

<?php
// ══════════════════════════════════════════════════════════════════════════════
// 1. CORE PAGES
// ══════════════════════════════════════════════════════════════════════════════
echo "  <!-- ══ Core Pages ══ -->\n";

// Homepage — highest authority
echo "  <url>\n";
echo "    <loc>{$base}/</loc>\n";
echo "    <lastmod>{$indexLastMod}</lastmod>\n";
echo "    <changefreq>daily</changefreq>\n";
echo "    <priority>1.0</priority>\n";
echo "    <image:image>\n";
echo "      <image:loc>{$base}/assets/images/og-banner-1200.png</image:loc>\n";
echo "      <image:caption>" . htmlspecialchars(APP_NAME . ' — ' . APP_TAGLINE, ENT_XML1) . "</image:caption>\n";
echo "    </image:image>\n";
echo "  </url>\n";

// Free Trial / Register — highest conversion page
echo url("{$base}/auth/register.php",    0.9, 'monthly', $indexLastMod);

// Login page
echo url("{$base}/auth/login.php",       0.6, 'yearly',  $indexLastMod);

// Forgot password
echo url("{$base}/auth/forgot-password.php", 0.3, 'yearly', $indexLastMod);

// ══════════════════════════════════════════════════════════════════════════════
// 2. HOMEPAGE SECTION ANCHORS
//    These help Google understand the page sections and may generate sitelinks.
// ══════════════════════════════════════════════════════════════════════════════
echo "\n  <!-- ══ Homepage Sections ══ -->\n";
$sections = [
    'features' => ['priority' => 0.8, 'freq' => 'weekly',  'label' => 'Features'],
    'modules'  => ['priority' => 0.9, 'freq' => 'weekly',  'label' => 'Modules'],
    'pricing'  => ['priority' => 0.9, 'freq' => 'weekly',  'label' => 'Pricing Plans'],
    'about'    => ['priority' => 0.6, 'freq' => 'monthly', 'label' => 'About Us'],
    'contact'  => ['priority' => 0.6, 'freq' => 'monthly', 'label' => 'Contact'],
];
foreach ($sections as $anchor => $meta) {
    echo url("{$base}/#{$anchor}", $meta['priority'], $meta['freq'], $indexLastMod);
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. MODULE LANDING PAGES  (/module/{slug})
//    One dedicated SEO page per module — strong keyword targeting.
// ══════════════════════════════════════════════════════════════════════════════
echo "\n  <!-- ══ Module Landing Pages ══ -->\n";
foreach ($modules as $m) {
    $slug = htmlspecialchars($m['slug'], ENT_XML1);
    $lm   = !empty($m['updated_at']) ? date('Y-m-d', strtotime($m['updated_at'])) : $moduleLastMod;
    echo "  <url>\n";
    echo "    <loc>{$base}/module/{$slug}</loc>\n";
    echo "    <lastmod>{$lm}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}
?>

</urlset>
