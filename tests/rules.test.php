<?php
/**
 * tests/rules.test.php — the editable brain: operating rules, doctrine and
 * prompt templates.
 *
 * The point of these three classes is that nothing the AI depends on is
 * hardcoded. So the tests care most about two things: that a rule changed in
 * one place actually reaches the prompt, and that a bad value is refused rather
 * than quietly poisoning every downstream decision.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

foreach (['AvRules', 'AvKnowledge', 'AvPrompts', 'Levels', 'Mentorship'] as $c) {
    require_once AV_ROOT . "/lib/$c.php";
}

/** Return every rule to its coded default so each block starts clean. */
function rules_reset(): void {
    AvRules::ensure();
    try { Database::pdo()->exec('DELETE FROM av_rules'); } catch (Throwable $e) {}
    AvRules::flush();
}

/* ---- Defaults: a fresh install is coherent ---- */
rules_reset();
ck('rules: default cadence is 7 days', AvRules::int('mentorship.cadence_days') === 7);
ck('rules: default ladder runs O..G', AvRules::arr('levels.order') === ['O','A','B','C','D','E','F','G']);
ck('rules: auto-promote is off by default', AvRules::bool('levels.auto_promote') === false);
ck('rules: character scoring is off by default', AvRules::bool('ai.allow_character_score') === false);
ck('rules: escalation tone is non-empty', AvRules::str('escalation.tone') !== '');
// The shipped defaults must not contradict each other, or every install starts
// with a warning nobody can act on.
ck('rules: shipped defaults have no conflicts', AvRules::conflicts() === []);
ck('rules: snapshot covers the whole registry', count(AvRules::snapshot()) === count(AvRules::REGISTRY));

/* ---- Precedence: a stored override beats the coded default ---- */
rules_reset();
$r = AvRules::set('mentorship.cadence_days', '14', 'test');
ck('rules: set accepts a valid int', !empty($r['ok']));
ck('rules: override wins over the default', AvRules::int('mentorship.cadence_days') === 14);
ck('rules: rawOverride reports the stored value', AvRules::rawOverride('mentorship.cadence_days') === '14');
// Clearing returns the rule to its default rather than storing an empty value.
AvRules::set('mentorship.cadence_days', '', 'test');
ck('rules: clearing restores the default', AvRules::int('mentorship.cadence_days') === 7);
ck('rules: cleared rule has no override', AvRules::rawOverride('mentorship.cadence_days') === '');

/* ---- Validation: bad values are refused, not coerced ---- */
rules_reset();
ck('rules: reject above max', empty(AvRules::set('mentorship.cadence_days', '900')['ok']));
ck('rules: reject below min', empty(AvRules::set('mentorship.cadence_days', '0')['ok']));
ck('rules: reject non-numeric int', empty(AvRules::set('mentorship.cadence_days', 'soon')['ok']));
ck('rules: reject unknown enum member', empty(AvRules::set('escalation.miss_1', 'shout')['ok']));
ck('rules: accept a known enum member', !empty(AvRules::set('escalation.miss_1', 'nudge_mentee')['ok']));
ck('rules: reject an unknown key', empty(AvRules::set('does.not.exist', '1')['ok']));
ck('rules: reject duplicate list members', empty(AvRules::set('levels.order', 'O,A,A')['ok']));
ck('rules: bad value left the rule untouched', AvRules::int('mentorship.cadence_days') === 7);
// A rejected write must not have been stored.
ck('rules: rejected write stored nothing', AvRules::rawOverride('mentorship.cadence_days') === '');

/* ---- Booleans accept the forms a human or a form would send ---- */
rules_reset();
foreach (['1' => true, 'true' => true, 'yes' => true, 'on' => true, '0' => false, 'false' => false, 'off' => false] as $in => $want) {
    AvRules::set('levels.require_multiplication', (string) $in);
    ck("rules: bool '$in' parses correctly", AvRules::bool('levels.require_multiplication') === $want);
}

/* ---- Conflicts: individually valid, jointly nonsensical ---- */
rules_reset();
AvRules::set('health.amber_min_rate', '95');       // above the Green threshold
ck('rules: amber above green is flagged', count(AvRules::conflicts()) > 0);
rules_reset();
AvRules::set('levels.auto_promote', '1');
ck('rules: auto-promote is flagged as a conflict', count(AvRules::conflicts()) > 0);
rules_reset();
AvRules::set('ai.allow_character_score', '1');
ck('rules: character scoring is flagged as a conflict', count(AvRules::conflicts()) > 0);
rules_reset();
ck('rules: conflicts clear again after reset', AvRules::conflicts() === []);

/* ---- Undo restores the previous state ---- */
rules_reset();
AvRules::set('mentorship.inactive_days', '30');
$prev = AvRules::rawOverride('mentorship.inactive_days');
AvRules::set('mentorship.inactive_days', '45');
ck('rules: second write applied', AvRules::int('mentorship.inactive_days') === 45);
AvRules::applyUndo('rule_set', ['key' => 'mentorship.inactive_days', 'to' => $prev]);
ck('rules: undo restores the prior value', AvRules::int('mentorship.inactive_days') === 30);
AvRules::applyUndo('rule_set', ['key' => 'mentorship.inactive_days', 'to' => '']);
ck('rules: undo to empty restores the default', AvRules::int('mentorship.inactive_days') === 21);
ck('rules: undo rejects an unknown op', AvRules::applyUndo('nope', ['key' => 'x']) === false);

/* ---- Doctrine: seeded, editable, bounded ---- */
$seeded = AvKnowledge::all();
ck('knowledge: seeds on first use', count($seeded) >= 8);
ck('knowledge: seeded entries are live', (bool) $seeded[0]['enabled']);
ck('knowledge: prompt block is non-empty', AvKnowledge::asPromptBlock() !== '');

$bad = AvKnowledge::save(0, ['title' => '', 'body' => 'x']);
ck('knowledge: reject a blank title', empty($bad['ok']));
$bad2 = AvKnowledge::save(0, ['title' => 'x', 'body' => '']);
ck('knowledge: reject a blank body', empty($bad2['ok']));

$new = AvKnowledge::save(0, ['title' => 'Test entry', 'body' => 'A fact for the test.', 'priority' => 99, 'enabled' => true]);
ck('knowledge: save returns an id', !empty($new['ok']) && $new['id'] > 0);
AvKnowledge::flush();
ck('knowledge: a live entry reaches the prompt', strpos(AvKnowledge::asPromptBlock(), 'A fact for the test.') !== false);

// Disabling an entry must remove it from what the AI is told.
AvKnowledge::save((int) $new['id'], ['title' => 'Test entry', 'body' => 'A fact for the test.', 'priority' => 99, 'enabled' => false]);
AvKnowledge::flush();
ck('knowledge: a disabled entry leaves the prompt', strpos(AvKnowledge::asPromptBlock(), 'A fact for the test.') === false);

$before = count(AvKnowledge::all());
ck('knowledge: delete removes the row', AvKnowledge::remove((int) $new['id']) && count(AvKnowledge::all()) === $before - 1);

// The doctrine block is hard-bounded so one long entry cannot crowd out a prompt.
rules_reset();
AvRules::set('ai.doctrine_char_limit', '600');
AvKnowledge::flush();
ck('knowledge: block honours its size limit', mb_strlen(AvKnowledge::asPromptBlock()) <= 601);
rules_reset();
AvKnowledge::flush();

/* ---- Prompts: interpolation, context, validation ---- */
$p = AvPrompts::render('meeting.minutes', ['today' => '2026-01-02', 'max_items' => 5]);
ck('prompts: interpolates a declared variable', strpos($p, '2026-01-02') !== false);
ck('prompts: interpolates the item cap', strpos($p, 'at most 5 action items') !== false);
ck('prompts: leaves no unfilled placeholders', strpos($p, '{{') === false);
ck('prompts: appends the doctrine block', strpos($p, 'AFROVANGUARD DOCTRINE') !== false);
// meeting.minutes deliberately does NOT carry the rules block — it summarises,
// it does not judge — so appending thresholds there would be noise.
ck('prompts: omits rules where not requested', strpos($p, 'AFROVANGUARD OPERATING RULES') === false);

$n = AvPrompts::render('mentorship.nudge', ['name' => 'Ada', 'other' => 'Bode', 'missed' => 'a check-in', 'days' => 30, 'step' => 2]);
ck('prompts: nudge carries the rules block', strpos($n, 'AFROVANGUARD OPERATING RULES') !== false);
ck('prompts: nudge carries the doctrine block', strpos($n, 'AFROVANGUARD DOCTRINE') !== false);
ck('prompts: unknown template renders empty', AvPrompts::render('no.such.prompt') === '');

// A rule edited in Studio must reach the model on the very next call.
rules_reset();
AvRules::set('mentorship.cadence_days', '30');
$n2 = AvPrompts::render('mentorship.nudge', ['name' => 'Ada', 'other' => 'Bode', 'missed' => 'x', 'days' => 1, 'step' => 1]);
ck('prompts: a rule change reaches the prompt', strpos($n2, 'every 30 days') !== false);
rules_reset();

ck('prompts: reject an undeclared placeholder', empty(AvPrompts::validate('meeting.minutes', 'Hi {{nobody}}')['ok']));
ck('prompts: accept a declared placeholder', !empty(AvPrompts::validate('meeting.minutes', 'Today is {{today}}')['ok']));
ck('prompts: an empty template means "use the default"', !empty(AvPrompts::validate('meeting.minutes', '')['cleared']));

$def = AvPrompts::template('goal.tasks');
AvPrompts::set('goal.tasks', 'Custom planner for {{max}} tasks on {{today}}.', 'test');
ck('prompts: override replaces the default', strpos(AvPrompts::template('goal.tasks'), 'Custom planner') === 0);
ck('prompts: rawOverride reports the stored template', strpos(AvPrompts::rawOverride('goal.tasks'), 'Custom planner') === 0);
AvPrompts::applyUndo('prompt_set', ['key' => 'goal.tasks', 'to' => '']);
ck('prompts: undo restores the default', AvPrompts::template('goal.tasks') === $def);
ck('prompts: restored prompt has no override', AvPrompts::rawOverride('goal.tasks') === '');

/* ---- Levels: the ladder follows the rules, and rewards mentoring ---- */
reset_users();
rules_reset();
ck('levels: default ladder has 8 rungs', count(Levels::order()) === 8);
ck('levels: G is the top', Levels::nextOf('G') === null);
ck('levels: A follows O', Levels::nextOf('O') === 'A');
ck('levels: ladder() describes every configured rung', count(Levels::ladder()) === 8);

// Shortening the ladder must move the ceiling with it.
AvRules::set('levels.order', 'O,A,B');
ck('levels: a shortened ladder is honoured', Levels::order() === ['O','A','B']);
ck('levels: the new top has no next', Levels::nextOf('B') === null);
// An unusable value falls back to the shipped ladder rather than breaking.
try { Database::pdo()->prepare('UPDATE av_rules SET value = ? WHERE rule_key = ?')->execute(['O', 'levels.order']); } catch (Throwable $e) {}
AvRules::flush();
ck('levels: a one-rung ladder falls back to the default', count(Levels::order()) === 8);
rules_reset();

// A member with no mentees is not eligible, and is reported as such honestly.
ck('levels: everyone starts at O', Levels::of(1) === 'O');
ck('levels: no mentees means not eligible', Levels::eligibleForNext(1) === false);
$rec = Levels::recommendation(1);
ck('levels: an empty record reads as insufficient data', $rec['verdict'] === 'insufficient_data');
ck('levels: a recommendation never carries a score', !array_key_exists('score', $rec));
ck('levels: a recommendation carries its evidence', is_array($rec['evidence']) && $rec['evidence'] !== []);

// Referrals alone must NOT advance anyone — the anti-pattern the design rejects.
Levels::recordReferral(1, 2);
Levels::recordReferral(1, 3);
ck('levels: referrals are still recorded', Levels::referralCount(1) === 2);
ck('levels: two referrals do NOT earn Level A', Levels::eligibleForNext(1) === false);

// Only genuinely active mentees count. Two pairings that have never met do not.
Mentorship::ensure();
$db = Database::pdo();
// Earlier test files leave pairings behind, so clear the table and read back the
// ids we are given rather than assuming they start at 1.
$db->exec('DELETE FROM mentorships');
$db->exec('DELETE FROM mentor_sessions');
$db->exec("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (1,2,'active','2026-01-01','2026-01-01'),(1,3,'active','2026-01-01','2026-01-01')");
$pairIds = array_map('intval', $db->query('SELECT id FROM mentorships WHERE mentor_id = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
ck('levels: two pairings were created', count($pairIds) === 2);
ck('levels: pairings with no sessions are not active mentees', count(Mentorship::verifiedActiveMentees(1)) === 0);
ck('levels: paper pairings do not earn Level A', Levels::eligibleForNext(1) === false);

// Give each pairing the sessions the rules require, and the picture changes.
$recent = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
$ins = $db->prepare("INSERT INTO mentor_sessions (mentorship_id, scheduled_at, attendance, duration_min, created_at) VALUES (?,?,'attended',45,?)");
foreach ($pairIds as $mid) { $ins->execute([$mid, $recent, $recent]); $ins->execute([$mid, $recent, $recent]); }
ck('levels: sessions make mentees genuinely active', count(Mentorship::verifiedActiveMentees(1)) === 2);
$m = Mentorship::multiplication(1);
ck('levels: multiplication counts active mentees', $m['active'] === 2);
ck('levels: nobody is multiplying yet', $m['multiplying'] === 0);

// Tenure is unrecorded here, and an unrecorded date must not block a member.
ck('levels: two active mentees earn Level A', Levels::eligibleForNext(1) === true);
$rec2 = Levels::recommendation(1);
ck('levels: the verdict is now advance', $rec2['verdict'] === 'advance');
ck('levels: an advance recommendation lists no gaps', $rec2['gaps'] === []);

// Raising the bar must immediately un-qualify them — the rule is live.
AvRules::set('levels.mentees_for_a', '3');
ck('levels: raising the bar removes eligibility', Levels::eligibleForNext(1) === false);
AvRules::set('levels.active_min_sessions', '5');
ck('levels: a stricter activity bar drops the count', count(Mentorship::verifiedActiveMentees(1)) === 0);
rules_reset();

// Sessions outside the window do not count.
$db->exec('DELETE FROM mentor_sessions');
$old = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
foreach ($pairIds as $mid) { $ins->execute([$mid, $old, $old]); $ins->execute([$mid, $old, $old]); }
ck('levels: sessions outside the window do not count', count(Mentorship::verifiedActiveMentees(1)) === 0);

// Setting a level records who did it and when, so tenure can be measured.
ck('levels: set accepts a configured rung', Levels::set(1, 'A', 'test-admin') === true);
ck('levels: the new level reads back', Levels::of(1) === 'A');
ck('levels: setting a level stamps the date', Levels::levelSince(1) !== '');
ck('levels: reject a rung not on the ladder', Levels::set(1, 'Z') === false);

// progress() drives the portal card — it must report mentoring, not referrals.
$prog = Levels::progress(1);
ck('levels: progress reports active mentees', array_key_exists('active_mentees', $prog));
ck('levels: progress carries the live ladder', $prog['order'] === Levels::order());
ck('levels: progress labels the current level', $prog['label'] !== '');

rules_reset();
reset_users();
