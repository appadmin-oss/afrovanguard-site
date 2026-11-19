<?php
/**
 * tests/attendancesettings.test.php — Afrovanguard members have a lateness
 * time of their own, as NGG's do, and the attendance settings are organised.
 *
 *   • The gate is told each member's own rule when it looks them up
 *     (`punctuality`, the contract NGG answers): NGV participants by the NGV
 *     resumption time, other members by theirs, plus the grace minutes — so
 *     the desk records what Afrovanguard will, not the location's hours.
 *   • No lateness on a day that is not theirs, or with lateness switched off:
 *     the rule is null, and they are shown in, on time.
 *   • A resumption time is a time (HH:MM); anything else is refused.
 *   • Every attendance rule sits in exactly one settings group; an admin may
 *     change the times and days, not the fines or points — and cannot get
 *     round that by Undo.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$asPdo = Database::pdo();
GateAttendance::ensure();
foreach (['ngv_participants'] as $t) { try { NgvDb::pdo()->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
AvRules::save(['gate.programme_days' => 'mon,tue,wed,thu,fri', 'gate.holidays' => '', 'gate.lateness_on' => '1',
               'gate.resumption_ngv' => '07:00', 'gate.resumption_member' => '09:00', 'gate.grace_minutes' => '10'], 'test');
$asUser = static function (string $name) use ($asPdo): array {
    $email = strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test';
    $asPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, 'x', 'member', 'active')")->execute([$name, $email]);
    return ['id' => (int) $asPdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'member', 'status' => 'active'];
};
$asVan = $asUser('Ada Vanguard');
NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (?, ?, ?, 'active', '2026-01-05', '2026-01-05', '2026-01-05')")
    ->execute([$asVan['id'], 'Ada Vanguard', 'p' . $asVan['id'] . '@example.test']);
$asMem = $asUser('Bola Member');
GateAttendance::forgetCentres();

$asWed = '2026-11-18'; $asSun = '2026-11-22';
ck('lateness: an NGV participant is judged by the NGV time, with the grace',
    GateAttendance::punctualityFor($asVan, $asWed) === ['resumption' => '07:00', 'grace_minutes' => 10]);
ck('lateness: another member is judged by the members\' time', GateAttendance::punctualityFor($asMem, $asWed) === ['resumption' => '09:00', 'grace_minutes' => 10]);
ck('lateness: not on a day that is not theirs (a Sunday): no lateness, shown in on time', GateAttendance::punctualityFor($asMem, $asSun) === null);
AvRules::save(['gate.holidays' => $asWed], 'test');
ck('lateness: …nor on a holiday', GateAttendance::punctualityFor($asVan, $asWed) === null);
$asCleared = AvRules::save(['gate.holidays' => ''], 'test');
ck('holidays: the list can be emptied again (it was a one-way door: once set, never cleared)', !empty($asCleared['ok']) && GateAttendance::holidays() === []);

GateAttendance::$todayForTests = $asWed;
$asPerson = GateAttendance::resolveRef((string) $asMem['id'])['person'] ?? [];
ck('lateness: the gate is told the member\'s rule when it looks them up', ($asPerson['punctuality'] ?? null) === ['resumption' => '09:00', 'grace_minutes' => 10]);
AvRules::save(['gate.lateness_on' => '0'], 'test');
$asOff = GateAttendance::resolveRef((string) $asMem['id'])['person'] ?? [];
ck('lateness: switched off, the gate is told null — never left to fall back on the location\'s hours',
    array_key_exists('punctuality', $asOff) && $asOff['punctuality'] === null);
AvRules::save(['gate.lateness_on' => '1'], 'test');
GateAttendance::$todayForTests = null;

$asBad = AvRules::save(['gate.resumption_member' => '9am'], 'test');
ck('lateness: a resumption time must be a time (HH:MM), anything else is refused', empty($asBad['ok']) && isset($asBad['errors']['gate.resumption_member']));
ck('lateness: …and 24:00 is not a time', empty(AvRules::save(['gate.resumption_ngv' => '24:00'], 'test')['ok']));
AvRules::save(['gate.resumption_member' => '08:30', 'gate.grace_minutes' => '5'], 'test');
ck('lateness: a saved time is the one the gate is told', GateAttendance::punctualityFor($asMem, $asWed) === ['resumption' => '08:30', 'grace_minutes' => 5]);
AvRules::save(['gate.resumption_member' => '09:00', 'gate.grace_minutes' => '10'], 'test');

/* The settings, organised. */
$asAtt = [];
foreach ((AvRules::describe()['groups']['Attendance'] ?? []) as $r) $asAtt[] = $r['key'];
$asGrouped = array_merge(...array_map(static fn($g) => $g['rules'], GateAttendance::SETTING_GROUPS));
ck('settings: every attendance rule is in exactly one group' . (($x = array_diff($asAtt, $asGrouped)) ? ' — loose: ' . implode(', ', $x) : ''),
    !array_diff($asAtt, $asGrouped) && !array_diff($asGrouped, $asAtt) && count($asGrouped) === count(array_unique($asGrouped)));
$asAdmin = GateAttendance::settableBy('admin');
ck('settings: an admin sets the times, days and absence', in_array('gate.resumption_member', $asAdmin, true) && in_array('gate.programme_days', $asAdmin, true) && in_array('gate.mark_absent', $asAdmin, true));
ck('settings: …not the fines or points (a Super Admin\'s, as every rule was)', !in_array('gate.late_fine', $asAdmin, true) && !in_array('gate.points_on_time', $asAdmin, true)
    && in_array('gate.late_fine', GateAttendance::settableBy('superadmin'), true) && GateAttendance::settableBy('editor') === []);
$asScreen = GateAttendance::settingsFor('admin');
ck('settings: the screen shows every group, with fines and points read-only for an admin',
    count($asScreen) === count(GateAttendance::SETTING_GROUPS) && !array_values(array_filter($asScreen, static fn($g) => $g['key'] === 'fines'))[0]['editable']
    && array_values(array_filter($asScreen, static fn($g) => $g['key'] === 'late'))[0]['editable']);

$asApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('settings: an admin\'s save is let through only when every key is one they may set',
    str_contains($asApi, "if (\$askKeys && !array_diff(\$askKeys, GateAttendance::settableBy('admin'))) \$needs = 'admin';"));
ck('settings: Undo of a rules change obeys the same line (it used to replay any rule for any admin)',
    str_contains($asApi, "array_diff(\$uk, GateAttendance::settableBy(\$role))") && str_contains($asApi, 'Undoing that rule change needs a Super Admin.'));
require_once AV_ROOT . '/lib/StudioSections.php';
ck('settings: Attendance is a Studio section under People, for admins', (static function () {
    foreach (StudioSections::all() as $s) if ($s['id'] === 'attendance') return $s['group'] === 'People' && $s['min'] === 'admin';
    return false; })());

try { NgvDb::pdo()->exec('DELETE FROM ngv_participants'); } catch (Throwable $e) {}
