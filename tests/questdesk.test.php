<?php
/**
 * tests/questdesk.test.php — the desk on the Pyramid Manual's catalogue.
 *
 * The promises: an award is priced by the catalogue, never the mentor; a
 * switched-off line cannot be proposed and a re-priced one proposes at its new
 * price; a deduction takes points away only once the committee approves it;
 * a catalogue fine is charged at the manual's price under its ledger reason;
 * an award is held to the member's monthly ceiling, and only while that rule
 * is on.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8150–8199.
 */
declare(strict_types=1);

$db = Database::pdo();
$nf = NgvDb::pdo();
Conduct::ensure(); QuestPoints::ensure(); QuestRules::ensure();
$db->exec('DELETE FROM conduct_cases WHERE mentee_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM mentorships WHERE mentor_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM quest_rule_items');
$nf->exec('DELETE FROM ngv_charges WHERE member_id BETWEEN 8150 AND 8199');
$nf->exec('DELETE FROM ngv_participants WHERE member_id BETWEEN 8150 AND 8199');
QuestRules::forget();

reset_users();
$people = [8151 => ['Desk Mentor', 'mentor.qd@x.test'], 8152 => ['Desk Mentee', 'mentee.qd@x.test'],
           8153 => ['Desk Committee', 'committee.qd@x.test'], 8154 => ['Desk Boss', 'boss.qd@afrovanguard.org.ng']];
foreach ($people as $id => [$n, $e]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $n, $e, '', 'member', gmdate('Y-m-d H:i:s')]);
}
$U = static fn(int $id): array => ['id' => $id, 'name' => $people[$id][0], 'email' => $people[$id][1]];
AdminRoles::add('boss.qd@afrovanguard.org.ng', 'superadmin', 'test');
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))->execute([8153, 'test', gmdate('Y-m-d H:i:s')]);
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, kind, created_at) VALUES (81501, 8151, 8152, 'active', 'vquest', ?)")
   ->execute([gmdate('Y-m-d H:i:s')]);
$today = date('Y-m-d');
$ev = 'Missed the Tuesday project meeting and the weekly tracker without a word to anyone.';
$propose = static fn(array $in) => Conduct::propose(8151, 81501, $in + ['occurred_on' => $today, 'evidence' => $ev]);

/* ── awards: the catalogue prices them ── */

$a = $propose(['kind' => 'award', 'action' => 'conflict_resolved', 'points' => 1]);
ck('questdesk: an award proposes at the catalogue\'s price, whatever the form said',
   $a['ok'] && (int) Conduct::find($a['id'])['points'] === 50 && Conduct::find($a['id'])['reason'] === 'leadership');
ck('questdesk: an action not in the catalogue is refused', !$propose(['kind' => 'award', 'action' => 'bribery'])['ok']);
ck('questdesk: "mentee rises" is the system\'s to give, not a mentor\'s', !$propose(['kind' => 'award', 'action' => 'mentee_rises'])['ok']);

QuestRules::set('award', 'team_harmony', ['enabled' => false], $U(8154));
ck('questdesk: a switched-off line cannot be proposed', !$propose(['kind' => 'award', 'action' => 'team_harmony'])['ok']);
QuestRules::set('award', 'early_completion', ['value' => 15], $U(8154));
$b = $propose(['kind' => 'award', 'action' => 'early_completion']);
ck('questdesk: a re-priced line proposes at its new price', $b['ok'] && (int) Conduct::find($b['id'])['points'] === 15);

AvRules::save(['quest.awards_custom' => '1'], 'test');
$c = $propose(['kind' => 'award', 'action' => 'reason:other', 'title' => 'Carried the generator up three floors', 'points' => 12]);
ck('questdesk: with custom awards switched on, "Other" takes the mentor\'s number', $c['ok'] && (int) Conduct::find($c['id'])['points'] === 12
   && Conduct::find($c['id'])['action_id'] === '');
AvRules::reset('quest.awards_custom');
ck('questdesk: …and is refused again once switched off', !$propose(['kind' => 'award', 'action' => 'reason:other', 'title' => 'x', 'points' => 3, 'occurred_on' => date('Y-m-d', strtotime('-1 day'))])['ok']);

/* ── deductions ── */

$d = $propose(['kind' => 'deduction', 'action' => 'value_breach']);
ck('questdesk: a deduction is proposed at the catalogue\'s price', $d['ok'] && (int) Conduct::find($d['id'])['points'] === 50
   && Conduct::find($d['id'])['kind'] === 'deduction');
ck('questdesk: proposing takes nothing away', QuestPoints::total(8152) === 0);
Conduct::decide($a['id'], $U(8153), true, '');
Conduct::decide($d['id'], $U(8153), true, 'Two facilitators saw it.');
ck('questdesk: once approved, the deduction comes off the total', QuestPoints::total(8152) === 0
   && (int) $db->query("SELECT points FROM quest_points WHERE member_id = 8152 AND period = 'deduction:" . (int) $d['id'] . "'")->fetchColumn() === -50);
ck('questdesk: a deduction does not count against what was earned this month', QuestPoints::earnedInMonth(8152, substr($today, 0, 7)) === 50);
ck('questdesk: a deduction is once-only, like a grant', !QuestPoints::deduct(8152, 50, 'deduction:' . $d['id']) && QuestPoints::total(8152) === 0);

AvRules::save(['quest.deductions_enabled' => '0'], 'test');
ck('questdesk: with deductions switched off, none can be proposed', !$propose(['kind' => 'deduction', 'action' => 'meeting_absence'])['ok']);
AvRules::reset('quest.deductions_enabled');

/* ── fines from the manual ── */

$nf->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (8152,'Desk Mentee','mentee.qd@x.test','active',?,?,?)")
   ->execute([$today, $today, $today]);
$f = $propose(['kind' => 'fine', 'action' => 'id_lost', 'amount' => '1']);
ck('questdesk: a catalogue fine is the manual\'s price under its ledger reason', $f['ok'] && (int) Conduct::find($f['id'])['amount'] === 4000
   && Conduct::find($f['id'])['reason'] === 'uniform' && Conduct::caseLabel(Conduct::find($f['id'])) === 'Lost ID card');
$g = $propose(['kind' => 'fine', 'action' => 'theft_dishonesty']);
ck('questdesk: a fine the manual prices each time needs an amount', !$g['ok']);
Conduct::decide($f['id'], $U(8153), true, '');
ck('questdesk: an approved catalogue fine is on the NGV account',
   (int) $nf->query("SELECT amount FROM ngv_charges WHERE member_id = 8152 AND kind = 'fine'")->fetchColumn() === 4000);

/* ── the monthly ceiling ── */

$db->exec('DELETE FROM quest_points WHERE member_id = 8152');
QuestPoints::grant(8152, 480, 'test:seed', 'test', '', 0, $today);
ck('questdesk: at level O the month\'s ceiling is 499', Conduct::withinCeiling(8152, 50, $today) === 19);
$h = $propose(['kind' => 'award', 'action' => 'significant_growth']);
$r = Conduct::decide($h['id'], $U(8153), true, '');
ck('questdesk: an award past the ceiling is approved up to it, and says so',
   $r['ok'] && str_contains($r['outcome'], 'monthly ceiling') && QuestPoints::total(8152) === 499);
AvRules::save(['quest.monthly_ceiling' => '0'], 'test');
ck('questdesk: with the ceiling switched off, the whole award fits', Conduct::withinCeiling(8152, 50, $today) === 50);
AvRules::reset('quest.monthly_ceiling');

$db->exec('DELETE FROM conduct_cases WHERE mentee_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM mentorships WHERE mentor_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8150 AND 8199');
$db->exec('DELETE FROM quest_rule_items');
$db->exec("DELETE FROM admin_users WHERE email = 'boss.qd@afrovanguard.org.ng'");
$nf->exec('DELETE FROM ngv_charges WHERE member_id BETWEEN 8150 AND 8199');
$nf->exec('DELETE FROM ngv_participants WHERE member_id BETWEEN 8150 AND 8199');
QuestRules::forget();
