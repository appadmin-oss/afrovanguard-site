<?php
/**
 * celebrate.php — shareable celebration poster generator (1080×1350 PNG).
 *
 *   ?type=votm[&id=N|&month=YYYY-MM]   Volunteer of the Month poster
 *   ?type=birthday&id=N                Birthday card for a team member
 *   ?type=holiday[&date=YYYY-MM-DD]    Today's (or a date's) holiday card
 *   &dl=1                              force a download filename
 *
 * Matches the brand VOTM poster: light textured card, gold roundel, month
 * pill, "Congratulations!", big title, circular B&W portrait in a gold ring,
 * a gold name ribbon, the tribute, and the social footer. Cached to db/cache.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/people.php';
require_once AV_ROOT . '/lib/celebrations.php';

$fallback = rtrim(SITE_URL, '/') . '/assets/img/ethos-og.jpg';
$display = AV_ROOT . '/assets/fonts/display.ttf';  // Cormorant SC
$body    = AV_ROOT . '/assets/fonts/body.ttf';     // Montserrat
if (!extension_loaded('gd') || !function_exists('imagettftext') || !is_file($body)) {
    header('Location: ' . $fallback, true, 302); exit;
}

$type = preg_replace('/[^a-z]/', '', (string) ($_GET['type'] ?? 'votm'));
$pdo  = Database::pdo();

/* ---- gather subject ---- */
$name = ''; $role = ''; $photo = ''; $kicker = ''; $title = ''; $msg = ''; $monthLabel = '';
$theme = [243, 180, 22]; $cacheKey = '';

if ($type === 'holiday') {
    $d = (string) ($_GET['date'] ?? '');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    $c = av_celebration_today($pdo, $date);
    if (!$c) { header('Location: ' . $fallback, true, 302); exit; }
    $p = $c['primary'];
    $kicker = 'Afrovanguard celebrates';
    $title = $p['title'];
    $msg = $p['message'];
    if (preg_match('/^#([0-9a-f]{6})$/i', (string) ($p['theme'] ?? ''), $mm)) {
        $theme = [hexdec(substr($mm[1], 0, 2)), hexdec(substr($mm[1], 2, 2)), hexdec(substr($mm[1], 4, 2))];
    }
    $cacheKey = 'hol-' . $date . '-' . md5($title . $msg);
} else {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $r = av_team_one($pdo, $id);
        $m = ($r['status'] ?? '') === 'ok' ? $r['member'] : null;
    } else {
        $v = av_votm($pdo); $m = null;
        if ($v['votm']) { $r = av_team_one($pdo, (int) $v['votm']['id']); $m = ($r['status'] ?? '') === 'ok' ? $r['member'] : null; $vd = $v['votm']; }
    }
    if (!$m) { header('Location: ' . $fallback, true, 302); exit; }
    $name = $m['name']; $role = $m['role']; $photo = $m['photo'];
    if ($type === 'birthday') {
        $kicker = 'Happy Birthday!'; $title = 'CELEBRATING YOU';
        $msg = 'Wishing you a wonderful year ahead. Thank you for all you give to the movement — happy birthday from the whole Afrovanguard family!';
        $cacheKey = 'bday-' . $id . '-' . md5($name . $photo);
    } else { // votm
        $vd = $vd ?? av_votm($pdo, isset($_GET['month']) ? (string) $_GET['month'] : null)['votm'];
        $monthLabel = '';
        if ($vd && !empty($vd['month'])) { $monthLabel = strtoupper(date('F Y', mktime(0, 0, 0, (int) $vd['month'], 1, (int) ($vd['year'] ?: date('Y'))))); }
        $kicker = 'Congratulations!'; $title = "VOLUNTEER OF\nTHE MONTH";
        $msg = ($vd && !empty($vd['quote'])) ? $vd['quote']
            : 'Your service speaks louder than words. Your impact reaches farther than you know. Thank you for making a difference.';
        $cacheKey = 'votm-' . $m['id'] . '-' . ($monthLabel ?: 'na') . '-' . md5($name . $photo . $msg);
    }
}

/* ---- cache ---- */
$cacheDir = AV_ROOT . '/db/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$file = $cacheDir . '/celebrate-' . preg_replace('/[^a-z0-9-]/i', '', $cacheKey) . '.png';
$send = function (string $f) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="afrovanguard-celebration.png"');
    readfile($f); exit;
};
if (is_file($file) && filemtime($file) > time() - 86400) $send($file);

/* ---- helpers ---- */
function av_fetch_img(string $url): ?string {
    if ($url === '') return null;
    if ($url[0] === '/') { $p = AV_ROOT . $url; return is_file($p) ? (file_get_contents($p) ?: null) : null; }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
        $d = curl_exec($ch); $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200; curl_close($ch);
        return ($ok && $d) ? $d : null;
    }
    return @file_get_contents($url) ?: null;
}
/**
 * Square-crop (top-biased) → grayscale → optional brand duotone, masked to a
 * circle with a soft edge feather and a gentle vignette. $dark/$light are the
 * two duotone endpoints; pass null for a plain B&W treatment.
 */
function av_circle_portrait(?string $data, int $d, ?array $dark = null, ?array $light = null): ?GdImage {
    if (!$data) return null;
    $src = @imagecreatefromstring($data); if (!$src) return null;
    $sw = imagesx($src); $sh = imagesy($src); $side = min($sw, $sh);
    $sx = (int) (($sw - $side) / 2); $sy = (int) (($sh - $side) / 7); if ($sy + $side > $sh) $sy = $sh - $side;
    $sq = imagecreatetruecolor($d, $d);
    imagecopyresampled($sq, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);
    imagefilter($sq, IMG_FILTER_GRAYSCALE);
    imagefilter($sq, IMG_FILTER_CONTRAST, -8);       // gentle lift so faces read well
    $duo = $dark && $light;
    $out = imagecreatetruecolor($d, $d);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $d, $d, imagecolorallocatealpha($out, 0, 0, 0, 127));
    $r = $d / 2; $feather = 2.0; $vig = $r * 0.62;
    for ($y = 0; $y < $d; $y++) for ($x = 0; $x < $d; $x++) {
        $dx = $x - $r + .5; $dy = $y - $r + .5; $dist = sqrt($dx * $dx + $dy * $dy);
        if ($dist > $r) continue;
        $g = imagecolorat($sq, $x, $y) & 0xFF; $t = $g / 255;
        if ($duo) {
            $rr = (int) ($dark[0] + ($light[0] - $dark[0]) * $t);
            $gg = (int) ($dark[1] + ($light[1] - $dark[1]) * $t);
            $bb = (int) ($dark[2] + ($light[2] - $dark[2]) * $t);
        } else { $rr = $gg = $bb = $g; }
        if ($dist > $vig) { $f = 1 - ($dist - $vig) / ($r - $vig) * 0.30; $rr = (int) ($rr * $f); $gg = (int) ($gg * $f); $bb = (int) ($bb * $f); }
        $a = $dist > $r - $feather ? (int) (127 * ($dist - ($r - $feather)) / $feather) : 0;
        imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $rr, $gg, $bb, $a));
    }
    imagedestroy($src); imagedestroy($sq);
    return $out;
}
function av_text_center($im, string $f, float $size, int $cx, int $y, $col, string $t): void {
    $b = imagettfbbox($size, 0, $f, $t); $w = $b[2] - $b[0];
    imagettftext($im, $size, 0, (int) ($cx - $w / 2), $y, $col, $f, $t);
}
/** faux-bold centered text (heavier strokes) */
function av_text_center_bold($im, string $f, float $size, int $cx, int $y, $col, string $t): void {
    $b = imagettfbbox($size, 0, $f, $t); $w = $b[2] - $b[0]; $x = (int) ($cx - $w / 2);
    foreach ([[0,0],[1,0],[0,1],[1,1],[2,0]] as $o) imagettftext($im, $size, 0, $x + $o[0], $y + $o[1], $col, $f, $t);
}
function av_wrap(string $f, float $size, int $maxw, string $t): array {
    $words = preg_split('/\s+/', trim($t)); $lines = []; $cur = '';
    foreach ($words as $word) {
        $try = $cur === '' ? $word : "$cur $word";
        $b = imagettfbbox($size, 0, $f, $try);
        if (($b[2] - $b[0]) > $maxw && $cur !== '') { $lines[] = $cur; $cur = $word; } else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}
/** auto-fit: shrink size until the text wraps within $maxw and $maxLines. Returns [size, lines[]]. */
function av_fit_lines(string $f, float $start, float $min, int $maxw, string $t, int $maxLines): array {
    $t = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $t)));
    for ($s = $start; $s >= $min; $s -= 2) {
        $lines = av_wrap($f, $s, $maxw, $t);
        if (count($lines) > $maxLines) continue;
        $ok = true;
        foreach ($lines as $ln) { $b = imagettfbbox($s, 0, $f, $ln); if (($b[2] - $b[0]) > $maxw) { $ok = false; break; } }
        if ($ok) return [$s, $lines];
    }
    return [$min, array_slice(av_wrap($f, $min, $maxw, $t), 0, $maxLines)];
}
/** centered text with letter-spacing (for tracked uppercase eyebrows). */
function av_text_tracked_center($im, string $f, float $size, int $cx, int $y, $col, string $t, float $track): void {
    $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY); $space = $size * 0.34;
    $tot = 0; $w = [];
    foreach ($chars as $ch) { if ($ch === ' ') { $w[] = $space; $tot += $space + $track; continue; } $b = imagettfbbox($size, 0, $f, $ch); $cw = $b[2] - $b[0]; $w[] = $cw; $tot += $cw + $track; }
    $tot -= $track; $x = $cx - $tot / 2;
    foreach ($chars as $i => $ch) { if ($ch !== ' ') imagettftext($im, $size, 0, (int) $x, $y, $col, $f, $ch); $x += $w[$i] + $track; }
}
/** a 4-point sparkle/star (concave diamond). */
function av_sparkle($im, int $x, int $y, float $r, $col): void {
    $s = $r * 0.26;
    imagefilledpolygon($im, [$x, (int) ($y - $r), (int) ($x + $s), (int) ($y - $s), $x + (int) $r, $y, (int) ($x + $s), (int) ($y + $s), $x, (int) ($y + $r), (int) ($x - $s), (int) ($y + $s), $x - (int) $r, $y, (int) ($x - $s), (int) ($y - $s)], $col);
}
/** scatter decorative sparkles across the canvas, avoiding the central column. */
function av_scatter_sparkles($im, int $W, int $H, $gold, $goldFaint): void {
    $pts = [[120, 470, 13], [980, 360, 16], [88, 760, 9], [995, 720, 11], [150, 1080, 14], [930, 1040, 10], [70, 300, 8], [1010, 980, 9], [200, 250, 7], [880, 250, 8]];
    foreach ($pts as [$x, $y, $r]) av_sparkle($im, $x, $y, (float) $r, ($r % 2 === 0) ? $gold : $goldFaint);
}

/* ---- render ---- */
$W = 1080; $H = 1350;
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
$ink = imagecolorallocate($im, 17, 18, 22);
$inkSoft = imagecolorallocate($im, 70, 72, 80);
$white = imagecolorallocate($im, 255, 255, 255);
$gold = imagecolorallocate($im, 243, 180, 22);
$goldDk = imagecolorallocate($im, 197, 138, 10);
[$tr, $tg, $tb] = $theme; $themeCol = imagecolorallocate($im, $tr, $tg, $tb);

$cx = (int) ($W / 2);
$themeLight = imagecolorallocate($im, (int) min(255, $tr + (255 - $tr) * 0.34), (int) min(255, $tg + (255 - $tg) * 0.34), (int) min(255, $tb + (255 - $tb) * 0.34));
$goldFaint = imagecolorallocatealpha($im, 243, 180, 22, 80);
$duoDark = [30, 24, 14]; $duoLight = [252, 244, 224];   // warm sepia → cream portrait duotone

// background — warm off-white, soft facets, a top sheen and a grounding tint
imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, 245, 244, 240));
$facet = imagecolorallocatealpha($im, 255, 255, 255, 98);
imagefilledpolygon($im, [0, 0, 460, 0, 0, 460], $facet);
imagefilledpolygon($im, [$W, $H, $W - 520, $H, $W, $H - 520], $facet);
imagefilledrectangle($im, 0, $H - 230, $W, $H, imagecolorallocatealpha($im, $tr, $tg, $tb, 118)); // tint base
$shade = imagecolorallocatealpha($im, 17, 18, 22, 124);
imagefilledpolygon($im, [$W, 0, $W - 320, 0, $W, 320], $shade);
av_scatter_sparkles($im, $W, $H, $gold, $goldFaint);

// gold roundel (emblem) top-left, with a faint halo
$ex = 150; $ey = 162; $eR = 70;
imagefilledellipse($im, $ex, $ey, $eR * 2 + 22, $eR * 2 + 22, $goldFaint);
imagefilledellipse($im, $ex, $ey, $eR * 2, $eR * 2, $gold);
imagefilledellipse($im, $ex, $ey, $eR * 2 - 14, $eR * 2 - 14, $ink);
av_text_center($im, $display, 30, $ex, $ey + 11, $gold, 'AV');

// month pill top-right (votm only)
if ($monthLabel !== '') {
    $pad = 24; $ps = 23; $b = imagettfbbox($ps, 0, $body, $monthLabel); $pw = ($b[2] - $b[0]) + $pad * 2;
    $px2 = $W - 72; $px1 = $px2 - $pw; $py1 = 130; $py2 = 182; $rad = 26;
    imagefilledrectangle($im, $px1 + $rad, $py1, $px2 - $rad, $py2, $gold);
    imagefilledrectangle($im, $px1, $py1 + 4, $px2, $py2 - 4, $gold);
    imagefilledellipse($im, $px1 + $rad, (int) (($py1 + $py2) / 2), $py2 - $py1, $py2 - $py1, $gold);
    imagefilledellipse($im, $px2 - $rad, (int) (($py1 + $py2) / 2), $py2 - $py1, $py2 - $py1, $gold);
    av_text_center($im, $body, $ps, (int) (($px1 + $px2) / 2), $py2 - 18, $ink, $monthLabel);
}

$hasPhoto = in_array($type, ['votm', 'birthday'], true);

if ($hasPhoto) {
    /* ---------- portrait layout (VOTM / birthday) — name is the hero ---------- */
    $eyebrow = $type === 'votm' ? 'VOLUNTEER OF THE MONTH' : 'HAPPY BIRTHDAY';
    av_text_tracked_center($im, $body, 27, $cx, 322, $goldDk, $eyebrow, 7);
    // little flourish under the eyebrow
    imagesetthickness($im, 3);
    imageline($im, $cx - 96, 348, $cx - 34, 348, $gold); imageline($im, $cx + 34, 348, $cx + 96, 348, $gold);
    av_sparkle($im, $cx, 348, 7, $gold);
    imagesetthickness($im, 1);

    // portrait — duotone, soft shadow, gold ring
    $cy = 612; $diam = 404; $rOut = (int) ($diam / 2) + 17;
    imagefilledellipse($im, $cx, $cy + 20, $rOut * 2 + 8, $rOut * 2 + 8, imagecolorallocatealpha($im, 17, 18, 22, 104)); // shadow
    imagefilledellipse($im, $cx, $cy, $rOut * 2, $rOut * 2, $gold);                         // gold ring
    $port = av_circle_portrait(av_fetch_img($photo), $diam, $duoDark, $duoLight);
    if ($port) {
        imagecopy($im, $port, (int) ($cx - $diam / 2), (int) ($cy - $diam / 2), 0, 0, $diam, $diam);
        imagedestroy($port);
        imagesetthickness($im, 2); imageellipse($im, $cx, $cy, $diam + 2, $diam + 2, $goldDk); imagesetthickness($im, 1);
    } else {
        imagefilledellipse($im, $cx, $cy, $diam, $diam, imagecolorallocate($im, $duoDark[0], $duoDark[1], $duoDark[2]));
        $pp = preg_split('/\s+/', $name); $ini = strtoupper(($pp[0][0] ?? '') . ($pp[count($pp) - 1][0] ?? ''));
        av_text_center($im, $display, 150, $cx, $cy + 56, $gold, $ini);
    }

    // name ribbon (hero) — gold banner with folded ends + drop shadow
    $ry = $cy + (int) ($diam / 2) - 6; $rh = 92; $rx1 = 150; $rx2 = $W - 150;
    imagefilledrectangle($im, $rx1 + 6, $ry + 8, $rx2 + 6, $ry + $rh + 8, imagecolorallocatealpha($im, 17, 18, 22, 110));
    imagefilledpolygon($im, [$rx1 - 58, $ry + 14, $rx1, $ry + 2, $rx1, $ry + $rh + 4, $rx1 - 58, $ry + $rh + 28], $goldDk);
    imagefilledpolygon($im, [$rx2 + 58, $ry + 14, $rx2, $ry + 2, $rx2, $ry + $rh + 4, $rx2 + 58, $ry + $rh + 28], $goldDk);
    imagefilledrectangle($im, $rx1, $ry, $rx2, $ry + $rh, $gold);
    imagefilledrectangle($im, $rx1, $ry, $rx2, $ry + 5, imagecolorallocatealpha($im, 255, 255, 255, 80)); // sheen
    $nm = strtoupper($name);
    $ns = 56; $b = imagettfbbox($ns, 0, $display, $nm); while (($b[2] - $b[0]) > ($rx2 - $rx1 - 64) && $ns > 26) { $ns -= 2; $b = imagettfbbox($ns, 0, $display, $nm); }
    av_text_center($im, $display, $ns, $cx, $ry + (int) (($rh + $ns) / 2) - 4, $white, $nm);
    $yCur = $ry + $rh + 28;

    // role
    if ($role !== '') { $yCur += 50; av_text_tracked_center($im, $body, 24, $cx, $yCur, $inkSoft, strtoupper($role), 3); }

    // tribute / quote message
    $yCur += 56;
    $isQuote = $type === 'votm';
    $mtxt = $isQuote ? ('“' . $msg . '”') : $msg;
    [$ms, $mlines] = av_fit_lines($body, 30, 21, $W - 220, $mtxt, 4);
    foreach ($mlines as $i => $ln) av_text_center($im, $body, $ms, $cx, $yCur + $i * (int) ($ms * 1.5), $inkSoft, $ln);
} else {
    /* ---------- badge layout (holiday) ---------- */
    av_text_tracked_center($im, $body, 25, $cx, 332, $goldDk, 'AFROVANGUARD CELEBRATES', 6);
    imagesetthickness($im, 3);
    imageline($im, $cx - 96, 358, $cx - 34, 358, $gold); imageline($im, $cx + 34, 358, $cx + 96, 358, $gold);
    av_sparkle($im, $cx, 358, 7, $gold); imagesetthickness($im, 1);

    // big themed disc with the holiday name inside
    $cy = 730; $diam = 540; $rim = (int) ($diam / 2) + 22;
    imagefilledellipse($im, $cx, $cy + 22, $rim * 2 + 8, $rim * 2 + 8, imagecolorallocatealpha($im, 17, 18, 22, 108)); // shadow
    imagefilledellipse($im, $cx, $cy, $rim * 2, $rim * 2, $gold);               // gold rim
    imagefilledellipse($im, $cx, $cy, $diam, $diam, $themeCol);                 // theme disc
    imagefilledellipse($im, $cx, $cy - 64, (int) ($diam * 0.62), (int) ($diam * 0.5), imagecolorallocatealpha($im, 255, 255, 255, 112)); // sheen
    imagesetthickness($im, 2); imageellipse($im, $cx, $cy, $diam - 52, $diam - 52, imagecolorallocatealpha($im, 255, 255, 255, 88)); imagesetthickness($im, 1);

    // holiday title inside the disc, white, auto-fit up to 3 lines
    [$ts, $tl] = av_fit_lines($display, 92, 40, $diam - 130, $title, 3);
    $tlh = (int) ($ts * 1.2); $n = count($tl);
    $startY = $cy - (int) (($n - 1) * $tlh / 2) + (int) ($ts * 0.34);
    foreach ($tl as $i => $line) av_text_center_bold($im, $display, $ts, $cx, $startY + $i * $tlh, $white, $line);

    // message below the disc
    $msgY = $cy + (int) ($diam / 2) + 100;
    [$ms, $mlines] = av_fit_lines($body, 31, 22, $W - 200, $msg, 3);
    foreach ($mlines as $i => $ln) av_text_center($im, $body, $ms, $cx, $msgY + $i * (int) ($ms * 1.5), $inkSoft, $ln);
}

// footer — handles, with a hairline divider above
imagesetthickness($im, 2);
imageline($im, 90, $H - 104, $W - 90, $H - 104, imagecolorallocatealpha($im, 17, 18, 22, 116));
imagesetthickness($im, 1);
imagettftext($im, 24, 0, 90, $H - 58, $ink, $body, '@afro.vanguard');
$fr = 'www.afrovanguard.org.ng'; $b = imagettfbbox(24, 0, $body, $fr);
imagettftext($im, 24, 0, $W - 90 - ($b[2] - $b[0]), $H - 58, $ink, $body, $fr);

imagepng($im, $file);
imagedestroy($im);
$send($file);
