<?php
/**
 * tests/memberdesk.test.php — the member desk's day-to-day tools, after NGG's
 * admin (lib/MemberDesk.php): dated notes, activity, CSV export, a message
 * to chosen members, the dormant list.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$dp = Database::pdo();
MemberRoster::ensure();
MemberDesk::ensure();
Collab::myTasks(0, 1);
Notifications::ensure();
$dp->exec('DELETE FROM member_notes WHERE user_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM collab_tasks WHERE assignee_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM lms_users WHERE id BETWEEN 9990 AND 9998');
$mk = static function (int $id, string $name, string $status, ?string $login) use ($dp): void {
    $dp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status, last_login) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, $name, 'md' . $id . '@afrovanguard.org.ng', 'x', 'member', $status, $login]);
};
$mk(9991, 'Desk Active', 'active', gmdate('Y-m-d H:i:s'));
$mk(9992, '=HYPERLINK("x") Dormant', 'active', gmdate('Y-m-d H:i:s', time() - 90 * 86400));
$mk(9993, 'Desk Suspended', 'suspended', null);
MemberRoster::update(9991, ['centre' => 'Egbeda Test', 'phone' => '08031234567'], 'test');

/* ── Notes ───────────────────────────────────────────────────────────── */
ck('notes: an empty note is refused', !MemberDesk::addNote(9991, '   ', 'ada@x')['ok']);
$n = MemberDesk::addNote(9991, 'Called about dues; will pay Friday.', 'ada@x');
ck('notes: dated and signed', $n['ok'] && $n['notes'][0]['author'] === 'ada@x' && $n['notes'][0]['at'] !== '');
MemberDesk::addNote(9991, 'Paid.', 'bola@x');
ck('notes: newest first', MemberDesk::notes(9991)[0]['body'] === 'Paid.');
$id = MemberDesk::notes(9991)[0]['id'];
ck('notes: only whoever wrote one takes it back', !MemberDesk::deleteNote($id, 'ada@x')['ok'] && MemberDesk::deleteNote($id, 'bola@x')['ok']);
$old = MemberDesk::addNote(9991, 'Old one.', 'ada@x')['notes'][0]['id'];
$dp->prepare('UPDATE member_notes SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 3 * 86400), $old]);
ck('notes: after a day a note is the record', !MemberDesk::deleteNote($old, 'ada@x')['ok']);
ck('notes: on a member that does not exist, refused', !MemberDesk::addNote(9998, 'x', 'ada@x')['ok']);

/* ── Activity ────────────────────────────────────────────────────────── */
Collab::addTask(9991, 'Open one', 9991);
$t = Collab::addTask(9991, 'Done one', 9991); Collab::toggleTask(9991, $t);
$a = MemberDesk::activity(9991);
ck('activity: last sign-in, tasks open and finished, notes', $a['last_login'] !== '' && $a['tasks_open'] === 1 && $a['tasks_done_90'] === 1 && $a['notes'] === 2);

/* ── Dormant ─────────────────────────────────────────────────────────── */
$dorm = array_map('intval', array_column(MemberRoster::roster(['segment' => 'dormant', 'page_size' => 200])['members'], 'id'));
ck('dormant: active but not signed in for 60 days', in_array(9992, $dorm, true) && !in_array(9991, $dorm, true) && !in_array(9993, $dorm, true));
ck('dormant: a tile on the dashboard', array_key_exists('dormant', MemberRoster::dashboard()['segments']));

/* ── Export ──────────────────────────────────────────────────────────── */
$x = MemberDesk::export(['q' => 'md99', 'kind' => 'all']);
$rows = array_map(static fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", trim(substr($x['csv'], 3)))));
ck('export: a header row and one row per member the filter matches', $rows[0][1] === 'Name' && $x['count'] === 3);
$byName = []; foreach (array_slice($rows, 1) as $r) $byName[$r[1]] = $r;
ck('export: the phone and centre come with them', ($byName['Desk Active'][3] ?? '') === '08031234567' && ($byName['Desk Active'][4] ?? '') === 'Egbeda Test');
ck('export: a cell a spreadsheet would run as a formula is written as text', isset($byName["'=HYPERLINK(\"x\") Dormant"]));
ck('export: only the chosen, when chosen', MemberDesk::export(['q' => 'md99', 'kind' => 'all'], [9991])['count'] === 1);
ck('export: starts with a byte-order mark, so Excel reads it as UTF-8', str_starts_with($x['csv'], "\xEF\xBB\xBF"));

/* ── Message ─────────────────────────────────────────────────────────── */
ck('message: needs a title and a body', !MemberDesk::message([9991], '', 'x', false, 'a')['ok'] && !MemberDesk::message([9991], 'x', '', false, 'a')['ok']);
$m = MemberDesk::message([9991, 9992, 9993], 'Hall closed Saturday', 'Meet at Egbeda instead.', false, 'ada@x');
ck('message: to each active member chosen; the suspended one skipped', $m['ok'] && $m['sent'] === 2 && $m['skipped'] === 1);
ck('message: it is in their portal notifications', (bool) $dp->query("SELECT 1 FROM user_notifications WHERE user_id = 9992 AND title = 'Hall closed Saturday'")->fetchColumn());
ck('message: more than 500 at once is refused', !MemberDesk::message(range(1, 501), 't', 'b', false, 'a')['ok']);

/* ── Wiring ──────────────────────────────────────────────────────────── */
$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('admin: export is audited, and every desk action is management-only', str_contains($api, "\$lms->audit('member.export'") && str_contains($api, "'roster_export', 'roster_centres', 'member_notes', 'member_note_add', 'member_note_delete', 'member_activity', 'member_message',"));
$html = (string) file_get_contents(AV_ROOT . '/admin/index.php');
ck('admin: centre and level filters, filter chips, export, message, activity and notes', str_contains($html, 'id="memCentre"') && str_contains($html, 'id="memLevel"')
   && str_contains($html, 'id="memChips"') && str_contains($html, 'id="memExport"') && str_contains($html, 'id="memMsgForm"') && str_contains($html, 'id="mdActivity"') && str_contains($html, 'id="mdNotes"'));

$dp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM member_notes WHERE user_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM collab_tasks WHERE assignee_id BETWEEN 9990 AND 9998');
$dp->exec('DELETE FROM lms_users WHERE id BETWEEN 9990 AND 9998');
