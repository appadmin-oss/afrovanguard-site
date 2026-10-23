<?php
/**
 * tests/questmoney.test.php — the Pyramid Manual's Section 3, points against
 * fines by income bracket.
 *
 * The promises: nothing moves money until the switch is on and a bracket
 * gives a point a value; a bracket scales the manual's base fines; the month
 * offsets fines by points as a waiver the NGV ledger clamps, once per member;
 * a suspended member's points earn nothing that month; an overdue balance
 * SUGGESTS the suspension and the fine, never imposes them; the Reward Pool
 * is a share of fines actually paid.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8300–8399.
 */
declare(strict_types=1);

$db = Database::pdo();
$nf = NgvDb::pdo();
QuestMoney::ensure(); QuestLadder::ensure(); QuestPoints::ensure(); Conduct::ensure();
$clean = static function () use ($db, $nf): void {
    foreach (['quest_income_brackets', 'quest_member_brackets', 'quest_reconciliations', 'quest_reward_pool'] as $t) $db->exec('DELETE FROM ' . $t);
    $db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8300 AND 8399');
    $db->exec('DELETE FROM quest_suggestions WHERE member_id BETWEEN 8300 AND 8399');
    $db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8300 AND 8399');
    $nf->exec('DELETE FROM ngv_payments WHERE member_id BETWEEN 8300 AND 8399');
    $nf->exec('DELETE FROM ngv_fine_meta WHERE charge_id IN (SELECT id FROM ngv_charges WHERE member_id BETWEEN 8300 AND 8399)');
    $nf->exec('DELETE FROM ngv_charges WHERE member_id BETWEEN 8300 AND 8399');
    $nf->exec('DELETE FROM ngv_participants WHERE member_id BETWEEN 8300 AND 8399');
};
$clean();
AvRules::resetAll('test');

reset_users();
$now = gmdate('Y-m-d H:i:s');
foreach ([8301 => 'Money Member', 8302 => 'Money Unplaced', 8303 => 'Money Committee', 8304 => 'Money Suspended'] as $id => $n) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $n, 'm' . $id . '@x.test', '', 'member', $now]);
    $nf->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (?,?,?,'active',?,?,?)")
       ->execute([$id, $n, 'm' . $id . '@x.test', '2026-01-05', $now, $now]);
}
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))->execute([8303, 'test', $now]);
$committee = ['id' => 8303, 'email' => 'm8303@x.test'];
$member = ['id' => 8301, 'email' => 'm8301@x.test'];
$last = gmdate('Y-m', strtotime(gmdate('Y-m-01') . ' -1 month'));
$day = $last . '-10';

/* ── nothing moves until it is set up ── */

ck('money: not ready with the switch off and no brackets', !QuestMoney::ready());
ck('money: a member off the committee cannot set brackets', !QuestMoney::saveBracket(['label' => 'x', 'point_value' => 10], $member)['ok']);
ck('money: a bracket needs a name', !QuestMoney::saveBracket(['label' => '', 'point_value' => 10], $committee)['ok']);
ck('money: a bracket\'s top cannot be below its bottom', !QuestMoney::saveBracket(['label' => 'x', 'min_income' => '100000', 'max_income' => '50000'], $committee)['ok']);
$b = QuestMoney::saveBracket(['label' => 'Under ₦100k', 'min_income' => '0', 'max_income' => '100,000', 'point_value' => '50', 'fine_pct' => '150'], $committee);
ck('money: the committee sets a bracket', $b['ok'] && (int) QuestMoney::brackets()[$b['id']]['point_value'] === 50);
ck('money: still not ready while the switch is off', !QuestMoney::ready());
ck('money: …and the month cannot be run', !QuestMoney::apply($last, $committee)['ok']);

/* ── a bracket scales the manual's base fines ── */

ck('money: without a bracket a fine is the manual\'s base', QuestMoney::scaleFine(8301, 1000) === 1000);
QuestMoney::setMemberBracket(8301, $b['id'], $committee);
QuestMoney::setMemberBracket(8304, $b['id'], $committee);
ck('money: nobody sets their own bracket', !QuestMoney::setMemberBracket(8303, $b['id'], $committee)['ok']);
ck('money: in a bracket at 150%, the manual\'s ₦1,000 is ₦1,500', QuestMoney::scaleFine(8301, 1000) === 1500);
ck('money: a bracket in use cannot be deleted', !QuestMoney::deleteBracket($b['id'], $committee)['ok']);

/* ── the month ── */

QuestPoints::grant(8301, 40, 'test:earned', 'test', '', 0, $day);
QuestPoints::deduct(8301, 10, 'test:deducted', 'test', '', 0, $day);
NgvFines::issue(8301, 'conduct', 3000, 'Test fine', $day, false, 8303);
NgvFines::issue(8302, 'conduct', 1000, 'Test fine', $day, false, 8303);
QuestPoints::grant(8304, 20, 'test:earned', 'test', '', 0, $day);
NgvFines::issue(8304, 'conduct', 500, 'Test fine', $day, false, 8303);
$s = QuestLadder::suggest(8304, 'suspension', 'Test suspension.', 'test:sus', 0, $last);
QuestLadder::accept($s, $committee, '');

$p = QuestMoney::preview($last);
$row = current(array_filter($p['rows'], static fn($r) => $r['member_id'] === 8301));
ck('money: net points that month are monetised by the bracket — (40 − 10) × ₦50', $row && $row['points'] === 30 && $row['credit'] === 1500);
ck('money: and offset against that month\'s fines, leaving the balance', $row['fines'] === 3000 && $row['offset'] === 1500 && $row['balance'] === 1500);
ck('money: somebody fined with no bracket is listed, not skipped', (bool) array_filter($p['unplaced'], static fn($u) => $u['member_id'] === 8302));
$sus = current(array_filter($p['rows'], static fn($r) => $r['member_id'] === 8304));
ck('money: a member suspended that month loses that month\'s incentive', $sus && $sus['suspended'] && $sus['credit'] === 0 && $sus['balance'] === 500);
ck('money: previewing moves nothing', (int) NgvFines::forMember(8301)['owing'] === 3000);

AvRules::save(['quest.money_enabled' => '1'], 'test');
ck('money: ready once switched on with a valued bracket', QuestMoney::ready());
ck('money: a month that has not ended cannot be run', !QuestMoney::apply(gmdate('Y-m'), $committee)['ok']);
ck('money: nobody off the committee runs the month', !QuestMoney::apply($last, $member)['ok']);
$a = QuestMoney::apply($last, $committee);
ck('money: the committee runs the month', $a['ok'] && $a['ran'] === 2 && $a['offset'] === 1500 && $a['failed'] === []);
ck('money: the offset is on the NGV ledger, and the fine is part-settled by it', (int) NgvFines::forMember(8301)['owing'] === 1500
   && (int) $nf->query("SELECT COUNT(*) FROM ngv_payments WHERE member_id = 8301 AND kind = 'fine' AND credit_kind = 'waiver' AND note LIKE 'Quest points offset%'")->fetchColumn() === 1);
$rec = QuestMoney::forMember(8301)[0];
ck('money: the month is recorded with the balance and the day it is due', $rec['month'] === $last && (int) $rec['balance'] === 1500
   && $rec['status'] === 'open' && $rec['due_on'] > gmdate('Y-m-d'));
ck('money: the month runs once per member', QuestMoney::apply($last, $committee)['ran'] === 0
   && (int) $nf->query("SELECT COUNT(*) FROM ngv_payments WHERE member_id = 8301 AND credit_kind = 'waiver'")->fetchColumn() === 1);

/* ── overdue: suggested, never imposed ── */

$db->exec("UPDATE quest_reconciliations SET due_on = '2000-01-01' WHERE member_id = 8301");
$sw = QuestMoney::sweep(true);
$sug = QuestLadder::forMember(8301);
ck('money: an overdue balance suggests a week\'s suspension and the ₦2,000 fine', $sw['overdue'] === 1
   && (bool) array_filter($sug, static fn($s) => $s['kind'] === 'suspension' && $s['status'] === 'open')
   && (bool) array_filter($sug, static fn($s) => $s['kind'] === 'fine' && (int) $s['amount'] === 2000));
ck('money: and imposes neither', (int) NgvFines::forMember(8301)['owing'] === 1500 && !QuestLadder::suspendedIn(8301, gmdate('Y-m')));
$fineSug = current(array_filter($sug, static fn($s) => $s['kind'] === 'fine'));
ck('money: accepting the suggested fine posts it', QuestLadder::accept((int) $fineSug['id'], $committee, '')['ok'] && (int) NgvFines::forMember(8301)['owing'] === 3500);
ck('money: the row is marked overdue, and the sweep does not suggest twice', QuestMoney::forMember(8301)[0]['status'] === 'overdue'
   && count(QuestLadder::forMember(8301)) === 2);

/* ── the Reward Pool ── */

$nf->prepare("INSERT INTO ngv_payments (member_id, kind, credit_kind, amount, currency, period, created_at) VALUES (8302, 'fine', 'payment', 1000, 'NGN', '', ?)")
   ->execute([$day . ' 12:00:00']);
AvRules::save(['quest.reward_pool_pct' => '10'], 'test');
$pool = QuestMoney::computePool($last);
ck('money: the pool is its share of fines actually paid that month — not waived, not owed', $pool['collected'] === 1000 && $pool['amount'] === 100);
ck('money: and is kept by month', QuestMoney::poolFor($last)['amount'] === 100 && QuestMoney::poolTotal() === 100);

$clean();
AvRules::resetAll('test');
