<?php
/**
 * tests/volunteerseeds.test.php — NGG volunteers seeded from NGG's export,
 * linked to their account as they sign in.
 *
 *   • A dry run first; an apply must carry the dry run's digest.
 *   • Rows without a usable email, or with an email or ID twice, are refused.
 *   • Same name and phone under two IDs is flagged, both are kept.
 *   • Somebody with an account is linked on apply; everybody else at sign-in,
 *     by email (any case), else by a phone that is theirs alone.
 *   • Seeded volunteers are recognised (dashboard, identity says Summer School).
 *   • An NGG sync never retires a row NGG did not send.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$sp = Database::pdo();
NggVolunteers::ensure(); MemberRoster::ensure();
foreach (['ngg_volunteers', 'ngg_volunteer_syncs'] as $t) $sp->exec('DELETE FROM ' . $t);
$sp->exec('DELETE FROM lms_users WHERE id BETWEEN 9700 AND 9719');
$sp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9700 AND 9719');
$mkU = static function (int $id, string $name, string $email, string $phone = '') use ($sp): array {
    $sp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $name, $email, 'x', 'learner', 'active']);
    if ($phone !== '') $sp->prepare('INSERT INTO member_profiles (user_id, phone) VALUES (?,?)')->execute([$id, $phone]);
    return ['id' => $id, 'name' => $name, 'email' => $email];
};
$early = $mkU(9701, 'Tola Early', 'tola.early@mail.test');   // already has an account

$csv = "\xEF\xBB\xBF" . implode("\n", [
    'volunteerId,name,email,phone,role,programme,centre,faculty,courses,safeguarding,status,createdAt',
    '"VOL-INS-26-001","Tola Early","Tola.Early@mail.test","+2348000000001","instructor","summer","Ijaiye","Creative Arts","Catering","cleared","active","2026-08-15T12:00:00Z"',
    '"VOL-MEN-26-002","Kemi Later","kemi.later@mail.test","+2348000000002","mentor","ngg","Egbeda","Music","","pending","active","2026-08-15T12:01:00Z"',
    '"VOL-ADM-26-003","Sade Twice","sade.one@mail.test","+2348000000003","admin","summer","Ayobo","","","cleared","active","2026-08-15T12:02:00Z"',
    '"VOL-ADM-26-004","Sade Twice","sade.two@mail.test","+2348000000003","admin","summer","Ayobo","","","cleared","active","2026-08-15T12:03:00Z"',
    '"VOL-INS-26-005","No Email","","+2348000000005","instructor","summer","Ikotun","","","pending","active",""',
    '"VOL-INS-26-006","Dup Email","kemi.later@mail.test","","instructor","summer","Ikotun","","","pending","active",""',
    '"VOL-INS-26-007","Phone Only","phone.only.seed@mail.test","08000000007","instructor","summer","Abesan","Media","Photography","cleared","active",""',
]);
$p = VolunteerSeeds::parseCsv($csv);
ck('seed: NGG’s export parses (BOM and all)', $p['ok'] && count($p['rows']) === 7);
ck('seed: a file without volunteerId/email columns is refused', !VolunteerSeeds::parseCsv("name,phone\nA,1")['ok']);

$dry = VolunteerSeeds::import($p['rows']);
$by = []; foreach ($dry['rows'] as $r) $by[$r['id']] = $r;
ck('seed: the dry run writes nothing', (int) $sp->query('SELECT COUNT(*) FROM ngg_volunteers')->fetchColumn() === 0);
ck('seed: five would be new, two refused', $dry['counts']['new'] === 5 && $dry['counts']['refused'] === 2);
ck('seed: no email is refused, with why', $by['VOL-INS-26-005']['action'] === 'refused' && str_contains($by['VOL-INS-26-005']['notes'][0], 'email'));
ck('seed: one email twice is refused on the second row', $by['VOL-INS-26-006']['action'] === 'refused' && str_contains($by['VOL-INS-26-006']['notes'][0], 'row 3'));
ck('seed: same name and phone under two IDs is flagged, not refused', $by['VOL-ADM-26-004']['action'] === 'new' && str_contains(implode(' ', $by['VOL-ADM-26-004']['notes']), 'two emails'));
ck('seed: who already has an account is named', ($by['VOL-INS-26-001']['account']['id'] ?? 0) === 9701 && $dry['counts']['has_account'] === 1);
ck('seed: the role reads as NGG writes it', $by['VOL-INS-26-001']['role'] === 'Instructor — Catering' && $by['VOL-MEN-26-002']['role'] === 'Mentor — Music');

ck('seed: an apply without the dry run’s digest is refused', !VolunteerSeeds::import($p['rows'], ['apply' => true, 'expect_digest' => 'nope'])['ok']);
$ap = VolunteerSeeds::import($p['rows'], ['apply' => true, 'expect_digest' => $dry['digest'], 'actor' => 'test']);
ck('seed: applied', $ap['ok'] && $ap['applied'] && (int) $sp->query("SELECT COUNT(*) FROM ngg_volunteers WHERE origin = 'seed'")->fetchColumn() === 5);
ck('seed: an existing account is linked at once', (int) $sp->query("SELECT user_id FROM ngg_volunteers WHERE volunteer_id = 'VOL-INS-26-001'")->fetchColumn() === 9701 && $ap['linked'] >= 1);
ck('seed: and is a volunteer now', Volunteers::isVolunteer($early));
$id = Volunteers::identity($early);
ck('seed: who says Summer School, with role and centre', ($id[0]['label'] ?? '') === 'NGG Summer School volunteer' && ($id[0]['detail'] ?? '') === 'Instructor — Catering, Ijaiye');
$again = VolunteerSeeds::import($p['rows']);
ck('seed: the same file again changes nothing', $again['counts']['new'] === 0 && $again['counts']['update'] === 0 && $again['counts']['same'] === 5);

/* Sign-ins */
$kemi = $mkU(9702, 'Kemi Later', 'KEMI.LATER@mail.test');
ck('seed: Kemi, signed up later, is recognised by email even before the link', Volunteers::isVolunteer($kemi));
ck('seed: and claimed at sign-in', VolunteerSeeds::claim(9702) === 1 && (int) $sp->query("SELECT user_id FROM ngg_volunteers WHERE volunteer_id = 'VOL-MEN-26-002'")->fetchColumn() === 9702);
ck('seed: claiming again is a no-op', VolunteerSeeds::claim(9702) === 0);
$po = $mkU(9703, 'Phone Only', 'different@mail.test', '+234 800 000 0007');
ck('seed: a different email, a phone that is theirs alone: linked by phone', VolunteerSeeds::claim(9703) === 1
    && $sp->query("SELECT user_id || ':' || linked_via FROM ngg_volunteers WHERE volunteer_id = 'VOL-INS-26-007'")->fetchColumn() === '9703:phone');
ck('seed: and is a volunteer by the link', Volunteers::isVolunteer($po));
$mkU(9704, 'Sade A', 'sa@mail.test', '08000000003'); $mkU(9705, 'Sade B', 'sb@mail.test', '+2348000000003');
ck('seed: a phone two accounts share links nobody', VolunteerSeeds::claim(9704) === 0 && VolunteerSeeds::claim(9705) === 0);
$st = VolunteerSeeds::status();
ck('seed: the Studio count', $st['seeded'] === 5 && $st['linked'] === 3 && $st['waiting'] === 2);

/* An NGG sync does not retire a seeded row it never sent. */
$src = (string) file_get_contents(AV_ROOT . '/lib/NggVolunteers.php');
ck('seed: the sync only retires rows NGG sent', str_contains($src, "WHERE status <> 'gone' AND origin = 'ngg'"));
ck('seed: signing in claims seeded rows before recognition', str_contains((string) file_get_contents(AV_ROOT . '/lib/LmsAuth.php'), "VolunteerSeeds::claim(\$userId);\n        if (class_exists('Volunteers')) Volunteers::recognizeAtLogin"));
ck('seed: no volunteer file is kept in the repository', !glob(AV_ROOT . '/{data,db,storage}/*volunteers*.csv', GLOB_BRACE));

foreach (['ngg_volunteers'] as $t) $sp->exec('DELETE FROM ' . $t);
$sp->exec('DELETE FROM lms_users WHERE id BETWEEN 9700 AND 9719');
$sp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9700 AND 9719');
