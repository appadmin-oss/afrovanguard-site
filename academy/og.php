<?php
/**
 * academy/og.php — branded Open Graph image for a course (1200×630 PNG).
 *   /academy/og/<slug>.png  or  ?slug=<slug>
 * White / black / gold treatment; cover shown as a positioned right panel.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/Images/og-image.png';
$font  = AV_ROOT . '/assets/fonts/display.ttf';
$fontUI = AV_ROOT . '/assets/fonts/body.ttf';
if (!$slug || !extension_loaded('gd') || !function_exists('imagettftext') || !is_file($font)) { header('Location: ' . $fallback, true, 302); exit; }

$c = (new AcademyRepository())->bySlug($slug);
if (!$c) { header('Location: ' . $fallback, true, 302); exit; }

$W = 1200; $H = 630; $M = 84; $cover = $c['cover_url'] ?? '';
$cacheDir = AV_ROOT . '/db/cache'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/acog-' . $slug . '.png'; $stamp = $cacheFile . '.key';
$key = md5($c['title'] . $c['category'] . $c['level'] . $c['gradient'] . $cover . ($c['price'] ?? ''));
$valid = is_file($cacheFile) && is_file($stamp) && trim((string) @file_get_contents($stamp)) === $key;

if (!$valid) {
    $im = imagecreatetruecolor($W, $H);
    $ink = imagecolorallocate($im, 11, 11, 12);
    $white = imagecolorallocate($im, 255, 255, 255);
    $gold = imagecolorallocate($im, 232, 163, 23);
    $muted = imagecolorallocate($im, 150, 150, 156);
    imagefilledrectangle($im, 0, 0, $W, $H, $ink);                 // black canvas
    imagefilledrectangle($im, 0, 0, $W, 10, $gold);                // gold top bar

    $textMax = $W - 2 * $M;
    $bg = $cover ? og2_img($cover) : null;
    if ($bg) {
        $px = (int) ($W * 0.58); $pw = $W - $px;
        $sw = imagesx($bg); $sh = imagesy($bg); $sc = max($pw / $sw, $H / $sh);
        $nw = (int) ($sw * $sc); $nh = (int) ($sh * $sc);
        imagecopyresampled($im, $bg, $px + (int) (($pw - $nw) / 2), (int) (($H - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($bg);
        for ($x = 0; $x < 130; $x++) { $a = (int) (127 - 120 * ($x / 130)); imagefilledrectangle($im, $px - 130 + $x, 0, $px - 130 + $x + 1, $H, imagecolorallocatealpha($im, 11, 11, 12, max(0, $a))); }
        imagefilledrectangle($im, $px, 0, $px + 5, $H, $gold);
        $textMax = $px - $M - 24;
    }

    imagettftext($im, 19, 0, $M, 96, $gold, $fontUI, 'AFROVANGUARD ACADEMY');
    // wrapped title
    $size = 60; $words = explode(' ', $c['title']); $lines = []; $cur = '';
    foreach ($words as $w) { $try = $cur === '' ? $w : "$cur $w"; $bb = imagettfbbox($size, 0, $font, $try); if (($bb[2] - $bb[0]) > $textMax && $cur !== '') { $lines[] = $cur; $cur = $w; } else { $cur = $try; } }
    if ($cur !== '') $lines[] = $cur; $lines = array_slice($lines, 0, 4);
    $lh = (int) round($size * 1.26); $y = 210 + $size;
    foreach ($lines as $ln) { imagettftext($im, $size, 0, $M, $y, $white, $font, $ln); $y += $lh; }

    $meta = strtoupper($c['category']) . '   ·   ' . strtoupper($c['level']);
    imagettftext($im, 20, 0, $M, $H - 96, $muted, $fontUI, $meta);
    // price pill
    $price = strtoupper((string) ($c['price'] ?? 'Free'));
    $pb = imagettfbbox(20, 0, $fontUI, $price);
    imagefilledrectangle($im, $M, $H - 78, $M + ($pb[2] - $pb[0]) + 36, $H - 36, $gold);
    imagettftext($im, 20, 0, $M + 18, $H - 48, $ink, $fontUI, $price);
    $logo = 'afrovanguard.org.ng/academy'; $lb = imagettfbbox(18, 0, $fontUI, $logo);
    imagettftext($im, 18, 0, $W - $M - ($lb[2] - $lb[0]), $H - 52, $muted, $fontUI, $logo);

    imagepng($im, $cacheFile); @file_put_contents($stamp, $key); imagedestroy($im);
}
header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400'); header('Content-Length: ' . filesize($cacheFile)); readfile($cacheFile);

function og2_img(string $src) {
    try {
        if ($src[0] === '/') { $p = AV_ROOT . $src; return is_file($p) ? @imagecreatefromstring((string) file_get_contents($p)) : null; }
        if (preg_match('~^https?://~', $src)) { $d = @file_get_contents($src, false, stream_context_create(['http' => ['timeout' => 6]])); return $d ? @imagecreatefromstring($d) : null; }
    } catch (Throwable $e) {}
    return null;
}
