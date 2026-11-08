<?php
/**
 * tests/thumbs-search.test.php — images at the size they are shown, and the
 * Academy's search.
 *
 * The admin console's Academy list drew every course cover at upload size as
 * a 64-pixel thumbnail: 52 MB for 46 courses on the test catalogue. These
 * pin the thumbnail service and that the lists use it, and the search the
 * Studio uses for courses, items and learners.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/academy/_avac.php';

/* ══ Thumbnails ══════════════════════════════════════════════════════════ */

ck('thumbs: widths snap up a fixed ladder', Thumbs::width(100) === 160 && Thumbs::width(321) === 640 && Thumbs::width(5000) === 1280);
ck('thumbs: Cloudinary resizes its own images', Thumbs::url('https://res.cloudinary.com/acme/image/upload/v1/a/b.jpg', 320) === 'https://res.cloudinary.com/acme/image/upload/w_320,c_limit,f_auto,q_auto/v1/a/b.jpg');
ck('thumbs: an existing Cloudinary transformation is left alone', Thumbs::url('https://res.cloudinary.com/acme/image/upload/w_100,c_fill/v1/b.jpg', 320) === 'https://res.cloudinary.com/acme/image/upload/w_100,c_fill/v1/b.jpg');
ck('thumbs: another host is unchanged', Thumbs::url('https://example.com/a.jpg', 320) === 'https://example.com/a.jpg');
ck('thumbs: a site image is unchanged', Thumbs::url('/Images/a.jpg', 320) === '/Images/a.jpg');

$tdir = AV_ROOT . '/uploads/diary';
if (!is_dir($tdir)) @mkdir($tdir, 0775, true);
$tfile = $tdir . '/thumbtest-' . getmypid() . '.png';
$im = imagecreatetruecolor(1800, 900); imagefilledrectangle($im, 0, 0, 1799, 899, imagecolorallocate($im, 200, 120, 10)); imagepng($im, $tfile); imagedestroy($im);
$tpath = '/uploads/diary/' . basename($tfile);
ck('thumbs: one of our uploads goes through thumb.php', Thumbs::url($tpath, 300) === '/thumb.php?w=320&src=' . rawurlencode($tpath));
$made = Thumbs::make($tpath, 320);
ck('thumbs: it is made at the width asked', $made !== null && (getimagesize($made[0])[0] ?? 0) === 320);
ck('thumbs: and is much smaller than the upload', $made !== null && filesize($made[0]) < filesize($tfile));
ck('thumbs: made once, then reused', $made !== null && Thumbs::make($tpath, 320)[0] === $made[0]);
$small = $tdir . '/thumbsmall-' . getmypid() . '.png';
$im = imagecreatetruecolor(200, 100); imagepng($im, $small); imagedestroy($im);
ck('thumbs: never upscaled — a small image is served as it is', (Thumbs::make('/uploads/diary/' . basename($small), 640)[0] ?? '') === realpath($small));
@unlink($small);
ck('thumbs: a path outside uploads is refused', Thumbs::localImage('/uploads/../lib/bootstrap.php') === null && Thumbs::localImage('/lib/x.png') === null && Thumbs::make('/uploads/diary/../../config.php', 320) === null);
ck('thumbs: a thumbnail is not a source', $made !== null && Thumbs::localImage('/uploads/thumbs/320/' . basename($made[0])) === null);
ck('thumbs: a srcset lists the ladder', substr_count(Thumbs::srcset($tpath, [320, 640]), 'thumb.php') === 2);
$card = avac_cover(['cover_url' => $tpath, 'title' => 'x'], 'avac-card-img');
ck('thumbs: catalogue cards load a thumbnail with a srcset, lazily', str_contains($card, 'src="/thumb.php?w=640') && str_contains($card, 'srcset=') && str_contains($card, 'loading="lazy"'));
ck('thumbs: the console list uses the thumbnail', str_contains((string) file_get_contents(AV_ROOT . '/admin/app.js'), 'c.cover_thumb || c.cover_url') && str_contains((string) file_get_contents(AV_ROOT . '/admin/api.php'), "'cover_thumb'"));
ck('thumbs: the editor is not loaded on every console page', !str_contains((string) file_get_contents(AV_ROOT . '/admin/index.php'), '<script src="/assets/vendor/tinymce/tinymce.min.js">'));
foreach ([$tfile, $made[0] ?? ''] as $f) if ($f !== '' && is_file($f) && str_contains($f, 'uploads')) @unlink($f);

/* ══ Search ══════════════════════════════════════════════════════════════ */

$ac = new AcademyRepository(); $lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$ss = $ac->save(['title' => 'Searchable Budgeting Basics', 'summary' => 'Money for clubs', 'status' => 'draft', 'access_type' => 'open', 'category' => 'Leadership']);
$sc = $ac->bySlug($ss, true);
$sm = $lms->addModule((int) $sc['id'], 'Week one');
$lms->saveLesson(['module_id' => $sm, 'title' => 'Reading a 100% honest balance sheet', 'slug' => '', 'body_html' => '<p>x</p>']);
Database::pdo()->prepare('INSERT INTO lms_users (name, email, password_hash, role) VALUES (?,?,?,?)')->execute(['Zainab Searchtest', 'zainab.searchtest@example.com', 'x', 'learner']);
$zu = $lms->userByEmail('zainab.searchtest@example.com');
$lms->enrol((int) $zu['id'], (int) $sc['id']);

$r = $cw->search('budgeting basics', true);
ck('search: every word must match a course', count($r['courses']) === 1 && $r['courses'][0]['slug'] === $ss);
ck('search: drafts are found too (it is the authors’ search)', $r['courses'][0]['status'] === 'draft');
ck('search: an item is found by its title, with its course', ($cw->search('balance sheet')['items'][0]['course_slug'] ?? '') === $ss);
ck('search: a % is a percent sign, not a wildcard', count($cw->search('100%')['items']) === 1 && count($cw->search('9%')['items']) === 0);
ck('search: learners by name or email, only when allowed', count($cw->search('searchtest', true)['learners']) === 1 && $cw->search('searchtest', false)['learners'] === []);
ck('search: a learner result says how many courses', (int) ($cw->search('zainab', true)['learners'][0]['courses'] ?? 0) === 1);
ck('search: nothing typed, nothing found', $cw->search('   ', true) === ['courses' => [], 'items' => [], 'learners' => []]);
ck('search: the console only gives learners to management roles', str_contains((string) file_get_contents(AV_ROOT . '/admin/api.php'), "\$people = in_array(function_exists('av_admin_role') ? av_admin_role() : 'superadmin', ['admin', 'superadmin'], true);"));

/* ══ The roster searches on the server ═══════════════════════════════════ */

ck('roster: a name search finds the learner', count($lms->roster((int) $sc['id'], 300, 'zainab')) === 1);
ck('roster: a miss finds nobody', $lms->roster((int) $sc['id'], 300, 'nobody-here') === []);
ck('roster: each row says where the learner is', ($lms->roster((int) $sc['id'])[0]['state'] ?? '') === 'not_started');
ck('roster: and can be filtered by it', count($lms->roster((int) $sc['id'], 300, '', 'not_started')) === 1 && $lms->roster((int) $sc['id'], 300, '', 'certified') === []);
