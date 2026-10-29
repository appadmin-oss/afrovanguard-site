<?php
/**
 * tests/accountsecurity.test.php — the member-management audit's urgent fixes.
 *
 *   • An account nobody verified is not the address owner's to inherit: the
 *     first proof of the address (a code, Google) discards the password and
 *     sessions whoever registered it chose.
 *   • A new password, or a new email, signs the account out everywhere else.
 *   • The volunteer people search is for leads and team runners, and shows
 *     no addresses.
 *   • Editors don't reach member data; imports obey the desk's guards and its
 *     two-person rule.
 *   • A child promoted from NGG waits for a guardian's consent.
 *   • Backups are taken, encrypted, restorable and pruned.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$asPdo = Database::pdo();
$asPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@acctsec.test'");
$asSess = static function (int $uid, string $tok) use ($asPdo): void {
    $asPdo->prepare('INSERT INTO lms_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)')->execute([hash('sha256', $tok), $uid, gmdate('Y-m-d H:i:s', time() + 3600)]);
};
$asSessions = static fn(int $uid): int => (int) $asPdo->query("SELECT COUNT(*) FROM lms_sessions WHERE user_id = $uid")->fetchColumn();
$asSquat = static function (string $email) use ($asPdo, $asSess): int {
    /* Somebody registers the address with a password of theirs and never verifies it. */
    $asPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES ('Squatter', ?, ?, 'learner', 0, 1)")
        ->execute([$email, password_hash('squatter-pass-123', PASSWORD_BCRYPT)]);
    $id = (int) $asPdo->lastInsertId();
    $asSess($id, str_repeat('a', 40) . $id);
    return $id;
};

/* ── An unverified account is claimed, not inherited ─────────────────────── */
(new ReflectionMethod('LmsAuth', 'ensureSecurity'))->invoke(null);
$sq = $asSquat('owner.g@acctsec.test');
$r = LmsAuth::oauthSignIn('owner.g@acctsec.test', 'Owner', true);
$row = $asPdo->query("SELECT * FROM lms_users WHERE id = $sq")->fetch(PDO::FETCH_ASSOC);
ck('Claim: Google proving the address signs its owner in', $r['ok']);
ck('Claim: …and the password the squatter chose no longer works', !password_verify('squatter-pass-123', (string) $row['password_hash']) && (int) $row['has_password'] === 0);
ck('Claim: …the account is verified now', (int) $row['email_verified'] === 1);
ck('Claim: …and the squatter\'s session is gone', !$asPdo->query("SELECT 1 FROM lms_sessions WHERE token_hash = '" . hash('sha256', str_repeat('a', 40) . $sq) . "'")->fetchColumn());

$sq2 = $asSquat('owner.otp@acctsec.test');
Otp::ensure();
$asPdo->prepare('INSERT INTO lms_otps (email, purpose, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['owner.otp@acctsec.test', 'login', hash('sha256', 'owner.otp@acctsec.test|login|482913'), gmdate('Y-m-d H:i:s', time() + 600), gmdate('Y-m-d H:i:s')]);
$r = LmsAuth::loginWithOtp('owner.otp@acctsec.test', '482913');
$row = $asPdo->query("SELECT * FROM lms_users WHERE id = $sq2")->fetch(PDO::FETCH_ASSOC);
ck('Claim: an emailed code does the same', $r['ok'] && !password_verify('squatter-pass-123', (string) $row['password_hash']) && (int) $row['email_verified'] === 1
    && !$asPdo->query("SELECT 1 FROM lms_sessions WHERE token_hash = '" . hash('sha256', str_repeat('a', 40) . $sq2) . "'")->fetchColumn());

/* A verified account's password is the owner's: a sign-in by code leaves it. */
$asPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES ('Kept', 'kept@acctsec.test', ?, 'member', 1, 1)")
    ->execute([password_hash('kept-pass-12345', PASSWORD_BCRYPT)]);
$kept = (int) $asPdo->lastInsertId();
LmsAuth::oauthSignIn('kept@acctsec.test', 'Kept', true);
ck('Claim: a verified account keeps its password', password_verify('kept-pass-12345', (string) $asPdo->query("SELECT password_hash FROM lms_users WHERE id = $kept")->fetchColumn()));

/* ── A new password signs out the other devices, not this one ───────────── */
$asPdo->exec("DELETE FROM lms_sessions WHERE user_id = $kept");
$here = str_repeat('b', 40); $there = str_repeat('c', 40);
$asSess($kept, $here); $asSess($kept, $there);
$_COOKIE[LmsAuth::COOKIE] = $here;
$sp = LmsAuth::setPassword($kept, 'A-new-Passw0rd!');
unset($_COOKIE[LmsAuth::COOKIE]);
ck('Password: a new password signs out every other device', $sp['ok'] && $asSessions($kept) === 1
    && $asPdo->query("SELECT 1 FROM lms_sessions WHERE token_hash = '" . hash('sha256', $here) . "'")->fetchColumn());

/* ── A new email: unverified again and signed out ───────────────────────── */
$m = MemberRoster::create(['name' => 'Email Mover', 'email' => 'mover@acctsec.test'], 'tester');
$asPdo->exec('UPDATE lms_users SET email_verified = 1 WHERE id = ' . (int) $m['id']);
$asSess((int) $m['id'], str_repeat('d', 40));
$u = MemberRoster::update((int) $m['id'], ['email' => 'moved@acctsec.test'], 'tester');
ck('Email: changing it needs the new address confirmed', $u['ok'] && (int) $asPdo->query('SELECT email_verified FROM lms_users WHERE id = ' . (int) $m['id'])->fetchColumn() === 0);
ck('Email: …and signs them out everywhere', $asSessions((int) $m['id']) === 0);
$sum = Approvals::summary('roster_update', ['id' => (int) $m['id'], 'email' => 'other@acctsec.test'], 0);
ck('Email: a change of email is put to a second admin, in words', is_string($sum) && str_contains($sum, 'Change the email') && str_contains($sum, 'other@acctsec.test'));

/* ── The Studio: editors don't reach member data ─────────────────────────── */
$asApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
preg_match('/\$managementOnly = \[(.*?)\];/s', $asApi, $mo);
foreach (['ac_roster', 'ac_export', 'ac_grants', 'roster_enrol', 'roster_reset', 'cert_issue', 'cert_revoke', 'ngv_intake_consent'] as $act) {
    ck("Studio: $act is not an editor's", str_contains($mo[1] ?? '', "'$act'"));
}
ck('Studio: the moderation queues show editors no email addresses', str_contains($asApi, '$noEmails(') && substr_count($asApi, '$noEmails(') >= 2);
ck('Studio: mem_save goes through the two-person rule', (bool) preg_match("/\['roster_bulk',\s*'roster_update',\s*'mem_save'\]/", $asApi));
ck('Studio: the roster import runs with the importer\'s rights', (bool) preg_match("/MemberRoster::import\([^;]*'ctx' => \\\$memberCtx\(\)/s", $asApi));

/* ── Imports obey the desk ──────────────────────────────────────────────── */
$adminCtx = ['studio_role' => 'admin', 'self_id' => 0];
$imp = MemberRoster::import([['name' => 'Ceiling Test', 'email' => 'ceiling@afrovanguard.org.ng', 'role' => 'admin']], ['ctx' => $adminCtx]);
ck('Import: an admin cannot hand out the admin access level by CSV — refused in the dry run', $imp['failed'] === 1
    && str_contains((string) ($imp['rows'][0]['detail'] ?? ''), 'Super Admin'));
$victim = MemberRoster::create(['name' => 'Held Member', 'email' => 'held@acctsec.test'], 'tester');
$imp = MemberRoster::import([['name' => 'Held Member', 'email' => 'held@acctsec.test', 'status' => 'suspended']], ['ctx' => $adminCtx, 'overwrite' => true]);
$heldRow = $imp['rows'][0] ?? [];
ck('Import: a suspension by CSV is left for the desk, where a second admin approves it',
    in_array($heldRow['action'] ?? '', ['unchanged', 'update'], true) && str_contains(implode(' ', $heldRow['warnings'] ?? []), 'second admin'));
$imp = MemberRoster::import([['name' => 'Held Member', 'email' => 'held@acctsec.test', 'status' => 'suspended']], ['ctx' => $adminCtx, 'overwrite' => true, 'apply' => true, 'expect_digest' => $imp['digest']]);
ck('Import: …and applying the file does not suspend them', (string) $asPdo->query('SELECT status FROM lms_users WHERE id = ' . (int) $victim['id'])->fetchColumn() === 'active');

/* ── The volunteer people search ────────────────────────────────────────── */
$volSrc = (string) file_get_contents(AV_ROOT . '/portal/volunteer.php');
ck('People: only leads and team runners may search people', str_contains($volSrc, 'Volunteers::canSearchPeople'));
ck('People: …and it is rate limited', str_contains($volSrc, "av_rate_ok('vol_people_'"));
$asPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, email_verified) VALUES ('Findable Person', 'findable@acctsec.test', 'x', 'member', 1)")->execute();
$found = Volunteers::people('Findable');
ck('People: results carry an id and a masked hint, never the address', $found && isset($found[0]['id'], $found[0]['hint'])
    && !isset($found[0]['email']) && !str_contains(json_encode($found), 'findable@acctsec.test'));
ck('People: a plain member may not search', !Volunteers::canSearchPeople(['id' => $kept, 'role' => 'member']));

/* ── Commitments: assign to the people the item came from ───────────────── */
ck('Commitments: the queue offers people linked to each item, not the whole membership',
    method_exists('Commitments', 'assignableFor') && str_contains((string) file_get_contents(AV_ROOT . '/portal/commitments.php'), 'Commitments::assignableFor('));

/* ── Codes never in the log ─────────────────────────────────────────────── */
$otpSrc = (string) file_get_contents(AV_ROOT . '/lib/Otp.php');
ck('Codes: a sign-in code is never written to the error log', !preg_match('/error_log\([^;]*\$code/', $otpSrc));

/* ── Celebrations: birthdays for members only ───────────────────────────── */
$apiSrc = (string) file_get_contents(AV_ROOT . '/api.php');
ck('Celebrations: a visitor sees no birthdays and no names', str_contains($apiSrc, "isOrgMember") && str_contains($apiSrc, "unset(\$it['people'])"));
ck('Celebrations: …and the answer is not cached publicly', str_contains($apiSrc, "if (\$action !== 'celebrations') header('Cache-Control: public"));

/* ── A child promoted from NGG waits for a guardian ─────────────────────── */
NgvIntake::ensure();
NgvDb::pdo()->exec("DELETE FROM ngv_applications WHERE email LIKE '%@acctsec.test'");
$asPdo->exec("DELETE FROM ngv_intake WHERE ngg_member_id LIKE 'NGG-SEC-%'");
$kidDob = gmdate('Y-m-d', strtotime('-15 years'));
$r = NgvIntake::take(['nggMemberId' => 'NGG-SEC-1', 'name' => 'Young One', 'email' => 'young@acctsec.test', 'dob' => $kidDob], 'D-1');
ck('Minor: a child with no guardian consent is held, not enrolled', $r['status'] === 'needs_consent'
    && (int) $asPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'young@acctsec.test'")->fetchColumn() === 0);
ck('Minor: …and waits on the Members dashboard', (bool) array_filter(NgvIntake::waiting(), static fn($w) => $w['ngg_member_id'] === 'NGG-SEC-1' && $w['status'] === 'needs_consent'));
$r = NgvIntake::take(['nggMemberId' => 'NGG-SEC-2', 'name' => 'Flagged One', 'email' => 'flagged@acctsec.test', 'minor' => true, 'guardianConsent' => ['status' => 'none']], 'D-2');
ck('Minor: NGG saying "minor" is enough, without a birthday', $r['status'] === 'needs_consent');
$r = NgvIntake::take(['nggMemberId' => 'NGG-SEC-2', 'name' => 'Flagged One', 'email' => 'flagged@acctsec.test', 'minor' => true,
    'guardianConsent' => ['status' => 'granted', 'method' => 'signed_form', 'at' => '2026-10-01', 'by' => 'Mrs One']], 'D-3');
ck('Minor: the consent NGG sends releases them — to be invited to register, in person, with their parent', $r['ok'] && $r['status'] === 'invited' && (int) ($r['application_id'] ?? 0) > 0);
ck('Minor: …and the consent is kept as the record of it', str_contains((string) $asPdo->query("SELECT consent FROM ngv_intake WHERE ngg_member_id = 'NGG-SEC-2'")->fetchColumn(), 'Mrs One'));
ck('Minor: the office cannot record consent without saying who gave it', !NgvIntake::recordConsent('NGG-SEC-1', 'signed_form', '', 'tester')['ok']);
$r = NgvIntake::recordConsent('NGG-SEC-1', 'in_person', 'Mr Young', 'tester');
ck('Minor: …and with it, the held promotion goes on to its invitation', $r['ok'] && $r['status'] === 'invited');
ck('Minor: …audited', (int) $asPdo->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'ngv.intake.consent'")->fetchColumn() >= 1);
$r = NgvIntake::take(['nggMemberId' => 'NGG-SEC-3', 'name' => 'Grown One', 'email' => 'grown@acctsec.test', 'dob' => '1990-01-01'], 'D-4');
ck('Minor: an adult is not held', $r['status'] === 'invited');

/* ── Backups ────────────────────────────────────────────────────────────── */
$bkDir = sys_get_temp_dir() . '/av-bk-' . getmypid();
putenv('AV_BACKUP_DIR=' . $bkDir);
putenv('AV_BACKUP_KEY');
$b = Backups::run();
$bkFiles = glob($bkDir . '/*') ?: [];
ck('Backup: both databases are copied', $b['ok'] && count($b['files']) === 2 && count($bkFiles) === 2);
ck('Backup: …unencrypted without a key, and then never sent off-site', !$b['encrypted'] && $b['offsite'] === [] && isset($b['note']));
$main = $bkDir . '/' . $b['files'][0];
$plain = gzdecode((string) file_get_contents($main));
$tmpDb = $bkDir . '/restore.sqlite'; file_put_contents($tmpDb, $plain);
$rs = new PDO('sqlite:' . $tmpDb);
ck('Backup: the copy is a working database with the members in it', (int) $rs->query("SELECT COUNT(*) FROM lms_users WHERE email = 'kept@acctsec.test'")->fetchColumn() === 1);
$rs = null; @unlink($tmpDb);
putenv('AV_BACKUP_KEY=correct horse battery staple');
$b = Backups::run();
$enc = $bkDir . '/' . $b['files'][0];
$blob = (string) file_get_contents($enc);
ck('Backup: with a key, encrypted', $b['encrypted'] && str_ends_with($enc, '.enc') && str_starts_with($blob, 'AVBK1') && @gzdecode($blob) === false);
ck('Backup: …the key opens it', ($d = Backups::decrypt($blob, 'correct horse battery staple')) !== null && str_starts_with((string) gzdecode($d), 'SQLite format 3'));
ck('Backup: …and the wrong key does not', Backups::decrypt($blob, 'wrong key entirely!') === null);
for ($i = 0; $i < 16; $i++) touch($bkDir . '/main-2020' . sprintf('%04d', $i + 101) . '-000000.sqlite.gz');
Backups::run();
ck('Backup: only the newest 14 of each are kept', count(glob($bkDir . '/main-*') ?: []) === Backups::KEEP);
ck('Backup: the cron takes one a day', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'Backups::runIfDue()'));
foreach (glob($bkDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($bkDir);
putenv('AV_BACKUP_DIR'); putenv('AV_BACKUP_KEY');

$asPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@acctsec.test' OR email = 'ceiling@afrovanguard.org.ng'");
