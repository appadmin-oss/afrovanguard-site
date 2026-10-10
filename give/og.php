<?php
/**
 * give/og.php — the share card for one appeal (1200×630 PNG).
 *   /give/og/<slug>.png   or   ?slug=<slug>
 *
 * This image IS the appeal on WhatsApp, and on WhatsApp nobody reads past it.
 * So it carries the three things that make somebody tap: the title, the figure,
 * and the progress bar. A generic org card would work equally hard and say
 * nothing.
 *
 * Cached against a key built from everything drawn, so it is regenerated when
 * the appeal moves and served from disk the rest of the time — a share card is
 * requested by a crawler for every person the link reaches.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/assets/og/og-default.png';
$font   = AV_ROOT . '/assets/fonts/display.ttf';
$fontUI = AV_ROOT . '/assets/fonts/body.ttf';

$a = $slug !== '' ? Appeals::bySlug($slug) : null;
/* A draft has no share card. Redirecting to the site card rather than drawing
   one is deliberate: an unpublished appeal must not leak its title through an
   image either. */
if (!Appeals::isPublic($a) || !extension_loaded('gd') || !function_exists('imagettftext') || !is_file($font)) {
    header('Location: ' . $fallback, true, 302);
    exit;
}

$st = Appeals::state($a);
$W = 1200; $H = 630; $M = 84;

$cacheDir = AV_ROOT . '/db/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/giveog-' . $slug . '.png';
$stamp     = $cacheFile . '.key';
$key = md5(implode('|', [
    (string) $a['title'], (string) $a['tagline'], (string) $a['cover_url'],
    (string) $st['raised'], (string) $st['goal'], (string) $st['donors'],
    (string) ($st['percent'] ?? 'x'), (string) $a['status'],
]));
$valid = is_file($cacheFile) && is_file($stamp) && trim((string) @file_get_contents($stamp)) === $key;

if (!$valid) {
    $im    = imagecreatetruecolor($W, $H);
    $ink   = imagecolorallocate($im, 11, 11, 12);
    $white = imagecolorallocate($im, 255, 255, 255);
    $gold  = imagecolorallocate($im, 243, 180, 22);
    $muted = imagecolorallocate($im, 150, 150, 156);
    $trough = imagecolorallocate($im, 44, 44, 50);
    $green = imagecolorallocate($im, 52, 211, 153);

    imagefilledrectangle($im, 0, 0, $W, $H, $ink);
    imagefilledrectangle($im, 0, 0, $W, 10, $gold);

    $textMax = $W - 2 * $M;
    $bg = !empty($a['cover_url']) ? og_give_img((string) $a['cover_url']) : null;
    if ($bg) {
        $px = (int) ($W * 0.60); $pw = $W - $px;
        $sw = imagesx($bg); $sh = imagesy($bg);
        $sc = max($pw / $sw, $H / $sh);
        $nw = (int) ($sw * $sc); $nh = (int) ($sh * $sc);
        imagecopyresampled($im, $bg, $px + (int) (($pw - $nw) / 2), (int) (($H - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($bg);
        // Feather the photo into the panel so the type never sits on busy pixels.
        for ($x = 0; $x < 140; $x++) {
            $alpha = (int) (127 - 120 * ($x / 140));
            imagefilledrectangle($im, $px - 140 + $x, 0, $px - 140 + $x + 1, $H, imagecolorallocatealpha($im, 11, 11, 12, max(0, $alpha)));
        }
        imagefilledrectangle($im, $px, 0, $px + 5, $H, $gold);
        $textMax = $px - $M - 30;
    }

    imagettftext($im, 19, 0, $M, 92, $gold, $fontUI, strtoupper((string) ($a['kind'] === 'emergency' ? 'Emergency appeal' : 'Afrovanguard appeal')));

    // Title, wrapped to at most three lines — the fourth is never read.
    $size = 56; $lines = []; $cur = '';
    foreach (explode(' ', (string) $a['title']) as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        $bb = imagettfbbox($size, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $textMax && $cur !== '') { $lines[] = $cur; $cur = $w; } else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    $lines = array_slice($lines, 0, 3);
    $y = 190 + $size;
    foreach ($lines as $ln) { imagettftext($im, $size, 0, $M, $y, $white, $font, $ln); $y += (int) round($size * 1.24); }

    /* The figure and the bar. This is the part that earns the tap. */
    $barY = $H - 150;
    $barW = $textMax;
    if ($st['percent'] !== null) {
        imagefilledrectangle($im, $M, $barY, $M + $barW, $barY + 14, $trough);
        $fill = (int) round($barW * $st['percent'] / 100);
        if ($fill > 0) imagefilledrectangle($im, $M, $barY, $M + max(4, $fill), $barY + 14, $st['met'] ? $green : $gold);
        $headline = Appeals::naira($st['raised']) . '  raised of  ' . Appeals::naira($st['goal']);
        $sub = $st['percent'] . '%   ·   ' . $st['donors'] . ' donor' . ($st['donors'] === 1 ? '' : 's');
        if ($st['days_left'] !== null && $st['days_left'] >= 0) $sub .= '   ·   ' . $st['days_left'] . ' days left';
    } else {
        $headline = Appeals::naira($st['raised']) . '  raised so far';
        $sub = $st['donors'] . ' donor' . ($st['donors'] === 1 ? '' : 's');
    }
    imagettftext($im, 30, 0, $M, $barY - 30, $white, $fontUI, $headline);
    imagettftext($im, 19, 0, $M, $barY + 56, $muted, $fontUI, $sub);

    $foot = 'afrovanguard.org.ng/give';
    $fb = imagettfbbox(18, 0, $fontUI, $foot);
    imagettftext($im, 18, 0, $W - $M - ($fb[2] - $fb[0]), $H - 40, $muted, $fontUI, $foot);

    imagepng($im, $cacheFile);
    @file_put_contents($stamp, $key);
    imagedestroy($im);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . (string) filesize($cacheFile));
readfile($cacheFile);

/** Decode a cover into a GD image, or null. Never throws — a card without the
 *  photograph is fine, a 500 where a crawler expected an image is not. */
function og_give_img(string $src)
{
    try {
        if ($src !== '' && $src[0] === '/') {
            $p = AV_ROOT . $src;
            return is_file($p) ? @imagecreatefromstring((string) @file_get_contents($p)) : null;
        }
        if (preg_match('~^https?://~', $src)) {
            $d = @file_get_contents($src, false, stream_context_create(['http' => ['timeout' => 6]]));
            return $d ? @imagecreatefromstring($d) : null;
        }
    } catch (Throwable $e) { }
    return null;
}
