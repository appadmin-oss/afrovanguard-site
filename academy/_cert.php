<?php
/**
 * academy/_cert.php — the one certificate renderer (PNG, GD), for a course
 * certificate and a programme certificate alike, so the two cannot drift
 * into different designs or different verify lines.
 */
declare(strict_types=1);

/**
 * @param string $kind  'Certificate of Completion' | 'Programme Certificate'
 * @param string $line  e.g. 'has successfully completed the programme'
 */
function avac_cert_png(string $learner, string $kind, string $line, string $title, string $issuedAt, string $serial, string $file): void
{
    $font = AV_ROOT . '/assets/fonts/display.ttf';
    $fontUI = AV_ROOT . '/assets/fonts/body.ttf';
    if (!extension_loaded('gd') || !is_file($font)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $kind . ' ' . $serial . ' — ' . $learner . ' — ' . $title;
        return;
    }
    $W = 1600; $H = 1131; $im = imagecreatetruecolor($W, $H);
    $cream = imagecolorallocate($im, 253, 252, 248);
    $ink = imagecolorallocate($im, 17, 24, 39);
    $gold = imagecolorallocate($im, 200, 134, 10);
    $goldlt = imagecolorallocate($im, 243, 180, 22);
    $muted = imagecolorallocate($im, 120, 120, 126);
    imagefilledrectangle($im, 0, 0, $W, $H, $cream);
    imagesetthickness($im, 6); imagerectangle($im, 40, 40, $W - 40, $H - 40, $goldlt);
    imagesetthickness($im, 2); imagerectangle($im, 58, 58, $W - 58, $H - 58, $gold);
    $center = static function (string $txt, int $size, int $y, $col, string $f) use ($im, $W): void {
        $bb = imagettfbbox($size, 0, $f, $txt); $w = $bb[2] - $bb[0];
        imagettftext($im, $size, 0, (int) (($W - $w) / 2), $y, $col, $f, $txt);
    };
    $center('AFROVANGUARD ACADEMY', 20, 150, $gold, $fontUI);
    $center($kind, 58, 250, $ink, $font);
    $center('This certifies that', 22, 360, $muted, $fontUI);
    $center($learner, 70, 470, $ink, $font);
    imagefilledrectangle($im, (int) ($W / 2 - 220), 500, (int) ($W / 2 + 220), 503, $goldlt);
    $center($line, 22, 575, $muted, $fontUI);
    $size = 46; $bb = imagettfbbox($size, 0, $font, $title);
    if (($bb[2] - $bb[0]) > $W - 320) $size = 36;
    $center($title, $size, 650, $gold, $font);
    imagettftext($im, 18, 0, 220, 880, $ink, $fontUI, date('F j, Y', strtotime($issuedAt) ?: time()));
    imagettftext($im, 14, 0, 220, 910, $muted, $fontUI, 'Date of issue');
    imagesetthickness($im, 2); imageline($im, 220, 845, 560, 845, $muted);
    $serialTxt = 'Serial ' . $serial;
    $bb = imagettfbbox(16, 0, $fontUI, $serialTxt);
    imagettftext($im, 16, 0, $W - 220 - ($bb[2] - $bb[0]), 880, $ink, $fontUI, $serialTxt);
    $vtxt = 'Verify at afrovanguard.org.ng/academy/verify';
    $bb = imagettfbbox(13, 0, $fontUI, $vtxt);
    imagettftext($im, 13, 0, $W - 220 - ($bb[2] - $bb[0]), 910, $muted, $fontUI, $vtxt);
    imageline($im, $W - 560, 845, $W - 220, 845, $muted);
    $center('Raising one million incorruptible leaders for Africa.', 16, 1010, $muted, $fontUI);
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9.-]/', '', strtolower($file)) . '"');
    imagepng($im); imagedestroy($im);
}
