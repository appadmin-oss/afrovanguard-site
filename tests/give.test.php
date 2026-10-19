<?php
/**
 * tests/give.test.php — the /give/ index view helpers (give/avgv-view.php, row 9).
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/give/avgv-view.php';

/* Order: live+urgent, live ending soon, other live, then not-live; featured first within a rank. */
$soon = gmdate('Y-m-d', strtotime('+3 days'));
$sorted = avgv_sort([
    ['slug' => 'funded', 'status' => 'funded', 'featured' => 1],
    ['slug' => 'plain', 'status' => 'live', 'featured' => 0],
    ['slug' => 'plain-featured', 'status' => 'live', 'featured' => 1],
    ['slug' => 'soon', 'status' => 'live', 'ends_on' => $soon],
    ['slug' => 'urgent', 'status' => 'live', 'urgent' => 1],
]);
ck('give: urgent live appeals lead the index', $sorted[0]['slug'] === 'urgent');
ck('give: an appeal ending within a week comes next', $sorted[1]['slug'] === 'soon');
ck('give: featured wins within the same rank', $sorted[2]['slug'] === 'plain-featured' && $sorted[3]['slug'] === 'plain');
ck('give: a funded appeal stays on the page, at the bottom', $sorted[4]['slug'] === 'funded');

ck('give: kicker says Funded for a funded appeal', avgv_kicker(['status' => 'funded', 'urgent' => 1], []) === 'Funded');
ck('give: kicker says Urgent for an urgent one', avgv_kicker(['status' => 'live', 'urgent' => 1], []) === 'Urgent');
ck('give: kicker counts the days left when ending soon',
   avgv_kicker(['status' => 'live'], ['ending_soon' => true, 'ended' => false, 'days_left' => 5]) === '5 days left'
   && avgv_kicker(['status' => 'live'], ['ending_soon' => true, 'ended' => false, 'days_left' => 1]) === '1 day left');
ck('give: otherwise the kicker is the appeal kind', avgv_kicker(['status' => 'live', 'kind' => 'equipment'], []) === 'Equipment');

ck('give: needs board labels', avgv_need_when('daily') === 'Today' && avgv_need_when('weekly') === 'This week' && avgv_need_when('once') === 'Still needed');
ck('give: bar widths are clamped to 0–100', avgv_pct(140) === 100 && avgv_pct(-5) === 0 && avgv_pct(null) === 0 && avgv_pct(64) === 64);
ck('give: lead meta joins donors and days left', avgv_lead_meta(['donors' => 112, 'days_left' => 9, 'ended' => false]) === '112 donors · 9 days left');
ck('give: lead meta never says 0 donors', avgv_lead_meta(['donors' => 0, 'days_left' => null]) === '');

$load = avgv_load();
ck('give: the loader reads the store without error', $load['error'] === false);
ck('give: and returns every section the page draws',
   isset($load['appeals'], $load['summary'], $load['needs'], $load['need_total'], $load['item_cats'], $load['item_sum']));
