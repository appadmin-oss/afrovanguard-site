<?php
/**
 * tests/recurrence.test.php — meetings repeat however people meet
 * (lib/Recurrence.php), and Google, the list, the clock and the calendar file
 * all read the same rule.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tz = new DateTimeZone('Africa/Lagos');
$tue = new DateTimeImmutable('2027-01-12 10:00:00', $tz);   /* the second Tuesday of January */
$dates = static function (?array $r, DateTimeImmutable $s, int $n = 6): array {
    $o = [];
    if ($r === null) return $o;
    foreach (Recurrence::each($r, $s) as $d) { $o[] = $d->format('Y-m-d H:i'); if (count($o) >= $n) break; }
    return $o;
};

/* ── The rule ───────────────────────────────────────────────────────────── */
$r = Recurrence::normalise(['freq' => 'weekly', 'interval' => 2, 'days' => ['MO', 'TH'], 'end' => 'count', 'count' => 5, 'skip' => '2027-01-28'], $tue);
ck('repeat: every 2 weeks on Mon and Thu, 5 times, skipping one — the dates', $dates($r, $tue) === ['2027-01-14 10:00', '2027-01-25 10:00', '2027-02-08 10:00', '2027-02-11 10:00']);
ck('repeat: …in words', Recurrence::label($r, $tue) === 'Every 2 weeks on Mon and Thu, 5 times, except 1 date');
ck('repeat: …and as Google is told it (the skipped date still counts toward 5)',
   Recurrence::lines($r, $tue) === ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=5', 'EXDATE;TZID=Africa/Lagos:20270128T100000']);
$r = Recurrence::normalise(['freq' => 'monthly', 'monthly' => 'nth'], $tue);
ck('repeat: the second Tuesday of every month', $dates($r, $tue, 3) === ['2027-01-12 10:00', '2027-02-09 10:00', '2027-03-09 10:00'] && Recurrence::lines($r, $tue) === ['RRULE:FREQ=MONTHLY;BYDAY=2TU']);
$r = Recurrence::normalise(['freq' => 'monthly', 'monthly' => 'last', 'end' => 'until', 'until' => '2027-03-31'], $tue);
ck('repeat: the last Tuesday, until a date', $dates($r, $tue) === ['2027-01-26 10:00', '2027-02-23 10:00', '2027-03-30 10:00']
   && str_contains(Recurrence::lines($r, $tue)[0], 'BYDAY=-1TU;UNTIL=20270331T225959Z'));
$jan31 = new DateTimeImmutable('2027-01-31 09:00:00', $tz);
ck('repeat: the 31st skips the months without one, as Google does', $dates(Recurrence::normalise(['freq' => 'monthly'], $jan31), $jan31, 3) === ['2027-01-31 09:00', '2027-03-31 09:00', '2027-05-31 09:00']);
ck('repeat: every 3 days', $dates(Recurrence::normalise(['freq' => 'daily', 'interval' => 3], $tue), $tue, 3) === ['2027-01-12 10:00', '2027-01-15 10:00', '2027-01-18 10:00']);
$ny = new DateTimeImmutable('2027-03-08 10:00:00', new DateTimeZone('America/New_York'));
ck('repeat: 10:00 stays 10:00 across a clock change', array_map(static fn($d) => $d->format('H:i'), array_slice(iterator_to_array(Recurrence::each(Recurrence::normalise(['freq' => 'weekly'], $ny), $ny), false), 0, 2)) === ['10:00', '10:00']);
ck('repeat: nonsense is made safe — a bad interval is 1, unknown days dropped', ($x = Recurrence::normalise(['freq' => 'weekly', 'interval' => -4, 'days' => ['XX', 'fr']], $tue)) && $x['interval'] === 1 && $x['days'] === ['FR']);
ck('repeat: an end before the start is no end', Recurrence::normalise(['freq' => 'daily', 'end' => 'until', 'until' => '2026-01-01'], $tue)['end'] === 'never');
ck('repeat: a one-off is null, and says so', Recurrence::normalise(['freq' => 'once'], $tue) === null && Recurrence::label(null, $tue) === 'One-off');
ck('repeat: the old words still mean what they meant', Recurrence::presetOf(Recurrence::fromPreset('weekdays', $tue), $tue) === 'weekdays'
   && Recurrence::presetOf(Recurrence::fromPreset('biweekly', $tue), $tue) === 'biweekly' && Recurrence::label(Recurrence::fromPreset('weekdays', $tue), $tue) === 'Every weekday');

/* ── A meeting that repeats that way ────────────────────────────────────── */
$ep = Database::pdo();
Meetings::ensure();
$ep->exec('DELETE FROM lms_users WHERE id BETWEEN 9780 AND 9784');
$ep->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([9781, 'Rita Repeat', 'rita.repeat@afrovanguard.org.ng', 'x', 'coordinator', 'active']);
$eEnv = [];
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT', 'AV_WS_CALENDAR_ID', 'AV_WS_TOKEN_URL', 'AV_WS_BASE_URL', 'AV_MEET_BASE_URL', 'AV_MEET_ARTIFACTS'] as $k) { $eEnv[$k] = getenv($k); putenv($k); }
$userTz = av_user_tz(9781);
$local = static fn(string $utc) => (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(Recurrence::tz()))->format('D H:i');

$r = Meetings::schedule(9781, ['title' => 'RR Stand-up', 'when' => '2027-01-12T10:00', 'duration' => 20,
    'repeat' => ['freq' => 'weekly', 'days' => ['MO', 'TH'], 'end' => 'count', 'count' => 6, 'skip' => ['2027-01-18']]]);
$row = $ep->query('SELECT * FROM meetings WHERE id = ' . (int) ($r['id'] ?? 0))->fetch(PDO::FETCH_ASSOC);
ck('meeting: a custom rule is kept with the meeting', $r['ok'] && $row['frequency'] === 'custom' && str_contains((string) $row['recur'], '"days":["MO","TH"]'));
if ($userTz === Recurrence::tz()) {
    ck('meeting: asked for Mondays and Thursdays from a Tuesday, it starts on the Thursday', $local((string) $row['scheduled_at']) === 'Thu 10:00');
}
$m = Meetings::get(9781, (int) $r['id']);
ck('meeting: it reads back as its rule, in words', $m['repeat']['days'] === ['MO', 'TH'] && $m['frequency_label'] === 'Every week on Mon and Thu, 6 times, except 1 date' && $m['is_series']);
ck('meeting: the clock and the minutes walk the same rule', count(MeetingMinutes::occurrences($row, strtotime('2027-01-01 UTC'), strtotime('2027-03-01 UTC'))) === 5);
$ics = str_replace("\r\n ", '', MeetingMinutes::icsFor($row));
ck('meeting: the calendar file carries the rule and the skipped date, on the meeting clock',
   str_contains($ics, 'RRULE:FREQ=WEEKLY;BYDAY=MO,TH;COUNT=6') && str_contains($ics, 'EXDATE') && str_contains($ics, 'DTSTART;TZID='));

$r2 = Meetings::schedule(9781, ['title' => 'RR Never', 'when' => '2027-01-12T10:00', 'repeat' => ['freq' => 'weekly', 'days' => ['MO'], 'end' => 'until', 'until' => '2027-01-13']]);
ck('meeting: a rule that never happens is refused, in words', !$r2['ok'] && str_contains($r2['error'], 'never happens'));
$r3 = Meetings::schedule(9781, ['title' => 'RR Weekly', 'when' => '2027-01-12T10:00', 'frequency' => 'weekly']);
ck('meeting: the old one-word cadence still works', $r3['ok'] && $ep->query('SELECT frequency FROM meetings WHERE id = ' . (int) $r3['id'])->fetchColumn() === 'weekly');

/* Change it: the rule, then only the date. */
$u = Meetings::update(9781, (int) $r3['id'], ['repeat' => ['freq' => 'monthly', 'monthly' => 'last']]);
ck('edit: a weekly meeting becomes the last Tuesday of the month', $u['ok'] && in_array('frequency', $u['changed'], true)
   && Meetings::get(9781, (int) $r3['id'])['frequency_label'] === 'Every month on the last Tuesday');
if ($userTz === Recurrence::tz()) {
    $u = Meetings::update(9781, (int) $r3['id'], ['when' => '2027-02-10T10:00']);
    $at = (string) $ep->query('SELECT scheduled_at FROM meetings WHERE id = ' . (int) $r3['id'])->fetchColumn();
    ck('edit: moved to a Wednesday, it is the last Wednesday — the start moves to that date', $u['ok'] && $local($at) === 'Wed 10:00' && str_starts_with($at, '2027-02-24')
       && Meetings::get(9781, (int) $r3['id'])['frequency_label'] === 'Every month on the last Wednesday');
    $w = Meetings::schedule(9781, ['title' => 'RR Simple', 'when' => '2027-01-12T10:00', 'frequency' => 'weekly']);
    Meetings::update(9781, (int) $w['id'], ['when' => '2027-01-13T10:00']);
    ck('edit: a simple weekly meeting moved to a Wednesday is weekly on Wednesdays', Meetings::get(9781, (int) $w['id'])['frequency_label'] === 'Every week on Wed');
}

/* The form's preview. */
$p = Meetings::previewRepeat(9781, '2027-01-12T10:00', ['freq' => 'weekly', 'interval' => 3, 'days' => ['FR']]);
ck('preview: the words and the next dates, before saving', $p['ok'] && $p['label'] === 'Every 3 weeks on Fri' && count($p['dates']) === 6 && str_starts_with($p['dates'][0], 'Fri'));
ck('preview: and that it moves to the first day the rule allows', $p['moved'] !== '');
ck('preview: a rule that never happens says so', Meetings::previewRepeat(9781, '2027-01-12T10:00', ['freq' => 'daily', 'end' => 'count', 'count' => 0])['never'] === false
   && Meetings::previewRepeat(9781, '2027-01-12T10:00', ['freq' => 'weekly', 'days' => ['MO'], 'end' => 'until', 'until' => '2027-01-13'])['never'] === true);

$ep->exec("DELETE FROM meeting_attendees WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'RR %')");
$ep->exec("DELETE FROM meetings WHERE title LIKE 'RR %'");
$ep->exec('DELETE FROM lms_users WHERE id BETWEEN 9780 AND 9784');
foreach ($eEnv as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }
