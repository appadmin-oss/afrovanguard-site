<?php
/**
 * tests/finessmart.test.php — the fines import, smarter (lib/NgvFines.php).
 *
 *   • It reads what people actually have: an Excel workbook, a CSV with no
 *     headings, a list typed into WhatsApp.
 *   • It remembers who a person chose for a row, and matches it next time.
 *   • It does not charge a fine the gate or the desk already gave that day.
 *   • It points out someone fined for the same thing often.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$fs = NgvDb::pdo();
$fsMain = Database::pdo();
MemberRoster::ensure();
GateAttendance::ensure();
foreach (['ngv_charges', 'ngv_payments', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $fs->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
Database::metaSet('ngv_fine_aliases', '');
$fsMain->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@smartfine.test')");
$fsMain->exec("DELETE FROM lms_users WHERE email LIKE '%@smartfine.test'");
$tunde = (int) MemberRoster::create(['name' => 'Tunde Bakare', 'email' => 'tunde@smartfine.test', 'phone' => '08037770001'], 'tester')['id'];
$tundeK = (int) MemberRoster::create(['name' => 'Tunde Bakari', 'email' => 'tundek@smartfine.test'], 'tester')['id'];
$ada = (int) MemberRoster::create(['name' => 'Adaeze Nwosu', 'email' => 'adaeze@smartfine.test'], 'tester')['id'];
NgvFines::forget();
$today = date('Y-m-d');
$yday = date('Y-m-d', strtotime('-1 day'));

/* ── What a cell is ── */
ck('smart: "2k" is ₦2,000, "₦1,500" is ₦1,500, and a year is not money', NgvFines::amountOf('2k') === 2000 && NgvFines::amountOf('₦1,500') === 1500 && NgvFines::amountOf('2023') === null);
ck('smart: cells are told apart — email, phone, date, reason, name', NgvFines::kindOf('a@b.co') === 'email' && NgvFines::kindOf('0803 777 0001') === 'phone'
    && NgvFines::kindOf('12/10/2026') === 'date' && NgvFines::kindOf('came in late') === 'reason' && NgvFines::kindOf('Adaeze Nwosu') === 'name');
$dec = NgvFines::day('12 Dec');
ck('smart: a day with no year is the last one that has passed, never one ahead', $dec !== null && $dec <= $today && str_ends_with($dec, '-12-12'));

/* ── A typed list ── */
$list = NgvFines::parseAny("Adaeze Nwosu – late – {$yday} – 2k\nTunde Bakare - no uniform\n");
ck('smart: a list typed one fine a line is read, each piece by what it is', $list['ok'] && count($list['rows']) === 2
    && $list['rows'][0]['name'] === 'Adaeze Nwosu' && $list['rows'][0]['amount'] === '2000' && $list['rows'][0]['date'] === $yday && NgvFines::reasonOf($list['rows'][0]['reason']) === 'late'
    && NgvFines::reasonOf($list['rows'][1]['reason']) === 'uniform' && str_contains($list['read_as'], 'typed list'));

/* ── A sheet with no headings ── */
$bare = NgvFines::parseAny("Adaeze Nwosu,adaeze@smartfine.test,1500,late,{$yday}\nTunde Bakare,tunde@smartfine.test,500,uniform,{$yday}\n");
ck('smart: a CSV with no header row has its columns worked out from what is in them', $bare['ok'] && $bare['rows'][1]['email'] === 'tunde@smartfine.test'
    && $bare['rows'][0]['amount'] === '1500' && $bare['rows'][1]['date'] === $yday && str_contains($bare['read_as'], 'worked out'));

/* ── An Excel workbook ── */
$tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>Reason</t></si><si><t>Amount</t></si><si><t>Date</t></si><si><t>Adaeze Nwosu</t></si><si><t>absent</t></si></sst>');
$serial = (int) floor(strtotime($yday . ' UTC') / 86400) + 25569;
$z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2"><v>2500</v></c><c r="D2"><v>' . $serial . '</v></c></row></sheetData></worksheet>');
$z->close();
$wb = NgvFines::parseAny('', base64_encode((string) file_get_contents($tmp))); @unlink($tmp);
ck('smart: an Excel workbook is read — its dates too, which Excel keeps as day numbers', $wb['ok'] && ($wb['rows'][0]['name'] ?? '') === 'Adaeze Nwosu' && $wb['rows'][0]['amount'] === '2500' && $wb['rows'][0]['date'] === $yday);

/* ── Remembering who a person chose ── */
$row = array_merge(array_fill_keys(NgvFines::COLUMNS, ''), ['name' => 'Tunde B', 'amount' => '500', 'reason' => 'late', 'date' => $yday]);
$first = NgvFines::import([$row]);
ck('smart: "Tunde B" could be two people, so it is asked', ($first['rows'][0]['status'] ?? '') === 'ambiguous');
$chosen = $row + []; $chosen['assign'] = (string) $tunde;
$d = NgvFines::import([$chosen]);
NgvFines::import([$chosen], ['apply' => true, 'expect_digest' => $d['digest'], 'by' => 1]);
$again = NgvFines::import([array_merge($row, ['date' => $today, 'reason' => 'uniform'])]);
ck('smart: once a person chose who "Tunde B" is and imported it, the next sheet is matched — and says how', ($again['rows'][0]['status'] ?? '') === 'ready'
    && (int) ($again['rows'][0]['member']['id'] ?? 0) === $tunde && $again['rows'][0]['how'] === 'what you chose before');

/* ── Already fined that day, another way ── */
NgvLedger::postCharge($ada, 'fine', 1000, 'late:' . $yday, ['reason' => 'late', 'note' => 'Late arrival — gate', 'source' => 'accrual']);
$gateDup = NgvFines::import([array_merge(array_fill_keys(NgvFines::COLUMNS, ''), ['name' => 'Adaeze Nwosu', 'reason' => 'late', 'amount' => '1000', 'date' => $yday])]);
ck('smart: a fine the gate already gave for lateness that day is not charged again, and it says so', ($gateDup['rows'][0]['status'] ?? '') === 'already'
    && str_contains(implode(' ', $gateDup['rows'][0]['warnings']), 'CACENTRE gate'));

/* ── Fined for the same thing often ── */
foreach ([5, 12] as $ago) NgvFines::issue($ada, 'uniform', 500, '', date('Y-m-d', strtotime("-{$ago} days")), false, 1);
$often = NgvFines::import([array_merge(array_fill_keys(NgvFines::COLUMNS, ''), ['name' => 'Adaeze Nwosu', 'reason' => 'no uniform', 'date' => $today])]);
ck('smart: someone fined for the same thing often is pointed out', ($often['rows'][0]['status'] ?? '') === 'ready' && str_contains(implode(' ', $often['rows'][0]['warnings']), '3rd'));

$desk = (string) file_get_contents(AV_ROOT . '/academy/ngv/fines.php');
ck('smart: the desk takes a workbook and says how it read the sheet', str_contains($desk, '.xlsx') && str_contains($desk, 'NgvFines::parseAny(') && str_contains($desk, 'read_as'));

foreach (['ngv_charges', 'ngv_payments', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $fs->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
Database::metaSet('ngv_fine_aliases', '');
$fsMain->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@smartfine.test')");
$fsMain->exec("DELETE FROM lms_users WHERE email LIKE '%@smartfine.test'");
NgvFines::forget();
