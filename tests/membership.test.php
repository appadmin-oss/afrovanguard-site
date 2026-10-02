<?php
/**
 * tests/membership.test.php — dues membership, and who may change a member.
 *
 *   • One definition of a member's state, and the dashboard count is the
 *     length of the roster list it opens.
 *   • The office can record dues taken in person, give months or a lifetime
 *     membership with a reason, end one and undo that — every change a row,
 *     every row saying who.
 *   • Times are UTC; an email is matched whatever its case; a cancelled
 *     membership is not "lifetime".
 *   • Nobody changes their own access or status; staff are a Super Admin's;
 *     access above instructor is a Super Admin's; an admin's email is never
 *     handed to another account.
 *   • Suspending ends the member's sessions.
 *   • A bulk run is validated first, capped, per-row, and audited once.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$msPdo = Database::pdo();
MemberRoster::ensure();
Membership::ensure();
AdminRoles::ensure();
$msPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@ms.test')");
$msPdo->exec("DELETE FROM payments WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@ms.test')");
$msPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@ms.test' OR email LIKE '%.ms@afrovanguard.org.ng'");
$msPdo->exec("DELETE FROM admin_users WHERE email LIKE '%@ms.test' OR email LIKE '%.ms@afrovanguard.org.ng'");

$mk = function (string $name, string $email, string $role = 'member') use ($msPdo): int {
    $msPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, 'x', $role, 'active']);
    return (int) $msPdo->lastInsertId();
};
$row = function (int $uid, string $status, ?string $expires, string $endedAt = '') use ($msPdo): void {
    $msPdo->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at, ended_at) VALUES (?, 'member', ?, ?, ?, ?)")
        ->execute([$uid, $status, gmdate('Y-m-d H:i:s', time() - 400 * 86400), $expires, $endedAt]);
};
$at = fn(int $days) => gmdate('Y-m-d H:i:s', time() + $days * 86400);

/* ── One definition of state ─────────────────────────────────────────── */
$life  = $mk('Lola Life', 'life@ms.test');      $row($life, 'active', null);
$cur   = $mk('Chidi Current', 'cur@ms.test');   $row($cur, 'active', $at(200));
$soon  = $mk('Sade Soon', 'soon@ms.test');      $row($soon, 'active', $at(10));
$lap   = $mk('Lami Lapsed', 'lap@ms.test');     $row($lap, 'active', $at(-5));
$canc  = $mk('Kunle Cancelled', 'canc@ms.test'); $row($canc, 'cancelled', null, gmdate('Y-m-d H:i:s'));   // a cancelled LIFETIME row
$never = $mk('Ngozi Never', 'never@ms.test');

$want = ['lifetime' => $life, 'current' => $cur, 'due_soon' => $soon, 'lapsed' => $lap, 'cancelled' => $canc, 'never' => $never];
foreach ($want as $state => $uid) ck("Membership: $state is read as $state", Membership::summary($uid)['state'] === $state);

$lms = new LmsRepository();
ck('Membership: a cancelled lifetime membership is NOT "lifetime" on the member\'s dues panel',
    $lms->duesStatus($canc)['lifetime'] === false && $lms->duesStatus($canc)['state'] === 'none');
ck('Membership: isMember agrees — the lapsed and the cancelled are not members', !$lms->isMember($lap) && !$lms->isMember($canc) && $lms->isMember($soon));

$ids = function (string $state): array {
    $r = MemberRoster::roster(['membership' => $state, 'q' => '@ms.test', 'page_size' => 200]);
    $x = array_map(fn($m) => (int) $m['id'], $r['members']); sort($x); return $x;
};
foreach ($want as $state => $uid) ck("Membership: the roster's \"$state\" filter is exactly that member", $ids($state) === [$uid]);
ck('Membership: "member" is everybody with a live membership', $ids('member') === [$life, $cur, $soon]);
$counts = Membership::counts();
$all = 0; foreach (Membership::STATES as $s) $all += $counts[$s];
ck('Membership: every member is in exactly one state', $all === (int) $msPdo->query('SELECT COUNT(*) FROM lms_users')->fetchColumn());
ck('Membership: an unknown filter shows everybody rather than failing', MemberRoster::roster(['membership' => 'bogus', 'q' => '@ms.test'])['total'] === 6);
$r = MemberRoster::roster(['q' => 'soon@ms.test']);
ck('Membership: the roster row says where they stand, from the same query', ($r['members'][0]['membership'] ?? '') === 'due_soon');

/* ── Recording dues the office took ──────────────────────────────────── */
$g = Membership::grant($soon, ['months' => 12, 'amount_ngn' => 12000, 'method' => 'cash', 'reference' => 'RCPT-77', 'verified_offline' => 1, 'actor' => 'office@ms.test']);
ck('Membership: dues paid in cash are recorded', $g['ok'] && $g['membership']['state'] === 'current' && $g['membership']['total_paid_ngn'] === 12000);
ck('Membership: …extending from their paid-through date, not from today — paying early forfeits nothing',
    abs(strtotime($g['expires_at'] . ' UTC') - strtotime('+12 months', strtotime($at(10) . ' UTC'))) < 5);
ck('Membership: …with a payment row the receipt trail can find', (int) $msPdo->query("SELECT COUNT(*) FROM payments WHERE reference = 'OFF-RCPT-77' AND status = 'paid' AND kind = 'membership'")->fetchColumn() === 1);
ck('Membership: the same receipt cannot be recorded twice',
    (Membership::grant($soon, ['months' => 1, 'amount_ngn' => 1000, 'method' => 'cash', 'reference' => 'RCPT-77', 'verified_offline' => 1, 'actor' => 'x'])['code'] ?? '') === 'duplicate_reference');
ck('Membership: money needs a method', !Membership::grant($cur, ['months' => 1, 'amount_ngn' => 1000, 'verified_offline' => 1, 'actor' => 'x'])['ok']);
ck('Membership: free months need a reason', (Membership::grant($cur, ['months' => 1, 'actor' => 'x'])['code'] ?? '') === 'reason_required');
ck('Membership: …a waiver is a reason', Membership::grant($never, ['months' => 6, 'method' => 'waiver', 'actor' => 'x'])['ok']
    && Membership::summary($never)['state'] === 'current' && Membership::summary($never)['total_paid_ngn'] === 0);
ck('Membership: months are bounded', !Membership::grant($cur, ['months' => 0, 'method' => 'waiver', 'actor' => 'x'])['ok']
    && !Membership::grant($cur, ['months' => 500, 'method' => 'waiver', 'actor' => 'x'])['ok']);
ck('Membership: a lifetime member is not sold months', (Membership::grant($life, ['months' => 1, 'method' => 'waiver', 'actor' => 'x'])['code'] ?? '') === 'lifetime');

/* ── Lifetime, cancel, reinstate ─────────────────────────────────────── */
ck('Membership: a lifetime membership needs a reason', (Membership::lifetime($cur, '', 'x')['code'] ?? '') === 'reason_required');
ck('Membership: …and with one, it never expires', Membership::lifetime($cur, 'Founding member', 'x')['ok'] && Membership::summary($cur)['state'] === 'lifetime');
ck('Membership: ending one needs a reason', (Membership::cancel($cur, '', 'x')['code'] ?? '') === 'reason_required');
$c = Membership::cancel($cur, 'Asked to leave', 'office@ms.test');
ck('Membership: ending one ends every live row, and says who and why', $c['ok'] && $c['membership']['state'] === 'cancelled'
    && ($c['membership']['ended']['by'] ?? '') === 'office@ms.test' && !$lms->isMember($cur));
ck('Membership: …and keeps the history — nothing is deleted', count(Membership::history($cur)['memberships']) >= 2);
ck('Membership: a cancellation can be undone', Membership::reinstate($cur, 'x')['ok'] && Membership::summary($cur)['state'] === 'lifetime');
$row($lap, 'cancelled', $at(-1), gmdate('Y-m-d H:i:s', time() - 3 * 86400));
ck('Membership: …but not one that has run out since', (Membership::reinstate($lap, 'x')['code'] ?? '') === 'expired_since');

/* ── UTC, and email case ─────────────────────────────────────────────── */
$utc = $mk('Uche Utc', 'utc@ms.test');
$lms->grantMembership($utc, 12);
$exp = (string) $msPdo->query("SELECT expires_at FROM memberships WHERE user_id = $utc")->fetchColumn();
ck('Membership: a paid membership\'s expiry is written in UTC, as it is compared', abs(strtotime($exp . ' UTC') - strtotime('+12 months')) < 5);
ck('Membership: dues sent with the email in another case still land', $lms->grantMembershipByEmail('UTC@MS.Test', 1)
    && (int) $msPdo->query("SELECT COUNT(*) FROM memberships WHERE user_id = $utc")->fetchColumn() === 2);

/* ── Who may change what ─────────────────────────────────────────────── */
$admin = $mk('Ada Admin', 'admin@ms.test');
$coord = $mk('Coco Ordinator', 'coord.ms@afrovanguard.org.ng', 'coordinator');
$studio = $mk('Stu Dio', 'studio.ms@afrovanguard.org.ng');
AdminRoles::add('studio.ms@afrovanguard.org.ng', 'admin', 'test');
AdminRoles::add('boss.ms@afrovanguard.org.ng', 'superadmin', 'test');
$asAdmin = ['studio_role' => 'admin', 'self_id' => $admin];
$asSuper = ['studio_role' => 'superadmin', 'self_id' => 0];

ck('Guard: nobody suspends themselves', (MemberRoster::update($admin, ['status' => 'suspended'], 'x', $asAdmin)['code'] ?? '') === 'own_account');
ck('Guard: …or changes their own access level', (MemberRoster::update($admin, ['role' => 'instructor'], 'x', $asAdmin)['code'] ?? '') === 'own_account');
ck('Guard: …but may correct their own phone', MemberRoster::update($admin, ['phone' => '08030000000'], 'x', $asAdmin)['ok']);
ck('Guard: a coordinator is staff — an admin cannot suspend them', (MemberRoster::update($coord, ['status' => 'suspended'], 'x', $asAdmin)['code'] ?? '') === 'staff_account');
ck('Guard: …nor a Studio admin whose member role is plain', (MemberRoster::update($studio, ['email' => 'elsewhere@ms.test'], 'x', $asAdmin)['code'] ?? '') === 'staff_account');
ck('Guard: …a Super Admin can', MemberRoster::update($coord, ['status' => 'suspended'], 'x', $asSuper)['ok']);
MemberRoster::update($coord, ['status' => 'active'], 'x', $asSuper);
$orgm = $mk('Ore Org', 'ore.ms@afrovanguard.org.ng');
ck('Guard: below Super Admin, access is given up to instructor', MemberRoster::update($orgm, ['role' => 'instructor'], 'x', $asAdmin)['ok']
    && (MemberRoster::update($orgm, ['role' => 'admin'], 'x', $asAdmin)['code'] ?? '') === 'role_ceiling');
ck('Guard: …and nobody is CREATED above it either', (MemberRoster::create(['name' => 'New Coord', 'email' => 'nc.ms@afrovanguard.org.ng', 'role' => 'coordinator'], 'x', 'studio', $asAdmin)['code'] ?? '') === 'role_ceiling');

/* ── Admins are organisation addresses ───────────────────────────────── */
ck('Org: a personal address cannot be made a Studio admin', (AdminRoles::add('someone@gmail.com', 'admin', 'test')['code'] ?? '') === 'org_email');
$msPdo->prepare('INSERT INTO admin_users (email, role, added_by, created_at) VALUES (?,?,?,?)')->execute(['legacy@ms.test', 'superadmin', 'old', gmdate('Y-m-d H:i:s')]);
ck('Org: …and one already on the list grants nothing — it is listed so it can be removed',
    AdminRoles::roleForEmail('legacy@ms.test') === '' && !array_values(array_filter(AdminRoles::list(), fn($r) => $r['email'] === 'legacy@ms.test'))[0]['valid']);
ck('Org: an org address still is an admin', AdminRoles::roleForEmail('studio.ms@afrovanguard.org.ng') === 'admin');
ck('Org: not even a Super Admin gives coordinator or admin access to a personal address',
    (MemberRoster::update($never, ['role' => 'coordinator'], 'x', $asSuper)['code'] ?? '') === 'org_email'
    && (MemberRoster::create(['name' => 'Gee Mail', 'email' => 'gee@gmail.com', 'role' => 'admin'], 'x', 'studio', $asSuper)['code'] ?? '') === 'org_email');
ck('Org: …nor moves a staff account onto one', (MemberRoster::update($coord, ['email' => 'coco@gmail.com'], 'x', $asSuper)['code'] ?? '') === 'org_email');
ck('Org: …nor does the importer', (MemberRoster::update($never, ['role' => 'admin'], 'importer')['code'] ?? '') === 'org_email');
$legacyStaff = $mk('Old Staff', 'oldstaff@ms.test', 'coordinator');
ck('Org: a staff account already on a personal address can still have its phone corrected', MemberRoster::update($legacyStaff, ['phone' => '08031112222'], 'x', $asSuper)['ok']);
putenv('AV_SUPERADMIN_EMAIL=boss@gmail.com');
ck('Org: the default Super Admin is never seeded on a personal address', SuperAdmin::defaultEmail() === 'mamcareer@afrovanguard.org.ng');
putenv('AV_SUPERADMIN_EMAIL');
ck('Guard: a Super Admin\'s address cannot be moved onto another account — that was a way to become one',
    (MemberRoster::update($never, ['email' => 'boss.ms@afrovanguard.org.ng'], 'x', $asAdmin)['code'] ?? '') === 'admin_email');
ck('Guard: an internal caller (the importer) is not restricted by these', MemberRoster::update($never, ['role' => 'member'], 'importer')['ok']);

/* ── Suspending ends sessions ────────────────────────────────────────── */
$msPdo->prepare('INSERT INTO lms_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)')->execute([hash('sha256', 'ms-sess'), $soon, $at(30)]);
MemberRoster::update($soon, ['status' => 'suspended'], 'x', $asAdmin);
ck('Suspend: their sessions are gone, so reactivating does not revive them on a lost phone',
    (int) $msPdo->query("SELECT COUNT(*) FROM lms_sessions WHERE user_id = $soon")->fetchColumn() === 0);
MemberRoster::update($soon, ['status' => 'active'], 'x', $asAdmin);

/* ── Bulk ────────────────────────────────────────────────────────────── */
ck('Bulk: a run is capped, and the cap is named', (MemberRoster::bulk(range(1, MemberRoster::BULK_MAX + 1), 'centre', ['centre' => 'X'], 'x', $asAdmin)['code'] ?? '') === 'too_many');
$before = (int) $msPdo->query("SELECT COUNT(*) FROM member_profiles WHERE centre = 'Ikeja'")->fetchColumn();
ck('Bulk: the payload is checked before anything is written', !MemberRoster::bulk([$lap, $never], 'status', ['status' => 'banished'], 'x', $asAdmin)['ok']);
$auditBefore = (int) $msPdo->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'member.bulk'")->fetchColumn();
$b = MemberRoster::bulk([$lap, $never, $coord, $admin, 999999], 'status', ['status' => 'suspended'], 'x', $asAdmin);
$by = []; foreach ($b['results'] as $x) $by[$x['id']] = $x;
ck('Bulk: refusals are per row and named, the rest go through — and it is reported as partial',
    $b['ok'] && $b['partial'] && $b['applied'] === 2 && $b['refused'] === 3
    && $by[$coord]['code'] === 'staff_account' && $by[$admin]['code'] === 'own_account' && $by[999999]['code'] === 'not_found');
$again = MemberRoster::bulk([$lap, $never], 'status', ['status' => 'suspended'], 'x', $asAdmin);
$bs = MemberRoster::bulk([$coord, $studio], 'status', ['status' => 'suspended'], 'x', $asSuper);
ck('Bulk: a staff account\'s status is never changed in a crowd — not even by a Super Admin',
    $bs['applied'] === 0 && $bs['refused'] === 2 && $bs['results'][0]['code'] === 'staff_account');
ck('Bulk: "unchanged" is reported, distinct from applied', $again['unchanged'] === 2 && $again['applied'] === 0);
ck('Bulk: one audit line for each run, not one per member',
    (int) $msPdo->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'member.bulk'")->fetchColumn() === $auditBefore + 3);   // three runs above
$bg = MemberRoster::bulk([$lap, $admin], 'membership_grant', ['months' => 3, 'method' => 'waiver'], 'x', $asAdmin);
ck('Bulk: months can be given to many — never to yourself', $bg['applied'] === 1 && Membership::summary($lap)['state'] === 'current'
    && $bg['results'][1]['code'] === 'own_account');
ck('Bulk: a bulk grant with no money needs a reason', (MemberRoster::bulk([$lap], 'membership_grant', ['months' => 3], 'x', $asAdmin)['code'] ?? '') === 'reason_required');
$ix = MemberRoster::ids(['q' => '@ms.test']);
ck('Bulk: "select all matching" is one request with the true total', $ix['total'] === count($ix['ids']) && !$ix['capped'] && in_array($never, $ix['ids'], true));

/* ── Timeline ────────────────────────────────────────────────────────── */
$tl = MemberRoster::timeline($soon);
$what = array_column($tl, 'what');
ck('Timeline: a member\'s history reads as sentences — the dues recorded, the suspension, who did it',
    in_array('Dues recorded', $what, true) && in_array('Changed', $what, true) && !array_filter($tl, fn($e) => $e['by'] === ''));

/* ── The drawer carries it ───────────────────────────────────────────── */
$m = MemberRoster::get($cur);
ck('Drawer: a member\'s record includes their membership', ($m['membership']['state'] ?? '') === 'lifetime');
ck('Drawer: …and says whether they are staff', MemberRoster::get($coord)['staff'] === true && MemberRoster::get($cur)['staff'] === false);
ck('Dashboard: the overview counts memberships by state', isset(MemberRoster::overview()['membership']['due_soon']));


/* ── Learners, dues payers, members: three populations, not one ─────── */
$learner = $mk('Lara Learner', 'learner@ms.test', 'learner');
$payer   = $mk('Pele Payer', 'payer@ms.test', 'learner');
ck('Dues: a learner can pay dues', Membership::grant($payer, ['months' => 12, 'amount_ngn' => 12000, 'method' => 'transfer', 'verified_offline' => 1, 'actor' => 'x'])['ok']);
ck('Dues: …and is still a learner — paying dues makes nobody a member',
    (string) $msPdo->query("SELECT role FROM lms_users WHERE id = $payer")->fetchColumn() === 'learner' && !MemberRoster::isMember($payer));
$lms->grantMembership($payer, 1);
ck('Dues: …nor does paying through the Academy checkout', (string) $msPdo->query("SELECT role FROM lms_users WHERE id = $payer")->fetchColumn() === 'learner');
$desk = array_map(fn($m) => (int) $m['id'], MemberRoster::roster(['q' => '@ms.test', 'page_size' => 200])['members']);
ck('Desk: learners are not on the member desk — the one paying dues included', !in_array($learner, $desk, true) && !in_array($payer, $desk, true) && in_array($cur, $desk, true));
$lr = array_map(fn($m) => (int) $m['id'], MemberRoster::roster(['q' => '@ms.test', 'kind' => 'learner', 'page_size' => 200])['members']);
sort($lr);
ck('Desk: …they are found when asked for, as learners', $lr === [$learner, $payer]);
ck('Dues: the dues count is over every account — the learner paying dues is counted as paying dues',
    in_array($payer, array_map(fn($m) => (int) $m['id'], MemberRoster::roster(['q' => '@ms.test', 'kind' => 'all', 'membership' => 'member', 'page_size' => 200])['members']), true));
$ov = MemberRoster::overview();
ck('Dashboard: members and learners are counted apart',
    $ov['learners'] === (int) $msPdo->query("SELECT COUNT(*) FROM lms_users WHERE role = 'learner'")->fetchColumn()
    && $ov['total'] === (int) $msPdo->query("SELECT COUNT(*) FROM lms_users WHERE role <> 'learner'")->fetchColumn() + count(array_filter(array_keys(MemberRoster::vanguardIds()), fn($i) => (string) $msPdo->query("SELECT role FROM lms_users WHERE id = " . (int) $i)->fetchColumn() === 'learner')));
MemberCards::backfill('test', 500);
ck('Cards: the backfill gives a gate card to members, never to a learner', MemberCards::secure($learner) === null && MemberCards::secure($payer) === null && MemberCards::secure($cur) !== null);
ck('Desk: the member desk does not create learners', (MemberRoster::create(['name' => 'New Learner', 'email' => 'nl@ms.test', 'role' => 'learner'], 'x')['code'] ?? '') === 'learner');
ck('Drawer: a learner\'s record says so', MemberRoster::get($payer)['learner'] === true && MemberRoster::get($cur)['learner'] === false);

/* ── Importing with the organisation's own ID ───────────────────────── */
Database::metaSet('gate_card_formats', '');
$csv = "Name,Email,Member ID\nIdris Own,idris@ms.test,AVG/24/0107\nJoy Own,joy@ms.test,AVG/24/0108\nKemi Odd,kemi@ms.test,ZZ-1\n";
$rows = MemberRoster::parseCsv($csv)['rows'];
$noFmt = MemberRoster::import($rows, ['actor' => 'x']);
ck('ID import: without a format, an ID that is not an NGV number is refused — and the refusal says how to fix it',
    $noFmt['failed'] === 3 && str_contains($noFmt['rows'][0]['detail'], 'define the ID format'));
$f = MemberCards::saveIdFormat('AVG/23/0042', 'Afrovanguard IDs', '', 'x');
ck('ID format: defined from one example — digits vary, the rest is as printed', $f['ok'] && $f['format']['mask'] === 'AVG/99/9999' && $f['format']['template'] === '{id}');
ck('ID format: one that would match almost anything is refused', !MemberCards::saveIdFormat('42', 'Short', '', 'x')['ok']);
$fid = $f['format']['id'];
$dry = MemberRoster::import($rows, ['actor' => 'x', 'id_format' => $fid]);
ck('ID import: with the format, IDs that fit it go through and the one that does not is named',
    $dry['created'] === 2 && $dry['failed'] === 1
    && (bool) array_filter($dry['rows'], fn($r) => $r['action'] === 'failed' && str_contains($r['detail'], 'does not fit') && str_contains($r['detail'], 'AVG/99/9999')));
ck('ID import: the ID format is part of what the dry run promised', $dry['digest'] !== $noFmt['digest']);
$applied = MemberRoster::import($rows, ['actor' => 'x', 'id_format' => $fid, 'apply' => true, 'expect_digest' => $dry['digest']]);
$idris = (int) $msPdo->query("SELECT id FROM lms_users WHERE email = 'idris@ms.test'")->fetchColumn();
ck('ID import: applied, each ID is recorded as that member\'s card', $applied['cards']['ids'] === 2 && (MemberCards::lookup(null, $fid, 'AVG/24/0107')['member_id'] ?? 0) === $idris);
ck('ID import: the gate reads the card — its QR is the ID, and the format decodes it',
    MemberCards::decode(MemberCards::format($fid), 'AVG/24/0107') === 'AVG/24/0107'
    && in_array($fid, array_column(MemberCards::gateFormats(), 'id'), true));
$clash = MemberRoster::import(MemberRoster::parseCsv("Name,Email,Member ID\nSomebody Else,else@ms.test,AVG/24/0107\n")['rows'], ['actor' => 'x', 'id_format' => $fid]);
ck('ID import: an ID already recorded for one member is not given to a second, nor merged into the first — it is refused by name',
    $clash['created'] === 0 && $clash['updated'] === 0 && $clash['failed'] === 1 && str_contains($clash['rows'][0]['detail'], 'Idris Own'));
$again = MemberRoster::import(MemberRoster::parseCsv("Name,Email,Member ID
Idris Own,idris@ms.test,AVG/24/0107
")['rows'], ['actor' => 'x', 'id_format' => $fid]);
ck('ID import: …while the same person with the same ID imports again cleanly', $again['failed'] === 0 && $again['created'] === 0);


/* ── The member dashboard: every tile is the list it opens ───────────── */
$fA = $mk('Fola Fined', 'fola@ms.test');  $fB = $mk('Gbenga Paid', 'gbenga@ms.test');
// NGV fines are on NGV participants — vanguards, who are members.
NgvMember::ensureParticipant($fA, ['name' => 'Fola Fined', 'email' => 'fola@ms.test']);
NgvMember::ensureParticipant($fB, ['name' => 'Gbenga Paid', 'email' => 'gbenga@ms.test']);
NgvFines::issue($fA, 'late', 1000, '', '', false, 1);
NgvFines::issue($fA, 'uniform', 500, '', '', false, 1);
NgvFines::issue($fB, 'late', 1000, '', '', false, 1);
NgvLedger::payment($fB, 'fine', 1000, ['method' => 'cash'], 1);
MemberRoster::forgetFines();
$msPdo->prepare("UPDATE lms_users SET status = 'suspended' WHERE id = ?")->execute([$fA]);
Levels::set($orgm, 'A', 'test');
Promotion::ensure();
$msPdo->prepare("INSERT INTO av_promotion_reviews (user_id, from_level, to_level, status, created_at) VALUES (?, 'O', 'A', 'open', ?)")->execute([$cur, gmdate('Y-m-d H:i:s')]);
$msPdo->prepare("INSERT INTO av_promotion_reviews (user_id, from_level, to_level, status, created_at) VALUES (?, 'O', 'A', 'open', ?)")->execute([$orgm, gmdate('Y-m-d H:i:s')]);   // already reached: history

$dash = MemberRoster::overview()['dashboard'];
$seg = function (string $k): array {
    $r = MemberRoster::roster(['segment' => $k, 'page_size' => 200]);
    $x = array_map(fn($m) => (int) $m['id'], $r['members']); sort($x); return $x;
};
foreach (array_keys(MemberRoster::SEGMENTS) as $k) {
    ck("Dashboard: the \"$k\" tile is the length of the list it opens", $dash['segments'][$k] === count($seg($k)) && $dash['segments'][$k] === MemberRoster::roster(['segment' => $k])['total']);
}
$mineF = fn(array $ids) => array_values(array_intersect($ids, [$fA, $fB]));
ck('Dashboard: fined counts people — two fines are one person', $mineF($seg('fined')) === [$fA, $fB]);
ck('Dashboard: owing is the ledger\'s standing: the paid fine is cleared', $mineF($seg('fines_owed')) === [$fA] && $mineF($seg('fines_cleared')) === [$fB]);
$fsMine = array_intersect_key(MemberRoster::fineStanding(), [$fA => 1, $fB => 1]);
ck('Dashboard: the naira figures add up the ledger\'s own standing', array_sum(array_column($fsMine, 'owing')) === 1500 && array_sum(array_column($fsMine, 'amount')) === 2500
    && $dash['fines']['owing_ngn'] >= 1500 && $dash['fines']['count'] >= 3);
ck('Dashboard: suspended', in_array($fA, $seg('suspended'), true) && !in_array($fB, $seg('suspended'), true));
ck('Dashboard: a level raised this year is counted', in_array($orgm, $seg('level_raised'), true));
ck('Dashboard: a promotion awaiting a decision — not one for a level already reached', in_array($cur, $seg('review_open'), true) && !in_array($orgm, $seg('review_open'), true));
ck('Dashboard: dues sit beside them, with the money received this year', isset($dash['dues']['due_soon'], $dash['dues']['received_12m_ngn']) && $dash['dues']['received_12m_ngn'] >= 12000);
ck('Dashboard: an unknown segment shows the roster rather than failing', MemberRoster::roster(['segment' => 'bogus'])['total'] === MemberRoster::roster([])['total']);

$msPdo->exec("DELETE FROM admin_users WHERE email LIKE '%@ms.test' OR email LIKE '%.ms@afrovanguard.org.ng'");
