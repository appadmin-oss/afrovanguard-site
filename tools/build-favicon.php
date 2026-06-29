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
$root = dirname(__DIR__);
$srcPng = $root . '/assets/site/icon-192.png';

/* ---- 1) favicon.ico : a 32×32 PNG wrapped in a single-image ICO container ---- */
if (is_file($srcPng) && function_exists('imagecreatefrompng')) {
    $src = imagecreatefrompng($srcPng);
    $w = imagesx($src); $h = imagesy($src);
    $size = 32;
    $ico32 = imagecreatetruecolor($size, $size);
    imagealphablending($ico32, false); imagesavealpha($ico32, true);
    imagecopyresampled($ico32, $src, 0, 0, 0, 0, $size, $size, $w, $h);
    ob_start(); imagepng($ico32); $png = ob_get_clean();
    imagedestroy($src); imagedestroy($ico32);

    // ICONDIR (6) + ICONDIRENTRY (16) + PNG payload
    $ico  = pack('vvv', 0, 1, 1);                       // reserved, type=icon, count=1
    $ico .= pack('CCCC', $size, $size, 0, 0);           // w, h, palette, reserved
    $ico .= pack('vv', 1, 32);                          // planes, bpp
    $ico .= pack('VV', strlen($png), 22);               // size of PNG, offset (6+16)
    $ico .= $png;
    file_put_contents($root . '/favicon.ico', $ico);
    echo "wrote favicon.ico (" . strlen($ico) . " bytes)\n";
} else {
    echo "skip favicon.ico (no GD or source missing)\n";
}

/* ---- 2) inject favicon <link>s into static .html heads (idempotent) ---- */
$links = "  <link rel=\"icon\" href=\"/favicon.ico\" sizes=\"any\" />\n"
       . "  <link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"/assets/site/icon-192.png\" />\n"
       . "  <link rel=\"apple-touch-icon\" href=\"/assets/site/icon-192.png\" />\n";

$pages = [
    'index.html', 'about.html', 'contact.html', 'donate.html',
    'donor-dashboard.html', 'member.html',
    '403.html', '404.html', '429.html', '500.html', '503.html',
    'projects/index.html', 'projects/africa-gates/index.html', 'projects/sts/lcasp.html',
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
