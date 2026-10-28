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

/* Today's running order, and the facts that used to be on it twice.
   Pins the promise, not the markup: these are positions relative to each
   other and counts of a repeated binding, so a restyle does not break them
   but putting the greeting's filler back above the attention card does. */
$today = (string) file_get_contents(AV_ROOT . '/portal/views/today.php');

$posAttn = strpos($today, 'avpAttnH');
$posAsk  = strpos($today, 'askTaskH');
$posKpis = strpos($today, 'class="avp-kpis"');
$posMony = strpos($today, 'class="avp-today-ask"');
$posCols = strpos($today, 'class="avp-cols"');
ck('today: "Needs your attention" is the first card on the page',
   $posAttn !== false && $posAttn < $posAsk && $posAttn < $posKpis);
ck('today: the ask-a-task card follows attention, above at-a-glance',
   $posAsk !== false && $posAsk > $posAttn && $posAsk < $posKpis);
ck('today: asking — money or a change to your record — is a link to Requests, below at-a-glance, above the columns',
   $posMony !== false && $posMony > $posKpis && $posMony < $posCols);

/* The online count was on screen three times at once (top bar, KPI, the
   Who's online card). The card is the one that also says who, so the KPI
   went; tasks.js null-guards every one of these writes. */
ck('today: the members-online KPI is gone, the Who\'s online card is not',
   !str_contains($today, 'kpiOnline') && str_contains($today, 'onlineCount'));
ck('today: mentorship hours are printed once, in the Mentorship card',
   substr_count($today, '$mentorHoursLabel') === 2);   /* the KPI, and the card's own stat */
ck('today: today\'s date is printed once', substr_count($today, "date('D, M j')") === 2
   && strpos($today, "date('D, M j')") > $posAttn);    /* the attention card, or the all-clear line that replaces it */

/* An empty attention list is one line, not a card with a heading and a chip. */
ck('today: nothing-needs-you is a line, not an empty card',
   str_contains($today, 'avp-allclear') && !str_contains($today, 'Nothing needs you right now.</p>'));
