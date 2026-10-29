<?php
/**
 * tests/ngvregistration.test.php — applying online, finishing at a CACENTRE.
 *
 * The form is the first step, not the registration: an applicant is invited,
 * books (or walks in), and registers in person with the signed MoU, their
 * documents and their guarantor. Everything the office decides is content.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rgNgv = NgvDb::pdo();
$rgNgv->exec("DELETE FROM ngv_bookings WHERE application_id IN (SELECT id FROM ngv_applications WHERE email LIKE '%@reg2.test')");
$rgNgv->exec("DELETE FROM ngv_applications WHERE email LIKE '%@reg2.test'");
$rgSent = [];
$rgCap = static function (array $m) use (&$rgSent): bool { $rgSent[] = $m; return true; };
$rgMailWas = getenv('AV_MAIL_DISABLED');
Mailer::fakeRoads(['smtp' => $rgCap, 'resend' => $rgCap, 'relay' => $rgCap, 'host' => $rgCap]);
putenv('AV_MAIL_DISABLED=0');
$rgYears = static fn(int $n) => ((int) date('Y') - $n) . '-01-15';

/* ── A reference, and who may read the application by it ───────────────── */
$a = NgvMember::submitApplication(['name' => 'Tunde Apply', 'email' => 'Tunde@Reg2.test', 'dob' => $rgYears(20), 'centre' => 'Test Hall',
    'g_name' => 'Mr Boss', 'g_relationship' => 'employer', 'g_phone' => '08030000000', 'g_email' => 'boss@reg2.test', 'understood' => true]);
$app = NgvMember::application($a);
ck('ngv reg: every application gets a reference that can be read aloud — no 0/O or 1/I', (bool) preg_match('/^NGV-[A-HJ-NP-Z2-9]{6}$/', (string) $app['ref']));
ck('ngv reg: the guarantor and the understanding are stored with it', $app['g_name'] === 'Mr Boss' && $app['g_relationship'] === 'employer' && $app['understood_at'] !== '');
ck('ngv reg: the applicant reads it with reference AND email — a photocopied MoU is not enough',
   NgvRegistration::forApplicant(strtolower(str_replace('-', '', (string) $app['ref'])), 'tunde@reg2.test')['id'] == $a
   && NgvRegistration::forApplicant((string) $app['ref'], 'someone@reg2.test') === null);
ck('ngv reg: the desk finds it by reference alone', (int) NgvRegistration::forDesk((string) $app['ref'])['id'] === $a);

$k = NgvRegistration::key((string) $app['ref']);
ck('ngv reg: the emailed link opens the application with its signed key, and nothing else does',
   (int) NgvRegistration::forKey((string) $app['ref'], $k)['id'] === $a && NgvRegistration::forKey((string) $app['ref'], str_repeat('0', 24)) === null
   && NgvRegistration::forKey('NGV-AAAAAA', $k) === null && str_contains(NgvRegistration::link((string) $app['ref']), 'k=' . $k));

/* ── Do they understand? ───────────────────────────────────────────────── */
$qs = NgvRegistration::questions();
$right = []; foreach ($qs as $i => $q) $right[$i] = $q['answer'];
$wrong = $right; $wrong[1] = 1;
$c = NgvRegistration::checkUnderstanding($wrong);
ck('ngv reg: a wrong answer is not a refusal — it comes back with the right one explained',
   count($qs) >= 4 && !$c['ok'] && array_keys($c['wrong']) === [1] && str_contains($c['wrong'][1], 'work the fee off'));
ck('ngv reg: all right, and they may go on', NgvRegistration::checkUnderstanding($right)['ok']);

/* ── The guarantor ──────────────────────────────────────────────────────── */
$G = static fn(array $over) => $over + ['g_name' => 'A Person', 'g_relationship' => 'faith_leader', 'g_phone' => '08031111111', 'g_email' => 'pastor@reg2.test'];
ck('ngv reg: an adult may name an employer, faith leader or community leader', NgvRegistration::cleanGuarantor($G([]), false, 'me@reg2.test')['ok']);
ck('ngv reg: a child\'s guarantor must be their parent or guardian', !NgvRegistration::cleanGuarantor($G([]), true, 'me@reg2.test')['ok']
   && NgvRegistration::cleanGuarantor($G(['g_relationship' => 'parent']), true, 'me@reg2.test')['ok']);
ck('ngv reg: a guarantor needs a name and a phone', !NgvRegistration::cleanGuarantor($G(['g_phone' => '']), false, 'me@reg2.test')['ok']);
ck('ngv reg: and their own email, not the applicant\'s', !NgvRegistration::cleanGuarantor($G(['g_email' => 'ME@reg2.test']), false, 'me@reg2.test')['ok']);
ck('ngv reg: "other" says what the relationship is', !NgvRegistration::cleanGuarantor($G(['g_relationship' => 'other']), false, 'me@reg2.test')['ok']
   && NgvRegistration::cleanGuarantor($G(['g_relationship' => 'other', 'g_relationship_other' => 'Uncle']), false, 'me@reg2.test')['ok']);

/* ── What to bring ──────────────────────────────────────────────────────── */
$minorApp = ['dob' => $rgYears(15), 'source' => 'register'];
$labels = static fn(array $app) => array_column(NgvRegistration::checklistFor($app), 'label');
ck('ngv reg: the checklist is the office\'s list, by who it applies to — a child\'s includes their parent or guardian',
   in_array('Your parent or guardian as your guarantor', $labels($minorApp), true) && !in_array('Your parent or guardian as your guarantor', $labels($app), true));
ck('ngv reg: somebody coming up from NGG is asked for their NGG card', in_array('Your NGG member card', $labels(['dob' => $rgYears(19), 'source' => 'ngg']), true)
   && !in_array('Your NGG member card', $labels($app), true));
$doc = Ngv::get(); $doc['reg_checklist'][] = ['label' => 'Bring a pen', 'desc' => '', 'required' => true, 'applies' => 'all'];
Ngv::save(['reg_checklist' => $doc['reg_checklist']]);
ck('ngv reg: an item the office adds is on everybody\'s list at once — nothing is hard-coded', in_array('Bring a pen', $labels($app), true));
Ngv::reset();

/* ── Invited ────────────────────────────────────────────────────────────── */
$rgSent = [];
$inv = NgvRegistration::invite($a, 1);
$to = array_column($rgSent, 'to');
ck('ngv reg: inviting emails the applicant their page, and tells the guarantor they were named',
   !empty($inv['ok']) && NgvMember::application($a)['status'] === 'invited' && in_array('tunde@reg2.test', $to, true) && in_array('boss@reg2.test', $to, true));
ck('ngv reg: …and the guarantor is told they are not responsible for fees', str_contains(implode(' ', array_map(fn($m) => (string) ($m['html'] ?? ''), $rgSent)), 'not</b> responsible for their fees'));
ck('ngv reg: inviting creates nothing — no account, no charge', (int) NgvMember::application($a)['member_id'] === 0);

/* ── Booking ────────────────────────────────────────────────────────────── */
Ngv::save(['reg_slots' => [['centre' => 'Test Hall', 'days' => 'Mon, Wed', 'times' => ['10:00', '14:00'], 'capacity' => '1']]]);
$slots = NgvRegistration::slots('Test Hall');
$wd = array_values(array_unique(array_map(static fn($s) => (int) gmdate('N', strtotime($s['date'] . ' 12:00:00 UTC')), $slots))); sort($wd);
ck('ngv reg: slots are the office\'s days and times, from tomorrow, four weeks ahead', $slots !== [] && $wd === [1, 3]
   && min(array_column($slots, 'date')) > date('Y-m-d') && max(array_column($slots, 'date')) <= date('Y-m-d', strtotime('+' . NgvRegistration::BOOK_AHEAD_DAYS . ' days')));
$first = $slots[0];
ck('ngv reg: booking is only for somebody invited', empty(NgvRegistration::book(
    NgvMember::submitApplication(['name' => 'Not Invited', 'email' => 'no@reg2.test', 'dob' => $rgYears(20)]), 'Test Hall', $first['date'], $first['time'])['ok']));
$bk = NgvRegistration::book($a, 'Test Hall', $first['date'], $first['time']);
ck('ngv reg: an invited applicant books a time, and is told by email', !empty($bk['ok']) && NgvMember::application($a)['status'] === 'booked'
   && NgvRegistration::bookingFor($a)['slot_time'] === $first['time']);
$b2 = NgvMember::submitApplication(['name' => 'Second Person', 'email' => 'second@reg2.test', 'dob' => $rgYears(22)]);
NgvRegistration::invite($b2, 1);
ck('ngv reg: a full time cannot be booked again (capacity 1)', empty(NgvRegistration::book($b2, 'Test Hall', $first['date'], $first['time'])['ok']));
NgvRegistration::book($a, 'Test Hall', $slots[1]['date'], $slots[1]['time']);
ck('ngv reg: moving a booking frees the old time', count(array_filter(NgvRegistration::slots('Test Hall'), fn($s) => $s['date'] === $first['date'] && $s['time'] === $first['time'])) === 1
   && (int) $rgNgv->query("SELECT COUNT(*) FROM ngv_bookings WHERE application_id = $a AND status = 'booked'")->fetchColumn() === 1);

/* ── Not coming ─────────────────────────────────────────────────────────── */
$n1 = NgvRegistration::noShow((int) NgvRegistration::bookingFor($a)['id']);
ck('ngv reg: one missed visit puts them back to invited, free to book again', !empty($n1['ok']) && !$n1['lapsed'] && NgvMember::application($a)['status'] === 'invited');
NgvRegistration::book($a, 'Test Hall', $slots[2]['date'], $slots[2]['time']);
$rgNgv->prepare('UPDATE ngv_bookings SET slot_date = ? WHERE application_id = ? AND status = ?')->execute([date('Y-m-d', strtotime('-3 days')), $a, 'booked']);
$sw = NgvRegistration::sweep();
ck('ngv reg: a booked day that passed with nobody coming is a no-show, and the second lapses the invitation',
   $sw['noShows'] >= 1 && NgvMember::application($a)['status'] === 'lapsed');
ck('ngv reg: a lapsed application can be invited again', !empty(NgvRegistration::invite($a, 1)['ok']) && (int) NgvMember::application($a)['no_shows'] === 0);
$rgNgv->prepare('UPDATE ngv_applications SET invited_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime('-' . (NgvRegistration::INVITE_LAPSE_DAYS + 2) . ' days')), $b2]);
NgvRegistration::sweep();
ck('ngv reg: an invitation nobody acts on for ' . NgvRegistration::INVITE_LAPSE_DAYS . ' days lapses', NgvMember::application($b2)['status'] === 'lapsed');

/* ── The MoU ────────────────────────────────────────────────────────────── */
$m = NgvRegistration::mou(NgvMember::application($a));
ck('ngv reg: the MoU is the office\'s text with this applicant\'s details in, and its version',
   $m['version'] === 'NGV-MoU-2026.10' && str_contains($m['body'], 'Tunde Apply') && str_contains($m['body'], 'Mr Boss') && str_contains($m['body'], 'Employer')
   && str_contains($m['body'], '₦240,000') && !preg_match('/\{[a-z_]+\}/', $m['body']));
ck('ngv reg: and it says the guarantor is not responsible for fees', str_contains($m['body'], 'not responsible for my fees'));

/* ── The pages ──────────────────────────────────────────────────────────── */
$rgPage = static fn(string $f) => (string) file_get_contents(AV_ROOT . '/academy/ngv/' . $f);
ck('ngv reg: the MoU page opens only with the signed key, and only once invited',
   str_contains($rgPage('mou.php'), 'NgvRegistration::forKey(') && str_contains($rgPage('mou.php'), "['invited', 'accepted', 'booked', 'attended']"));
ck('ngv reg: the status page finds by reference AND email, and says the same thing whether the reference exists or not',
   str_contains($rgPage('status.php'), 'NgvRegistration::forApplicant($ref') && substr_count($rgPage('status.php'), 'We could not find an application') === 1);
ck('ngv reg: the form checks understanding, the guarantor, the centre and availability before saving',
   ($f = $rgPage('register.php')) && strpos($f, 'checkUnderstanding') < strpos($f, 'NgvMember::submitApplication(')
   && strpos($f, 'cleanGuarantor') < strpos($f, 'NgvMember::submitApplication(') && str_contains($f, "trim(\$old['availability']) === ''"));

Ngv::reset();
$rgNgv->exec("DELETE FROM ngv_bookings WHERE application_id IN (SELECT id FROM ngv_applications WHERE email LIKE '%@reg2.test')");
$rgNgv->exec("DELETE FROM ngv_applications WHERE email LIKE '%@reg2.test'");
Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED' . ($rgMailWas === false ? '' : '=' . $rgMailWas));

/* ── The schema text ────────────────────────────────────────────────────────
   NgvDb's DDL is run statement by statement, split on ';'. A semicolon inside
   a comment there cuts a CREATE TABLE in half: the table is never created, the
   error goes to a log, and every page that reads it fails. It happened twice
   while this file was written. */
$rgDdl = (new ReflectionMethod('NgvDb', 'ddl'))->invoke(null);
preg_match_all('#/\*.*?\*/#s', (string) $rgDdl, $rgCm);
ck('ngv reg: no comment inside the NGV schema holds a semicolon', !array_filter($rgCm[0], static fn($c) => str_contains($c, ';')));
