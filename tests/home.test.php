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
ck('greeting: the line under it is the ISO week, then the session and its week',
   Greeting::subline('2026-11-03', ['live' => true, 'label' => 'DOMI 2026', 'week_of' => 9, 'total_weeks' => 14, 'days_left' => 41]) === 'Week 45 · DOMI 2026, week 9 of 14 · 41 days left'
   && Greeting::subline('2026-11-03', null) === 'Week 45');

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
