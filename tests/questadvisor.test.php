<?php
/**
 * tests/questadvisor.test.php — the AI advisor on the catalogue.
 *
 * The promises: the figures are counted in grouped queries and flag what a
 * careful person would (never used, often rejected, one mentor's habit,
 * clipped by the ceiling); with no model the advice is the figures alone and
 * says so; a model's proposals are kept only if they name a real line at a
 * price inside its bounds; nothing applies itself — a superadmin applies a
 * proposal, once, into the same history as a change made by hand.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8400–8499.
 */
declare(strict_types=1);

$db = Database::pdo();
Conduct::ensure(); QuestRules::ensure(); QuestAdvisor::ensure();
$db->exec('DELETE FROM conduct_cases');
$db->exec('DELETE FROM quest_rule_items'); $db->exec('DELETE FROM quest_rule_log');
$db->exec('DELETE FROM quest_advice');
QuestRules::forget(); QuestAdvisor::$model = null;
AvRules::resetAll('test');

reset_users();
foreach ([8401 => ['Advice Boss', 'boss.qa@afrovanguard.org.ng'], 8402 => ['Advice Mentor', 'mentor.qa@x.test'], 8403 => ['Other Mentor', 'other.qa@x.test']] as $id => [$n, $e]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $n, $e, '', 'member', gmdate('Y-m-d H:i:s')]);
}
AdminRoles::add('boss.qa@afrovanguard.org.ng', 'superadmin', 'test');
$boss = ['id' => 8401, 'email' => 'boss.qa@afrovanguard.org.ng'];
$mentor = ['id' => 8402, 'email' => 'mentor.qa@x.test'];

$case = static function (string $kind, string $action, string $status, int $mentor, int $mentee, string $outcome = '') use ($db): void {
    $pts = (int) (QuestRules::item($kind, $action)['value'] ?? 0);
    $db->prepare("INSERT INTO conduct_cases (kind, mentee_id, mentor_id, mentorship_id, reason, action_id, points, amount, occurred_on, evidence, status, outcome, created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$kind, $mentee, $mentor, 1, $kind === 'fine' ? 'conduct' : 'test', $action, $kind === 'fine' ? 0 : $pts, $kind === 'fine' ? $pts : 0,
                  gmdate('Y-m-d'), 'evidence', $status, $outcome, gmdate('Y-m-d H:i:s')]);
};
/* punctuality: 8 proposals, all by one mentor, 4 rejected. */
for ($i = 0; $i < 8; $i++) $case('award', 'punctuality', $i < 4 ? 'rejected' : 'approved', 8402, 8450 + $i);
/* exceeded_target: 4 approved, 3 of them clipped by the ceiling. */
for ($i = 0; $i < 4; $i++) $case('award', 'exceeded_target', 'approved', $i % 2 ? 8402 : 8403, 8460 + $i, $i < 3 ? 'points:20 (monthly ceiling)' : 'points:100');
$case('deduction', 'value_breach', 'approved', 8403, 8470);

/* ── figures ── */

$u = QuestAdvisor::usage();
$p = $u['lines']['award:punctuality'];
ck('advisor: usage counts each line — proposed, approved, rejected, granted', $p['proposed'] === 8 && $p['approved'] === 4 && $p['rejected'] === 4 && $p['granted'] === 120);
ck('advisor: …and how lopsided it is', $p['mentors'] === 1 && $p['top_mentor_share'] === 100);
ck('advisor: totals add awards, deductions and fines apart', $u['totals']['points_deducted'] === 50 && $u['totals']['proposed'] === 13);

$sig = QuestAdvisor::signals($u);
$has = static fn(string $id, string $word) => (bool) array_filter($sig, static fn($s) => $s['id'] === $id && str_contains($s['text'], $word));
ck('advisor: a line rejected 40% of the time or more is flagged', $has('punctuality', 'rejected 50%'));
ck('advisor: one mentor\'s habit is flagged', $has('punctuality', 'One mentor accounts for 100%'));
ck('advisor: a line never put forward is flagged', $has('team_harmony', 'has not been put forward'));
$clip = current(array_filter($sig, static fn($s) => $s['id'] === 'exceeded_target'));
ck('advisor: a line the ceiling keeps clipping is flagged, with a concrete price to try',
   $clip && $clip['proposal'] && $clip['proposal']['value'] === 75);

/* ── asking ── */

ck('advisor: only a superadmin asks', !QuestAdvisor::ask('optimize', $mentor)['ok']);
ck('advisor: a mode that is not one of the four is refused', !QuestAdvisor::ask('gamble', $boss)['ok']);
AvRules::save(['quest.ai_advisor' => '0'], 'test');
ck('advisor: switched off, it is not asked', !QuestAdvisor::ask('optimize', $boss)['ok']);
AvRules::reset('quest.ai_advisor');

AvRules::save(['ai.enabled' => '0'], 'test');
$r = QuestAdvisor::ask('update', $boss);
ck('advisor: with no model, the advice is the figures alone, and says so', $r['ok'] && $r['advice']['source'] === 'rules-only'
   && str_contains($r['advice']['summary'], 'no model') && count($r['advice']['proposals']) === 1);

$seen = '';
QuestAdvisor::$model = static function (string $sys) use (&$seen): array {
    $seen = $sys;
    return ['ok' => true, 'provider' => 'test', 'text' => "Here you go:\n" . json_encode([
        'summary' => 'Punctuality is contested; exceeding target is capped out.',
        'proposals' => [
            ['kind' => 'award', 'id' => 'punctuality', 'enabled' => null, 'value' => 15, 'why' => 'Rejected half the time.'],
            ['kind' => 'award', 'id' => 'bribery', 'enabled' => false, 'value' => null, 'why' => 'Not a line.'],
            ['kind' => 'award', 'id' => 'early_completion', 'enabled' => null, 'value' => 99999, 'why' => 'Out of bounds.'],
            ['kind' => 'deduction', 'id' => 'no_additions', 'enabled' => null, 'value' => 70, 'why' => 'No change at all.'],
        ],
        'ideas' => [['label' => 'Teaching a session to younger members', 'kind' => 'award', 'points' => 35, 'why' => 'Nothing prices it.']],
        'questions' => ['Should punctuality stay a Quest award at all?'],
    ])];
};
$r = QuestAdvisor::ask('optimize', $boss);
$adv = $r['advice'];
ck('advisor: the model is given the catalogue and the figures', str_contains($seen, 'punctuality · award · pts 30') && str_contains($seen, 'rejected 4'));
ck('advisor: the model\'s summary, ideas and questions are kept', $adv['source'] === 'test' && str_contains($adv['summary'], 'contested')
   && $adv['ideas'][0]['points'] === 35 && $adv['questions'][0] === 'Should punctuality stay a Quest award at all?');
ck('advisor: a proposal for a line that does not exist, out of bounds, or changing nothing is dropped',
   count(array_filter($adv['proposals'], static fn($x) => in_array($x['id'], ['bribery', 'early_completion', 'no_additions'], true))) === 0);
$idx = array_search('punctuality', array_column($adv['proposals'], 'id'), true);
ck('advisor: a sound proposal is kept, and the figures\' own proposal beside it', $idx !== false && in_array('exceeded_target', array_column($adv['proposals'], 'id'), true));
ck('advisor: asking changes nothing', QuestRules::item('award', 'punctuality')['value'] === 30);

/* ── applying ── */

ck('advisor: a mentor cannot apply a proposal', !QuestAdvisor::apply((int) $adv['id'], (int) $idx, $mentor)['ok']);
$ap = QuestAdvisor::apply((int) $adv['id'], (int) $idx, $boss);
ck('advisor: a superadmin applies it, through QuestRules', $ap['ok'] && QuestRules::item('award', 'punctuality')['value'] === 15);
$h = QuestRules::history(1)[0];
ck('advisor: in the same history as a hand-made change, citing the advice', str_contains($h['note'], 'AI advisor #' . $adv['id']) && $h['item_id'] === 'punctuality');
ck('advisor: a proposal is applied once', !QuestAdvisor::apply((int) $adv['id'], (int) $idx, $boss)['ok']);
ck('advisor: the advice remembers what was applied', QuestAdvisor::find((int) $adv['id'])['applied'] === [(int) $idx]);

QuestAdvisor::$model = null;
$db->exec('DELETE FROM conduct_cases');
$db->exec('DELETE FROM quest_rule_items'); $db->exec('DELETE FROM quest_rule_log');
$db->exec('DELETE FROM quest_advice');
$db->exec("DELETE FROM admin_users WHERE email = 'boss.qa@afrovanguard.org.ng'");
QuestRules::forget();
AvRules::resetAll('test');
