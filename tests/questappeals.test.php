<?php
/**
 * tests/questappeals.test.php — a fine proposed like a bill (owner, 2026-10-22).
 *
 * The promises:
 *   · a fine or deduction is put to the member when it is proposed, and they
 *     answer it — accept, or contest with their side; an award is not shown;
 *   · the committee may not approve before the member answers or the notice
 *     runs out, and may always reject;
 *   · a member may report themselves, from the catalogue only, never an award,
 *     never at their own price; somebody else decides it, and it is not
 *     appealable;
 *   · an approved fine or deduction is appealed once, in time, on grounds,
 *     and heard by somebody with no part in it — not the member, not the
 *     proposer, not the approver;
 *   · overturning voids the fine or takes the deduction off (and dismisses a
 *     demotion it raised); reducing re-posts it smaller, never larger;
 *   · an open appeal holds the overdue-balance consequences;
 *   · the portal and the desk carry it: a Bills & appeals section for every
 *     account, an Appeals section on the desk, ops the endpoint handles.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8700–8799.
 */
declare(strict_types=1);

$db = Database::pdo();
$nf = NgvDb::pdo();
Conduct::ensure(); QuestPoints::ensure(); QuestLadder::ensure(); QuestMoney::ensure(); Mentorship::ensure();
$wipe = static function () use ($db, $nf): void {
    $db->exec('DELETE FROM conduct_appeals WHERE member_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM conduct_cases WHERE mentee_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM quest_suggestions WHERE member_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM quest_reconciliations WHERE member_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8700 AND 8799');
    $db->exec('DELETE FROM mentorships WHERE id BETWEEN 87000 AND 87099');
    $db->exec('DELETE FROM lms_users WHERE id BETWEEN 8700 AND 8799');
    $nf->exec('DELETE FROM ngv_charges WHERE member_id BETWEEN 8700 AND 8799');
    $nf->exec('DELETE FROM ngv_participants WHERE member_id BETWEEN 8700 AND 8799');
};
$wipe();
AvRules::resetAll('test');

$people = [8701 => 'Appeal Mentor', 8702 => 'Appeal Member', 8703 => 'Committee One', 8704 => 'Committee Two', 8705 => 'Plain Member'];
foreach ($people as $id => $n) $db->prepare('INSERT INTO lms_users (id, name, email, password_hash) VALUES (?,?,?,?)')->execute([$id, $n, 'ap' . $id . '@x.test', 'x']);
foreach ([8703, 8704] as $c) $db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))->execute([$c, 'test', gmdate('Y-m-d H:i:s')]);
$nf->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (8702,'Appeal Member','ap8702@x.test','active','2026-01-05','2026-01-05','2026-01-05')")->execute();
$now = gmdate('Y-m-d H:i:s');
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at, updated_at) VALUES (87001, 8701, 8702, 'active', ?, ?)")->execute([$now, $now]);
$U = static fn(int $id): array => ['id' => $id, 'name' => $people[$id], 'email' => 'ap' . $id . '@x.test'];
$day = gmdate('Y-m-d', time() - 86400);
$ev = 'Left the Saturday session at eleven without telling anybody, and did not come back.';
$owing = static fn(): int => (int) NgvFines::forMember(8702)['owing'];

/* ── the bill ── */
$f = Conduct::propose(8701, 87001, ['kind' => 'fine', 'action' => 'unapproved_exit', 'occurred_on' => $day, 'evidence' => $ev]);
ck('appeals: a mentor proposes a fine from the catalogue', $f['ok']);
$bills = Conduct::billsFor(8702);
ck('appeals: the member sees it at once, as a bill', count($bills) === 1 && $bills[0]['status'] === 'proposed' && (string) $bills[0]['response'] === '');
$aw = Conduct::propose(8701, 87001, ['kind' => 'award', 'action' => 'extra_responsibility', 'occurred_on' => $day, 'evidence' => 'Took the register for the whole day and handed it in complete.']);
ck('appeals: an award is not a bill, and is not listed', $aw['ok'] && count(Conduct::billsFor(8702)) === 1);
$r = Conduct::decide((int) $f['id'], $U(8703), true, '');
ck('appeals: the committee cannot approve before the member answers or the notice runs out', !$r['ok'] && str_contains($r['error'], 'until'));
ck('appeals: …and nothing is owed', $owing() === 0);
ck('appeals: contesting needs the member\'s side', !Conduct::respond(8702, (int) $f['id'], 'contested', 'No.')['ok']);
ck('appeals: nobody answers somebody else\'s bill', !Conduct::respond(8705, (int) $f['id'], 'accepted', '')['ok']);
ck('appeals: the member contests it, in their words',
   Conduct::respond(8702, (int) $f['id'], 'contested', 'I told the facilitator I was ill, and she let me go home at eleven.')['ok']
   && Conduct::find((int) $f['id'])['response'] === 'contested');
$r = Conduct::decide((int) $f['id'], $U(8703), true, 'The facilitator does not recall it.');
ck('appeals: once answered, the committee decides', $r['ok'] && $owing() === 3000);
ck('appeals: an answer cannot change once it is decided', !Conduct::respond(8702, (int) $f['id'], 'accepted', '')['ok']);

/* ── the appeal ── */
ck('appeals: only the member it is about appeals', !Conduct::appeal(8705, (int) $f['id'], str_repeat('Not mine at all. ', 3))['ok']);
ck('appeals: grounds are required', !Conduct::appeal(8702, (int) $f['id'], 'Unfair.')['ok']);
$ap = Conduct::appeal(8702, (int) $f['id'], 'The facilitator wrote my early leave in her notebook; the page is dated and signed.');
ck('appeals: the member appeals an approved fine', $ap['ok'] && Conduct::hasOpenAppeal(8702));
ck('appeals: once only', !Conduct::appeal(8702, (int) $f['id'], 'A second go, with exactly the same grounds as before.')['ok']);
ck('appeals: the approver does not hear the appeal', !Conduct::hearAppeal((int) $ap['id'], $U(8703), 'overturned', 'Checked.')['ok']);
ck('appeals: nor the proposer, nor the member', !Conduct::hearAppeal((int) $ap['id'], $U(8701), 'overturned', 'x')['ok'] && !Conduct::hearAppeal((int) $ap['id'], $U(8702), 'overturned', 'x')['ok']);
ck('appeals: a hearing gives its reason', !Conduct::hearAppeal((int) $ap['id'], $U(8704), 'upheld', '')['ok']);
ck('appeals: a reduction is never upward', !Conduct::hearAppeal((int) $ap['id'], $U(8704), 'reduced', 'Halved.', 5000)['ok']);
$h = Conduct::hearAppeal((int) $ap['id'], $U(8704), 'reduced', 'Left with leave, but did not sign out. Halved.', 1500);
ck('appeals: somebody new reduces it, and what is owed is the new figure', $h['ok'] && $owing() === 1500 && !Conduct::hasOpenAppeal(8702));
ck('appeals: heard once', !Conduct::hearAppeal((int) $ap['id'], $U(8704), 'overturned', 'Again.')['ok']);
$b = Conduct::billsFor(8702)[0];
ck('appeals: the member\'s bill carries the outcome', $b['appeal_status'] === 'reduced' && (int) $b['appeal_value'] === 1500);

/* ── a deduction, overturned ── */
AvRules::save(['quest.notice_days' => '0'], 'test');
$d = Conduct::propose(8701, 87001, ['kind' => 'deduction', 'action' => 'negative_conduct', 'occurred_on' => $day, 'evidence' => 'Spoke over the facilitator three times and walked out of the circle.']);
Conduct::decide((int) $d['id'], $U(8703), true, '');
ck('appeals: an approved deduction takes the points', QuestPoints::total(8702) === -30);
$sid = QuestLadder::suggest(8702, 'demotion', 'raised by deduction', 'deduction:' . (int) $d['id']);
$ap2 = Conduct::appeal(8702, (int) $d['id'], 'I was asking about the fees; I did not walk out — I went to the toilet and came back.');
$h2 = Conduct::hearAppeal((int) $ap2['id'], $U(8704), 'overturned', 'Two members confirm the toilet; the conduct was a question.');
ck('appeals: overturned, the deduction is off the record', $h2['ok'] && QuestPoints::total(8702) === 0);
ck('appeals: …and the demotion it raised is dismissed', $sid > 0 && QuestLadder::find($sid)['status'] === 'dismissed');

/* ── time ── */
$d2 = Conduct::propose(8701, 87001, ['kind' => 'deduction', 'action' => 'meeting_absence', 'occurred_on' => $day, 'evidence' => 'Did not attend the Tuesday project meeting, no message sent.']);
Conduct::decide((int) $d2['id'], $U(8703), true, '');
$db->prepare('UPDATE conduct_cases SET decided_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 30 * 86400), (int) $d2['id']]);
ck('appeals: after the appeal days, it cannot be appealed', Conduct::appealOpenUntil(Conduct::find((int) $d2['id'])) === ''
   && !Conduct::appeal(8702, (int) $d2['id'], 'Too late, but here are my grounds all the same, at length.')['ok']);
AvRules::save(['quest.appeals_enabled' => '0'], 'test');
$db->prepare('UPDATE conduct_cases SET decided_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), (int) $d2['id']]);
ck('appeals: switched off, nothing is appealable', Conduct::appealOpenUntil(Conduct::find((int) $d2['id'])) === '');
AvRules::reset('quest.appeals_enabled', 'test');

/* ── reporting yourself ── */
ck('appeals: nobody reports an award for themselves', !Conduct::selfReport(8702, ['action' => 'award:extra_responsibility', 'occurred_on' => $day, 'evidence' => $ev])['ok']);
$s = Conduct::selfReport(8702, ['action' => 'fine:idle', 'amount' => '1', 'occurred_on' => $day, 'evidence' => 'Sat idle for an hour on Wednesday afternoon with nothing assigned.']);
ck('appeals: a member reports a fine against themselves, at the catalogue\'s price', $s['ok'] && (int) Conduct::find((int) $s['id'])['amount'] === 500
   && Conduct::find((int) $s['id'])['mentor_kind'] === 'self' && Conduct::find((int) $s['id'])['response'] === 'accepted');
ck('appeals: the member does not decide their own report', !Conduct::decide((int) $s['id'], $U(8702) + ['email' => 'ap8702@x.test'], true, '')['ok']);
AvRules::save(['quest.notice_days' => '2'], 'test');
ck('appeals: a self-report is already answered, so no notice holds it', Conduct::noticeLeft(Conduct::find((int) $s['id'])) === '');
ck('appeals: somebody else confirms it', Conduct::decide((int) $s['id'], $U(8704), true, '')['ok']);
ck('appeals: a self-report is not appealable', Conduct::appealOpenUntil(Conduct::find((int) $s['id'])) === ''
   && !Conduct::appeal(8702, (int) $s['id'], 'I changed my mind about what I said earlier on.')['ok']);
ck('appeals: without an NGV account, a member can report a deduction but not a fine',
   !Conduct::selfReport(8705, ['action' => 'fine:idle', 'occurred_on' => $day, 'evidence' => $ev])['ok']
   && Conduct::selfReport(8705, ['action' => 'deduction:meeting_absence', 'occurred_on' => $day, 'evidence' => 'Missed the Monday meeting; I forgot it was on.'])['ok']);
$w = (int) $db->query("SELECT id FROM conduct_cases WHERE mentee_id = 8705 AND status = 'proposed'")->fetchColumn();
ck('appeals: a member may withdraw their own report while it waits', Conduct::withdraw(8705, $w)['ok']);
AvRules::save(['quest.self_report' => '0'], 'test');
ck('appeals: switched off, nobody reports themselves', !Conduct::selfReport(8705, ['action' => 'deduction:meeting_absence', 'occurred_on' => $day, 'evidence' => 'Missed the Monday meeting; I forgot it was on.'])['ok']);
AvRules::reset('quest.self_report', 'test');

/* ── an open appeal holds the overdue consequences ── */
AvRules::save(['quest.money_enabled' => '1', 'quest.notice_days' => '0'], 'test');
$f3 = Conduct::propose(8701, 87001, ['kind' => 'fine', 'action' => 'sleeping', 'occurred_on' => $day, 'evidence' => 'Asleep on the bench during the afternoon work block for forty minutes.']);
Conduct::decide((int) $f3['id'], $U(8703), true, '');
Conduct::appeal(8702, (int) $f3['id'], 'I was told to rest by the nurse after the morning drill; she can confirm it.');
$db->prepare("INSERT INTO quest_reconciliations (month, member_id, bracket_id, points, point_value, status, due_on, balance, created_at) VALUES ('2026-09', 8702, 0, 0, 0, 'open', '2026-10-01', 2000, ?)")->execute([$now]);
QuestMoney::sweep(true);
ck('appeals: while an appeal is open, an overdue balance suggests no suspension',
   (int) $db->query("SELECT COUNT(*) FROM quest_suggestions WHERE member_id = 8702 AND kind = 'suspension'")->fetchColumn() === 0);
AvRules::resetAll('test');

/* ── where it lives ── */
ck('appeals: every account has Bills & appeals in the Vanguard Quest group', PortalNav::SECTIONS['bills']['for'] === ['all'] && PortalNav::SECTIONS['bills']['group'] === 'Vanguard Quest');
ck('appeals: the desk has an Appeals section, for the committee', (QuestDesk::SECTIONS['appeals'][3] ?? '') === 'decide' && is_file(AV_ROOT . '/mentorship/compliance/views/appeals.php'));
$ep = (string) file_get_contents(AV_ROOT . '/mentorship/conduct.php');
ck('appeals: the endpoint handles the member\'s ops and the hearing',
   str_contains($ep, "'self_report'   => Conduct::selfReport(") && str_contains($ep, "'appeal'        => Conduct::appeal(") && str_contains($ep, "case 'appeal_hear':"));
$view = (string) file_get_contents(AV_ROOT . '/portal/views/bills.php');
ck('appeals: the member\'s page posts every op it shows', preg_match_all('~name="op" value="(self_report|respond|appeal|self_withdraw)"~', $view) >= 4);
$u = $U(8702);
ob_start(); require AV_ROOT . '/portal/views/bills.php'; $html = (string) ob_get_clean();
ck('appeals: the member\'s page renders, with no inline style', str_contains($html, 'data-view="bills"') && !preg_match('/\sstyle\s*=/', $html) && str_contains($html, 'Reduced'));

$wipe();
