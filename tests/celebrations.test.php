<?php
/**
 * tests/celebrations.test.php — what the celebrations pop-up is told (row 17).
 *
 *   • One card a day, in the design's order: your birthday, a major holiday,
 *     teammates' birthdays, other observances.
 *   • Your own birthday is yours: age when you gave a year, never wished twice.
 *   • Member-page themes run from the day before through the day after.
 *   • Built-in copy follows the design: sentence case, no exclamation marks,
 *     years counted from the date.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/celebrations.php';
$celPdo = Database::pdo();

ck('Celebrations: number words', av_celebration_words(9) === 'nine' && av_celebration_words(66) === 'sixty-six' && av_celebration_words(40) === 'forty');
ck('Celebrations: ordinals', av_celebration_ordinal(30) === '30th' && av_celebration_ordinal(21) === '21st' && av_celebration_ordinal(12) === '12th' && av_celebration_ordinal(23) === '23rd');
ck('Celebrations: lists read as prose', av_celebration_list(['A', 'B', 'C']) === 'A, B and C' && av_celebration_list(['A']) === 'A');

$cal = av_celebration_calendar(2026);
$bang = array_filter($cal, fn($r) => strpos($r[1] . $r[6], '!') !== false);
ck('Celebrations: built-in copy has no exclamation marks', !$bang);
$ind = array_values(array_filter($cal, fn($r) => $r[0] === 'independence'))[0];
ck('Celebrations: Independence counts Nigeria’s and our years from the date', $ind[6] === 'Sixty-six years of Nigeria, and nine of Afrovanguard marching with the young people of Alimosho.');
$fd = array_values(array_filter($cal, fn($r) => $r[0] === 'founding'))[0];
ck('Celebrations: the anniversary names the year count', $fd[1] === 'Nine years of Afrovanguard');

ck('Celebrations: order — yours, major holiday, teammates, observance',
    av_celebration_weight(['type' => 'birthday', 'key' => 'birthday', 'mine' => true]) > av_celebration_weight(['type' => 'holiday', 'key' => 'christmas'])
    && av_celebration_weight(['type' => 'holiday', 'key' => 'independence']) > av_celebration_weight(['type' => 'birthday', 'key' => 'birthday'])
    && av_celebration_weight(['type' => 'birthday', 'key' => 'birthday']) > av_celebration_weight(['type' => 'holiday', 'key' => 'girlchild', 'scope' => 'international']));

$c = av_celebration_today($celPdo, '2026-12-25');
ck('Celebrations: Christmas is today’s card on 25 Dec', $c && $c['primary']['key'] === 'christmas' && $c['primary']['title'] === 'Merry Christmas');
ck('Celebrations: nothing on an ordinary day', av_celebration_today($celPdo, '2026-09-03') === null);

$team = ['date' => '2026-10-07', 'items' => [
    ['type' => 'birthday', 'key' => 'birthday', 'scope' => 'internal', 'people' => [['name' => 'Oluwaseun Eze', 'role' => '', 'photo' => '', 'id' => 4], ['name' => 'Adaeze Nwosu', 'role' => '', 'photo' => '', 'id' => 5]]],
]];
$team['primary'] = $team['items'][0];
$v = av_celebration_for_viewer($team, ['name' => 'Oluwaseun Eze', 'birthday' => '10-07', 'year' => 1996], '2026-10-07', 50);
ck('Celebrations: your birthday comes first, with your age', $v['primary']['mine'] === true && $v['primary']['title'] === 'Happy 30th birthday, Oluwaseun' && $v['primary']['person']['initials'] === 'OE' && $v['primary']['points'] === 50);
ck('Celebrations: …and you are not also wished as a teammate', count($v['items']) === 2 && count($v['items'][1]['people']) === 1 && $v['items'][1]['people'][0]['name'] === 'Adaeze Nwosu');
$v2 = av_celebration_for_viewer(null, ['name' => 'Kemi Ade', 'birthday' => '10-07', 'year' => 0], '2026-10-07');
ck('Celebrations: no year given, no age shown', $v2 && $v2['primary']['title'] === 'Happy birthday, Kemi' && $v2['primary']['person']['age'] === null);
ck('Celebrations: not your birthday, nothing added', av_celebration_for_viewer(null, ['name' => 'Kemi Ade', 'birthday' => '01-02'], '2026-10-07') === null);
ck('Celebrations: a visitor gets the public card unchanged', av_celebration_for_viewer($team, null, '2026-10-07') === $team);

$t = av_celebration_theme($celPdo, '2026-12-24');
ck('Celebrations: the theme starts the day before', $t && $t['key'] === 'christmas' && $t['today'] === false && $t['date'] === '2026-12-25');
$t = av_celebration_theme($celPdo, '2026-12-26');
ck('Celebrations: …and runs through the day after', $t && $t['key'] === 'christmas');
ck('Celebrations: no theme far from a holiday', av_celebration_theme($celPdo, '2026-09-03') === null);
