<?php
/**
 * tests/cacentrelocations.test.php — the portal's centres follow CACENTRE's
 * locations (Centres::syncFromCacentre), and a member's absences follow their
 * own location's calendar (GateAttendance::isProgrammeDayFor).
 *
 *   • A location is matched to a centre by its id, so a rename in CACENTRE
 *     renames the centre here — with its members — instead of adding another.
 *   • A location CACENTRE takes off is retired here, never deleted.
 *   • Once the list has arrived, nobody adds, renames or retires a centre
 *     here by hand: the Studio says where to do it.
 *   • A member at a location that does not meet that day, or is out of term,
 *     is not marked absent and not fined.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$clPdo = Database::pdo();
MemberRoster::ensure();
Centres::ensure();
GateAttendance::ensure();
$clKeep = Database::metaGet(Centres::CAC_META);
$clPdo->exec('DELETE FROM av_centres');
Database::metaSet(Centres::CAC_META, '');
$clPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@cacloc.test')");
$clPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@cacloc.test'");

Centres::save(['name' => 'Egbeda', 'code' => 'EG'], 'tester');
Centres::save(['name' => 'Ayobo Hub', 'code' => 'AYH'], 'tester');
Centres::save(['name' => 'Old Hall', 'code' => 'OH'], 'tester');
$tola = (int) MemberRoster::create(['name' => 'Tola Loc', 'email' => 'tola@cacloc.test', 'centre' => 'Ayobo Hub'], 'tester')['id'];
$kemi = (int) MemberRoster::create(['name' => 'Kemi Loc', 'email' => 'kemi@cacloc.test', 'centre' => 'Egbeda'], 'tester')['id'];
$EG = 'L0123456789'; $AY = 'Labcdefabcd'; $IK = 'L1111111111'; $OH = 'L2222222222';
$sat = ['weekdays' => [6], 'resumption' => '10:00', 'grace_minutes' => 5, 'holidays' => ['2026-10-24'], 'term_start' => '', 'term_end' => '',
        'terms' => [['code' => 'TONI', 'name' => 'First term', 'start' => '2026-09-14', 'end' => '2026-12-18']]];
$by = static function (string $id): ?array { foreach (Centres::all() as $c) if ($c['cac_id'] === $id) return $c; return null; };

ck('cacentre: before the list arrives, centres are added here as before', !Centres::cacentreActive());
ck('cacentre: a list with a bad id changes nothing', !Centres::syncFromCacentre(['version' => 'v0-xxxxxxxx', 'locations' => [['id' => 'x', 'name' => 'X', 'status' => 'active']]])['ok'] && Centres::canonical('X') === null);

$r = Centres::syncFromCacentre(['version' => 'v1-aaaaaaaa', 'tz' => 'Africa/Lagos', 'locations' => [
    ['id' => $EG, 'name' => 'Egbeda', 'status' => 'active', 'aliases' => [], 'calendar' => $sat],
    ['id' => $AY, 'name' => 'Ayobo', 'status' => 'active', 'aliases' => ['Ayobo Hub'], 'calendar' => null],
    ['id' => $IK, 'name' => 'Ikotun', 'status' => 'active', 'aliases' => [], 'calendar' => null],
    ['id' => $OH, 'name' => 'Old Hall', 'status' => 'retired', 'aliases' => [], 'calendar' => null],
]]);
ck('cacentre: the list is taken', $r['ok']);
ck('cacentre: a centre here under the same name is linked, keeping its code', ($by($EG)['code'] ?? '') === 'EG');
ck('cacentre: one here under a former name is linked and renamed, and its members move with it', ($by($AY)['name'] ?? '') === 'Ayobo' && MemberRoster::get($tola)['centre'] === 'Ayobo');
ck('cacentre: a location the portal never had becomes a centre', ($by($IK)['name'] ?? '') === 'Ikotun' && $by($IK)['status'] === 'active');
ck('cacentre: one CACENTRE took off is retired here, not deleted', ($by($OH)['status'] ?? '') === 'retired');
ck('cacentre: the location’s calendar comes with it', (Centres::calendarFor('egbeda')['weekdays'] ?? []) === [6]);
ck('cacentre: the list is now in charge', Centres::cacentreActive() && Centres::cacentreState()['version'] === 'v1-aaaaaaaa');

Centres::syncFromCacentre(['version' => 'v2-bbbbbbbb', 'locations' => [
    ['id' => $EG, 'name' => 'Egbeda Centre', 'status' => 'active', 'aliases' => ['Egbeda'], 'calendar' => $sat],
    ['id' => $IK, 'name' => 'Ikotun', 'status' => 'retired', 'aliases' => [], 'calendar' => null],
]]);
ck('cacentre: renamed in CACENTRE, renamed here — the same centre, its members with it', ($by($EG)['name'] ?? '') === 'Egbeda Centre' && $by($EG)['code'] === 'EG' && MemberRoster::get($kemi)['centre'] === 'Egbeda Centre');
ck('cacentre: retired in CACENTRE, retired here', $by($IK)['status'] === 'retired');

/* What the portal can no longer change by hand. */
ck('cacentre: no centre is added here once the list is in charge', (Centres::save(['name' => 'Agege'], 'tester')['code'] ?? '') === 'managed_by_cacentre');
ck('cacentre: a linked centre is not renamed here', (Centres::save(['id' => $by($EG)['id'], 'name' => 'Egbeda West', 'code' => 'EG'], 'tester')['code'] ?? '') === 'managed_by_cacentre');
ck('cacentre: …but its code still is the portal’s', Centres::save(['id' => $by($EG)['id'], 'name' => 'Egbeda Centre', 'code' => 'EGC'], 'tester')['ok'] && $by($EG)['code'] === 'EGC');
ck('cacentre: a linked centre is not retired or deleted here', (Centres::setStatus($by($EG)['id'], 'retired', 'tester')['code'] ?? '') === 'managed_by_cacentre'
    && (Centres::delete($by($EG)['id'], 'tester')['code'] ?? '') === 'managed_by_cacentre');
ck('cacentre: a member’s unknown centre is refused with where to add it', str_contains(Centres::refusal('Agege'), 'CACENTRE'));

/* Absences by the location's calendar: Egbeda meets on Saturdays, in TONI, not on 24 October. */
GateAttendance::forgetCentres();
ck('cacentre: a Saturday in term is a day for a member at Egbeda', GateAttendance::isProgrammeDayFor($kemi, '2026-10-17'));
ck('cacentre: a Friday is not — that location does not meet then', !GateAttendance::isProgrammeDayFor($kemi, '2026-10-16'));
ck('cacentre: nor a Saturday that is the location’s holiday', !GateAttendance::isProgrammeDayFor($kemi, '2026-10-24'));
ck('cacentre: nor a Saturday out of term', !GateAttendance::isProgrammeDayFor($kemi, '2027-01-09'));
AvRules::save(['gate.programme_days' => 'mon,tue,wed,thu,fri', 'gate.holidays' => '2026-10-17'], 'test');
ck('cacentre: the portal’s own holiday is a holiday everywhere', !GateAttendance::isProgrammeDayFor($kemi, '2026-10-17'));
ck('cacentre: a member at a location with no calendar keeps the portal’s days', GateAttendance::isProgrammeDayFor($tola, '2026-10-16') && !GateAttendance::isProgrammeDayFor($tola, '2026-10-18'));
AvRules::save(['gate.holidays' => ''], 'test');

$src = (string) file_get_contents(AV_ROOT . '/lib/GateAttendance.php');
ck('cacentre: the absence sweep asks each member’s own calendar', (bool) preg_match('/foreach \(\$members as \$mid\) \{\s*\/\*[^*]*\*\/\s*if \(!self::isProgrammeDayFor\(\$mid, \$d\)\) continue;/', $src));
$gate = (string) file_get_contents(AV_ROOT . '/integrations/cacentre-gate.php');
ck('cacentre: the gate passes the list through its signed door', str_contains($gate, "if (\$op === 'locations')") && str_contains($gate, 'Centres::syncFromCacentre('));

/* Put things back. */
$clPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@cacloc.test')");
$clPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@cacloc.test'");
$clPdo->exec('DELETE FROM av_centres');
Database::metaSet(Centres::CAC_META, (string) ($clKeep ?? ''));
GateAttendance::forgetCentres();
