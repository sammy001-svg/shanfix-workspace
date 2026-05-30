<?php
/**
 * OrbitDesk — Dynamic OG Image Generator
 * Uses the real office photo as background with branded text overlay.
 * Outputs a 1200×630 PNG ready for og:image, Twitter Card, and WhatsApp previews.
 *
 * Query params (all optional, URL-encoded):
 *   t  = Page / module title (max 50 chars shown)
 *   s  = Subtitle / org name (max 60 chars shown)
 *   c  = Accent hex color (default: 1A8A4E — used for the title highlight bar)
 *
 * Cached to assets/cache/og/ by param hash for 7 days.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');

// ── Parameters ────────────────────────────────────────────────────
$title    = mb_substr(strip_tags($_GET['t'] ?? APP_NAME),    0, 50);
$subtitle = mb_substr(strip_tags($_GET['s'] ?? APP_TAGLINE), 0, 60);
$accentHex= preg_replace('/[^0-9a-fA-F]/', '', ltrim($_GET['c'] ?? '1A8A4E', '#'));
$accentHex= strlen($accentHex) === 6 ? $accentHex : '1A8A4E';

// ── Cache ─────────────────────────────────────────────────────────
$cacheDir = __DIR__ . '/../assets/cache/og';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheDir . '/.htaccess', "Options -Indexes\nAddType image/png .png\n");
}

$cacheKey  = md5($title . '|' . $subtitle . '|' . $accentHex);
$cachePath = $cacheDir . '/' . $cacheKey . '.png';

if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 604800) {
    readfile($cachePath);
    exit;
}

// ── Word-wrap helper ──────────────────────────────────────────────
function wrapText(string $text, int $maxChars): array {
    $words = explode(' ', $text);
    $lines = [];
    $cur   = '';
    foreach ($words as $word) {
        $test = $cur === '' ? $word : $cur . ' ' . $word;
        if (strlen($test) > $maxChars && $cur !== '') {
            $lines[] = $cur;
            $cur     = $word;
        } else {
            $cur = $test;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

// ── Load background photo ─────────────────────────────────────────
$bgPath = __DIR__ . '/../assets/images/og-banner-1200.png';
$bg     = file_exists($bgPath) ? @imagecreatefrompng($bgPath) : null;

$W = 1200;
$H = 630;
$img = imagecreatetruecolor($W, $H);
imagealphablending($img, true);

if ($bg) {
    // Scale/crop background to fit canvas
    $bw = imagesx($bg);
    $bh = imagesy($bg);
    imagecopyresampled($img, $bg, 0, 0, 0, 0, $W, $H, $bw, $bh);
    unset($bg);
} else {
    // Fallback: solid navy if photo missing
    imagefill($img, 0, 0, imagecolorallocate($img, 11, 45, 78));
}

// ── Dark gradient overlay (bottom two-thirds, for text readability) ─
// Draws rows with increasing alpha from top to bottom of overlay zone
$overlayTop = (int)($H * 0.28); // overlay starts at 28% from top
for ($y = $overlayTop; $y < $H; $y++) {
    $progress = ($y - $overlayTop) / ($H - $overlayTop); // 0 → 1
    $alpha    = (int)(0 + $progress * 115);               // 0 → 115 (out of 127)
    $c = imagecolorallocatealpha($img, 5, 15, 30, 127 - $alpha);
    imagefilledrectangle($img, 0, $y, $W, $y, $c);
}

// Additional solid band at very bottom for footer strip
imagefilledrectangle($img, 0, $H - 68, $W, $H,
    imagecolorallocatealpha($img, 5, 15, 30, 20));

// ── Accent color helpers ──────────────────────────────────────────
$ar = hexdec(substr($accentHex, 0, 2));
$ag = hexdec(substr($accentHex, 2, 2));
$ab = hexdec(substr($accentHex, 4, 2));

$cAccent   = imagecolorallocate($img, $ar, $ag, $ab);
$cWhite    = imagecolorallocate($img, 255, 255, 255);
$cOffWhite = imagecolorallocate($img, 210, 230, 220);
$cMuted    = imagecolorallocate($img, 160, 190, 175);

// ── Left accent bar ───────────────────────────────────────────────
imagefilledrectangle($img, 0, 0, 7, $H, $cAccent);

// ── Top-left logo area: app name ─────────────────────────────────
$f5w = imagefontwidth(5);
$f5h = imagefontheight(5);

// Semi-transparent pill behind app name
$pillW = strlen(APP_NAME) * $f5w + 28;
$bgPill = imagecolorallocatealpha($img, 5, 15, 30, 70);
imagefilledrectangle($img, 40, 36, 40 + $pillW, 36 + $f5h + 10, $bgPill);
imagefilledrectangle($img, 40, 36, 44, 36 + $f5h + 10, $cAccent); // left pip

imagestring($img, 5, 50, 42, APP_NAME, $cWhite);
imagestring($img, 5, 51, 42, APP_NAME, $cWhite); // pseudo-bold

// ── Title text (large, white) ────────────────────────────────────
$titleLines = wrapText($title, 30);
$titleY     = 290;
foreach ($titleLines as $line) {
    // Shadow
    imagestring($img, 5, 42, $titleY + 2, $line, imagecolorallocate($img, 0, 0, 0));
    // Bold simulation (draw twice)
    imagestring($img, 5, 41, $titleY, $line, $cWhite);
    imagestring($img, 5, 42, $titleY, $line, $cWhite);
    $titleY += $f5h + 16;
}

// Accent underline under title
$lineEnd = 41 + max(array_map('strlen', $titleLines)) * $f5w + 20;
imagefilledrectangle($img, 41, $titleY - 4, min($lineEnd, 600), $titleY - 2, $cAccent);

// ── Subtitle text ─────────────────────────────────────────────────
$subLines = wrapText($subtitle, 48);
$subY = $titleY + 12;
foreach (array_slice($subLines, 0, 2) as $line) {
    imagestring($img, 3, 41, $subY, $line, $cOffWhite);
    $subY += imagefontheight(3) + 8;
}

// ── Bottom strip: URL ─────────────────────────────────────────────
$urlText = preg_replace('#^https?://#', '', rtrim(APP_URL, '/'));
$f3w     = imagefontwidth(3);
$stripY  = $H - 68 + 22;

imagestring($img, 3, 50, $stripY, APP_TAGLINE, $cMuted);
imagestring($img, 3, $W - strlen($urlText) * $f3w - 50, $stripY + 1, $urlText, $cOffWhite);
imagestring($img, 3, $W - strlen($urlText) * $f3w - 50, $stripY,     $urlText, $cOffWhite);

// ── Output & cache ────────────────────────────────────────────────
ob_start();
imagepng($img, null, 8);
$pngData = ob_get_clean();
unset($img);

@file_put_contents($cachePath, $pngData);
echo $pngData;
