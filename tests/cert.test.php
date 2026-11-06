<?php
/**
 * tests/cert.test.php — the Academy certificate (academy/_cert.php), per
 * HANDOFF-ACADEMY-CERTIFICATE.md §7 A-08: the fitting rules as a pure
 * function, the course/programme copy, the PNG itself (signature, 3200×2262,
 * the serial printed in full), the plain-text fallback, and that the printed
 * serial verifies.
 */
declare(strict_types=1);
require_once AV_ROOT . '/academy/_cert.php';

/* ══ Fitting, pure: a fake measure (every character 0.5 em wide) ══════════ */

$fake = static fn(string $t, float $px): float => mb_strlen($t) * $px * 0.5;
$fitName = static fn(string $n, callable $m) => avac_cert_fit($n, $m, 700, 108, 72, 4, 2, 2);
$fitTitle = static fn(string $t, callable $m) => avac_cert_fit($t, $m, 700, 46, 34, 2, 2, 3);

$r = $fitName('Ada Obi', $fake);
ck('cert fit: a short name is one line at 108', $r['size'] === 108 && $r['lines'] === ['Ada Obi']);
$r = $fitName('Adaeze Okonkwobello', $fake);   // 19 chars × 54 = 1026 > 700: wraps at 108
ck('cert fit: a name too wide for one line wraps at the last space, still at 108', $r['size'] === 108 && $r['lines'] === ['Adaeze', 'Okonkwobello']);
$r = $fitName('Abcdefghijklm Nopqrstuvwxyz', $fake);   // 13 chars: 702 at 108, 676 at 104
ck('cert fit: a word too wide at 108 steps the name down by 4', $r['size'] === 104 && count($r['lines']) === 2);
$word30 = str_repeat('Abcdefghij', 3);
$r = $fitName($word30, $fake);   // 30 × 36 = 1080 at 72: scaled to fit, floor(72 × 700/1080) = 46
ck('cert fit: a single word wider than 700 at 72 is scaled to fit, not cut', $r['size'] === 46 && $r['lines'] === [$word30] && $r['width'] <= 700);
$r = $fitName("  Ngozi \n\t  Eze  ", $fake);
ck('cert fit: whitespace is trimmed and collapsed, case kept', $r['lines'] === ['Ngozi Eze']);
$r = $fitTitle(str_repeat('Word ', 40), $fake);
ck('cert fit: a very long title uses three lines at the floor or less, never more', count($r['lines']) <= 3 && $r['size'] <= 34
    && implode(' ', $r['lines']) === trim(str_repeat('Word ', 40)));
$r = $fitTitle('Leading Change in Local Government Areas and Wards', $fake);   // 50 chars: 2 lines at 46
ck('cert fit: a title wraps to two lines at 46 before stepping down', $r['size'] === 46 && count($r['lines']) === 2);

/* Collision guard: pull the limit up so it must act; the name gives way first, to its floor. */
$L = avac_cert_layout('Adaeze Okonkwobello', 'A Title', 'course', $fake, 500);
ck('cert guard: the name gives way first, the title is untouched', $L['name']['size'] < 108 && $L['title']['size'] === 46 && $L['titleBottom'] <= 500);
$L = avac_cert_layout('Adaeze Okonkwobello', 'A Title', 'course', $fake, 300);
ck('cert guard: then the title, each only to its floor', $L['name']['size'] === 72 && $L['title']['size'] === 34);
$L = avac_cert_layout('Adaeze Okonkwobello', 'A Title', 'course', $fake);
ck('cert guard: normally nothing gives way', $L['name']['size'] === 108 && $L['title']['size'] === 46 && $L['titleBottom'] <= 869 - 48);
ck('cert layout: the rule sits 40 below a two-line name (455 in the reference)', abs($L['ruleTop'] - 455) < 1);

/* ══ Copy: course vs programme (§5) ═════════════════════════════════════ */

$c = avac_cert_copy('course'); $p = avac_cert_copy('programme');
ck('cert copy: course says "the course", not "the programme"', $c['leadin'] === 'for successfully completing the course' && $c['subtitle'] === 'of Completion');
ck('cert copy: programme says every course in the programme', $p['leadin'] === 'for successfully completing every course in the programme' && $p['subtitle'] === 'of Programme Completion');
$src = (string) file_get_contents(AV_ROOT . '/academy/_cert.php');
ck('cert: no DejaVu stand-ins remain (A-02)', !str_contains($src, 'display.ttf') && !str_contains($src, 'body.ttf'));
ck('cert: the old tagline is gone', !str_contains($src, 'incorruptible'));
ck('cert callers: the course passes the course variant', str_contains((string) file_get_contents(AV_ROOT . '/academy/certificate.php'), "avac_cert_png((string) \$user['name'], 'course',"));
ck('cert callers: the programme passes the programme variant', str_contains((string) file_get_contents(AV_ROOT . '/academy/programme.php'), "avac_cert_png((string) \$u['name'], 'programme',"));
ck('cert: signatory defaults to D-2 unless configured', avac_cert_signatory() === [
    defined('ACADEMY_CERT_SIGNATORY') ? (string) constant('ACADEMY_CERT_SIGNATORY') : 'Van. Ujagbe Onofua',
    defined('ACADEMY_CERT_SIGNATORY_TITLE') ? (string) constant('ACADEMY_CERT_SIGNATORY_TITLE') : 'COO, Afrovanguard']);
ck('cert config: config.example.php documents the signatory', str_contains((string) file_get_contents(AV_ROOT . '/config.example.php'), "define('ACADEMY_CERT_SIGNATORY',"));

/* ══ The PNG, with the real fonts ═══════════════════════════════════════ */

if (avac_cert_font_paths() === null) {
    ck('cert: GD with FreeType and the brand fonts are available to the suite', false);
} else {
    $serial = 'AV-3F9A1C7B2E04-2026';
    $trace = null;
    $png = (string) avac_cert_render('Adaeze Okonkwo-Bello', 'course', 'Foundations of Ethical Leadership', '2026-11-06 10:00:00', $serial, $trace);
    ck('cert png: PNG signature', substr($png, 0, 8) === "\x89PNG\r\n\x1a\n");
    $sz = getimagesizefromstring($png);
    ck('cert png: 3200×2262 (A-03)', $sz !== false && $sz[0] === 3200 && $sz[1] === 2262);
    ck('cert png: the serial is printed in full, as its own run', in_array($serial, $trace, true));
    ck('cert png: date is day first', in_array('6 November 2026', $trace, true));
    ck('cert png: course copy drawn', in_array('of Completion', $trace, true) && in_array('for successfully completing the course', $trace, true)
        && in_array('This is to proudly certify that', $trace, true));
    $trace = null;
    avac_cert_render('Adaeze Okonkwo-Bello', 'programme', 'Civic Pathway', '2026-11-06', 'AVP-8B21D04E9C3A-2026', $trace);
    ck('cert png: programme copy drawn', in_array('of Programme Completion', $trace, true)
        && in_array('for successfully completing every course in the programme', $trace, true) && in_array('AVP-8B21D04E9C3A-2026', $trace, true));

    /* A-04 stress inputs, measured in Cormorant Garamond 600 as drawn. */
    $m = static fn(string $t, float $px): float => avac_cert_measure(AV_ROOT . AVAC_CERT_FONTS['serif600'], $t, $px);
    $ref = avac_cert_layout('Adaeze Okonkwo-Bello', 'Foundations of Ethical Leadership', 'course', $m);
    ck('cert fit: the reference name is two lines at 108', $ref['name']['size'] === 108 && $ref['name']['lines'] === ['Adaeze', 'Okonkwo-Bello']);
    $long = 'Oluwaseyifunmi Adebanjo-Ogunleye-Nwachukwu';
    $title = 'Community Influencers Masterclass: Leading Change in Local Government Areas';
    $s = avac_cert_layout($long, $title, 'course', $m);
    ck('cert stress: long name is two lines, whole, within 700', count($s['name']['lines']) === 2 && implode(' ', $s['name']['lines']) === $long
        && $s['name']['width'] <= 700 && $s['name']['size'] < 72);
    ck('cert stress: long title is two lines between 34 and 46, within 700', count($s['title']['lines']) === 2 && $s['title']['size'] >= 34
        && $s['title']['size'] <= 46 && $s['title']['width'] <= 700 && implode(' ', $s['title']['lines']) === $title);
    ck('cert stress: nothing reaches the signature band', $s['titleBottom'] <= 869 - 48);
    $w = avac_cert_layout('Oluwaseyifunmiadebanjoogunleye', $title, 'course', $m);
    ck('cert stress: a single 30-letter word is one line, scaled within 700', $w['name']['lines'] === ['Oluwaseyifunmiadebanjoogunleye']
        && $w['name']['width'] <= 700 && $w['titleBottom'] <= 869 - 48);
    ck('cert: a decomposed name is composed before drawing (A-05)', avac_cert_clean("O\u{323}la\u{301}") === "\u{1ECC}l\u{E1}");
}

/* ══ A-07: the plain-text fallback when the renderer cannot draw ═══════ */

$fb = (string) file_get_contents(AV_ROOT . '/academy/_cert.php');
ck('cert fallback: still sends the plain-text line', str_contains($fb, "header('Content-Type: text/plain; charset=utf-8');")
    && str_contains($fb, "avac_cert_copy(\$variant)['kind'] . ' ' . \$serial . ' — ' . \$learner . ' — ' . \$title"));

/* ══ A-06: the printed serial verifies ════════════════════════════════════ */

$db = Database::pdo();
$ac = new AcademyRepository();
$cslug = $ac->save(['title' => 'Cert Verify Course', 'summary' => 's', 'status' => 'published', 'access_type' => 'open']);
$course = $ac->bySlug($cslug);
$db->prepare("INSERT INTO lms_users (name, email, password_hash) VALUES (?,?,?)")->execute(['Adaeze Okonkwo-Bello', 'cert-verify@x.co', 'x']);
$uid = (int) $db->lastInsertId();
$serial = 'AV-' . strtoupper(bin2hex(random_bytes(6))) . '-' . date('Y');   // the issuing format, LmsRepository::issueCertificate
$db->prepare('INSERT INTO certificates (user_id, course_id, serial) VALUES (?,?,?)')->execute([$uid, (int) $course['id'], $serial]);
ck('cert verify: the issued serial looks up', ((new LmsRepository())->certificateBySerial($serial)['learner'] ?? '') === 'Adaeze Okonkwo-Bello');
$html = render_page(AV_ROOT . '/academy/verify.php', ['serial' => $serial]);
ck('cert verify: pasting the serial into /academy/verify verifies', str_contains($html, 'Verified') && str_contains($html, $serial));

/* The crest (CERT-03): the artwork the design owner supplied, committed, at the
   size the handoff names, with a transparent ground so the pennant shows round it. */
$crest = __DIR__ . '/../assets/site/academy-crest.png';
ck('cert: the Academy crest is committed', is_file($crest));
$ci = is_file($crest) ? getimagesize($crest) : false;
ck('cert: the crest is 795x864, as supplied', $ci !== false && $ci[0] === 795 && $ci[1] === 864);
if ($ci !== false && function_exists('imagecreatefrompng')) {
    $cimg = imagecreatefrompng($crest);
    ck('cert: and its corners are transparent', ((imagecolorat($cimg, 0, 0) >> 24) & 0x7F) === 127 && ((imagecolorat($cimg, 794, 0) >> 24) & 0x7F) === 127);
    imagedestroy($cimg);
}
