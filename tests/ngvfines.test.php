<?php
/**
 * tests/ngvfines.test.php — fines as a desk: issue, list, settle, import.
 *
 *   • The catalogue prices a reason once, so a blank amount is the usual one.
 *   • A fine can go to many people at once, dated, and each is one ledger row.
 *   • The list reads where each fine stands from the ledger's own credits.
 *   • An import assigns each row to the person it is for — by NGV ID, email,
 *     phone or name, close spellings included — and NEVER guesses between two.
 *   • An import is a dry run until that exact dry run is applied, and a re-import
 *     charges nobody twice.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$nf = NgvDb::pdo();
$main = Database::pdo();
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $nf->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
try { Database::metaSet('ngv_fine_catalogue', ''); Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
GateAttendance::ensure();
MemberRoster::ensure();
$main->exec('DELETE FROM lms_users WHERE id BETWEEN 9100 AND 9199');
$main->exec('DELETE FROM gate_member_cards WHERE member_id BETWEEN 9100 AND 9199');
$people = [
    9101 => ['Adebayo Bello', 'adebayo@fines.test', '08031110001', 'A-NGV-25-0901'],
    9102 => ['Chiamaka Okafor', 'chiamaka@fines.test', '08031110002', 'A-NGV-25-0902'],
    9103 => ['Tunde Bakare', 'tunde.b@fines.test', '08031110003', ''],
    9104 => ['Tunde Bakari', 'tunde.k@fines.test', '', ''],
    9105 => ['Zainab Musa', 'zainab@fines.test', '08031110005', ''],
];
foreach ($people as $id => [$name, $email, $phone, $card]) {
    $main->prepare('INSERT INTO lms_users (id, name, email, password_hash) VALUES (?,?,?,?)')->execute([$id, $name, $email, 'x']);
    $nf->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (?,?,?,'active','2026-01-05','2026-01-05','2026-01-05')")->execute([$id, $name, $email]);
    if ($phone !== '') $main->prepare('INSERT OR REPLACE INTO member_profiles (user_id, phone) VALUES (?, ?)')->execute([$id, $phone]);
    if ($card !== '') GateAttendance::assignCard($id, $card, 0);
}
NgvFines::forget();

/* ── Catalogue ── */
$cat = NgvFines::catalogue();
ck('Fines: every reason has a catalogue entry', array_keys($cat) === array_keys(NgvLedger::FINE_REASONS));
$sv = NgvFines::saveCatalogue(['late' => 1500, 'uniform' => '700']);
ck('Fines: the catalogue saves', $sv['ok'] && $sv['catalogue']['late']['amount'] === 1500 && $sv['catalogue']['uniform']['amount'] === 700);
ck('Fines: …and refuses a silly amount', !NgvFines::saveCatalogue(['late' => -5])['ok']);

/* ── Issue ── */
$one = NgvFines::issue(9101, 'late', '', 'Came in at 7:40', '2026-09-28', false, 1);
ck('Fines: a blank amount is the catalogue amount', $one['ok'] && $one['amount'] === 1500);
ck('Fines: …and the day it happened is kept', (string) $nf->query('SELECT occurred_on FROM ngv_fine_meta WHERE charge_id = ' . (int) $one['entryId'])->fetchColumn() === '2026-09-28');
ck('Fines: a future day is refused', !NgvFines::issue(9101, 'late', 1000, '', '2099-01-01', false, 1)['ok']);
ck('Fines: "other" with no note is refused', !NgvFines::issue(9101, 'other', 1000, '', '', false, 1)['ok']);
ck('Fines: equipment has no usual amount, so needs one', !NgvFines::issue(9101, 'equipment', '', 'Mouse', '', false, 1)['ok']);
ck('Fines: somebody not in NGV cannot be fined', !NgvFines::issue(1, 'late', 1000, '', '', false, 1)['ok']);
$many = NgvFines::issueMany([9102, 9105, 9105], 'uniform', '', 'No ID card', '', false, 1);
ck('Fines: one fine to many people — each once', $many['ok'] && $many['fined'] === 2 && $many['total'] === 1400);

/* ── List and standing ── */
NgvLedger::payment(9101, 'fine', 1000, ['method' => 'cash'], 1);
$byId = static function () { $o = []; foreach (NgvFines::all() as $r) $o[(int) $r['id']] = $r; return $o; };
$l = $byId();
ck('Fines: part-paid reads as part paid', $l[(int) $one['entryId']]['status'] === 'part' && $l[(int) $one['entryId']]['owing'] === 500);
$zFine = (int) $many['results'][1]['entryId'];
$w = NgvFines::waiveOne($zFine, 'First time', 1);
ck('Fines: waiving one fine sets aside what is left of it', $w['ok'] && $byId()[$zFine]['status'] === 'waived');
ck('Fines: a waiver needs a reason', !NgvFines::waiveOne((int) $many['results'][0]['entryId'], '', 1)['ok']);
$v = NgvFines::voidOne((int) $many['results'][0]['entryId'], 'Was wearing it', 1);
ck('Fines: voiding keeps it on record as voided', $v['ok'] && $byId()[(int) $many['results'][0]['entryId']]['status'] === 'voided');
ck('Fines: filters work', count(NgvFines::all(['status' => 'open'])) === 1 && count(NgvFines::all(['reason' => 'uniform'])) === 2 && count(NgvFines::all(['q' => 'zainab'])) === 1);
$s = NgvFines::summary();
ck('Fines: the desk figures add up', $s['outstanding'] === 500 && $s['owing_members'] === 1);

/* ── Smart assignment ── */
$row = static fn(array $r) => array_merge(array_fill_keys(NgvFines::COLUMNS, ''), $r);
ck('Fines: matched by NGV ID', ($a = NgvFines::assign($row(['id' => 'a-ngv-25-0902'])))['status'] === 'matched' && $a['member']['id'] === 9102 && $a['how'] === 'NGV ID');
ck('Fines: matched by email', NgvFines::assign($row(['email' => 'ZAINAB@fines.test']))['member']['id'] === 9105);
ck('Fines: matched by phone, however it is written', ($a = NgvFines::assign($row(['phone' => '+234 803 111 0001'])))['member']['id'] === 9101 && $a['how'] === 'phone');
ck('Fines: matched by name in another order and case', NgvFines::assign($row(['name' => 'okafor CHIAMAKA']))['member']['id'] === 9102);
ck('Fines: matched by a close spelling — and says so', ($a = NgvFines::assign($row(['name' => 'Chiamaka Okafo'])))['status'] === 'matched' && str_contains($a['how'], 'spelt'));
$amb = NgvFines::assign($row(['name' => 'Tunde Bakar']));
ck('Fines: two near names are never guessed between', $amb['status'] === 'ambiguous' && count(array_intersect([9103, 9104], array_column($amb['candidates'], 'id'))) === 2);
ck('Fines: …and a person picking one settles it', NgvFines::assign($row(['name' => 'Tunde Bakar', 'assign' => '9104']))['member']['id'] === 9104);
ck('Fines: nobody like that — unmatched', NgvFines::assign($row(['name' => 'Xavier Quint']))['status'] === 'unmatched');
ck('Fines: an ID on one person and a name on another warns', !empty(NgvFines::assign($row(['id' => 'A-NGV-25-0901', 'name' => 'Zainab Musa']))['warnings']));
ck('Fines: reasons read from plain words', NgvFines::reasonOf('came in late') === 'late' && NgvFines::reasonOf('No ID card') === 'uniform'
    && NgvFines::reasonOf('broke the projector') === 'equipment' && NgvFines::reasonOf('Rude to a mentor') === 'conduct' && NgvFines::reasonOf('Parking') === 'other');
ck('Fines: days read as people write them', NgvFines::day('28/09/2026') === '2026-09-28' && NgvFines::day('2026-9-1') === '2026-09-01' && NgvFines::day('31/02/2026') === null);

/* ── Import ── */
$csv = "Name,NGV ID,Email,Amount,Offence,Date,Notes\n"
     . "Adebayo Bello,,,,late,29/09/2026,\n"
     . ",A-NGV-25-0902,,2000,Absent,2026-09-29,\n"
     . "Tunde Bakar,,,500,no ID card,,\n"
     . "Xavier Quint,,,500,late,,\n"
     . ",,zainab@fines.test,\"₦3,000\",broke the projector,,\n"
     . "Adebayo Bello,,,,late,29/09/2026,\n"
     . "Zainab Musa,,,abc,late,,\n";
$p = NgvFines::parseCsv($csv);
ck('Fines: the sheet parses, its columns understood', $p['ok'] && count($p['rows']) === 7 && $p['rows'][1]['id'] === 'A-NGV-25-0902');
$dry = NgvFines::import($p['rows'], ['by' => 1]);
$st = array_column($dry['rows'], 'status');
ck('Fines: the dry run sorts every row', $st === ['ready', 'ready', 'ambiguous', 'unmatched', 'ready', 'duplicate', 'invalid']);
ck('Fines: …a quoted ₦3,000 is 3000, and "broke the projector" is equipment', $dry['rows'][4]['amount'] === 3000 && $dry['rows'][4]['reason'] === 'equipment');
ck('Fines: …and charges nobody', (int) $nf->query("SELECT COUNT(*) FROM ngv_charges WHERE period LIKE 'imp:%'")->fetchColumn() === 0);
ck('Fines: an apply without its check is refused', NgvFines::import($p['rows'], ['apply' => true])['code'] === 'preview_required');
$edited = $p['rows']; $edited[2]['assign'] = '9103';
ck('Fines: an edited sheet is a different check', NgvFines::import($edited, ['apply' => true, 'expect_digest' => $dry['digest']])['code'] === 'changed');
$dry2 = NgvFines::import($edited, ['by' => 1]);
$go = NgvFines::import($edited, ['apply' => true, 'expect_digest' => $dry2['digest'], 'by' => 1, 'source' => 'september.csv']);
ck('Fines: the import charges what was ready, to the right people', $go['ok'] && $go['counts']['fined'] === 4
    && (int) $nf->query("SELECT COUNT(*) FROM ngv_charges WHERE period LIKE 'imp:%' AND member_id = 9103")->fetchColumn() === 1);
ck('Fines: …a blank amount took the catalogue (1,500 for late)', (int) $nf->query("SELECT amount FROM ngv_charges WHERE period LIKE 'imp:%' AND member_id = 9101")->fetchColumn() === 1500);
ck('Fines: …the run is recorded', (int) $nf->query('SELECT fined FROM ngv_fine_imports ORDER BY id DESC LIMIT 1')->fetchColumn() === 4);
ck('Fines: …and imported fines carry the run', count(NgvFines::all(['source' => 'import'])) === 4);
$again = NgvFines::import($edited, ['by' => 1]);
ck('Fines: the same sheet again charges nobody twice', $again['counts']['ready'] === 0 && $again['counts']['already'] === 4);

/* ── The page ── */
$page = (string) file_get_contents(AV_ROOT . '/academy/ngv/fines.php');
ck('Fines: the console page is guarded like the rest of the console', str_contains($page, 'av_admin_role') && str_contains($page, 'av_csrf_require') && str_contains($page, 'require_same_origin'));

/* ── A vanguard's own fines ── */
$mine = NgvFines::forMember(9101);
$theirs = array_values(array_filter(NgvFines::all(), static fn($r) => (int) $r['member_id'] === 9101));
ck('Fines: a vanguard sees exactly their own fines, standing as the desk reads it',
   array_column($mine['fines'], 'id') === array_column($theirs, 'id')
   && array_column($mine['fines'], 'status') === array_column($theirs, 'status'));
ck('Fines: …with what is still owing added up', $mine['owing'] === array_sum(array_map(static fn($r) => in_array($r['status'], ['owing', 'part'], true) ? (int) $r['owing'] : 0, $theirs)));
ck('Fines: …and nobody else\'s name or email in it', !array_filter($mine['fines'], static fn($r) => isset($r['name']) || isset($r['email'])));
ck('Fines: somebody with no fines has none', NgvFines::forMember(9199)['fines'] === [] && NgvFines::forMember(9199)['owing'] === 0);

/* The card the vanguard sees, drawn as the portal draws it. */
$fnHtml = (static function () {
    $u = ['id' => 9101, 'name' => 'Adebayo Bello', 'email' => 'adebayo@fines.test'];
    $c = Ngv::get();
    ob_start();
    try {
        require AV_ROOT . '/academy/ngv/_dashboard-data.php';
        $ngvParts = ['fines']; $ngvStandalone = false; $ngvBanner = false; $ngvScript = false; $ngvAccountHref = '#ngv-account';
        require AV_ROOT . '/academy/ngv/_dashboard-body.php';
    } catch (Throwable $e) { ob_end_clean(); return 'ERROR ' . $e->getMessage(); }
    return (string) ob_get_clean();
})();
ck('Fines: the vanguard\'s Fines card lists their fines with where each stands',
   str_contains($fnHtml, 'id="fines"') && str_contains($fnHtml, 'Came in at 7:40') && !str_contains($fnHtml, 'ERROR'));
ck('Fines: …and shows nothing of the account or other cards', !str_contains($fnHtml, 'id="account"') && !str_contains($fnHtml, 'id="reading"'));
$portal = av_portal_source();
ck('Fines: the portal has a Fines view for vanguards', str_contains($portal, "'ngv-fines', 'Fines'") && str_contains($portal, 'id="view-ngv-fines"'));
$landing = (string) file_get_contents(AV_ROOT . '/academy/ngv/index.php');
ck('Fines: the NGV page explains fines from the desk\'s own amounts', str_contains($landing, 'id="fines"') && str_contains($landing, 'NgvFines::catalogue()'));
ck('Fines: …and the fines desk is linked for admins only', str_contains($landing, 'href="/academy/ngv/fines.php"') && str_contains($landing, "['admin', 'superadmin']"));

foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $nf->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$main->exec('DELETE FROM gate_member_cards WHERE member_id BETWEEN 9100 AND 9199');
$main->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9100 AND 9199');
$main->exec('DELETE FROM lms_users WHERE id BETWEEN 9100 AND 9199');
try { Database::metaSet('ngv_fine_catalogue', ''); } catch (Throwable $e) {}
NgvFines::forget();
