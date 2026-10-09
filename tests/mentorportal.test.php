<?php
/**
 * tests/mentorportal.test.php — the mentor portal's read model and its rules.
 *
 * ── WHAT IS WORTH PINNING HERE ──────────────────────────────────────────────
 * This portal holds young people's records and the only safeguarding route a
 * mentor has. The failures worth a test are the ones that are invisible until
 * they matter:
 *
 *   • A mentor must never see, or write to, a pairing that is not theirs —
 *     including through a bulk action, where the id arrives in a list.
 *   • A filter chip's count and the rows behind it must agree. A chip that
 *     says 79 and opens 25 is how a roster stops being believed.
 *   • "Exemplary" with nothing behind it is what a values record fills up
 *     with. The evidence rule has to live in the server.
 *   • A worried check-in has to reach the coordinator whatever the mentor
 *     then does with the page.
 *   • A pairing must not close until the three closing steps are done. The
 *     button is disabled in the browser, and a disabled button is a
 *     suggestion.
 *   • A lapsed safeguarding module has to block Accept in the SERVER.
 *   • Nothing typed at 1am reaches a fifteen-year-old at 1am.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();
MentorPortal::ensure();
foreach (['mentorships', 'mentor_sessions', 'mentor_values', 'mentor_checkins',
          'mentor_messages', 'mentor_academy', 'mentor_concerns', 'mentor_undo',
          'mentor_goals', 'mentor_profiles', 'mentor_cohorts'] as $t) {
    try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
}

/* Two mentors, so "only your own" is a question with a real wrong answer. */
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES
    (11,'Adaeze Okafor','adaeze@x.co','x'), (12,'Segun Bello','segun@x.co','x')");
Mentorship::becomeMentor(11, ['headline' => 'Chapter lead', 'focus' => 'Leadership', 'bio' => '', 'capacity' => 20, 'accepting' => true]);
Mentorship::becomeMentor(12, ['headline' => 'Other mentor', 'focus' => 'Enterprise', 'bio' => '', 'capacity' => 20, 'accepting' => true]);
$db->exec("INSERT INTO mentor_cohorts (id,name,segment,programme,starts,ends,status,created_at) VALUES (1,'Alimosho','org','Quest','2025-01-01','','open','2025-01-01')");

$now = time();
$mk = function (int $mentor, string $name, int $daysAgo, array $o = []) use ($db, $now): int {
    static $uid = 100;
    $uid++;
    $db->prepare('INSERT INTO lms_users (id,name,email,password_hash) VALUES (?,?,?,?)')
       ->execute([$uid, $name, 'u' . $uid . '@x.co', 'x']);
    $began = gmdate('Y-m-d H:i:s', $now - $daysAgo * 86400);
    $db->prepare("INSERT INTO mentorships (mentor_id,mentee_id,status,message,created_at,updated_at,segment,cohort_id,programme,origin,goals,stage,track,started_at,ends_at,close_steps)
                  VALUES (?,?,?,'',?,?,'org',1,'Quest','admin',?,?,?,?,?,'')")
       ->execute([$mentor, $uid, $o['status'] ?? 'active', $began, $began,
                  $o['goals'] ?? 'Lead one project.', $o['stage'] ?? 4, $o['track'] ?? 'Leadership', $began, $o['ends_at'] ?? '']);
    return (int) $db->lastInsertId();
};
$session = function (int $pair, int $daysAgo, string $att = 'attended', int $mins = 60) use ($db, $now): int {
    $at = gmdate('Y-m-d H:i:s', $now - $daysAgo * 86400);
    $db->prepare("INSERT INTO mentor_sessions (mentorship_id,title,scheduled_at,notes,status,created_at,attendance,duration_min,session_type,outcome,attended_at)
                  VALUES (?,'Progress check-in',?,'',?,?,?,?,'checkin','',?)")
       ->execute([$pair, $at, $att, $at, $att, $att === 'attended' ? $mins : 0, $att === 'attended' ? $at : '']);
    return (int) $db->lastInsertId();
};

$mine   = $mk(11, 'Ngozi Udo', 60);        $session($mine, 3);  $session($mine, 17);
$stale  = $mk(11, 'Chidi Eze', 90);        $session($stale, 40);
$nogoal = $mk(11, 'Halima Sani', 20, ['goals' => '', 'stage' => 2]);
$tolog  = $mk(11, 'Tunde Ajayi', 50);      $session($tolog, 2, 'scheduled');
$closing= $mk(11, 'Amara Obi', 300, ['stage' => 6]);  $session($closing, 5);
$theirs = $mk(12, 'Not Yours', 30);        $session($theirs, 5);

$a = new MentorPortal(11);
$b = new MentorPortal(12);

/* ══ ACCESS ══════════════════════════════════════════════════════════════ */

ck('access: a mentor owns their own pairing', $a->ownsPairing($mine));
ck('access: and does not own another mentor’s — which is the only thing '
 . 'standing between an edited URL and somebody else’s mentee',
    !$a->ownsPairing($theirs));
ck('access: the case file of a pairing that is not yours comes back NULL, '
 . 'not an empty record that the view then renders',
    $a->caseFile($theirs) === null && is_array($a->caseFile($mine)));
ck('access: writing to it is refused too, not merely hidden',
    ($a->saveGoals($theirs, 'Nice try.')['ok'] ?? true) === false);
ck('access: …and the other mentor’s goals are untouched',
    (string) $db->query('SELECT goals FROM mentorships WHERE id = ' . $theirs)->fetchColumn() === 'Lead one project.');
ck('access: the roster shows only your own', count($a->roster('', 'all', 'wait', 1)['rows']) === 5);
ck('access: the other mentor sees only theirs', count($b->roster('', 'all', 'wait', 1)['rows']) === 1);

/* ══ FILTERS AND COUNTS ══════════════════════════════════════════════════ */

$r = $a->roster('', 'all', 'wait', 1);
ck('roster: the All chip counts every active pairing', $r['counts']['all'] === 5);
ck('roster: "no session in 21+ days" finds the one that has gone quiet', $r['counts']['wait'] === 2);
ck('roster: "goals not set" finds the one with none', $r['counts']['goals'] === 1);
ck('roster: "session to log" finds the one that has happened and was not logged', $r['counts']['log'] === 1);
ck('roster: "closing soon" finds the one at stage six', $r['counts']['closing'] === 1);
foreach (['wait', 'goals', 'log', 'closing'] as $filter) {
    $f = $a->roster('', $filter, 'wait', 1);
    ck("roster: the {$filter} chip's count and the rows behind it agree — a chip "
     . 'that says one number and opens another is how a roster stops being believed',
        $f['total'] === $r['counts'][$filter] && count($f['rows']) === $f['total']);
}
ck('roster: search matches a name', count($a->roster('Ngozi', 'all', 'wait', 1)['rows']) === 1);
ck('roster: search matches a track', count($a->roster('Leadership', 'all', 'wait', 1)['rows']) === 5);
ck('roster: search matches the chapter, which is the pairing’s cohort',
    count($a->roster('Alimosho', 'all', 'wait', 1)['rows']) === 5);
ck('roster: a search with a LIKE wildcard in it is text, not an operator — a '
 . 'mentee called "100%" has to be findable',
    count($a->roster('%', 'all', 'wait', 1)['rows']) === 0);
ck('roster: an unknown filter falls back to All rather than erroring',
    $a->roster('', 'nonsense', 'wait', 1)['filter'] === 'all');
ck('roster: longest-wait sorts by the LAST SESSION, not by how old the '
 . 'pairing is — a three-hundred-day pairing you met last week is not the '
 . 'one that needs you',
    ($a->roster('', 'all', 'wait', 1)['rows'][0]['name'] ?? '') === 'Tunde Ajayi');
ck('roster: a pairing with a session booked but never ATTENDED counts from the '
 . 'day it started — a session in the diary is not a session that happened',
    ($a->roster('Tunde', 'all', 'wait', 1)['rows'][0]['days'] ?? 0) === 50);
ck('roster: name A–Z sorts by name', ($a->roster('', 'all', 'name', 1)['rows'][0]['name'] ?? '') === 'Amara Obi');

/* ── Paging, at a size that would hide a mistake ── */
for ($i = 0; $i < 60; $i++) { $p = $mk(11, 'Seeded ' . $i, 10 + $i); $session($p, 5); }
$page1 = $a->roster('', 'all', 'name', 1, true);
$page3 = $a->roster('', 'all', 'name', 3, true);
ck('paging: 25 to a page', count($page1['rows']) === MentorPortal::PER_PAGE);
ck('paging: the total counts them all, not just the page', $page1['total'] === 65);
ck('paging: page three is the remainder, and a different set', count($page3['rows']) === 15
    && $page3['rows'][0]['pairing_id'] !== $page1['rows'][0]['pairing_id']);
ck('paging: no row appears on two pages', count(array_intersect(
        array_column($page1['rows'], 'pairing_id'), array_column($page3['rows'], 'pairing_id'))) === 0);
$full2 = $a->roster('', 'all', 'name', 2);
ck('paging: the full render of page two holds both pages, so a reload of a '
 . 'deep link shows what "Show 25 more" showed', count($full2['rows']) === 50);

/* ── MP-04: no N+1 ── */
if (!class_exists('CountingStatement')) {
    eval('final class CountingStatement extends PDOStatement { public static int $n = 0; protected function __construct() {}
          #[ReturnTypeWillChange] public function execute(?array $p = null): bool { self::$n++; return parent::execute($p); } }');
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);
$counted = new MentorPortal(11);
CountingStatement::$n = 0; $counted->roster('', 'all', 'wait', 1);
$nRoster = CountingStatement::$n;
$counted2 = new MentorPortal(11);
CountingStatement::$n = 0; $counted2->navBadges();
$nBadges = CountingStatement::$n;
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
ck('no N+1: a roster page of 25 costs ' . $nRoster . ' queries, not one per row '
 . '(the spec allows 4)', $nRoster <= 4);
ck('no N+1: the nav badges cost ' . $nBadges . ', not one per badge', $nBadges <= 3);

/* ══ VALUES ══════════════════════════════════════════════════════════════ */

$levels = array_fill_keys(array_keys(MentorPortal::VALUES), 0);
ck('values: seven values, rated, with nothing seen, saves',
    ($a->saveValues($mine, $levels, [])['ok'] ?? false) === true);
ck('values: a missing value is refused — "Not seen" is an answer, leaving it '
 . 'blank is not', ($a->saveValues($mine, array_slice($levels, 0, 6), [])['ok'] ?? true) === false);

$rise = $levels; $rise['diligence'] = 2;
ck('values: a RISE with no evidence is refused',
    ($a->saveValues($mine, $rise, [])['ok'] ?? true) === false);
ck('values: a rise with weak evidence is refused too',
    ($a->saveValues($mine, $rise, ['diligence' => 'Great'])['ok'] ?? true) === false);
ck('values: a rise with something a reader can picture is accepted',
    ($a->saveValues($mine, $rise, ['diligence' => 'Came back to the funding note after it had already been marked and rewrote it.'])['ok'] ?? false) === true);
$top = $levels; $top['faith'] = 3; $top['diligence'] = 2;
ck('values: Exemplary needs evidence even when it is not a rise — which is '
 . 'the case the browser rule alone would let through',
    ($a->saveValues($mine, $top, ['diligence' => 'Came back to the funding note after it had already been marked.'])['ok'] ?? true) === false);
ck('values: "good." is weak however it is punctuated', MentorPortal::evidenceWeak('good.'));
ck('values: so is anything under eighteen characters', MentorPortal::evidenceWeak('She did well'));
ck('values: a real sentence is not', !MentorPortal::evidenceWeak('Ran the whole session when I was late.'));
ck('values: saving moves you to a mentee nobody has observed this month',
    ($a->saveValues($mine, $levels, [])['next_id'] ?? 0) > 0);
ck('values: and that is not the one just saved',
    ($a->saveValues($mine, $levels, [])['next_id'] ?? 0) !== $mine);
ck('values: an observation on someone else’s mentee is refused',
    ($a->saveValues($theirs, $levels, [])['ok'] ?? true) === false);

/* ══ CHECK-INS ═══════════════════════════════════════════════════════════ */

$rows = $a->checkins(true);
ck('check-ins: week 1, 2 and 4 are generated from the pairing’s own start date',
    count(array_filter($rows, fn($x) => $x['pairing_id'] === $mine && in_array($x['week'], [1, 2, 4], true))) === 3);
$ck1 = null;
foreach ($rows as $x) if ($x['pairing_id'] === $mine && $x['week'] === 1) $ck1 = $x;
ck('check-ins: a calm set of answers raises nothing',
    ($a->saveCheckin((int) $ck1['id'], $mine, 0, 0, 0)['flagged'] ?? true) === false);

$ck2 = null;
foreach ($rows as $x) if ($x['pairing_id'] === $mine && $x['week'] === 2) $ck2 = $x;
$flagged = $a->saveCheckin((int) $ck2['id'], $mine, 0, 0, 2);
ck('check-ins: "yes, something is worrying me" raises the flag', ($flagged['flagged'] ?? false) === true);
$queue = MentorPortal::coordinatorQueue();
ck('check-ins: …and it is IN the coordinator’s queue, not only on the mentor’s '
 . 'screen — the mentor is not asked whether to escalate it',
    in_array((int) $ck2['id'], array_map(fn($x) => (int) $x['id'], $queue), true));
$ck4 = null;
foreach ($rows as $x) if ($x['pairing_id'] === $mine && $x['week'] === 4) $ck4 = $x;
ck('check-ins: "struggling" raises it as well', ($a->saveCheckin((int) $ck4['id'], $mine, 2, 0, 0)['flagged'] ?? false) === true);
ck('check-ins: a check-in belonging to another pairing is refused',
    ($a->saveCheckin((int) $ck1['id'], $nogoal, 0, 0, 0)['ok'] ?? true) === false);

/* ══ CLOSING ═════════════════════════════════════════════════════════════ */

ck('closing: a pairing will not close with none of the steps done — the button '
 . 'is disabled in the browser, and a disabled button is a suggestion',
    ($a->closePairing($closing)['ok'] ?? true) === false);
$a->closeStep($closing, 'schedule', true);
$a->closeStep($closing, 'review', true);
ck('closing: nor with two of the three', ($a->closePairing($closing)['ok'] ?? true) === false);
$a->closeStep($closing, 'contact', true);
ck('closing: with all three, it closes', ($a->closePairing($closing)['ok'] ?? false) === true);
ck('closing: and the pairing leaves the active roster',
    !in_array($closing, array_column($a->roster('', 'all', 'wait', 1000)['rows'], 'pairing_id'), true));
ck('closing: an unknown step is refused rather than stored',
    ($a->closeStep($mine, 'whatever', true)['ok'] ?? true) === false);

/* ══ SESSIONS AND HOURS ══════════════════════════════════════════════════ */

$sid = $session($tolog, 1, 'scheduled');
$logged = $a->logSession($sid, 45, ['Goals', 'Nonsense topic'], 'Worried', 'Talked about the chapter meeting.');
ck('sessions: logging one records the minutes', ($logged['minutes'] ?? 0) === 45);
ck('sessions: an invented topic is dropped rather than stored',
    (string) $db->query('SELECT topics FROM mentor_sessions WHERE id = ' . $sid)->fetchColumn() === 'Goals');
ck('sessions: a worried or upset mentee is reported back, so the page can '
 . 'point at Report a concern', ($logged['concern'] ?? false) === true);
ck('sessions: an odd length snaps to one of the four offered',
    ($a->logSession($session($tolog, 1, 'scheduled'), 37, [], 'Engaged', '')['minutes'] ?? 0) === 60);
$other = $session($theirs, 1, 'scheduled');
ck('sessions: logging someone else’s session is refused', ($a->logSession($other, 60, [], 'Engaged', '')['ok'] ?? true) === false);
$a->unlogSession($sid);
ck('sessions: Undo puts it back to scheduled and takes the hours off',
    (string) $db->query('SELECT attendance FROM mentor_sessions WHERE id = ' . $sid)->fetchColumn() === 'scheduled'
 && (int) $db->query('SELECT duration_min FROM mentor_sessions WHERE id = ' . $sid)->fetchColumn() === 0);

/* ══ MESSAGES ════════════════════════════════════════════════════════════ */

$m = $a->sendMessage($mine, 'How did the talk go?');
ck('messages: a message is stored', ($m['ok'] ?? false) === true);
$hour = (int) date('G');
$shouldQueue = $hour < MentorPortal::SEND_FROM || $hour >= MentorPortal::SEND_TO;
ck('messages: outside 8am–8pm it queues until the morning, and inside them it '
 . 'does not — nothing typed at 1am reaches a fifteen-year-old at 1am',
    ($m['queued'] ?? false) === $shouldQueue);
$row = $db->query('SELECT send_after FROM mentor_messages ORDER BY id DESC LIMIT 1')->fetchColumn();
ck('messages: a queued one carries the time it will be shown; a sent one does not',
    $shouldQueue ? ((string) $row !== '' && (int) date('G', strtotime((string) $row)) === MentorPortal::SEND_FROM) : (string) $row === '');
ck('messages: it is stored immediately either way, so the safeguarding lead '
 . 'can read it before it is delivered',
    (int) $db->query('SELECT COUNT(*) FROM mentor_messages')->fetchColumn() === 1);
ck('messages: an empty one is refused', ($a->sendMessage($mine, '   ')['ok'] ?? true) === false);
ck('messages: to another mentor’s mentee, refused', ($a->sendMessage($theirs, 'Hello')['ok'] ?? true) === false);

/* ══ THE ACADEMY GATE ════════════════════════════════════════════════════ */

$req = $mk(12, 'Hopeful One', 0, ['status' => 'pending']);
$db->prepare('UPDATE mentorships SET mentor_id = 11 WHERE id = ?')->execute([$req]);
$db->prepare('UPDATE mentor_profiles SET capacity = 200 WHERE user_id = 11')->execute();   // capacity is tested on its own below
$fresh = fn() => new MentorPortal(11);

ck('academy: with no safeguarding pass at all, Accept is blocked',
    str_contains($fresh()->acceptBlock(), 'safeguarding'));
ck('academy: and the block is enforced on the WRITE, not only on the button',
    ($fresh()->acceptRequest($req)['ok'] ?? true) === false);

$db->prepare('INSERT INTO mentor_academy (user_id,module_key,score,passed,completed_at) VALUES (11,?,?,1,?)')
   ->execute(['safeguarding', 100, gmdate('Y-m-d H:i:s', $now - 400 * 86400)]);
ck('academy: a pass from over a year ago has LAPSED', !$fresh()->safeguardingCurrent());
ck('academy: a lapsed module still blocks Accept', str_contains($fresh()->acceptBlock(), 'lapsed'));

$db->prepare('INSERT INTO mentor_academy (user_id,module_key,score,passed,completed_at) VALUES (11,?,?,1,?)')
   ->execute(['safeguarding', 100, gmdate('Y-m-d H:i:s')]);
ck('academy: retaking it clears the block', $fresh()->safeguardingCurrent() && $fresh()->acceptBlock() === '');
ck('academy: and now the request can be accepted', ($fresh()->acceptRequest($req)['ok'] ?? false) === true);

$marked = MentorAcademy::mark('safeguarding', [1, 0, 1, 1, 1]);
ck('academy: every answer right is 100%', ($marked['score'] ?? 0) === 100 && $marked['passed']);
$half = MentorAcademy::mark('safeguarding', [1, 0, 1, 0, 0]);
ck('academy: 60% does not pass — the mark is ' . MentorPortal::PASS_MARK . '%',
    ($half['score'] ?? 0) === 60 && !$half['passed']);
ck('academy: a failed attempt is recorded too, so a coordinator can see who is '
 . 'stuck rather than only who is done',
    ($fresh()->recordAttempt('goals', [2, 2, 2, 2, 2])['passed'] ?? true) === false
 && (int) $db->query("SELECT COUNT(*) FROM mentor_academy WHERE module_key = 'goals' AND passed = 0")->fetchColumn() === 1);
ck('academy: every required module has lessons, a scenario and a quiz',
    count(array_filter(MentorAcademy::all(), fn($x) => count($x['lessons']) >= 3 && count($x['scenario']['options']) >= 3 && count($x['quiz']) >= 3)) === count(MentorAcademy::all()));
ck('academy: every scenario has exactly ONE best answer',
    count(array_filter(MentorAcademy::all(), fn($x) => count(array_filter($x['scenario']['options'], fn($o) => $o['best'])) === 1)) === count(MentorAcademy::all()));

/* ══ CAPACITY ════════════════════════════════════════════════════════════ */

$db->prepare('UPDATE mentor_profiles SET capacity = 1 WHERE user_id = 11')->execute();
ck('capacity: a mentor already past their capacity is told so, with the number',
    str_contains($fresh()->acceptBlock(), 'capacity of 1'));
$db->prepare('UPDATE mentor_profiles SET capacity = 200 WHERE user_id = 11')->execute();

/* ══ BULK ════════════════════════════════════════════════════════════════ */

$ids = array_column($a->roster('', 'all', 'name', 1, true)['rows'], 'pairing_id');
$bulk = $a->bulk('checkin', array_merge($ids, [$theirs]));
ck('bulk: it works on the ids it was given', $bulk['ok'] === count($ids));
ck('bulk: EVERY id is checked, including the one slipped into the middle of '
 . 'the list', count($bulk['failed']) === 1);
ck('bulk: the failure is named, so the mentor is told rather than left to find '
 . 'out from the mentee', ($bulk['failed'][0]['name'] ?? '') !== '');
$before = (int) $db->query('SELECT COUNT(*) FROM mentor_checkins')->fetchColumn();
$a->bulkUndo((string) $bulk['undo']);
ck('bulk: Undo removes exactly what it asked for',
    (int) $db->query('SELECT COUNT(*) FROM mentor_checkins')->fetchColumn() === $before - $bulk['ok']);
ck('bulk: an undo token works once', ($a->bulkUndo((string) $bulk['undo'])['ok'] ?? true) === false);
$many = $a->bulk('checkin', array_fill(0, 300, $mine));
ck('bulk: the cap is 100 ids however many are sent', $many['ok'] + count($many['failed']) <= 100);
ck('bulk: an unknown action does nothing at all',
    ($a->bulk('delete-everything', $ids)['ok'] ?? 1) === 0);

/* ══ CONCERNS ════════════════════════════════════════════════════════════ */

ck('concern: a category that is not one of the seven is refused',
    ($a->reportConcern('whatever', 'Something happened that worried me.')['ok'] ?? true) === false);
ck('concern: a few words is not a report', ($a->reportConcern('home', 'Worried')['ok'] ?? true) === false);
$c = $a->reportConcern('home', 'She said her uncle gets angry and that she sleeps elsewhere some nights.', $mine);
ck('concern: a real one is filed and answers with a case number',
    ($c['ok'] ?? false) && preg_match('/^AVS-\d{4}-[0-9A-F]{4}$/', (string) $c['case_no']) === 1);
ck('concern: it is stored against the mentor who filed it',
    (int) $db->query('SELECT mentor_id FROM mentor_concerns ORDER BY id DESC LIMIT 1')->fetchColumn() === 11);
$c2 = $a->reportConcern('home', 'A concern about a pairing that is not mine.', $theirs);
ck('concern: naming someone else’s pairing files it with NO pairing rather '
 . 'than refusing — a report must never be harder to make than it has to be',
    ($c2['ok'] ?? false) === true
 && (int) $db->query('SELECT mentorship_id FROM mentor_concerns ORDER BY id DESC LIMIT 1')->fetchColumn() === 0);

/* ══ THE PLAN ════════════════════════════════════════════════════════════ */

$lines = $a->planLines($mine);
ck('plan: four lines, in the order the page prints them',
    array_keys($lines) === ['Open', 'Check', 'Ask', 'Agree']);
ck('plan: it uses the mentee’s own first name', str_contains($lines['Open'], 'Ngozi'));
ck('plan: it never proposes contact outside the portal, which is a rule about '
 . 'what it may SAY and so belongs where the lines are made',
    !preg_match('/whatsapp|wa\.me|phone|call them|text them|meet at|my number/i', implode(' ', $lines)));
ck('plan: for a pairing that is not yours, there are no lines at all',
    $a->planLines($theirs) === []);

/* ══ THE SHAPE OF A ROW ══════════════════════════════════════════════════ */

$row = null;
foreach ($a->roster('Ngozi', 'all', 'wait', 1)['rows'] as $x) $row = $x;
ck('row: the health reads from the days since the last session', $row['tone'] === 'green');
ck('row: a pairing nobody has met for over three weeks is red',
    ($a->roster('Chidi', 'all', 'wait', 1)['rows'][0]['tone'] ?? '') === 'red');
ck('row: a brand-new pairing is "just started", not "needs attention" — three '
 . 'weeks is not late when the pairing is two weeks old',
    ($a->roster('Halima', 'all', 'wait', 1)['rows'][0]['health'] ?? '') === 'Just started');
ck('row: initials come from the name', $row['initials'] === 'NU');
ck('row: sessions kept is a percentage, or nothing at all', $row['kept'] === null || ($row['kept'] >= 0 && $row['kept'] <= 100));

/* ══ GOALS ═══════════════════════════════════════════════════════════════
   Goals are rows now. Three things have to hold, and all three are the kind
   that fail silently: the paragraph a mentor wrote before this existed has to
   become rows exactly once; mentorships.goals — which the mentee's portal, the
   roster filter and Today all still read — has to follow every write; and a
   goal id belonging to someone else's pairing has to be refused, not merely
   absent from the page.                                                    */

$gc = $a->caseFile($mine);
ck('goals: the paragraph on an existing pairing becomes one goal row',
    count($gc['goals_list']) === 1 && $gc['goals_list'][0]['title'] === 'Lead one project.');
ck('goals: and it is dated when the pairing started, not today — a timeline '
 . 'that says goals were agreed this morning is a timeline nobody trusts',
    substr($gc['goals_list'][0]['created_at'], 0, 10) === gmdate('Y-m-d', $now - 60 * 86400));
ck('goals: opening the case file again does not migrate it a second time',
    count($a->caseFile($mine)['goals_list']) === 1);
ck('goals: a pairing with no text has no rows, and nothing is invented',
    $a->caseFile($nogoal)['goals_list'] === []);

$g1 = $a->addGoal($nogoal, '  Speak at the  chapter meeting ', 'She runs the session herself', '2026-12-01');
ck('goals: a goal can be added, and the whitespace is tidied',
    ($g1['ok'] ?? false) && $a->caseFile($nogoal)['goals_list'][0]['title'] === 'Speak at the chapter meeting');
ck('goals: the first goal moves the pairing to "Goals agreed"',
    (int) $db->query('SELECT stage FROM mentorships WHERE id = ' . $nogoal)->fetchColumn() === 3);
ck('goals: and mentorships.goals follows, because the mentee’s own portal, the '
 . 'roster filter and Today all read that column and none of them changed',
    (string) $db->query('SELECT goals FROM mentorships WHERE id = ' . $nogoal)->fetchColumn() === 'Speak at the chapter meeting');
ck('goals: "goals not set" no longer counts it',
    $a->roster('', 'all', 'wait', 1)['counts']['goals'] === 0);
ck('goals: a date the browser could not have sent is dropped, not half-parsed',
    ($a->addGoal($nogoal, 'Second goal', '', '01/12/2026')['ok'] ?? false)
    && $a->caseFile($nogoal)['goals_list'][1]['due_on'] === '');
ck('goals: an empty goal is refused',
    ($a->addGoal($nogoal, '   ', '', '')['ok'] ?? true) === false);
$a->addGoal($nogoal, 'Third goal', '', '');
ck('goals: three open goals at a time is the limit, and the fourth says why',
    ($a->addGoal($nogoal, 'Fourth goal', '', '')['ok'] ?? true) === false
    && count($a->caseFile($nogoal)['goals_list']) === 3);

$gid = $a->caseFile($nogoal)['goals_list'][0]['id'];
ck('goals: marking one met records when, and leaves room for another',
    ($a->setGoalStatus($nogoal, $gid, 'met')['ok'] ?? false)
    && MentorPortal::goalTally($a->caseFile($nogoal)['goals_list']) ['met'] === 1
    && $a->caseFile($nogoal)['goals_list'][0]['closed_at'] !== '');
ck('goals: a met goal still counts as a goal — the pairing has not gone back '
 . 'to having none',
    str_contains((string) $db->query('SELECT goals FROM mentorships WHERE id = ' . $nogoal)->fetchColumn(), 'Speak at the chapter meeting'));
ck('goals: setting one aside keeps the row but takes it out of the live set',
    ($a->setGoalStatus($nogoal, $gid, 'dropped')['ok'] ?? false)
    && count($a->caseFile($nogoal)['goals_list']) === 3
    && !str_contains((string) $db->query('SELECT goals FROM mentorships WHERE id = ' . $nogoal)->fetchColumn(), 'Speak at the chapter meeting'));
ck('goals: an unknown status is refused',
    ($a->setGoalStatus($nogoal, $gid, 'finished')['ok'] ?? true) === false);

$mineGoal = $a->caseFile($mine)['goals_list'][0]['id'];
ck('goals: a goal id from a DIFFERENT pairing of your own is still refused — '
 . 'ownership of the pairing is not ownership of every goal in the database',
    ($a->setGoalStatus($nogoal, $mineGoal, 'met')['ok'] ?? true) === false
    && ($a->editGoal($nogoal, $mineGoal, 'Rewritten', '', '')['ok'] ?? true) === false
    && ($a->removeGoal($nogoal, $mineGoal)['ok'] ?? true) === false);
ck('goals: and the goal it pointed at is untouched',
    $a->caseFile($mine)['goals_list'][0]['title'] === 'Lead one project.'
    && $a->caseFile($mine)['goals_list'][0]['status'] === 'open');
ck('goals: the other mentor cannot add one to your pairing',
    ($b->addGoal($nogoal, 'Mine now', '', '')['ok'] ?? true) === false);

ck('goals: a goal can be reworded',
    ($a->editGoal($nogoal, $a->caseFile($nogoal)['goals_list'][1]['id'], 'Second goal, reworded', 'How we will know', '2027-01-15')['ok'] ?? false)
    && $a->caseFile($nogoal)['goals_list'][1]['title'] === 'Second goal, reworded');

/* The plan assistant chases what is still open. A met goal is not the thing
   to ask about next time, and one set aside is the thing you agreed to stop. */
$pl = $a->planLines($nogoal);
ck('plan: it asks about a goal that is still open, never one already met',
    str_contains($pl['Ask'], 'Second goal, reworded'));

foreach ($a->caseFile($nogoal)['goals_list'] as $g) $a->removeGoal($nogoal, $g['id']);
ck('goals: removing the last one empties the text and puts the pairing back at '
 . 'kick-off, rather than leaving it standing at a step it is not on',
    (string) $db->query('SELECT goals FROM mentorships WHERE id = ' . $nogoal)->fetchColumn() === ''
    && (int) $db->query('SELECT stage FROM mentorships WHERE id = ' . $nogoal)->fetchColumn() === 2
    && $a->roster('', 'all', 'wait', 1)['counts']['goals'] === 1);
