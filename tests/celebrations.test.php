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
$t = av_celebration_theme($celPdo, '2026-12-27');
ck('Celebrations: …and runs through the day after', $t && $t['key'] === 'boxingday');
$t = av_celebration_theme($celPdo, '2026-12-26');
ck('Celebrations: Boxing Day themes the page in Christmas art', $t && $t['key'] === 'boxingday' && $t['art'] === 'christmas' && $t['today'] === true);
ck('Celebrations: no theme far from a holiday', av_celebration_theme($celPdo, '2026-09-03') === null);

/* ── the year's holidays (owner, 2026-10-11): anniversary on 14 Nov, Nigerian public holidays, the countdown ── */
$fd = array_values(array_filter(av_celebration_calendar(2026), fn($r) => $r[0] === 'founding'))[0];
ck('Celebrations: the anniversary is 14 November', $fd[2] === '11-14');
$c = av_celebration_today($celPdo, '2026-11-14');
ck('Celebrations: on 14 Nov 2026 the card is the ninth anniversary', $c && $c['primary']['key'] === 'founding' && $c['primary']['title'] === 'Nine years of Afrovanguard');
ck('Celebrations: nothing on 1 July any more', av_celebration_today($celPdo, '2026-07-01') === null);
$c = av_celebration_today($celPdo, '2026-11-10');
ck('Celebrations: the week before counts down', $c && $c['primary']['key'] === 'foundingweek' && $c['primary']['title'] === 'Four days to go: nine years of Afrovanguard' && $c['primary']['art'] === 'founding');
$c = av_celebration_today($celPdo, '2026-11-13');
ck('Celebrations: …to “Tomorrow”', $c && $c['primary']['title'] === 'Tomorrow: nine years of Afrovanguard');
ck('Celebrations: …starting seven days out', av_celebration_today($celPdo, '2026-11-07') !== null && av_celebration_today($celPdo, '2026-11-06') === null);
$c = av_celebration_today($celPdo, '2027-11-14');
ck('Celebrations: and next year it counts ten', $c && $c['primary']['title'] === 'Ten years of Afrovanguard');
$c = av_celebration_today($celPdo, '2026-12-26');
ck('Celebrations: Boxing Day is a holiday', $c && $c['primary']['key'] === 'boxingday');
$c = av_celebration_today($celPdo, '2026-08-26');
ck('Celebrations: Eid-el-Maulud is computed (26 Aug 2026)', $c && $c['primary']['key'] === 'maulud' && $c['primary']['art'] === 'eidfitr');
$yr = av_celebration_year($celPdo, 2026);
$dates = array_column($yr, 'date');
$sorted = $dates; sort($sorted);
ck('Celebrations: the year lists every holiday with its date, in order', count($yr) === 29 && $dates === $sorted
    && in_array('2026-03-20', $dates, true) && in_array('2026-04-05', $dates, true) && in_array('2026-05-27', $dates, true));
ck('Celebrations: the Studio shows the countdown as one row', count(array_filter(av_celebration_year_for_studio($celPdo, 2026), fn($h) => $h['key'] === 'foundingweek')) === 1);
ck('Celebrations: every listed holiday has art to wear', !array_filter($yr, fn($h) => $h['art'] === ''));

/* overrides: turn one off, move a fixed date, set this year's Eid */
$offId = av_celebrations_save($celPdo, ['key' => 'christmas', 'name' => '', 'md' => '12-25', 'enabled' => false]);
ck('Celebrations: a turned-off holiday is skipped', av_celebration_today($celPdo, '2026-12-25') === null);
$x = array_values(array_filter(av_celebration_year($celPdo, 2026), fn($h) => $h['key'] === 'christmas'))[0];
ck('Celebrations: …but stays listed for the Studio, marked off', $x['enabled'] === false && $x['override_id'] === $offId);
av_celebrations_delete($celPdo, $offId);
ck('Celebrations: …and returns when the override goes', av_celebration_today($celPdo, '2026-12-25')['primary']['key'] === 'christmas');
$mv = av_celebrations_save($celPdo, ['key' => 'eidfitr', 'name' => '', 'md' => '03-21', 'on_date' => '2026-03-21']);
ck('Celebrations: Eid can be set to the announced date', av_celebration_today($celPdo, '2026-03-21')['primary']['key'] === 'eidfitr'
    && (av_celebration_today($celPdo, '2026-03-20')['primary']['key'] ?? '') !== 'eidfitr');
ck('Celebrations: …for that year only', av_celebration_today($celPdo, '2027-03-10')['primary']['key'] === 'eidfitr');
ck('Celebrations: …and a blank name keeps the calendar’s words', av_celebration_today($celPdo, '2026-03-21')['primary']['title'] === 'Eid Mubarak');
av_celebrations_delete($celPdo, $mv);
$mf = av_celebrations_save($celPdo, ['key' => 'happiness', 'name' => 'A happier day', 'md' => '03-21']);
ck('Celebrations: a fixed holiday can be moved and renamed', av_celebration_today($celPdo, '2026-03-21')['primary']['title'] === 'A happier day');
av_celebrations_delete($celPdo, $mf);

$adm = (string) file_get_contents(AV_ROOT . '/admin/app.js') . (string) file_get_contents(AV_ROOT . '/admin/index.php');
ck('Celebrations: the Studio lists the year with Edit and Turn off', strpos($adm, 'data-celtoggle') !== false && strpos($adm, 'id="celYearNext"') !== false && strpos($adm, 'id="c_on"') !== false);
ck('Celebrations: no inline style left on the celebrations screens', !preg_match('/id="celebrationsView".{0,1600}style="/s', $adm));
