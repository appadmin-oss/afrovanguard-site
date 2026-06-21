<?php
/**
 * diary/og.php — branded Open Graph image generator (1200×630 PNG).
 *
 *   /diary/og/<slug>.png   (pretty, via .htaccess)  or  ?slug=<slug>
 *
 * Renders a per-article social card from the database: the article cover
 * (if one was uploaded) under a brand scrim, otherwise a brand gradient —
 * with an accent bar, eyebrow, wrapped title, category · read-time, author
 * and the wordmark. Cached to db/cache/. Falls back to the site OG image
 * if GD/fonts are unavailable.
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

$W = 1200; $H = 630; $M = 80;
$cover = $a['cover_url'] ?? '';

$cacheDir = AV_ROOT . '/db/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/og-' . $slug . '.png';
$cacheStamp = $cacheFile . '.key';
$cacheKey = md5($a['title'] . $a['category'] . $a['gradient'] . $a['read_minutes'] . $cover . strip_tags($a['authors_html']));
$valid = is_file($cacheFile) && is_file($cacheStamp) && trim((string) @file_get_contents($cacheStamp)) === $cacheKey;

if (!$valid) {
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);

    $grads = [
        'g-gold'   => [[243,180,22],[123,90,4]], 'g-ink' => [[31,41,55],[10,15,26]],
        'g-sunset' => [[249,115,22],[146,64,14]], 'g-sky' => [[37,99,235],[14,116,144]],
        'g-green'  => [[22,163,74],[6,78,59]],
    ];
    [$c1, $c2] = $grads[$a['gradient']] ?? $grads['g-gold'];

    // Background: cover image (cropped to fill) under a dark scrim, else gradient.
    $bg = $cover ? og_load_image($cover) : null;
    if ($bg) {
        $sw = imagesx($bg); $sh = imagesy($bg);
        $scale = max($W / $sw, $H / $sh);
        $nw = (int) ($sw * $scale); $nh = (int) ($sh * $scale);
        imagecopyresampled($im, $bg, (int) (($W - $nw) / 2), (int) (($H - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($bg);
        // dark vertical scrim for legibility
        for ($y = 0; $y < $H; $y++) {
            $al = (int) (38 + 62 * ($y / $H));               // 0.55 → 1.0-ish
            imagefilledrectangle($im, 0, $y, $W, $y + 1, imagecolorallocatealpha($im, 8, 12, 20, 127 - (int)($al * 0.9)));
        }
    } else {
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            $r = (int) round($c1[0] + ($c2[0] - $c1[0]) * $t);
            $g = (int) round($c1[1] + ($c2[1] - $c1[1]) * $t);
            $b = (int) round($c1[2] + ($c2[2] - $c1[2]) * $t);
            imagefilledrectangle($im, 0, $y, $W, $y + 1, imagecolorallocate($im, $r, $g, $b));
        }
        $grid = imagecolorallocatealpha($im, 255, 255, 255, 112);
        for ($x = 0; $x < $W; $x += 48) imageline($im, $x, 0, $x, $H, $grid);
        for ($y = 0; $y < $H; $y += 48) imageline($im, 0, $y, $W, $y, $grid);
    }

    $white = imagecolorallocate($im, 255, 255, 255);
    $soft  = imagecolorallocatealpha($im, 255, 255, 255, 45);
    $gold  = imagecolorallocate($im, 243, 180, 22);

    // Top accent bar
    imagefilledrectangle($im, 0, 0, $W, 10, $gold);

    // Eyebrow
    imagettftext($im, 19, 0, $M, 96, $soft, $fontUI, 'THE AFROVANGUARD DIARY');

    // Title — wrap to width
    $size = 62; $maxW = $W - 2 * $M; $words = explode(' ', $a['title']); $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        $bb = imagettfbbox($size, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; } else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    $lines = array_slice($lines, 0, 4);
    $lineH = (int) round($size * 1.28);
    $y = 200 + $size;
    foreach ($lines as $ln) {
        imagettftext($im, $size, 0, $M + 2, $y + 2, imagecolorallocatealpha($im, 0, 0, 0, 95), $font, $ln); // shadow
        imagettftext($im, $size, 0, $M, $y, $white, $font, $ln);
        $y += $lineH;
    }

    // Footer: category · read · author (left), wordmark (right)
    $meta = strtoupper($a['category']) . '   ·   ' . (int) $a['read_minutes'] . ' MIN READ';
    imagettftext($im, 21, 0, $M, $H - 96, $gold, $fontUI, $meta);
    $author = trim(strip_tags($a['authors_html']));
    if ($author !== '') imagettftext($im, 19, 0, $M, $H - 58, $white, $fontUI, 'By ' . $author);

    $logo = 'AFROVANGUARD'; $lb = imagettfbbox(24, 0, $fontUI, $logo);
    imagettftext($im, 24, 0, $W - $M - ($lb[2] - $lb[0]), $H - 58, $white, $fontUI, $logo);

    imagepng($im, $cacheFile);
    @file_put_contents($cacheStamp, $cacheKey);
    imagedestroy($im);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);

/** Load a cover image from a URL or a site-relative /uploads path. */
function og_load_image(string $src)
{
    try {
        if ($src[0] === '/') {
            $path = AV_ROOT . $src;
            return is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : null;
        }
        if (preg_match('~^https?://~', $src)) {
            $ctx = stream_context_create(['http' => ['timeout' => 6], 'ssl' => ['verify_peer' => true]]);
            $data = @file_get_contents($src, false, $ctx);
            return $data ? @imagecreatefromstring($data) : null;
        }
    } catch (Throwable $e) {}
    return null;
}
