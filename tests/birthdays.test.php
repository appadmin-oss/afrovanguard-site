<?php
/**
 * tests/birthdays.test.php — members' birthdays, and what they are used for.
 *
 *   • The day is enough. A member can give their birthday without their age.
 *   • 29 February is celebrated on the 28th in other years — not never.
 *   • One email per person per day, and not a second to somebody the Team
 *     page's birthday email already reached.
 *   • Birthday points at the gate: once a year, on time or not.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$bdPdo = Database::pdo();
Birthdays::ensure();
GateAttendance::ensure();
$bdUser = static function (string $name) use ($bdPdo): int {
    $bdPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test', 'x', 'member', 'active']);
    return (int) $bdPdo->lastInsertId();
};

$k = $bdUser('Kemi Birthday');
ck('Birthdays: none until given', Birthdays::of($k) === null);
$r = Birthdays::set($k, '1998-07-14');
ck('Birthdays: a date from the picker keeps day and year', $r['ok'] && Birthdays::of($k) === ['birthday' => '07-14', 'year' => 1998] && Birthdays::label(Birthdays::of($k)) === '14 July 1998');
Birthdays::set($k, '1998-07-14', false);
ck('Birthdays: …or the day alone, for somebody keeping their age to themselves', Birthdays::of($k) === ['birthday' => '07-14', 'year' => 0] && Birthdays::label(Birthdays::of($k)) === '14 July');
ck('Birthdays: MM-DD is taken as the day alone', Birthdays::set($k, '07-14')['ok'] && Birthdays::of($k)['year'] === 0);
ck('Birthdays: an impossible date is refused', !Birthdays::set($k, '2001-02-30')['ok'] && !Birthdays::set($k, '13-01')['ok'] && !Birthdays::set($k, 'tomorrow')['ok']);
ck('Birthdays: an impossible year is refused', !Birthdays::set($k, '1850-07-14')['ok'] && !Birthdays::set($k, gmdate('Y') . '-01-01')['ok']);
ck('Birthdays: and it is the day that is celebrated, any year', Birthdays::isOn($k, '2031-07-14') && !Birthdays::isOn($k, '2031-07-15'));

$l = $bdUser('Leap Day');
ck('Birthdays: 29 February is a real birthday', Birthdays::set($l, '2004-02-29')['ok']);
ck('Birthdays: …celebrated on the 29th in a leap year', Birthdays::isOn($l, '2028-02-29') && !Birthdays::isOn($l, '2028-02-28'));
ck('Birthdays: …and on the 28th in other years, not never', Birthdays::isOn($l, '2027-02-28'));
ck('Birthdays: …so the 28th lists them in a common year', in_array($l, array_map('intval', array_column(Birthdays::on('2027-02-28'), 'id')), true));

ck('Birthdays: removing one removes it', Birthdays::set($l, '')['ok'] && Birthdays::of($l) === null);

/* Email: once per person per day, and not twice for somebody on the Team page. */
Database::metaSet('member_bday_sent', '');
Birthdays::set($k, '07-14');
$t = $bdUser('Team Person'); Birthdays::set($t, '07-14');
$te = (string) $bdPdo->query("SELECT email FROM lms_users WHERE id = {$t}")->fetchColumn();
if (function_exists('av_team_ensure')) {
    av_team_ensure($bdPdo);
    $bdPdo->prepare("INSERT INTO team (name, email, birthday, active) VALUES (?, ?, '07-14', 1)")->execute(['Team Person', $te]);
}
$first = Birthdays::emailToday('2031-07-14');
ck('Birthdays: today\'s members are written to — except one the team email already reaches', $first === (function_exists('av_team_ensure') ? 1 : 2));
ck('Birthdays: and nobody twice on the same day', Birthdays::emailToday('2031-07-14') === 0);
ck('Birthdays: the cron sends them', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'Birthdays::emailToday()'));

/* At the gate. */
AvRules::save(['gate.points_birthday' => '50', 'gate.points_on_time' => '5', 'gate.programme_days' => 'mon,tue,wed,thu,fri'], 'test');
$in = static fn(string $id, int $mid, string $day, string $status = 'present') => ['id' => $id, 'day' => $day, 'at' => $day . 'T07:00:00Z', 'action' => 'in',
    'ref' => (string) $mid, 'status' => $status, 'late_minutes' => $status === 'late' ? 5 : 0, 'centre' => 'Egbeda', 'method' => 'scan'];
$m = $bdUser('Gate Birthday'); Birthdays::set($m, '2000-10-22');   // a Thursday in 2026
GateAttendance::report([$in('bd-1', $m, '2026-10-22', 'late')]);
$p = GateAttendance::points($m);
ck('Birthdays: coming in on your birthday earns the birthday points, late or not', $p['total'] === 50 && $p['recent'][0]['note'] === 'Happy birthday');
GateAttendance::report([$in('bd-2', $m, '2026-10-22')]);
ck('Birthdays: once that day, however often the gate reports', GateAttendance::points($m)['total'] === 50);
$w = $bdUser('Weekend Birthday'); Birthdays::set($w, '10-24');   // a Saturday in 2026
GateAttendance::report([$in('bd-3', $w, '2026-10-24')]);
ck('Birthdays: a birthday on a Saturday still counts, though the day itself earns nothing', GateAttendance::points($w)['total'] === 50);
$n = $bdUser('No Birthday Given');
GateAttendance::report([$in('bd-4', $n, '2026-10-22')]);
ck('Birthdays: somebody who gave none gets the day\'s points only', GateAttendance::points($n)['total'] === 5);
ck('Birthdays: the gate is never told a birthday', !str_contains((string) json_encode(GateAttendance::resolveRef((string) $m)), '10-22'));

/* Recorded by the office, never by the member. */
$prefs = (string) file_get_contents(AV_ROOT . '/portal/prefs.php');
$portal = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('Birthdays: a member has no way to set their own — not in their preferences API', !str_contains($prefs, 'birthday'));
ck('Birthdays: …nor on their Account card, which only shows what the office recorded', !str_contains($portal, 'set_birthday') && !str_contains($portal, 'id="bdForm"') && str_contains($portal, 'Birthdays::label($bday)'));
ck('Birthdays: the Studio records them, on the member list, audited', str_contains((string) file_get_contents(AV_ROOT . '/admin/api.php'), "\$lms->audit('birthday'")
   && str_contains((string) file_get_contents(AV_ROOT . '/admin/app.js'), "post('mem_save', { id: bd.getAttribute('data-id'), birthday:"));
ck('Birthdays: and the NGV console, on enrolment and on the attendance page', str_contains((string) file_get_contents(AV_ROOT . '/academy/ngv/members.php'), "Birthdays::set(\$mid, (string) \$in['birthday'])")
   && str_contains((string) file_get_contents(AV_ROOT . '/academy/ngv/attendance.php'), "Birthdays::set(\$mid, (string) (\$in['birthday'] ?? ''))"));
$rows = (new LmsRepository(Database::pdo()))->membersForAdmin('Kemi');
ck('Birthdays: the Studio member list carries the recorded birthday', $rows && $rows[0]['birthday'] === '07-14');
