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
ck('academy a11y: a course card\'s link is named by the course, its details are the description',
   str_contains($cards, 'aria-labelledby="<?= $id ?>-t"') && str_contains($cards, 'id="<?= $id ?>-t"'));
$idx = (string) file_get_contents("$r/academy/index.php");
ck('academy a11y: so are the featured course and NextGen Vanguard links',
   str_contains($idx, 'aria-labelledby="avac-feat-h"') && str_contains($idx, 'aria-labelledby="avac-ngv-h"'));

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
