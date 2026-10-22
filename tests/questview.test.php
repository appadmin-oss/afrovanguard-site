<?php
/**
 * tests/questview.test.php — the member's own Quest page, and the count it
 * reads.
 *
 * What this guards:
 *   · Quest::progress() was seventy queries per member. It is one now, and it
 *     must still count only slots that exist.
 *   · The page leads with the journey level, not a number; the level held
 *     comes second, with what the next one asks and this month's ceiling.
 *   · An open integrity finding is visible while it stands, and only then.
 *
 * Run via tests/run.php (provides ck()). Fixtures: member ids 9800–9899.
 */
declare(strict_types=1);

QuestRecord::ensure(); QuestPoints::ensure(); Conduct::ensure(); Levels::ensure();
$db = Database::pdo();
$db->exec("DELETE FROM quest_findings WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM quest_journey  WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM quest_points   WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM compliance_members WHERE user_id BETWEEN 9800 AND 9899");
Quest::$override = null; Quest::forget();

$M = 9801; $D = 9802;
reset_users();
foreach ([$M, $D] as $id) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$id, 'Member ' . $id, 'v' . $id . '@afrovanguard.org.ng', '', 'member', gmdate('Y-m-d H:i:s')]);
}
$db->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))
   ->execute([$D, 'test', gmdate('Y-m-d H:i:s')]);
$decider = ['id' => $D, 'email' => 'v' . $D . '@afrovanguard.org.ng'];

/* ── The count ─────────────────────────────────────────────────────────── */

ck('questview: nobody has proved anything to begin with', Quest::progress($M) === ['done' => 0, 'total' => 70]);
QuestPoints::grant($M, 1, 'challenge:' . $M . ':diligence.4', 'challenge', '', $D, '', 'diligence');
QuestPoints::grant($M, 1, 'challenge:' . $M . ':faith.2', 'challenge', '', $D, '', 'faith');
QuestPoints::grant($M, 1, 'challenge:' . $M . ':courage.1', 'challenge', '', $D, '', '');   // no such slot
QuestPoints::grant($M, 5, 'award:98001', 'service', '', $D, '', '');                        // not a challenge
QuestPoints::grant($D, 1, 'challenge:' . $D . ':diligence.5', 'challenge', '', $M, '', 'diligence');  // somebody else

$done = QuestPoints::challengesDone($M);
sort($done);
ck('questview: the ledger lists this member\'s sign-offs, and only theirs', $done === ['courage.1', 'diligence.4', 'faith.2']);
ck('questview: progress counts only slots the registry has', Quest::progress($M) === ['done' => 2, 'total' => 70]);
ck('questview: a member id that is a prefix of another does not borrow its sign-offs',
   QuestPoints::challengesDone(980) === [] && Quest::progress(980)['done'] === 0);

$byV = Quest::progressByValue($M);
ck('questview: by value, the seven in order', array_keys($byV) === array_keys(Quest::values()));
ck('questview: ten slots under each, and the two proved where they belong',
   array_sum(array_column($byV, 'total')) === 70 && $byV['diligence']['done'] === 1 && $byV['faith']['done'] === 1
   && array_sum(array_column($byV, 'done')) === 2);
ck('questview: none written yet, and it says so', array_sum(array_column($byV, 'written')) === 0);

/* ── The page ──────────────────────────────────────────────────────────── */

$render = function (int $id): string {
    $u = ['id' => $id, 'name' => 'Member ' . $id, 'email' => 'v' . $id . '@afrovanguard.org.ng'];
    ob_start();
    require AV_ROOT . '/portal/views/quest.php';
    return (string) ob_get_clean();
};

$html = $render($M);
$jAt  = strpos($html, 'Your journey level');
ck('questview: the page opens on the journey level, before any number',
   $jAt !== false && $jAt < (int) strpos($html, 'Quest points'));
ck('questview: everybody is a Visitor until a person says otherwise',
   (bool) preg_match('~<li class="avp-level is-here" aria-current="step"><span class="avp-level-b">0</span>Visitor</li>~', $html));
ck('questview: the five levels, numbered from zero', substr_count($html, '<li class="avp-level') === 5 && str_contains($html, '>4</span>Vanguard'));
ck('questview: the level comes second, beside the journey — the Pyramid Manual settled the ladder',
   (int) strpos($html, 'Your level') > (int) strpos($html, 'Your journey level') && str_contains($html, 'O · Ordinary Member'));
ck('questview: what the next level asks, in points and in mentees actually met', str_contains($html, '/ 50 points') && str_contains($html, 'Active Member'));
ck('questview: this month against the level\'s ceiling', str_contains($html, '/ 499'));
ck('questview: the evidence total and the challenge count are the record\'s', str_contains($html, '<div class="avp-kpi-v av-num">8</div>') && str_contains($html, '2 / 70'));
ck('questview: gate points are named as a different thing', str_contains($html, 'Separate from the points on your gate pass'));
ck('questview: unwritten challenges are explained, not hidden', str_contains($html, 'still being written'));
ck('questview: no integrity card while nothing stands', !str_contains($html, 'Open with the compliance committee'));

QuestRecord::setJourney($M, 'explorer', $decider, 'Has started the self-assessment.');
ck('questview: a level a person set is the one shown',
   str_contains($render($M), 'aria-current="step"><span class="avp-level-b">1</span>Explorer'));

$f = QuestRecord::raiseFinding($M, 'commitments', 'Three agreed deliveries missed in September without a word to anybody.', $decider);
$html = $render($M);
ck('questview: an open finding is visible while it stands',
   !empty($f['ok']) && str_contains($html, 'Open with the compliance committee') && str_contains($html, 'Repeated failure to honour commitments'));
ck('questview: the detail of a finding is not repeated on the page', !str_contains($html, 'Three agreed deliveries'));
QuestRecord::resolveFinding((int) $f['id'], 'Talked it through; deliveries back on time since.', $decider);
ck('questview: once resolved it stops standing, and the card goes', !str_contains($render($M), 'Open with the compliance committee'));

ck('questview: an empty record has an empty state, not a blank card', str_contains($render(9803), 'Nothing yet.'));

$portal = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('questview: everybody has it in the menu — the entry is not behind a role',
   (bool) preg_match('~\$nav\[\'You\'\] = \[[^;]*\[\'quest\', \'The Quest\'~s', $portal)
   && str_contains($portal, "require __DIR__ . '/views/quest.php';"));

$db->exec("DELETE FROM quest_findings WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM quest_journey  WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM quest_points   WHERE member_id BETWEEN 9800 AND 9899");
$db->exec("DELETE FROM compliance_members WHERE user_id BETWEEN 9800 AND 9899");
