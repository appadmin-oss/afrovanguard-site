<?php
/**
 * tests/memberroster.test.php — the member desk, and members' cards.
 *
 *   • A member is validated before anything is written, every problem at once.
 *   • One email, one member: never two records sharing a sign-in.
 *   • A member made here has a secure card at once; an imported one too, and
 *     the card they already hold keeps working (their NGV number, or an old
 *     card read by a format the importer described).
 *   • An import is a dry run until it is applied, and an apply must name the
 *     dry run it applies.
 *   • A staff member is not a spreadsheet row.
 *   • The gate reads secure, NGV and old printed cards; a replaced card stops.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mrPdo = Database::pdo();
MemberRoster::ensure();
GateAttendance::ensure();
foreach (['av_member_cards', 'member_profiles', 'member_import_runs', 'gate_member_cards'] as $t) $mrPdo->exec('DELETE FROM ' . $t);
$mrPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@roster.test'");
Database::metaSet('gate_card_formats', '');

/* ── Validation ───────────────────────────────────────────────────────── */
$v = MemberRoster::validate(['name' => 'x', 'email' => 'not-an-email', 'role' => 'emperor', 'birthday' => '1998-02-31', 'phone' => '12']);
ck('Roster: every problem is reported at once, in words', !$v['ok'] && count($v['errors']) === 5 && str_contains($v['errors']['email'], 'not an email'));
ck('Roster: a phone that lost its leading zero gets it back', MemberRoster::cleanPhone('8031234567') === '08031234567');
ck('Roster: a spreadsheet birthday (14/07/1998) is understood', MemberRoster::validate(['name' => 'Ada Obi', 'email' => 'a@roster.test', 'birthday' => '14/07/1998'])['clean']['birthday'] === '1998-07-14');

/* ── One member ───────────────────────────────────────────────────────── */
$c = MemberRoster::create(['name' => 'Ada  Obi', 'email' => 'Ada@Roster.test', 'phone' => '8031234567', 'centre' => 'Egbeda', 'level' => 'A', 'birthday' => '07-14'], 'tester');
$ada = $c['ok'] ? MemberRoster::get((int) $c['id']) : null;
ck('Roster: a member is created with their whole record', $ada && $ada['name'] === 'Ada Obi' && $ada['email'] === 'ada@roster.test'
    && $ada['phone'] === '08031234567' && $ada['centre'] === 'Egbeda' && $ada['level'] === 'A' && $ada['birthday'] === '07-14');
ck('Roster: …and a secure card for the gate, at once', (bool) preg_match('/^AVQR-[0-9A-HJKMNP-TV-Z]{16}$/', (string) ($c['card'] ?? '')) && MemberCards::secure((int) $c['id']) === $c['card']);
$dup = MemberRoster::create(['name' => 'Ada Again', 'email' => 'ADA@roster.test', 'joined_on' => '2024-01-06'], 'tester');
ck('Roster: one email, one member — adding an email that exists links to that account, never a second', $dup['ok'] && !empty($dup['linked'])
    && (int) $dup['id'] === (int) $c['id'] && (int) $mrPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'ada@roster.test'")->fetchColumn() === 1);
ck('Roster: …filling what the account lacked and replacing nothing it had', $dup['filled'] === ['joined_on'] && MemberRoster::get((int) $c['id'])['name'] === 'Ada Obi');
$other = MemberRoster::create(['name' => 'Other Person', 'email' => 'other@roster.test'], 'tester');
ck('Roster: changing a member\'s email to somebody else\'s is refused, naming who has it',
    !MemberRoster::update((int) $other['id'], ['email' => 'ada@roster.test'], 'tester')['ok']
    && str_contains(MemberRoster::update((int) $other['id'], ['email' => 'ada@roster.test'], 'tester')['error'], 'Ada Obi'));
ck('Roster: a member made in the Studio needs an email', !MemberRoster::create(['name' => 'No Mail'], 'tester')['ok']);
$u = MemberRoster::update((int) $c['id'], ['phone' => '08090000000', 'centre' => 'Ayobo'], 'tester');
ck('Roster: an edit changes what was given and says what', $u['ok'] && $u['changed'] === ['phone', 'centre'] && $u['member']['centre'] === 'Ayobo' && $u['member']['name'] === 'Ada Obi');
ck('Roster: an edit is validated like a creation', !MemberRoster::update((int) $c['id'], ['email' => 'nope'], 'tester')['ok']);

/* ── Import ───────────────────────────────────────────────────────────── */
$csv = "Name,Email,Phone,Centre,NGV Number,Date of Birth\n"
     . "Bola Ade,bola@roster.test,8030000001,Egbeda,A-NGV-23-0007,14/07/2001\n"
     . "Chidi Eze,,08030000002,Ayobo,,\n"
     . "Ada Obi,ada@roster.test,,,,\n"
     . ",broken@roster.test,,,,\n";
$p = MemberRoster::parseCsv($csv);
ck('Import: a CSV with the columns people use is read', $p['ok'] && count($p['rows']) === 4 && in_array('ngv', $p['columns'], true) && $p['rows'][0]['phone'] === '8030000001');
ck('Import: a file with no Name column is refused in words', !MemberRoster::parseCsv("Email\nx@y.z")['ok']);
$dry = MemberRoster::import($p['rows']);
ck('Import: a row with no email is refused — every member signs in with one', $dry['failed'] === 2
    && (bool) array_filter($dry['rows'], static fn($x) => $x['name'] === 'Chidi Eze' && $x['action'] === 'failed' && str_contains($x['detail'], 'email')));
ck('Import: an email already on an account is that account, and the dry run says so',
    (bool) array_filter($dry['rows'], static fn($x) => $x['action'] !== 'create' && in_array('linked to the existing account ada@roster.test', $x['warnings'] ?? [], true)));
ck('Import: a dry run says what it would do, and writes nothing', $dry['ok'] && !$dry['applied'] && $dry['created'] === 1
    && (int) $mrPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'bola@roster.test'")->fetchColumn() === 0);
ck('Import: …counting the cards it would issue', $dry['cards']['would_issue'] === 1);
ck('Import: an apply that names no dry run is refused', (MemberRoster::import($p['rows'], ['apply' => true])['code'] ?? '') === 'preview_required');
ck('Import: an apply of a different file than the one checked is refused', (MemberRoster::import(array_slice($p['rows'], 0, 2), ['apply' => true, 'expect_digest' => $dry['digest']])['code'] ?? '') === 'roster_changed');
$run = MemberRoster::import($p['rows'], ['apply' => true, 'expect_digest' => $dry['digest'], 'actor' => 'tester', 'source' => 'members.csv']);
$bola = (int) $mrPdo->query("SELECT id FROM lms_users WHERE email = 'bola@roster.test'")->fetchColumn();
ck('Import: applied, it creates the members', $run['ok'] && $run['created'] === 1 && $bola > 0);
ck('Import: every imported member gets a secure card', MemberCards::secure($bola) !== null && $run['cards']['issued'] === 1);
ck('Import: …and the NGV number already on their card keeps working', GateAttendance::cardFor($bola) === 'A-NGV-23-0007' && $run['cards']['ngv'] === 1);
ck('Import: …and no record is invented for the row without an email', (int) $mrPdo->query("SELECT COUNT(*) FROM lms_users WHERE name = 'Chidi Eze'")->fetchColumn() === 0);
ck('Import: a member holding an NGV card is a NextGen Vanguard; one without is not',
    NgvMember::isVanguard($bola) && !NgvMember::isVanguard((int) $other['id']) && MemberRoster::get($bola)['ngv'] === true);
$ngvOnly = MemberRoster::roster(['kind' => 'ngv']); $notNgv = MemberRoster::roster(['kind' => 'member', 'q' => 'roster.test']);
ck('Roster: NextGen Vanguards are told apart by their NGV record, not their email',
    in_array($bola, array_map('intval', array_column($ngvOnly['members'], 'id')), true)
    && !in_array($bola, array_map('intval', array_column($notNgv['members'], 'id')), true) && $ngvOnly['members'][0]['ngv'] === true);
/* An NGV application made under an address before the account existed is linked to the account. */
NgvDb::pdo()->exec("DELETE FROM ngv_applications WHERE email LIKE '%@roster.test'");
NgvDb::pdo()->prepare("INSERT INTO ngv_applications (name, email, status, created_at) VALUES (?, ?, 'new', ?)")->execute(['Ife Applied', 'ife@roster.test', gmdate('c')]);
$ife = MemberRoster::create(['name' => 'Ife Applied', 'email' => 'ife@roster.test'], 'tester');
ck('Roster: an NGV application under the same email is linked to the new account', $ife['ngv_linked'] === 1
    && (int) NgvDb::pdo()->query("SELECT member_id FROM ngv_applications WHERE email = 'ife@roster.test'")->fetchColumn() === (int) $ife['id']);
NgvDb::pdo()->exec("DELETE FROM ngv_applications WHERE email LIKE '%@roster.test'");
ck('Import: a member already on the roll is matched by email, not made twice',
    (int) $mrPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'ada@roster.test'")->fetchColumn() === 1);
ck('Import: the run is recorded and recognised next time', (MemberRoster::import($p['rows'])['previous']['actor'] ?? '') === 'tester');

/* An old card read by a format the importer described. */
$d = MemberCards::fromExample('https://old.portal.example/m/00042', '00042');
ck('Cards: a format is worked out from an example card, the host let vary', $d['ok'] && $d['template'] === '{any}/m/{id}' && $d['mask'] === '99999');
ck('Cards: a format that would match almost anything is refused', !MemberCards::saveFormat(['id' => 'any', 'template' => '{id}', 'mask' => '*'], 'tester')['ok']);
ck('Cards: …and one whose example does not read under it', !MemberCards::saveFormat(['id' => 'x', 'template' => '{id}', 'mask' => '999-999', 'example' => 'abc'], 'tester')['ok']);
$sf = MemberCards::saveFormat(['id' => 'old-portal', 'label' => 'Old portal cards', 'template' => $d['template'], 'mask' => $d['mask'], 'example' => 'https://old.portal.example/m/00042'], 'tester');
ck('Cards: a good format is saved and handed to the gate', $sf['ok'] && MemberCards::gateFormats()[0]['id'] === 'old-portal');
$csv2 = "Name,Email,Card Code\nDayo Old,dayo@roster.test,00042\n";
$rows2 = MemberRoster::parseCsv($csv2)['rows'];
$dry2 = MemberRoster::import($rows2, ['card_format' => 'old-portal']);
MemberRoster::import($rows2, ['apply' => true, 'expect_digest' => $dry2['digest'], 'card_format' => 'old-portal', 'actor' => 'tester']);
$dayo = (int) $mrPdo->query("SELECT id FROM lms_users WHERE email = 'dayo@roster.test'")->fetchColumn();
$hit = MemberCards::lookup(null, 'old-portal', '00042');
ck('Cards: the code on their old card is recorded by that format', $hit && $hit['member_id'] === $dayo && !$hit['void']);
ck('Cards: the card format is part of what an import does — changing it is another dry run',
    MemberRoster::digest($rows2, 'old-portal', false) !== MemberRoster::digest($rows2, '', false));

/* A staff member is not a spreadsheet row. */
$mrPdo->prepare("UPDATE lms_users SET role = 'coordinator' WHERE id = ?")->execute([(int) $c['id']]);
$csv3 = "Name,Email,Status\nSomebody Else,ada@roster.test,suspended\n";
$r3 = MemberRoster::parseCsv($csv3)['rows'];
$d3 = MemberRoster::import($r3, ['overwrite' => true]);
MemberRoster::import($r3, ['apply' => true, 'overwrite' => true, 'expect_digest' => $d3['digest'], 'actor' => 'tester']);
$after = MemberRoster::get((int) $c['id']);
ck('Import: a coordinator\'s name and status are not the spreadsheet\'s to change', $after['name'] === 'Ada Obi' && $after['status'] === 'active');

/* ── The gate ─────────────────────────────────────────────────────────── */
$sec = MemberCards::secure($bola);
$h = MemberCards::lookup($sec);
ck('Gate: a secure card is the member it was issued to', $h && $h['member_id'] === $bola && $h['kind'] === 'secure');
$new = MemberCards::issue($bola, 'tester');
ck('Gate: reissuing stops the old card at once', MemberCards::lookup($sec)['void'] === true && MemberCards::lookup($new)['void'] === false);
MemberCards::voidPrinted($dayo);
ck('Gate: "reprinted — retire the old card" stops the old printed one', MemberCards::lookup(null, 'old-portal', '00042')['void'] === true);
ck('Gate: an NGV card is still read the way it always was', GateAttendance::resolveCard('A-NGV-23-0007')['ok'] === true);

/* ── The backfill, and the dashboard ──────────────────────────────────── */
$mrPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute(['Before Cards', 'before@roster.test', 'x', 'member', 'active']);
$before = (int) $mrPdo->lastInsertId();
$bf = MemberCards::backfill('tester');
ck('Cards: everybody already on the roll gets a card, by the backfill', MemberCards::secure($before) !== null && $bf['remaining'] === 0);
$ov = MemberRoster::overview();
ck('Dashboard: it counts members and how they joined, month by month', $ov['total'] >= 5 && count($ov['joined']) === 12);
ck('Dashboard: it lists what is wrong, and only that', isset($ov['quality']['phone']) && !isset($ov['quality']['card']) && $ov['vanguards'] >= 1);
$roster = MemberRoster::roster(['missing' => 'phone', 'q' => 'roster.test']);
ck('Roster: "no phone" finds exactly those', in_array((int) $other['id'], array_map('intval', array_column($roster['members'], 'id')), true)
    && !in_array($bola, array_map('intval', array_column($roster['members'], 'id')), true));
$page = MemberRoster::roster(['sort' => 'name', 'dir' => 'asc', 'page_size' => 10, 'q' => 'roster.test']);
ck('Roster: sorted and paged on the server, with each member\'s card', $page['page_size'] === 10 && !empty($page['members'][0]['card']));

foreach (['av_member_cards', 'member_profiles', 'member_import_runs', 'gate_member_cards'] as $t) $mrPdo->exec('DELETE FROM ' . $t);
$mrPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@roster.test'");
Database::metaSet('gate_card_formats', '');

/* ── NextGen Vanguard inside the member portal ────────────────────────── */
$portal = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('Portal: a vanguard\'s NGV dashboard is a section of the portal, from the same files as the old page',
    str_contains($portal, "NgvMember::isVanguard((int) \$u['id'])") && str_contains($portal, '_dashboard-data.php') && str_contains($portal, '_dashboard-body.php'));
ck('Portal: …read in its own scope, so its names cannot overwrite the portal\'s', str_contains($portal, 'get_defined_vars()'));
$dash = (string) file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
ck('Portal: the old dashboard sends a vanguard to the portal, and still writes every save',
    str_contains($dash, "Location: /portal/#ngv") && str_contains($dash, 'NgvMember::saveSelf') && str_contains((string) file_get_contents(AV_ROOT . '/academy/ngv/_dashboard-body.php'), "NGV_URL = '/academy/ngv/dashboard.php'"));

ck('Portal: NGV is three portal views — Programme, Fees & account, Attendance & pass — not a page inside a page',
    str_contains($portal, "['ngv', 'Programme'") && str_contains($portal, "['ngv-account', 'Fees & account'") && str_contains($portal, 'data-view="attendance"')
    && str_contains($portal, "'ngvStandalone' => false"));
ck('Portal: Today carries the programme and what needs doing about it', str_contains($portal, 'today-ngv') && str_contains($portal, "outstanding on your NGV account") && str_contains($portal, 'The CACENTRE gate cannot let you in'));
ck('Portal: Membership shows the NGV ID, the NGV account and the gate beside the dues', str_contains($portal, '>NGV ID<') && str_contains($portal, '>NGV account<') && str_contains($portal, '>CACENTRE gate<'));
ck('Portal: the sidebar names a vanguard as one', str_contains($portal, "\$isNgv ? 'NextGen Vanguard'"));
$gp = (string) file_get_contents(AV_ROOT . '/gate-pass.php');
ck('Portal: telling the office about a day away from the portal comes back to the portal', str_contains($gp, "Location: /portal/#attendance") && str_contains((string) file_get_contents(AV_ROOT . '/portal/_attendance.php'), 'name="return" value="portal"'));
ck('Portal: fee and damage emails open the portal\'s Fees & account, not the old page',
    !str_contains((string) file_get_contents(AV_ROOT . '/lib/NgvLedger.php'), 'ngv/dashboard.php#') && !str_contains((string) file_get_contents(AV_ROOT . '/lib/NgvDamage.php'), 'ngv/dashboard.php#'));
