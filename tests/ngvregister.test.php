<?php
/**
 * tests/ngvregister.test.php — applying, being enrolled, and NGG promotions.
 *
 *   • A repeat application for an address overwrote the first — name, phone,
 *     message — with no proof of owning the address.
 *   • Enrolment matched the account by exact email, so a capitalised address
 *     typed on a phone found "no account" though one existed.
 *   • Enrolling from an application issued no NGV card, dropped the plan, and
 *     blanked an existing track.
 *   • Two NGG members sharing a parent's email became one account and one
 *     vanguard; a staff account under an address was enrolled too.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rgPdo = Database::pdo();
$rgNgv = NgvDb::pdo();
foreach (['ngv_applications', 'ngv_participants'] as $t) { try { $rgNgv->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$rgPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@reg.test'");

/* ── A repeat cannot rewrite somebody else's application ────────────────── */
$a1 = NgvMember::submitApplication(['name' => 'Bisi Real', 'email' => 'Bisi@Reg.test', 'phone' => '08011111111', 'message' => 'I want to learn.']);
$a2 = NgvMember::submitApplication(['name' => 'Somebody Else', 'email' => 'bisi@reg.test', 'phone' => '09099999999', 'message' => 'withdraw me please']);
$app = NgvMember::application($a1);
ck('ngv register: a repeat from the same address is the same application', $a1 > 0 && $a1 === $a2);
ck('ngv register: …and does not replace what the first said', $app['name'] === 'Bisi Real' && $app['phone'] === '08011111111');
ck('ngv register: …what it would change is recorded for the reviewer, marked unverified',
   str_contains($app['message'], 'unverified') && str_contains($app['message'], 'Somebody Else') && str_contains($app['message'], 'I want to learn.'));
ck('ngv register: the address is stored lowercased, as accounts are', $app['email'] === 'bisi@reg.test');

/* ── Enrolment finds the account whatever the case, and completes it ───── */
$rgPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute(['Ada Obi', 'ada.obi@reg.test', 'x', 'learner', 'active']);
$ada = (int) $rgPdo->lastInsertId();
$rgNgv->prepare("INSERT INTO ngv_applications (name, email, track, plan, status) VALUES (?, ?, ?, ?, 'new')")->execute(['Ada Obi', 'Ada.Obi@Reg.test', '', '']);
$appId = (int) $rgNgv->lastInsertId();
ck('ngv register: an application typed with capitals still finds the account', NgvMember::autolinkApplication($appId));
NgvMember::ensureParticipant($ada, ['name' => 'Ada Obi', 'email' => 'ada.obi@reg.test']);
$rgNgv->exec("UPDATE ngv_participants SET track = 'TECHOME' WHERE member_id = $ada");
$en = NgvMember::enrollApplication($appId, 0);
ck('ngv register: enrolling from it succeeds', !empty($en['ok']) && (int) $en['member_id'] === $ada);
ck('ngv register: …an empty track on the application does not blank the participant\'s', (string) NgvMember::participant($ada)['track'] === 'TECHOME');
ck('ngv register: …and they get an NGV ID card, as NGG promotions always did', (bool) preg_match(GateAttendance::CARD_PATTERN, (string) GateAttendance::cardFor($ada)));

/* ── NGG promotions: one email is not one person ────────────────────────── */
NgvIntake::ensure();
$rgPdo->exec("DELETE FROM ngv_intake WHERE email LIKE '%@reg.test'");
$s1 = NgvIntake::take(['nggMemberId' => 'NGG-REG-1', 'name' => 'Tola Ade', 'email' => 'parent@reg.test']);
$s2 = NgvIntake::take(['nggMemberId' => 'NGG-REG-2', 'name' => 'Femi Ade', 'email' => 'parent@reg.test']);
ck('ngv register: the first sibling is promoted', !empty($s1['ok']) && (int) ($s1['member_id'] ?? 0) > 0);
ck('ngv register: the second, on the same parent\'s email, is held for a person rather than merged into the first',
   ($s2['status'] ?? '') === 'needs_review' && in_array('NGG-REG-2', array_column(NgvIntake::waiting(), 'ngg_member_id'), true));
ck('ngv register: …and the first vanguard keeps their own name', (string) NgvMember::participant((int) $s1['member_id'])['name'] === 'Tola Ade');
$rgPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute(['Coord', 'coord@reg.test', 'x', 'admin', 'active']);
$st = NgvIntake::take(['nggMemberId' => 'NGG-REG-3', 'name' => 'Child Of Coord', 'email' => 'coord@reg.test']);
ck('ngv register: a promotion onto a staff account is held, not enrolled', ($st['status'] ?? '') === 'needs_review');

/* ── The form ───────────────────────────────────────────────────────────── */
$form = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/register.php');
ck('ngv register: an applicant under 18 needs a guardian, their phone and their agreement',
   str_contains($form, "\$minor && (trim(\$old['guardian_name']) === '' || trim(\$old['guardian_phone']) === '' || !\$guardianOk)"));
ck('ngv register: everybody agrees to the privacy notice before anything is stored', strpos($form, "elseif (!\$agreed)") < strpos($form, 'NgvMember::submitApplication('));
$js = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/reading.js');
ck('ngv register: book claims post to the dashboard, which handles them, from wherever the page is shown',
   str_contains($js, "fetch('/academy/ngv/dashboard.php'") && !str_contains($js, 'fetch(location.pathname'));
