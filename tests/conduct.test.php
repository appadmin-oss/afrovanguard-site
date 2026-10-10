<?php
/**
 * tests/conduct.test.php — a mentor's fines and awards (lib/Conduct.php).
 *
 * The promises: a mentor proposes only for their OWN active mentee; nothing
 * is owed or granted until the compliance committee or a superadmin approves;
 * nobody decides a case they proposed or one about themselves; a fine needs
 * an NGV account and lands on it through NgvFines; an award's points are
 * granted once; rejecting needs a reason.
 */
declare(strict_types=1);
$db = Database::pdo();
$nf = NgvDb::pdo();
Conduct::ensure(); MentorPortal::ensure(); GateAttendance::ensure();
foreach (['conduct_cases', 'compliance_members', 'mentorships'] as $t) { try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$db->exec('DELETE FROM lms_users WHERE id BETWEEN 9300 AND 9399');
$db->exec("DELETE FROM gate_points WHERE member_id BETWEEN 9300 AND 9399");
foreach (['ngv_charges', 'ngv_fine_meta'] as $t) { try { $nf->exec("DELETE FROM $t WHERE " . ($t === 'ngv_charges' ? 'member_id' : 'charge_id') . ' > 0'); } catch (Throwable $e) {} }
$nf->exec('DELETE FROM ngv_participants WHERE member_id BETWEEN 9300 AND 9399');

$people = [9301 => ['Mentor Ada', 'mentor.cd@x.test'], 9302 => ['Mentee Bayo', 'bayo.cd@x.test'], 9303 => ['Mentee Chi', 'chi.cd@x.test'],
           9304 => ['Committee Dele', 'dele.cd@x.test'], 9305 => ['Super Eze', 'eze.cd@afrovanguard.org.ng'], 9306 => ['Other Mentor', 'other.cd@x.test']];
foreach ($people as $id => [$n, $e]) $db->prepare('INSERT INTO lms_users (id, name, email, password_hash) VALUES (?,?,?,?)')->execute([$id, $n, $e, 'x']);
$nf->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (9302,'Mentee Bayo','bayo.cd@x.test','active','2026-01-05','2026-01-05','2026-01-05')")->execute();
$now = gmdate('Y-m-d H:i:s');
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at, updated_at) VALUES (93001, 9301, 9302, 'active', ?, ?)")->execute([$now, $now]);
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at, updated_at) VALUES (93002, 9301, 9303, 'active', ?, ?)")->execute([$now, $now]);
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at, updated_at) VALUES (93003, 9306, 9303, 'ended', ?, ?)")->execute([$now, $now]);
AdminRoles::add('eze.cd@afrovanguard.org.ng', 'superadmin', 'test');
$U = static fn(int $id): array => ['id' => $id, 'name' => $people[$id][0], 'email' => $people[$id][1]];
$today = date('Y-m-d');
$ev = 'Arrived and shouted at a facilitator in front of the group on Saturday morning.';

/* ── proposing ── */
ck('conduct: a mentor cannot propose for somebody else\'s mentee', !Conduct::propose(9306, 93001, ['kind' => 'award', 'reason' => 'growth', 'points' => 5, 'occurred_on' => $today, 'evidence' => $ev])['ok']);
ck('conduct: an ended pairing cannot be used', !Conduct::propose(9306, 93003, ['kind' => 'award', 'reason' => 'growth', 'points' => 5, 'occurred_on' => $today, 'evidence' => $ev])['ok']);
$r = Conduct::propose(9301, 93001, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '2,000', 'occurred_on' => $today, 'evidence' => 'Was rude.']);
ck('conduct: evidence of a sentence or two is required', !$r['ok']);
$r = Conduct::propose(9301, 93001, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '2,000', 'occurred_on' => date('Y-m-d', strtotime('+1 day')), 'evidence' => $ev]);
ck('conduct: a future day is refused', !$r['ok']);
$r = Conduct::propose(9301, 93001, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '999999', 'occurred_on' => $today, 'evidence' => $ev]);
ck('conduct: a fine above the cap is refused', !$r['ok']);
$r = Conduct::propose(9301, 93002, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '2000', 'occurred_on' => $today, 'evidence' => $ev]);
ck('conduct: a mentee with no NGV account cannot be fined money', !$r['ok'] && str_contains($r['error'], 'NextGen Vanguard'));
$fine = Conduct::propose(9301, 93001, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '2,000', 'occurred_on' => $today, 'evidence' => $ev]);
ck('conduct: a mentor proposes a fine for their own NGV mentee', $fine['ok']);
ck('conduct: the same thing twice on one day is refused', !Conduct::propose(9301, 93001, ['kind' => 'fine', 'reason' => 'conduct', 'amount' => '2000', 'occurred_on' => $today, 'evidence' => $ev])['ok']);
$owedBefore = (int) $nf->query("SELECT COUNT(*) FROM ngv_charges WHERE member_id = 9302 AND kind = 'fine'")->fetchColumn();
ck('conduct: proposing posts nothing to the account', $owedBefore === 0);
$award = Conduct::propose(9301, 93002, ['kind' => 'award', 'reason' => 'leadership', 'title' => 'Led the Saturday clean-up', 'points' => 10, 'occurred_on' => $today,
    'evidence' => 'Organised twelve members, brought the tools, and stayed until the street was clear.']);
ck('conduct: any mentee can be given an award', $award['ok']);

/* ── deciding ── */
ck('conduct: a mentor is not a decider', !Conduct::decide($fine['id'], $U(9301), true, '')['ok']);
ck('conduct: somebody off the committee cannot decide', !Conduct::decide($fine['id'], $U(9306), true, '')['ok']);
Conduct::addMember('dele.cd@x.test', $U(9304));
ck('conduct: only a superadmin keeps the committee list', !Conduct::isCommittee(9304));
ck('conduct: a superadmin is a superior', Conduct::isSuperior($U(9305)) && Conduct::canDecide($U(9305)));
ck('conduct: a superadmin adds a committee member', Conduct::addMember('dele.cd@x.test', $U(9305))['ok'] && Conduct::isCommittee(9304));
ck('conduct: rejecting needs a reason', !Conduct::decide($award['id'], $U(9304), false, '')['ok']);

$d = Conduct::decide($fine['id'], $U(9304), true, 'Seen by two facilitators.');
ck('conduct: the committee approves a fine', $d['ok'] && $d['status'] === 'approved');
$charge = $nf->query("SELECT amount, reason FROM ngv_charges WHERE member_id = 9302 AND kind = 'fine'")->fetch(PDO::FETCH_ASSOC);
ck('conduct: an approved fine is on the mentee\'s NGV account', $charge && (int) $charge['amount'] === 2000 && $charge['reason'] === 'conduct');
ck('conduct: the fine is owed like any other', (int) NgvFines::forMember(9302)['owing'] === 2000);
ck('conduct: a case is decided once', !Conduct::decide($fine['id'], $U(9305), true, '')['ok']);

$d2 = Conduct::decide($award['id'], $U(9305), true, '');
ck('conduct: a superadmin approves an award', $d2['ok']);
$pts = (int) $db->query("SELECT SUM(points) FROM gate_points WHERE member_id = 9303")->fetchColumn();
ck('conduct: an approved award grants its points', $pts === 10);
GateAttendance::grant(9303, $today, 'award:' . $award['id'], 10, 'again');
ck('conduct: an award\'s points are granted once', (int) $db->query("SELECT SUM(points) FROM gate_points WHERE member_id = 9303")->fetchColumn() === 10);
ck('conduct: the mentee sees their award', count(Conduct::awardsFor(9303)) === 1 && Conduct::awardsFor(9302) === []);

/* Nobody decides what they proposed or what is about them. */
Conduct::addMember('mentor.cd@x.test', $U(9305));
$own = Conduct::propose(9301, 93002, ['kind' => 'award', 'reason' => 'growth', 'points' => 3, 'occurred_on' => $today, 'evidence' => 'Kept a reading streak going for six weeks without a gap.']);
ck('conduct: a committee member cannot approve their own proposal', !Conduct::decide($own['id'], $U(9301), true, '')['ok']);
Conduct::addMember('chi.cd@x.test', $U(9305));
ck('conduct: a committee member cannot decide a case about themselves', !Conduct::decide($own['id'], $U(9303), true, '')['ok']);
ck('conduct: the proposer can withdraw while it waits', Conduct::withdraw(9301, $own['id'])['ok'] && Conduct::find($own['id'])['status'] === 'withdrawn');
ck('conduct: nobody else can withdraw it', !Conduct::withdraw(9306, $award['id'])['ok']);

/* Kinds of mentor. */
ck('conduct: pairings start as Academy mentorships', Conduct::pairing(9301, 93001)['kind'] === 'academy');
ck('conduct: the committee marks a Vanguard Quest mentorship', Conduct::setPairingKind(93001, 'vquest', $U(9304))['ok'] && Conduct::pairing(9301, 93001)['kind'] === 'vquest');
ck('conduct: a mentor cannot mark their own pairing', !Conduct::setPairingKind(93002, 'vquest', $U(9306))['ok']);
$vq = Conduct::propose(9301, 93001, ['kind' => 'award', 'reason' => 'values', 'points' => 4, 'occurred_on' => $today, 'evidence' => 'Spoke about their family story with pride at the chapter session.']);
ck('conduct: a case records which kind of mentor asked', Conduct::find($vq['id'])['mentor_kind'] === 'vquest');

/* The pages and endpoint. */
$ep = (string) file_get_contents(AV_ROOT . '/mentorship/conduct.php');
ck('conduct endpoint: same-origin and CSRF on every post', str_contains($ep, 'require_same_origin()') && str_contains($ep, "av_csrf_valid((string) (\$_POST['csrf']"));
foreach (['/mentorship/mentor/views/conduct.php', '/mentorship/compliance/index.php'] as $f) {
    ck('conduct page ' . basename(dirname($f)) . ': no inline styles', !preg_match('/\sstyle\s*=/', (string) file_get_contents(AV_ROOT . $f)));
}
ck('conduct css: tokens only', !preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) file_get_contents(AV_ROOT . '/mentorship/conduct.css')));

$db->exec('DELETE FROM conduct_cases'); $db->exec('DELETE FROM compliance_members');
$db->exec("DELETE FROM admin_users WHERE email = 'eze.cd@afrovanguard.org.ng'");
