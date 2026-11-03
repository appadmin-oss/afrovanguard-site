<?php
/**
 * tests/home.test.php — Today, Home v4: the greeting, the growth signature,
 * the Vanguard Pulse card, and the colour logic the design asks for.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ── The greeting (lib/Greeting.php) ───────────────────────────────────── */
$base = ['id' => 42, 'first' => 'Adaeze', 'date' => '2026-11-03', 'hour' => 10];

$a = Greeting::pick($base); $b = Greeting::pick($base);
ck('greeting: the same all day', $a === $b);
$days = [];
for ($i = 0; $i < 400; $i++) $days[] = Greeting::pick(['date' => date('Y-m-d', strtotime('2026-01-01 +' . $i . ' days'))] + $base);
ck('greeting: different across days', count(array_unique(array_column($days, 'text'))) > 8);
$hon = count(array_filter($days, static fn($g) => $g['kind'] === 'honours'));
ck('greeting: honours come up about one day in four', $hon > 400 * 0.17 && $hon < 400 * 0.33);
ck('greeting: no full stops, no exclamation marks, no numbers',
   !array_filter($days, static fn($g) => preg_match('/[.!0-9]$|!|\d/u', $g['text'])));
ck('greeting: everyday lines carry the first name', !array_filter($days, static fn($g) => $g['kind'] === 'everyday' && !str_contains($g['text'], 'Adaeze')));
ck('greeting: service lines are for volunteers only', !array_filter($days, static fn($g) => $g['kind'] === 'service'));
$vol = [];
for ($i = 0; $i < 200; $i++) $vol[] = Greeting::pick(['volunteer' => true, 'date' => date('Y-m-d', strtotime('2026-01-01 +' . $i . ' days'))] + $base);
ck('greeting: a volunteer is thanked for serving some days', (bool) array_filter($vol, static fn($g) => $g['kind'] === 'service'));
ck('greeting: a birthday wins the day', Greeting::pick(['birthday' => true] + $base)['text'] === 'Happy birthday, Adaeze');
ck('greeting: a session\'s first day and final week are moments',
   Greeting::pick(['session_day_one' => true] + $base)['text'] === 'Day one, Adaeze'
   && Greeting::pick(['session_final_week' => true] + $base)['text'] === 'Final week, Adaeze');
ck('greeting: a fine or an appeal waiting gets a plain line, never a title, even on a birthday',
   Greeting::pick(['formal' => true, 'birthday' => true] + $base) === ['text' => 'Hello, Adaeze', 'kind' => 'plain']);
$eve = []; for ($i = 0; $i < 200; $i++) $eve[] = Greeting::pick(['hour' => 9, 'date' => date('Y-m-d', strtotime('2026-01-01 +' . $i . ' days'))] + $base)['text'];
ck('greeting: "Evening" only after 18:00', !in_array('Evening, Adaeze', $eve, true));
$mon = []; for ($i = 0; $i < 300; $i++) { $d = date('Y-m-d', strtotime('2026-01-01 +' . $i . ' days')); $g = Greeting::pick(['date' => $d] + $base)['text']; if ($g === 'Happy Monday, Adaeze') $mon[] = date('N', strtotime($d)); }
ck('greeting: weekday names only on their day', !array_diff(array_unique($mon), ['1']));
$domi = ['live' => true, 'state' => 'live', 'label' => 'DOMI 2026', 'week_of' => 9, 'total_weeks' => 14, 'days_left' => 41];
ck('greeting: in a session the line is the week, the session and its week, then days left — as the board writes it',
   Greeting::sublineParts('2026-11-03', $domi) === ['main' => 'Week 45 · DOMI 2026, week 9 of 14', 'tail' => '41 days left']
   && Greeting::subline('2026-11-03', $domi) === 'Week 45 · DOMI 2026, week 9 of 14 · 41 days left');
ck('greeting: before a session it counts down to it', Greeting::subline('2026-11-03', ['live' => false, 'state' => 'upcoming', 'label' => 'Summer School 2027', 'days_to_start' => 12]) === 'Week 45 · Summer School 2027 starts in 12 days');
ck('greeting: with no session it still says more than the week — the day', Greeting::subline('2026-11-03', null) === 'Week 45 · Tuesday 3 November');
$tvH = (string) file_get_contents(AV_ROOT . '/portal/views/today.php'); $cssH = (string) file_get_contents(AV_ROOT . '/portal/home.css');
ck('greeting: the phone leaves off the days left, the desktop keeps them', str_contains($tvH, "<span class=\"hv-wide\"><span class=\"hv-sep\"> · </span><?= e(\$hvWeekLine['tail']) ?>") && str_contains($cssH, '.hv .hv-wide { display:none }'));
ck('hero: the chips say the desktop board\'s words, and the phone board\'s shorter ones', str_contains($tvH, '<span class="hv-wide">Vanguard </span>Level') && str_contains($tvH, 'Verified<span class="hv-wide"> member</span>') && str_contains($tvH, ' · ID <?= e($hvIdNo) ?>'));

/* ── The growth signature (lib/GrowthSignature.php) ───────────────────── */
ck('signature: nothing to count, nothing drawn', GrowthSignature::compute([]) === []);
$sig = GrowthSignature::compute([
    'books_verified' => 12, 'books_total' => 24, 'courses' => 4, 'courses_done' => 2, 'courses_certified' => 1,
    'today' => '2026-11-03', 'tasks' => [
        ['due' => '2026-11-01', 'done' => 1, 'done_at' => '2026-10-31 09:00:00'],   // on time
        ['due' => '2026-11-01', 'done' => 1, 'done_at' => '2026-11-02 09:00:00'],   // late
        ['due' => '2026-11-02', 'done' => 0],                                       // open, late already
        ['due' => '2026-11-09', 'done' => 0],                                       // not due: not counted
        ['due' => '', 'done' => 1],                                                 // undated: not counted
    ],
    'attendance_rate' => 90, 'mentor_rate' => 70, 'mentees' => 1, 'mentees_needed' => 2,
]);
ck('signature: knowledge is the mean of books (50%) and courses finished (50%)', $sig['knowledge'] === 50);
ck('signature: competence is certified of finished', $sig['competence'] === 50);
ck('signature: responsibility counts on-time of dated tasks finished or already late', $sig['responsibility'] === 33);
ck('signature: consistency is attendance and mentorship together', $sig['consistency'] === 80);
ck('signature: impact is mentees of what the next level asks', $sig['impact'] === 50);
ck('signature: in the design\'s order', array_keys($sig) === ['knowledge', 'competence', 'responsibility', 'consistency', 'impact']);
ck('signature: a bar with no basis is left out, not drawn at zero',
   array_keys(GrowthSignature::compute(['attendance_rate' => 75])) === ['consistency']);
ck('signature: every bar says how it is worked out', array_keys(GrowthSignature::HOW) === array_keys($sig));

/* ── The view ─────────────────────────────────────────────────────────── */
$tv = (string) file_get_contents(AV_ROOT . '/portal/views/today.php');
$css = (string) file_get_contents(AV_ROOT . '/portal/home.css');
$js = (string) file_get_contents(AV_ROOT . '/portal/home.js');
$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
$pc = (string) file_get_contents(AV_ROOT . '/portal/_pulse-card.php');
ck('home: its own stylesheet and script are on the portal', str_contains($idx, "'/portal/home.css'") && str_contains($idx, "av_asset('/portal/home.js')"));
ck('home: built from its own components, not the older card styles', !preg_match('/class="avp-(card|kpi|attn|cols|btn)/', $tv));
ck('home: the greeting is picked by lib/Greeting.php, not typed', str_contains($tv, 'Greeting::pick(') && !str_contains($tv, 'Welcome back, <?='));
ck('home: the ring is the way to the next level, and says so to a screen reader', str_contains($tv, "of the way to ' . e(\$jNextLabel)"));
ck('home: no plan, no ring — an empty ring reads as a failing grade', str_contains($tv, '<?php if ($hvPlanAll): ?>'));
ck('home: quick access shows only the sections this member has', str_contains($tv, 'PortalNav::visible($qid, $portalCtx)'));
ck('home: the reading card is for whoever may read', str_contains($tv, '$hvReads = $isNgv || !empty($mayRead);'));
ck('home: the announcement can be dismissed, remembered per post', str_contains($js, "'av-notice-gone-'") && str_contains($tv, 'data-hv-dismiss'));
ck('home: the calendar feed leaves out events (drawn with their buttons) and tasks (already in Needs you)', str_contains($js, 'SKIP = { event: 1, site: 1, afg: 1, task: 1 }'));
ck('home: a link from the feed is followed only if it is http(s) or this site', str_contains($js, "/^(https?:\\/\\/|\\/)/i.test(it.url || '')"));
ck('home: the meeting scheduler still refreshes the agenda', str_contains($js, 'window.avReloadCalendar = load;') && str_contains($tv, 'id="openMeetModal"'));

/* Colour logic: gold is never text on white; gold text is the deep gold. */
ck('colour: gold text uses the deep gold token', str_contains($css, '--hv-gold-text:#8A6406') && !preg_match('/\bcolor:\s*var\(--hv-gold\)/', $css));
ck('colour: amber (owing) is apart from brand gold (you)', str_contains($css, '--hv-warn:#8A4B00') && str_contains($css, '.hv-chip--warn { background:var(--hv-warn-bg); color:var(--hv-warn) }'));
ck('colour: dark mode swaps every role, not the roles themselves', str_contains($css, '.avp.is-dark .hv {') && substr_count($css, '--hv-') > 60);
ck('home: a phone gets the phone board', str_contains($css, '@media (max-width: 720px)') && str_contains($css, '.hv-plan-row { display:flex'));

/* ── Vanguard Pulse ───────────────────────────────────────────────────── */
ck('pulse: the member sees the card before anything is shared', str_contains($pc, '<dialog class="hv-pulse-dlg"') && str_contains($js, 'dlg.showModal()'));
ck('pulse: what is shared is their own numbers and their invite link', str_contains($pc, "'. Raising 1 million incorruptible leaders for Africa. Join: ' . \$pulse['invite']"));
ck('pulse: the QR is drawn here, never by a chart service', str_contains($pc, 'chillerlan\QRCode\QRCode') && !preg_match('~https?://[^"\']*(chart|qr)~i', $pc));
ck('pulse: WhatsApp is a link the member presses, the text URL-encoded', str_contains($pc, "https://wa.me/?text=<?= rawurlencode(\$pTxt) ?>"));

/* Render the card with hostile input: everything printed is escaped. */
$pulse = ['name' => '<script>x</script>', 'initials' => 'X', 'photo' => '', 'level' => 'O', 'level_label' => 'Foundation', 'pct' => 62,
          'next_label' => 'Level A', 'week' => 'Week 45', 'books' => 1, 'books_total' => 24, 'courses' => 0, 'meetings' => 3,
          'meet_rate' => null, 'mentees' => 1, 'invite' => 'https://afrovanguard.org.ng/join?r=1"><i data-x=1>'];
ob_start(); require AV_ROOT . '/portal/_pulse-card.php'; $out = (string) ob_get_clean();
ck('pulse: renders the member\'s numbers', str_contains($out, '62% of the way to Level A') && str_contains($out, 'book read') && str_contains($out, 'meetings attended'));
ck('pulse: a name or link cannot break out of the page', !str_contains($out, '<script>x</script>') && !str_contains($out, '"><i data-x=1>'));
ck('pulse: the QR is in the card when the encoder is present', !class_exists(\chillerlan\QRCode\QRCode::class) || str_contains($out, 'aria-label="QR code: your invite link"'));

/* ── The navigation (Home v4): sidebar, tab bar, More sheet ───────────── */
$shJs = (string) file_get_contents(AV_ROOT . '/portal/shell.js');
$shCss = (string) file_get_contents(AV_ROOT . '/portal/shell.css');
$prefs = (string) file_get_contents(AV_ROOT . '/portal/prefs.php');
$avpJs = (string) file_get_contents(AV_ROOT . '/portal/avp.js');
ck('nav: its stylesheet and script are on the portal, the stylesheet last', str_contains($idx, "'/portal/home.css', '/portal/shell.css'],") && str_contains($idx, "av_asset('/portal/shell.js')"));
ck('nav: Home sits at the top under no heading; every other group folds', PortalNav::UNTITLED === ['Home'] && str_contains($idx, 'class="avn-gh" aria-expanded="true" aria-controls='));
ck('nav: a folded group still opens for the section on screen', str_contains($shJs, "document.addEventListener('portal:view'") && str_contains($shJs, "set(h, true, false)"));
ck('nav: every section has an icon in the registry', !array_diff(array_keys(PortalNav::SECTIONS), array_keys(PortalNav::ICONS)));
ck('nav: the Vanguard Quest is in the design\'s order', array_slice(array_keys(array_filter(PortalNav::SECTIONS, static fn($s) => $s['group'] === 'Vanguard Quest')), 0, 3) === ['quest', 'mentorship', 'bills']);
ck('nav: status is a word — "1 late", "Due", owed money — and a count is quiet', str_contains($idx, "\$tasksOverdue ? \$tasksOverdue . ' late' : ''") && str_contains($idx, "? 'Due' : '', 'warn'") && str_contains($shCss, '.avn-chip--bad { background:var(--avn-bad-bg); color:var(--avn-bad) }'));
ck('nav: the palette still strips a row\'s status from its label', str_contains($idx, '<span class="pnav-badge avn-end">'));
ck('nav: the top bar has the online count, the bell and you', str_contains($idx, 'id="topOnline" href="/people/"') && str_contains($idx, 'id="notifBtn"') && str_contains($idx, 'class="avn-me" href="#membership"'));
ck('nav: the view name is still announced, though the crumb is gone from sight', str_contains($idx, '<p class="avn-sr" aria-live="polite">Portal: <span id="crumbHere">'));

ck('sheet: More opens the sheet, no longer the drawer', str_contains($idx, 'id="avpMore" aria-haspopup="dialog" aria-controls="avpSheet"') && str_contains($avpJs, "more = null;"));
ck('sheet: a dialog that holds focus, closes on Esc and gives focus back', str_contains($idx, 'class="avm-sheet" role="dialog" aria-modal="true"') && str_contains($shJs, "e.key === 'Escape'") && str_contains($shJs, 'opener.focus()'));
ck('sheet: the handle takes it from half to full and back, by tap or drag', str_contains($shJs, "setFull(!wrap.classList.contains('is-full'))") && str_contains($shJs, "addEventListener('pointermove'") && str_contains($shCss, '.avm.is-open.is-full .avm-sheet { transform:none }'));
ck('sheet: every section the account has is in it, under the sidebar\'s groups', str_contains($idx, '<?php foreach ($nav as $group => $items): ?>' . "\n      <div class=\"avm-sec\">"));
ck('sheet: it is a phone\'s only', str_contains($shCss, '@media (min-width: 640px) { .avm { display:none !important } }'));

$g = ['org' => false, 'ngv' => false, 'vol' => false, 'gate' => false, 'inventory' => false, 'fines' => false, 'reading' => false];
$o = ['org' => true] + $g;
ck('shortcuts: eight by default, only sections the account can open', count(PortalNav::shortcuts($o)) === 8 && !array_diff(PortalNav::shortcuts($g), array_keys(array_filter(PortalNav::SECTIONS, static fn($s, $id) => PortalNav::visible($id, $g), ARRAY_FILTER_USE_BOTH))));
ck('shortcuts: the member\'s own choice wins, but a stored id never opens a section they lack',
   PortalNav::shortcuts($o, ['wallet', 'tasks']) === ['wallet', 'tasks'] && PortalNav::shortcuts($g, ['tasks', 'wallet']) === ['wallet']);
ck('shortcuts: Today is never one — it is the first tab', !in_array('overview', PortalNav::shortcuts($o, ['overview', 'quest']), true));
ck('shortcuts: saved through Prefs, CSRF and rate-limited, only real section ids', str_contains($prefs, "case 'set_shortcuts':") && str_contains($prefs, "av_require_write(\$uid, 'prefs', 30);") && str_contains($prefs, 'isset(PortalNav::SECTIONS[$id])'));
ck('shortcuts: editing parks the links, because the router listens before anything can stop a tap', str_contains($shJs, 'function park(on)') && str_contains($shJs, "'data-goto-off'"));

ck('appearance: light, dark, or follow the device', str_contains($idx, "['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto']") && str_contains($avpJs, "window.avpSetTheme = set;") && str_contains($avpJs, "mode === 'auto' && !!(MQ && MQ.matches)"));
ck('appearance: Auto never flashes light on a dark phone', str_contains($idx, "if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) document.body.classList.add('is-dark');"));

/* ── The announcement: one line, and a page to read it ────────────────── */
$ann = (string) file_get_contents(AV_ROOT . '/portal/announcement.php');
ck('announcement: one line that scrolls when it is long, still when it fits', str_contains($css, '.hv .hv-notice.is-long .hv-marq { animation:hv-marq') && str_contains($js, "notice.classList.add('is-long')"));
ck('announcement: it holds while hovered or focused, and does not move for reduced motion', str_contains($css, 'animation-play-state:paused') && str_contains($css, "@media (prefers-reduced-motion: reduce) {\n  .hv .hv-notice.is-long .hv-marq { animation:none }"));
ck('announcement: only the headline shows — the first sentence, no byline — on one line',
   str_contains($tv, "preg_match('/^(.{12,}?[.!?])(?:\\s|$)/u', \$hvNTitle, \$hvNm)") && !str_contains($tv, 'hv-notice-meta') && str_contains($css, '.hv .hv-notice { flex-wrap:nowrap;'));
ck('announcement: "Read" opens it whole, on its own page', str_contains($tv, 'href="/portal/announcement.php?id=<?= (int) $hvNotice[\'id\'] ?>"'));
ck('announcement page: only the Announcements space, at the reader\'s clearance', str_contains($ann, 'Community::post((int) ($_GET[\'id\'] ?? 0), (int) $u[\'id\'])') && str_contains($ann, "!== 'announcements') \$post = null;"));
ck('announcement page: the body is escaped, line breaks kept', str_contains($ann, 'nl2br(e($rest))'));
ck('announcement page: it loads Community itself — it is not in the bootstrap, and a missing class read as "not found"', str_contains($ann, "require_once AV_ROOT . '/lib/Community.php';"));

/* ── Celebrations, carried into the Home v4 shell ─────────────────────────
   assets/site/celebrations.js finds the nav logo to dress it for the day, and
   slides its banner in after the first <header>. The portal's sidebar seal is
   one of the logos it knows now (it knew none of the portal's before), and the
   top bar is still the page's first header, so the banner lands under it. */
$celJs = (string) file_get_contents(AV_ROOT . '/assets/site/celebrations.js');
ck('celebrations: the script is still on the portal', str_contains($idx, "av_asset('/assets/site/celebrations.js')"));
ck('celebrations: the day\'s doodle logo dresses the portal\'s sidebar seal', str_contains($celJs, "D.querySelector('.avn-brand')")
   && (bool) preg_match('~<a class="avn-brand"[^>]*>\s*<img [^>]*>\s*<span class="avn-brand-t">Afrovanguard</span>~', $idx));
ck('celebrations: the banner\'s anchor — the first <header> on the page — is the top bar', ($hp = strpos($idx, '<header')) !== false && str_contains(substr($idx, $hp, 60), 'class="avp-top avn-top"'));
ck('celebrations: a member\'s birthday is Today\'s greeting too', str_contains($tv, "'birthday' => class_exists('Birthdays') && Birthdays::isOn((int) \$u['id'], \$todayKey)"));

/* ── Your week: the card the owner sent back ─────────────────────────────
   Three defects in one screenshot — a 417px card with the event's title in a
   20px column reading "C..", its date wrapping one word per line, and the
   line "Nothing in your calendar for the next three weeks. A clear run."
   printed directly above an event. */
$wkCss = (string) file_get_contents(AV_ROOT . '/portal/home.css');
$wkJs  = (string) file_get_contents(AV_ROOT . '/portal/home.js');
$wkTv  = (string) file_get_contents(AV_ROOT . '/portal/views/today.php');

ck('your week: a card measures ITSELF, because it is one column of a grid that goes to one',
   str_contains($wkCss, 'container:hvcard / inline-size') && str_contains($wkCss, '@container hvcard'));
ck('your week: the event row stacks by the CARD\'s width, never the window\'s',
   (bool) preg_match('~@container hvcard \(max-width: 520px\)\s*\{[^}]*\.hv-ag \{~s', $wkCss));
ck('your week: …and the window-measuring copy of that rule is gone, or it wins and lies',
   !preg_match('~@media[^{]*\{(?:[^{}]|\{[^{}]*\})*?\.hv \.hv-ag-acts \{[^}]*grid-column~s', $wkCss));
$wkBlock = '';
if (($wkAt = strpos($wkCss, '@container hvcard (max-width: 520px)')) !== false) {
    $wkBlock = substr($wkCss, $wkAt, (int) strpos($wkCss, "\n}", $wkAt) - $wkAt);
}
ck('your week: the actions take the whole row, not a column under the title',
   str_contains($wkBlock, '.hv-ag-acts') && str_contains($wkBlock, 'grid-column:1 / -1'));
ck('your week: a title wraps to two lines rather than ellipsing to one letter',
   str_contains($wkCss, '-webkit-line-clamp:2') && !preg_match('~\.hv-ag-t b \{[^}]*white-space:nowrap~', $wkCss));

$wkTaskBlock = '';
if (($wkAt2 = strpos($wkCss, '@container hvcard (max-width: 460px)')) !== false) {
    $wkTaskBlock = substr($wkCss, $wkAt2, (int) strpos($wkCss, "\n}", $wkAt2) - $wkAt2);
}
ck('your week: the tasks row beside it measures the card too, not the window',
   str_contains($wkTaskBlock, '.hv-task {') && str_contains($wkTaskBlock, 'grid-column:1 / -1')
   && !str_contains($wkCss, '.hv .hv-task > .hv-btn { grid-column:2'));

/* The contradiction. The feed is the member's OWN calendar; the Events list
   below it is the programme's, from a different place entirely. */
ck('your week: the feed knows whether the programme\'s events follow it',
   str_contains($wkTv, "\$todayEvents ? ' data-has-events=\"1\"' : ''")
   && str_contains($wkJs, "week.getAttribute('data-has-events') === '1'"));
ck('your week: "a clear run" is never printed above an event',
   (bool) preg_match('~hasEvents\s*\?\s*\'Nothing in your own calendar~', $wkJs)
   && (bool) preg_match('~:\s*\'Nothing in your calendar for the next three weeks\. A clear run\.~', $wkJs));

/* Where it is, said once. */
$wkWhere = static function (string $venue, string $place): string {
    $r = ['venue' => $venue, 'location' => $place];
    $m = new ReflectionMethod('SiteEvents', 'where');
    $m->setAccessible(true);
    return (string) $m->invoke(null, $r);
};
ck('your week: a venue inside the location is not said twice',
   $wkWhere('Online', 'Online, Africa') === 'Online, Africa'
   && $wkWhere('Ikeja', 'Ikeja, Lagos') === 'Ikeja, Lagos');
ck('your week: …nor a location inside the venue',
   $wkWhere('Ikeja Hall, Lagos', 'Lagos') === 'Ikeja Hall, Lagos');
ck('your week: and two different places are both named',
   $wkWhere('The Dome', 'Abuja') === 'The Dome, Abuja');
ck('your week: one on its own is enough',
   $wkWhere('', 'Abuja') === 'Abuja' && $wkWhere('The Dome', '') === 'The Dome');
