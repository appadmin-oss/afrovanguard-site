<?php
/**
 * tests/rules.test.php — the dynamic rules / knowledge / prompt layer.
 *
 * The point of this layer is that Afrovanguard's constitution is DATA, so the
 * things worth pinning are the ones that would silently corrupt policy:
 *
 *   1. Resolution order — Studio override beats config/env beats default. Get
 *      this wrong and a deployment's .htaccess quietly overrules leadership.
 *   2. Validation rejects rather than coerces. A cadence of "-3" must not become
 *      0 days and put every pairing permanently overdue.
 *   3. A partly-invalid batch changes NOTHING. Half-applied policy is worse than
 *      a rejected form.
 *   4. Cross-rule coherence — red ≥ amber means nothing is ever Amber.
 *   5. A stored value that later fails validation falls back instead of poisoning
 *      the engine (bounds can tighten in a release).
 *   6. Prompts interpolate, strip unfilled placeholders, and refuse edits that
 *      drop a required variable.
 *   7. The level ladder follows the rule, and advancement tests ACTIVE mentorship
 *      — attended sessions — not headcount. This is the report's §17 principle,
 *      and the whole reason the engine was rewritten.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();

/* ════════════════════════════════════════════════════════════════
   AvRules — resolution, validation, coherence
   ════════════════════════════════════════════════════════════════ */

AvRules::ensure();
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();

ck('rules: a declared default resolves', AvRules::int('mentorship.cadence_days') === 7);
ck('rules: unknown key yields null, not a crash', AvRules::get('nope.not.a.rule') === null);

// ── Precedence: env/config under an override ──
putenv('AV_MENTORSHIP_CADENCE_DAYS=14');
AvRules::invalidate();
ck('rules: config/env beats the default', AvRules::int('mentorship.cadence_days') === 14);

$r = AvRules::save(['mentorship.cadence_days' => '10'], 'tester');
ck('rules: save accepts a valid value', $r['ok'] === true && $r['saved'] === 1);
ck('rules: the Studio override beats config/env', AvRules::int('mentorship.cadence_days') === 10);

$d = AvRules::describe();
$cadence = null;
foreach ($d['groups']['Mentorship'] as $row) { if ($row['key'] === 'mentorship.cadence_days') $cadence = $row; }
ck('rules: describe() reports the override provenance', $cadence && $cadence['source'] === 'studio');
ck('rules: describe() carries the actor', $cadence && $cadence['updated_by'] === 'tester');

AvRules::reset('mentorship.cadence_days');
ck('rules: reset falls back to config/env', AvRules::int('mentorship.cadence_days') === 14);
putenv('AV_MENTORSHIP_CADENCE_DAYS');
AvRules::invalidate();
ck('rules: with env gone it falls back to the default', AvRules::int('mentorship.cadence_days') === 7);

// ── Validation rejects; it does not coerce ──
ck('rules: below-minimum int is rejected', AvRules::cast('mentorship.cadence_days', '0') === null);
ck('rules: negative int is rejected', AvRules::cast('mentorship.cadence_days', '-3') === null);
ck('rules: above-maximum int is rejected', AvRules::cast('mentorship.cadence_days', '9999') === null);
ck('rules: non-numeric int is rejected', AvRules::cast('mentorship.cadence_days', 'soon') === null);
ck('rules: in-range int casts', AvRules::cast('mentorship.cadence_days', '14') === 14);
ck('rules: bool accepts "on"', AvRules::cast('escalation.notify_chain', 'on') === true);
ck('rules: bool accepts "no"', AvRules::cast('escalation.notify_chain', 'no') === false);
ck('rules: bool rejects nonsense', AvRules::cast('escalation.notify_chain', 'maybe') === null);
ck('rules: enum accepts a listed option', AvRules::cast('escalation.tone', 'firm') === 'firm');
ck('rules: enum rejects an unlisted option', AvRules::cast('escalation.tone', 'sarcastic') === null);
ck('rules: csv normalises spacing', AvRules::cast('levels.ladder', ' O , A ,B ') === 'O,A,B');
ck('rules: csv rejects empty', AvRules::cast('levels.ladder', ' , , ') === null);

// A rejected value must not be silently read as false/0 by a caller.
$before = AvRules::int('mentorship.cadence_days');
$bad = AvRules::save(['mentorship.cadence_days' => '-5'], 'tester');
ck('rules: invalid save is refused', $bad['ok'] === false && isset($bad['errors']['mentorship.cadence_days']));
ck('rules: refused save changed nothing', AvRules::int('mentorship.cadence_days') === $before);

// ── A partly-invalid batch is atomic ──
$mixed = AvRules::save(['escalation.steps' => '5', 'escalation.tone' => 'rude'], 'tester');
ck('rules: mixed batch is refused', $mixed['ok'] === false);
ck('rules: mixed batch wrote NEITHER value', AvRules::int('escalation.steps') === 3 && AvRules::str('escalation.tone') === 'supportive');

// ── Cross-rule coherence ──
$clash = AvRules::save(['health.red_attendance_pct' => '80'], 'tester');   // amber is 70
ck('rules: red >= amber is caught as a conflict', $clash['ok'] === false && count($clash['conflicts']) > 0);
ck('rules: the conflicting value was not stored', AvRules::int('health.red_attendance_pct') === 40);

$win = AvRules::save(['mentorship.cadence_days' => '30', 'mentorship.active_mentee_requires_days' => '7'], 'tester');
ck('rules: an active-window shorter than the cadence is caught', $win['ok'] === false && count($win['conflicts']) > 0);

$ladderBad = AvRules::save(['levels.ladder' => 'O,A,A,B'], 'tester');
ck('rules: a duplicated ladder entry is caught', $ladderBad['ok'] === false && count($ladderBad['conflicts']) > 0);
$ladderShort = AvRules::save(['levels.ladder' => 'O'], 'tester');
ck('rules: a one-level ladder is caught', $ladderShort['ok'] === false && count($ladderShort['conflicts']) > 0);

// ── A stored value that no longer validates must not poison the engine ──
$db->exec('DELETE FROM av_rules');
$db->prepare('INSERT INTO av_rules (rule_key, value, updated_by, updated_at) VALUES (?,?,?,?)')
   ->execute(['mentorship.cadence_days', '99999', 'legacy', gmdate('c')]);
AvRules::invalidate();
ck('rules: an out-of-bounds stored value falls back to the default', AvRules::int('mentorship.cadence_days') === 7);

// ── Undo restores the previous value, and un-pins what had no override ──
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();
AvRules::save(['escalation.steps' => '6'], 'tester');
ck('rules: value set before undo', AvRules::int('escalation.steps') === 6);
ck('rules: applyUndo with a null previous clears the override', AvRules::applyUndo('save', ['values' => ['escalation.steps' => null]]) === true);
ck('rules: after undo the rule is back to its default', AvRules::int('escalation.steps') === 3);
ck('rules: applyUndo rejects an unknown op', AvRules::applyUndo('destroy', []) === false);

// ── The prompt block reflects live rules, and states the AI's limits ──
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();
$block = AvRules::asPromptBlock();
ck('rules: prompt block names the cadence', strpos($block, 'every 7 day(s)') !== false);
ck('rules: prompt block forbids deciding promotions by default', stripos($block, 'never decide') !== false);
ck('rules: prompt block forbids character scores by default', stripos($block, 'character to a score') !== false);
AvRules::save(['mentorship.cadence_days' => '14'], 'tester');
ck('rules: prompt block follows a changed rule', strpos(AvRules::asPromptBlock(), 'every 14 day(s)') !== false);
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();

/* ════════════════════════════════════════════════════════════════
   AvKnowledge
   ════════════════════════════════════════════════════════════════ */

AvKnowledge::ensure();
$db->exec('DELETE FROM av_knowledge');
AvKnowledge::invalidate();

$k = AvKnowledge::save(0, ['title' => 'Servant leadership', 'body' => 'Arrive early and serve before attending.', 'scope' => 'all', 'priority' => 90, 'active' => true], 'tester');
ck('kb: an entry saves', $k['ok'] === true && $k['id'] > 0);
ck('kb: a titleless entry is refused', AvKnowledge::save(0, ['title' => '', 'body' => 'x'])['ok'] === false);
ck('kb: an empty body is refused', AvKnowledge::save(0, ['title' => 'x', 'body' => '  '])['ok'] === false);
ck('kb: an over-long body is refused', AvKnowledge::save(0, ['title' => 'x', 'body' => str_repeat('a', 4001)])['ok'] === false);

$mentorEntry = AvKnowledge::save(0, ['title' => 'First session', 'body' => 'Cover goals and expectations.', 'scope' => 'mentorship', 'priority' => 80, 'active' => true], 'tester');
$offEntry = AvKnowledge::save(0, ['title' => 'Retired guidance', 'body' => 'Do not use this any more.', 'scope' => 'all', 'priority' => 99, 'active' => false], 'tester');
AvKnowledge::invalidate();

$mBlock = AvKnowledge::asPromptBlock('mentorship');
ck('kb: scoped entry reaches its own scope', strpos($mBlock, 'First session') !== false);
ck('kb: an "all" entry reaches every scope', strpos($mBlock, 'Servant leadership') !== false);
ck('kb: an inactive entry is never fed', strpos($mBlock, 'Retired guidance') === false);

$meetBlock = AvKnowledge::asPromptBlock('meetings');
ck('kb: a scoped entry does NOT leak into another scope', strpos($meetBlock, 'First session') === false);
ck('kb: unknown scope degrades to "all" rather than erroring', strpos(AvKnowledge::asPromptBlock('bogus'), 'Servant leadership') !== false);

// Deleting and restoring through the audit undo primitive.
$prev = AvKnowledge::get((int) $mentorEntry['id']);
ck('kb: remove works', AvKnowledge::remove((int) $mentorEntry['id']) === true);
AvKnowledge::invalidate();
ck('kb: the removed entry is gone from the block', strpos(AvKnowledge::asPromptBlock('mentorship'), 'First session') === false);
ck('kb: applyUndo restores it', AvKnowledge::applyUndo('restore', ['fields' => $prev]) === true);
AvKnowledge::invalidate();
ck('kb: the restored entry is fed again', strpos(AvKnowledge::asPromptBlock('mentorship'), 'First session') !== false);

$db->exec('DELETE FROM av_knowledge');
AvKnowledge::invalidate();

/* ════════════════════════════════════════════════════════════════
   AvPrompts
   ════════════════════════════════════════════════════════════════ */

AvPrompts::ensure();
$db->exec('DELETE FROM av_prompts');

ck('prompts: a known key has a default', AvPrompts::template('meeting.minutes') !== '');
ck('prompts: an unknown key renders empty', AvPrompts::render('nope.nope') === '');

$rendered = AvPrompts::render('goal.tasks', ['max' => '5', 'today' => '2026-08-17']);
ck('prompts: variables interpolate', strpos($rendered, 'BETWEEN 3 AND 5') !== false && strpos($rendered, '2026-08-17') !== false);
ck('prompts: no placeholder survives rendering', strpos($rendered, '{{') === false);
ck('prompts: the rules block is appended', strpos($rendered, 'AFROVANGUARD RULES') !== false);

// An unsupplied variable is stripped, not left for the model to invent.
$partial = AvPrompts::render('meeting.agenda', ['title' => 'Board sync']);
ck('prompts: an unfilled placeholder is stripped', strpos($partial, '{{previous_minutes}}') === false);
ck('prompts: supplied vars still land', strpos($partial, 'Board sync') !== false);

// Overrides.
$ov = AvPrompts::save('meeting.minutes', 'Custom minutes instructions, strict JSON only.', 'tester');
ck('prompts: an override saves', $ov['ok'] === true && $ov['cleared'] === false);
ck('prompts: the override is used', strpos(AvPrompts::render('meeting.minutes'), 'Custom minutes instructions') !== false);

$desc = array_values(array_filter(AvPrompts::describe(), fn($p) => $p['key'] === 'meeting.minutes'))[0];
ck('prompts: describe() reports the override', $desc['source'] === 'studio' && $desc['updated_by'] === 'tester');

// Saving the default back clears the override rather than duplicating it.
$same = AvPrompts::save('meeting.minutes', AvPrompts::defaultText('meeting.minutes'), 'tester');
ck('prompts: saving the default clears the override', $same['ok'] === true && $same['cleared'] === true);
$desc2 = array_values(array_filter(AvPrompts::describe(), fn($p) => $p['key'] === 'meeting.minutes'))[0];
ck('prompts: source is default again', $desc2['source'] === 'default');

// A template that drops a required variable is refused — it would send the model
// a prompt with no input.
$dropped = AvPrompts::save('goal.tasks', 'Just make up some tasks please.', 'tester');
ck('prompts: dropping a required variable is refused', $dropped['ok'] === false && strpos($dropped['error'], 'today') !== false);
ck('prompts: an unknown key cannot be saved', AvPrompts::save('nope.nope', 'x')['ok'] === false);
ck('prompts: an over-long template is refused', AvPrompts::save('meeting.minutes', str_repeat('a', 8001))['ok'] === false);

$db->exec('DELETE FROM av_prompts');

/* ════════════════════════════════════════════════════════════════
   Levels — the ladder follows the rule; advancement tests real mentorship
   ════════════════════════════════════════════════════════════════ */

Levels::ensure();
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();

ck('levels: the default ladder is O..G', Levels::order() === ['O', 'A', 'B', 'C', 'D', 'E', 'F', 'G']);
ck('levels: base is the first rung', Levels::base() === 'O');
ck('levels: D–G have real labels, not codes', Levels::labelOf('G') !== 'Level G' && strpos(Levels::labelOf('G'), 'Grand') !== false);
ck('levels: a code outside the label table still renders', Levels::labelOf('Z') === 'Level Z');
ck('levels: nextOf walks the ladder', Levels::nextOf('A') === 'B' && Levels::nextOf('G') === null);

AvRules::save(['levels.ladder' => 'O,A,B'], 'tester');
ck('levels: the ladder follows the rule', Levels::order() === ['O', 'A', 'B']);
ck('levels: nextOf respects the shortened ladder', Levels::nextOf('B') === null);
ck('levels: set() refuses a level off the ladder', Levels::set(1, 'G') === false);
ck('levels: set() accepts one on the ladder', Levels::set(1, 'A', 'tester') === true);
ck('levels: the new level reads back', Levels::of(1) === 'A');
AvRules::reset('levels.ladder');
AvRules::invalidate();
ck('levels: a level off the shortened ladder is still readable, not demoted', Levels::of(1) === 'A');

// ── Advancement is NOT bought with referrals (the §17 principle) ──
Levels::set(1, 'O', 'tester');
$db->exec('DELETE FROM member_referrals');
Levels::recordReferral(1, 2);
Levels::recordReferral(1, 3);
ck('levels: referrals are still recorded', Levels::referralCount(1) === 2);
$rec = Levels::recommend(1);
ck('levels: two referrals alone do NOT earn advancement', $rec['recommend'] === false);
ck('levels: the gap explains that active mentees are what count', count($rec['gaps']) > 0 && stripos(implode(' ', $rec['gaps']), 'active mentee') !== false);

// ── Real, attended mentorship does count ──
Mentorship::ensure();
$db->exec('DELETE FROM mentorships');
$db->exec('DELETE FROM mentor_sessions');
$now = gmdate('Y-m-d H:i:s', time() - 86400);
foreach ([2, 3] as $mentee) {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
       ->execute([1, $mentee, $now, $now]);
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Check-in',?,'attended',?)")
       ->execute([$pid, $now, $now]);
}
ck('levels: attended sessions make mentees active', Mentorship::activeMenteeCount(1) === 2);

// A pairing that exists but has never met must not count.
$db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
   ->execute([2, 3, $now, $now]);
ck('levels: a pairing with no attended session is NOT active', Mentorship::activeMenteeCount(2) === 0);

// A merely-scheduled session is not evidence of mentoring either.
$db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
   ->execute([3, 1, $now, $now]);
$pid = (int) $db->lastInsertId();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Booked',?,'scheduled',?)")
   ->execute([$pid, $now, $now]);
ck('levels: a scheduled-but-unattended session does not count', Mentorship::activeMenteeCount(3) === 0);

$mult = Mentorship::multiplicationSummary(1);
ck('levels: summary counts direct active mentees', $mult['active'] === 2);
ck('levels: summary sees no second generation yet', $mult['multiplying'] === 0);

// ── A diamond is not depth ──
// Mentee 2 starts mentoring user 3 — but 3 is ALREADY a direct mentee of 1. So
// nobody new sits below 1, and depth must stay at 1. Counting the diamond as a
// second generation would inflate a leader's apparent reach whenever one of
// their mentees also mentors a peer, which is the headcount inflation §17 warns
// against. Shortest-path depth is the conservative, honest reading.
$p2 = (int) $db->query("SELECT id FROM mentorships WHERE mentor_id = 2 AND mentee_id = 3")->fetchColumn();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Check-in',?,'attended',?)")
   ->execute([$p2, $now, $now]);
ck('levels: mentee 2 now has an active mentee', Mentorship::activeMenteeCount(2) === 1);
$dia = Mentorship::multiplicationSummary(1);
ck('levels: a mentee who mentors registers as multiplying', $dia['multiplying'] === 1);
ck('levels: a diamond does NOT inflate depth', $dia['depth'] === 1);
ck('levels: a diamond does NOT inflate descendants', $dia['descendants'] === 2);

// ── A real chain does grow depth ──
// Mentee 2 mentors user 4, who is nobody else's mentee: a true second generation.
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (4,'Dayo','d@x.co','x'),(5,'Ejiro','e@x.co','x')");
$db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
   ->execute([2, 4, $now, $now]);
$p4 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Check-in',?,'attended',?)")
   ->execute([$p4, $now, $now]);
$m2 = Mentorship::multiplicationSummary(1);
ck('levels: a genuine second generation grows depth', $m2['depth'] === 2);
ck('levels: descendants count the whole active network', $m2['descendants'] === 3);
ck('levels: direct active mentees are unchanged by depth', $m2['active'] === 2);

// A third generation: user 4 mentors user 5.
$db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
   ->execute([4, 5, $now, $now]);
$p5 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Check-in',?,'attended',?)")
   ->execute([$p5, $now, $now]);
$m3 = Mentorship::multiplicationSummary(1);
ck('levels: a third generation grows depth again', $m3['depth'] === 3);
ck('levels: descendants grow with it', $m3['descendants'] === 4);
ck('levels: multiplicationDepth agrees with the summary', Mentorship::multiplicationDepth(1) === $m3['depth']);

// ── The active window is a rule, and it is enforced ──
// Age the whole network past the window: nothing counts as active any more.
$old = gmdate('Y-m-d H:i:s', time() - 120 * 86400);
$db->prepare('UPDATE mentor_sessions SET scheduled_at = ?')->execute([$old]);
$narrow = AvRules::save(['mentorship.active_mentee_requires_days' => '30'], 'tester');
ck('levels: narrowing the window was accepted', $narrow['ok'] === true);
ck('levels: sessions older than the window stop counting', Mentorship::activeMenteeCount(1) === 0);
$wide = AvRules::save(['mentorship.active_mentee_requires_days' => '180'], 'tester');
ck('levels: widening the window was accepted', $wide['ok'] === true);
ck('levels: widening the window brings them back', Mentorship::activeMenteeCount(1) === 2);
$db->prepare('UPDATE mentor_sessions SET scheduled_at = ?')->execute([$now]);
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();

// A cycle in the data must terminate rather than recurse forever.
$db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
   ->execute([3, 1, $now, $now]);
$cyc = (int) $db->lastInsertId();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Cycle',?,'attended',?)")
   ->execute([$cyc, $now, $now]);
$depth = Mentorship::multiplicationDepth(1);
ck('levels: a cycle terminates instead of hanging', $depth > 0 && $depth <= 12);

/* ── Promotion recommends; it does not decide ── */
AvRules::save(['levels.min_days_at_level' => '0', 'levels.min_attendance_pct' => '0'], 'tester');
$rec2 = Levels::recommend(1);
ck('levels: with real active mentees the criteria are met', $rec2['recommend'] === true);
ck('levels: the recommendation carries its evidence', count($rec2['reasons']) > 0);
ck('levels: metrics are reported alongside', ($rec2['metrics']['active_mentees'] ?? 0) === 2);

$act = Levels::promoteIfEligible(1, 'tester');
ck('levels: auto_promote is OFF by default — nobody is promoted', $act['promoted'] === false && $act['recommended'] === true);
ck('levels: the level is unchanged after a recommendation', Levels::of(1) === 'O');

AvRules::save(['levels.auto_promote' => '1'], 'tester');
$act2 = Levels::promoteIfEligible(1, 'tester');
ck('levels: auto_promote ON does promote', $act2['promoted'] === true);
ck('levels: the promotion landed', Levels::of(1) === 'A');
ck('levels: tenure is stamped on promotion', Levels::daysAtLevel(1) !== null);

// A member with nothing must not be recommended.
AvRules::save(['levels.auto_promote' => '0'], 'tester');
Levels::set(2, 'O', 'tester');
$db->exec('DELETE FROM mentorships WHERE mentor_id = 2');
$rec3 = Levels::recommend(2);
ck('levels: a member with no active mentees is not recommended', $rec3['recommend'] === false);

// progress() must describe advancement in terms of mentees, not referrals.
$p = Levels::progress(2);
ck('levels: progress() exposes active mentees', array_key_exists('active_mentees', $p));
ck('levels: progress() still exposes order for the portal', is_array($p['order']) && count($p['order']) >= 2);
ck('levels: progress() keeps referrals_needed for template compatibility', array_key_exists('referrals_needed', $p));

$db->exec('DELETE FROM av_rules');
AvRules::invalidate();

/* ════════════════════════════════════════════════════════════════
   The AI master switch
   ════════════════════════════════════════════════════════════════ */

AvRules::save(['ai.enabled' => '0'], 'tester');
ck('ai switch: off makes Collab report AI unavailable', Collab::aiAvailable() === false);
$mres = Meetings::structure('Some transcript text.');
ck('ai switch: off refuses to structure minutes', $mres['ok'] === false && stripos($mres['error'], 'switched off') !== false);
$db->exec('DELETE FROM av_rules');
AvRules::invalidate();
