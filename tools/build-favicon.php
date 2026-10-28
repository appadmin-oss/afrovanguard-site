<?php
/**
 * tools/build-favicon.php — generate /favicon.ico from the PWA icon and inject
 * the favicon <link> tags into the static .html pages' <head>.
 *
 * The PHP pages get their favicon links from render_head() in lib/partials.php;
 * the static marketing/error pages can't, so we sweep them here (idempotent —
 * safe to re-run). One source of truth for the icon: assets/site/icon-192.png.
 *
 *   php tools/build-favicon.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);

/* ---- 1) the whole icon set, from the seal ------------------------------
   One source of truth for the brand mark: assets/site/av-seal-256.png.

   The icons used to be a flat gold "A" on a navy that is in no token — a
   placeholder nobody replaced. They are the seal now, WHOLE, at every size:

     any / maskable   the seal on the brand ink. Maskable gets a bigger
                      margin so Android's circle never bites the gold ring.
     favicon          the same seal at 48, 32 and 16.

   THE SEAL IS NEVER CROPPED, RECOLOURED OR DRAWN OVER HERE. Scaling it and
   centring it on a plate is all this script may do to it. If the mark needs
   a simplified small-size variant, that is the designer's to draw.
   ---------------------------------------------------------------------- */
$srcSeal = $root . '/assets/site/av-seal-256.png';
if (is_file($srcSeal) && function_exists('imagecreatefrompng')) {
    $seal = imagecreatefrompng($srcSeal);
    imagealphablending($seal, true); imagesavealpha($seal, true);
    $sw = imagesx($seal); $sh = imagesy($seal);

    /** A transparent truecolour canvas. */
    $canvas = static function (int $n) {
        $im = imagecreatetruecolor($n, $n);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $n, $n, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        return $im;
    };
    /** Rounded square in --av-ink. $r = 0 for the maskable square. */
    $plate = static function ($im, int $n, int $r) {
        $ink = imagecolorallocate($im, 0x11, 0x18, 0x27);   // --av-ink #111827
        if ($r <= 0) { imagefilledrectangle($im, 0, 0, $n - 1, $n - 1, $ink); return; }
        imagefilledrectangle($im, $r, 0, $n - 1 - $r, $n - 1, $ink);
        imagefilledrectangle($im, 0, $r, $n - 1, $n - 1 - $r, $ink);
        foreach ([[$r, $r], [$n - 1 - $r, $r], [$r, $n - 1 - $r], [$n - 1 - $r, $n - 1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $ink);
        }
    };
    /** The seal, centred at $scale of the canvas. */
    $place = static function ($im, $seal, int $n, float $scale) use ($sw, $sh) {
        $d = (int) round($n * $scale);
        $o = (int) round(($n - $d) / 2);
        imagecopyresampled($im, $seal, $o, $o, 0, 0, $d, $d, $sw, $sh);
    };
    $save = static function ($im, string $path) use ($root) {
        imagepng($im, $root . '/' . $path, 9);
        echo 'wrote ', $path, ' (', number_format(filesize($root . '/' . $path)), " bytes)\n";
        imagedestroy($im);
    };

    foreach ([512, 192] as $n) {
        $im = $canvas($n); $plate($im, $n, (int) round($n * 0.22)); $place($im, $seal, $n, 0.76);
        $save($im, 'assets/site/icon-' . $n . '.png');
    }
    // Maskable: square plate, seal inside the 80% safe circle.
    $im = $canvas(512); $plate($im, 512, 0); $place($im, $seal, 512, 0.56);
    $save($im, 'assets/site/icon-512-maskable.png');

    /* favicon.ico — 48, 32 and 16, each its own image so no chrome has to
       downscale another.

       The WHOLE seal, at every size. An earlier pass here cropped it to the
       shield and knot because the ring text stops being readable somewhere
       under 64px — which is true, and is not a reason to cut a logo apart.
       The seal is one lockup. At 16px it reads as a gold ring around a dark
       shield, which is what a 16px mark is for; it is not supposed to be
       read, it is supposed to be recognised. A genuine small-size variant is
       the designer's to draw, not this script's to invent. */
    $entries = [];
    foreach ([48, 32, 16] as $n) {
        $im = $canvas($n);
        imagecopyresampled($im, $seal, 0, 0, 0, 0, $n, $n, $sw, $sh);
        ob_start(); imagepng($im, null, 9); $entries[$n] = ob_get_clean();
        imagedestroy($im);
    }
    $count  = count($entries);
    $ico    = pack('vvv', 0, 1, $count);
    $offset = 6 + 16 * $count;
    foreach ($entries as $n => $png) {
        $ico .= pack('CCCC', $n, $n, 0, 0) . pack('vv', 1, 32) . pack('VV', strlen($png), $offset);
        $offset += strlen($png);
    }
    foreach ($entries as $png) $ico .= $png;
    file_put_contents($root . '/favicon.ico', $ico);
    echo 'wrote favicon.ico (', number_format(strlen($ico)), " bytes, ", $count, " sizes)\n";

    imagedestroy($seal);
} else {
    echo "skip icons (no GD or assets/site/av-seal-256.png missing)\n";
}

/* ---- 2) inject favicon <link>s into static .html heads (idempotent) ---- */
$links = "  <link rel=\"icon\" href=\"/favicon.ico\" sizes=\"any\" />\n"
       . "  <link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"/assets/site/icon-192.png\" />\n"
       . "  <link rel=\"apple-touch-icon\" href=\"/assets/site/icon-192.png\" />\n";

$pages = [
    'index.html', 'about.html', 'contact.html', 'donate.html',
    'donor-dashboard.html', 'member.html',
    '403.html', '404.html', '429.html', '500.html', '503.html',
    'projects/index.html', 'projects/sts/lcasp.html',
];
$done = 0;
foreach ($pages as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) continue;
    $html = file_get_contents($path);
    if (strpos($html, 'rel="icon"') !== false) { echo "ok (already): $rel\n"; continue; }
    // insert right after the first <meta charset...> if present, else after <head>
    if (preg_match('~<meta\s+charset[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . "\n" . $links . substr($html, $pos);
    } elseif (($pos = stripos($html, '<head>')) !== false) {
        $pos += 6;
        $html = substr($html, 0, $pos) . "\n" . $links . substr($html, $pos);
    } else {
        echo "skip (no head): $rel\n"; continue;
    }
    file_put_contents($path, $html);
    echo "injected: $rel\n"; $done++;
}
echo "Done. $done page(s) updated.\n";
