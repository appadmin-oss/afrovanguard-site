<?php
/**
 * tests/portalviews.test.php — every portal view parses.
 *
 * portal/views/inventory.php lost its closing endif in the shell rebuild. It
 * is only included when the CACENTRE bridge is configured, so every local
 * run passed while the live portal stopped half-way down the page, before
 * any of its scripts — Tasks, Volunteering, the lot — were sent.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

foreach (array_merge(glob(AV_ROOT . '/portal/views/*.php') ?: [], glob(AV_ROOT . '/portal/*.php') ?: []) as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    ck('portal: ' . substr($f, strlen(AV_ROOT) + 1) . ' parses', $rc === 0);
}

/* Today's running order (Home v4), and the facts that used to be on it
   twice. Pins the promise, not the markup: positions relative to each other
   and counts of a repeated binding, so a restyle does not break them but
   pushing what needs the member below their growth does. */
$today = (string) file_get_contents(AV_ROOT . '/portal/views/today.php');

$posHero  = strpos($today, 'class="hv-hero"');
$posAsk   = strpos($today, 'id="hvAsk"');
$posNeeds = strpos($today, 'id="hvNeedsH"');
$posWeek  = strpos($today, 'id="hvWeekH"');
$posGrow  = strpos($today, '<h2>Your growth</h2>');
$posRec   = strpos($today, 'class="hv-record"');
$posMony  = strpos($today, 'data-goto="requests"');
ck('today: you first — the greeting and your standing open the page',
   $posHero !== false && $posHero < $posAsk && $posHero < $posNeeds);
ck('today: the composer follows you, above what needs you',
   $posAsk !== false && $posAsk > $posHero && $posAsk < $posNeeds);
ck('today: "Needs you" and "Your week" are the first cards — act now, then growth, then the record',
   $posNeeds !== false && $posNeeds < $posWeek && $posWeek < $posGrow && $posGrow < $posRec);
ck('today: asking finance for money is a link to Requests, after the record',
   $posMony !== false && $posMony > $posRec);

/* The online count was on screen three times at once. It is in the top bar
   now and nowhere on Today; tasks.js null-guards every write to the old ids. */
ck('today: the online count is not repeated on Today',
   !str_contains($today, 'kpiOnline') && !str_contains($today, 'id="onlineCount"'));
ck('today: mentorship hours are printed once, in the record',
   substr_count($today, '$mentorHoursLabel') === 1);
ck('today: the date is said once, by the week line under the greeting',
   !str_contains($today, "date('D, M j')") && substr_count($today, 'Greeting::subline(') === 1);

/* An empty attention list is one line, not a list with nothing in it. */
ck('today: nothing-needs-you is a line, not an empty list',
   str_contains($today, 'Nothing needs you right now.') && str_contains($today, 'class="hv-clear"'));

/* One primary per card: the first thing that needs you is ink, the rest are not. */
ck('today: only the most urgent row gets the primary button',
   str_contains($today, "\$ai === 0 ? ' hv-btn--ink' : ''"));
