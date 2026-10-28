<?php
/**
 * tests/questrecord.test.php — what a member carries in the Quest, and the
 * one thing no amount of it can buy past.
 *
 * Two defects this guards:
 *   · A quota counted on PAIRED mentees is gamed by opening pairings. The
 *     Quest counts mentees actually being mentored, and `vquest` only.
 *   · An integrity finding that could be outweighed by points would set the
 *     price at which dishonesty is worth paying.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

QuestRecord::ensure(); Conduct::ensure(); Mentorship::ensure(); QuestPoints::ensure();
$db = Database::pdo();
$M = 9601; $A = 9602; $B = 9603; $C = 9604; $D = 9605;   // mentor, mentees, decider
$db->exec("DELETE FROM quest_findings WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM quest_journey  WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM quest_points   WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM mentorships    WHERE mentor_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM compliance_members WHERE user_id BETWEEN 9600 AND 9699");

reset_users();
foreach ([$M, $A, $B, $C, $D] as $id) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, 'Member ' . $id, 'r' . $id . '@afrovanguard.org.ng', '', 'member', gmdate('Y-m-d H:i:s')]);
}
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))
   ->execute([$D, 'test', gmdate('Y-m-d H:i:s')]);
$decider = ['id' => $D, 'email' => 'r' . $D . '@afrovanguard.org.ng'];
$mentor  = ['id' => $M, 'email' => 'r' . $M . '@afrovanguard.org.ng'];

$pair = function (int $mentee, string $kind) use ($db, $M): int {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, kind, created_at) VALUES (?,?,'active',?,?)")
       ->execute([$M, $mentee, $kind, gmdate('Y-m-d H:i:s')]);
    return (int) $db->lastInsertId();
};

/* ── mentees: the Quest counts vquest, and counts the stricter sense ──── */

ck('record: a mentor with nobody has nobody', QuestRecord::pairedMentees($M) === 0 && QuestRecord::activeMentees($M) === 0);

$pair($A, 'vquest');
$pair($B, 'academy');
ck('record: an Academy pairing is not a Quest pairing', QuestRecord::pairedMentees($M) === 1);
ck('record: and the one vquest pairing is the one counted',
   QuestRecord::vquestPairedIds($M) === [$A]);

$pair($C, 'vquest');
ck('record: paired counts the rows that exist', QuestRecord::pairedMentees($M) === 2);
ck('record: two vquest pairings exist and neither has ever met, so the quota is zero — a pairing is not a mentee',
   QuestRecord::pairedMentees($M) === 2 && QuestRecord::activeMentees($M) === 0);

/* ── the journey: everybody starts a Visitor, and a person moves them ─── */

ck('record: everybody is a Visitor until somebody says otherwise', QuestRecord::journey($A) === 'visitor');
ck('record: a level outside the five is refused',
   !QuestRecord::setJourney($A, 'champion', $decider)['ok']);
ck('record: a mentor does not move somebody along the journey',
   !QuestRecord::setJourney($A, 'explorer', $mentor)['ok'] && QuestRecord::journey($A) === 'visitor');
ck('record: compliance does', QuestRecord::setJourney($A, 'explorer', $decider, 'Finished the self-assessment.')['ok']
   && QuestRecord::journey($A) === 'explorer');
ck('record: and can move them again', QuestRecord::setJourney($A, 'practitioner', $decider)['ok']
   && QuestRecord::journey($A) === 'practitioner');
ck('record: the journey is not computed from points — nothing was earned',
   QuestPoints::total($A) === 0);

/* ── integrity: a gate, not a deduction ──────────────────────────────── */

$detail = 'Took the float from the Saturday session and did not account for it when asked on the Monday.';
ck('record: a member with nothing against them holds integrity', QuestRecord::holdsIntegrity($A));
ck('record: a mentor cannot record a finding', !QuestRecord::raiseFinding($A, 'resources', $detail, $mentor)['ok']);
ck('record: a verdict is not a finding', !QuestRecord::raiseFinding($A, 'resources', 'He is dishonest.', $decider)['ok']);
ck('record: a finding needs a kind', !QuestRecord::raiseFinding($A, 'vibes', $detail, $decider)['ok']);
ck('record: nobody records a finding about themselves',
   !QuestRecord::raiseFinding($D, 'resources', $detail, $decider)['ok']);

$f = QuestRecord::raiseFinding($A, 'resources', $detail, $decider);
ck('record: compliance records one', !empty($f['ok']));
ck('record: and it stands against them', !QuestRecord::holdsIntegrity($A));

QuestPoints::grant($A, 500, 'award:9001', 'leadership', 'Ran the whole programme', $D);
ck('record: five hundred points do not clear it — it is a gate, not a price',
   QuestPoints::total($A) === 500 && !QuestRecord::holdsIntegrity($A));

ck('record: resolving needs to say what changed', !QuestRecord::resolveFinding($f['id'], '', $decider)['ok']);
ck('record: a mentor does not resolve one', !QuestRecord::resolveFinding($f['id'], 'It is fine.', $mentor)['ok']);
ck('record: compliance resolves it', QuestRecord::resolveFinding($f['id'], 'Repaid in full and accounted for.', $decider)['ok']);
ck('record: …and the member holds integrity again — recovery is reachable', QuestRecord::holdsIntegrity($A));
ck('record: the finding stays on the record either way', count(QuestRecord::findings($A)) === 1
   && QuestRecord::findings($A, true) === []);
ck('record: a resolved finding cannot be resolved twice',
   !QuestRecord::resolveFinding($f['id'], 'again', $decider)['ok']);

/* ── the whole record ─────────────────────────────────────────────────── */

$rec = QuestRecord::of($A);
ck('record: of() carries points, progress, mentees, journey and standing',
   $rec['points'] === 500 && $rec['journey'] === 'practitioner'
   && $rec['challenges']['total'] === 70 && $rec['integrity'] === true
   && $rec['open_findings'] === 0 && array_key_exists('mentees_active', $rec));
ck('record: and no rank — which ladder a member climbs is not settled',
   !array_key_exists('rank', $rec) && !array_key_exists('level', $rec));

$db->exec("DELETE FROM quest_findings WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM quest_journey  WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM quest_points   WHERE member_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM mentorships    WHERE mentor_id BETWEEN 9600 AND 9699");
$db->exec("DELETE FROM compliance_members WHERE user_id BETWEEN 9600 AND 9699");
