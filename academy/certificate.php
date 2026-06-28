<?php
/**
 * academy/certificate.php — branded completion certificate (PNG).
 *   /academy/<course>/certificate   (requires sign-in + 100% completion)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$ac = new AcademyRepository();
$lms = new LmsRepository();
$course = $slug ? $ac->bySlug($slug) : null;
$user = LmsAuth::user();

if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }
if (!$user) { header('Location: ' . academy_url($slug . '/learn')); exit; }
$cert = $lms->issueCertificate((int) $user['id'], (int) $course['id']);
if (!$cert) { http_response_code(403); require_once AV_ROOT . '/lib/errors.php'; av_error_render(403); exit; }

$font = AV_ROOT . '/assets/fonts/display.ttf';
$fontUI = AV_ROOT . '/assets/fonts/body.ttf';
if (!extension_loaded('gd') || !is_file($font)) {
    header('Content-Type: text/plain'); echo 'Certificate ' . $cert['serial'] . ' — ' . $user['name'] . ' — ' . $course['title']; exit;
}

$W = 1600; $H = 1131; $im = imagecreatetruecolor($W, $H);
$cream = imagecolorallocate($im, 253, 252, 248);
$ink = imagecolorallocate($im, 17, 24, 39);
$gold = imagecolorallocate($im, 200, 134, 10);
$goldlt = imagecolorallocate($im, 243, 180, 22);
$muted = imagecolorallocate($im, 120, 120, 126);
imagefilledrectangle($im, 0, 0, $W, $H, $cream);
// double gold border
imagesetthickness($im, 6); imagerectangle($im, 40, 40, $W - 40, $H - 40, $goldlt);
imagesetthickness($im, 2); imagerectangle($im, 58, 58, $W - 58, $H - 58, $gold);

$center = function ($txt, $size, $y, $col, $f) use ($im, $W) {
    $bb = imagettfbbox($size, 0, $f, $txt); $w = $bb[2] - $bb[0];
    imagettftext($im, $size, 0, (int) (($W - $w) / 2), $y, $col, $f, $txt);
};
$center('AFROVANGUARD ACADEMY', 20, 150, $gold, $fontUI);
$center('Certificate of Completion', 58, 250, $ink, $font);
$center('This certifies that', 22, 360, $muted, $fontUI);
$center($user['name'], 70, 470, $ink, $font);
imagefilledrectangle($im, (int) ($W / 2 - 220), 500, (int) ($W / 2 + 220), 503, $goldlt);
$center('has successfully completed the programme', 22, 575, $muted, $fontUI);
// course title (wrap if long)
$title = $course['title']; $size = 46; $bb = imagettfbbox($size, 0, $font, $title);
if (($bb[2] - $bb[0]) > $W - 320) $size = 36;
$center($title, $size, 650, $gold, $font);

$date = date('F j, Y', strtotime($cert['issued_at']));
imagettftext($im, 18, 0, 220, 880, $ink, $fontUI, $date);
imagettftext($im, 14, 0, 220, 910, $muted, $fontUI, 'Date of issue');
imagesetthickness($im, 2); imageline($im, 220, 845, 560, 845, $muted);

$verify = rtrim(SITE_URL, '/') . '/academy/verify.php?serial=' . urlencode($cert['serial']);
$serialTxt = 'Serial ' . $cert['serial'];
$bb = imagettfbbox(16, 0, $fontUI, $serialTxt);
imagettftext($im, 16, 0, $W - 220 - ($bb[2] - $bb[0]), 880, $ink, $fontUI, $serialTxt);
$vtxt = 'Verify at afrovanguard.org.ng/academy/verify';
$bb = imagettfbbox(13, 0, $fontUI, $vtxt);
imagettftext($im, 13, 0, $W - 220 - ($bb[2] - $bb[0]), 910, $muted, $fontUI, $vtxt);
imageline($im, $W - 560, 845, $W - 220, 845, $muted);

$center('Raising one million incorruptible leaders for Africa.', 16, 1010, $muted, $fontUI);

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="afrovanguard-certificate-' . $slug . '.png"');
imagepng($im); imagedestroy($im);
