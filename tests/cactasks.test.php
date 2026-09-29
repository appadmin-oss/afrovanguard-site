<?php
/**
 * tests/cactasks.test.php — the console's tasks, read into the portal.
 *
 * CACENTRE already reads this site's tasks. Without this half, a member
 * working in the portal saw half their day, and "what have I got today"
 * answered differently depending on which site they asked — which is worse
 * than neither half, because it teaches people to check both anyway.
 *
 * The two properties that matter are the same two as the other direction:
 * it READS, so neither site can hold a different answer to whether something
 * is done; and it FAILS SOFT, because the portal dashboard is the first thing
 * a member sees and the other site being mid-deploy is not a reason for it to
 * break.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CacSso.php';
require_once dirname(__DIR__) . '/lib/CacTasks.php';

$src    = (string) file_get_contents(dirname(__DIR__) . '/lib/CacTasks.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/portal/index.php');
$css    = (string) file_get_contents(dirname(__DIR__) . '/portal/portal.css');

/* ── It reads; it does not copy ─────────────────────────────────────────── */
foreach (['INSERT', 'UPDATE ', 'DELETE', 'CURLOPT_POST', 'CURLOPT_CUSTOMREQUEST'] as $write) {
    ck('nothing here writes (' . $write . '): CACENTRE stays the one place a CACENTRE task '
     . 'is true, so the two sites cannot disagree about whether it is done', !str_contains($src, $write));
}

/* ── It fails soft, in every way it can fail ────────────────────────────── */
$r = CacTasks::forMember(0);
ck('a member id of nothing makes no request at all', $r['ok'] === false && $r['tasks'] === []);

ck('no shared secret here is not an error anybody is shown — the portal simply does not '
 . 'mention the console', str_contains($src, "'error' => 'not-configured'"));
ck('and the console being down has its own answer, distinct from having no tasks', str_contains($src, "'error' => 'unreachable'"));

ck('both timeouts are set — a connect that hangs is what turns a slow dashboard into one '
 . 'nobody waits for', str_contains($src, 'CURLOPT_TIMEOUT        => self::TIMEOUT')
&& str_contains($src, 'CURLOPT_CONNECTTIMEOUT => self::TIMEOUT'));
ck('three seconds, not thirty', str_contains($src, 'private const TIMEOUT = 3;'));

ck('TLS verification stays on: a task list is not worth teaching this codebase that a '
 . 'certificate is optional', str_contains($src, 'CURLOPT_SSL_VERIFYPEER => true')
&& str_contains($src, 'CURLOPT_SSL_VERIFYHOST => 2'));

/* ── One address for the other site ─────────────────────────────────────── */
ck("the console's address is derived from the sign-on landing page rather than written a "
 . 'second time, so the two cannot point at different hosts', str_contains($src, 'parse_url(CacSso::LANDING)'));

/* ── Another system's data is treated as such ───────────────────────────── */
ck('the reply is capped', str_contains($src, 'array_slice($rows, 0, 50)'));
ck('and every field bounded', str_contains($src, "mb_substr(\$title, 0, 300)"));
ck('and escaped where it renders', str_contains($portal, "<?= e(\$t['title']) ?>"));

/* ── The panel is its own card ──────────────────────────────────────────── */
ck('the console\'s tasks are their own card, not rows in the portal\'s list: those rows are '
 . 'ticked, edited and deleted here and these cannot be, so mixing them would put two kinds '
 . 'of row under one set of controls, half of which would do nothing', str_contains($portal, '<section class="pcard" id="cacTasks">'));

/* Scoped to this section: the page has checkboxes further down, and a
   lazy match from here would find one of those and call it a tick. */
$panel = (string) strstr($portal, '<section class="pcard" id="cacTasks">');
$panel = (string) substr($panel, 0, (int) strpos($panel, '</section>'));
ck('and there is no tick on them — a control that cannot do anything is worse than none',
   $panel !== '' && !str_contains($panel, 'type="checkbox"'));

ck('it links back to the console, which is where one of these is completed', str_contains($portal, 'CacTasks::consoleUrl()'));

/* ── Priority survives the translation ──────────────────────────────────── */
ck("CACENTRE has four priorities and this site has three. Letting `urgent` fall through to "
 . 'normal is what dropping an unknown value does, and it would make the most urgent task on '
 . 'the list look like the most routine one', str_contains($portal, "'urgent', 'high' => 'high',"));

/* ── Every class it draws is a class this site defines ──────────────────── */
foreach (['task', 'task--pri-high', 'task--pri-low', 'task-body', 'task-title', 'task-sub',
          'task-due', 'task-pri', 'task-pri--high', 'pcard', 'pcard-head', 'pcard-body',
          'task-head', 'task-head-l', 'task-head-sub', 'task-list', 'pbtn'] as $c) {
    ck('portal.css defines .' . $c . ', which the panel uses', str_contains($css, '.' . $c));
}
