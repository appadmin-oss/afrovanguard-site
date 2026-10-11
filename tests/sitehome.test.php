<?php
/**
 * tests/sitehome.test.php — the homepage hero as content (lib/SiteHome.php),
 * and the media library under it (lib/Media.php).
 */
declare(strict_types=1);
$root = dirname(__DIR__);

/* ── the shipped hero IS the defaults: publishing them changes nothing ── */
$idx = (string) file_get_contents("$root/index.html");
$a = strpos($idx, SiteHome::MARK_OPEN); $b = strpos($idx, SiteHome::MARK_CLOSE);
ck('home: index.html carries the cms:hero markers', $a !== false && $b !== false && $b > $a);
$block = trim(substr($idx, $a + strlen(SiteHome::MARK_OPEN), $b - $a - strlen(SiteHome::MARK_OPEN)));
ck('home: index.html\'s hero is exactly what the defaults render', $block === SiteHome::heroHtml(SiteHome::clean(SiteHome::defaults())['doc']));

/* ── validation ── */
$d = SiteHome::defaults();
$bad = $d; $bad['slides'][0]['href'] = 'javascript:alert(1)';
ck('home: a javascript: link is refused', !SiteHome::clean($bad)['ok']);
$bad = $d; $bad['slides'][1]['image'] = 'data:image/png;base64,AAAA';
ck('home: a data: image is refused', !SiteHome::clean($bad)['ok']);
$bad = $d; foreach ($bad['slides'] as &$s) $s['hidden'] = true; unset($s);
ck('home: a carousel with every slide hidden is refused', !SiteHome::clean($bad)['ok']);
$bad = $d; $bad['mode'] = 'video';
ck('home: video mode with no video is refused', !SiteHome::clean($bad)['ok']);
$bad['video']['src'] = 'https://www.youtube.com/watch?v=abc';
ck('home: a YouTube link is refused for the background video', !SiteHome::clean($bad)['ok']);
$x = $d; $x['slides'][0]['title'] = '<script>x</script>Title';
ck('home: tags are stripped from text', SiteHome::clean($x)['doc']['slides'][0]['title'] === 'xTitle');
$x = $d; $x['slides'][] = ['image' => '', 'title' => ''];
ck('home: an empty new row from the form is ignored', count(SiteHome::clean($x)['doc']['slides']) === 6);

/* ── rendering ── */
$v = $d; $v['mode'] = 'video'; $v['video']['src'] = '/uploads/media/2026/10/anniv.mp4'; $v['video']['href'] = '/give/';
$vh = SiteHome::heroHtml(SiteHome::clean($v)['doc']);
ck('home: video mode renders the video, its link and the poster as the first picture',
   str_contains($vh, 'data-mode="video"') && str_contains($vh, 'data-video="/uploads/media/2026/10/anniv.mp4"')
   && str_contains($vh, 'data-video-href="/give/"') && substr_count($vh, 'class="avh-hero-img') === 1 && !str_contains($vh, 'data-avh-dot="0"'));
$h = $d; $h['slides'][2]['hidden'] = true; $h['interval'] = 9;
$hh = SiteHome::heroHtml(SiteHome::clean($h)['doc']);
ck('home: a hidden slide is not rendered, and the dots count the rest', substr_count($hh, 'class="avh-hero-img') === 5 && str_contains($hh, 'Story 5 of 5') && !str_contains($hh, '/Images/alimosho.jpg'));
ck('home: the interval travels to the page', str_contains($hh, 'data-interval="9"'));
$q = $d; $q['slides'][0]['title'] = 'Say "hello" & <b>';
ck('home: text is escaped in attributes and content', str_contains(SiteHome::heroHtml(SiteHome::clean($q)['doc']), 'Say &quot;hello&quot; &amp;'));

/* ── draft → publish → history, on a copy of the page ── */
$tmp = sys_get_temp_dir() . '/av-home-' . getmypid() . '.html';
file_put_contents($tmp, $idx);
SiteHome::$indexPath = $tmp;
foreach ([SiteHome::KEY_DRAFT, SiteHome::KEY_LIVE, SiteHome::KEY_HIST] as $k) Database::metaSet($k, '');
$e = $d; $e['slides'][0]['title'] = 'Nine years. One movement.';
ck('home: a draft saves', SiteHome::saveDraft($e)['ok'] && SiteHome::hasUnpublished());
ck('home: a draft does not touch the page', file_get_contents($tmp) === $idx);
ck('home: the preview shows the draft', str_contains((string) SiteHome::previewHtml(SiteHome::draft()), 'Nine years. One movement.'));
ck('home: publishing writes the page', SiteHome::publish('test@x')['ok'] && str_contains((string) file_get_contents($tmp), '<h1 data-avh-hero-title>Nine years. One movement.</h1>'));
ck('home: the rest of the page is untouched', str_replace(SiteHome::heroHtml(SiteHome::live()), '', (string) file_get_contents($tmp)) === str_replace($block, '', $idx));
ck('home: publishing keeps the previous version', count(SiteHome::history()) === 1 && !SiteHome::hasUnpublished());
ck('home: an old version can be restored as the draft', SiteHome::restore(0)['ok'] && SiteHome::draft()['slides'][0]['title'] === 'Nine years of raising incorruptible leaders');
SiteHome::discardDraft();
ck('home: discarding the draft returns to what is live', SiteHome::draft()['slides'][0]['title'] === 'Nine years. One movement.');
file_put_contents($tmp, '<html><body>no markers</body></html>');
ck('home: a page without markers is refused, not guessed at', !SiteHome::writeIndex('<header></header>')['ok']);
@unlink($tmp); SiteHome::$indexPath = null;
foreach ([SiteHome::KEY_DRAFT, SiteHome::KEY_LIVE, SiteHome::KEY_HIST] as $k) Database::metaSet($k, '');

/* ── media: uploads in pieces, pictures processed, edits from the original ── */
$mr = sys_get_temp_dir() . '/av-media-' . getmypid();
@mkdir($mr . '/uploads', 0775, true);
Media::$root = $mr;
Media::ensure(); Database::pdo()->exec('DELETE FROM media_items');
$im = imagecreatetruecolor(1200, 800);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
imagefilledrectangle($im, 0, 0, 599, 799, imagecolorallocate($im, 30, 30, 200));
$src = "$mr/src.jpg"; imagejpeg($im, $src, 90);
$bytes = (string) file_get_contents($src);
$st = Media::start('Anniversary Photo.jpg', strlen($bytes), 'test');
ck('media: an upload starts', $st['ok'] && strlen($st['token']) === 32);
$parts = str_split($bytes, 20000);
foreach ($parts as $i => $p) Media::chunk($st['token'], $i, $p);
ck('media: a repeated piece is accepted once', Media::chunk($st['token'], 0, $parts[0])['ok']);
ck('media: a piece out of order is refused', !Media::chunk($st['token'], count($parts) + 3, 'x')['ok']);
$f = Media::finish($st['token'], 'Two colours');
ck('media: the picture is filed', $f['ok'] && $f['item']['kind'] === 'image' && $f['item']['width'] === 1200 && $f['item']['alt'] === 'Two colours');
$item = $f['item'];
ck('media: the original is kept', is_file($mr . $item['original']));
ck('media: web copies are made', count($item['variants']) >= 1 && is_file($mr . $item['variants'][0]['url']));
ck('media: a name is never the uploader\'s file name verbatim', str_starts_with(basename($item['url']), 'anniversary-photo-'));
$ed = Media::edit($item['id'], ['crop' => ['x' => 0, 'y' => 0, 'w' => 600, 'h' => 800], 'rotate' => 90], 'test');
/* Turn first, then crop in the turned picture's coordinates — what the editor shows. 1200×800 turned is 800×1200. */
ck('media: turn then crop makes the expected shape', $ed['ok'] && $ed['item']['width'] === 600 && $ed['item']['height'] === 800);
ck('media: an edit is a new file, and the old one is gone', $ed['item']['url'] !== $item['url'] && !is_file($mr . $item['url']) && is_file($mr . $ed['item']['url']));
$rv = Media::revert($item['id'], 'test');
ck('media: back to the original restores its size', $rv['ok'] && $rv['item']['width'] === 1200 && $rv['item']['edits'] === []);
$fake = "$mr/evil.jpg"; file_put_contents($fake, '<?php echo 1; ?>');
ck('media: a script named .jpg is refused by its content', !Media::import($fake, 'evil.jpg', 'test')['ok']);
ck('media: an unknown kind of file is refused at the start', !Media::start('notes.pdf', 100, 'test')['ok']);
ck('media: a huge picture is refused at the start', !Media::start('big.jpg', Media::IMAGE_MAX + 1, 'test')['ok']);
/* In use on the homepage → cannot be deleted. */
$use = SiteHome::defaults(); $use['slides'][0]['image'] = $rv['item']['url'];
SiteHome::saveDraft($use);
ck('media: a picture used on the homepage draft cannot be deleted', !Media::delete($item['id'])['ok']);
$ed2 = Media::edit($item['id'], ['rotate' => 180], 'test');
ck('media: editing a picture in use points the homepage draft at the new file', $ed2['repointed'] === 1 && SiteHome::draft()['slides'][0]['image'] === $ed2['item']['url']);
Database::metaSet(SiteHome::KEY_DRAFT, '');
ck('media: once unused it can be deleted, files and all', Media::delete($item['id'])['ok'] && !is_file($mr . $ed2['item']['url']) && !is_file($mr . $item['original']));
Media::$root = null;
exec('rm -rf ' . escapeshellarg($mr));

/* ── the Studio wiring ── */
$api = (string) file_get_contents("$root/admin/api.php");
ck('studio: publishing the homepage and deleting media are admin-only', preg_match("/managementOnly = \\[.*'home_publish', 'home_restore', 'media_delete'/s", $api) === 1);
foreach (['admin/site.js', 'admin/index.php'] as $f) {
    $src2 = (string) file_get_contents("$root/$f");
    $mine = $f === 'admin/index.php' ? substr($src2, strpos($src2, '<!-- HOMEPAGE'), strpos($src2, '<!-- DESIGN STUDIO') - strpos($src2, '<!-- HOMEPAGE')) : $src2;
    ck("studio: $f's content screens set no inline styles", !preg_match('/\sstyle\s*=|\.style\./', $mine));
}
