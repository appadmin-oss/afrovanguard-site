<?php
/**
 * tests/nggvolunteers.test.php — NGG's volunteers, recognised here; and the
 * dashboard's wider work: opportunities, kudos, people, the service letter.
 *
 *   • NGG is asked with the shared secret, under the "volunteers." purpose, and
 *     paged to the end; the copy is linked to accounts by email.
 *   • A volunteer NGG stops listing is kept, marked gone — and no longer
 *     recognised as one.
 *   • Signing in, an NGG volunteer is recognised and welcomed, once.
 *   • Opportunities: post, sign up (never past the places), withdraw, mark who
 *     came — those who came get the hours, once.
 *   • Kudos: to somebody else, a volunteer, with a word; counted to milestones.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$np = Database::pdo();
NggVolunteers::ensure(); VolunteerWork::ensure(); Notifications::ensure();
foreach (['ngg_volunteers', 'ngg_volunteer_syncs', 'vol_opportunities', 'vol_signups', 'vol_kudos', 'vol_recognitions', 'vol_teams', 'vol_team_members'] as $t) { try { $np->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$np->exec("DELETE FROM vol_hours WHERE source = 'opportunity'");
$np->exec('DELETE FROM lms_users WHERE id BETWEEN 9500 AND 9599');
$mk = static function (int $id, string $name, string $email, string $role = 'learner') use ($np): array {
    $np->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $name, $email, 'x', $role, 'active']);
    return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role];
};
$ada  = $mk(9501, 'Ada Volunteer', 'ada.ngg@gmail.test');        // an NGG volunteer, personal email
$bola = $mk(9502, 'Bola Learner', 'bola@gmail.test');            // nobody's volunteer
$coord = $mk(9503, 'Kemi Coord', 'kemi@afrovanguard.org.ng', 'coordinator');

ck('NGG: before a sync, an NGG volunteer with a personal email is not recognised', !Volunteers::isVolunteer($ada));

/* NGG, as a fake that checks the signature the way NGG does, and pages by two. */
putenv('NGG_WEBHOOK_SECRET=shared-secret-for-tests');
$feed = [
    ['volunteerId' => 'VOL-1', 'name' => 'Ada Volunteer', 'email' => 'ADA.NGG@gmail.test', 'role' => 'mentor', 'roleLabel' => 'Mentor', 'centre' => 'Egbeda', 'status' => 'active', 'safeguardingCleared' => true, 'hours' => 64, 'sessions' => 16, 'level' => 'Keeper', 'photoUrl' => 'https://ngg.test/p.jpg'],
    ['volunteerId' => 'VOL-2', 'name' => 'Femi Instructor', 'email' => 'femi@ngg.test', 'role' => 'instructor', 'roleLabel' => 'Instructor', 'centre' => 'Ayobo', 'status' => 'active', 'photoUrl' => 'http://insecure.test/x.jpg'],
    ['volunteerId' => 'VOL-3', 'name' => 'Gbenga Admin', 'email' => 'gbenga@ngg.test', 'role' => 'admin', 'centre' => 'Egbeda', 'status' => 'active'],
];
$calls = [];
NggVolunteers::$transport = static function (string $url, string $raw, array $h) use (&$calls, &$feed) {
    $calls[] = $url;
    $hdr = implode("\n", $h);
    preg_match('/X-NGG-Timestamp: (\d+)/', $hdr, $ts);
    preg_match('/X-NGG-Signature: (\S+)/', $hdr, $sig);
    if (($sig[1] ?? '') !== 'sha256=' . hash_hmac('sha256', 'volunteers.' . ($ts[1] ?? '') . '.' . $raw, 'shared-secret-for-tests')) return ['code' => 401, 'body' => '{"ok":false}'];
    $in = json_decode($raw, true);
    $after = (int) ($in['after'] ?? 0);
    $page = array_slice($feed, $after, 2);
    $done = $after + 2 >= count($feed);
    return ['code' => 200, 'body' => json_encode(['ok' => true, 'volunteers' => $page, 'next' => $done ? null : $after + 2, 'done' => $done])];
};
$r = NggVolunteers::sync();
ck('NGG: the list is fetched, signed, to the end (two pages)', $r['ok'] && $r['received'] === 3 && count($calls) === 2 && str_ends_with($calls[0], '/api/hooks/afrovanguard.php'));
ck('NGG: …and linked to accounts by email, however NGG cased it', (int) $np->query("SELECT user_id FROM ngg_volunteers WHERE volunteer_id = 'VOL-1'")->fetchColumn() === 9501);
ck('NGG: an insecure photo address is not kept', (string) $np->query("SELECT photo_url FROM ngg_volunteers WHERE volunteer_id = 'VOL-2'")->fetchColumn() === '');
ck('NGG: now Ada is recognised as a volunteer', Volunteers::isVolunteer($ada) && !Volunteers::isVolunteer($bola));
$id = Volunteers::identity($ada);
ck('NGG: …as an NGG volunteer, with role, centre and NGG\'s hours', ($id[0]['source'] ?? '') === 'ngg' && $id[0]['detail'] === 'Mentor, Egbeda' && $id[0]['hours'] === 64 && $id[0]['level'] === 'Keeper');

/* Recognised at sign-in, welcomed once. */
$np->exec("DELETE FROM user_notifications WHERE user_id = 9501");
$first = Volunteers::recognizeAtLogin(9501);
$again = Volunteers::recognizeAtLogin(9501);
ck('Sign-in: an NGG volunteer is recognised and welcomed the first time', $first && $first['first'] === true
    && (int) $np->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9501 AND title = 'Welcome to your Volunteer dashboard'")->fetchColumn() === 1);
ck('Sign-in: …and not welcomed again', $again && $again['first'] === false && (int) $np->query('SELECT logins FROM vol_recognitions WHERE user_id = 9501')->fetchColumn() === 2);
ck('Sign-in: somebody who is no volunteer is left alone', Volunteers::recognizeAtLogin(9502) === null);
ck('Sign-in: it runs on every way in (the one place a session starts)', str_contains((string) file_get_contents(AV_ROOT . '/lib/LmsAuth.php'), 'Volunteers::recognizeAtLogin($userId)'));

/* NGG stops listing someone: kept, gone, no longer recognised. */
$feed = array_slice($feed, 0, 1) + [1 => $feed[2]];
$feed = array_values($feed);
$r2 = NggVolunteers::sync();
ck('NGG: somebody NGG no longer lists is kept and marked gone', $r2['ok'] && $r2['gone'] === 1 && (string) $np->query("SELECT status FROM ngg_volunteers WHERE volunteer_id = 'VOL-2'")->fetchColumn() === 'gone');
ck('NGG: the six-hourly sync waits its turn', NggVolunteers::syncIfDue() === null);
NggVolunteers::$transport = static fn() => ['code' => 401, 'body' => '{"ok":false,"error":"bad_signature"}'];
$bad = NggVolunteers::sync();
ck('NGG: a refused signature says the secrets differ, and the copy is untouched', !$bad['ok'] && str_contains($bad['error'], 'secret differs') && (int) $np->query("SELECT COUNT(*) FROM ngg_volunteers WHERE status = 'active'")->fetchColumn() === 2);
putenv('NGG_WEBHOOK_SECRET');
ck('NGG: with no shared secret nothing is asked', !NggVolunteers::sync()['ok'] && NggVolunteers::syncIfDue() === null);
NggVolunteers::$transport = null;

/* ── Opportunities ── */
$t = Volunteers::createTeam($coord, 'NV Events');
Volunteers::setMember($coord, $t['id'], $ada['email'], 'member');
$soon = (new DateTime('+2 days', new DateTimeZone(AV_TZ)))->format('Y-m-d\TH:i');
ck('Opportunities: only a coordinator or the team\'s lead posts', !VolunteerWork::post($ada, ['title' => 'x', 'when' => $soon])['ok']);
$o = VolunteerWork::post($coord, ['title' => 'NV Setup day', 'when' => $soon, 'duration' => 180, 'slots' => 1, 'location' => 'CACENTRE', 'team_id' => $t['id']]);
ck('Opportunities: a team\'s opportunity is announced to the team', $o['ok'] && (int) $np->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9501 AND title = 'New opportunity: NV Setup day'")->fetchColumn() === 1);
ck('Opportunities: a volunteer signs up', VolunteerWork::signup($ada, $o['id'])['ok'] && VolunteerWork::list($ada)[0]['mine'] === 'signed');
ck('Opportunities: …and nobody past the places', !VolunteerWork::signup($coord, $o['id'])['ok']);
ck('Opportunities: withdrawing frees the place', VolunteerWork::withdraw($ada, $o['id'])['ok'] && VolunteerWork::signup($coord, $o['id'])['ok']);
VolunteerWork::withdraw($coord, $o['id']); VolunteerWork::signup($ada, $o['id']);
ck('Opportunities: the day before, those signed up are reminded, once', VolunteerWork::sweep(time() + 86400 + 3600) === 1 && VolunteerWork::sweep(time() + 86400 + 3600) === 0);
ck('Opportunities: who came is marked after, not before', !VolunteerWork::mark($coord, $o['id'], 9501, 'attended')['ok']);
$np->prepare('UPDATE vol_opportunities SET starts_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5 * 3600), $o['id']]);
ck('Opportunities: only whoever posted it marks', !VolunteerWork::mark($ada, $o['id'], 9501, 'attended')['ok']);
VolunteerWork::mark($coord, $o['id'], 9501, 'attended');
VolunteerWork::mark($coord, $o['id'], 9501, 'attended');
ck('Opportunities: those who came are credited its hours — once', (int) $np->query("SELECT SUM(minutes) FROM vol_hours WHERE user_id = 9501 AND source = 'opportunity'")->fetchColumn() === 180);
VolunteerWork::mark($coord, $o['id'], 9501, 'absent');
ck('Opportunities: …and un-credited if it was a mistake', (int) $np->query("SELECT COUNT(*) FROM vol_hours WHERE user_id = 9501 AND source = 'opportunity'")->fetchColumn() === 0);
VolunteerWork::mark($coord, $o['id'], 9501, 'attended');

/* ── Kudos ── */
ck('Kudos: not to yourself', !VolunteerWork::give($ada, 9501, 'thanks', 'Me!')['ok']);
ck('Kudos: not to somebody who is no volunteer', !VolunteerWork::give($ada, 9502, 'thanks', 'Thanks')['ok']);
ck('Kudos: a coordinator is no volunteer until made one', !VolunteerWork::give($ada, 9503, 'thanks', 'Thanks')['ok']);
Volunteers::promote([9503], 1, 'Runs the events team');
$k = VolunteerWork::give($ada, 9503, 'teamwork', 'Ran the whole setup day.');
ck('Kudos: to a volunteer, with a word — and they are told', $k['ok'] && (int) $np->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9503 AND title LIKE '%sent you kudos'")->fetchColumn() === 1);
ck('Kudos: on the receiver\'s list and the team\'s wall', VolunteerWork::kudosFor($coord)['count'] === 1 && count(VolunteerWork::kudosFor($ada)['wall']) === 1);
ck('Kudos: counted toward milestones', in_array('kudos_1', array_column(Volunteers::engagement($coord)['earned'], 'key'), true));

/* ── People and the letter ── */
$people = VolunteerWork::people($coord);
$names = array_column($people, 'name');
ck('People: Afrovanguard\'s team volunteers and NGG\'s, one list, one row a person', in_array('Gbenga Admin', $names, true) && in_array('NV Events', $people[array_search('Ada Volunteer', $names)]['teams'], true) && count(array_keys($names, 'Ada Volunteer')) === 1);
ck('People: …NGG\'s marked with role and centre; somebody gone is not listed', ($people[array_search('Ada Volunteer', $names)]['ngg']['centre'] ?? '') === 'Egbeda' && !in_array('Femi Instructor', $names, true));
ck('People: filtered to NGG', array_column(VolunteerWork::people($coord, '', 'ngg'), 'name') === ['Ada Volunteer', 'Gbenga Admin']);
$letter = VolunteerWork::serviceLetter($ada);
ck('Service letter: a PDF, with a code that verifies it', $letter !== null && str_starts_with($letter['bytes'], '%PDF') && $letter['code'] === VolunteerWork::letterCode(9501, gmdate('Y-m-d')));

foreach (['ngg_volunteers', 'ngg_volunteer_syncs', 'vol_opportunities', 'vol_signups', 'vol_kudos', 'vol_recognitions', 'vol_teams', 'vol_team_members'] as $tt) { try { $np->exec('DELETE FROM ' . $tt); } catch (Throwable $e) {} }
$np->exec("DELETE FROM vol_hours WHERE user_id BETWEEN 9500 AND 9599");
$np->exec('DELETE FROM lms_users WHERE id BETWEEN 9500 AND 9599');
