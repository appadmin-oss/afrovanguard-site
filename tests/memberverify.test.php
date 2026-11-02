<?php
/**
 * tests/memberverify.test.php — being a member is an act with a record, not an
 * email domain (lib/MemberVerification.php).
 *
 * The first assertion is the one that matters most: NOBODY the old rule
 * covered loses access when this ships. A security change that locks the
 * organisation out of its own portal gets turned off within the day, and then
 * it protects nothing at all.
 *
 *   • Everybody the old rule covered is grandfathered, once, and keeps their
 *     access — and is on the list the office still owes a decision.
 *   • An org address confers NOTHING on anyone new: signing in makes a
 *     learner, and signing in again does not promote them.
 *   • Verifying needs a BASIS. "They have our email address" is what this
 *     replaced, so a verification with nothing behind it is refused.
 *   • Declined beats an elevated role and the address both.
 *   • The record says who, when and on what basis, and is kept.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mvPdo = Database::pdo();
MemberRoster::ensure(); MemberVerification::ensure();
$mvPdo->exec("DELETE FROM member_verify_log WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mver.test' OR email LIKE '%@afrovanguard.org.ng' AND name LIKE 'Mver %')");
$mvPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE name LIKE 'Mver %')");
$mvPdo->exec("DELETE FROM lms_users WHERE name LIKE 'Mver %'");

$mvMk = function (string $name, string $email, string $role = 'learner') use ($mvPdo): int {
    $mvPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['Mver ' . $name, $email, 'x', $role, 'active', gmdate('Y-m-d H:i:s')]);
    return (int) $mvPdo->lastInsertId();
};
$mvUser = static fn(int $id) => Database::pdo()->query('SELECT * FROM lms_users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: null;
$mvIsMember = static fn(int $id) => MemberRoster::isMember($id);

/* ── Nobody is locked out ────────────────────────────────────────────── */
/* The backfill is deliberately global — that is the point of it — so this
   test notes who already had a state, forces a run, and puts the database
   back at the end. Without that, every other test's fixtures come out of
   this file grandfathered, and the next file along finds its learner is a
   member. (It did. tests/todo.test.php caught it.) */
$mvBefore = array_map('intval', $mvPdo->query("SELECT user_id FROM member_profiles WHERE verified_state <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$mvMarkerWas = Database::metaGet(MemberVerification::BACKFILL_KEY);

/* Somebody the OLD rule covered and the new one would not: a learner on an
   org address. Before the backfill they are nobody; the backfill is what
   keeps them where they were. */
$mvOld = $mvMk('Old Hand', 'mver.old.hand@afrovanguard.org.ng', 'learner');
MemberVerification::grandfatherAll('test', true);
ck('verify: everybody the old rule covered is grandfathered, so nobody loses access',
   MemberVerification::state($mvOld) === 'grandfathered'
   && MemberVerification::isVerified($mvOld) === true
   && LmsAuth::isOrgMember($mvUser($mvOld)) === true
   && $mvIsMember($mvOld) === true);
ck('verify: …and they are on the list the office still owes a decision',
   in_array($mvOld, array_column(MemberVerification::toVerify(500), 'id'), true));
$mvAgain = MemberVerification::grandfatherAll('test');
ck('verify: the backfill runs once, not on every request',
   !empty($mvAgain['already']) || (int) $mvAgain['marked'] === 0);
/* It CLAIMS the marker BEFORE working, so two processes arriving together on
   the first request after a deploy do not both do the whole pass. The claim
   has to be WRITTEN, not merely read: asserting only that a running marker is
   respected passes just as well when nothing ever writes one. */
Database::metaSet(MemberVerification::BACKFILL_KEY, '');
ck('verify: an unclaimed backfill is taken, and the claim is written down',
   MemberVerification::claimBackfill() === 'mine'
   && str_starts_with((string) Database::metaGet(MemberVerification::BACKFILL_KEY), 'running:'));
ck('verify: …so the next process along finds it taken and stands back',
   MemberVerification::claimBackfill() === 'running'
   && ($mvRacing = MemberVerification::grandfatherAll('test')) && !empty($mvRacing['running']));
Database::metaSet(MemberVerification::BACKFILL_KEY, 'running:' . (time() - MemberVerification::CLAIM_SECONDS - 60));
ck('verify: …but a claim whose process died is taken over, not waited on for ever',
   MemberVerification::claimBackfill() === 'mine');
Database::metaSet(MemberVerification::BACKFILL_KEY, gmdate('Y-m-d H:i:s') . ' · 0');
ck('verify: …and once it is done, nobody runs it again',
   MemberVerification::claimBackfill() === 'already');

/* ── The address confers nothing on anybody new ──────────────────────── */
$mvNew = $mvMk('New Face', 'mver.new.face@afrovanguard.org.ng', 'learner');
ck('verify: a NEW account on an org address is not a member',
   MemberVerification::state($mvNew) === ''
   && LmsAuth::isOrgMember($mvUser($mvNew)) === false
   && $mvIsMember($mvNew) === false);
ck('verify: …and they are on the list, with the reason in words',
   (function () use ($mvNew) {
       foreach (MemberVerification::toVerify(500) as $w) if ($w['id'] === $mvNew) return str_contains($w['why'], 'no longer enough');
       return false;
   })());
$mvSrc = (string) file_get_contents(AV_ROOT . '/lib/LmsAuth.php');
ck('verify: signing in no longer mints a member, nor promotes a learner, on the address',
   !str_contains($mvSrc, "\$role = self::isOrgEmail(\$email) ? 'member' : 'learner';")
   && !str_contains($mvSrc, "UPDATE lms_users SET role = 'member' WHERE id = ?")
   && !preg_match('/function isOrgMember(.|\n)*?isOrgEmail/', $mvSrc));

/* ── Verifying is a decision, and needs a basis ──────────────────────── */
$mvNoBasis = MemberVerification::verify($mvNew, '   ', 'desk@test');
ck('verify: a verification with nothing behind it is refused', ($mvNoBasis['code'] ?? '') === 'no_basis');
ck('verify: …and the refusal names what this replaced',
   str_contains((string) ($mvNoBasis['error'] ?? ''), 'email address'));
$mvOk = MemberVerification::verify($mvNew, 'Saw their ID at the Egbeda centre', 'desk@test', 'joined the media team');
ck('verify: a verification with a basis makes them a member',
   !empty($mvOk['ok']) && MemberVerification::state($mvNew) === 'verified'
   && LmsAuth::isOrgMember($mvUser($mvNew)) === true && $mvIsMember($mvNew) === true);
ck('verify: …and they leave the list',
   !in_array($mvNew, array_column(MemberVerification::toVerify(500), 'id'), true));
$mvHist = MemberVerification::historyFor($mvNew);
ck('verify: the record says who, when and on what basis',
   count($mvHist) === 1 && $mvHist[0]['action'] === 'verify' && $mvHist[0]['by'] === 'desk@test'
   && $mvHist[0]['basis'] === 'Saw their ID at the Egbeda centre' && $mvHist[0]['at'] !== '');

/* ── Declined beats the role and the address ─────────────────────────── */
$mvStaffish = $mvMk('Left Us', 'mver.left.us@afrovanguard.org.ng', 'member');
ck('verify: an elevated role is itself an act, so it still makes a member',
   LmsAuth::isOrgMember($mvUser($mvStaffish)) === true);
$mvNoReason = MemberVerification::decline($mvStaffish, '  ', 'desk@test');
ck('verify: declining without a reason is refused', ($mvNoReason['code'] ?? '') === 'no_reason');
MemberVerification::decline($mvStaffish, 'Left the organisation in 2024; the address is a forward', 'desk@test');
ck('verify: declined beats BOTH an elevated role and the address',
   MemberVerification::state($mvStaffish) === 'declined'
   && MemberVerification::isVerified($mvStaffish) === false
   && LmsAuth::isOrgMember($mvUser($mvStaffish)) === false
   && $mvIsMember($mvStaffish) === false);

/* ── Taking it back is not a suspension ──────────────────────────────── */
MemberVerification::unverify($mvNew, 'The ID turned out to be somebody else’s', 'desk@test');
ck('verify: unverifying returns them to nothing, not to declined',
   MemberVerification::state($mvNew) === '' && MemberVerification::isDeclined($mvNew) === false
   && LmsAuth::isOrgMember($mvUser($mvNew)) === false);
ck('verify: …and every decision is kept, newest first',
   count(MemberVerification::historyFor($mvNew)) === 2
   && MemberVerification::historyFor($mvNew)[0]['action'] === 'unverify');

/* A verified LEARNER is a member: that is the whole point of the route. */
$mvOutsider = $mvMk('Outsider', 'mver.outsider@gmail.test', 'learner');
ck('verify: a learner on no org address is nobody until somebody says otherwise',
   $mvIsMember($mvOutsider) === false && LmsAuth::isOrgMember($mvUser($mvOutsider)) === false);
MemberVerification::verify($mvOutsider, 'Signed the membership form at the AGM', 'desk@test');
ck('verify: …and a verified learner IS a member, whatever their address',
   $mvIsMember($mvOutsider) === true && LmsAuth::isOrgMember($mvUser($mvOutsider)) === true);

/* ── Only a verified member owes dues ────────────────────────────────── */
/* An unverified person is a volunteer with limited access. Sending an
   invoice to somebody the organisation has not confirmed a relationship with
   is the wrong way round. */
Database::metaSet(Membership::SETTINGS_KEY, (string) json_encode(
    ['enabled' => true, 'monthly_ngn' => 1000, 'annual_ngn' => 12000, 'from_level' => 'O', 'start' => '2026-01-01']));
Membership::forgetSettings();
$mvDues = $mvMk('Owes Nothing', 'mver.owes.nothing@gmail.test', 'member');
$mvPdo->prepare("INSERT INTO member_profiles (user_id, joined_on, phone, centre, notes, work_roles, about, source, updated_at) VALUES (?, '2026-02-01', '', '', '', '', '', '', '')")->execute([$mvDues]);
$mvPdo->prepare("UPDATE lms_users SET level = 'O', created_at = '2026-02-01 09:00:00' WHERE id = ?")->execute([$mvDues]);
$mvOb2 = Membership::obligation($mvDues);
ck('verify: an unverified person owes no dues, and the reason says volunteer',
   $mvOb2['owes'] === false && $mvOb2['code'] === 'unverified' && str_contains($mvOb2['why'], 'volunteer'));
ck('verify: …and they are not in the obliged list at all',
   !isset(Membership::arrearsFor([$mvDues], (int) strtotime('2026-10-23 12:00:00 UTC'))[$mvDues]));

/* Verifying them starts the clock THERE, not at the day they joined: they
   owed nothing for the months nobody had checked who they were. */
MemberVerification::verify($mvDues, 'Signed the form at the centre', 'desk@test');
$mvPdo->prepare("UPDATE member_profiles SET verified_at = '2026-08-10 10:00:00' WHERE user_id = ?")->execute([$mvDues]);
$mvPdo->prepare("UPDATE member_verify_log SET acted_at = '2026-08-10 10:00:00' WHERE user_id = ? AND action = 'verify'")->execute([$mvDues]);
$mvA2 = Membership::arrearsFor([$mvDues], (int) strtotime('2026-10-23 12:00:00 UTC'))[$mvDues] ?? null;
ck('verify: dues begin the day they were verified, not the day they joined',
   $mvA2 && $mvA2['since'] === '2026-08-10' && $mvA2['months'] === 3);

/* A GRANDFATHERED member was being charged the day before this shipped. The
   office doing its paperwork must not wipe a real debt. */
$mvGf = $mvMk('Long Standing', 'mver.long.standing@gmail.test', 'member');
$mvPdo->prepare("INSERT INTO member_profiles (user_id, joined_on, phone, centre, notes, work_roles, about, source, updated_at) VALUES (?, '2026-02-01', '', '', '', '', '', '', '')")->execute([$mvGf]);
$mvPdo->prepare("UPDATE lms_users SET level = 'O', created_at = '2026-02-01 09:00:00' WHERE id = ?")->execute([$mvGf]);
MemberVerification::grandfatherAll('test', true);
MemberVerification::verify($mvGf, 'Known to the team for years', 'desk@test');
$mvA3 = Membership::arrearsFor([$mvGf], (int) strtotime('2026-10-23 12:00:00 UTC'))[$mvGf] ?? null;
ck('verify: verifying a grandfathered member does NOT wipe what they already owed',
   $mvA3 && $mvA3['since'] === '2026-02-01' && $mvA3['months'] === 9);

/* The community bot is an account, not a person. A list with something
   nobody can ever action at the top is a list people stop reading. */
$mvBotEmail = class_exists('Community') ? Community::BOT_EMAIL : 'community-bot@afrovanguard.org.ng';
ck('verify: the community bot is not on the list of people to check',
   !in_array(strtolower($mvBotEmail), array_map('strtolower', array_column(MemberVerification::toVerify(1000), 'email')), true));

/* ── The office can reach it ─────────────────────────────────────────── */
$mvApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('verify: the Studio has the actions, and they are management\'s',
   str_contains($mvApi, "case 'member_verify_list':") && str_contains($mvApi, "case 'member_verify':")
   && str_contains($mvApi, "case 'member_decline':") && str_contains($mvApi, "case 'member_unverify':")
   && preg_match('/\$managementOnly = \[(.|\n)*?\];/', $mvApi, $mvMg) && str_contains($mvMg[0], "'member_verify'"));
ck('verify: …and the member is TOLD, rather than silently shown less',
   (function () {
       $v = (string) file_get_contents(AV_ROOT . '/portal/views/membership.php');
       return str_contains($v, 'Membership not confirmed yet') && str_contains($v, 'MemberVerification::isDeclined');
   })());
ck('verify: …a pane that says who is waiting and why',
   str_contains((string) file_get_contents(AV_ROOT . '/admin/index.php'), 'data-pane="verify"')
   && str_contains((string) file_get_contents(AV_ROOT . '/admin/app.js'), "post('member_verify'"));

/* ── Clean up, including everything the forced backfill touched ──────── */
$mvPdo->exec("DELETE FROM member_verify_log WHERE user_id IN (SELECT id FROM lms_users WHERE name LIKE 'Mver %')");
$mvPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE name LIKE 'Mver %')");
$mvPdo->exec("DELETE FROM lms_users WHERE name LIKE 'Mver %'");
/* Everybody the forced run marked who did not have a state before it. */
$mvKeep = $mvBefore ? ' AND user_id NOT IN (' . implode(',', $mvBefore) . ')' : '';
$mvPdo->exec("UPDATE member_profiles SET verified_state = '', verified_by = '', verified_at = '', verified_basis = ''
               WHERE verified_state = 'grandfathered'$mvKeep");
$mvPdo->exec("DELETE FROM member_verify_log WHERE action = 'grandfather' AND acted_by = 'test'");
/* The rows went back; the per-request memo has to as well, or the next test
   file in this process reads a state that is no longer in the database.
   (tests/todo.test.php found exactly that, twice.) */
MemberVerification::forget();
if ($mvMarkerWas === null) { try { Database::metaSet(MemberVerification::BACKFILL_KEY, ''); } catch (Throwable $e) {} }
ck('verify: the test puts the database back — no other fixture is left grandfathered',
   (int) $mvPdo->query("SELECT COUNT(*) FROM member_profiles WHERE verified_state = 'grandfathered'$mvKeep")->fetchColumn() === 0);
