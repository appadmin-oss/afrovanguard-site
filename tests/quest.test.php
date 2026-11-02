<?php
/**
 * tests/quest.test.php — the vendored registry, and signing off one of the seventy.
 *
 * lib/quest/ is a copy of appadmin-oss/van-quest's registry. A copy drifts,
 * and a drifted copy does not announce itself — it scores somebody wrongly.
 * So the invariants the source validator enforces are enforced here too,
 * against the copy this site actually reads.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

Quest::forget();
Quest::$override = null;

/* ── the vendored copy, held to the same invariants as the source ─────── */

$values = Quest::values();
ck('quest: the vendored registry carries the seven values', count($values) === 7);
ck('quest: with the ids the mentor portal already stores against',
   array_keys($values) === array_keys(MentorPortal::VALUES));

$ch = Quest::challenges();
ck('quest: and the seventy challenges', count($ch) === 70);
ck('quest: ten to a value, every challenge under a value that exists', (function () use ($ch, $values) {
    $n = [];
    foreach ($ch as $id => $c) {
        if (!isset($values[$c['value']])) return false;
        if ($id !== $c['value'] . '.' . $c['index']) return false;
        $n[$c['value']] = ($n[$c['value']] ?? 0) + 1;
    }
    foreach (array_keys($values) as $v) if (($n[$v] ?? 0) !== 10) return false;
    return true;
})());
ck('quest: every challenge is worth one point, so 70/70 means all seventy',
   array_sum(array_column($ch, 'points')) === 70);

$ranks = Quest::ranks();
ck('quest: eight ranks, bands meeting with no gap and no overlap', (function () use ($ranks) {
    if (count($ranks) !== 8) return false;
    for ($i = 0; $i < 7; $i++) {
        if (($ranks[$i]['points']['max'] ?? null) === null) return false;
        if ($ranks[$i + 1]['points']['min'] !== $ranks[$i]['points']['max'] + 1) return false;
        if ($ranks[$i + 1]['mentees']['min'] < $ranks[$i]['mentees']['min']) return false;
    }
    return $ranks[7]['points']['max'] === null;
})());

/* ── the Pyramid Manual's catalogue, deductions and fines (22 Oct 2026) ── */

$aw = Quest::awards();
ck('quest: the catalogue is priced — every action carries the manual\'s points',
   count($aw) >= 16 && !array_filter($aw, static fn($a) => !is_int($a['points']) || $a['points'] < 1));
ck('quest: the catalogue\'s prices are the manual\'s', $aw['punctuality']['points'] === 30
   && $aw['exceeded_target']['points'] === 100 && $aw['mentee_rises']['points'] === 50);
ck('quest: every action sits in a group that exists',
   !array_diff(array_unique(array_column($aw, 'group')), array_keys(Quest::groups('awardable-actions'))));
$de = Quest::deductions();
ck('quest: seven deductions, each a positive number of points to take away',
   count($de) === 7 && !array_filter($de, static fn($d) => (int) $d['points'] < 1));
ck('quest: only inactivity suggests a demotion, and only suggests it',
   array_keys(array_filter($de, static fn($d) => ($d['suggests'] ?? '') === 'demotion')) === ['inactive_month']);
$fi = Quest::fines();
ck('quest: every fine names the NGV ledger reason it is charged under',
   count($fi) >= 31 && !array_filter($fi, static fn($f) => !in_array($f['ledger_reason'] ?? '', ['late', 'absent', 'uniform', 'equipment', 'conduct', 'other'], true)));
ck('quest: a rank carries its earning share, colour and ceiling',
   Quest::rank('G')['earning']['cap_pct'] === 50 && Quest::rank('D')['colour'] === 'black' && Quest::rank('C')['monthly_ceiling'] === 499
   && Quest::rank('F')['monthly_ceiling'] === null);
ck('quest: rankFor is arithmetic on both floors — points alone do not lift a rank',
   Quest::rankFor(0, 0) === 'O' && Quest::rankFor(400, 2) === 'B' && Quest::rankFor(400, 3) === 'C'
   && Quest::rankFor(5000, 0) === 'A' && Quest::rankFor(5000, 1) === 'A' && Quest::rankFor(60, 0) === 'A'
   && Quest::rankFor(2000, 9) === 'G');

$j = Quest::journey();
ck('quest: five journey levels, Visitor at zero', count($j) === 5
   && $j[0]['id'] === 'visitor' && $j[0]['order'] === 0 && $j[4]['id'] === 'vanguard');

/* ── nothing can be signed off against a challenge nobody has written ─── */

ck('quest: the seventy are still awaiting their official wording',
   !Quest::isOfficial('individuation.1') && Quest::challenge('individuation.1') !== null);
ck('quest: a challenge that does not exist is not official either',
   !Quest::isOfficial('courage.1') && Quest::challenge('courage.1') === null);
ck('quest: a challenge knows its value', Quest::valueOf('communal.7') === 'communal');
ck('quest: and a value knows its ten', count(Quest::challengesOf('diligence')) === 10);

/* ── signing one off, against a registry the test supplies ────────────── */

Conduct::ensure(); MentorPortal::ensure(); QuestPoints::ensure();
$db = Database::pdo();
$db->exec("DELETE FROM quest_points WHERE member_id BETWEEN 9500 AND 9599");
$db->exec("DELETE FROM conduct_cases WHERE mentee_id BETWEEN 9500 AND 9599");

/* One challenge given its official wording, so the path can be driven end to
   end before the owner supplies the seventy. Everything else stays empty. */
$official = json_decode((string) file_get_contents(AV_ROOT . '/lib/quest/challenges.json'), true);
foreach ($official['challenges'] as &$row) {
    if ($row['id'] === 'diligence.4') {
        $row['status'] = 'official';
        $row['text'] = 'Finish something you promised, on the day you promised it, without being reminded.';
        $row['evidence'] = 'What was promised, when it was due, and when it landed.';
    }
}
unset($row);
Quest::$override = ['challenges' => $official];

$MENTOR = 9501; $MENTEE = 9502; $DECIDER = 9503;
reset_users();
foreach ([[$MENTOR, 'Quest Mentor'], [$MENTEE, 'Quest Mentee'], [$DECIDER, 'Quest Decider']] as [$id, $name]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, $name, 'q' . $id . '@afrovanguard.org.ng', '', 'member', gmdate('Y-m-d H:i:s')]);
}
$db->prepare("INSERT INTO mentorships (id, mentor_id, mentee_id, status, kind, created_at) VALUES (?,?,?,'active','vquest',?)")
   ->execute([95001, $MENTOR, $MENTEE, gmdate('Y-m-d H:i:s')]);
/* On the committee directly: who may join it is conduct.test.php's business. */
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))
   ->execute([$DECIDER, 'test', gmdate('Y-m-d H:i:s')]);
$decider = ['id' => $DECIDER, 'email' => 'q' . $DECIDER . '@afrovanguard.org.ng'];
$today = gmdate('Y-m-d');
$ev = 'Said on Monday he would have the stock count done by Thursday, and it was on my desk Thursday morning without a reminder.';

$bad = Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'courage.1', 'occurred_on' => $today, 'evidence' => $ev]);
ck('quest: a challenge outside the seventy is refused', !$bad['ok'] && str_contains($bad['error'], 'not one of the seventy'));

$empty = Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'diligence.5', 'occurred_on' => $today, 'evidence' => $ev]);
ck('quest: a challenge nobody has written yet cannot be signed off against',
   !$empty['ok'] && str_contains($empty['error'], 'no official wording'));

$thin = Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'diligence.4', 'occurred_on' => $today, 'evidence' => 'He is diligent.']);
ck('quest: a verdict is not evidence, for a challenge as for anything else', !$thin['ok']);

$case = Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'diligence.4', 'occurred_on' => $today, 'evidence' => $ev]);
ck('quest: a written challenge can be put forward', !empty($case['ok']));
ck('quest: the case records which challenge, and the value it belongs to',
   Conduct::find($case['id'])['challenge_id'] === 'diligence.4' && Conduct::find($case['id'])['reason'] === 'diligence');
ck('quest: the mentor does not price it — the registry does', (int) Conduct::find($case['id'])['points'] === 1);

ck('quest: nothing is granted while it waits', QuestPoints::total($MENTEE) === 0);
ck('quest: the same challenge cannot be put forward twice while one waits',
   !Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'diligence.4', 'occurred_on' => $today, 'evidence' => $ev])['ok']);

ck('quest: the mentor who proposed it may not decide it',
   !Conduct::decide($case['id'], ['id' => $MENTOR, 'email' => 'q' . $MENTOR . '@afrovanguard.org.ng'], true, '')['ok']);

/* Only the compliance committee signs off a challenge — not a superadmin
   (the owner, 2 November 2026). A superadmin still decides fines and awards. */
$SUPER = 9504;
$db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
   ->execute([$SUPER, 'Quest Super', 'q' . $SUPER . '@afrovanguard.org.ng', '', 'member', gmdate('Y-m-d H:i:s')]);
AdminRoles::add('q' . $SUPER . '@afrovanguard.org.ng', 'superadmin', 'test');
$super = ['id' => $SUPER, 'email' => 'q' . $SUPER . '@afrovanguard.org.ng'];
$sup = Conduct::decide($case['id'], $super, true, 'Seen.');
ck('quest: a superadmin off the committee does not sign off a challenge',
   Conduct::canDecide($super) && !$sup['ok'] && str_contains($sup['error'], 'compliance committee') && Conduct::find($case['id'])['status'] === 'proposed');
$db->prepare('DELETE FROM admin_users WHERE email = ?')->execute(['q' . $SUPER . '@afrovanguard.org.ng']);

ck('quest: compliance signs it off', Conduct::decide($case['id'], $decider, true, 'Seen.')['ok']);
ck('quest: …and the point lands in the Quest ledger, not the gate\'s',
   QuestPoints::total($MENTEE) === 1
   && (int) $db->query("SELECT COALESCE(SUM(points),0) FROM gate_points WHERE member_id = $MENTEE")->fetchColumn() === 0);
ck('quest: attributed to the value it proves', QuestPoints::byValue($MENTEE)['diligence'] === 1);
ck('quest: and counted against the seventy', Quest::progress($MENTEE) === ['done' => 1, 'total' => 70]);

$again = Conduct::propose($MENTOR, 95001, ['kind' => 'challenge', 'challenge_id' => 'diligence.4', 'occurred_on' => $today, 'evidence' => $ev]);
ck('quest: a challenge is proved once, ever', !$again['ok'] && str_contains($again['error'], 'once, ever'));

/* ── a Demonstrated mark is the proposal and its evidence ─────────────── */

foreach ($official['challenges'] as &$row) {
    if ($row['id'] === 'faith.2') { $row['status'] = 'official'; $row['text'] = 'Keep a promise to yourself for thirty days.'; $row['evidence'] = 'What was kept, and how it is known.'; }
}
unset($row);
Quest::$override = ['challenges' => $official]; Quest::forget();
$portal = new MentorPortal($MENTOR);
$marks = array_fill_keys(array_keys(MentorPortal::VALUES), 0);
$seen  = 'Kept to the dawn prayer every day this month and told me on the days it was hard, without being asked.';
$low = $portal->saveValues(95001, ['faith' => 1] + $marks, ['faith' => $seen], ['faith' => 'faith.2']);
ck('quest: a mark below Demonstrated puts no challenge forward', !$low['ok'] && str_contains($low['error'], 'Demonstrated'));
$wrong = $portal->saveValues(95001, ['faith' => 2] + $marks, ['faith' => $seen], ['faith' => 'diligence.4']);
ck('quest: the challenge must be one of that value\'s ten', !$wrong['ok'] && str_contains($wrong['error'], 'this value'));
$noev = $portal->saveValues(95001, ['faith' => 2] + $marks, [], ['faith' => 'faith.2']);
ck('quest: a challenge put forward needs the evidence written down', !$noev['ok']);
$mark = $portal->saveValues(95001, ['faith' => 2] + $marks, ['faith' => $seen], ['faith' => 'faith.2']);
$pcase = $db->query("SELECT * FROM conduct_cases WHERE mentee_id = $MENTEE AND challenge_id = 'faith.2'")->fetch(PDO::FETCH_ASSOC);
ck('quest: a Demonstrated mark naming a challenge files it as a proposal', $mark['ok'] && $mark['proposed'] === ['faith.2']
   && $pcase && $pcase['status'] === 'proposed' && str_contains((string) $pcase['evidence'], 'Marked Demonstrated on Faith'));
ck('quest: …and completes nothing by itself', !in_array('faith.2', QuestPoints::challengesDone($MENTEE), true));
$plain = $portal->saveValues(95001, ['diligence' => 3] + $marks, ['diligence' => $seen], ['diligence' => 'diligence.5']);
ck('quest: the marks are kept even when the challenge cannot be put forward', $plain['ok'] && isset($plain['not_proposed']['diligence.5']));
ck('quest: compliance confirms the mark to sign it off', Conduct::decide((int) $pcase['id'], $decider, true, 'Confirmed.')['ok']
   && in_array('faith.2', QuestPoints::challengesDone($MENTEE), true));
$db->exec("DELETE FROM mentor_values WHERE mentorship_id = 95001");

Quest::$override = null;
Quest::forget();
$db->exec("DELETE FROM quest_points WHERE member_id BETWEEN 9500 AND 9599");
$db->exec("DELETE FROM conduct_cases WHERE mentee_id BETWEEN 9500 AND 9599");
