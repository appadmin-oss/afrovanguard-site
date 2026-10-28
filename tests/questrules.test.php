<?php
/**
 * tests/questrules.test.php — every priced line of the Quest can be switched
 * off or re-priced, and the manual's price is never lost.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8100–8199.
 */
declare(strict_types=1);

QuestRules::ensure();
$db = Database::pdo();
$db->exec('DELETE FROM quest_rule_items');
$db->exec('DELETE FROM quest_rule_log');
QuestRules::forget(); Quest::$override = null; Quest::forget();

reset_users();
foreach ([[8101, 'Rules Boss', 'boss.qr@afrovanguard.org.ng'], [8102, 'Rules Member', 'member.qr@afrovanguard.org.ng']] as [$id, $n, $e]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $n, $e, '', 'member', gmdate('Y-m-d H:i:s')]);
}
AdminRoles::add('boss.qr@afrovanguard.org.ng', 'superadmin', 'test');
$boss = ['id' => 8101, 'email' => 'boss.qr@afrovanguard.org.ng'];
$member = ['id' => 8102, 'email' => 'member.qr@afrovanguard.org.ng'];

/* ── reading ── */

$aw = QuestRules::items('award');
ck('questrules: the catalogue reads at the manual\'s prices, all switched on',
   count($aw) === count(Quest::awards()) && $aw['punctuality']['value'] === 30 && $aw['punctuality']['base'] === 30
   && !array_filter($aw, static fn($a) => !$a['enabled'] || $a['overridden']));
ck('questrules: deductions and fines read the same way',
   QuestRules::item('deduction', 'no_additions')['value'] === 70 && QuestRules::item('fine', 'id_lost')['value'] === 4000);
ck('questrules: a fine the manual prices each time stays unpriced', QuestRules::item('fine', 'theft_dishonesty')['value'] === null);
ck('questrules: a line that is not in the registry is nothing', QuestRules::item('award', 'bribery') === null && QuestRules::items('nonsense') === []);

/* ── who may change them ── */

ck('questrules: a member who is not a superadmin cannot change a price',
   !QuestRules::set('award', 'punctuality', ['value' => 99], $member)['ok'] && QuestRules::item('award', 'punctuality')['value'] === 30);

/* ── changing ── */

$r = QuestRules::set('award', 'punctuality', ['value' => 20], $boss, 'Too generous for turning up.');
ck('questrules: a superadmin re-prices a line', $r['ok'] && QuestRules::item('award', 'punctuality')['value'] === 20);
ck('questrules: …and the manual\'s price is still known', QuestRules::item('award', 'punctuality')['base'] === 30
   && QuestRules::item('award', 'punctuality')['overridden']);
ck('questrules: a price outside the bounds is refused',
   !QuestRules::set('award', 'punctuality', ['value' => 0], $boss)['ok'] && !QuestRules::set('award', 'punctuality', ['value' => 5000], $boss)['ok']);

QuestRules::set('deduction', 'no_additions', ['enabled' => false], $boss, 'Not for teenagers.');
ck('questrules: a line can be switched off', !QuestRules::item('deduction', 'no_additions')['enabled']
   && QuestRules::usable('deduction', 'no_additions') === null && QuestRules::usable('deduction', 'value_breach') !== null);

ck('questrules: a change that changes nothing is reported, not logged',
   !empty(QuestRules::set('award', 'punctuality', ['value' => 20], $boss)['unchanged']));

$h = QuestRules::history();
ck('questrules: every change is in the history, newest first, with who and why',
   count($h) === 2 && $h[0]['item_id'] === 'no_additions' && $h[0]['what'] === 'switched off'
   && $h[1]['what'] === 'price 30 pts → 20 pts' && $h[1]['note'] === 'Too generous for turning up.' && $h[1]['by_name'] === 'Rules Boss');

$d = QuestRules::drift();
ck('questrules: drift says how far the live catalogue is from the manual',
   $d['award']['repriced'] === 1 && $d['deduction']['off'] === 1 && $d['fine']['off'] === 0);

/* ── back to the manual ── */

QuestRules::reset('award', 'punctuality', $boss);
QuestRules::set('deduction', 'no_additions', ['enabled' => true], $boss);
ck('questrules: reset puts the manual\'s price back and leaves no override behind',
   QuestRules::item('award', 'punctuality')['value'] === 30 && !QuestRules::item('award', 'punctuality')['overridden']
   && (int) $db->query('SELECT COUNT(*) FROM quest_rule_items')->fetchColumn() === 0);

/* ── the global switches live with every other rule ── */

ck('questrules: the Quest\'s global switches are AvRules, in their own group',
   AvRules::bool('quest.deductions_enabled') && AvRules::bool('quest.monthly_ceiling') && !AvRules::bool('quest.money_enabled')
   && AvRules::bool('levels.quest_pyramid'));

$db->exec('DELETE FROM quest_rule_items');
$db->exec('DELETE FROM quest_rule_log');
QuestRules::forget();
