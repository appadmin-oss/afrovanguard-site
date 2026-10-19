<?php
/**
 * tests/cms.test.php — the Studio's content store (lib/Cms.php): one life for
 * the homepage hero, every page and the site details.
 *
 *   • A draft changes nothing a visitor sees; saving the live content again is no draft.
 *   • A run of saves is one revision; every publish is a version that can be
 *     compared and put back, the first one included.
 *   • Review: submit, send back with a reason, approve. Editing what was sent
 *     for review (or scheduled) takes it back to a draft.
 *   • Scheduling: refused in the past, published by runDue() when due, and a
 *     failure is left visible, not retried for ever.
 *   • Site details: the footer's email and links change on every static page
 *     at once, and the defaults are today's footer byte for byte.
 */
declare(strict_types=1);
$root = dirname(__DIR__);

/* a throwaway copy of the site */
$tr = sys_get_temp_dir() . '/av-cms-' . getmypid();
@mkdir("$tr/terms", 0775, true);
foreach (['index.html', 'about.html', '404.html', 'terms/index.php'] as $f) copy("$root/$f", "$tr/$f");
SiteHome::$indexPath = "$tr/index.html";
SitePages::$root = $tr;
SiteSettings::$root = $tr;
foreach (Cms::refs() as $r) Cms::forget($r);

ck('cms: every document is listed — the hero, each page, the site details', Cms::refs()[0] === 'hero' && in_array('page:about.html', Cms::refs(), true) && array_slice(Cms::refs(), -1)[0] === 'site');
ck('cms: an unknown document is refused', !Cms::save('page:../config.php', [], 'x')['ok'] && !Cms::known('nope'));

/* ── drafts and revisions (the hero) ── */
$idx = (string) file_get_contents("$tr/index.html");
$h = SiteHome::defaults(); $h['slides'][0]['title'] = 'Nine years. One movement.';
$r = Cms::save('hero', $h, 'ada');
ck('cms: a draft saves and says how much changed', $r['ok'] && $r['state'] === 'draft' && $r['changes'] === 1);
ck('cms: a draft changes nothing a visitor sees', file_get_contents("$tr/index.html") === $idx);
$h['slides'][0]['sub'] = 'Still building.';
Cms::save('hero', $h, 'ada');
ck('cms: a run of saves by one person is one revision', count(array_filter(Cms::timeline('hero'), fn($t) => $t['action'] === 'save')) === 1);
Cms::save('hero', $h, 'bola');
ck('cms: …another person’s save is its own', count(array_filter(Cms::timeline('hero'), fn($t) => $t['action'] === 'save')) === 2);
ck('cms: saving what is live clears the draft', Cms::save('hero', SiteHome::defaults(), 'ada')['state'] === 'live' && Cms::draft('hero') === null);

/* ── review ── */
Cms::save('hero', $h, 'ada');
ck('cms: nothing to review without a draft', !Cms::submit('page:about.html', 'ada')['ok']);
ck('cms: an editor sends a draft for review', Cms::submit('hero', 'ada', 'For the anniversary')['ok'] && Cms::state('hero') === 'review');
ck('cms: sending back needs a reason', !Cms::reject('hero', 'chief', '  ')['ok']);
ck('cms: sent back with a reason, the draft stays', Cms::reject('hero', 'chief', 'Shorter headline')['ok'] && Cms::state('hero') === 'draft' && Cms::doc('hero')['note'] === 'Shorter headline' && Cms::draft('hero') !== null);
Cms::submit('hero', 'ada');
$h['slides'][0]['title'] = 'Nine years.';
Cms::save('hero', $h, 'ada');
ck('cms: editing what was sent for review takes it back to a draft', Cms::state('hero') === 'draft');

/* ── publish, history, compare, put back ── */
$p = Cms::publish('hero', 'chief', 'Anniversary hero');
ck('cms: publishing writes the page and empties the draft', $p['ok'] && str_contains((string) file_get_contents("$tr/index.html"), 'Nine years.</h1>') && Cms::draft('hero') === null && Cms::state('hero') === 'live');
$v = Cms::versions('hero');
ck('cms: the first publish keeps what was there before it', count($v) === 2 && $v[0]['action'] === 'publish' && $v[1]['action'] === 'baseline' && $v[0]['note'] === 'Anniversary hero');
ck('cms: what is live is the last published version', Cms::liveOf('hero')['slides'][0]['title'] === 'Nine years.');
$c = Cms::compare('hero', (string) $v[1]['id'], 'live');
ck('cms: two versions compare field by field, in words a person uses', $c['ok'] && count($c['rows']) === 2 && $c['rows'][0]['label'] === 'Slide 1: headline' && $c['rows'][0]['after'] === 'Nine years.');
ck('cms: publishing with no draft is refused', !Cms::publish('hero', 'chief')['ok']);
ck('cms: an earlier version can be made the draft', Cms::restore('hero', $v[1]['id'], 'chief')['ok'] && Cms::draftView('hero') === SiteHome::defaults());
Cms::publish('hero', 'chief');
ck('cms: …and publishing it puts the page back exactly', file_get_contents("$tr/index.html") === $idx);
ck('cms: the timeline says who did what', ($t = Cms::timeline('hero')) && $t[0]['action'] === 'publish' && $t[0]['by'] === 'chief' && in_array('restore', array_column($t, 'action'), true) && in_array('reject', array_column($t, 'action'), true));

/* ── scheduling ── */
Cms::save('hero', $h, 'ada');
ck('cms: a time in the past is refused', !Cms::schedule('hero', '2001-01-01 09:00', 'chief')['ok']);
ck('cms: so is one more than a year out', !Cms::schedule('hero', date('Y-m-d H:i', time() + 400 * 86400), 'chief')['ok']);
$s = Cms::schedule('hero', date('Y-m-d\TH:i', time() + 7200), 'chief');
ck('cms: a draft is scheduled (the time is Lagos time)', $s['ok'] && Cms::state('hero') === 'scheduled' && abs(strtotime($s['scheduled_at'] . ' UTC') - (time() + 7200 - 3600)) < 120);
ck('cms: nothing is published before its time', Cms::runDue() === [] && Cms::state('hero') === 'scheduled');
ck('cms: at its time it publishes by itself', Cms::runDue(time() + 3 * 3600) === ['hero' => true] && Cms::state('hero') === 'live' && str_contains((string) file_get_contents("$tr/index.html"), 'Nine years.</h1>'));
ck('cms: …recorded as a scheduled publish', str_contains(Cms::versions('hero')[0]['by'], '(scheduled)'));
Cms::save('hero', SiteHome::defaults() + ['x' => 1], 'ada'); Cms::save('hero', array_replace_recursive(SiteHome::defaults(), ['slides' => [['title' => 'Later']]]), 'ada');
Cms::schedule('hero', date('Y-m-d\TH:i', time() + 7200), 'chief');
Cms::save('hero', array_replace_recursive(SiteHome::defaults(), ['slides' => [['title' => 'Changed again']]]), 'ada');
ck('cms: editing a scheduled draft takes it off the schedule', Cms::state('hero') === 'draft' && Cms::doc('hero')['scheduled_at'] === '');
Cms::schedule('hero', date('Y-m-d\TH:i', time() + 7200), 'chief');
ck('cms: a schedule can be cancelled', Cms::unschedule('hero', 'chief')['ok'] && Cms::state('hero') === 'draft');
Cms::discard('hero', 'chief');
ck('cms: discarding returns to what is live', Cms::draft('hero') === null && Cms::state('hero') === 'live');

/* ── a page, through the same life ── */
$tp = array_values(array_filter(SitePages::fields('terms/index.php'), fn($x) => $x['type'] === 'text'))[0];
$terms = (string) file_get_contents("$tr/terms/index.php");
ck('cms: a page draft holds only what differs', Cms::save('page:terms/index.php', [$tp['key'] => ['value' => 'New words'], 'terms-p-999' => ['value' => 'x']], 'ada')['changes'] === 1);
ck('cms: the page draft compares against the live page', ($c = Cms::compare('page:terms/index.php', 'live', 'draft')) && $c['ok'] && count($c['rows']) === 1 && $c['rows'][0]['after'] === 'New words');
ck('cms: a page publishes and keeps the version before it', Cms::publish('page:terms/index.php', 'chief')['ok'] && count(Cms::versions('page:terms/index.php')) === 2);
Cms::restore('page:terms/index.php', Cms::history('page:terms/index.php')[0]['id'], 'chief'); Cms::publish('page:terms/index.php', 'chief');
ck('cms: …and put back to the exact bytes', file_get_contents("$tr/terms/index.php") === $terms);

/* ── site details: the footer, everywhere ── */
require_once AV_ROOT . '/partials/avh-chrome.php';
$foot = avh_chrome_html()['foot'];
$idx = (string) file_get_contents("$tr/index.html");   // the homepage as the tests above left it
ck('cms: with nothing edited, the footer is today’s, exactly', str_contains($idx, $foot) && SiteSettings::live() === SiteSettings::defaults());
ck('cms: a bad email is refused', !Cms::save('site', ['email' => 'not-an-email'] + SiteSettings::defaults(), 'ada')['ok']);
ck('cms: an http (not https) social link is refused', !Cms::save('site', array_replace_recursive(SiteSettings::defaults(), ['social' => ['x' => 'http://x.com/a']]), 'ada')['ok']);
$set = array_replace_recursive(SiteSettings::defaults(), ['email' => 'hello@afrovanguard.org.ng', 'social' => ['youtube' => '']]);
ck('cms: site details save as a draft', Cms::save('site', $set, 'ada')['ok'] && SiteSettings::live()['email'] === 'cacentre@afrovanguard.org.ng');
$pv = SiteSettings::previewHtml(Cms::draft('site'), $idx);
ck('cms: the preview shows the new footer without writing it', str_contains($pv, 'mailto:hello@afrovanguard.org.ng') && file_get_contents("$tr/index.html") === $idx);
$r = Cms::publish('site', 'chief');
$about = (string) file_get_contents("$tr/about.html"); $home = (string) file_get_contents("$tr/index.html"); $nf = (string) file_get_contents("$tr/404.html");
ck('cms: publishing site details rewrites every page’s footer', $r['ok'] && $r['pages'] === 3 && str_contains($home, 'mailto:hello@afrovanguard.org.ng') && str_contains($about, 'mailto:hello@afrovanguard.org.ng') && str_contains($nf, 'mailto:hello@afrovanguard.org.ng'));
ck('cms: …an emptied social link drops its icon', !str_contains($about, 'aria-label="YouTube"') && str_contains($about, 'aria-label="Instagram"'));
ck('cms: …and nothing else on the page moves', str_replace(avh_chrome_html()['foot'], $foot, $home) === $idx);
ck('cms: PHP pages read the new details as they render', SiteSettings::live()['email'] === 'hello@afrovanguard.org.ng');
Cms::restore('site', Cms::history('site')[0]['id'], 'chief'); Cms::publish('site', 'chief');
ck('cms: putting the old details back restores every page exactly', file_get_contents("$tr/index.html") === $idx && SiteSettings::live() === SiteSettings::defaults());

/* ── the overview ── */
Cms::save('page:about.html', [array_values(SitePages::fields('about.html'))[1]['key'] => ['value' => 'Draft words']], 'ada');
Cms::submit('page:about.html', 'ada', 'Please check');
$ov = array_column(Cms::overview(), null, 'ref');
ck('cms: the overview shows what waits for review, by whom and why', $ov['page:about.html']['state'] === 'review' && $ov['page:about.html']['submitted_by'] === 'ada' && $ov['page:about.html']['note'] === 'Please check' && $ov['hero']['state'] === 'live');

/* tidy */
foreach (Cms::refs() as $r) Cms::forget($r);
SiteHome::$indexPath = null; SitePages::$root = null; SiteSettings::$root = null; SiteSettings::forget();
exec('rm -rf ' . escapeshellarg($tr));

/* ── the Studio ── */
$api = (string) file_get_contents("$root/admin/api.php");
$cron = (string) file_get_contents("$root/tasks/cron.php");
ck('cms: approving, scheduling and sending back are admin-only', (bool) preg_match("~managementOnly = \\[.*'cms_publish', 'cms_schedule', 'cms_unschedule', 'cms_reject'~s", $api));
ck('cms: the cron publishes what is due', str_contains($cron, 'Cms::runDue()'));
ck('cms: the old per-screen actions are gone (one way to change content)', !preg_match("~case 'home_(save|publish)'|case 'page_(save|publish)'~", $api));
