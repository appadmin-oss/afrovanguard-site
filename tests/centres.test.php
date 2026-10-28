<?php
/**
 * tests/centres.test.php — the centres members belong to (lib/Centres.php),
 * and the member desk's rules that came with it.
 *
 *   • One list of centres, each with one spelling and a code, as NGG keeps them.
 *   • A centre in use is retired, never deleted out from under its members.
 *   • A rename is carried onto every member there.
 *   • Nobody changes their own level, or is in a crowd they selected.
 *   • The level filter and the timeline agree with the record.
 */
declare(strict_types=1);

$cPdo = Database::pdo();
MemberRoster::ensure();
Centres::ensure();
$cPdo->exec('DELETE FROM av_centres');
$cPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@centres.test')");
$cPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@centres.test'");

/* ── the list ─────────────────────────────────────────────────────────── */
$eg = Centres::save(['name' => '  Egbeda ', 'code' => ''], 'tester');
ck('centres: a centre is added, its code made from its name', $eg['ok'] && $eg['centre']['name'] === 'Egbeda' && $eg['centre']['code'] === 'EG');
$ay = Centres::save(['name' => 'Ayobo', 'code' => 'ay'], 'tester');
ck('centres: a code is upper-cased', $ay['ok'] && $ay['centre']['code'] === 'AY');
ck('centres: one spelling — "egbeda" is already a centre', !Centres::save(['name' => 'egbeda'], 'tester')['ok']);
ck('centres: one code — EG is already Egbeda’s', str_contains((string) Centres::save(['name' => 'Egbeda North', 'code' => 'EG'], 'tester')['error'], 'Egbeda'));
ck('centres: a code is letters and digits, starting with a letter', !Centres::save(['name' => 'Ikotun', 'code' => '9-X'], 'tester')['ok']);
ck('centres: a suggested code never takes one in use', Centres::suggestCode('Egbe Annexe') === 'EG2');
ck('centres: a name is at least two letters', !Centres::save(['name' => 'X'], 'tester')['ok']);

/* ── members in centres ───────────────────────────────────────────────── */
$m1 = MemberRoster::create(['name' => 'Tola Ade', 'email' => 'tola@centres.test', 'centre' => 'egbeda'], 'tester');
ck('centres: a member is put in a centre in the list’s spelling', $m1['ok'] && MemberRoster::get((int) $m1['id'])['centre'] === 'Egbeda');
$m2 = MemberRoster::create(['name' => 'Kemi Ola', 'email' => 'kemi@centres.test', 'centre' => 'Ikorodu'], 'tester');
ck('centres: a centre typed on a member that the list does not have joins it, as a work role does',
    $m2['ok'] && in_array('Ikorodu', Centres::names(), true));
$all = Centres::all();
$egRow = array_values(array_filter($all, fn($c) => $c['name'] === 'Egbeda'))[0];
ck('centres: each centre says how many members it holds', $egRow['members'] === 1);

/* ── rename, retire, delete ───────────────────────────────────────────── */
$r = Centres::save(['id' => $egRow['id'], 'name' => 'Egbeda Hub', 'code' => 'EG'], 'tester');
ck('centres: a rename is carried onto every member there', $r['ok'] && $r['moved'] === 1 && MemberRoster::get((int) $m1['id'])['centre'] === 'Egbeda Hub');
$d = Centres::delete($egRow['id'], 'tester');
ck('centres: a centre in use is not deleted — it says what uses it, and to retire it', !$d['ok'] && $d['code'] === 'centre_in_use' && $d['uses']['members'] === 1 && str_contains($d['error'], 'Retire'));
ck('centres: retiring keeps its members in it', Centres::setStatus($egRow['id'], 'retired', 'tester')['ok'] && MemberRoster::get((int) $m1['id'])['centre'] === 'Egbeda Hub');
ck('centres: and it leaves the list of open centres', !in_array('Egbeda Hub', Centres::names(), true));
$u = MemberRoster::update((int) $m2['id'], ['centre' => 'Egbeda Hub'], 'tester');
ck('centres: nobody new is put in a retired centre', !$u['ok'] && str_contains((string) $u['error'], 'retired'));
$u = MemberRoster::update((int) $m1['id'], ['centre' => 'Egbeda Hub', 'phone' => '08030000001'], 'tester');
ck('centres: but a member already there keeps it when the rest of their record is saved', $u['ok'] && $u['member']['centre'] === 'Egbeda Hub');
ck('centres: a centre nobody uses is deleted', Centres::delete((int) $ay['id'], 'tester')['ok'] && !in_array('Ayobo', array_column(Centres::all(), 'name'), true));

/* ── bulk ──────────────────────────────────────────────────────────────── */
$m3 = MemberRoster::create(['name' => 'Seun Bello', 'email' => 'seun@centres.test'], 'tester');
$b = MemberRoster::bulk([(int) $m2['id'], (int) $m3['id']], 'centre', ['centre' => 'Ikordu'], 'tester', ['self_id' => 999999]);
ck('centres: bulk "Set centre" refuses a centre not on the list before touching anyone — no phantom centre from a typo',
    !$b['ok'] && ($b['code'] ?? '') === 'unknown_centre' && !in_array('Ikordu', array_column(Centres::all(), 'name'), true));
$b = MemberRoster::bulk([(int) $m2['id'], (int) $m3['id']], 'centre', ['centre' => 'ikorodu'], 'tester', ['self_id' => 999999]);
ck('centres: and puts them in one that is, in its spelling', $b['ok'] && MemberRoster::get((int) $m3['id'])['centre'] === 'Ikorodu');

/* ── your own record ──────────────────────────────────────────────────── */
$selfCtx = ['self_id' => (int) $m3['id'], 'studio_role' => 'admin'];
$lv = MemberRoster::update((int) $m3['id'], ['level' => 'A'], 'tester', $selfCtx);
ck('members: nobody raises their own level', !$lv['ok'] && $lv['code'] === 'own_account' && str_contains($lv['error'], 'level'));
ck('members: another admin can', MemberRoster::update((int) $m3['id'], ['level' => 'A'], 'tester', ['self_id' => 999999, 'studio_role' => 'admin'])['ok']);
$b = MemberRoster::bulk([(int) $m2['id'], (int) $m3['id']], 'work_role_add', ['role' => 'Centres Test Role'], 'tester', $selfCtx);
$mine = array_values(array_filter($b['results'], fn($x) => $x['id'] === (int) $m3['id']))[0] ?? [];
ck('members: your own record is refused in any bulk action, by name, and the rest go through', $b['ok'] && ($mine['code'] ?? '') === 'own_account' && $b['applied'] === 1 && $b['refused'] === 1);

/* ── the level filter agrees with the dashboard ───────────────────────── */
$cPdo->prepare("UPDATE lms_users SET level = '' WHERE id = ?")->execute([(int) $m2['id']]);
$base = class_exists('Levels') ? Levels::base() : 'O';
$ids = array_column(MemberRoster::roster(['level' => $base, 'q' => 'centres.test', 'kind' => 'all', 'page_size' => 50])['members'], 'id');
ck('members: filtering by the base level finds members with no stored level', in_array((int) $m2['id'], array_map('intval', $ids), true));

/* ── the timeline survives an email change ────────────────────────────── */
MemberRoster::update((int) $m2['id'], ['phone' => '08030000002'], 'tester');
MemberRoster::update((int) $m2['id'], ['email' => 'kemi.ola@centres.test'], 'tester');
$tl = MemberRoster::timeline((int) $m2['id']);
$events = array_column($tl, 'event');
ck('members: the history kept under the old email is still on the record after the email changes',
    in_array('member.create', $events, true) && in_array('member.email', $events, true) && count(array_filter($events, fn($e) => $e === 'member.update')) >= 2);

/* ── the Studio ───────────────────────────────────────────────────────── */
$api = (string) file_get_contents(dirname(__DIR__) . '/admin/api.php');
$ui = (string) file_get_contents(dirname(__DIR__) . '/admin/index.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/admin/app.js');
foreach (['centre_save', 'centre_status', 'centre_delete'] as $a) {
    ck("centres: $a is a POST", (bool) preg_match("~case '$a':\\s*\\n\\s*if \\(\\\$method !== 'POST'\\)~", $api));
}
ck('centres: the centre actions are management-only, like the rest of the member desk', (bool) preg_match("~managementOnly = \\[[^;]*'centres_list', 'centre_save', 'centre_status', 'centre_delete'~s", $api));
ck('centres: Members has a Centres section', str_contains($ui, 'data-mv="centres"') && str_contains($ui, 'data-pane="centres"'));
ck('centres: a member’s centre is chosen from the list, not typed', str_contains($ui, '<select id="md_centre" data-centre-pick>') && str_contains($ui, '<select id="mc_centre" data-centre-pick>'));
$pane = substr($ui, (int) strpos($ui, 'data-pane="centres"'), 2000);
ck('centres: the Centres section carries no inline style', !str_contains($pane, 'style='));
$memJs = substr($js, (int) strpos($js, 'function memTab('), (int) strpos($js, "\$('#mdSave')") - (int) strpos($js, 'function memTab('));
ck('members: the member desk’s script writes no inline style', $memJs !== '' && !preg_match('/style=|\.style\./', $memJs));

/* ── select-all-matching, and the one action list ─────────────────────── */
$f = ['q' => 'centres.test', 'kind' => 'all'];
$ids = MemberRoster::ids($f);
ck('members: "choose all matching" is every id the list counts, in one query', $ids['total'] === MemberRoster::roster($f + ['page_size' => 200])['total'] && count($ids['ids']) === $ids['total'] && !$ids['capped']);
ck('members: it shares the list’s filter, so a filter narrows it the same way', MemberRoster::ids($f + ['centre' => 'Ikorodu'])['total'] === MemberRoster::roster($f + ['centre' => 'Ikorodu'])['total']);
$rsrc = (string) file_get_contents(dirname(__DIR__) . '/lib/MemberRoster.php');
$idsBody = substr($rsrc, (int) strpos($rsrc, 'public static function ids('), 900);
ck('members: select-all no longer pages through the roster', !str_contains($idsBody, 'self::roster(') && str_contains($idsBody, 'self::filter($f)'));
ck('members: an unknown sort falls back to the default and the list says which sort it used', MemberRoster::roster($f + ['sort' => 'DROP TABLE'])['sort'] === '' && MemberRoster::roster($f + ['sort' => 'name', 'dir' => 'asc'])['sort'] === 'name');
$js2 = (string) file_get_contents(dirname(__DIR__) . '/admin/app.js');
ck('members: one declaration of member actions feeds the bulk bar and every row', str_contains($js2, 'var MEMBER_ACTIONS = [') && str_contains($js2, "memActionOptions('bulk')") && str_contains($js2, "memActionOptions('row')"));
ck('members: both run through one runner', substr_count($js2, "post('roster_bulk'") === 1 && str_contains($js2, 'function memRunAction('));
ck('members: the bulk bar’s actions are not a second hand-written list', !str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/index.php'), '<option value="status:suspended">'));

/* Leave nothing behind for the files that run after this one. */
$cPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@centres.test')");
$cPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@centres.test'");
$cPdo->exec("DELETE FROM member_roles WHERE name = 'Centres Test Role'");
$cPdo->exec('DELETE FROM av_centres');
