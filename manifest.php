<?php
/**
 * Progressive Web App manifest — served as application/manifest+json.
 * Reads org branding when the user is logged in so the installed icon/name
 * matches their workspace.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$appName  = APP_NAME;
$iconBase = APP_URL . '/assets/images';

// Use org branding if logged in
if (isLoggedIn()) {
    $u = currentUser();
    if (!empty($u['org_name'])) $appName = $u['org_name'] . ' — ' . APP_NAME;
    $branding = getOrgBranding((int)$u['org_id']);
    $themeColor = $branding['color'];
} else {
    $themeColor = '#1A8A4E';
}

echo json_encode([
    'name'             => $appName,
    'short_name'       => APP_NAME,
    'description'      => 'All-in-one business management platform for African businesses.',
    'start_url'        => APP_URL . '/client/index.php',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#0B2D4E',
    'theme_color'      => $themeColor,
    'lang'             => 'en',
    'scope'            => '/',
    'categories'       => ['business', 'productivity', 'finance'],
    'icons'            => [
        ['src' => APP_URL . '/api/pwa-icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => APP_URL . '/api/pwa-icon.php?size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $iconBase . '/favicon.svg',             'sizes' => 'any',     'type' => 'image/svg+xml'],
    ],
    'shortcuts' => [
        ['name' => 'Dashboard',    'url' => APP_URL . '/client/index.php',    'icons' => [['src' => $iconBase . '/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Analytics',    'url' => APP_URL . '/client/analytics.php','icons' => [['src' => $iconBase . '/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Billing',      'url' => APP_URL . '/client/billing.php',  'icons' => [['src' => $iconBase . '/icon-192.png', 'sizes' => '192x192']]],
    ],
    'screenshots' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
