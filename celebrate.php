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
function av_circle_portrait(?string $data, int $d): ?GdImage {
    if (!$data) return null;
    $src = @imagecreatefromstring($data); if (!$src) return null;
    $sw = imagesx($src); $sh = imagesy($src); $side = min($sw, $sh);
    $sx = (int) (($sw - $side) / 2); $sy = (int) (($sh - $side) / 7); if ($sy + $side > $sh) $sy = $sh - $side;
    $sq = imagecreatetruecolor($d, $d);
    imagecopyresampled($sq, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);
    imagefilter($sq, IMG_FILTER_GRAYSCALE);
    $out = imagecreatetruecolor($d, $d);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $d, $d, imagecolorallocatealpha($out, 0, 0, 0, 127));
    $r = $d / 2;
    for ($y = 0; $y < $d; $y++) for ($x = 0; $x < $d; $x++) {
        $dx = $x - $r + .5; $dy = $y - $r + .5;
        if ($dx * $dx + $dy * $dy <= $r * $r) imagesetpixel($out, $x, $y, imagecolorat($sq, $x, $y));
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

// background: soft off-white with faint diagonal facets
imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, 244, 243, 239));
$facet = imagecolorallocatealpha($im, 255, 255, 255, 90);
imagefilledpolygon($im, [0, 0, 420, 0, 0, 420], $facet);
imagefilledpolygon($im, [$W, $H, $W - 460, $H, $W, $H - 460], $facet);
$shade = imagecolorallocatealpha($im, 17, 18, 22, 122);
imagefilledpolygon($im, [$W, 0, $W - 300, 0, $W, 300], $shade);

// gold roundel (emblem) top-left
$ex = 150; $ey = 165; $eR = 74;
imagefilledellipse($im, $ex, $ey, $eR * 2, $eR * 2, $gold);
imagefilledellipse($im, $ex, $ey, $eR * 2 - 16, $eR * 2 - 16, $ink);
av_text_center($im, $display, 30, $ex, $ey + 11, $gold, 'AV');

// month pill top-right (votm only)
if ($monthLabel !== '') {
    $pad = 22; $ps = 24; $b = imagettfbbox($ps, 0, $body, $monthLabel); $pw = ($b[2] - $b[0]) + $pad * 2;
    $px2 = $W - 70; $px1 = $px2 - $pw; $py1 = 132; $py2 = 184;
    imagefilledrectangle($im, $px1, $py1, $px2, $py2, $gold);
    av_text_center($im, $body, $ps, (int) (($px1 + $px2) / 2), $py2 - 18, $ink, $monthLabel);
}

// kicker ("Congratulations!" / "Happy Birthday!")
av_text_center($im, $display, 70, (int) ($W / 2), 340, $ink, $kicker);

// title (1–2 lines), heavy
$ty = 470; $tlines = explode("\n", $title);
foreach ($tlines as $i => $tl) av_text_center_bold($im, $body, 86, (int) ($W / 2), $ty + $i * 96, $ink, $tl);

// portrait or themed medallion
$hasPhoto = in_array($type, ['votm', 'birthday'], true);
$cy = 800; $diam = 416; $cx = (int) ($W / 2);
if ($hasPhoto) {
    imagefilledellipse($im, $cx, $cy, $diam + 30, $diam + 30, $gold);      // solid gold ring
    $port = av_circle_portrait(av_fetch_img($photo), $diam);
    if ($port) {
        imagecopy($im, $port, (int) ($cx - $diam / 2), (int) ($cy - $diam / 2), 0, 0, $diam, $diam);
        imagedestroy($port);
    } else {
        imagefilledellipse($im, $cx, $cy, $diam, $diam, imagecolorallocate($im, 26, 34, 51));
        $pp = preg_split('/\s+/', $name); $ini = strtoupper(($pp[0][0] ?? '') . ($pp[count($pp) - 1][0] ?? ''));
        av_text_center($im, $display, 140, $cx, $cy + 52, $gold, $ini);
    }
} else {
    imagefilledellipse($im, $cx, $cy, $diam + 28, $diam + 28, $themeCol);
    imagefilledellipse($im, $cx, $cy, $diam, $diam, imagecolorallocate($im, 244, 243, 239));
}

// name ribbon (votm/birthday) — gold banner with folded ends
$ribbonEnd = $cy + (int) ($diam / 2);
if ($name !== '') {
    $ry = $cy + (int) ($diam / 2) - 24; $rh = 84; $rx1 = 168; $rx2 = $W - 168;
    imagefilledpolygon($im, [$rx1 - 56, $ry + 12, $rx1, $ry + 2, $rx1, $ry + $rh + 4, $rx1 - 56, $ry + $rh + 26], $goldDk);
    imagefilledpolygon($im, [$rx2 + 56, $ry + 12, $rx2, $ry + 2, $rx2, $ry + $rh + 4, $rx2 + 56, $ry + $rh + 26], $goldDk);
    imagefilledrectangle($im, $rx1, $ry, $rx2, $ry + $rh, $gold);
    $nm = strtoupper($name);
    $ns = 50; $b = imagettfbbox($ns, 0, $display, $nm); while (($b[2] - $b[0]) > ($rx2 - $rx1 - 56) && $ns > 24) { $ns -= 2; $b = imagettfbbox($ns, 0, $display, $nm); }
    av_text_center($im, $display, $ns, $cx, $ry + (int) (($rh + $ns) / 2) - 4, $white, $nm);
    $ribbonEnd = $ry + $rh + 26;
}

// tribute message (wrapped, centered, max 3 lines)
$msgY = ($name !== '') ? $ribbonEnd + 50 : $cy + (int) ($diam / 2) + 70;
$ms = 29; $lines = array_slice(av_wrap($body, $ms, $W - 200, $msg), 0, 3);
foreach ($lines as $i => $ln) av_text_center($im, $body, $ms, $cx, $msgY + $i * 42, $inkSoft, $ln);

// footer
imagettftext($im, 25, 0, 90, $H - 64, $ink, $body, '@afro.vanguard');
$fr = 'www.afrovanguard.org.ng'; $b = imagettfbbox(25, 0, $body, $fr);
imagettftext($im, 25, 0, $W - 90 - ($b[2] - $b[0]), $H - 64, $ink, $body, $fr);

imagepng($im, $file);
imagedestroy($im);
$send($file);
