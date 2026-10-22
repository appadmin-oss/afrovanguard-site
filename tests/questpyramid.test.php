<?php
/**
 * tests/questpyramid.test.php — the Pyramid Manual on the ladder that exists.
 *
 * The promises: Levels recommends on the manual's floors (Quest points and
 * direct mentees) while levels.quest_pyramid is on, and never promotes; an
 * open integrity finding holds a recommendation whatever the points; a
 * demotion is only ever suggested, and a person moves the member down one
 * level; the mentor of a member who rises earns "mentee rises" once per
 * level; inactivity is found in grouped queries and suggested once a month.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 8200–8299.
 */
declare(strict_types=1);

$db = Database::pdo();
Levels::ensure(); Mentorship::ensure(); QuestPoints::ensure(); QuestRecord::ensure(); QuestLadder::ensure(); Conduct::ensure(); GateAttendance::ensure();
$clean = static function () use ($db): void {
    $db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8200 AND 8299');
    $db->exec('DELETE FROM quest_findings WHERE member_id BETWEEN 8200 AND 8299');
    $db->exec('DELETE FROM quest_suggestions WHERE member_id BETWEEN 8200 AND 8299');
    $db->exec('DELETE FROM mentor_sessions WHERE mentorship_id IN (SELECT id FROM mentorships WHERE mentor_id BETWEEN 8200 AND 8299)');
    $db->exec('DELETE FROM mentorships WHERE mentor_id BETWEEN 8200 AND 8299');
    $db->exec('DELETE FROM gate_attendance WHERE member_id BETWEEN 8200 AND 8299');
    $db->exec('DELETE FROM compliance_members WHERE user_id BETWEEN 8200 AND 8299');
};
$clean();
AvRules::resetAll('test');
/* Isolate the manual's floors from the older criteria, which rules.test pins. */
AvRules::save(['levels.min_days_at_level' => '0', 'levels.min_attendance_pct' => '0', 'levels.min_commitment_pct' => '0',
               'levels.require_multiplication' => '0', 'quest.promotion_monthly_points' => '0'], 'test');

reset_users();
$now = gmdate('Y-m-d H:i:s');
foreach ([8201 => 'Pyramid Leader', 8202 => 'Pyramid Mentee', 8203 => 'Pyramid Committee', 8204 => 'Pyramid Quiet', 8205 => 'Second Mentee'] as $id => $n) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $n, 'p' . $id . '@x.test', '', 'member', $now]);
}
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))->execute([8203, 'test', $now]);
$committee = ['id' => 8203, 'email' => 'p8203@x.test'];
$pair = static function (int $mentor, int $mentee) use ($db, $now): int {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, kind, created_at, updated_at) VALUES (?,?,'active','vquest',?,?)")
       ->execute([$mentor, $mentee, $now, $now]);
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, created_at) VALUES (?,'Check-in',?,'attended',?)")
       ->execute([$id, $now, $now]);
    return $id;
};

/* ── recommending on the manual's floors ── */

$r = Levels::recommend(8201);
ck('pyramid: with no points and no mentee, nobody is recommended', !$r['recommend'] && $r['next'] === 'A'
   && (bool) array_filter($r['gaps'], static fn($g) => str_contains($g, 'Quest points')));
QuestPoints::grant(8201, 60, 'test:a', 'test');
$r = Levels::recommend(8201);
ck('pyramid: points alone are not enough — Active needs a mentee actually met', !$r['recommend']
   && (bool) array_filter($r['gaps'], static fn($g) => str_contains($g, 'active mentee')));
$pair(8201, 8202);
$r = Levels::recommend(8201);
ck('pyramid: 60 points and one mentee met recommends Active', $r['recommend']
   && (bool) array_filter($r['reasons'], static fn($x) => str_contains($x, '60 Quest points')));
ck('pyramid: the recommendation names what no record can show, for the reviewer', count($r['metrics']['for_review']) >= 3);
ck('pyramid: recommending promotes nobody', Levels::promoteIfEligible(8201)['promoted'] === false && Levels::of(8201) === 'O');

AvRules::save(['quest.promotion_monthly_points' => '150'], 'test');
$r = Levels::recommend(8201);
ck('pyramid: the Scoring Board\'s 150 a month for 3 months is checked, month by month', !$r['recommend']
   && count($r['metrics']['monthly_points']) === 3 && (bool) array_filter($r['gaps'], static fn($g) => str_contains($g, '150 points')));
AvRules::save(['quest.promotion_monthly_points' => '0'], 'test');

$f = QuestRecord::raiseFinding(8201, 'dishonesty', 'Claimed a session that the mentee says never happened, twice in September.', $committee);
ck('pyramid: an open integrity finding holds the recommendation, whatever the points', !Levels::recommend(8201)['recommend']);
QuestRecord::resolveFinding((int) $f['id'], 'Talked through; the record corrected.', $committee);
ck('pyramid: resolved, it stops standing', Levels::recommend(8201)['recommend']);

Levels::set(8201, 'B', 'test');
QuestPoints::grant(8201, 400, 'test:b', 'test');
$r = Levels::recommend(8201);
ck('pyramid: from Becoming upward the mentee floor binds — 460 points and one mentee is not Committed', !$r['recommend']
   && $r['next'] === 'C' && (bool) array_filter($r['gaps'], static fn($g) => str_contains($g, 'Points alone do not promote')));

AvRules::save(['levels.quest_pyramid' => '0'], 'test');
ck('pyramid: switched off, the manual\'s floors are not asked', !isset(Levels::recommend(8201)['metrics']['quest_points']));
AvRules::reset('levels.quest_pyramid');

ck('pyramid: the manual\'s titles, and the older ones when switched off', Levels::labelOf('A') === 'Active Member' && Levels::labelOf('G') === 'Grandfather'
   && (AvRules::save(['levels.quest_titles' => '0'], 'test')['ok'] && Levels::labelOf('A') === 'Level A — Growing Leader'));
AvRules::reset('levels.quest_titles');

/* ── the mentor's reward when a mentee rises ── */

$db->exec('DELETE FROM quest_points WHERE member_id BETWEEN 8200 AND 8299');
Levels::set(8201, 'O', 'test');
Levels::set(8202, 'O', 'test');
Levels::set(8202, 'A', 'committee');
ck('pyramid: a mentee\'s promotion earns their Quest mentor the catalogue\'s 50',
   QuestPoints::total(8201) === 50 && QuestPoints::granted(8201, 'mentee-rank:8202:A'));
Levels::set(8202, 'O', 'committee');
Levels::set(8202, 'A', 'committee');
ck('pyramid: once per mentee per level, however often it is set', QuestPoints::total(8201) === 50);
ck('pyramid: a move down earns nothing', QuestLadder::onLevelChanged(8202, 'A', 'O') === 0);
AvRules::save(['quest.mentor_reward_on_rise' => '0'], 'test');
ck('pyramid: switched off, no reward', QuestLadder::onLevelChanged(8202, 'A', 'B') === 0);
AvRules::reset('quest.mentor_reward_on_rise');

/* ── demotions are suggested, and a person decides ── */

$id = QuestLadder::suggest(8202, 'demotion', 'No activity for 30 days.', 'test:1');
ck('pyramid: a demotion is raised as a suggestion, and the level does not move', $id > 0 && Levels::of(8202) === 'A');
ck('pyramid: the same source raises it once', QuestLadder::suggest(8202, 'demotion', 'again', 'test:1') === 0);
ck('pyramid: the member it is about cannot decide it', !QuestLadder::accept($id, ['id' => 8202, 'email' => 'p8202@x.test'])['ok']);
ck('pyramid: nobody off the committee can decide it', !QuestLadder::accept($id, ['id' => 8204, 'email' => 'p8204@x.test'])['ok']);
$acc = QuestLadder::accept($id, $committee, 'Agreed.');
ck('pyramid: the committee accepts it and the member moves down one level', $acc['ok'] && $acc['outcome'] === 'level:A>O' && Levels::of(8202) === 'O');
$id2 = QuestLadder::suggest(8202, 'demotion', 'Again.', 'test:2');
ck('pyramid: dismissing needs a reason', !QuestLadder::dismiss($id2, $committee, '')['ok'] && QuestLadder::dismiss($id2, $committee, 'They were ill.')['ok']
   && QuestLadder::find($id2)['status'] === 'dismissed');
AvRules::save(['quest.demotion_suggestions' => '0'], 'test');
ck('pyramid: with demotion suggestions off, none is raised', QuestLadder::suggest(8202, 'demotion', 'x', 'test:3') === 0);
AvRules::reset('quest.demotion_suggestions');

/* ── inactivity, found in grouped queries ── */

Levels::set(8204, 'A', 'test');
Levels::set(8201, 'B', 'test');
$quiet = QuestLadder::inactive();
ck('pyramid: a member above the base with nothing on record is inactive', array_key_exists(8204, $quiet) && $quiet[8204] === '');
ck('pyramid: a member who mentored this week is not', !array_key_exists(8201, $quiet));
$db->prepare("INSERT INTO gate_attendance (member_id, day, status, created_at) VALUES (8204, ?, 'present', ?)")->execute([gmdate('Y-m-d'), $now]);
ck('pyramid: a gate sign-in counts as activity', !array_key_exists(8204, QuestLadder::inactive()));
$db->exec('DELETE FROM gate_attendance WHERE member_id = 8204');
$sw = QuestLadder::sweep(true);
ck('pyramid: the sweep suggests a demotion for the inactive', ($sw['raised'] ?? 0) >= 1
   && (bool) array_filter(QuestLadder::forMember(8204), static fn($s) => $s['kind'] === 'demotion' && $s['status'] === 'open'));
ck('pyramid: and once a month, not every run', (QuestLadder::sweep(true)['raised'] ?? -1) === 0);
ck('pyramid: and once a day unless forced', isset(QuestLadder::sweep()['skipped']));

/* ── a deduction for inactivity suggests, and only suggests ── */

$pid = (int) $db->query('SELECT id FROM mentorships WHERE mentor_id = 8201 AND mentee_id = 8202')->fetchColumn();
Levels::set(8202, 'A', 'test');
$case = Conduct::propose(8201, $pid, ['kind' => 'deduction', 'action' => 'inactive_month', 'occurred_on' => date('Y-m-d'),
    'evidence' => 'Has not come to a single session or sent a report since the start of last month.']);
Conduct::decide($case['id'], $committee, true, '');
ck('pyramid: an approved inactivity deduction suggests a demotion and leaves the level alone', Levels::of(8202) === 'A'
   && (bool) array_filter(QuestLadder::forMember(8202), static fn($s) => $s['source'] === 'deduction:' . $case['id'] && $s['status'] === 'open'));

$db->exec('DELETE FROM conduct_cases WHERE mentee_id BETWEEN 8200 AND 8299');
$clean();
AvRules::resetAll('test');
