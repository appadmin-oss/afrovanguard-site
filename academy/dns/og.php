<?php
/**
 * academy/dns/og.php — the summit's social card (1200×630 PNG).
 *
 *   /academy/dns/og.png   (see academy/.htaccess)
 *
 * Drawn rather than committed, so it can never drift from the summit's facts:
 * every value comes from Summit::facts(), and the cache key is a hash of them —
 * change a date and the card redraws itself on the next request.
 *
 * Same treatment as the page: near-black canvas, the orange→red rule, the lime
 * triad block and the cream fact strip. Falls back to the site's default OG
 * image if GD or the fonts are unavailable, so a share never breaks.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$fallback = rtrim(SITE_URL, '/') . '/Images/og-image.png';
$display  = AV_ROOT . '/assets/fonts/display.ttf';
$body     = AV_ROOT . '/assets/fonts/body.ttf';

if (!extension_loaded('gd') || !function_exists('imagettftext')
    || !is_file($display) || !is_file($body)) {
    header('Location: ' . $fallback, true, 302);
    exit;
}

$f     = Summit::facts();
$venue = $f['venue'];

$W = 1200; $H = 630; $M = 72;

$cacheDir = AV_ROOT . '/db/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/dns-og.png';
$stampFile = $cacheFile . '.key';
$key   = md5(json_encode($f) . '|v2');
$valid = is_file($cacheFile) && is_file($stampFile)
      && trim((string) @file_get_contents($stampFile)) === $key;

/** Draw text with letter spacing — GD has none, and the poster leans on it. */
function dns_tracked($im, float $size, int $x, int $y, $colour, string $font, string $text, float $track): int
{
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        imagettftext($im, $size, 0, $x, $y, $colour, $font, $ch);
        $bb = imagettfbbox($size, 0, $font, $ch);
        $x += (int) round(($bb[2] - $bb[0]) + $track);
    }
    return $x;
}

/** Width of a string at a given size/font. */
function dns_w(float $size, string $font, string $text): int
{
    $bb = imagettfbbox($size, 0, $font, $text);
    return $bb[2] - $bb[0];
}

if (!$valid) {
    $im = imagecreatetruecolor($W, $H);

    $black  = imagecolorallocate($im, 12, 12, 12);
    $white  = imagecolorallocate($im, 255, 255, 255);
    $cream  = imagecolorallocate($im, 222, 210, 187);
    $creamD = imagecolorallocate($im, 18, 16, 13);
    $lime   = imagecolorallocate($im, 163, 201, 58);
    $gold   = imagecolorallocate($im, 243, 180, 22);
    $muted  = imagecolorallocate($im, 150, 145, 136);

    imagefilledrectangle($im, 0, 0, $W, $H, $black);

    // Orange → red rule across the top, matching the display gradient.
    for ($x = 0; $x < $W; $x++) {
        $t = $x / $W;
        $c = imagecolorallocate($im,
            (int) round(255 + (224 - 255) * $t),
            (int) round(157 + (31 - 157) * $t),
            (int) round(0   + (45 - 0)   * $t));
        imagefilledrectangle($im, $x, 0, $x + 1, 9, $c);
    }

    // Kicker
    dns_tracked($im, 15, $M, 96, $gold, $body, 'AFROVANGUARD  PRESENTS', 5.0);

    // Title. The last word carries the orange→red ramp, as on the poster —
    // GD has no gradient fill, so each glyph is drawn at its own point on it.
    $size = 76;
    $lh   = (int) round($size * 1.16);
    $y    = 96 + 92;
    foreach (["D'VANGUARD", 'NATIONAL'] as $text) {
        imagettftext($im, $size, 0, $M, $y, $white, $display, $text);
        $y += $lh;
    }
    $accent  = 'SUMMIT';
    $accentW = max(1, dns_w($size, $display, $accent));
    $x = $M;
    foreach (preg_split('//u', $accent, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $t = min(1, max(0, ($x - $M) / $accentW));
        $c = imagecolorallocate($im,
            (int) round(255 + (224 - 255) * $t),
            (int) round(157 + (31 - 157) * $t),
            (int) round(0   + (45 - 0)   * $t));
        imagettftext($im, $size, 0, $x, $y, $c, $display, $ch);
        $x += dns_w($size, $display, $ch);
    }

    // MASTER | TAME | OWN, in the lime block — measured from the SUMMIT
    // baseline so it can never run into the fact strip below.
    $triad = strtoupper($f['triad']);
    $tw    = dns_w(19, $body, $triad) + 90;
    $ty    = $y + 26;
    imagefilledrectangle($im, $M, $ty, $M + $tw, $ty + 54, $lime);
    dns_tracked($im, 19, $M + 26, $ty + 36, $creamD, $body, $triad, 3.0);

    // "DNS '26" badge, top right
    $badge = $f['edition'];
    $bw    = dns_w(30, $display, $badge);
    imagerectangle($im, $W - $M - $bw - 56, 64, $W - $M, 134, $gold);
    imagettftext($im, 30, 0, $W - $M - $bw - 28, 112, $gold, $display, $badge);

    // Cream fact strip along the foot
    $stripY = $H - 118;
    imagefilledrectangle($im, 0, $stripY, $W, $H, $cream);
    $facts = [
        ['DATES', $f['date_short']],
        ['VENUE', $venue['name'] . ', ' . $venue['area']],
        ['PASS',  $f['pass']['label'] . ' · all four days'],
    ];
    $colW = (int) (($W - 2 * $M) / 3);
    foreach ($facts as $i => [$label, $value]) {
        $cx = $M + $i * $colW;
        if ($i > 0) imagefilledrectangle($im, $cx - 26, $stripY + 30, $cx - 25, $H - 34,
                                         imagecolorallocatealpha($im, 18, 16, 13, 96));
        dns_tracked($im, 11, $cx, $stripY + 46, imagecolorallocatealpha($im, 18, 16, 13, 40),
                    $body, $label, 3.0);
        // Shrink to fit rather than spilling into the next column.
        $vs = 21.0;
        while ($vs > 13 && dns_w($vs, $body, $value) > $colW - 34) $vs -= 1.0;
        imagettftext($im, $vs, 0, $cx, $stripY + 82, $creamD, $body, $value);
    }

    // Host, bottom right of the dark area
    $host = 'afrovanguard.org.ng/academy/dns';
    imagettftext($im, 15, 0, $W - $M - dns_w(15, $body, $host), $stripY - 30, $muted, $body, $host);

    imagepng($im, $cacheFile);
    @file_put_contents($stampFile, $key);
    imagedestroy($im);
}

if (!is_file($cacheFile)) { header('Location: ' . $fallback, true, 302); exit; }
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);
