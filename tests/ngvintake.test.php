<?php
/**
 * tests/ngvintake.test.php — NGG promotes somebody to NGV; they get an NGV account here.
 *
 *   • A promotion is an invitation to register at a CACENTRE (29 Oct 2026):
 *     an application, already filled in and invited. No account yet.
 *   • Registered at the desk, a new address becomes an account; one that
 *     already has an account is linked. Either way they are enrolled in NGV and
 *     given an NGV ID — a vanguard, so their NGV views open in the portal.
 *   • NGG retries, and promotes people again: one person, one application, one account.
 *   • No email, no invented address: it waits, visibly, for the office to give one.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_ngvdesk.php';

$niPdo = Database::pdo();
NgvIntake::ensure();
$niPdo->exec('DELETE FROM ngv_intake');
$niPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@intake.test'");
NgvDb::pdo()->exec("DELETE FROM ngv_applications WHERE email LIKE '%@intake.test'");

$r = NgvIntake::take(['nggMemberId' => 'NGG-2026-0101', 'name' => 'Tomi Promoted', 'email' => 'Tomi@Intake.test', 'phone' => '8031234567',
    'centre' => 'Egbeda', 'dob' => '2005-03-09', 'track' => 'TECHOME'], 'EV-1');
$tApp = NgvMember::application((int) ($r['application_id'] ?? 0));
ck('Intake: a promotion is an invitation to register, not an account', $r['ok'] && $r['status'] === 'invited' && $tApp
    && (string) $tApp['status'] === 'invited' && (string) $tApp['source'] === 'ngg' && (string) $tApp['ref'] === (string) $r['ref']
    && (int) $niPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'tomi@intake.test'")->fetchColumn() === 0);
ck('Intake: …the application carries what NGG sent', $tApp && $tApp['email'] === 'tomi@intake.test' && $tApp['dob'] === '2005-03-09'
    && $tApp['track'] === 'TECHOME' && $tApp['centre'] === 'Egbeda');

$again = NgvIntake::take(['nggMemberId' => 'NGG-2026-0101', 'name' => 'Tomi Promoted', 'email' => 'tomi@intake.test'], 'EV-2');
ck('Intake: the same promotion again — a retry — is the same invitation',
    $again['status'] === 'already_invited' && (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_applications WHERE email = 'tomi@intake.test'")->fetchColumn() === 1);

$done = ngv_test_register((int) $r['application_id']);
$tomi = (int) ($done['member_id'] ?? 0);
ck('Intake: registered at the desk, they get an account', !empty($done['ok']) && $tomi > 0
    && (string) $niPdo->query("SELECT email FROM lms_users WHERE id = $tomi")->fetchColumn() === 'tomi@intake.test');
ck('Intake: …enrolled in NGV, so a vanguard here', NgvMember::participant($tomi) !== null && NgvMember::isVanguard($tomi));
ck('Intake: …with an NGV ID card and a secure gate card', (bool) preg_match(GateAttendance::CARD_PATTERN, (string) GateAttendance::cardFor($tomi)) && MemberCards::secure($tomi) !== null);
ck('Intake: …their track carried into the programme', (string) NgvMember::participant($tomi)['track'] === 'TECHOME');
ck('Intake: …and the promotion is linked to them', ($row = $niPdo->query("SELECT status, member_id FROM ngv_intake WHERE ngg_member_id = 'NGG-2026-0101'")->fetch(PDO::FETCH_ASSOC))
    && $row['status'] === 'linked' && (int) $row['member_id'] === $tomi);
$again = NgvIntake::take(['nggMemberId' => 'NGG-2026-0101', 'name' => 'Tomi Promoted', 'email' => 'tomi@intake.test'], 'EV-3');
ck('Intake: promoted again once registered — still one account', $again['status'] === 'already' && (int) $again['member_id'] === $tomi
    && (int) $niPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'tomi@intake.test'")->fetchColumn() === 1);

$niPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute(['Ade Existing', 'ade@intake.test', 'x', 'member', 'active']);
$ade = (int) $niPdo->lastInsertId();
$l = NgvIntake::take(['nggMemberId' => 'NGG-2026-0102', 'name' => 'Ade From NGG', 'email' => 'ADE@intake.test']);
$ld = ngv_test_register((int) ($l['application_id'] ?? 0));
ck('Intake: an email that already has an account is that account — linked, not duplicated', $l['status'] === 'invited' && (int) ($ld['member_id'] ?? 0) === $ade
    && (string) $niPdo->query("SELECT name FROM lms_users WHERE id = $ade")->fetchColumn() === 'Ade Existing');
ck('Intake: …and now a vanguard', NgvMember::isVanguard($ade));

NgvDb::pdo()->prepare("INSERT INTO ngv_applications (name, email, track, plan, status, source) VALUES (?, ?, '', '', 'new', 'web')")->execute(['Sade Self', 'sade@intake.test']);
$selfApp = (int) NgvDb::pdo()->lastInsertId();
$sp = NgvIntake::take(['nggMemberId' => 'NGG-2026-0104', 'name' => 'Sade Self', 'email' => 'sade@intake.test']);
ck('Intake: somebody who already applied themselves has THAT application invited — not a second one', ($sp['status'] ?? '') === 'invited'
    && (int) $sp['application_id'] === $selfApp && (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_applications WHERE email = 'sade@intake.test'")->fetchColumn() === 1);

$n = NgvIntake::take(['nggMemberId' => 'NGG-2026-0103', 'name' => 'Kemi No Mail', 'email' => '']);
ck('Intake: no email, no invented address — it waits, and the dashboard lists it', $n['ok'] && $n['status'] === 'needs_email'
    && in_array('NGG-2026-0103', array_column(NgvIntake::waiting(), 'ngg_member_id'), true)
    && (int) $niPdo->query("SELECT COUNT(*) FROM lms_users WHERE name = 'Kemi No Mail'")->fetchColumn() === 0);
ck('Intake: a bad address is refused the same way', NgvIntake::supplyEmail('NGG-2026-0103', 'not-an-email')['status'] === 'needs_email');
$s = NgvIntake::supplyEmail('NGG-2026-0103', 'kemi@intake.test');
ck('Intake: the office gives the email and the invitation goes at once', $s['status'] === 'invited' && (int) ($s['application_id'] ?? 0) > 0
    && !in_array('NGG-2026-0103', array_column(NgvIntake::waiting(), 'ngg_member_id'), true));
ck('Intake: something that is not a promotion is refused', NgvIntake::take(['name' => 'Nobody'])['status'] === 'bad_event');

$ep = (string) file_get_contents(AV_ROOT . '/integrations/ngg.php');
ck('Intake: the endpoint checks NGG\'s signature, and does not exist without a secret',
    str_contains($ep, "hash_hmac('sha256', \$ts . '.' . \$raw") && str_contains($ep, 'hash_equals') && str_contains($ep, "'not-configured'], 404"));
ck('Intake: …answers a promotion it cannot use 2xx, so NGG does not retry what a retry cannot fix', str_contains($ep, "(\$r['status'] ?? '') === 'bad_event' ? 200 : 500"));

$niPdo->exec('DELETE FROM ngv_intake');
NgvDb::pdo()->exec("DELETE FROM ngv_applications WHERE email LIKE '%@intake.test'");
foreach ([$tomi, $ade] as $id) { try { NgvDb::pdo()->exec('DELETE FROM ngv_participants WHERE member_id = ' . (int) $id); } catch (Throwable $e) {} }
$niPdo->exec("DELETE FROM gate_member_cards WHERE member_id IN ($tomi, $ade)");
$niPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@intake.test'");
