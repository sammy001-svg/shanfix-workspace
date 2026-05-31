<?php
/**
 * PWA Icon Generator — produces a 192×192 or 512×512 PNG icon on-the-fly.
 * Usage: /api/pwa-icon.php?size=192  or  ?size=512
 * Caches for 24 h via HTTP headers.
 *
 * The icon uses the org's primary color (when logged in) or the platform green.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$size = (int)($_GET['size'] ?? 192);
$size = in_array($size, [192, 512]) ? $size : 192;

// Determine brand color
$color = '#1A8A4E';
if (isLoggedIn()) {
    $u = currentUser();
    $b = getOrgBranding((int)$u['org_id']);
    $color = $b['color'];
}
[$r, $g, $bl] = sscanf(ltrim($color, '#'), '%02x%02x%02x');

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

if (!function_exists('imagecreatetruecolor')) {
    // GD not available — redirect to favicon.svg
    header('Location: ' . APP_URL . '/assets/images/favicon.svg');
    exit;
}

$img  = imagecreatetruecolor($size, $size);
$bg   = imagecolorallocate($img, (int)$r, (int)$g, (int)$bl);
$fg   = imagecolorallocate($img, 255, 255, 255);
imagefill($img, 0, 0, $bg);

// Rounded-square mask via arc for corners
$radius = (int)($size * 0.22);
imagefilledarc($img, $radius, $radius, $radius * 2, $radius * 2, 180, 270, $bg, IMG_ARC_PIE);
imagefilledarc($img, $size - $radius, $radius, $radius * 2, $radius * 2, 270, 360, $bg, IMG_ARC_PIE);
imagefilledarc($img, $radius, $size - $radius, $radius * 2, $radius * 2, 90, 180, $bg, IMG_ARC_PIE);
imagefilledarc($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, 0, 90, $bg, IMG_ARC_PIE);

// Draw a simple "O" letter (first letter of OrbitDesk) centered
$font = 5; // GD built-in font
$ch   = 'O';
$fw   = imagefontwidth($font);
$fh   = imagefontheight($font);
$scale = (int)($size * 0.4 / $fh);
$scale = max(1, $scale);
// Draw thick concentric circles to represent a logo mark
$cx = $size / 2;
$cy = $size / 2;
$outer = (int)($size * 0.35);
$inner = (int)($size * 0.22);
imagesetthickness($img, max(2, (int)($size * 0.04)));
imagearc($img, (int)$cx, (int)$cy, $outer * 2, $outer * 2, 0, 360, $fg);
imagefilledellipse($img, (int)$cx, (int)$cy, $inner * 2, $inner * 2, $fg);
// Small dot off-center (orbit metaphor)
$dotR = (int)($size * 0.07);
$dotX = (int)($cx + $outer * 0.65);
$dotY = (int)$cy;
imagefilledellipse($img, $dotX, $dotY, $dotR * 2, $dotR * 2, $fg);

imagepng($img);
imagedestroy($img);
