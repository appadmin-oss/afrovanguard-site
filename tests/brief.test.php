<?php
/**
 * tests/brief.test.php — the leadership brief (report §21, §31, §38).
 *
 * The property that matters most here is not that the brief renders. It is that
 * every number in it was COUNTED, not written by a language model. A brief is
 * acted on — a mentor reassigned, a promotion review opened, an intervention
 * made — and an inferred figure is worse than a missing one, because it is
 * indistinguishable from a real one.
 *
 * So these tests do arithmetic against fixtures with known answers and check the
 * brief reports exactly that. They also pin:
 *
 *   • the deterministic assembly, which is what ships whenever no provider
 *     answers — the suite configures none, so every brief below takes that path;
 *   • the cadence, so a five-minute cron tick produces one brief a week and not
 *     two thousand;
 *   • growth as a real delta against a stored previous brief, absent on the
 *     first one because there is nothing honest to compare against;
 *   • §20's distinction between "meets the criteria" and "meets every
 *     MEASURABLE criterion but needs a human to check something".
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Mentorship.php';

$db = Database::pdo();

$bReset = static function () use ($db): void {
    reset_users();
    Mentorship::ensure(); Accountability::ensure(); Brief::ensure();
    if (class_exists('Levels')) Levels::ensure();
    if (class_exists('Commitments')) Commitments::ensure();
    foreach (['mentorships', 'mentor_sessions', 'av_escalations', 'av_briefs', 'commitments', 'user_notifications'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    AvRules::ensure(); AvRules::resetAll('test');
    Database::metaSet('accountability_from', '2020-01-01');
    Database::metaSet('accountability_last_run', '');
};
$bPair = static function (int $m, int $e) use ($db): int {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, segment, created_at, updated_at) VALUES (?,?,'active','org','2026-01-01','2026-01-01')")
       ->execute([$m, $e]);
    return (int) $db->lastInsertId();
};
$bSess = static function (int $p, float $daysAgo, string $att) use ($db): void {
    $db->prepare('INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, status, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$p, 'Weekly', gmdate('Y-m-d H:i:s', time() - (int) round($daysAgo * 86400)), $att, $att, gmdate('Y-m-d H:i:s')]);
};

/* ══ The figures are counted, and the counts are right ═════════════════ */

$bReset();
$p1 = $bPair(1, 2);
$bSess($p1, 3, 'attended');
$bSess($p1, 2, 'attended');
$bSess($p1, 1, 'missed');
$bSess($p1, 5, 'cancelled');
$bSess($p1, -2, 'scheduled');
$bSess($p1, 20, 'attended');            // outside a 7-day window, inside a 30-day one
$m = Brief::metrics('week');

ck('brief: meetings due in the window are counted', $m['meetings']['due'] === 3);
ck('brief: completed is counted', $m['meetings']['completed'] === 2);
ck('brief: unattended is counted', $m['meetings']['missed'] === 1);
ck('brief: cancelled is counted separately, not as a miss', $m['meetings']['cancelled'] === 1);
ck('brief: the completion rate is arithmetic', $m['meetings']['rate'] === 67);
ck('brief: upcoming meetings are counted', $m['meetings']['upcoming'] === 1);
ck('brief: sessions outside the window are excluded', $m['meetings']['due'] === 3);
ck('brief: the window is the period', $m['period'] === 'week');

// A longer period sees the older session.
$mm = Brief::metrics('month');
ck('brief: a month-long window picks up the older session', $mm['meetings']['due'] === 4);
ck('brief: and reports its own period', $mm['period'] === 'month');

/* ══ Relationship health rolls up ══════════════════════════════════════ */

$bReset();
$g = $bPair(1, 2); foreach ([20, 13, 6] as $d) $bSess($g, $d, 'attended'); $bSess($g, -2, 'scheduled');
$r = $bPair(1, 3); foreach ([20, 13, 6] as $d) $bSess($r, $d, 'missed');
$m = Brief::metrics('week');
ck('brief: active pairings are counted', $m['relationships']['active'] === 2);
ck('brief: green is counted', $m['relationships']['green'] === 1);
ck('brief: red is counted', $m['relationships']['red'] === 1);
ck('brief: the at-risk list names the pair', ($m['relationships']['at_risk'][0]['mentee'] ?? '') === 'Chidi');
ck('brief: and carries the reasons, not just a grade', !empty($m['relationships']['at_risk'][0]['reasons']));

/* ══ Overdue commitments ═══════════════════════════════════════════════ */

$db->prepare("INSERT INTO commitments (member_id, source_kind, source_id, dedupe_key, title, due, status, created_at) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([2, 'meeting', 1, 'bk1', 'Send the report', '2026-01-01', 'open', '2025-12-01']);
$m = Brief::metrics('week');
ck('brief: overdue commitments are counted', $m['commitments']['overdue'] === 1);

/* ══ §20 — ready vs "needs a human check" ══════════════════════════════ */

$bReset();
// Ada mentors two people, both attending perfectly: every measurable criterion
// met, but tenure is unknown on a fresh fixture, so the engine withholds the
// recommendation. That person must still reach leadership.
$bSess($bPair(2, 4), 20, 'attended'); $bSess($bPair(2, 5), 20, 'attended');
foreach ([[2, 4], [2, 5]] as $i => $x) {}
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (4,'Chidi2','c2@x.co','x'),(5,'Ngozi','n@x.co','x')");
$pa = $bPair(2, 4); $pb = $bPair(2, 5);
foreach ([20, 13, 6] as $d) { $bSess($pa, $d, 'attended'); $bSess($pb, $d, 'attended'); }
$m = Brief::metrics('week');
$near = $m['promotions']['near'] ?? [];
ck('brief: someone meeting every measurable criterion is surfaced', count($near) >= 1);
ck('brief: they are counted', ($m['promotions']['near_count'] ?? 0) === count($near));
ck('brief: with what is actually outstanding', !empty($near[0]['blocked_by'] ?? []));
ck('brief: and they are NOT reported as recommended', $m['promotions']['ready'] === 0);
ck('brief: the figures the model sees say a human must check',
   strpos(Brief::figuresAsText($m), 'NEEDS A HUMAN CHECK') !== false);

/* ══ The deterministic brief stands on its own ═════════════════════════ */

$bReset();
$red = $bPair(1, 2); foreach ([20, 13, 6] as $d) $bSess($red, $d, 'missed');
$m = Brief::metrics('week');
$n = Brief::assemble($m);
ck('assemble: it produces a headline', trim($n['headline']) !== '');
ck('assemble: the headline carries the real rate', strpos($n['headline'], '0%') !== false);
ck('assemble: a red relationship is critical', count($n['critical']) >= 1);
ck('assemble: and names both people', strpos($n['critical'][0], 'Ada') !== false && strpos($n['critical'][0], 'Bode') !== false);
ck('assemble: it declares itself computed, not written', $n['source'] === 'computed');

// No provider is configured in the suite, so narrate() must fall back — and the
// brief must be identical to the computed one, not empty.
$nn = Brief::narrate($m);
ck('narrate: with no provider it falls back to the computed brief', $nn['source'] === 'computed');
ck('narrate: and the fallback is not empty', trim($nn['headline']) !== '');

// The master switch stops the model, never the brief.
AvRules::save(['ai.enabled' => '0'], 'test');
$off = Brief::narrate($m);
ck('narrate: ai.enabled off still produces a brief', trim($off['headline']) !== '');
ck('narrate: and it is the computed one', $off['source'] === 'computed');
AvRules::resetAll('test');

/* ══ Cadence — one per period, however often cron ticks ════════════════ */

ck('cadence: a week-old brief is due again', Brief::isDue('week', gmdate('Y-m-d H:i:s', time() - 10 * 86400)));
ck('cadence: one written just now is not', !Brief::isDue('week', gmdate('Y-m-d H:i:s')));
ck('cadence: a daily brief from yesterday is due', Brief::isDue('day', gmdate('Y-m-d H:i:s', time() - 86400)));
ck('cadence: a daily brief from today is not', !Brief::isDue('day', gmdate('Y-m-d H:i:s')));
ck('cadence: no previous brief means due', Brief::isDue('week', ''));

$bReset();
$q = $bPair(1, 2); $bSess($q, 3, 'attended');
$first = Brief::generate('week');
ck('generate: the first brief of the week is written', empty($first['skipped']) && ($first['id'] ?? 0) > 0);
$second = Brief::generate('week');
ck('generate: a second run the same week is skipped', !empty($second['skipped']));
ck('generate: and it says why', strpos((string) ($second['why'] ?? ''), 'already has a brief') !== false);
$forced = Brief::generate('week', true);
ck('generate: force overrides the cadence', empty($forced['skipped']));
ck('generate: an unknown period is refused', empty(Brief::generate('fortnight')['ok']));

ck('store: the latest brief reads back', (Brief::latest('week')['id'] ?? 0) > 0);
ck('store: its metrics survive the round trip', is_array(Brief::latest('week')['metrics'] ?? null));
ck('store: history returns both runs', count(Brief::history('week', 10)) === 2);
ck('store: a period with no briefs returns null', Brief::latest('day') === null);

/* ══ Growth is a measured delta, never a guess ═════════════════════════ */

$bReset();
$g1 = $bPair(1, 2); $bSess($g1, 3, 'attended');
$b1 = Brief::generate('week', true);
ck('growth: the first brief reports none', ($b1['metrics']['growth'] ?? []) === []);

$bPair(1, 3);                                     // a second pairing appears
$b2 = Brief::generate('week', true);
$growth = $b2['metrics']['growth'] ?? [];
ck('growth: the second brief measures the change', $growth !== []);
ck('growth: and states the direction and size', (bool) preg_grep('/\+1 active pairing/', $growth));
ck('growth: the model does not author it — it is copied from the metrics',
   ($b2['narrative']['growth'] ?? null) === $growth);

/* ══ §21 — leadership is told, without being told twice ════════════════ */

$bReset();
$n1 = $bPair(1, 2); $bSess($n1, 3, 'attended');
AdminRoles::ensure();
AdminRoles::add('a@x.co', 'admin', 'test');       // Ada is user 1 in the fixture
$before = (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='brief'")->fetchColumn();
Brief::generate('week', true);
$after = (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='brief'")->fetchColumn();
ck('notify: an administrator is told the brief exists', $after > $before);
$row = $db->query("SELECT title, body FROM user_notifications WHERE kind='brief' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('notify: the notice names the period', strpos((string) $row['title'], 'Weekly') === 0);
ck('notify: and carries the headline, not just a ping', trim((string) $row['body']) !== '');

// The same brief must not notify twice — the dedupe key is the brief id.
$mid = (int) $db->query("SELECT MAX(id) FROM av_briefs")->fetchColumn();
$dupe = (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE dedupe_key = 'brief:week:{$mid}'")->fetchColumn();
ck('notify: one notice per administrator per brief', $dupe === 1);

$bReset();

/* ══ The Studio surface is management-only ═════════════════════════════ */

// The brief names individuals, their attendance and their promotion readiness.
// The Studio action list is a DENY-editors list, so an action missing from it is
// readable by an editor.
$apiSrc2 = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$mgmt2 = '';
if (preg_match('~\$managementOnly = \[(.*?)\n    \];~s', $apiSrc2, $mm2)) $mgmt2 = $mm2[1];
foreach (['brief_latest', 'brief_run'] as $act) {
    ck("studio: {$act} is management-only", strpos($mgmt2, "'{$act}'") !== false);
    ck("studio: {$act} is implemented", strpos($apiSrc2, "case '{$act}'") !== false);
}
// Writing a brief is a model call and a full sweep, so it must be rate limited.
ck('studio: generating a brief is rate limited', strpos($apiSrc2, "av_rate_ok('brief_run'") !== false);

// The cron rung must exist, and must NOT force — forcing on a five-minute tick
// would write ~2,000 briefs a week and notify leadership on every one.
$cronSrc = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('cron: the brief is wired in', strpos($cronSrc, 'Brief::generate(') !== false);
ck('cron: and it does not force', strpos($cronSrc, 'Brief::generate($bp, true)') === false);

