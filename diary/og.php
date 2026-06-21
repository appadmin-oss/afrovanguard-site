<?php
/**
 * diary/og.php — branded Open Graph image generator (1200×630 PNG).
 *
 *   /diary/og/<slug>.png   (pretty, via .htaccess)  or  ?slug=<slug>
 *
 * Renders a per-article social card from the database: brand gradient,
 * eyebrow, wrapped title, and category · read-time. Cached to db/cache/.
 * Falls back to the site OG image if GD/fonts are unavailable.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/Images/og-image.png';

$font   = AV_ROOT . '/assets/fonts/display.ttf';
$fontUI = AV_ROOT . '/assets/fonts/body.ttf';
if (!$slug || !extension_loaded('gd') || !function_exists('imagettftext') || !is_file($font)) {
    header('Location: ' . $fallback, true, 302); exit;
}

$a = (new DiaryRepository())->bySlug($slug);
if (!$a) { header('Location: ' . $fallback, true, 302); exit; }

// ---- cache ----
$cacheDir = AV_ROOT . '/db/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/og-' . $slug . '.png';
$cacheKey  = md5($a['title'] . $a['category'] . $a['gradient'] . $a['read_minutes']);
$cacheStamp = $cacheFile . '.key';
$valid = is_file($cacheFile) && is_file($cacheStamp) && trim((string)@file_get_contents($cacheStamp)) === $cacheKey;

if (!$valid) {
    $W = 1200; $H = 630;
    $im = imagecreatetruecolor($W, $H);

    // Brand gradient per article (matches the CSS .g-* classes)
    $grads = [
        'g-gold'   => [[243,180,22],  [123,90,4]],
        'g-ink'    => [[31,41,55],    [10,15,26]],
        'g-sunset' => [[249,115,22],  [146,64,14]],
        'g-sky'    => [[37,99,235],   [14,116,144]],
        'g-green'  => [[22,163,74],   [6,78,59]],
    ];
    [$c1, $c2] = $grads[$a['gradient']] ?? $grads['g-gold'];
    for ($y = 0; $y < $H; $y++) {
        $t = $y / $H;
        $r = (int) round($c1[0] + ($c2[0] - $c1[0]) * $t);
        $g = (int) round($c1[1] + ($c2[1] - $c1[1]) * $t);
        $b = (int) round($c1[2] + ($c2[2] - $c1[2]) * $t);
        imagefilledrectangle($im, 0, $y, $W, $y + 1, imagecolorallocate($im, $r, $g, $b));
    }
    // Subtle grid texture
    $grid = imagecolorallocatealpha($im, 255, 255, 255, 110);
    for ($x = 0; $x < $W; $x += 48) imageline($im, $x, 0, $x, $H, $grid);
    for ($y = 0; $y < $H; $y += 48) imageline($im, 0, $y, $W, $y, $grid);

    $white = imagecolorallocate($im, 255, 255, 255);
    $soft  = imagecolorallocatealpha($im, 255, 255, 255, 40);
    $M = 80;

    // Eyebrow
    imagettftext($im, 20, 0, $M, 96, $soft, $fontUI, 'THE AFROVANGUARD DIARY');

    // Title — word-wrap to width
    $title = $a['title'];
    $size = 64; $maxW = $W - 2 * $M; $words = explode(' ', $title); $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        $bb = imagettfbbox($size, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; }
        else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    $lines = array_slice($lines, 0, 5);
    $lineH = (int) round($size * 1.32);
    $blockH = count($lines) * $lineH;
    $y = (int) (($H - $blockH) / 2) + $size + 10;
    foreach ($lines as $ln) { imagettftext($im, $size, 0, $M, $y, $white, $font, $ln); $y += $lineH; }

    // Footer meta
    $meta = strtoupper($a['category']) . '   ·   ' . (int) $a['read_minutes'] . ' MIN READ';
    imagettftext($im, 22, 0, $M, $H - 64, $white, $fontUI, $meta);
    // Logo bottom-right
    $logo = 'AFROVANGUARD'; $lb = imagettfbbox(26, 0, $fontUI, $logo);
    imagettftext($im, 26, 0, $W - $M - ($lb[2] - $lb[0]), $H - 60, $white, $fontUI, $logo);

    imagepng($im, $cacheFile);
    @file_put_contents($cacheStamp, $cacheKey);
    imagedestroy($im);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);
