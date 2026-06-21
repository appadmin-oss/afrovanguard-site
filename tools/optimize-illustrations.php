<?php
/**
 * tools/optimize-illustrations.php — make illustrations web-ready.
 *
 * Drop full-size originals named  <name>-src.(png|jpg|jpeg|webp)  into
 * assets/illustrations/ (or any folder passed as an argument), then run:
 *
 *   php tools/optimize-illustrations.php [folder]
 *
 * For each original it writes, sized for mobile → large screens:
 *   <name>.png      ~840px wide  (universal fallback)
 *   <name>.webp     ~840px wide  (1x, modern)
 *   <name>@2x.webp  ~1680px wide (retina / large screens)
 */
declare(strict_types=1);
if (!extension_loaded('gd')) { fwrite(STDERR, "GD extension required.\n"); exit(1); }

$root = dirname(__DIR__);
$dir  = $argv[1] ?? ($root . '/assets/illustrations');
$ONE = 840; $TWO = 1680;

$srcs = glob($dir . '/*-src.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
if (!$srcs) { fwrite(STDOUT, "No '*-src.*' originals found in $dir.\nDrop originals like error-404-src.png and re-run.\n"); exit(0); }

function load(string $f) {
    $d = file_get_contents($f);
    return $d ? imagecreatefromstring($d) : false;
}
function resize($im, int $w) {
    $sw = imagesx($im); $sh = imagesy($im);
    if ($sw <= $w) { $w = $sw; }
    $h = (int) round($sh * ($w / $sw));
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $sw, $sh);
    return $out;
}

foreach ($srcs as $f) {
    $name = preg_replace('/-src$/', '', pathinfo($f, PATHINFO_FILENAME));
    $im = load($f);
    if (!$im) { fwrite(STDERR, "skip (decode failed): $f\n"); continue; }
    $one = resize($im, $ONE); $two = resize($im, $TWO);
    imagepng($one, "$dir/$name.png", 8);
    imagewebp($one, "$dir/$name.webp", 82);
    imagewebp($two, "$dir/$name@2x.webp", 80);
    imagedestroy($one); imagedestroy($two); imagedestroy($im);
    fwrite(STDOUT, "✓ $name → .png, .webp, @2x.webp\n");
}
fwrite(STDOUT, "Done.\n");
