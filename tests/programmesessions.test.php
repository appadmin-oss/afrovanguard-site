<?php
/**
 * tests/programmesessions.test.php — the programme sessions, read from
 * CACENTRE (lib/ProgrammeSessions.php). CACENTRE keeps the one calendar;
 * here it is cached, decided by the dates, and shown.
 *
 * CACENTRE is stood in for by useSource(). Years 2090–2093 are this test's.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$was = Database::metaGet('programme_sessions_cache');
$at = static fn(string $d, string $hm = '12:00') => (new DateTimeImmutable($d . ' ' . $hm, new DateTimeZone('Africa/Lagos')))->getTimestamp();
$row = static fn(string $code, int $y, string $s, string $e, array $x = []) => $x + ['session_id' => "$code-$y", 'code' => $code, 'year' => $y, 'name' => $code,
    'start_date' => "$y-$s", 'end_date' => "$y-$e", 'theme' => '', 'cover_url' => '', 'featured' => false, 'notes' => 'office only'];
$cal = [
    $row('TONI', 2091, '01-06', '03-29'), $row('MEDI', 2091, '04-06', '06-28'),
    $row('SUMMER', 2091, '07-06', '08-30', ['name' => 'Summer School', 'theme' => 'Build what you wish existed', 'cover_url' => 'javascript:alert(1)']),
    $row('DOMI', 2091, '09-07', '12-13'), $row('TONI', 2092, '01-06', '03-29'), $row('MEDI', 2092, '04-06', '06-28'),
    ['session_id' => 'bad id', 'start_date' => 'x'],
];
$calls = 0; $down = false;
ProgrammeSessions::useSource(function (?int $year) use (&$calls, &$down, &$cal, $row) {
    $calls++;
    if ($down) return null;
    $out = ['ok' => true, 'sessions' => $cal];
    if ($year !== null) $out['year_sessions'] = $year === 2080 ? [$row('DOMI', 2080, '09-07', '12-13')] : [];
    return $out;
});
Database::metaSet('programme_sessions_cache', '');

/* ── Read from CACENTRE, and kept ────────────────────────────────────── */
$c = ProgrammeSessions::calendar();
ck('read: what CACENTRE sends, a row it sent broken left out', count($c['sessions']) === 6 && !$c['stale']);
ck('read: the office’s notes are not kept here', !array_key_exists('notes', $c['sessions'][0]));
ck('read: a cover that is not https or a path is dropped', ($c['sessions'][2]['cover_url'] ?? 'x') === '');
$calls = 0;
ProgrammeSessions::calendar();
ck('cache: read again within ten minutes, CACENTRE is not asked', $calls === 0);
$down = true;
$c = ProgrammeSessions::calendar(true);
ck('cache: when CACENTRE does not answer, the last copy is used, and said to be old', $calls === 1 && $c['stale'] && count($c['sessions']) === 6);
$down = false;

/* ── Decided here, from the dates ────────────────────────────────────── */
$n = ProgrammeSessions::now($at('2091-07-20'));
ck('now: the session whose dates hold today is live', $n['current']['session_id'] === 'SUMMER-2091' && $n['current']['label'] === 'Summer School 2091');
ck('now: with its week, of how many, and the days left', $n['current']['week_of'] === 3 && $n['current']['total_weeks'] === 8 && $n['current']['days_left'] === 41);
ck('now: and the next to start', $n['next']['session_id'] === 'DOMI-2091' && $n['next']['days_to_start'] === 49);
$n = ProgrammeSessions::now($at('2091-09-01'));
ck('now: between sessions nothing is live; the countdown is to the next', $n['current'] === null && $n['countdown']['session_id'] === 'DOMI-2091');
ck('now: to midnight in Lagos on its first day', $n['countdown']['starts_at'] === '2091-09-07T00:00:00+01:00');
ck('now: a copy fetched before a session began still shows it live on the day', ProgrammeSessions::now($at('2091-09-07', '08:00'))['current']['session_id'] === 'DOMI-2091');
ck('now: after the year’s last session, next year’s first is next', ProgrammeSessions::now($at('2091-12-20'))['next']['session_id'] === 'TONI-2092');
$cal[5]['featured'] = true;
ProgrammeSessions::calendar(true);
ck('featured on CACENTRE: the homepage counts down to it, not the next', ProgrammeSessions::now($at('2091-09-01'))['countdown']['session_id'] === 'MEDI-2092');

/* ── Years and reports ───────────────────────────────────────────────── */
ck('year: the cached year, in date order', array_column(ProgrammeSessions::forYear(2091, $at('2091-07-20')), 'code') === ['TONI', 'MEDI', 'SUMMER', 'DOMI']);
ck('year: one outside the cached window is asked for', array_column(ProgrammeSessions::forYear(2080, $at('2091-07-20')), 'session_id') === ['DOMI-2080']);
$rep = ProgrammeSessions::report('SUMMER-2091');
ck('report: gate visits, volunteer hours and tasks here, within its dates', $rep !== null && array_key_exists('visits', $rep) && array_key_exists('volunteer_hours', $rep) && array_key_exists('tasks_done', $rep));
ck('report: a session CACENTRE does not list has none', ProgrammeSessions::report('NOPE-2091') === null);

/* ── Wiring ──────────────────────────────────────────────────────────── */
$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('studio: reads, refreshes and reports — it does not edit; CACENTRE does', str_contains($api, "'psess_list', 'psess_refresh', 'psess_report',") && !str_contains($api, 'psess_save'));
ck('studio: links to CACENTRE to edit', str_contains((string) file_get_contents(AV_ROOT . '/admin/index.php'), 'id="psEditLink"'));
$pub = (string) file_get_contents(AV_ROOT . '/api.php');
ck('site: the public API gives the session now, the next and the countdown', str_contains($pub, "\$action === 'sessions'"));
ck('site: the homepage has the strip and its script', str_contains((string) file_get_contents(AV_ROOT . '/index.html'), 'data-avh-sess') && str_contains((string) file_get_contents(AV_ROOT . '/index.html'), '/assets/site/sessions.js?v='));
ck('portal: Today names the session', str_contains((string) file_get_contents(AV_ROOT . '/portal/views/today.php'), 'ProgrammeSessions::now()'));

ProgrammeSessions::useSource(null);
Database::metaSet('programme_sessions_cache', (string) ($was ?? ''));
