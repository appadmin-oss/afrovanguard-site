<?php
/**
 * tests/cardphoto.test.php — the card photo (lib/CardPhoto.php).
 *
 * A photo goes to a printer, so: it must be big enough, cut to the panel's
 * shape, stripped of its camera metadata, and the model's measurement of
 * where the head is must be checked before anybody frames by it.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/IdCard.php';

$cpPdo = Database::pdo();
$cpPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
      ->execute(['Photo Member', 'photo.member.' . bin2hex(random_bytes(3)) . '@example.test', 'x', 'member', 'active']);
$cpId = (int) $cpPdo->lastInsertId();

/* A JPEG of a given size, with an EXIF-ish APP1 marker spliced in. */
$cpJpeg = static function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 160, 40));
    $f = tempnam(sys_get_temp_dir(), 'cpt');
    imagejpeg($im, $f, 90); imagedestroy($im);
    $b = (string) file_get_contents($f);
    $exif = "\xFF\xE1" . pack('n', 2 + 14) . "Exif\0\0GPSTEST!";
    file_put_contents($f, substr($b, 0, 2) . $exif . substr($b, 2));
    return $f;
};
$cpFile = static fn(string $path): array => ['name' => 'p.jpg', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];

/* ── Store ─────────────────────────────────────────────────────────────── */
$small = $cpJpeg(500, 380);
$r = CardPhoto::save($cpId, $cpFile($small), 'test');
ck('photo: too small to print sharply is refused, with the spec’s words', !$r['ok'] && $r['error'] === CardPhoto::TOO_SMALL);

$big = $cpJpeg(1600, 1600);
$r = CardPhoto::save($cpId, $cpFile($big), 'test');
ck('photo: a good photo is stored and becomes the card photo', $r['ok'] && CardPhoto::of($cpId)['photo'] === $r['url']);
$local = AV_ROOT . parse_url($r['url'] ?? '', PHP_URL_PATH);
$size = is_file($local) ? getimagesize($local) : [0, 0];
ck('photo: cut to the panel’s shape and stored at 600 dpi (1114 × 830)', $size[0] === 1114 && abs($size[0] / max(1, $size[1]) - CardPhoto::ASPECT) < 0.01);
ck('photo: re-encoded — the camera’s metadata is gone', is_file($local) && !str_contains((string) file_get_contents($local), 'GPSTEST'));
ck('card: the card prints it', IdCard::forMember($cpId)['photo_url'] === $r['url']);

$txt = tempnam(sys_get_temp_dir(), 'cpt'); file_put_contents($txt, "<?php echo 'x';");
ck('photo: anything that is not a photo is refused by its bytes', !CardPhoto::save($cpId, $cpFile($txt) + ['name' => 'x.jpg'], 'test')['ok']);

ck('photo: removed, the card goes back to initials', CardPhoto::clear($cpId, 'test')['ok'] && IdCard::forMember($cpId)['photo_url'] === null);
if (is_file($local)) @unlink($local);
foreach ([$small, $big, $txt] as $f) @unlink($f);

/* ── Title ─────────────────────────────────────────────────────────────── */
ck('title: set, and printed under the name', CardPhoto::setRole($cpId, '  Chief   Servant ', 'test')['ok'] && IdCard::forMember($cpId)['role'] === 'Chief Servant');
ck('title: one line on the card — over 40 characters is refused', !CardPhoto::setRole($cpId, str_repeat('x', 41), 'test')['ok']);

/* ── Delivery ──────────────────────────────────────────────────────────── */
AvRules::save(['cards.photo_enhance' => '1', 'cards.photo_bg_removal' => '0'], 'test');
ck('photo: a Cloudinary photo is delivered improved',
   CardPhoto::deliver('https://res.cloudinary.com/av/image/upload/v1/cards/a.jpg') === 'https://res.cloudinary.com/av/image/upload/e_improve:30/f_png/v1/cards/a.jpg');
AvRules::save(['cards.photo_bg_removal' => '1'], 'test');
ck('photo: …and, when switched on, with its background removed so the member stands on the card’s gold',
   str_contains(CardPhoto::deliver('https://res.cloudinary.com/av/image/upload/v1/cards/a.jpg'), '/e_improve:30/e_background_removal/f_png/'));
AvRules::save(['cards.photo_bg_removal' => '0'], 'test');
ck('photo: a local photo is delivered as stored', CardPhoto::deliver('/uploads/cards/a.jpg') === '/uploads/cards/a.jpg');

/* ── Measure: the model's answer is checked before anything frames by it ── */
$good = ['subjectFound' => true, 'people' => 1, 'kind' => 'headshot', 'head' => [100, 300, 600, 700], 'face' => [250, 350, 590, 650],
         'leftEye' => [380, 420], 'rightEye' => [380, 580], 'shoulders' => ['y' => 700, 'left' => 100, 'right' => 900]];
$c = CardPhoto::clean($good);
ck('measure: a plausible answer becomes fractions of the image', $c['ok'] && abs($c['geom']['head']['y0'] - 0.1) < 1e-9 && count($c['geom']['eyes']) === 2);
ck('measure: a face outside its head is rejected, with the reason told to the second look',
   !CardPhoto::clean(['face' => [0, 0, 900, 900]] + $good)['ok']);
ck('measure: no single face is an answer, not an error', CardPhoto::clean(['subjectFound' => false, 'people' => 3])['geom']['subject'] === false);

$asked = 0;
$GLOBALS['__card_photo_mock'] = static function (string $extra) use (&$asked, $good): array {
    $asked++;
    $bad = ['face' => [0, 0, 900, 900]] + $good;
    return ['ok' => true, 'text' => json_encode($asked === 1 ? $bad : $good)];
};
$m = CardPhoto::measure((string) file_get_contents($cpJpeg(800, 800)));
ck('measure: an implausible first answer gets a second look, told what was wrong', $m['ok'] && $asked === 2);
unset($GLOBALS['__card_photo_mock']);

/* ── From Google: only a real headshot, only when there is no photo ────── */
$gImg = $cpJpeg(1200, 1200);
$GLOBALS['__card_photo_fetch'] = static fn(string $u): string => str_contains($u, '=s1200') ? (string) file_get_contents($gImg) : '';
$headshot = ['subjectFound' => true, 'people' => 1, 'kind' => 'headshot', 'head' => [150, 330, 620, 670], 'face' => [300, 360, 610, 640],
             'leftEye' => [420, 430], 'rightEye' => [420, 570], 'shoulders' => null];
$letter = ['subjectFound' => false, 'people' => 0, 'kind' => 'none', 'head' => [0, 0, 1, 1], 'face' => [0, 0, 1, 1], 'leftEye' => [0, 0], 'rightEye' => [0, 0]];
$GLOBALS['__card_photo_mock'] = static fn(string $x): array => ['ok' => true, 'text' => json_encode($letter)];
$r = CardPhoto::fromGoogle($cpId, 'https://lh3.googleusercontent.com/a/abc=s96-c');
ck('google: the default letter avatar (no face) never lands on an ID card', !$r['ok'] && $r['why'] === 'no_single_face' && CardPhoto::of($cpId)['photo'] === '');
$GLOBALS['__card_photo_mock'] = static fn(string $x): array => ['ok' => true, 'text' => json_encode($headshot)];
ck('google: only Google’s own photo host is fetched', CardPhoto::fromGoogle($cpId, 'https://evil.example/a.jpg')['why'] === 'not_google');
$r = CardPhoto::fromGoogle($cpId, 'https://lh3.googleusercontent.com/a/abc=s96-c');
$gUrl = (string) CardPhoto::of($cpId)['photo'];
ck('google: a real headshot, asked for at 1200 px, framed head and shoulders, becomes the card photo',
   $r['ok'] && $gUrl !== '' && Prefs::get($cpId, 'card_photo_source', '') === 'google');
$gLocal = AV_ROOT . parse_url($gUrl, PHP_URL_PATH);
$gs = is_file($gLocal) ? getimagesize($gLocal) : [0, 0];
ck('google: …cut to the panel’s shape', abs($gs[0] / max(1, $gs[1]) - CardPhoto::ASPECT) < 0.01);
ck('google: a member with a photo keeps it — Google never replaces it', CardPhoto::fromGoogle($cpId, 'https://lh3.googleusercontent.com/a/abc=s96-c')['why'] === 'has_photo');
$f = CardPhoto::frame(['subject' => true, 'head' => ['x0' => 0.4, 'x1' => 0.6, 'y0' => 0.2, 'y1' => 0.45], 'eyes' => null], 2000, 2000);
ck('frame: NGG’s rule — the head is 56% of the frame’s height, 11% of it above the crown, the panel’s shape',
   abs(500 / $f['h'] - 0.56) < 0.01 && abs(($f['h'] * 0.11) - (400 - $f['y'])) < 2 && abs($f['w'] / $f['h'] - CardPhoto::ASPECT) < 0.01);
$cpPdo->exec("UPDATE lms_users SET role = 'learner' WHERE id = {$cpId}");
CardPhoto::clear($cpId, 'test');
ck('google: an Academy learner’s photo is left alone — cards are members’', CardPhoto::fromGoogle($cpId, 'https://lh3.googleusercontent.com/a/abc=s96-c')['why'] === 'not_member');
$cpPdo->exec("UPDATE lms_users SET role = 'member' WHERE id = {$cpId}");
unset($GLOBALS['__card_photo_mock'], $GLOBALS['__card_photo_fetch']);
if (is_file($gLocal)) @unlink($gLocal);
@unlink($gImg);
$gsrc = (string) file_get_contents(AV_ROOT . '/auth/google.php');
ck('google: sign-in hands the photo over after the redirect, so signing in never waits on it',
   str_contains($gsrc, 'register_shutdown_function') && str_contains($gsrc, 'fastcgi_finish_request') && str_contains($gsrc, 'CardPhoto::fromGoogle($uid, $pic)'));
ck('google: the id_token’s picture is read', str_contains((string) file_get_contents(AV_ROOT . '/lib/GoogleAuth.php'), "'picture'  => (string) (\$claims['picture'] ?? '')"));

/* ── A member's own photo ─────────────────────────────────────────────── */
$self = (string) file_get_contents(AV_ROOT . '/card/photo.php');
ck('self: a member changes THEIR card only — the id is the session’s, never the request’s',
   str_contains($self, '$id = (int) $me[\'id\'];') && !str_contains($self, "\$_POST['id']") && !str_contains($self, "\$_GET['id']"));
ck('self: members only, CSRF-checked and rate-limited', str_contains($self, "LmsAuth::rank('member')") && str_contains($self, 'av_csrf_require();') && str_contains($self, "av_rate_ok('card_photo_self_'"));
$att = (string) file_get_contents(AV_ROOT . '/portal/_card.php');
$pidx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('portal: Membership → Your card, for every member with a card — not only those the gate takes — offers “Add your photo”',
   str_contains($att, 'Add your photo') && str_contains($pidx, "require __DIR__ . '/_card.php';") && str_contains($pidx, "'/assets/site/avc-card.css'"));

$js = (string) file_get_contents(AV_ROOT . '/assets/site/avc-photo.js');
ck('desk: the crop editor is locked to the panel’s shape and refuses a crop that would print soft',
   str_contains($js, 'aspectRatio: ASPECT') && str_contains($js, 'c.width >= MIN_W && c.height >= MIN_H'));
ck('desk: frames head and shoulders by NGG’s rule (head 56% of the height, 11% above the crown)',
   str_contains($js, 'var HEAD = 0.56, TOP = 0.11;'));

$cpPdo->exec("DELETE FROM lms_users WHERE id = {$cpId}");
