<?php
/**
 * tests/ngvguardian.test.php — applying to NGV, end to end, after the three fixes.
 *
 *   • Every "Apply" on the NGV page went to bit.ly/ngv, a WhatsApp group
 *     invite, from the page's stored copy — never to the form.
 *   • Enrolment needed the applicant to have made an account first, with the
 *     same address; one who never did sat at "accepted" for ever.
 *   • The form asked an age, not a date of birth, and a child's guardian
 *     "consent" was a box the child ticked.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$gPdo = Database::pdo();
$gNgv = NgvDb::pdo();
$gNgv->exec("DELETE FROM ngv_applications WHERE email LIKE '%@grd.test'");
$gPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@grd.test'");

$gSent = [];
$gCap = static function (array $m) use (&$gSent): bool { $gSent[] = $m; return true; };
$gMailWas = getenv('AV_MAIL_DISABLED');
Mailer::fakeRoads(['smtp' => $gCap, 'resend' => $gCap, 'relay' => $gCap, 'host' => $gCap]);
putenv('AV_MAIL_DISABLED=0');

/* ── 1. Apply means the form ────────────────────────────────────────────── */
Ngv::save(['hero' => ['cta_primary_url' => 'https://bit.ly/ngv', 'cta_secondary_url' => 'https://wa.me/2349037776318'],
           'cta' => ['button_url' => 'bit.ly/ngv'], 'contact' => ['apply_url' => 'https://chat.whatsapp.com/AbCdEf']]);
$doc = Ngv::get();
ck('ngv apply: a stored "Apply" that points at the WhatsApp shortlink opens the form instead',
   $doc['hero']['cta_primary_url'] === Ngv::APPLY_URL && $doc['cta']['button_url'] === Ngv::APPLY_URL && $doc['contact']['apply_url'] === Ngv::APPLY_URL);
ck('ngv apply: …while the "Chat on WhatsApp" button stays a chat', $doc['hero']['cta_secondary_url'] === 'https://wa.me/2349037776318');
ck('ngv apply: …and a link that merely starts the same way is left alone', Ngv::mendApplyUrl('https://bit.ly/ngvx') === 'https://bit.ly/ngvx');
Ngv::reset();
$seed = require dirname(__DIR__) . '/db/academy_content.php';
ck('ngv apply: the Academy course card is seeded with the form, not the shortlink',
   !str_contains((string) json_encode($seed), 'bit.ly/ngv') && !str_contains((string) file_get_contents(dirname(__DIR__) . '/academy/ngv/edit.php'), 'bit.ly/ngv'));

/* ── 2. Date of birth decides age ───────────────────────────────────────── */
$yearsAgo = static fn(int $n, string $md = '01-15') => ((int) date('Y') - $n) . '-' . $md;
ck('ngv dob: a real date in the past is kept; a made-up or future one is not',
   NgvMember::cleanDob('2007-02-29') === '' && NgvMember::cleanDob('2008-02-29') === '2008-02-29' && NgvMember::cleanDob(((int) date('Y') + 1) . '-01-01') === '');
ck('ngv dob: age is whole years, and the birthday itself counts', NgvMember::ageFromDob($yearsAgo(17, date('m-d'))) === 17
   && NgvMember::ageFromDob($yearsAgo(17, '12-31')) === (date('m-d') === '12-31' ? 17 : 16));
ck('ngv dob: under 18 is read from the date of birth, and from an older application\'s typed age',
   NgvMember::applicantIsMinor(['dob' => $yearsAgo(16)]) && !NgvMember::applicantIsMinor(['dob' => $yearsAgo(19)])
   && NgvMember::applicantIsMinor(['dob' => '', 'age' => '15']) && !NgvMember::applicantIsMinor(['dob' => '', 'age' => '']));

/* ── 3. Enrolment makes the account ─────────────────────────────────────── */
$adult = NgvMember::submitApplication(['name' => 'Kemi Adult', 'email' => 'Kemi@Grd.test', 'dob' => $yearsAgo(20), 'phone' => '08031234567']);
$aRow = NgvMember::application($adult);
ck('ngv dob: the application stores the date of birth, and the age derived from it', $aRow['dob'] === $yearsAgo(20) && $aRow['age'] === '20');
$gSent = [];
$en = NgvMember::enrollApplication($adult, 0);
$acct = $gPdo->query("SELECT id FROM lms_users WHERE email = 'kemi@grd.test'")->fetchColumn();
ck('ngv enrol: an applicant with no account is enrolled, and the account is made for them',
   !empty($en['ok']) && !empty($en['account_created']) && (int) $acct === (int) $en['member_id'] && NgvMember::isVanguard((int) $acct));
ck('ngv enrol: …with an NGV card, and the application marked enrolled',
   GateAttendance::cardFor((int) $acct) !== null && NgvMember::application($adult)['status'] === 'enrolled');
$welcome = implode("\n", array_map(fn($m) => (string) ($m['html'] ?? ''), $gSent));
ck('ngv enrol: …and told how to sign in, with no password to make first', str_contains($welcome, 'You do not need to set a password first'));

/* ── 4. A child's guardian confirms it themselves ───────────────────────── */
$child = NgvMember::submitApplication(['name' => 'Tobi Young', 'email' => 'tobi@grd.test', 'dob' => $yearsAgo(15),
    'guardian_name' => 'Mrs Young', 'guardian_phone' => '08030000000', 'guardian_email' => 'Mum@Grd.test', 'guardian_consent' => true]);
$cRow = NgvMember::application($child);
ck('ngv guardian: a box the applicant ticked is not consent', (int) $cRow['guardian_consent'] === 0 && !NgvMember::guardianConfirmed($cRow));
$no = NgvMember::enrollApplication($child, 0);
ck('ngv guardian: a child is not enrolled before their guardian agrees, and staff are told why',
   empty($no['ok']) && ($no['code'] ?? '') === 'needs_guardian'
   && !$gPdo->query("SELECT id FROM lms_users WHERE email = 'tobi@grd.test'")->fetchColumn());

$gSent = [];
$ask = NgvMember::askGuardian($child);
ck('ngv guardian: the guardian is emailed, and the caller is told it went', !empty($ask['ok']) && !empty($ask['sent']) && count($gSent) === 1 && ($gSent[0]['to'] ?? '') === 'mum@grd.test');
$link = preg_match('/guardian\.php\?a=(\d+)(?:&amp;|&)t=([a-f0-9]{40})/', (string) ($gSent[0]['html'] ?? ''), $lm) ? $lm : null;
ck('ngv guardian: …with a link to a page for this application', $link && (int) $link[1] === $child);
$token = $link ? $link[2] : '';
ck('ngv guardian: only the link\'s hash is stored', NgvMember::application($child)['guardian_token'] === hash('sha256', $token));
ck('ngv guardian: a wrong token opens nothing', NgvMember::guardianRequest($child, str_repeat('a', 40)) === null && NgvMember::guardianRequest($child, $token) !== null);

$gSent = [];
NgvMember::askGuardian($child);
$token2 = preg_match('/t=([a-f0-9]{40})/', (string) ($gSent[0]['html'] ?? ''), $m2) ? $m2[1] : '';
ck('ngv guardian: a new link replaces the last, so an old forwarded one stops working',
   $token2 !== '' && NgvMember::guardianRequest($child, $token) === null && NgvMember::guardianRequest($child, $token2) !== null);

$gNgv->prepare('UPDATE ngv_applications SET guardian_asked_at = ? WHERE id = ?')
     ->execute([date('Y-m-d H:i:s', time() - (NgvMember::GUARDIAN_LINK_DAYS + 1) * 86400), $child]);
ck('ngv guardian: a link past its days opens nothing', NgvMember::guardianRequest($child, $token2) === null && empty(NgvMember::confirmGuardian($child, $token2)['ok']));
$gNgv->prepare('UPDATE ngv_applications SET guardian_asked_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $child]);

$ok = NgvMember::confirmGuardian($child, $token2);
$cRow = NgvMember::application($child);
ck('ngv guardian: the guardian agrees, and it is recorded with how', !empty($ok['ok']) && NgvMember::guardianConfirmed($cRow)
   && $cRow['guardian_method'] === 'email_link' && (int) $cRow['guardian_consent'] === 1);
ck('ngv guardian: …once: the link is spent', $cRow['guardian_token'] === '' && empty(NgvMember::confirmGuardian($child, $token2)['ok']));
$en2 = NgvMember::enrollApplication($child, 0);
ck('ngv guardian: then the child can be enrolled', !empty($en2['ok']) && NgvMember::isVanguard((int) $en2['member_id']));

/* The office has it another way: a signed form, in person, a call. */
$child2 = NgvMember::submitApplication(['name' => 'Ada Junior', 'email' => 'adaj@grd.test', 'dob' => $yearsAgo(14), 'guardian_name' => 'Mr Junior', 'guardian_phone' => '08030000001']);
ck('ngv guardian: with no guardian email there is nothing to send, and it says so', empty(NgvMember::askGuardian($child2)['ok']));
ck('ngv guardian: the office cannot record consent "by email link" — that is the guardian\'s own',
   empty(NgvMember::recordGuardianConsent($child2, 'email_link', 1)['ok']) && empty(NgvMember::recordGuardianConsent($child2, '', 1)['ok']));
ck('ngv guardian: the office records a signed form, and the child can be enrolled',
   !empty(NgvMember::recordGuardianConsent($child2, 'signed_form', 1)['ok']) && NgvMember::application($child2)['guardian_method'] === 'signed_form'
   && !empty(NgvMember::enrollApplication($child2, 1)['ok']));
ck('ngv guardian: an adult needs no guardian, and asking for one is refused', empty(NgvMember::askGuardian($adult)['ok']));

/* The pages: the guardian's link shows, and only a POST agrees. */
$page = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/guardian.php');
ck('ngv guardian: opening the link agrees to nothing — only the form\'s POST does (mail scanners open links)',
   str_contains($page, "=== 'POST'") && substr_count($page, 'NgvMember::confirmGuardian(') === 1
   && strpos($page, 'NgvMember::confirmGuardian(') > strpos($page, "=== 'POST'") && str_contains($page, "empty(\$_POST['agree'])"));
$console = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/members.php');
ck('ngv guardian: the console shows a child waiting, with Enrol off and why, and the two ways forward',
   str_contains($console, "\$minorA && !\$gOk ? ' disabled title=") && str_contains($console, 'data-act="app_guardian_resend"') && str_contains($console, 'data-act="app_guardian_record"'));

Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED' . ($gMailWas === false ? '' : '=' . $gMailWas));
