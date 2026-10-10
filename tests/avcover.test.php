<?php
/**
 * tests/avcover.test.php — the shared cover graphic (partials/av-cover.php),
 * the image every page draws for anything without a photo.
 */
declare(strict_types=1);

require_once AV_ROOT . '/partials/av-cover.php';

$T = '2026-10-07';

ck('cover: category inferred from the title', av_cover_infer('Techome Digital Skills Bootcamp') === 'bootcamp'
    && av_cover_infer('Carols at CACENTRE') === 'faith' && av_cover_infer('Something else') === 'general');
ck('cover: hash is the browser’s (FNV-1a over UTF-16)', av_cover_hash('Town halls') === 707498939);

$c = av_cover(['kind' => 'event', 'title' => 'Ikotun Community Town Hall', 'date' => $T, 'time' => '4:00pm', 'location' => 'Ikotun', 'today' => $T]);
ck('cover: an event today is live', str_contains($c, 'Live now') && str_contains($c, 'avcv--townhall'));
$c = av_cover(['kind' => 'event', 'title' => 'Leaders of Tomorrow Summit', 'date' => '2026-09-12', 'today' => $T]);
ck('cover: a past event is muted and says so', str_contains($c, 'is-past') && str_contains($c, 'Took place'));
$c = av_cover(['kind' => 'event', 'title' => 'Techome Bootcamp', 'date' => '2026-10-14', 'end' => '2026-10-18', 'today' => $T]);
ck('cover: a multi-day event shows its end', str_contains($c, '– 18 Oct') && !str_contains($c, 'is-past'));
$c = av_cover(['kind' => 'event', 'title' => 'Carols at CACENTRE', 'date' => '2026-12-25', 'today' => $T]);
ck('cover: holiday stripe and source on the day', str_contains($c, 'avcv-hol-christmas') && str_contains($c, 'Afrovanguard · Events · Christmas'));

$c = av_cover(['kind' => 'diary', 'title' => 'Appraisal Isn’t a Tribunal. It’s a Mirror.', 'readTime' => '3 min read', 'date' => '2026-08-08', 'tone' => 'light']);
ck('cover: diary meta is read time · date, light tone', str_contains($c, '3 min read · 8 Aug 2026') && str_contains($c, 'avcv--light')
    && str_contains($c, 'Afrovanguard · The Diary') && !str_contains($c, 'avcv-date'));
$c = av_cover(['kind' => 'appeal', 'title' => 'Twelve laptops for Techome', 'progress' => 140, 'raised' => '₦285,000', 'goal' => '₦3,000,000']);
ck('cover: an appeal has a bar, clamped to 100', str_contains($c, 'avcv-bar') && str_contains($c, '--cv-bar:100%')
    && str_contains($c, '₦285,000 of ₦3,000,000') && str_contains($c, 'Live appeal'));
$c = av_cover(['kind' => 'course', 'title' => 'Leadership I — Integrity under pressure', 'category' => 'leadership', 'badge' => '6 weeks', 'meta' => 'Beginner · Certificate']);
ck('cover: a course carries badge, kicker and meta', str_contains($c, 'avcv-badge">6 weeks') && str_contains($c, '>Leadership<')
    && str_contains($c, 'Afrovanguard · Academy') && str_contains($c, 'Beginner · Certificate'));

ck('cover: ratio sets the shape', str_contains(av_cover(['title' => 'x', 'ratio' => '9:16']), 'avcv--tall')
    && str_contains(av_cover(['title' => 'x', 'ratio' => '1.91:1']), 'avcv--wide')
    && str_contains(av_cover(['title' => 'x', 'ratio' => 'bogus']), '--cv-ar:16/9'));
$long = str_repeat('Long title ', 9);
ck('cover: long titles step down', str_contains(av_cover(['title' => $long]), 'is-longer'));
$c = av_cover(['kind' => '<x>', 'title' => '<b>x</b>" onload="y', 'badge' => '<i>']);
ck('cover: unknown kind falls back, everything escaped', str_contains($c, 'Afrovanguard · Events')
    && !str_contains($c, '<b>') && !str_contains($c, '<i>') && !str_contains($c, '" onload'));
ck('cover: one role=img with an accessible label', substr_count($c, 'role="img"') === 1 && str_contains($c, 'aria-label="'));

/* the browser twin keeps the same tables */
$js = (string) file_get_contents(AV_ROOT . '/assets/site/avcv.js');
foreach (array_keys(av_cover_cats()) as $k) $okc = ($okc ?? true) && str_contains($js, $k . ': [');
ck('cover: avcv.js knows every category', $okc);
ck('cover: avcv.js knows every holiday', array_reduce(array_keys(av_cover_holidays()), fn($o, $k) => $o && str_contains($js, $k . ': ['), true));
