<?php
/**
 * tests/academya11y.test.php — the Academy for screen readers, keyboards,
 * low vision and learners who cannot hear (owner, 2026-11-11: "terrible").
 *
 * Each check pins a failure found by using the Academy that way — axe-core
 * passed most of these pages while they were still hard to use.
 */
declare(strict_types=1);
$r = AV_ROOT;
$js = (string) file_get_contents("$r/academy/avac.js");
ck('academy a11y: the home page does not jump to the catalogue on load (the address changes only once the filters are used)',
   str_contains($js, 'let touched = false;') && str_contains($js, 'if (!touched || !form || !history.replaceState) return;'));

$cards = (string) file_get_contents("$r/academy/_avac.php");
ck('academy a11y: a course card\'s TITLE is its link, stretched over the card — one short link, the card read once',
   str_contains($cards, '<a class="avac-stretch" href="<?= e($url) ?>">') && !str_contains($cards, '<a class="avac-card-a"')
   && str_contains($cards, '<span class="avac-card-go" aria-hidden="true">'));
$idx = (string) file_get_contents("$r/academy/index.php");
ck('academy a11y: so are the featured course, NextGen Vanguard and the summit',
   substr_count($idx, '<a class="avac-stretch"') === 3 && !preg_match('~<a class="avac-(feat|ngv|summit)"~', $idx));
ck('academy a11y: the whole card still opens the link, and shows keyboard focus',
   str_contains((string) file_get_contents("$r/academy/avac.css"), '.avac-stretch::after{content:"";position:absolute;inset:0')
   && str_contains((string) file_get_contents("$r/academy/avac.css"), '.avac-stretch-box:has(.avac-stretch:focus-visible){outline:3px solid'));

foreach (['academy/academy.css', 'academy/studio/studio.css', 'academy/ngv/register.php', 'academy/ngv/members.php', 'academy/ngv/edit.php', 'academy/ngv/ngv-dashboard.css'] as $f) {
    $t = (string) file_get_contents("$r/$f");
    preg_match_all('~[^{}]*:focus[^{]*\{([^}]*)\}~', $t, $m);
    $bad = array_filter($m[1], static fn ($b) => preg_match('~outline:\s*(none|0)\b~', $b) && !preg_match('~outline:\s*\dpx solid~', $b));
    ck("academy a11y: $f never removes the focus outline from a field", $bad === []);
}

$v = (string) file_get_contents("$r/academy/verify.php");
ck('academy a11y: certificate check — a labelled field, a hint, and an error tied to it',
   str_contains($v, '<label for="avv-serial"') && str_contains($v, 'aria-describedby="avv-hint') && str_contains($v, "aria-invalid=\"true\""));
ck('academy a11y: certificate check opens on "Verify a certificate", not "Not found"',
   str_contains($v, "\$state === 'ask' ? 'Verify a certificate'") && !str_contains($v, '>Not found</h1>'));

$le = (string) file_get_contents("$r/academy/avle.js");
ck('academy a11y: the quiz says which questions still need an answer, and marks them',
   str_contains($le, "still needs an answer.") && str_contains($le, "fs.setAttribute('aria-invalid', 'true')"));
ck('academy a11y: a video with no captions or transcript tells the learner how to get one',
   str_contains((string) file_get_contents("$r/academy/learn.php"), 'This video has no captions or transcript yet.'));

/* The Studio: a video lesson is not ready without captions or a transcript. */
$cw = new LmsCourseware();
$pdo = Database::pdo();
$cid = (int) ($pdo->query("SELECT id FROM courses ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($cid) {
    $c = $pdo->query("SELECT * FROM courses WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $lid = (int) $pdo->query("SELECT id FROM lessons WHERE course_id = $cid ORDER BY id LIMIT 1")->fetchColumn();
    $was = $pdo->query("SELECT kind, video_url, captions_url, transcript FROM lessons WHERE id = $lid")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE lessons SET kind = 'video', video_url = 'https://www.youtube.com/watch?v=aaaaaaaaaaa', captions_url = '', transcript = '' WHERE id = ?")->execute([$lid]);
    $fix = array_filter($cw->readiness($c), static fn ($x) => $x['level'] === 'fix' && str_contains($x['text'], 'needs captions or a transcript'));
    ck('academy a11y: the Studio refuses to call a video lesson ready without captions or a transcript', count($fix) === 1);
    $pdo->prepare("UPDATE lessons SET transcript = 'Hello.' WHERE id = ?")->execute([$lid]);
    $fix = array_filter($cw->readiness($c), static fn ($x) => str_contains($x['text'], 'needs captions or a transcript'));
    ck('academy a11y: …and a transcript satisfies it', $fix === []);
    $pdo->prepare("UPDATE lessons SET kind = ?, video_url = ?, captions_url = ?, transcript = ? WHERE id = ?")->execute([$was['kind'], $was['video_url'], $was['captions_url'], $was['transcript'], $lid]);
}

ck('academy a11y: the header fits 320px (400% zoom) — wordmark and gaps shrink',
   str_contains((string) file_get_contents("$r/assets/site/avh.css"), '@media (max-width:380px){.avh-nav-in{gap:10px}'));
ck('academy a11y: sign-in\'s upcoming steps are readable (was 2.57:1)',
   str_contains((string) file_get_contents("$r/assets/site/avsi.css"), 'font-weight:600;color:var(--av-muted)}'));

/* What a screen reader SAYS (recorded with a virtual screen reader, 2026-11-11). */
$chrome = (string) file_get_contents("$r/partials/avh-chrome.php");
ck('screen reader: the avatar initials are not read after the account button', !preg_match('~data-avh-me-initial>~', $chrome) && str_contains($chrome, 'data-avh-me-initial aria-hidden="true">'));
ck('screen reader: "opens in a new tab" in words, not "north-east arrow"', !str_contains($chrome, '<small>↗</small></a>') && str_contains($chrome, '<small aria-hidden="true">↗</small><span class="av-sr"> (opens in a new tab)</span>'));
$learn = (string) file_get_contents("$r/academy/learn.php");
ck('screen reader: Previous and Next name the lesson they go to', str_contains($learn, 'Previous<span class="av-sr"> lesson: ') && str_contains($learn, 'Next<span class="av-sr"> lesson: '));
ck('screen reader: the lesson count is said once ("1 of 6 lessons complete"), not "1/6" first', str_contains($learn, 'data-avle-count aria-hidden="true">'));
ck('screen reader: "Completed", not "Completed check mark" (the tick is CSS with empty alt)',
   !str_contains($learn . (string) file_get_contents("$r/academy/avle.js"), 'Completed ✓') && str_contains((string) file_get_contents("$r/academy/avle.css"), 'content:" ✓" / ""'));
$teach = (string) file_get_contents("$r/academy/teach.php");
ck('screen reader: the gradebook says "2 learners finished, 100%", not "2 dot 100%"', str_contains($teach, "learners' ?> finished, "));

/* Every device (2026-11-11): VoiceOver on iPhone and Mac, TalkBack on Android,
   Narrator and Windows contrast themes, NVDA/JAWS, reader modes. */
$avle = (string) file_get_contents("$r/academy/avle.css");
ck('VoiceOver (Safari): a list styled without bullets still says "list, N items"',
   str_contains($learn, '<ul class="avle-items" role="list"') && str_contains((string) file_get_contents("$r/academy/_avac.php") . (string) file_get_contents("$r/academy/index.php"), 'role="list"'));
ck('Windows contrast themes: the chosen quiz answer is marked in system colours', (bool) preg_match('~@media \(forced-colors:active\)\{[^@]*\.avle-opt:has\(input:checked\)\{border:3px solid Highlight\}~', $avle));
ck('Focus not obscured (WCAG 2.4.11): sticky header and lesson bar leave room for focus',
   str_contains((string) file_get_contents("$r/assets/site/avh.css"), 'html{scroll-padding-top:96px}') && str_contains($avle, 'html:has(.avle-bar-wrap){scroll-padding-bottom:110px}'));
ck('Reader modes (Edge Immersive Reader, Safari Reader): the lesson is an <article>', substr_count($learn, '<article class="avle-body') === 2);
ck('Target size (WCAG 2.5.8): NGV form checkboxes are 24px', str_contains((string) file_get_contents("$r/academy/ngv/register.php"), '.chk input{margin-top:.1rem;width:1.5rem;height:1.5rem;flex:none}'));
