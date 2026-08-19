<?php
/**
 * tests/commitments.test.php — G-1: promises tracked until they are kept.
 *
 * Before this, `Meetings::structure()` extracted action items and stored them as
 * JSON that nothing read again — the system recorded that somebody promised
 * something and then forgot. These assertions pin the four decisions that make the
 * replacement worth having, because each one is a thing a later "simplification"
 * would quietly remove:
 *
 *   1. Extraction never assigns. A name heard in a transcript is a suggestion.
 *   2. Re-extraction is idempotent, and never overwrites a human's decision.
 *   3. A miss reason is confidential — it is the one field that will contain
 *      disclosures about illness, money and family.
 *   4. Honesty is not punished: a self-reported miss is counted apart from the rate.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();
Commitments::ensure();
$db->exec('DELETE FROM commitments');
AvRules::resetAll('test');

/** The shape Meetings/Mentorship hand over. */
$items = [
    ['task' => 'Draft the Alimosho outreach plan', 'owner' => 'Ada',   'due_days' => 3],
    ['task' => 'Confirm the venue booking',        'owner' => 'Nobody Here', 'due_days' => null],
    ['task' => 'Send the budget to the treasurer', 'owner' => '',      'due_days' => 10],
    ['task' => '   ',                              'owner' => 'Ada'],           // junk
];

/* ══ 1. Extraction files what was said, and nothing else ═════════════════ */

$r = Commitments::fileMany(Commitments::SRC_MEETING, 501, $items);
ck('commitments: three real action items are filed', $r['created'] === 3);
ck('commitments: an empty task is skipped, not filed as blank', $r['skipped'] === 1);
$filed = Commitments::forSource(Commitments::SRC_MEETING, 501);
ck('commitments: they are readable by source', count($filed) === 3);
ck('commitments: each starts open', count(array_filter($filed, fn($c) => $c['status'] === 'open')) === 3);

// A dated action item keeps its date; an undated one gets the rule's default.
$byTitle = [];
foreach ($filed as $c) $byTitle[$c['title']] = $c;
ck('commitments: an explicit due_days is honoured',
   $byTitle['Draft the Alimosho outreach plan']['due'] === gmdate('Y-m-d', time() + 3 * 86400));
ck('commitments: an undated item takes commitments.default_due_days',
   $byTitle['Confirm the venue booking']['due'] === gmdate('Y-m-d', time() + Commitments::defaultDueDays() * 86400));

/* ══ 2. Extraction never assigns ═════════════════════════════════════════ */

ck('commitments: auto_assign_owner ships off', Commitments::autoAssignOwner() === false);
ck('commitments: nothing arrives owned', count(array_filter($filed, fn($c) => (int) $c['member_id'] !== 0)) === 0);
ck('commitments: the name the model heard is kept as a hint',
   $byTitle['Draft the Alimosho outreach plan']['owner_hint'] === 'Ada');

// The queue a chair works through carries a suggestion, so confirming is a
// decision about a name on screen rather than a search.
$queue = Commitments::unassigned();
ck('commitments: all three are in the unassigned queue', count($queue) === 3);
$sugg = [];
foreach ($queue as $q) $sugg[$q['title']] = (int) $q['suggested_member_id'];
ck('commitments: a resolvable name is suggested', $sugg['Draft the Alimosho outreach plan'] === 1);
ck('commitments: an unknown name suggests nobody', $sugg['Confirm the venue booking'] === 0);
ck('commitments: no name suggests nobody', $sugg['Send the budget to the treasurer'] === 0);

// With the rule ON it does assign — but still only when the name is unambiguous.
AvRules::save(['commitments.auto_assign_owner' => '1'], 'test');
ck('commitments: the rule reads back on', Commitments::autoAssignOwner() === true);
Commitments::fileMany(Commitments::SRC_MEETING, 502, [['task' => 'Book the hall', 'owner' => 'Ada', 'due_days' => 2]]);
$auto = Commitments::forSource(Commitments::SRC_MEETING, 502);
ck('commitments: with the rule on, an unambiguous name is assigned', (int) ($auto[0]['member_id'] ?? 0) === 1);

// Two members with the same name must still assign to neither.
$db->prepare('INSERT INTO lms_users (name,email,password_hash) VALUES (?,?,?)')->execute(['Ada', 'ada2@x.co', 'x']);
Commitments::fileMany(Commitments::SRC_MEETING, 503, [['task' => 'Print the flyers', 'owner' => 'Ada', 'due_days' => 2]]);
$amb = Commitments::forSource(Commitments::SRC_MEETING, 503);
ck('commitments: an ambiguous name assigns to nobody even with the rule on',
   (int) ($amb[0]['member_id'] ?? -1) === 0);
ck('commitments: and resolveOwner refuses to pick one', Commitments::resolveOwner('Ada') === 0);
AvRules::resetAll('test');

/* ══ 3. Re-extraction is idempotent and never undoes a human ═════════════ */

$again = Commitments::fileMany(Commitments::SRC_MEETING, 501, $items);
ck('commitments: a re-run creates nothing', $again['created'] === 0);
ck('commitments: a re-run updates in place', $again['updated'] === 3);
ck('commitments: still three rows', count(Commitments::forSource(Commitments::SRC_MEETING, 501)) === 3);

// A title the model rephrases slightly must still match — the dedupe key
// normalises case and punctuation, or every re-structure would duplicate.
ck('commitments: the dedupe key ignores case and punctuation',
   Commitments::dedupeKey('meeting', 1, 'Draft the plan!') === Commitments::dedupeKey('meeting', 1, 'draft   the  plan'));
ck('commitments: but not a different source',
   Commitments::dedupeKey('meeting', 1, 'Draft the plan') !== Commitments::dedupeKey('meeting', 2, 'Draft the plan'));
ck('commitments: nor a different kind',
   Commitments::dedupeKey('meeting', 1, 'x') !== Commitments::dedupeKey('session', 1, 'x'));

// The important half: a human acted, then the minutes were re-structured.
$one = (int) $filed[0]['id'];
Commitments::assign($one, 2, 99);
Commitments::complete($one, 2);
Commitments::fileMany(Commitments::SRC_MEETING, 501, $items);
$after = Commitments::get($one);
ck('commitments: a re-run does not reopen a completed commitment', $after['status'] === 'done');
ck('commitments: nor reassign it', (int) $after['member_id'] === 2);

// And the database refuses a duplicate even if the code forgets to check.
$dup = false;
try {
    $db->prepare('INSERT INTO commitments (source_kind, source_id, dedupe_key, title, created_at, updated_at) VALUES (?,?,?,?,?,?)')
       ->execute(['meeting', 501, (string) $filed[1]['dedupe_key'], 'x', '2026-01-01', '2026-01-01']);
} catch (Throwable $e) { $dup = true; }
ck('commitments: the unique index refuses a duplicate', $dup);

/* ══ 4. Misses: a reason is required, and it is confidential ═════════════ */

$two = (int) $filed[1]['id'];
Commitments::assign($two, 1, 99);
ck('commitments: require_miss_reason is on by default', Commitments::requireMissReason() === true);
$noReason = Commitments::miss($two, '');
ck('commitments: a miss with no reason is refused', empty($noReason['ok']));
ck('commitments: and says why', strpos((string) ($noReason['error'] ?? ''), 'reason') !== false);
ck('commitments: the commitment is still open after a refused miss', Commitments::get($two)['status'] === 'open');

$SECRET = 'Hospital visit for my mother all week.';
$ok = Commitments::miss($two, $SECRET, true);
ck('commitments: a miss with a reason is accepted', !empty($ok['ok']));
$missed = Commitments::get($two);
ck('commitments: the status is missed', $missed['status'] === 'missed');
ck('commitments: the reason is stored for the member', $missed['miss_reason'] === $SECRET);
ck('commitments: and the self-report is recorded', (int) $missed['self_reported'] === 1);

// Confidentiality. This is the field that will hold disclosures about illness,
// money and family, so every path to a non-owner must strip it.
$red = Commitments::redactedFor($missed);
ck('commitments: redactedFor removes the reason', !array_key_exists('miss_reason', $red));
ck('commitments: but says one exists', $red['has_miss_reason'] === true);
ck('commitments: a commitment with no reason reports none',
   Commitments::redactedFor(Commitments::get($one))['has_miss_reason'] === false);

// The AI must never be able to read it — level_check hands Levels::recommend()
// through unprojected, and this file just added fields to that.
$tool = json_encode(AvTools::run('level_check', ['user_id' => 1], ['tiers' => ['read']]));
ck('commitments: no miss reason reaches an AI tool', strpos((string) $tool, 'Hospital visit') === false);
ck('commitments: not even the field name', strpos((string) $tool, 'miss_reason') === false);
$stats = json_encode(AvTools::run('org_stats', [], ['tiers' => ['read']]));
ck('commitments: nor org_stats', strpos((string) $stats, 'Hospital visit') === false);

// With the rule off, a bare miss is allowed — the rule is real, not decoration.
AvRules::save(['commitments.require_miss_reason' => '0'], 'test');
$three = (int) $filed[2]['id'];
Commitments::assign($three, 1, 99);
ck('commitments: with the rule off a bare miss is accepted', !empty(Commitments::miss($three, '')['ok']));
AvRules::resetAll('test');

/* ══ Completion: candour counted apart from the rate ═════════════════════ */

$c1 = Commitments::completion(1);
ck('commitments: settled commitments are counted', $c1['missed'] >= 2);
ck('commitments: a self-reported miss is counted separately', $c1['self_reported_misses'] === 1);
ck('commitments: the rate is a percentage of settled, not of everything',
   $c1['rate'] !== null && $c1['rate'] >= 0 && $c1['rate'] <= 100);
ck('commitments: a member with nothing settled has no rate', Commitments::completion(3)['rate'] === null);

/* ══ Overdue and the chase ══════════════════════════════════════════════ */

$db->exec('DELETE FROM commitments');
Commitments::fileMany(Commitments::SRC_MEETING, 601, [['task' => 'Late thing', 'owner' => '', 'due_days' => 1]]);
$late = (int) Commitments::forSource(Commitments::SRC_MEETING, 601)[0]['id'];
ck('commitments: an unowned commitment is never chased', count(Commitments::overdue()) === 0);
Commitments::assign($late, 1, 99);
$db->prepare('UPDATE commitments SET due = ? WHERE id = ?')->execute([gmdate('Y-m-d', time() - 10 * 86400), $late]);
ck('commitments: an owned, past-due commitment is overdue', count(Commitments::overdue()) === 1);

// The grace period is a real threshold, not a comment.
AvRules::save(['commitments.overdue_grace_days' => '30'], 'test');
ck('commitments: a long grace period suppresses the chase', count(Commitments::overdue()) === 0);
AvRules::resetAll('test');

$sent = Commitments::sweepOverdue();
ck('commitments: the sweep nudges the owner', $sent === 1);
ck('commitments: a second sweep the same day does not nudge again', Commitments::sweepOverdue() === 0);
Commitments::complete($late, 1);
ck('commitments: a completed commitment stops being overdue', count(Commitments::overdue()) === 0);

/* ══ Session commitments name a ROLE, which resolves exactly ════════════ */

Mentorship::ensure();
$db->exec('DELETE FROM mentorships'); $db->exec('DELETE FROM mentor_sessions');
$db->prepare('INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at) VALUES (?,?,?,?,?)')
   ->execute([880, 1, 2, 'active', gmdate('Y-m-d H:i:s')]);
$db->prepare('INSERT INTO mentor_sessions (id, mentorship_id, title, scheduled_at) VALUES (?,?,?,?)')
   ->execute([990, 880, 'Session', gmdate('Y-m-d H:i:s')]);
ck('commitments: a session "mentor" resolves to the pairing\'s mentor',
   Commitments::suggestOwner(Commitments::SRC_SESSION, 990, 'mentor') === 1);
ck('commitments: and "mentee" to the mentee',
   Commitments::suggestOwner(Commitments::SRC_SESSION, 990, 'mentee') === 2);
ck('commitments: a role means nothing for a meeting',
   Commitments::suggestOwner(Commitments::SRC_MEETING, 990, 'mentor') === 0);
// Certain or not, it is still only a suggestion while the rule is off.
Commitments::fileMany(Commitments::SRC_SESSION, 990, [['task' => 'Read chapter 3', 'owner' => 'mentee', 'due_days' => 7]]);
$sc = Commitments::forSource(Commitments::SRC_SESSION, 990);
ck('commitments: a session commitment is filed', count($sc) === 1);
ck('commitments: certainty about the role does not skip confirmation',
   (int) $sc[0]['member_id'] === 0 && $sc[0]['owner_hint'] === 'mentee');

/* ══ The rules are live, and promotion reads them ════════════════════════ */

$desc = AvRules::describe();
$pending = [];
foreach (($desc['groups'] ?? []) as $g => $rules) {
    foreach ($rules as $rr) if (!empty($rr['pending']) && strpos((string) $rr['key'], 'commitments.') === 0) $pending[] = $rr['key'];
}
ck('commitments: no commitments rule is still labelled pending', $pending === []);

$db->exec('DELETE FROM commitments');
$rec = Levels::recommend(1);
ck('levels: the recommendation reports commitment metrics', array_key_exists('commitment_pct', $rec['metrics']));
ck('levels: too few settled commitments is reported as a fact about the data',
   !empty($rec['metrics']['commitment_sample_short']));
// The important half: it must NOT block. `recommend` is "no gaps", so a
// not-enough-data gap would freeze every promotion in the movement on deploy.
ck('levels: and it adds no gap, so it cannot block a promotion',
   count(array_filter($rec['gaps'], fn($g) => stripos($g, 'commitment') !== false)) === 0);

// Above the sample floor it becomes a real criterion.
for ($i = 1; $i <= 6; $i++) {
    Commitments::fileMany(Commitments::SRC_MEETING, 700 + $i, [['task' => 'Task ' . $i, 'owner' => '', 'due_days' => 5]]);
    $cid = (int) Commitments::forSource(Commitments::SRC_MEETING, 700 + $i)[0]['id'];
    Commitments::assign($cid, 1, 99);
    Commitments::complete($cid, 1);
}
$rec2 = Levels::recommend(1);
ck('levels: with enough settled commitments the rate is reported', (int) $rec2['metrics']['commitment_pct'] === 100);
ck('levels: and a passing rate is a stated reason',
   count(array_filter($rec2['reasons'], fn($g) => strpos($g, 'settled commitments') !== false)) === 1);

/* ══ Who may confirm an owner: whoever was in the room ═══════════════════ */

Meetings::ensure();
$db->exec('DELETE FROM commitments');
$db->exec('DELETE FROM meetings WHERE id IN (910, 911)');
$db->exec('DELETE FROM meeting_attendees WHERE meeting_id IN (910, 911)');

// 910: user 1 chaired it, user 2 was invited. 911: user 3 chaired, neither 1 nor 2.
$db->prepare('INSERT INTO meetings (id, title, creator_id, scheduled_at, status, provider) VALUES (?,?,?,?,?,?)')
   ->execute([910, 'Outreach planning', 1, gmdate('Y-m-d H:i:s'), 'done', 'google']);
$db->prepare('INSERT INTO meeting_attendees (meeting_id, email, user_id) VALUES (?,?,?)')
   ->execute([910, 'b@x.co', 2]);
$db->prepare('INSERT INTO meetings (id, title, creator_id, scheduled_at, status, provider) VALUES (?,?,?,?,?,?)')
   ->execute([911, 'Finance review', 3, gmdate('Y-m-d H:i:s'), 'done', 'google']);

Commitments::fileMany(Commitments::SRC_MEETING, 910, [['task' => 'Book the hall', 'owner' => '', 'due_days' => 5]]);
Commitments::fileMany(Commitments::SRC_MEETING, 911, [['task' => 'Reconcile the ledger', 'owner' => '', 'due_days' => 5]]);
$inRoom  = Commitments::forSource(Commitments::SRC_MEETING, 910)[0];
$outRoom = Commitments::forSource(Commitments::SRC_MEETING, 911)[0];

ck('commitments: the chair who ran the meeting may confirm', Commitments::canManage(1, $inRoom));
ck('commitments: an invited attendee may confirm', Commitments::canManage(2, $inRoom));
ck('commitments: somebody who was not there may not', !Commitments::canManage(1, $outRoom));
ck('commitments: and neither may a stranger to both', !Commitments::canManage(2, $outRoom));
ck('commitments: nor may a signed-out caller', !Commitments::canManage(0, $inRoom));
ck('commitments: a stranger to the meeting may not confirm its commitment', !Commitments::canManage(3, $inRoom));

// The queue is scoped, so it never shows a promise from a room you were not in.
$q1 = Commitments::unassignedFor(1);
ck('commitments: the queue shows only rooms the caller was in', count($q1) === 1);
ck('commitments: and it is the right one', (int) $q1[0]['source_id'] === 910);
ck('commitments: the unfiltered queue still sees both (admin console)', count(Commitments::unassigned()) === 2);

// A session's queue is scoped to the pairing.
$db->prepare('INSERT INTO mentorships (id, mentor_id, mentee_id, status, created_at) VALUES (?,?,?,?,?)')
   ->execute([881, 1, 2, 'active', gmdate('Y-m-d H:i:s')]);
$db->prepare('INSERT INTO mentor_sessions (id, mentorship_id, title, scheduled_at) VALUES (?,?,?,?)')
   ->execute([991, 881, 'Session', gmdate('Y-m-d H:i:s')]);
Commitments::fileMany(Commitments::SRC_SESSION, 991, [['task' => 'Read chapter 3', 'owner' => 'mentee', 'due_days' => 7]]);
$sess = Commitments::forSource(Commitments::SRC_SESSION, 991)[0];
ck('commitments: the mentor may confirm a session commitment', Commitments::canManage(1, $sess));
ck('commitments: so may the mentee', Commitments::canManage(2, $sess));
ck('commitments: a third party may not', !Commitments::canManage(3, $sess));

// A queue row must never carry somebody's miss reason, even redaction aside —
// the portal maps every queue row through redactedFor().
$SEC = 'Money was tight this month.';
Commitments::assign((int) $inRoom['id'], 2, 1);
Commitments::miss((int) $inRoom['id'], $SEC, false);
$redQueue = array_map([Commitments::class, 'redactedFor'], Commitments::unassigned());
ck('commitments: no queue row carries a miss reason',
   strpos(json_encode($redQueue), 'Money was tight') === false);

$db->exec('DELETE FROM commitments');
$db->exec('DELETE FROM meetings WHERE id IN (910, 911)');
$db->exec('DELETE FROM meeting_attendees WHERE meeting_id IN (910, 911)');
$db->exec('DELETE FROM mentorships'); $db->exec('DELETE FROM mentor_sessions');
